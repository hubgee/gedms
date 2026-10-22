# Cloud storage test plan

EDMS used to keep every uploaded byte on the local `edms` disk, where the only
way a write can fail is a full or dying filesystem. A cloud provider fails in
many more ways: it is slow, it drops connections mid-transfer, it accepts a
write and cannot see it for a moment, it runs out of quota, it refuses to copy
an object because a part is too small, and it charges for every byte that moves.

This plan describes how those conditions are reproduced and asserted. It covers
three substitute backends, three independent layers of fault injection, the
scenario matrix that ties them together, and the limits of what the current
implementation can do.

The Drive sidebar's **External Storage** entry is still a "Coming soon"
placeholder in `resources/views/files/partials/drive-nextcloud.blade.php`, so
none of this is that feature. The integration is the byte-store seam plus the
test harness that prove the upload pipeline works against a real cloud provider
today, and the foundation the feature will be built on later (see the roadmap at
the end).

## Requirement map

| Requirement | How it is simulated | Where |
|---|---|---|
| Network instability | Transient failures at a configurable rate, connections that die mid-chunk, stale reads; TCP resets and hard byte limits through a proxy | `CloudFaultProfile`, `FaultInjectingUploadStorage`, Toxiproxy |
| Latency and throughput | Per-operation latency, jitter and a bandwidth ceiling; proxy latency/jitter/bandwidth; browser-side throttling | `FaultInjectingUploadStorage`, Toxiproxy, CDP |
| Cloud-specific upload edge cases | S3 multipart-copy limits, Dropbox ordered append sessions, ranged reads, missing local paths, eventual consistency, quota exhaustion, cleanup of unfinished objects | `S3UploadStorage`, `DropboxUploadStorage`, the contract |
| Client behaviour under those conditions | Slow/offline browser, chunk retry/backoff, resume, cancel | Chrome DevTools / Playwright CDP |

## The seam under test

Everything that touches bytes goes through `App\Contracts\UploadStorage`, so a
driver swap changes chunks, published files, downloads, previews and copies at
the same time:

| Operation | Local | S3-compatible | Dropbox |
|---|---|---|---|
| `putChunk()` / `putFile()` | atomic write through a `.part` file and rename | single PUT | simple upload (one file per chunk) |
| `assemble()` | streamed concatenation | `UploadPartCopy` (server-side), streaming fallback | download + ordered upload session |
| `readStream()` | `fopen`/`fseek` | ranged `GetObject` | ranged `files/download` |
| `readHead()` | first bytes of the file | ranged `GetObject` | ranged `files/download` |
| `copy()` | filesystem copy | server-side `CopyObject` | `files/copy_v2` |
| `localPath()` | absolute path | `null` | `null` |

The pipeline never needs a local path: the executable-payload check and the MIME
sniff read `readHead()` instead of opening the file. That is the change that
makes the cloud backends honest instead of silently skipping checks.

The driver is chosen by `UPLOAD_STORAGE_DRIVER` (`local`, `s3`, `dropbox`). The
binding is **scoped**, not a singleton: the fault injector keeps a little
per-request state (the stale reads), and that state must survive within one
request or one queue job but must not leak into the next job.

## Invariants that must hold on every backend

1. **Bytes before index.** A chunk row is written only after the bytes are
   stored; a failed write leaves no partial row.
2. **The store is the source of truth.** `reconcileChunks()` asks the backend
   for the chunk size and treats a short or missing object as missing, even if
   the index says it arrived.
3. **Publish only after verification.** Size and, when the client declared one,
   SHA-256 must match before `File` and `FileVersion` rows are created.
4. **Assembly is idempotent.** The destination file UUID is the session UUID, so
   a retried job does not create a second copy.
5. **Missing chunks are recoverable.** A session whose bytes disappeared returns
   to `uploading` and the client re-sends only the gaps.
6. **No untrusted executable content.** A payload starting with `<?php` or `<?=`
   is rejected and the assembled object is deleted, on every backend.
