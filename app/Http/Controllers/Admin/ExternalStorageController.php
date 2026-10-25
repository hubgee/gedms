<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\CloudStorageDriver;
use App\Http\Controllers\Controller;
use App\Models\StorageSpace;
use App\Services\Uploads\Pkce;
use App\Services\Uploads\StorageDriverRegistry;
use App\Services\Uploads\StorageUsageReporter;
use App\Services\Uploads\UploadStorageFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Per-space storage configuration.
 *
 * The controller deliberately knows nothing about individual providers: the
 * driver registry supplies the settings schema, validation and OAuth flow.
 * Adding a provider therefore never touches this class.
 */
class ExternalStorageController extends Controller
{
    public function __construct(
        protected UploadStorageFactory $factory,
        protected StorageDriverRegistry $registry,
    ) {}

    public function index(Request $request): View
    {
        $query = StorageSpace::query()
            ->with(['ownerDepartment', 'ownerSection', 'ownerMinistry', 'ownerUser'])
            ->orderBy('type')->orderBy('name');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        return view('admin.external-storage.index', [
            'spaces' => $query->paginate(25)->withQueryString(),
            'type' => $request->string('type')->toString(),
            'q' => $request->string('q')->toString(),
            'driverLabels' => $this->registry->all(),
        ]);
    }

    public function edit(Request $request, StorageSpace $space, StorageUsageReporter $usage): View
    {
        $this->authorizeSpace($space);

        $selected = $space->storage_driver ?: (string) config('uploads.storage_driver', 'local');
        $selectedKey = $this->registry->has($selected) ? $selected : 'local';
        $config = $space->storageConfig() ?? [];

        $redirectUris = [];

        foreach ($this->registry->all() as $key => $driver) {
            if ($driver->oauthProvider() === null) {
                continue;
            }

            $driverConfig = $selectedKey === $key ? $config : [];
            $redirectUris[$key] = $this->redirectUri($key, $driverConfig);
        }

        return view('admin.external-storage.edit', [
            'space' => $space,
            'drivers' => $this->registry->all(),
            'selectedDriver' => $selectedKey,
            'inherited' => blank($space->storage_driver),
            'config' => $config,
            'redirectUris' => $redirectUris,
            'usage' => $usage->forSpace($space, $request->boolean('usage')),
        ]);
    }

    public function update(Request $request, StorageSpace $space): RedirectResponse
    {
        $this->authorizeSpace($space);

        $driver = $this->selectedDriver($request);

        if ($driver === null) {
            return back()->withErrors(['storage_driver' => 'Unknown driver.'])->withInput();
        }

        $config = $this->mergeConfig($driver, $request, $this->existingConfig($space, $driver));
        $errors = $driver->validate($config);

        if ($errors !== []) {
            return back()->withErrors(['storage_config' => $errors])->withInput();
        }

        $space->storage_driver = $driver->key();
        $space->setStorageConfig($config);
        $space->save();

        return redirect()
            ->route('admin.storage-spaces.external-storage.edit', $space)
            ->with('success', 'External storage configuration saved.');
    }

    /**
     * Drop the per-space override and fall back to the global driver again.
     */
    public function destroy(StorageSpace $space): RedirectResponse
    {
        $this->authorizeSpace($space);

        $space->storage_driver = null;
        $space->setStorageConfig(null);
        $space->save();

        return redirect()
            ->route('admin.storage-spaces.external-storage.edit', $space)
            ->with('success', 'This space now uses the global storage driver.');
    }

