# External storage

Every space can store its files in a different place. The feature is intended to
be provider-neutral: the admin UI is generated from the driver's own schema, so
adding a provider does not mean editing a controller or a Blade template.

## How it fits together

| Piece | Responsibility |
|---|---|
| `App\Contracts\CloudStorageDriver` | Describes a provider: key, label, description, settings fields, optional OAuth provider, `build()` and `validate()` |
| `App\Services\Uploads\StorageDriverRegistry` | Resolves the classes listed in `config/uploads.php → drivers` |
| `App\Services\Uploads\UploadStorageFactory` | Returns the `UploadStorage` for a space, falling back to the global driver |
| `App\Http\Controllers\Admin\ExternalStorageController` | Generic admin controller: render schemas, save encrypted config, run OAuth, test health |
| `resources/views/admin/external-storage/edit.blade.php` | Schema-driven form; no provider-specific branches |
| `resources/views/components/storage-field.blade.php` | Renders one field (`text`, `password`, `number`, `checkbox`, `select`, `url`) |

The upload pipeline never knows which provider is in use. It only depends on
`App\Contracts\UploadStorage`, which covers chunks, assembly, ranged reads,
copies and deletions. This is the seam that makes "connect any cloud" possible
without forking the upload state machine.

### Which driver is used

1. If the space has `storage_driver`, that driver and its `storage_config` are
   used. This includes an explicit `local` override, which is useful when the
   global driver is a cloud provider.
2. Otherwise the global driver from `UPLOAD_STORAGE_DRIVER` is used.

The configuration column is encrypted with the application key. A secret field
that is left blank on an edit keeps the stored value, so an administrator can
change a bucket or folder without re-entering credentials.

## Connected providers

| Driver | Key | What it covers |
|---|---|---|
| Local disk | `local` | The `edms` disk on this server; also usable as a per-space override |
| S3-compatible | `s3` | AWS S3, MinIO, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2, Ceph, ... |
| Dropbox | `dropbox` | A folder in a Dropbox account |
| Google Drive | `google` | A folder in Google Drive |
| Microsoft OneDrive | `onedrive` | The signed-in user's OneDrive (`/me/drive`) |

The OneDrive driver deliberately does not claim SharePoint site libraries yet;
that is a follow-up (see the roadmap in `cloud-storage-test-plan.md`).

## Admin workflow

1. Open **Administration → Storage Spaces**.
2. Choose a space and open **External storage**.
3. Select the provider. The fields change to that provider's schema.
4. Enter credentials once. For OAuth providers, click **Connect with ...**; the
   callback stores the refresh token encrypted on the space. No token copying.
5. Click **Test connection** to check that the credentials and root folder work.
6. Save. Only that space is routed to the provider.

To remove the override, use **Use global default**. That deletes the per-space
configuration and returns the space to the global driver.

The edit page shows the exact callback URL to register with the provider. The
URL is derived from the named route, so it stays correct under a path prefix.
If a reverse proxy changes the public host or scheme, set the matching
`*_REDIRECT_URI` variable and register that exact URL instead. You can also
override it per space with the **OAuth redirect URI** field on the edit page;
that value is stored encrypted with the space's configuration and is used for
both the authorisation request and the token exchange.

### OAuth redirect URIs

| Provider | Route |
|---|---|
| Dropbox | `admin/storage-spaces/external-storage/oauth/dropbox/callback` |
| Google Drive | `admin/storage-spaces/external-storage/oauth/google/callback` |
| Microsoft OneDrive | `admin/storage-spaces/external-storage/oauth/onedrive/callback` |

All three flows use PKCE (S256). The verifier is generated per attempt and kept
in the session; only its challenge goes to the provider. The state is a random
value stored in the session, and the transient configuration it carries is
encrypted. The callback compares the returned state, rejects expired attempts,
and then exchanges the code with the verifier.

Dropbox accepts an `http://` redirect for a non-localhost host **only because
the flow uses PKCE**; production should still use HTTPS. Google and Microsoft
require HTTPS except for `localhost`.

### Provider app setup

**Dropbox**

1. Create a scoped app with **App folder** access.
2. Enable `files.content.write`, `files.content.read`, `files.metadata.read`.
3. Add the callback URL shown on the edit page.
4. Use the app key and app secret in the form, then connect.

**Google Drive**

1. Create an OAuth client of type **Web application**.
2. Enable the Google Drive API.
3. Add the callback URL as an authorised redirect URI.
4. Use the client id and client secret, then connect. The flow requests
   `access_type=offline` and `prompt=consent`, which is what returns a refresh
   token.

**Microsoft OneDrive**

