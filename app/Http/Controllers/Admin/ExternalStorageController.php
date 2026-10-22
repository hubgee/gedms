<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageSpace;
use App\Services\Uploads\UploadStorageFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExternalStorageController extends Controller
{
    public function __construct(
        protected UploadStorageFactory $factory,
    ) {}

    public function index(): View
    {
        return view('admin.external-storage.index', [
            'spaces' => StorageSpace::orderBy('type')->orderBy('name')->get(),
        ]);
    }

    public function edit(StorageSpace $space): View
    {
        $this->authorizeSpace($space);

        return view('admin.external-storage.edit', [
            'space' => $space,
            'config' => $space->storageConfig() ?? [],
        ]);
    }

    public function update(Request $request, StorageSpace $space): RedirectResponse
    {
        $this->authorizeSpace($space);

        $driver = (string) $request->input('storage_driver', 'local');

        if (! in_array($driver, ['local', 's3', 'dropbox'], true)) {
            return back()->withErrors(['storage_driver' => 'Unknown driver.'])->withInput();
        }

        $config = $this->buildConfig($request, $driver);

        if ($driver !== 'local' && $config === null) {
            return back()->withErrors(['storage_config' => 'Invalid configuration for the selected driver.'])->withInput();
        }

        $space->storage_driver = $driver === 'local' ? null : $driver;
        $space->setStorageConfig($driver === 'local' ? null : $config);
        $space->save();

        return redirect()
            ->route('admin.storage-spaces.external-storage.edit', $space)
            ->with('success', 'External storage configuration saved.');
    }

    public function test(Request $request, StorageSpace $space): RedirectResponse
    {
        $this->authorizeSpace($space);

        $driver = (string) $request->input('storage_driver', $space->storage_driver ?: config('uploads.storage_driver', 'local'));
        $config = $this->buildConfig($request, $driver);

        if ($driver !== 'local' && $config === null) {
            return back()->withErrors(['storage_config' => 'Invalid configuration.'])->withInput();
        }

        $ok = $this->factory->check($driver, $config ?? []);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Connection successful.'
            : 'Connection failed. Check the credentials and endpoint.');
    }

    protected function authorizeSpace(StorageSpace $space): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (
                $user->hasRole('System Administrator')
                || $user->hasRole('Department Administrator')
                || $user->can('manage-system')
                || $user->can('manage-department')
            ),
            403
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildConfig(Request $request, string $driver): ?array
    {
        return match ($driver) {
            'local' => null,
            's3' => $this->buildS3Config($request),
            'dropbox' => $this->buildDropboxConfig($request),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildS3Config(Request $request): ?array
    {
        $disk = trim((string) $request->input('s3_disk', ''));

        if ($disk === '') {
            return null;
        }

        return ['cloud_disk' => $disk];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildDropboxConfig(Request $request): ?array
    {
        $key = trim((string) $request->input('dropbox_app_key', ''));
        $secret = trim((string) $request->input('dropbox_app_secret', ''));
        $refresh = trim((string) $request->input('dropbox_refresh_token', ''));

        if ($key === '' || $secret === '' || $refresh === '') {
            return null;
        }

        return [
            'app_key' => $key,
            'app_secret' => $secret,
            'refresh_token' => $refresh,
            'root' => trim((string) $request->input('dropbox_root', '/edms')) ?: '/edms',
            'timeout' => (int) $request->input('dropbox_timeout', 120),
        ];
    }
}
