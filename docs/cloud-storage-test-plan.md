# Cloud storage test plan

EDMS used to keep every uploaded byte on the local `edms` disk, where the only
way a write can fail is a full or dying filesystem. A cloud provider fails in
many more ways: it is slow, it drops connections mid-transfer, it accepts a
write and cannot see it for a moment, it runs out of quota, it refuses to copy
an object because a part is too small, and it charges for every byte that moves.

This plan describes how those conditions are reproduced and asserted. It covers
several substitute backends, three independent layers of fault injection, the
scenario matrix that ties them together, and the limits of what the current
implementation can do.

The External Storage feature is now implemented in Phase 4: an administrator
opens **Administration → Storage Spaces**, picks a provider, and the space's
files are routed through that provider's `UploadStorage` backend. The first half
of this document describes the byte-store seam and the fault harness that prove
the pipeline is safe on a cloud backend; the second half documents provider
setup, the admin flow and the remaining manual soak work.

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

Google Drive and OneDrive implement the same contract. Drive is id-based, so the
client resolves a path to a folder id and caches it for the request; OneDrive is
path-addressable and maps EDMS paths directly onto Graph paths. Neither can join
chunks server-side, so assembly downloads the chunks and uploads the
concatenation, exactly like Dropbox.

The pipeline never needs a local path: the executable-payload check and the MIME
sniff read `readHead()` instead of opening the file. That is the change that
makes the cloud backends honest instead of silently skipping checks.

The driver is chosen by `UPLOAD_STORAGE_DRIVER` (`local`, `s3`, `dropbox`,
`google`, `onedrive`). A space can override it with its own encrypted
configuration. The binding is **scoped**, not a singleton: the fault injector
keeps a little per-request state (the stale reads), and that state must survive
within one request or one queue job but must not leak into the next job.

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
| `--driver=` | `uploads.storage_driver` | `local`, `s3`, `dropbox`, `google` or `onedrive` |
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

Setup (admin UI, recommended):

1. Create an app at <https://www.dropbox.com/developers/apps> with **scoped
   access** and **App folder** access.
2. Enable the scopes `files.content.write`, `files.content.read` and
   `files.metadata.read`.
3. In the Dropbox app console, add the exact callback URL shown on the space's
   edit page:
   `.../admin/storage-spaces/external-storage/oauth/dropbox/callback`.
   If the app must send a different URL, enter it in the **OAuth redirect URI**
   field on the edit page; the app uses that value for both the authorisation
   request and the token exchange. Dropbox requires HTTPS except for
   `localhost`, so an `http://` non-localhost URL cannot be registered.
4. In **Administration → Storage Spaces**, open the space, choose **Dropbox**,
   enter the app key and secret, and click **Connect with Dropbox**. The
   callback exchanges the code and stores the refresh token encrypted on the
   space. Click **Test connection** to confirm access.
5. To make Dropbox the global default for every space without its own
   configuration, set the `.env` values below.

```dotenv
UPLOAD_STORAGE_DRIVER=dropbox
DROPBOX_APP_KEY=...
DROPBOX_APP_SECRET=...
DROPBOX_REFRESH_TOKEN=...
DROPBOX_ROOT=/edms
```

If the deployment terminates TLS at a proxy and the derived callback URL is
wrong, set `DROPBOX_REDIRECT_URI` to the exact registered URL.

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

### Google Drive

1. Create an OAuth client (type **Web application**) in the Google Cloud
   Console and enable the Google Drive API.
2. Add the callback URL from the space's edit page as an authorised redirect
   URI: `.../admin/storage-spaces/external-storage/oauth/google/callback`.
3. In **Administration → Storage Spaces**, choose **Google Drive**, enter the
   client id and secret, and click **Connect with Google Drive**.
4. Grant the `drive` scope. Google returns a refresh token only when consent is
   prompted, which the driver requests explicitly.

Global fallback:

```dotenv
UPLOAD_STORAGE_DRIVER=google
GOOGLE_DRIVE_CLIENT_ID=...
GOOGLE_DRIVE_CLIENT_SECRET=...
GOOGLE_DRIVE_REFRESH_TOKEN=...
GOOGLE_DRIVE_ROOT=/edms
```

Drive is id-based, so folder lookups are cached per request. Assembly downloads
the chunks and uploads the concatenation; the same egress trade-off as Dropbox
applies.

### Microsoft OneDrive

1. Register an application in Microsoft Entra (Azure AD).
2. Add a **Web** redirect URI matching the space's edit page:
   `.../admin/storage-spaces/external-storage/oauth/onedrive/callback`.
3. Grant the delegated permissions `Files.ReadWrite.All` and `offline_access`.
4. In **Administration → Storage Spaces**, choose **Microsoft OneDrive**, enter
   the application (client) id and client secret, leave the tenant as `common`
   for personal accounts (or use the tenant id/domain for an organisation), and
   click **Connect with Microsoft OneDrive**.