    public function test(Request $request, StorageSpace $space): RedirectResponse
    {
        $this->authorizeSpace($space);

        $driver = $this->selectedDriver($request);

        if ($driver === null) {
            return back()->withErrors(['storage_driver' => 'Unknown driver.'])->withInput();
        }

        $config = $this->mergeConfig($driver, $request, $this->existingConfig($space, $driver));
        $errors = $driver->validate($config);

        if ($errors !== []) {
            return back()->withErrors(['storage_config' => $errors])->withInput();
        }

        $ok = $this->factory->check($driver->key(), $config);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Connection successful.'
            : 'Connection failed. Check the credentials and endpoint.');
    }

    /**
     * Start a provider's OAuth authorisation-code flow for a space.
     */
    public function oauthConnect(Request $request, StorageSpace $space, string $driver): RedirectResponse
    {
        $this->authorizeSpace($space);

        if (! $this->registry->has($driver)) {
            return back()->with('error', 'Unknown storage provider.');
        }

        $descriptor = $this->registry->get($driver);
        $oauth = $descriptor->oauthProvider();

        if ($oauth === null) {
            return back()->with('error', 'This provider does not use an interactive connection.');
        }

        $state = Str::random(48);
        $config = $this->mergeConfig($descriptor, $request, $this->existingConfig($space, $descriptor));
        $redirectUri = $this->redirectUri($driver, $config);
        $codeVerifier = $oauth->usesPkce() ? Pkce::verifier() : null;

        try {
            $authorizeUrl = $oauth->authorizeUrl($config, $state, $redirectUri, $codeVerifier);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $request->session()->put($this->stateKey($state), [
            'space_id' => $space->id,
            'driver' => $driver,
            // The payload contains client secrets until the callback has
            // exchanged the code, so keep it encrypted even in the session
            // store (which is server-side but not encrypted by default).
            'config' => encrypt(json_encode($config)),
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
            'started_at' => now()->getTimestamp(),
        ]);

        // away(): the provider is an external host, not a local route.
        return redirect()->away($authorizeUrl);
    }

    /**
     * Finish a provider's OAuth flow and persist the refresh token.
     */
    public function oauthCallback(Request $request, string $driver): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $payload = $state !== ''
            ? $request->session()->pull($this->stateKey($state))
            : null;

        if (! is_array($payload) || ! isset($payload['space_id'], $payload['driver'])) {
            return redirect()
                ->route('admin.storage-spaces.index')
                ->with('error', 'The connection request expired. Start it again from the space.');
        }

        $space = StorageSpace::find($payload['space_id']);

        if (! $space) {
            return redirect()
                ->route('admin.storage-spaces.index')
                ->with('error', 'The storage space no longer exists.');
        }

        $this->authorizeSpace($space);

        $edit = route('admin.storage-spaces.external-storage.edit', $space);

        // A state value older than fifteen minutes is treated as abandoned.
        if ((int) ($payload['started_at'] ?? 0) < now()->subMinutes(15)->getTimestamp()) {
            return redirect($edit)->with('error', 'The connection request expired. Start it again.');
        }

        if ($payload['driver'] !== $driver || ! $this->registry->has($driver)) {
            return redirect($edit)->with('error', 'The connection request does not match this provider.');
        }

        $oauth = $this->registry->get($driver)->oauthProvider();

        if ($oauth === null) {
            return redirect($edit)->with('error', 'This provider does not use an interactive connection.');
        }

        $error = (string) ($request->query('error_description') ?: $request->query('error') ?: '');

        if ($error !== '') {
            return redirect($edit)->with('error', 'Authorisation was cancelled: '.$error);
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect($edit)->with('error', 'The provider did not return an authorisation code.');
        }

        $config = $this->payloadConfig($payload['config'] ?? null);

        try {
            $credentials = $oauth->exchange(
                $config,
                $code,
                (string) ($payload['redirect_uri'] ?? ''),
                is_string($payload['code_verifier'] ?? null) ? $payload['code_verifier'] : null,
            );
        } catch (Throwable $e) {
            return redirect($edit)->with('error', $e->getMessage());
        }

        $config = array_merge($config, $credentials);

        $space->storage_driver = $driver;
        $space->setStorageConfig($config);
        $space->save();

        return redirect($edit)->with('success', 'Connected. Click "Test connection" to verify the space can read and write.');
    }

    protected function authorizeSpace(StorageSpace $space): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (
                $user->can('manage-storage-spaces')
                || $user->hasRole('System Administrator')
                || $user->can('manage-system')
            ),
            403
        );
    }

    protected function selectedDriver(Request $request): ?CloudStorageDriver
    {
        $key = (string) $request->input('storage_driver', 'local');

        return $this->registry->has($key) ? $this->registry->get($key) : null;
    }

    /**
     * The stored configuration only belongs to the driver that is currently
     * selected. Switching drivers starts fresh, so a provider never inherits
     * another provider's secrets (and the old ones do not linger in the row).
     *
     * @return array<string, mixed>
     */
    protected function existingConfig(StorageSpace $space, CloudStorageDriver $driver): array
    {
        if ($space->storage_driver !== $driver->key()) {
            return [];
        }

        return $space->storageConfig() ?? [];
    }

    /**
     * The OAuth state payload keeps its configuration encrypted, because it
     * holds client secrets until the callback exchanges the code.
     *
     * @return array<string, mixed>
     */
    protected function payloadConfig(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = json_decode(decrypt($payload), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merge submitted settings into the stored configuration.
     *
     * Blank secret fields keep the stored secret: password inputs are never
     * echoed back, so "blank" has to mean "unchanged". Blank non-secret fields
     * fall back to the field's default only when no value is stored yet.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    protected function mergeConfig(CloudStorageDriver $driver, Request $request, array $existing): array
    {
        $settings = $request->input('settings');
        $input = is_array($settings)
            && isset($settings[$driver->key()])
            && is_array($settings[$driver->key()])
                ? $settings[$driver->key()]
                : [];

        $config = $existing;

        foreach ($driver->fields() as $field) {
            $key = (string) $field['key'];
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'checkbox') {
                $config[$key] = (bool) ($input[$key] ?? false);

                continue;
            }

            $value = $input[$key] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if (! array_key_exists($key, $config) && array_key_exists('default', $field)) {
                    $config[$key] = $field['default'];
                }

                continue;
            }

            if ($type === 'number' && is_numeric($value)) {
                $value = (int) $value;
            }

            $config[$key] = $value;
        }

        if ($request->filled('quota_bytes')) {
            $config['quota_bytes'] = max(0, (int) $request->input('quota_bytes'));
        } else {
            unset($config['quota_bytes']);
        }

        return $config;
    }

    /**
     * The exact callback URL registered with the provider. An explicit value
     * in config/uploads.php wins, because a host behind a proxy may not be
     * able to derive its public URL from the request.
     */
    protected function redirectUri(string $driver, array $config = []): string
    {
        $configured = trim((string) ($config['redirect_uri'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        $configured = trim((string) config('uploads.'.$driver.'.redirect_uri', ''));

        if ($configured !== '') {
            return $configured;
        }

        return route('admin.storage-spaces.external-storage.oauth.callback', ['driver' => $driver]);
    }

    protected function stateKey(string $state): string
    {
        return 'storage_oauth.'.$state;
    }
}
