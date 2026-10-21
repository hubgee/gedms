/**
 * Chunked, resumable uploader for the EDMS upload API.
 *
 * Contract (mirrors app/Http/Controllers/UploadController.php):
 *
 *   POST   /uploads                          -> session (resumes an existing one)
 *   PUT    /uploads/{uuid}/chunks/{index}    -> one chunk (multipart/form-data)
 *   POST   /uploads/{uuid}/complete          -> claims the session, queues assembly
 *   GET    /uploads/{uuid}                   -> which chunks are still missing
 *   DELETE /uploads/{uuid}                   -> cancel and drop the bytes
 *
 * Why XHR and not fetch: only XMLHttpRequest reports upload progress, and a
 * 10 GB upload without a progress bar is not an upload, it is a rumour.
 *
 * Restart safety lives server-side: every chunk is verified and stored before
 * it is indexed, and the session is keyed on name/size/mtime, so re-selecting
 * the same file after a crash, a reload or a network outage resumes from the
 * first chunk the server does not already have.
 */

const DEFAULTS = {
    /** Chunks in flight per file. Keep in sync with config('uploads.concurrency'). */
    concurrency: 3,
    /** Attempts per chunk (1 try + retries). */
    maxAttempts: 5,
    baseDelayMs: 1000,
    maxDelayMs: 15000,
    /** Assembly polling (the job runs on a queue). */
    pollIntervalMs: 2000,
    pollAttempts: 60,
};

/** Endpoints are emitted by Blade so the app can live in a subdirectory. */
let config = null;

function readConfig() {
    if (config) return config;

    const el = document.getElementById('gedms-upload-config');

    config = {
        store: el?.dataset.endpoint || '/uploads',
        sessionTemplate: el?.dataset.sessionTemplate || '/uploads/__UUID__',
        chunkTemplate:
            el?.dataset.chunkTemplate || '/uploads/__UUID__/chunks/__INDEX__',
        completeTemplate:
            el?.dataset.completeTemplate || '/uploads/__UUID__/complete',
        maxBytes: parseInt(el?.dataset.maxBytes || '0', 10) || null,
        chunkBytes: parseInt(el?.dataset.chunkBytes || '0', 10) || null,
        concurrency:
            parseInt(el?.dataset.concurrency || '0', 10) || DEFAULTS.concurrency,
    };

    return config;
}

function sessionUrl(uuid) {
    return readConfig().sessionTemplate.replace('__UUID__', encodeURIComponent(uuid));
}

function chunkUrl(uuid, index) {
    return readConfig()
        .chunkTemplate.replace('__UUID__', encodeURIComponent(uuid))
        .replace('__INDEX__', String(index));
}

function completeUrl(uuid) {
    return readConfig().completeTemplate.replace('__UUID__', encodeURIComponent(uuid));
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

/* ------------------------------------------------------------------ */
/* Errors                                                              */
/* ------------------------------------------------------------------ */

class UploadError extends Error {
    constructor(message, { status = 0, payload = null, retryable = false } = {}) {
        super(message);
        this.name = 'UploadError';
        this.status = status;
        this.payload = payload;
        this.retryable = retryable;
    }
}

function messageFrom(payload, status) {
    const errors = payload?.errors;
    if (errors && typeof errors === 'object') {
        const flat = Object.values(errors).flat().filter(Boolean);
        if (flat.length) return flat.join(' ');
    }

    if (payload?.message) return payload.message;

    switch (status) {
        case 401:
            return 'Your session has expired. Refresh the page and try again.';
        case 403:
            return 'You do not have permission to upload into this location.';
        case 413:
            return 'The server rejected this chunk as too large. Check the PHP upload limits.';
        case 419:
            return 'Your session expired (CSRF token). Refresh the page and try again.';
        case 422:
            return 'The upload was rejected by the server.';
        case 507:
            return 'The server ran out of storage space.';
        default:
            return status >= 500
                ? 'The server is temporarily unavailable.'
                : 'The upload failed.';
    }
}

function isRetryableStatus(status) {
    return status === 0 || status === 408 || status === 429 || status >= 500;
}

function toUploadError(error, status = 0) {
    if (error instanceof UploadError) return error;
    if (error?.name === 'AbortError') return error;
    return new UploadError(messageFrom(null, status), { status, retryable: true });
}

/* ------------------------------------------------------------------ */
/* Transport                                                           */
/* ------------------------------------------------------------------ */

/**
 * XHR request with upload progress. Resolves with the parsed JSON body.
 * Rejects with UploadError (retryable or not) or an AbortError DOMException.
 */
function request(url, { method = 'GET', body = null, onProgress = null, signal = null } = {}) {
    return new Promise((resolve, reject) => {
        if (signal?.aborted) {
            reject(new DOMException('Aborted', 'AbortError'));
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        xhr.setRequestHeader('Accept', 'application/json');
        if (csrfToken()) xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());

        if (onProgress && xhr.upload) {
            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) onProgress(event.loaded);
            };
        }

        const onAbort = () => xhr.abort();
        signal?.addEventListener('abort', onAbort, { once: true });

        const settle = (handler) => (value) => {
            signal?.removeEventListener('abort', onAbort);
            handler(value);
        };

        xhr.onload = settle(xhrLoad);
        xhr.onerror = settle(() =>
            reject(
                new UploadError('Network error — check your connection.', {
                    status: 0,
                    retryable: true,
                }),
            ),
        );
        xhr.ontimeout = settle(() =>
            reject(
                new UploadError('The connection timed out.', {
                    status: 0,
                    retryable: true,
                }),
            ),
        );
        xhr.onabort = settle(() => reject(new DOMException('Aborted', 'AbortError')));

        function xhrLoad() {
            let payload = null;
            try {
                payload = xhr.responseText ? JSON.parse(xhr.responseText) : null;
            } catch (_) {
                payload = null;
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(payload);
                return;
            }

            reject(
                new UploadError(messageFrom(payload, xhr.status), {
                    status: xhr.status,
                    payload,
                    retryable: isRetryableStatus(xhr.status),
                }),
            );
        }

        // Content-Type is intentionally not set: the browser must add the
        // multipart boundary for FormData.
        xhr.send(body);
    });
}