Global fallback:

```dotenv
UPLOAD_STORAGE_DRIVER=onedrive
ONEDRIVE_CLIENT_ID=...
ONEDRIVE_CLIENT_SECRET=...
ONEDRIVE_REFRESH_TOKEN=...
ONEDRIVE_TENANT=common
ONEDRIVE_ROOT=/edms
```

The driver targets the signed-in user's OneDrive (`/me/drive`); SharePoint site
libraries are not part of this driver.

## Scenario matrix

Every row is a test that must pass before the cloud backends are considered
ready. "Automated" rows already exist in the suite; the rest are manual or
nightly runs.

| # | Scenario | Layer / setup | Pass criteria | Automated |
|---|---|---|---|---|
| 1 | Clean round trip, every driver | `--preset=clean --driver=...` | `ok`, `verified`, SHA-256 matches | Yes (local, Dropbox fake; Google/OneDrive client URLs faked) |
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
php artisan test --filter="CloudOAuth"
php artisan test --filter="GoogleDriveClient"
php artisan test --filter="OneDriveClient"
php artisan test --filter="StorageDriverRegistry"
php artisan test --filter="StorageUsageReporter"
php artisan test --filter="Upload"
```

| Test file | Coverage |
|---|---|
| `tests/Feature/Uploads/CloudUploadStorageTest.php` | Fault decorator: transient failure, quota, mid-chunk truncation and retry, stale reads, wrong hash, assembly mismatch. Dropbox: ordered appends `[0, 8, 16]`, ranged read `bytes=4-9`, executable rejection with object deletion, download through `FileService`, server-side copy, truncated chunk reconciliation, temp cleanup, factory driver selection, unknown driver, missing credentials, local driver makes no HTTP calls |
| `tests/Unit/S3UploadStorageTest.php` | Multipart-copy decision: 5 MiB minimum, last-part exception, 10 000-part ceiling, 5 GiB copy ceiling, unreadable sizes |
| `tests/Unit/Uploads/PkceTest.php` | Verifier length and charset, uniqueness, S256 challenge, query parameters |
| `tests/Feature/Uploads/CloudOAuthTest.php` | PKCE and offline-access parameters for Dropbox, Google and OneDrive; token exchange includes the verifier; provider errors and missing refresh tokens surface |
| `tests/Feature/Uploads/StorageDriverRegistryTest.php` | Every configured driver resolves, keys and field schemas are complete, only OAuth drivers expose an OAuth provider, unknown keys fail, config-only driver addition works |
| `tests/Feature/Uploads/StorageUsageReporterTest.php` | Application sizes, provider quota through `ReportsStorageQuota`, configured quota precedence, no probe unless requested, backends without quota reporting |
| `tests/Feature/Uploads/GoogleDriveClientTest.php` | Resumable upload, copy replaces the destination, ranged `readHead`, quota parsing, missing items |
| `tests/Feature/Uploads/OneDriveClientTest.php` | Simple content upload, item lookup, quota parsing, copy replaces the destination and sets the parent folder, missing items |
| `tests/Feature/Admin/ExternalStorageControllerTest.php` | Driver list, callback URL, encrypted per-space config, blank secret retention, provider switching drops old secrets, explicit local override, validation, OAuth connect with PKCE, callback stores the refresh token, forged state rejection, admin authorisation |
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
| Verification read-back | 64 MiB | Larger files are trusted on size after an upload, because hashing them means downloading them again; the bytes were hashed locally before upload | `verify_read_back_limit` in `config/uploads.php` |
| Read-after-write | varies | `size()` returns `null` on a failed HEAD, so reconcile treats the chunk as missing instead of trusting the index | `eventually-consistent` preset |
| Incomplete multipart uploads | n/a | Aborted on handled failures; lifecycle rule covers killed workers | Bucket lifecycle + crash test |
| Provider errors surface | n/a | The `edms-cloud` disk uses `throw => true`, so a failed provider call raises instead of returning `false` | Point the disk at a stopped MinIO |
| Dropbox base URL | configurable | `DROPBOX_API_URL` / `DROPBOX_CONTENT_URL` let a proxy or a fake sit in front | Point the URL at a local fake |

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

## Roadmap

**Phase 1, complete.** Byte-store seam, local/S3/Dropbox drivers, fault decorator,
chaos harness, automated cloud tests.

**Phase 2, staging soak.** MinIO and Toxiproxy in CI or a nightly job; a 10 GB
S3 run; a 1 GB Dropbox run; bucket lifecycle and cleanup verified.

**Phase 3, hardening, complete.** Per-space credentials encrypted with the
application key, provider health checks, configurable endpoints, read-back
limits and a registry that hides provider specifics from the controller.

**Phase 4, the feature, complete.** The "Coming soon" placeholder is replaced by
**Administration → Storage Spaces → External storage**. The admin picks from
`local`, any S3-compatible store, Dropbox, Google Drive or OneDrive, fills the
fields the driver declares, tests the connection, and connects OAuth providers
with a single button. The configuration is encrypted on the space; spaces
without one inherit the global driver, and **Use global default** removes an
override.

**Phase 5, follow-ups.** SharePoint document libraries (the current OneDrive
driver targets `/me/drive`), Microsoft Graph delta-based listings for very large
folders, a provider-specific egress estimate in the UI, and a scheduled quota
probe for spaces that opt into it.

## Appendix: environment reference

| Variable | Default | Meaning |
|---|---|---|
| `UPLOAD_STORAGE_DRIVER` | `local` | `local`, `s3`, `dropbox`, `google` or `onedrive` |
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
| `DROPBOX_APP_KEY_FILE` / `DROPBOX_APP_SECRET_FILE` / `DROPBOX_REFRESH_TOKEN_FILE` | empty | Docker-style secret files; take precedence over the inline values |
| `DROPBOX_ROOT` | `/edms` | Folder inside the Dropbox app that holds EDMS files |
| `DROPBOX_TIMEOUT` | `120` | Seconds before a Dropbox request is abandoned |
| `DROPBOX_REDIRECT_URI` | derived | Exact callback URL registered on the Dropbox app |
| `GOOGLE_DRIVE_CLIENT_ID` / `GOOGLE_DRIVE_CLIENT_SECRET` / `GOOGLE_DRIVE_REFRESH_TOKEN` | empty | Google OAuth credentials |
| `GOOGLE_DRIVE_ROOT` | `/edms` | Folder inside Google Drive |
| `GOOGLE_DRIVE_REDIRECT_URI` | derived | Exact callback URL registered on the Google OAuth client |
| `ONEDRIVE_CLIENT_ID` / `ONEDRIVE_CLIENT_SECRET` / `ONEDRIVE_REFRESH_TOKEN` | empty | Microsoft OAuth credentials |
| `ONEDRIVE_TENANT` | `common` | Microsoft tenant id/domain, or `common` for personal accounts |
| `ONEDRIVE_ROOT` | `/edms` | Folder inside OneDrive |
| `ONEDRIVE_REDIRECT_URI` | derived | Exact callback URL registered on the Microsoft app |
| `AWS_*` | see `.env.example` | S3-compatible endpoint, credentials and bucket |

## Appendix: troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Every operation says "quota is exhausted" | `UPLOAD_FAULT_QUOTA_EXCEEDED=true` left in `.env` | Unset it; the harness presets do not write to `.env` |
| "Unknown upload storage driver" | Typo in `UPLOAD_STORAGE_DRIVER` or a removed driver | Use `local`, `s3`, `dropbox`, `google` or `onedrive` |
| "The [edms-cloud] disk has no bucket configured" | `AWS_BUCKET` unset | Set bucket, endpoint and credentials |
| S3 returns 403 | Wrong region, endpoint or path-style setting | Match the provider's documented endpoint; MinIO needs `AWS_USE_PATH_STYLE_ENDPOINT=true` |
| Multipart copy never happens | Chunks are below 5 MiB (the test suite's tiny chunks, for example) | Run with `--chunk-bytes` at or above `5242880` |
| Dropbox returns 401 | Refresh token expired or scopes missing | Click **Connect with Dropbox** again; the UI requests `token_access_type=offline` and the three file scopes |
| Dropbox says `invalid_redirect_uri` | The callback URL is not registered on the app | Copy the URL shown on the edit page into the Dropbox app console; use `DROPBOX_REDIRECT_URI` only when a proxy changes the public URL |
| Google says `redirect_uri_mismatch` | The authorised redirect URI differs by scheme, host, port or path | Register the exact URL shown on the edit page |
| Microsoft says `AADSTS50011` | The redirect URI is not registered on the app registration | Add the exact URL shown on the edit page under **Web** redirect URIs |
| A provider connection works but "Test connection" fails | The token was issued for a different account, or the root folder is not writable | Reconnect, and check the root folder and scopes |
| Dropbox assembly is slow | Sequential download plus upload | Expected; use smaller test files or prefer S3 for throughput |
| Assembly says a chunk is missing | Stale read or a vanished chunk | Re-run the harness, or let the client re-send; this is the reconciler working |
| Temp files appear under `storage/app/private/edms/tmp` | A failed run on the local driver | `php artisan edms:prune-uploads --ttl`, or delete the `tmp/{uuid}` directory |
| Incomplete multipart uploads in the bucket | Worker killed mid-assembly | Abort them, and add the lifecycle rule |