1. Register an application in Microsoft Entra.
2. Add a **Web** redirect URI matching the edit page.
3. Grant delegated `Files.ReadWrite.All` and `offline_access`.
4. Use the application (client) id and client secret. Leave the tenant as
   `common` for personal accounts, or enter the tenant id/domain for an
   organisation.

**S3-compatible**

The bucket must already exist. Either enter the bucket and credentials in the
form, or leave the fields blank to reuse the `UPLOAD_CLOUD_DISK` disk from
`config/filesystems.php`. `use_path_style` is required by MinIO and some other
self-hosted gateways.

## Adding a provider

1. Implement `App\Contracts\CloudStorageDriver`:

   ```php
   final class AcmeDriver extends AbstractStorageDriver
   {
       public function key(): string { return 'acme'; }

       public function label(): string { return 'Acme Cloud'; }

       public function description(): string { return 'A folder in Acme Cloud.'; }

       public function fields(): array
       {
           return [
               ['key' => 'token', 'label' => 'API token', 'type' => 'password', 'required' => true],
               ['key' => 'root', 'label' => 'Folder', 'type' => 'text', 'default' => '/edms'],
           ];
       }

       public function build(array $config): UploadStorage
       {
           return new AcmeUploadStorage(new AcmeClient($config['token'], $config['root']));
       }
   }
   ```

   Implement `validate()` when the generic "required field" check is not enough.

2. Implement `UploadStorage`. Reuse an existing backend if the API is
   S3-compatible; otherwise implement `putChunk`, `putFile`, `assemble`,
   `exists`, `size`, `delete`, `deleteDirectory`, `readStream`, `readHead`,
   `copy`, `directories`, `isEmpty` and `healthCheck`. A `localPath()` helper
   is optional; cloud stores return `null` and callers fall back to
   `readHead()`.

3. If the provider uses OAuth, implement
   `App\Contracts\CloudOAuthProvider` (`usesPkce`, `authorizeUrl`, `exchange`)
   and return it from `oauthProvider()`.

4. Register the class in `config/uploads.php`:

   ```php
   'drivers' => [
       // ...
       'acme' => App\Services\Uploads\Drivers\AcmeDriver::class,
   ],
   ```

5. Add config defaults for endpoints, credentials and timeouts under
   `uploads.acme`. Read them with `config()`, never `env()`, so `config:cache`
   works.

6. If the provider reports a quota, implement
   `App\Contracts\ReportsStorageQuota` on the storage class. The usage reporter
   checks the interface, not a concrete class.

7. Add a test with `Http::fake()` for URL construction and response parsing,
   and a registry test is automatic. The admin UI, OAuth routes and encrypted
   storage need no changes.

## Operational notes

- **Health check:** `php artisan edms:storage-health` checks the global driver;
  `--all` checks every registered driver; `--spaces` checks every space with
  external storage.
- **Quotas:** the space's own limit lives in `quota_bytes` and is shared by all
  drivers. Provider allocation is only probed on demand (the **Refresh usage**
  button) because S3 has no portable quota API.
- **Verification:** after assembly the size is always checked. Files up to the
  configured `verify_read_back_limit` (64 MiB by default) are read back and
  hashed; larger files are trusted on size, because hashing them would download
  them again. The bytes were hashed locally before upload.
- **Switching providers:** the old configuration is discarded, so one provider
  can never inherit another provider's secrets.
- **Secret files:** Dropbox supports `*_FILE` variables for Docker/Kubernetes
  secret mounts. The file contents take precedence over the inline values.
- **Queues:** assembly runs on the `uploads` queue. Run a worker with
  `php artisan queue:work uploads --queue=uploads --timeout=3600`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `invalid_redirect_uri` / `redirect_uri_mismatch` / `AADSTS50011` | Register the exact callback URL shown on the edit page. Check scheme, host, port and path; a proxy may need `*_REDIRECT_URI` |
| `Unknown upload storage driver` | Check the key in `config/uploads.php` and the space's `storage_driver` |
| Provider returns 401/403 | Reconnect; confirm the scopes and that the tenant allows the application |
| Connection succeeds but health check fails | The root folder is not writable, or the token belongs to a different account |
| S3 403 | Wrong region, endpoint or path-style flag; the bucket must exist |
| "Quota is exhausted" on every upload | `UPLOAD_FAULT_QUOTA_EXCEEDED` is still set; it is a test switch |
| Assembly is slow on Dropbox/Drive | Expected: neither can join chunks server-side, so assembly downloads and re-uploads |
| Large file verification seems skipped | Files above `verify_read_back_limit` are size-checked only; that is deliberate |

For the fault-injection harness, provider soak procedure and the exact scenario
matrix, see [cloud-storage-test-plan.md](cloud-storage-test-plan.md).