7. **Reads are ranged.** A preview of a 10 GB object must not download 10 GB.
8. **One store for everything.** Uploading to a bucket and serving downloads
   from the local disk must be impossible by configuration.
9. **Nothing is left behind.** Temporary chunk directories and rejected or
   half-assembled objects are removed.

## Three layers of fault injection

No single layer can reproduce every failure, and each layer has a different cost
and a different blind spot. Use all three:

| Layer | Tool | Controls | Proves | Cannot prove |
|---|---|---|---|---|
| Application | `FaultInjectingUploadStorage` decorator | Latency, jitter, bandwidth, transient failure rate, mid-transfer truncation, quota, stale reads | The state machine, the index and the retry logic react correctly; runs in CI with no account | Real TLS/DNS errors, provider-specific status codes, concurrency, packet loss |
| Wire | Toxiproxy in front of an S3 endpoint | Latency, jitter, bandwidth, timeouts, connection resets, byte limits | The application-level faults are not fiction: the same behaviour happens when the socket dies | Provider internals, signing errors, per-request quotas; needs the endpoint to be proxyable |
| Client | Chrome DevTools Protocol / Playwright | Offline, added latency, upload/download throughput | `resources/js/uploads.js` retries, backs off, resumes and reports progress | Anything on the server-to-provider leg |

The application layer is the default for automated tests because it is
deterministic enough to assert on and needs no infrastructure. The wire layer is
the acceptance test for "did we model the network correctly". The client layer
is the only one that exercises the browser uploader.

### Layer 1: the application decorator

`App\Services\Uploads\FaultInjectingUploadStorage` wraps any backend and applies
one `CloudFaultProfile` to every operation. Each knob is off by default.

| Knob | Effect |
|---|---|
| `latency_ms` | Sleep before every storage operation |
| `jitter_ms` | Add a random 0..N ms on top |
| `bandwidth_bps` | Sleep proportional to the bytes moved, so throughput never exceeds the ceiling |
| `failure_rate` | Fail an operation with "temporarily unavailable" with probability 0..1 |
| `drop_after_bytes` | Store only the first N bytes of a larger chunk, then throw "connection dropped" |
| `quota_exceeded` | Every operation fails with "quota is exhausted" |
| `eventual_consistency_reads` | The first N reads after a write report the object as missing |

Named presets (`php artisan edms:upload-chaos --list`):

| Preset | Settings | What it rehearses | Expected result |
|---|---|---|---|
| `clean` | none | Baseline | Round trip intact, one attempt per phase |
| `mobile-3g` | 300 ms latency, 200 ms jitter, 400 KB/s | Phone on a congested cell | Slower but complete; no timeouts |
| `satellite` | 1200 ms latency, 300 ms jitter, 2 MB/s | Long-distance link | Complete; retries, if any, succeed |
| `flaky` | 50 ms latency, 20% failures | Provider having a bad afternoon | Only successful operations are indexed; final hash matches |
| `drop-mid-transfer` | 20 ms latency, cut after 1 MiB | Connection dies mid-chunk | Short object detected, only that chunk re-sent |
| `quota-exhausted` | quota on | Full bucket | Clear 422 on `chunk`, session left `pending`, no rows |
| `eventually-consistent` | 20 ms latency, 2 stale reads | Provider with read lag | Reconcile reports the chunk missing, then it heals |

A custom profile can be set through `UPLOAD_FAULT_*` in `.env` without touching
code. Latency is per operation, so it accumulates: an 8 MB chunk takes one
operation, but a 1 GiB run with 8 MB chunks performs 128 writes plus the
assembly and will spend roughly `128 x latency` extra.

### Layer 2: the wire

Toxiproxy is a small TCP proxy. It is installed once and put between the
application and MinIO/S3; the application keeps talking HTTP, the proxy applies
the fault.

