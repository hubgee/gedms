@php
    $sortBase = array_filter(array_merge(request()->query(), ['space' => $space->id ?? null, 'folder' => $folder?->id ?? null, 'view' => $viewMode ?? null]), fn($v) => $v !== null && $v !== '');
    $sortUrl = function($col, $ord) use ($sortBase) {
        return route('files.index', array_merge($sortBase, ['sort' => $col, 'order' => $ord]));
    };
    $personalSpace = isset($spaces) ? $spaces->first(fn($s) => $s->type === \App\Models\StorageSpace::TYPE_PERSONAL) : null;
    $sharedSpace = isset($spaces) ? ($spaces->first(fn($s) => $s->type === \App\Models\StorageSpace::TYPE_MINISTRY) ?? $spaces->first(fn($s) => $s->type === \App\Models\StorageSpace::TYPE_DEPARTMENT)) : null;
@endphp
{{-- Nextcloud-style Drive: left sidebar, breadcrumbs, grid/list, details panel --}}
<div class="nc-drive flex flex-col min-h-[540px] bg-white rounded-xl border border-slate-200 overflow-hidden" x-data="{ viewMode: 'list', sidebarCollapsed: false }">
    <div class="flex flex-1 min-h-0">
        {{-- 1. Left sidebar (collapsible) --}}
        <aside class="nc-sidebar shrink-0 border-r border-slate-200 bg-slate-50/50 flex flex-col transition-all duration-200" :class="sidebarCollapsed ? 'w-14' : 'w-52'">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between shrink-0">
                <span x-show="!sidebarCollapsed" x-cloak class="text-xs font-semibold uppercase tracking-wider text-slate-500">Navigate</span>
                <button @click="sidebarCollapsed = !sidebarCollapsed" class="p-2 rounded-lg hover:bg-slate-200/80 text-slate-500" title="Toggle sidebar">
                    <svg class="w-4 h-4 transition-transform" :class="sidebarCollapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
            </div>
            <nav class="flex-1 overflow-y-auto p-2 space-y-0.5">
                <a href="{{ $personalSpace ? route('files.index', ['space' => $personalSpace->id]) : route('files.index') }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $space && $space->id === ($personalSpace?->id ?? null) ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>My Files</span>
                </a>
                @if($sharedSpace)
                <a href="{{ route('files.index', ['space' => $sharedSpace->id]) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $space && $space->id === $sharedSpace->id ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7a3 3 0 116 0 3 3 0 01-6 0zm-5 12a7 7 0 0114 0"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Shared Space</span>
                </a>
                @endif
                <a href="{{ route('files.index', ['view' => 'shared-by-me']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'shared-by-me' ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7a3 3 0 116 0 3 3 0 01-6 0zm-5 12a7 7 0 0114 0"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Shared by me</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'shared']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'shared' ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8a3 3 0 110 6 3 3 0 010-6zm-10 0a3 3 0 110 6 3 3 0 010-6zm0 8a7 7 0 0114 0"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Shared with you</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'archived']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'archived' ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 7h14l-1 12H6L5 7zm2-2h10l1 2H6l1-2z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Trash</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'locked']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'locked' ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 10V7a5 5 0 0110 0v3M6 10h12v9H6v-9z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Locked</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'favorites']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'favorites' ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 17.3l-5.2 2.7 1-5.8L2 9.5l5.8-.8L12 3l4.2 5.7 5.8.8-5.8 4.7 1 5.8L12 17.3z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Favorites</span>
                </a>
                <div class="pt-2 mt-2 border-t border-slate-200">
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg text-slate-400 text-sm" title="Coming soon">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16v10H4zM4 10h16"/></svg>
                        <span x-show="!sidebarCollapsed" x-cloak>External storage</span>
                    </div>
                </div>
            </nav>
        </aside>

        {{-- 2 & 3. Main area: top bar + content --}}
        <div class="flex-1 flex flex-col min-w-0">
            {{-- Top bar: breadcrumbs, search, New, view, sort, details toggle --}}
            <div class="nc-toolbar sticky top-0 z-10 flex flex-wrap items-center gap-3 px-4 py-3 bg-white border-b border-slate-200 shrink-0">
                {{-- Breadcrumbs --}}
                <nav class="flex items-center gap-1 text-sm min-w-0" aria-label="Breadcrumb">
                    @foreach ($breadcrumbs ?? [] as $i => $crumb)
                        @if ($i > 0)<span class="text-slate-300">/</span>@endif
                        @if ($crumb['url'])
                            <a href="{{ $crumb['url'] }}" class="text-slate-600 hover:text-blue-600 truncate max-w-[120px]" title="{{ $crumb['name'] }}">{{ $crumb['name'] }}</a>
                        @else
                            <span class="text-slate-800 font-medium truncate">{{ $crumb['name'] }}</span>
                        @endif
                    @endforeach
                </nav>
                <div class="flex-1 min-w-0"></div>
                {{-- Search --}}
                <form action="{{ route('search.index') }}" method="GET" class="hidden sm:block">
                    <input type="hidden" name="scope" value="files">
                    <div class="relative">
                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"/></svg>
                        <input type="search" name="q" placeholder="Search…" class="pl-8 pr-3 py-2 text-sm rounded-lg border border-slate-200 w-40 focus:border-blue-500 focus:ring-1 focus:ring-blue-500/30">
                    </div>
                </form>
                {{-- Details toggle --}}
                <button @click="$root.detailsOpen = !$root.detailsOpen" :class="$root.detailsOpen ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50" title="Toggle details panel">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V7a2 2 0 012-2h10a2 2 0 012 2v12a2 2 0 01-2 2z"/></svg>
                </button>
                {{-- View switcher --}}
                <div class="flex rounded-lg border border-slate-200 p-0.5">
                    <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    </button>
                    <button @click="viewMode = 'grid'" :class="viewMode === 'grid' ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm0 10a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10-10a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zm0 10a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    </button>
                </div>
                {{-- New (folder + upload) - Google Drive style --}}
                @if(($canCreateFolder ?? false))
                <div class="relative flex items-center gap-1" x-data="{ newOpen: false, showFolderForm: false }" @click.outside="if (!$event.target.closest('[data-nc-new-dropdown]')) { newOpen = false }">
                    <button type="button" id="nc-new-btn" @click="newOpen = !newOpen; showFolderForm = false" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New
                    </button>
                    <template x-teleport="body">
                    <div x-show="newOpen" x-cloak x-transition data-nc-new-dropdown
                         x-init="$watch('newOpen', v => { if (v) { $nextTick(() => { const btn = document.getElementById('nc-new-btn'); if (btn) { const r = btn.getBoundingClientRect(); $el.style.position = 'fixed'; $el.style.left = (r.left) + 'px'; $el.style.top = (r.bottom + 8) + 'px'; } }); } })"
                         class="bg-white rounded-lg shadow-xl border border-slate-200 py-1 min-w-[220px]">
                        {{-- New folder: expandable form (Google Drive style) --}}
                        <div @click.stop>
                            <button x-show="!showFolderForm" type="button" @click="showFolderForm = true; $nextTick(() => $refs.folderNameInput?.focus())"
                                class="w-full text-left px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-3">
                                <svg class="w-5 h-5 text-slate-500 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                New folder
                            </button>
                            <div x-show="showFolderForm" x-cloak class="px-3 py-3 border-t border-slate-100">
                                <form action="{{ route('folders.store') }}" method="POST" id="nc-new-folder-form">
                                    @csrf
                                    <input type="hidden" name="space_id" value="{{ $space->id }}">
                                    @if($folder)<input type="hidden" name="parent_id" value="{{ $folder->id }}">@endif
                                    <input type="text" name="name" value="Untitled folder" x-ref="folderNameInput"
                                        class="w-full px-3 py-2 text-sm rounded-lg border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 mb-2"
                                        @focus="$event.target.select()">
                                    <div class="flex gap-2">
                                        <button type="submit" class="flex-1 px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Create</button>
                                        <button type="button" @click="showFolderForm = false" class="px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 rounded-lg">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        {{-- Upload file --}}
                        <button type="button" class="w-full text-left px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-3" onclick="document.getElementById('nc-upload-input').click()">
                            <svg class="w-5 h-5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16.5A2.5 2.5 0 006.5 19H17.5A2.5 2.5 0 0020 16.5"/></svg>
                            File upload
                        </button>
                        <form id="nc-upload-form" action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" class="hidden" data-chunked-upload>
                            @csrf
                            <input type="hidden" name="space_id" value="{{ $space->id }}">
                            @if($folder)<input type="hidden" name="folder_id" value="{{ $folder->id }}">@endif
                            <input type="file" name="file[]" id="nc-upload-input" multiple>
                        </form>
                    </div>
                    </template>
                </div>
                @endif
            </div>

            {{-- Bulk actions toolbar --}}
            <div x-show="$root.getSelectedIds().length > 0" x-cloak
                 class="nc-bulk-toolbar flex items-center gap-4 px-4 py-2 bg-blue-50 border-b border-blue-100 text-sm">
                <span class="font-medium text-blue-800" x-text="$root.getSelectedIds().length + ' selected'"></span>
                @if($canCreateFolder ?? false)
                <button type="button" @click="$root.copyToClipboard($root.getSelectedItems().all)" class="text-blue-700 hover:underline font-medium">Copy</button>
                <button type="button" @click="$root.cutToClipboard($root.getSelectedItems().all)" class="text-blue-700 hover:underline font-medium">Cut</button>
                <button type="button" x-show="$root.hasClipboard()" @click="$root.submitPaste({{ $folder?->id ?? 'null' }})" class="text-blue-700 hover:underline font-medium">Paste</button>
                @endif
                <form action="{{ route('files.bulk-destroy') }}" method="POST" class="inline" onsubmit="return confirm('Delete selected items?')">
                    @csrf
                    <template x-for="id in $root.getSelectedIds()" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <button type="submit" class="text-red-600 hover:underline font-medium">Delete</button>
                </form>
                @if($canCreateFolder ?? false)
                <div class="relative" x-data="{ moveOpen: false }">
                    <button @click="moveOpen = !moveOpen" type="button" class="text-blue-700 hover:underline font-medium">Move</button>
                    <div x-show="moveOpen" @click.outside="moveOpen = false" x-cloak class="absolute left-0 mt-1 bg-white rounded-lg shadow-lg border border-slate-200 p-3 z-[110] min-w-[200px] max-h-[300px] overflow-y-auto">
                        <select x-ref="moveFolderSelect" class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 mb-2">
                            <option value="">Root</option>
                            @foreach($flatFolderOptions ?? [] as $opt)
                                <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                            @endforeach
                        </select>
                        <button type="button" @click="$root.submitPaste($refs.moveFolderSelect.value); moveOpen = false" class="w-full px-3 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">Move here</button>
                    </div>
                </div>
                @endif
                <a :href="'{{ url('/files') }}/' + $root.getSelectedIds()[0] + '/download'" x-show="$root.getSelectedIds().length === 1" class="text-blue-700 hover:underline font-medium">Download</a>
                <button @click="$root.clearSelection()" class="text-slate-500 hover:text-slate-700">Clear</button>
            </div>

            {{-- Main content --}}
            @include('files.partials.drive-content', ['style' => 'nextcloud', 'sortUrl' => $sortUrl ?? null, 'sortBy' => $sortBy ?? 'name', 'sortOrder' => $sortOrder ?? 'asc'])
        </div>
    </div>
</div>

<style>
.nc-drive .nc-nav-item span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nc-drive .table-row:hover { background-color: rgb(248 250 252); }
.nc-drive table thead th { font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: rgb(100 116 139); }
.nc-drive table thead th:first-child { padding-left: 1rem; }
</style>