function abortableSleep(ms, signal) {
    return new Promise((resolve, reject) => {
        if (signal?.aborted) {
            reject(new DOMException('Aborted', 'AbortError'));
            return;
        }

        const timer = setTimeout(() => {
            signal?.removeEventListener('abort', onAbort);
            resolve();
        }, ms);

        function onAbort() {
            clearTimeout(timer);
            reject(new DOMException('Aborted', 'AbortError'));
        }

        signal?.addEventListener('abort', onAbort, { once: true });
    });
}

function backoffDelay(attempt) {
    const delay = Math.min(
        DEFAULTS.maxDelayMs,
        DEFAULTS.baseDelayMs * Math.pow(2, attempt),
    );

    // Jitter keeps a flaky connection from retrying in lockstep across chunks.
    return Math.round(delay * (0.75 + Math.random() * 0.5));
}

/* ------------------------------------------------------------------ */
/* One file                                                            */
/* ------------------------------------------------------------------ */

/**
 * Stable client identity for a file. The server additionally verifies the byte
 * count of every chunk, so this is a resume key, not a trust anchor.
 */
export function fingerprintFor(file) {
    return `${file.name}:${file.size}:${file.lastModified || 0}`;
}

class UploadJob {
    constructor(file, { spaceId, folderId }) {
        this.file = file;
        this.spaceId = spaceId;
        this.folderId = folderId;
        this.state = 'queued';
        this.sentBytes = 0;
        this.error = null;
        this.session = null;
        this.controller = new AbortController();
        this.onUpdate = null;
    }

    get signal() {
        return this.controller.signal;
    }

    cancel() {
        this.controller.abort();
    }

    emit() {
        if (typeof this.onUpdate === 'function') this.onUpdate(this);
    }

    set(state, extra = {}) {
        this.state = state;
        Object.assign(this, extra);
        this.emit();
    }

    async start() {
        const cfg = readConfig();

        if (cfg.maxBytes && this.file.size > cfg.maxBytes) {
            throw new UploadError(
                `${this.file.name} is larger than the ${formatBytes(cfg.maxBytes)} upload limit.`,
            );
        }

        if (this.file.size < 1) {
            throw new UploadError(`${this.file.name} is empty.`);
        }

        this.set('starting');

        this.session = await this.initSession();

        // A file that was fully uploaded (but never finalised, or finalised
        // before a reload) needs nothing but a fresh "complete" call.
        if (this.session.status === 'completed') {
            this.sentBytes = this.file.size;
            this.set('completed');
            return;
        }

        this.set('uploading');

        await this.sendChunks();
        await this.finish();
    }

