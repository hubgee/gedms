@extends('layouts.app')

@section('content')
@php
    $formatBytes = function (?int $bytes): string {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        return number_format($value, $index === 0 ? 0 : 1).' '.$units[$index];
    };
@endphp

<div class="space-y-4 max-w-3xl">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-slate-500">
                <a href="{{ route('admin.storage-spaces.index') }}" class="hover:underline">Storage Spaces</a>
                <span class="mx-1">/</span>
            </p>
            <h1 class="text-2xl font-semibold text-slate-800">{{ $space->name ?? 'Space #'.$space->id }}</h1>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium {{ $selectedDriver === 'local' ? 'bg-slate-100 text-slate-600' : 'bg-blue-100 text-blue-700' }}">
            {{ ($drivers[$selectedDriver] ?? null)?->label() ?? 'Local disk' }}
        </span>
    </div>

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

    {{-- Usage --}}
    <div class="card p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-700">Usage</h2>
                <p class="text-xs text-slate-400 mt-0.5">{{ $usage['files'] }} file{{ $usage['files'] === 1 ? '' : 's' }} recorded in this space.</p>
            </div>
            <a href="{{ route('admin.storage-spaces.external-storage.edit', ['space' => $space, 'usage' => 1]) }}" class="text-xs text-blue-600 hover:underline">Refresh provider usage</a>
        </div>

        <div class="mt-4 flex items-baseline gap-2">
            <span class="text-2xl font-semibold text-slate-800">{{ $formatBytes($usage['used_bytes']) }}</span>
            @if($usage['quota_bytes'] !== null)
                <span class="text-sm text-slate-500">of {{ $formatBytes($usage['quota_bytes']) }}</span>
            @endif
        </div>

        @if($usage['percent'] !== null)
            <div class="mt-2 h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                <div class="h-2 rounded-full {{ $usage['percent'] >= 90 ? 'bg-red-500' : ($usage['percent'] >= 75 ? 'bg-amber-500' : 'bg-blue-500') }}" style="width: {{ $usage['percent'] }}%"></div>
            </div>
            <p class="mt-1 text-xs text-slate-400">{{ $usage['percent'] }}% of the configured quota.</p>
        @else
            <p class="mt-1 text-xs text-slate-400">No quota configured. Set a quota below, or connect a provider that reports its own allocation.</p>
        @endif

        @if($usage['reachable'] !== null || $usage['provider_used_bytes'] !== null)
            <div class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                @if($usage['reachable'] === true)
                    <span class="text-emerald-600 font-medium">Provider reachable.</span>
                @elseif($usage['reachable'] === false)
                    <span class="text-red-600 font-medium">Provider unreachable.</span>
                @endif
                @if($usage['provider_used_bytes'] !== null)
                    <span class="ml-1">Account usage: {{ $formatBytes($usage['provider_used_bytes']) }}@if($usage['provider_quota_bytes'] !== null) of {{ $formatBytes($usage['provider_quota_bytes']) }}@endif.</span>
                @endif
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.storage-spaces.external-storage.update', $space) }}" class="card p-6 space-y-5" x-data="{ driver: '{{ $selectedDriver }}' }">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Driver</label>
            <select name="storage_driver" x-model="driver" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                @foreach($drivers as $key => $descriptor)
                    <option value="{{ $key }}" @selected($selectedDriver === $key)>{{ $descriptor->label() }}</option>
                @endforeach
            </select>
            <p class="text-xs text-slate-400 mt-1">Only files uploaded to this space use this provider. Other spaces keep their own configuration or the global default.</p>
            @if($inherited)
                <p class="mt-2 rounded-lg bg-blue-50 border border-blue-200 px-3 py-2 text-xs text-blue-700">
                    This space has no configuration of its own, so it currently uses the global driver. Saving or connecting here overrides the global setting for this space only.
                </p>
            @endif
        </div>

        @foreach($drivers as $key => $descriptor)
            @php
                $fields = collect($descriptor->fields());
                $mainFields = $fields->reject(fn ($field) => $field['advanced'] ?? false);
                $advancedFields = $fields->filter(fn ($field) => $field['advanced'] ?? false);
                $oauth = $descriptor->oauthProvider() !== null;
                $connected = $selectedDriver === $key
                    && (filled($config['refresh_token'] ?? null) || filled($config['access_token'] ?? null));
            @endphp

            <div x-show="driver === '{{ $key }}'" x-cloak class="border-t border-slate-200 pt-4 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-700">{{ $descriptor->label() }}</h2>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $descriptor->description() }}</p>
                    </div>
                    @if($inherited && $selectedDriver === $key)
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700">Inherited from global settings</span>
                    @elseif($oauth && $selectedDriver === $key && $connected)
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">Connected</span>
                    @elseif($oauth && $selectedDriver === $key)
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700">Not connected yet</span>
                    @endif
                </div>

                @if($oauth && isset($redirectUris[$key]))
                    <div class="rounded-lg bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600 space-y-2">
                        <p class="font-medium text-slate-700">Register this redirect URI on the {{ $descriptor->label() }} application</p>
                        <code class="block break-all rounded bg-white border border-slate-200 px-2 py-1">{{ $redirectUris[$key] }}</code>
                        <p>Add the URL above in the provider's app console before connecting. Register the exact scheme, host, port and path; production should use HTTPS.</p>
                    </div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($mainFields as $field)
                        <x-storage-field :field="$field" :driver-key="$key" :config="$config" />
                    @endforeach
                </div>

                @if($advancedFields->isNotEmpty())
                    <details class="text-xs text-slate-500">
                        <summary class="cursor-pointer">Advanced settings</summary>
                        <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                            @foreach($advancedFields as $field)
                                <x-storage-field :field="$field" :driver-key="$key" :config="$config" />
                            @endforeach
                        </div>
                    </details>
                @endif

                @if($oauth && isset($redirectUris[$key]))
                    <div class="flex items-center gap-3">
                        <button type="submit"
                                formaction="{{ route('admin.storage-spaces.external-storage.oauth.connect', ['space' => $space, 'driver' => $key]) }}"
                                class="px-4 py-2 text-sm font-medium text-white bg-slate-900 rounded-lg hover:bg-slate-800">
                            {{ $connected ? 'Reconnect '.$descriptor->label() : 'Connect with '.$descriptor->label() }}
                        </button>
                        <p class="text-xs text-slate-400">Save stores these credentials; Connect opens the provider and stores the refresh token. You can connect before saving.</p>
                    </div>
                @endif
            </div>
        @endforeach

        <div class="border-t border-slate-200 pt-4">
            <label class="block text-sm font-medium text-slate-700 mb-1">Quota (bytes, optional)</label>
            <input type="number" min="0" name="quota_bytes" value="{{ $config['quota_bytes'] ?? '' }}" placeholder="Leave blank for no quota" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
            <p class="text-xs text-slate-400 mt-1">Used by the usage panel when the provider does not report its own allocation.</p>
        </div>

        <div class="flex items-center gap-3 pt-2 border-t border-slate-200">
            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Save</button>
            <button type="submit" formaction="{{ route('admin.storage-spaces.external-storage.test', $space) }}" class="px-4 py-2 text-sm font-medium text-slate-700 bg-slate-100 rounded-lg hover:bg-slate-200">Test connection</button>
        </div>
    </form>

    @unless($inherited)
        <form method="POST"
              action="{{ route('admin.storage-spaces.external-storage.destroy', $space) }}"
              onsubmit="return confirm('Use the global storage driver for this space? The per-space credentials will be deleted.')">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-xs text-red-600 hover:underline">Use global default</button>
        </form>
    @endunless

    <p class="text-xs text-slate-400">Saving stores credentials encrypted. Switching a space's driver does not move existing files; the previous configuration is not retained, so migrate files before switching if they must remain reachable.</p>
</div>
@endsection