```powershell
# One-time: download toxiproxy-server and toxiproxy-cli for Windows from
# https://github.com/Shopify/toxiproxy/releases and put both on PATH.
toxiproxy-server

# Proxy 127.0.0.1:9100 -> MinIO on 127.0.0.1:9000
toxiproxy-cli create s3 --listen 127.0.0.1:9100 --upstream 127.0.0.1:9000
```

Point the application at the proxy (`AWS_ENDPOINT=http://127.0.0.1:9100`) and
add a toxic before a run:

| Toxic | Example | Models |
|---|---|---|
| `latency` | `toxiproxy-cli toxic add s3 -t latency -a latency=800 -a jitter=200` | High RTT, variable link |
| `bandwidth` | `toxiproxy-cli toxic add s3 -t bandwidth -a rate=100` (KB/s) | Thin uplink |
| `reset_peer` | `toxiproxy-cli toxic add s3 -t reset_peer -a timeout=3000` | Connection reset mid-request |
| `limit_data` | `toxiproxy-cli toxic add s3 -t limit_data -a bytes=1048576` | Link that dies after N bytes |
| `timeout` | `toxiproxy-cli toxic add s3 -t timeout -a timeout=5000` | Provider that never answers |

Remove a toxic with `toxiproxy-cli toxic remove s3 -t latency`; remove the proxy
with `toxiproxy-cli delete s3`.

Toxiproxy is TCP-level, so it fits the plain-HTTP MinIO endpoint cleanly. It does
not fit the Dropbox client, whose base URL is a constant and whose traffic is
TLS: proxying `api.dropboxapi.com:443` would need certificate interception and a
configurable base URL. For Dropbox use layer 1 plus layer 3, or add a
configurable endpoint later (roadmap item).

### Layer 3: the browser

The client uploader is throttled in the browser, not on the server:

```js
const cdp = await context.newCDPSession(page);

await cdp.send('Network.emulateNetworkConditions', {
  offline: false,
  latency: 400,                          // ms added to every request
  downloadThroughput: 50 * 1024,         // bytes/second
  uploadThroughput: 50 * 1024,           // bytes/second
  connectionType: 'cellular3g',
});
```

Set `offline: true` to rehearse a complete loss of connectivity, or call
`cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0,
downloadThroughput: -1, uploadThroughput: -1 })` to restore.

Assert: the progress panel keeps moving when the link recovers, only missing
chunks are re-sent, a reload resumes instead of restarting, cancel stops the
requests, and the file that lands matches the local file byte for byte.

## Running the application-level harness

`php artisan edms:upload-chaos` is the executable half of this plan. It performs
the same sequence as a real upload against whatever the contract is bound to:
write every chunk, assemble, read the head, read a range, copy, verify SHA-256.
Nothing is mocked.

| Flag | Default | Meaning |
|---|---|---|
| `--preset=` | config | Fault profile from the table above |
| `--list` | | Print the presets and exit |
| `--driver=` | `uploads.storage_driver` | `local`, `s3` or `dropbox` |
| `--bytes=` | 1 MiB | Synthetic file size |
| `--chunk-bytes=` | 64 KiB | Chunk size |
| `--attempts=` | 3 | Retries per failing phase |
| `--keep` | off | Leave the bytes in the store for inspection |
| `--json` | off | Machine-readable report |

Exit code `0` means every phase passed and the assembled bytes match the bytes
that were sent; `1` means at least one phase failed (a preset that exhausts the
quota is expected to exit 1).

```powershell
php artisan edms:upload-chaos --preset=clean
php artisan edms:upload-chaos --preset=flaky --attempts=5
php artisan edms:upload-chaos --preset=drop-mid-transfer --bytes=4194304 --chunk-bytes=2097152
php artisan edms:upload-chaos --preset=clean --driver=s3 --bytes=33554432 --chunk-bytes=8388608
php artisan edms:upload-chaos --preset=clean --driver=dropbox --bytes=1048576 --chunk-bytes=262144
```

Chunk size chooses the S3 code path: with chunks below 5 MiB the assembly falls
back to streaming through the application; with 5 MiB or more (the 8 MiB default)
it uses server-side multipart copy. Test both deliberately.