    async initSession() {
        const body = new FormData();
        body.append('space_id', String(this.spaceId));
        if (this.folderId) body.append('folder_id', String(this.folderId));
        body.append('name', this.file.name);
        body.append('mime_type', this.file.type || '');
        body.append('size', String(this.file.size));
        body.append('fingerprint', fingerprintFor(this.file));

        const session = await withRetry(
            () => request(readConfig().store, { method: 'POST', body, signal: this.signal }),
            this.signal,
        );

        this.sentBytes = session.received_bytes || 0;
        this.emit();

        return session;
    }

    /**
     * Send every missing chunk through a small worker pool. The server is
     * authoritative about what is missing, so a resumed upload skips straight
     * to the gaps.
     */
    async sendChunks() {
        const cfg = readConfig();
        const chunkSize = cfg.chunkBytes || this.session.chunk_bytes;
        const total = this.session.total_chunks;
        const pending = [...(this.session.missing || Array.from({ length: total }, (_, i) => i))];

        const workers = Array.from(
            { length: Math.min(cfg.concurrency, pending.length) },
            () => this.worker(pending, chunkSize),
        );

        await Promise.all(workers);
    }

    async worker(pending, chunkSize) {
        while (pending.length) {
            if (this.signal.aborted) throw new DOMException('Aborted', 'AbortError');

            const index = pending.shift();
            const start = index * chunkSize;
            const blob = this.file.slice(start, Math.min(start + chunkSize, this.file.size));

            await this.sendChunk(index, blob);
        }
    }

    async sendChunk(index, blob) {
        const base = this.sentBytes;

        try {
            await withRetry(
                () =>
                    request(chunkUrl(this.session.uuid, index), {
                        method: 'PUT',
                        body: formChunk(blob),
                        signal: this.signal,
                        onProgress: (bytes) => {
                            this.sentBytes = base + bytes;
                            this.emit();
                        },
                    }),
                this.signal,
            );
        } catch (error) {
            // Never show progress for bytes the server did not accept.
            this.sentBytes = base;
            this.emit();
            throw error;
        }

        this.sentBytes = base + blob.size;
        this.emit();
    }

    async finish() {
        this.set('finishing');

        let session = await withRetry(
            () =>
                request(completeUrl(this.session.uuid), {
                    method: 'POST',
                    body: new FormData(),
                    signal: this.signal,
                }),
            this.signal,
        );

        // Assembly runs on a queue; keep the row honest until it lands.
        session = await this.waitForAssembly(session);

        if (session.status === 'failed') {
            throw new UploadError(
                session.failure_reason || 'The server could not assemble the file.',
            );
        }

        this.set('completed');
        return session;
    }

    async waitForAssembly(session) {
        if (session.status !== 'assembling') return session;

        this.set('assembling');

        for (let attempt = 0; attempt < DEFAULTS.pollAttempts; attempt++) {
            await abortableSleep(DEFAULTS.pollIntervalMs, this.signal);
            session = await request(sessionUrl(this.session.uuid), { signal: this.signal });

            if (session.status !== 'assembling') return session;
            this.set('assembling');
        }

        throw new UploadError(
            'The server is still assembling the file. It will appear once processing finishes.',
        );
    }
}

function formChunk(blob) {
    const body = new FormData();
    // The filename makes PHP's upload machinery treat the part as a real file;
    // without it some SAPI configurations drop the part silently.
    body.append('chunk', blob, 'chunk');
    return body;
}

async function withRetry(operation, signal) {
    let lastError = null;

    for (let attempt = 0; attempt < DEFAULTS.maxAttempts; attempt++) {
        try {
            return await operation();
        } catch (error) {
            if (error?.name === 'AbortError') throw error;

            lastError = toUploadError(error);
            if (!lastError.retryable || attempt === DEFAULTS.maxAttempts - 1) throw lastError;

            await abortableSleep(backoffDelay(attempt), signal);
        }
    }

    throw lastError || new UploadError('The upload failed.');
}

/* ------------------------------------------------------------------ */
/* Progress panel                                                      */
/* ------------------------------------------------------------------ */

