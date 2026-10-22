@extends('layouts.app')

@section('content')
<div class="space-y-4 max-w-2xl">
    <h1 class="text-2xl font-semibold text-slate-800">External storage: {{ $space->name ?? 'Space #'.$space->id }}</h1>

    @if(session('success'))
        <div class="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.storage-spaces.external-storage.update', $space) }}" class="card p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Driver</label>
            <select name="storage_driver" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <option value="local" {{ ($space->storage_driver ?? 'local') === 'local' ? 'selected' : '' }}>Local (default)</option>
                <option value="s3" {{ $space->storage_driver === 's3' ? 'selected' : '' }}>S3-compatible</option>
                <option value="dropbox" {{ $space->storage_driver === 'dropbox' ? 'selected' : '' }}>Dropbox</option>
            </select>
        </div>

        <div class="border-t border-slate-200 pt-4">
            <h2 class="text-sm font-semibold text-slate-700 mb-2">S3-compatible</h2>
            <label class="block text-sm text-slate-600 mb-1">Filesystem disk name (config/filesystems.php)</label>
            <input type="text" name="s3_disk" value="{{ $config['cloud_disk'] ?? '' }}" placeholder="edms-cloud" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
            <p class="text-xs text-slate-400 mt-1">Credentials come from the disk's <code>AWS_*</code> environment variables.</p>
        </div>

        <div class="border-t border-slate-200 pt-4">
            <h2 class="text-sm font-semibold text-slate-700 mb-2">Dropbox</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm text-slate-600 mb-1">App key</label>
                    <input type="text" name="dropbox_app_key" value="{{ $config['app_key'] ?? '' }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">App secret</label>
                    <input type="password" name="dropbox_app_secret" value="{{ $config['app_secret'] ?? '' }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">Refresh token</label>
                    <input type="password" name="dropbox_refresh_token" value="{{ $config['refresh_token'] ?? '' }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">Root folder</label>
                    <input type="text" name="dropbox_root" value="{{ $config['root'] ?? '/edms' }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">Timeout (seconds)</label>
                    <input type="number" name="dropbox_timeout" value="{{ $config['timeout'] ?? 120 }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Save</button>
            <button type="submit" formaction="{{ route('admin.storage-spaces.external-storage.test', $space) }}" class="px-4 py-2 text-sm font-medium text-slate-700 bg-slate-100 rounded-lg hover:bg-slate-200">Test connection</button>
        </div>
    </form>

    <p class="text-xs text-slate-400">Saving stores credentials encrypted. Switching a space's driver does not move existing files; migrate them manually if needed.</p>
</div>
@endsection