## Provider setup

### Local baseline

No configuration; this is the default.

```powershell
php artisan edms:upload-chaos --preset=clean
```

### S3-compatible (recommended primary)

This is the primary cloud target because S3's API is what most providers
implement, and `UploadPartCopy` lets the assembly happen server-side, inside the
provider, without pulling the bytes back through PHP. AWS S3, MinIO, Cloudflare
R2, DigitalOcean Spaces, Wasabi and Backblaze B2 all work by changing the disk
configuration.

MinIO is the recommended local target. It is a single binary, so it needs no
Docker (which is not installed on this machine):

```powershell
minio.exe server C:\minio-data --console-address ":9001"

mc.exe alias set local http://127.0.0.1:9000 minioadmin minioadmin
mc.exe mb local/edms
```

```dotenv
UPLOAD_STORAGE_DRIVER=s3
UPLOAD_CLOUD_DISK=edms-cloud
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=edms
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

For a hosted bucket, change `AWS_*` only. R2 uses `AWS_DEFAULT_REGION=auto` and
path-style access; Spaces and Wasabi use their regional endpoint.

Add an "abort incomplete multipart uploads after 1 day" lifecycle rule to the
bucket. A crashed assembly leaves an upload id behind that keeps costing storage
until it is aborted or expired; the application aborts on handled failures, but
a killed worker cannot.

Verify the whole application, not just the harness:

```powershell
php artisan edms:upload-chaos --preset=clean --driver=s3 --bytes=33554432 --chunk-bytes=8388608 --keep
mc.exe ls --recursive local/edms
mc.exe stat local/edms/chaos/.../assembled.bin
```

The harness prints the prefix when `--keep` is used. `mc stat` should report the
same size as `--bytes`; the reported `verified: true` and `ok: true` mean the
bytes that came back hash to the bytes that were sent.

### Dropbox

Dropbox is the second real backend. It is useful precisely because it is *not*
an object store: chunks are ordinary files, assembly appends to an upload
session strictly in order, and the whole file moves back through the application
(roughly 2x egress). That makes it a good stress test for the ordering and
cleanup logic.

Setup:

1. Create an app at <https://www.dropbox.com/developers/apps> with **scoped
   access** and **App folder** access.
2. Enable the scopes `files.content.write`, `files.content.read` and
   `files.metadata.read`.
3. Authorise once with `token_access_type=offline`:

   ```text
   https://www.dropbox.com/oauth2/authorize?client_id=<APP_KEY>&response_type=code&token_access_type=offline
   ```

4. Exchange the code for a refresh token:

   ```powershell
   curl.exe https://api.dropboxapi.com/oauth2/token `
     -d grant_type=authorization_code `
     -d code=<CODE> `
     -d client_id=<APP_KEY> `
     -d client_secret=<APP_SECRET>
   ```

5. Configure:

   ```dotenv
   UPLOAD_STORAGE_DRIVER=dropbox
   DROPBOX_APP_KEY=...
   DROPBOX_APP_SECRET=...
   DROPBOX_REFRESH_TOKEN=...
   DROPBOX_ROOT=/edms
   ```

```powershell
php artisan edms:upload-chaos --preset=clean --driver=dropbox --bytes=1048576 --chunk-bytes=262144
```

Notes:

- Dropbox allows up to 150 MB per upload request, so 8 MB chunks are comfortably
  inside the limit.
- Assembly is sequential by API design. Do not expect parallel assembly.
- Avoid routine multi-gigabyte Dropbox runs: the assembly downloads and
  re-uploads every byte. A 1 GB run is enough to prove the path; the cost model
  is documented below.

## Scenario matrix

Every row is a test that must pass before the cloud backends are considered
ready. "Automated" rows already exist in the suite; the rest are manual or
nightly runs.