function formatBytes(bytes) {
    if (!bytes && bytes !== 0) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Number(bytes);
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value >= 10 || unit === 0 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

const STATE_LABELS = {
    queued: 'Waiting…',
    starting: 'Preparing…',
    uploading: 'Uploading',
    finishing: 'Finalising…',
    assembling: 'Processing on server',
    completed: 'Done',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

class UploadPanel {
    constructor() {
        this.root = null;
        this.visible = false;
        this.rows = new Map();
    }

    ensureRoot() {
        if (this.root && document.body.contains(this.root)) return;

        this.root = document.createElement('div');
        this.root.id = 'gedms-uploads-panel';
        this.root.className =
            'fixed bottom-4 right-4 z-[99999] w-96 max-w-[92vw] rounded-xl border border-slate-200 bg-white shadow-2xl overflow-hidden';
        this.root.setAttribute('role', 'status');
        this.root.setAttribute('aria-live', 'polite');
        document.body.appendChild(this.root);
    }

    show() {
        this.ensureRoot();
        this.root.classList.remove('hidden');
    }

    hide() {
        this.root?.classList.add('hidden');
    }

    add(job) {
        this.show();

        const row = document.createElement('div');
        row.className = 'px-4 py-3 border-b border-slate-100 last:border-b-0';
        row.innerHTML = `
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-slate-800 truncate" title=""></p>
                    <p class="text-xs text-slate-500 mt-0.5 status">&nbsp;</p>
                    <div class="mt-2 h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                        <div class="bar h-full w-0 rounded-full bg-blue-600 transition-[width] duration-200"></div>
                    </div>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" data-action="cancel"
                            class="hidden p-1 rounded text-slate-400 hover:text-slate-600 hover:bg-slate-100"
                            aria-label="Cancel upload">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                    <button type="button" data-action="dismiss"
                            class="hidden p-1 rounded text-slate-400 hover:text-slate-600 hover:bg-slate-100"
                            aria-label="Dismiss">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>`;

        const name = row.querySelector('p');
        name.textContent = job.file.name;
        name.title = job.file.name;

        const cancel = row.querySelector('[data-action="cancel"]');
        cancel.addEventListener('click', () => job.cancel());

        row.querySelector('[data-action="dismiss"]').addEventListener('click', () => {
            this.rows.delete(job);
            row.remove();
            if (!this.root.children.length) this.hide();
        });

        this.rows.set(job, {
            row,
            status: row.querySelector('.status'),
            bar: row.querySelector('.bar'),
            cancel,
            dismiss: row.querySelector('[data-action="dismiss"]'),
        });

        // Insert the row into the panel. Without this the panel becomes visible
        // but stays empty: the job runs, yet nothing is ever displayed.
        this.root.appendChild(row);

        this.render(job);
    }

    render(job) {
        const views = this.rows.get(job);
        if (!views) return;

        const { status, bar, cancel, dismiss } = views;
        const percent = job.file.size
            ? Math.min(100, Math.round((job.sentBytes / job.file.size) * 100))
            : 0;

        bar.style.width = `${percent}%`;

        const active = ['queued', 'starting', 'uploading', 'finishing', 'assembling'].includes(job.state);

        cancel.classList.toggle('hidden', job.state !== 'uploading');
        dismiss.classList.toggle('hidden', active || job.state === 'cancelled');

        switch (job.state) {
            case 'uploading':
                bar.className = 'bar h-full rounded-full bg-blue-600 transition-[width] duration-200';
                status.textContent = `${percent}% · ${formatBytes(job.sentBytes)} of ${formatBytes(job.file.size)}`;
                break;
            case 'assembling':
                bar.className = 'bar h-full rounded-full bg-indigo-500 transition-[width] duration-200';
                status.textContent = STATE_LABELS.assembling;
                break;
            case 'completed':
                bar.className = 'bar h-full rounded-full bg-emerald-500 transition-[width] duration-200';
                status.textContent = STATE_LABELS.completed;
                break;
            case 'failed':
                bar.className = 'bar h-full rounded-full bg-red-500 transition-[width] duration-200';
                status.textContent = job.error?.message || STATE_LABELS.failed;
                status.classList.add('text-red-600');
                break;
            case 'cancelled':
                bar.className = 'bar h-full rounded-full bg-slate-300 transition-[width] duration-200';
                status.textContent = STATE_LABELS.cancelled;
                break;
            default:
                status.textContent = STATE_LABELS[job.state] || job.state;
        }
    }
}

/* ------------------------------------------------------------------ */
/* Queue                                                               */
/* ------------------------------------------------------------------ */

class UploadQueue {
    constructor() {
        this.panel = new UploadPanel();
        this.jobs = [];
        this.draining = false;
        this.drainRequested = false;
    }

    get activeCount() {
        return this.jobs.filter(
            (job) => !['completed', 'failed', 'cancelled'].includes(job.state),
        ).length;
    }

    enqueue(files, { spaceId, folderId }) {
        if (!spaceId) {
            this.panel.ensureRoot();
            window.alert('Cannot upload: the destination space is unknown for this page.');
            return;
        }

        const queued = Array.from(files || []);
        if (!queued.length) return;

        queued.forEach((file) => {
            const job = new UploadJob(file, { spaceId, folderId });
            this.jobs.push(job);
            this.panel.add(job);

            // Re-render this row whenever the job's state or progress changes.
            // Without this the row is drawn once ("Waiting…") and never updates,
            // even though the upload itself proceeds.
            job.onUpdate = () => this.panel.render(job);
        });

        this.drain();
    }

    async drain() {
        // If a drain is already running, do not simply bail: the caller may have
        // just queued more files, and the running loop only picks up whatever it
        // has not read yet. Leaving `draining` set and returning here would drop
        // those files silently, so the running loop is told to keep going.
        if (this.draining) {
            this.drainRequested = true;
            return;
        }

        this.draining = true;
        this.drainRequested = false;

        try {
            // Files run sequentially: three parallel 8 MB chunks is already
            // plenty of pressure on a shared connection, and running files in
            // parallel makes the progress display meaningless.
            //
            // The `drainRequested` flag makes this a pump: a job that is queued
            // while an earlier, slow job is still running is picked up rather
            // than stranded. The loop only ends once nothing is queued.
            do {
                this.drainRequested = false;

                const pending = this.jobs.filter((job) => job.state === 'queued');

                for (const job of pending) {
                    // The page may have been left mid-drain by a reload; a job
                    // that is already running must not be started twice.
                    if (job.state !== 'queued') continue;

                    try {
                        await job.start();
                    } catch (error) {
                        if (error?.name === 'AbortError') {
                            job.set('cancelled');
                        } else {
                            const uploadError = toUploadError(error);
                            job.error = uploadError;
                            job.set('failed');
                        }
                    }
                }
            } while (this.drainRequested);
        } finally {
            this.draining = false;
        }

        this.finishCycle();
    }

    finishCycle() {
        const completed = this.jobs.filter((job) => job.state === 'completed').length;
        const unfinished = this.jobs.filter((job) =>
            ['queued', 'starting', 'uploading', 'finishing', 'assembling'].includes(job.state),
        );

        if (!completed || unfinished.length) return;

        // The file listing is server-rendered, so a reload is how the new files
        // become visible. Matches the behaviour of the old full-page POST.
        window.setTimeout(() => window.location.reload(), 900);
    }

    guardBeforeUnload() {
        window.addEventListener('beforeunload', (event) => {
            if (this.activeCount <= 0) return;
            event.preventDefault();
            event.returnValue = '';
        });
    }
}

/* ------------------------------------------------------------------ */
/* Wiring                                                              */
/* ------------------------------------------------------------------ */

let queue = null;

function getQueue() {
    if (!queue) {
        queue = new UploadQueue();
        queue.guardBeforeUnload();
    }

    return queue;
}

/**
 * Enqueue files for upload. Used by the drag-and-drop handler in
 * files/index.blade.php, which knows the folder the files were dropped on.
 */
function upload(files, { spaceId, folderId } = {}) {
    getQueue().enqueue(files, { spaceId, folderId });
}

/**
 * Upload the files held by a form's file input (space/folder come from the
 * form's hidden inputs, so every toolbar works without extra wiring).
 */
function submit(form, files = null) {
    const chosen = files || form.querySelector('input[type="file"]')?.files;

    upload(chosen, {
        spaceId: form.querySelector('input[name="space_id"]')?.value,
        folderId: form.querySelector('input[name="folder_id"]')?.value || null,
    });

    const input = form.querySelector('input[type="file"]');
    if (input) input.value = '';
}

function wireForms() {
    document.querySelectorAll('form[data-chunked-upload]').forEach((form) => {
        if (form.dataset.chunkedWired) return;
        form.dataset.chunkedWired = '1';

        // The forms keep their legacy action, so a page with JavaScript failed
        // to load still uploads through the classic endpoint.
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submit(form);
        });

        const input = form.querySelector('input[type="file"]');
        input?.addEventListener('change', () => {
            if (input.files.length) submit(form);
        });
    });
}

document.addEventListener('DOMContentLoaded', wireForms);
// Some toolbars are injected by Alpine after the initial parse.
document.addEventListener('DOMContentLoaded', () => setTimeout(wireForms, 0));

export const GedmsUploads = {
    submit,
    upload,
    wireForms,
    fingerprintFor,
};

window.GedmsUploads = GedmsUploads;
