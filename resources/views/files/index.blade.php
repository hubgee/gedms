<x-app-layout>
    <x-slot name="header">
        @if(in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived','favorites']))
            File Manager — {{ match($viewMode ?? '') { 'shared' => 'Shared with you', 'shared-by-me' => 'Shared by me', 'locked' => 'Locked Files', 'archived' => 'Archived', 'favorites' => 'Favorites', default => 'Files' } }}
        @else
            File Manager — {{ $space->name ?? '—' }}
        @endif
    </x-slot>

    <div class="space-y-4" x-data="driveManager({{ json_encode(['favoritedIds' => $favoritedIds ?? [], 'initialFileData' => $initialFileData ?? null]) }})"
         x-init="if (initialFileData) { selectFile(initialFileData); }">

        {{-- Endpoint contract for resources/js/uploads.js. URLs come from the
             router so the app keeps working in a subdirectory. --}}
        <div id="gedms-upload-config" class="hidden"
             data-endpoint="{{ route('uploads.store') }}"
             data-session-template="{{ route('uploads.show', '__UUID__') }}"
             data-chunk-template="{{ route('uploads.chunks.store', ['uuid' => '__UUID__', 'index' => '__INDEX__']) }}"
             data-complete-template="{{ route('uploads.complete', '__UUID__') }}"
             data-chunk-bytes="{{ config('uploads.chunk_bytes') }}"
             data-concurrency="{{ config('uploads.concurrency') }}"
             data-max-bytes="{{ config('uploads.max_bytes') }}"></div>
        @if (session('success'))
            <div class="card card-body bg-emerald-50 border-emerald-200 text-emerald-800">{{ session('success') }}</div>
        @endif

        @if(!in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived']))
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($spaces as $s)
                <a href="{{ route('files.index', ['space' => $s->id]) }}"
                   class="px-4 py-2.5 rounded-lg text-sm font-medium transition-all {{ $space && $s->id === $space->id ? 'bg-indigo-700 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50' }}">
                    {{ $s->name }}
                </a>
            @endforeach
        </div>
        @endif

        {{-- Right-side preview drawer: fixed overlay, high z-index so it stacks above toolbar --}}
        @if (!in_array($driveStyle ?? 'default', ['sharepoint']))
        <div x-show="selectedFile && detailsOpen" x-cloak
             class="fixed inset-0 z-[99998] bg-black/40"
             @click="selectedFile = null"
             @keydown.escape.window="selectedFile = null"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
        </div>
        <div x-show="selectedFile && detailsOpen" x-cloak
             class="fixed top-0 right-0 bottom-0 z-[99999] w-full sm:min-w-[480px] sm:w-[85vw] lg:w-[900px] max-w-[95vw] bg-white flex flex-col overflow-hidden border-l border-slate-200 shadow-2xl"
             x-ref="detailsPanel"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             @click.stop>
            @include('files.partials.preview-body-gov')
        </div>
        @endif

        {{-- Hub (dept/ministry) or Drive UI --}}
        @php
            $style = in_array($driveStyle ?? 'default', ['drive','sharepoint','dropbox','nextcloud']) ? $driveStyle : 'nextcloud';
        @endphp
        <div x-show="!selectedFile || !detailsOpen" x-transition:leave="transition ease-out duration-150">
            @if($isHub ?? false)
                <div class="card rounded-xl border border-slate-200 overflow-hidden">
                    <div class="border-b border-slate-200 px-4 py-3 bg-slate-50">
                        <nav class="flex items-center gap-1 text-sm text-slate-600" aria-label="Breadcrumb">
                            @foreach ($breadcrumbs ?? [] as $i => $crumb)
                                @if ($i > 0)<span class="text-slate-300">/</span>@endif
                                @if ($crumb['url'])
                                    <a href="{{ $crumb['url'] }}" class="hover:text-blue-600">{{ $crumb['name'] }}</a>
                                @else
                                    <span class="font-medium text-slate-800">{{ $crumb['name'] }}</span>
                                @endif
                            @endforeach
                        </nav>
                    </div>
                    @include('files.partials.hub')
                </div>
            @else
                @include('files.partials.drive-' . $style)
            @endif
        </div>
    </div>

    <script>
        {{-- Upload forms are wired by resources/js/uploads.js (forms marked
             data-chunked-upload), which attaches change/submit handlers. --}}
        document.addEventListener('alpine:init', () => {
            Alpine.data('driveManager', (props = {}) => ({
                selectedFile: null,
                initialFileData: props.initialFileData || null,
                dragOver: false,
                detailsOpen: true,
                previewSidebarOpen: true,
                // View mode / sidebar live on the shared component (not on the
                // individual drive partials) so the toolbar, the content pane
                // and the details drawer all read the same state.
                viewMode: props.viewMode || 'list',
                sidebarCollapsed: false,
                favoritedIds: props.favoritedIds || [],
                clipboard: { items: [], operation: null },
                renameModalOpen: false,
                renameTarget: null,
                renameName: '',
                selectFile(file) {
                    this.selectedFile = file;
                },
                previewable(mime) {
                    if (!mime) return false;
                    return mime.startsWith('image/') || mime === 'application/pdf' || mime.startsWith('text/')
                        || mime.startsWith('audio/') || mime.startsWith('video/')
                        || mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                },
                previewAsIframe(mime) {
                    if (!mime) return false;
                    return mime.startsWith('image/') || mime === 'application/pdf' || mime.startsWith('text/')
                        || mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                },
                previewAsImage(mime) {
                    return mime && mime.startsWith('image/');
                },
                previewAsAudio(mime) {
                    return mime && mime.startsWith('audio/');
                },
                previewAsVideo(mime) {
                    return mime && mime.startsWith('video/');
                },
                handleDrop(e, targetFolderId, targetSpaceId) {
                    this.dragOver = false;
                    const json = e.dataTransfer.getData('application/x-gedms-drag');
                    if (json) {
                        try {
                            const data = JSON.parse(json);
                            const items = Array.isArray(data) ? data : [{ type: data.type, id: data.id }];
                            if (items.length && targetSpaceId) {
                                const form = document.getElementById('bulk-paste-form');
                                if (form) {
                                    form.querySelector('input[name="folder_id"]').value = targetFolderId || '';
                                    form.querySelector('input[name="space_id"]').value = targetSpaceId;
                                    form.querySelector('input[name="operation"]').value = 'cut';
                                    const cont = form.querySelector('[data-items]');
                                    if (cont) {
                                        cont.innerHTML = items.map((i, idx) =>
                                            `<input type="hidden" name="items[${idx}][type]" value="${i.type}"><input type="hidden" name="items[${idx}][id]" value="${i.id}">`
                                        ).join('');
                                    }
                                    form.submit();
                                }
                            }
                        } catch (_) {}
                        return;
                    }
                    const files = e.dataTransfer.files;
                    const form = document.getElementById('drop-upload-form');
                    if (files && files.length && form) {
                        // Route dropped files through the chunked uploader. It
                        // uploads into the folder that was dropped on, which is
                        // what people expect when they drop on a sub-folder row.
                        if (window.GedmsUploads && targetSpaceId) {
                            window.GedmsUploads.upload(files, {
                                spaceId: targetSpaceId,
                                folderId: targetFolderId || null,
                            });
                        } else {
                            const input = document.getElementById('drop-file-input');
                            const dt = new DataTransfer();
                            dt.items.add(files[0]);
                            input.files = dt.files;
                            form.submit();
                        }
                    }
                },
                /**
                 * Selection is mirrored into reactive state so Alpine re-renders
                 * the bulk toolbar. Reading `:checked` straight from the DOM does
                 * not create a reactive dependency, which is why the toolbar used
                 * to stay hidden. `getSelectedIds()`/`getSelectedItems()` keep
                 * reading the DOM so the submitting forms stay authoritative.
                 */
                selectedIds: [],
                syncSelection() {
                    this.selectedIds = this.getSelectedIds();
                },
                updateSelectionFromEvent(e) {
                    const cb = e.target;
                    const id = parseInt(cb.value, 10);
                    if (isNaN(id)) return;
                    if (cb.checked) {
                        if (!this.selectedIds.includes(id)) this.selectedIds = [...this.selectedIds, id];
                    } else {
                        this.selectedIds = this.selectedIds.filter(selected => selected !== id);
                    }
                },
                toggleAll(e) {
                    const checked = e.target.checked;
                    document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = checked);
                    this.syncSelection();
                },
                getSelectedIds() {
                    return Array.from(document.querySelectorAll('.file-checkbox:checked')).map(cb => parseInt(cb.value, 10)).filter(id => !isNaN(id));
                },
                getSelectedItems() {
                    const files = [], folders = [];
                    document.querySelectorAll('.file-checkbox:checked').forEach(cb => {
                        const id = parseInt(cb.value, 10);
                        if (isNaN(id)) return;
                        const type = cb.getAttribute('data-type') || 'file';
                        (type === 'folder' ? folders : files).push({ type, id });
                    });
                    return { files, folders, all: [...files, ...folders] };
                },
                clearSelection() {
                    document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = false);
                    this.syncSelection();
                },
                copyToClipboard(items) {
                    this.clipboard = { items: items, operation: 'copy' };
                },
                cutToClipboard(items) {
                    this.clipboard = { items: items, operation: 'cut' };
                },
                hasClipboard() {
                    return this.clipboard.items && this.clipboard.items.length > 0;
                },
                submitPaste(targetFolderId) {
                    const items = this.hasClipboard() ? this.clipboard.items : this.getSelectedItems().all;
                    const op = this.hasClipboard() ? this.clipboard.operation : 'cut';
                    if (!items.length) return;
                    const form = document.getElementById('bulk-paste-form');
                    if (!form) return;
                    form.querySelector('input[name="folder_id"]').value = targetFolderId || '';
                    form.querySelector('input[name="operation"]').value = op;
                    const cont = form.querySelector('[data-items]');
                    if (cont) {
                        cont.innerHTML = items.map((i, idx) =>
                            `<input type="hidden" name="items[${idx}][type]" value="${i.type}"><input type="hidden" name="items[${idx}][id]" value="${i.id}">`
                        ).join('');
                    }
                    form.submit();
                },
                openRenameModal(target) {
                    this.renameTarget = target;
                    this.renameName = target?.name || '';
                    this.renameModalOpen = true;
                },
                closeRenameModal() {
                    this.renameModalOpen = false;
                    this.renameTarget = null;
                    this.renameName = '';
                },
                isFavorited(fileId) {
                    return (this.favoritedIds || []).includes(fileId);
                },
                setFavorited(fileId, fav) {
                    if (fav) this.favoritedIds = [...(this.favoritedIds || []), fileId];
                    else this.favoritedIds = (this.favoritedIds || []).filter(id => id !== fileId);
                },
                // --- Toolbar / bulk-action helpers ---------------------------------
                // The drive partials own small Alpine scopes (view mode, context
                // menu, share modal). The shared state lives here on the single
                // `driveManager` component; every partial reaches it through
                // normal Alpine scope inheritance instead of $parent chains,
                // which break as soon as a partial gains or loses an x-data
                // wrapper, or is rendered inside x-teleport / x-if.
                selectedCount() {
                    // Must read the reactive array (not the DOM): only then does
                    // Alpine track this as a dependency of x-show/x-text and
                    // re-render the bulk toolbar when the selection changes.
                    return this.selectedIds.length;
                },
                selectedCountLabel() {
                    return this.selectedIds.length + ' selected';
                },
                moveSelectedTo(folderId) {
                    this.submitPaste(folderId);
                }
            }));
        });
    </script>
</x-app-layout>