| # | Scenario | Layer / setup | Pass criteria | Automated |
|---|---|---|---|---|
| 1 | Clean round trip, all three drivers | `--preset=clean --driver=...` | `ok`, `verified`, SHA-256 matches | Yes (local, Dropbox fake) |
| 2 | Transient provider failure | `flaky` or `UPLOAD_FAULT_FAILURE_RATE=0.2` | 422 on `chunk`, no index row, retry then completes | Yes |
| 3 | Quota exhausted | `quota-exhausted` | 422 "quota is exhausted", session `pending`, no rows | Yes |
| 4 | Connection cut mid-chunk | `drop-mid-transfer` (chunk > 1 MiB) | Short object stored, chunk reported missing, retry overwrites it, final hash matches | Yes |
| 5 | Connection reset on the wire | Toxiproxy `reset_peer` | Same as 4, but the failure arrives as a transport error | Manual |
| 6 | Stale read after write | `eventually-consistent` | `reconcileChunks()` reports the chunk missing, then it appears | Yes |
| 7 | Assembly hash mismatch | Tamper a chunk, or declare a wrong SHA | `UploadVerificationException`, destination deleted, no `File` row | Yes |
| 8 | Executable payload through a cloud store | Upload `<?php ...` via Dropbox/S3 | Rejected by the `readHead()` check, object deleted, no `File` row | Yes (Dropbox fake) |
| 9 | Worker dies mid-assembly | Stop `queue:work` during `assembling`, then `edms:prune-uploads` | Session requeued, one `File`, bytes intact | Manual |
| 10 | Chunks vanish before assembly | Delete a chunk from the bucket, then retry | Session returns to `uploading`, gaps reported, re-send heals | Manual |
| 11 | 10 GB file on S3 | `--bytes=10737418240 --chunk-bytes=8388608` | `verified`, `UploadPartCopy` used (trace shows `x-amz-copy-source`), no 10 GB temp file, no OOM | Manual, nightly |
| 12 | Dropbox ordered assembly | `--driver=dropbox --bytes=1 MiB` | Appends at offsets `0, 8, 16, ...`; the file is byte-identical | Yes (fake) |
| 13 | Ranged preview/download | Request `Range: bytes=4-9` on a cloud file | `206`, correct `Content-Range`, body is exactly the slice | Yes (Dropbox fake) |
| 14 | Copy a published file | `FileService::copy()` on `s3`/`dropbox` | Copy hashes equal to the original; S3 does it server-side | Manual (S3), fake (Dropbox) |
| 15 | Temporary directory cleanup | Successful assembly, then `isEmpty('tmp/{uuid}')` | All chunk objects gone | Yes (Dropbox fake) |
| 16 | Prune leftovers on a bucket | `edms:prune-uploads --ttl` with an orphaned prefix | `directories('tmp')` finds it, directory is removed | Manual |
| 17 | Slow mobile link in the browser | CDP throttling (layer 3) | Progress continues, retries with backoff, resume after reload, cancel works | Manual |
| 18 | Three concurrent uploads | Three browser tabs or three harness runs | No cross-contamination, all three verify | Manual |
| 19 | Wrong configuration fails loudly | `--driver=dropbox` without credentials; bucket missing | Clear exception naming the missing variable/disk | Yes (Dropbox credentials) |
| 20 | Local driver never touches the network | Default config, `Http::preventStrayRequests()` | No HTTP request is attempted | Yes |

## Automated coverage

Run the cloud half of the suite:

```powershell
php artisan test --filter="CloudUploadStorage"
php artisan test --filter="S3UploadStorage"
php artisan test --filter="Upload"
```

| Test file | Coverage |
|---|---|
| `tests/Feature/Uploads/CloudUploadStorageTest.php` | Fault decorator: transient failure, quota, mid-chunk truncation and retry, stale reads, wrong hash, assembly mismatch. Dropbox: ordered appends `[0, 8, 16]`, ranged read `bytes=4-9`, executable rejection with object deletion, download through `FileService`, server-side copy, truncated chunk reconciliation, temp cleanup, factory driver selection, unknown driver, missing credentials, local driver makes no HTTP calls |
| `tests/Unit/S3UploadStorageTest.php` | Multipart-copy decision: 5 MiB minimum, last-part exception, 10 000-part ceiling, 5 GiB copy ceiling, unreadable sizes |
| `tests/Support/FakeDropbox.php` | In-memory Dropbox API v2 for `Http::fake()`: token, upload, upload sessions with strict offset checks, ranged download, metadata, delete, list, copy |

