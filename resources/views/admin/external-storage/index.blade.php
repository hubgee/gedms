@extends('layouts.app')

@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-semibold text-slate-800">External storage</h1>
    <p class="text-slate-500">Configure where each space stores its files. A space with no configuration uses the global driver from <code>config/uploads.php</code>.</p>

    @if(session('success'))
        <div class="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">{{ session('error') }}</div>
    @endif

    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Type</label>
            <select name="type" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach(['personal','section','department','ministry'] as $t)
                    <option value="{{ $t }}" {{ $type === $t ? 'selected' : '' }}>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Search</label>
            <input type="text" name="q" value="{{ $q }}" placeholder="space name" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Filter</button>
    </form>

    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-600">Space</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-600">Type</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-600">Owner</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-600">Driver</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-600">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($spaces as $space)
                    @php
                        $owner = $space->ownerDepartment?->name
                            ?? $space->ownerSection?->name
                            ?? $space->ownerMinistry?->name
                            ?? $space->ownerUser?->name
                            ?? '—';
                    @endphp
                    <tr>
                        <td class="px-4 py-3 text-slate-800">{{ $space->name ?? 'Space #'.$space->id }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $space->type }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $owner }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $space->storage_driver ?: 'global default' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.storage-spaces.external-storage.edit', $space) }}" class="text-blue-600 hover:underline">Configure</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $spaces->links() }}
    </div>
</div>
@endsection