The Dropbox fake is deliberately strict about offsets: an out-of-order append
gets a `409 incorrect_offset` just like the real API. That is what makes the
"append in order" assertion meaningful rather than decorative.

## Limits and known gaps

These are properties of the providers, not bugs to paper over. Each one has a
defined behaviour and a test.

| Limit | Number | What the code does | How to test |
|---|---|---|---|
| S3 minimum non-final part | 5 MiB | Falls back to streaming concatenation when any non-final chunk is smaller | `S3UploadStorageTest`; tiny chunks in the harness |
| S3 maximum part (copy) | 5 GiB | Falls back to streaming | Unit test with an oversized part |
| S3 maximum parts | 10 000 | Falls back to streaming | Unit test at 10 001 parts |
| Application file ceiling | 10 GB | 1 280 parts at 8 MB, well below the part limit | 10 GB soak run |
| S3 copy is server-side | n/a | Bytes never enter PHP; assembly cost is one request per chunk | MinIO trace shows `UploadPartCopy` |
| Dropbox assembly egress | ~2x file size | Downloads each chunk and re-uploads it into the session | Egress accounting on a 1 GB run |
| Dropbox append order | strict | Chunks are appended in index order; an offset mismatch fails the assembly | `CloudUploadStorageTest` ordered-append test |
| No local path on cloud | n/a | `localPath()` returns `null`; checks use `readHead()` | Executable-payload test on Dropbox |
| Read-after-write | varies | `size()` returns `null` on a failed HEAD, so reconcile treats the chunk as missing instead of trusting the index | `eventually-consistent` preset |
| Incomplete multipart uploads | n/a | Aborted on handled failures; lifecycle rule covers killed workers | Bucket lifecycle + crash test |
| Provider errors surface | n/a | The `edms-cloud` disk uses `throw => true`, so a failed provider call raises instead of returning `false` | Point the disk at a stopped MinIO |
| Dropbox base URL | hard-coded | Wire-level proxying needs certificate interception; layer 1 + layer 3 cover it for now | Roadmap: configurable endpoint |

One deliberate trade-off: the Dropbox assembly re-downloads every chunk. For a
10 GB file that is 10 GB down plus 10 GB up, so assembly is the dominant cost.
The alternative, a single simple upload, cannot resume or stream, and would move
the failure window to the largest request instead of the smallest. The current
choice keeps every step retryable at chunk granularity.

## Soak and evidence

A cloud backend is not "tested" by one green run. For each provider, record:

- the harness `--json` report and exit code for every preset;
- `mc stat` (or the Dropbox metadata) of the assembled object: size and hash;
- the session and `upload_chunks` rows after the run;
- for S3, a short `mc admin trace` excerpt showing `UploadPartCopy`;
- wall-clock time and peak PHP memory for a 1 GB and a 10 GB run;
- the bucket listing before and after cleanup (`--keep` off) to prove no leftovers.

Suggested nightly sequence against MinIO, with the proxy in front:

```powershell
$presets = 'clean','mobile-3g','satellite','flaky','drop-mid-transfer','eventually-consistent'

foreach ($preset in $presets) {
    php artisan edms:upload-chaos --preset=$preset --driver=s3 --bytes=67108864 --chunk-bytes=8388608 --json
}
```

`quota-exhausted` is excluded from the loop because exit code `1` is the correct
outcome; run it separately and assert the error message instead.

## Roadmap to the real External Storage feature

**Phase 1, present.** Byte-store seam, local/S3/Dropbox drivers, fault decorator,
chaos harness, automated cloud tests. No user-facing change.

**Phase 2, staging soak.** MinIO and Toxiproxy in CI or a nightly job; a 10 GB
S3 run; a 1 GB Dropbox run; bucket lifecycle and cleanup verified.

**Phase 3, hardening.** Credentials from a secret store, per-space buckets or
prefixes, provider health checks, retry/backoff tuned from soak data, an
incomplete-upload metrics/alarm, configurable provider endpoints for wire-level
Dropbox testing.

**Phase 4, the feature.** Replace the "Coming soon" entry with a real external
storage configuration: an admin adds a provider, the app tests the connection,
stores credentials encrypted, and routes a space's files through the right
`UploadStorage`. The driver factory and the contract already provide the seam;
the remaining work is UI, credentials, authorisation and quota reporting.

## Appendix: environment reference

| Variable | Default | Meaning |
|---|---|---|
| `UPLOAD_STORAGE_DRIVER` | `local` | `local`, `s3` or `dropbox` |
| `UPLOAD_LOCAL_DISK` | `edms` | Disk used by the local driver |
| `UPLOAD_CLOUD_DISK` | `edms-cloud` | S3 disk used by the S3 driver |
| `UPLOAD_FAULT_LATENCY_MS` | `0` | Per-operation delay |
| `UPLOAD_FAULT_JITTER_MS` | `0` | Random extra delay |
| `UPLOAD_FAULT_BANDWIDTH_BPS` | unset | Throughput ceiling in bytes/second |
| `UPLOAD_FAULT_FAILURE_RATE` | `0` | Transient failure probability |
| `UPLOAD_FAULT_DROP_AFTER_BYTES` | unset | Truncate larger transfers at N bytes |
| `UPLOAD_FAULT_QUOTA_EXCEEDED` | `false` | Make every operation report a full bucket |
| `UPLOAD_FAULT_EVENTUAL_CONSISTENCY_READS` | `0` | Stale reads after each write |
| `DROPBOX_APP_KEY` / `DROPBOX_APP_SECRET` / `DROPBOX_REFRESH_TOKEN` | empty | Dropbox OAuth credentials |
| `DROPBOX_ROOT` | `/edms` | Folder inside the Dropbox app that holds EDMS files |
| `DROPBOX_TIMEOUT` | `120` | Seconds before a Dropbox request is abandoned |
| `AWS_*` | see `.env.example` | S3-compatible endpoint, credentials and bucket |

## Appendix: troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Every operation says "quota is exhausted" | `UPLOAD_FAULT_QUOTA_EXCEEDED=true` left in `.env` | Unset it; the harness presets do not write to `.env` |
| "Unknown upload storage driver" | Typo in `UPLOAD_STORAGE_DRIVER` | Use `local`, `s3` or `dropbox` |
| "The [edms-cloud] disk has no bucket configured" | `AWS_BUCKET` unset | Set bucket, endpoint and credentials |
| S3 returns 403 | Wrong region, endpoint or path-style setting | Match the provider's documented endpoint; MinIO needs `AWS_USE_PATH_STYLE_ENDPOINT=true` |
| Multipart copy never happens | Chunks are below 5 MiB (the test suite's tiny chunks, for example) | Run with `--chunk-bytes` at or above `5242880` |
| Dropbox returns 401 | Refresh token expired or scopes missing | Re-authorise with `token_access_type=offline` and the three file scopes |
| Dropbox assembly is slow | Sequential download plus upload | Expected; use smaller test files or prefer S3 for throughput |
| Assembly says a chunk is missing | Stale read or a vanished chunk | Re-run the harness, or let the client re-send; this is the reconciler working |
| Temp files appear under `storage/app/private/edms/tmp` | A failed run on the local driver | `php artisan edms:prune-uploads --ttl`, or delete the `tmp/{uuid}` directory |
| Incomplete multipart uploads in the bucket | Worker killed mid-assembly | Abort them, and add the lifecycle rule |
