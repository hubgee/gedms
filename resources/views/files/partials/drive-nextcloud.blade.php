@php
    $sortBase = array_filter(array_merge(request()->query(), ['space' => $space->id ?? null, 'folder' => $folder?->id ?? null, 'view' => $viewMode ?? null]), fn($v) => $v !== null && $v !== '');
    $sortUrl = function($col, $ord) use ($sortBase) {
        return route('files.index', array_merge($sortBase, ['sort' => $col, 'order' => $ord]));
    };
    $personalSpace = isset($spaces) ? $spaces->first(fn($s) => $s->type === \App\Models\StorageSpace::TYPE_PERSONAL) : null;
    $sharedSpace = isset($spaces) ? ($spaces->first(fn($s) => $s->type === \App\Models\StorageSpace::TYPE_MINISTRY) ?? $spaces->first(fn($s) => $s->type === \App\Models\StorageSpace::TYPE_DEPARTMENT) ?? $spaces->first(fn($s) => $s->type === \App\Models\StorageSpace::TYPE_SECTION)) : null;
@endphp
{{-- Nextcloud-style Drive: left sidebar, breadcrumbs, grid/list, details panel --}}
<div class="nc-drive flex flex-col min-h-[540px] bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="flex flex-1 min-h-0">
        {{-- 1. Left sidebar (collapsible) --}}
        <aside class="nc-sidebar shrink-0 border-r border-slate-200 bg-slate-50/50 flex flex-col transition-all duration-200" :class="sidebarCollapsed ? 'w-14' : 'w-52'">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between shrink-0">
                <span x-show="!sidebarCollapsed" x-cloak class="text-xs font-semibold uppercase tracking-wider text-slate-500">Navigate</span>
                <button @click="sidebarCollapsed = !sidebarCollapsed" class="p-2 rounded-lg hover:bg-slate-200/80 text-slate-500" title="Toggle sidebar">
                    <svg class="w-4 h-4 transition-transform" :class="sidebarCollapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                </button>
            </div>
            <nav class="flex-1 overflow-y-auto p-2 space-y-0.5">
                <a href="{{ $personalSpace ? route('files.index', ['space' => $personalSpace->id]) : route('files.index') }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $space && $personalSpace && $space->id === $personalSpace->id && !in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived','favorites']) ? 'bg-blue-100 text-blue-700' : 'text-slate-600 hover:bg-slate-200/80' }}" title="My Files">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>My Files</span>
                </a>
                @if($sharedSpace)
                <a href="{{ route('files.index', ['space' => $sharedSpace->id]) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $space && $space->id === $sharedSpace->id && !in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived','favorites']) ? 'bg-blue-100 text-blue-700' : 'text-slate-600 hover:bg-slate-200/80' }}" title="Shared Space ({{ $sharedSpace->type === \App\Models\StorageSpace::TYPE_MINISTRY ? 'Ministry' : ($sharedSpace->type === \App\Models\StorageSpace::TYPE_DEPARTMENT ? 'Department' : 'Section') }})">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Shared Space</span>
                </a>
                @endif
                <a href="{{ route('files.index', ['view' => 'shared-by-me']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'shared-by-me' ? 'bg-blue-100 text-blue-700' : 'text-slate-600 hover:bg-slate-200/80' }}" title="Files and folders you have shared">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Shared by me</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'shared']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'shared' ? 'bg-blue-100 text-blue-700' : 'text-slate-600 hover:bg-slate-200/80' }}" title="Files and folders others have shared with you">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Shared with you</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'archived']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'archived' ? 'bg-blue-100 text-blue-700' : 'text-slate-600 hover:bg-slate-200/80' }}" title="Deleted files">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Trash</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'locked']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'locked' ? 'bg-blue-100 text-blue-700' : 'text-slate-600 hover:bg-slate-200/80' }}" title="Locked">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 11V7a5 5 0 0 1 10 0v4M4 11h12v9H4z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Locked</span>
                </a>
                <a href="{{ route('files.index', ['view' => 'favorites']) }}" class="nc-nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ ($viewMode ?? '') === 'favorites' ? 'bg-blue-100 text-blue-700' : 'text-slate-600 hover:bg-slate-200/80' }}" title="Favorites">
                    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                    <span x-show="!sidebarCollapsed" x-cloak>Favorites</span>
                </a>
                <div class="pt-2 mt-2 border-t border-slate-200">
                    @if(auth()->user()?->can('manage-storage') || auth()->user()?->hasRole('admin'))
                        <a href="{{ route('admin.storage-spaces.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-200/80" title="Configure external storage">
                            <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                            <span x-show="!sidebarCollapsed" x-cloak>External storage</span>
                        </a>
                    @else
                        <div class="flex items-center gap-3 px-3 py-2 rounded-lg text-slate-400 text-sm" title="Coming soon">
                            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                            <span x-show="!sidebarCollapsed" x-cloak>External storage</span>
                        </div>
                    @endif
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
                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="search" name="q" placeholder="Search…" class="pl-8 pr-3 py-2 text-sm rounded-lg border border-slate-200 w-40 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    </div>
                </form>
                {{-- Details toggle --}}
                <button @click="detailsOpen = !detailsOpen" :class="detailsOpen ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50" title="Toggle details panel">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </button>
                {{-- View switcher --}}
                <div class="flex rounded-lg border border-slate-200 p-0.5">
                    <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    </button>
                    <button @click="viewMode = 'grid'" :class="viewMode === 'grid' ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    </button>
                </div>
                {{-- New (folder + upload) - Google Drive style --}}
                @if(($canCreateFolder ?? false))
                <div class="relative flex items-center gap-1" x-data="{ newOpen: false, showFolderForm: false }" @click.outside="if (!$event.target.closest('[data-nc-new-dropdown]')) { newOpen = false; showFolderForm = false }">
                    <button type="button" id="nc-new-btn" @click="newOpen = !newOpen; showFolderForm = false" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New
                    </button>
                    <template x-teleport="body">
                    <div x-show="newOpen" x-cloak x-transition data-nc-new-dropdown
                         x-init="$watch('newOpen', v => { if (v) { $nextTick(() => { const btn = document.getElementById('nc-new-btn'); if (btn) { const r = btn.getBoundingClientRect(); $el.style.cssText = 'position:fixed;top:'+(r.bottom+4)+'px;right:'+(window.innerWidth - r.right)+'px;z-index:9989;'; } }); } })"
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
                            <svg class="w-5 h-5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
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

            {{-- Bulk actions toolbar.
                 These bindings read `selectedIds` directly: Alpine only tracks
                 properties touched through its reactive proxy, and a method
                 call on the component resolves with a non-proxy `this`, so
                 `x-show="selectedCount() > 0"` never re-ran. --}}
            <div x-show="selectedIds.length > 0" x-cloak
                 class="nc-bulk-toolbar flex items-center gap-4 px-4 py-2 bg-blue-50 border-b border-blue-100 text-sm">
                <span class="font-medium text-blue-800" x-text="selectedIds.length + ' selected'"></span>
                @if($canCreateFolder ?? false)
                <button type="button" @click="copyToClipboard(getSelectedItems().all)" class="text-blue-700 hover:underline font-medium">Copy</button>
                <button type="button" @click="cutToClipboard(getSelectedItems().all)" class="text-blue-700 hover:underline font-medium">Cut</button>
                <button type="button" x-show="clipboard.items.length > 0" @click="submitPaste({{ $folder?->id ?? 'null' }})" class="text-blue-700 hover:underline font-medium">Paste</button>
                @endif
                <form id="bulk-delete-form" action="{{ route('files.bulk-destroy') }}" method="POST" class="inline" @submit.prevent="submitBulkDelete()">
                    @csrf
                    {{-- Inputs are populated from getSelectedItems() by submitBulkDelete() --}}
                    <div data-items></div>
                    <button type="submit" class="text-red-600 hover:underline font-medium">Delete</button>
                </form>
                @if($canCreateFolder ?? false)
                <div class="relative" x-data="{ moveOpen: false }">
                    <button @click="moveOpen = !moveOpen" type="button" class="text-blue-700 hover:underline font-medium">Move</button>
                    <div x-show="moveOpen" @click.outside="moveOpen = false" x-cloak class="absolute left-0 mt-1 bg-white rounded-lg shadow-lg border border-slate-200 p-3 z-[110] min-w-[200px] max-h-48 overflow-y-auto">
                        <select x-ref="moveFolderSelect" class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 mb-2">
                            <option value="">Root</option>
                            @foreach($flatFolderOptions ?? [] as $opt)
                                <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                            @endforeach
                        </select>
                        {{-- `$refs` is evaluated in this small scope, so it
                             resolves to the <select> declared just above. --}}
                        <button type="button" @click="moveSelectedTo($refs.moveFolderSelect.value); moveOpen = false" class="w-full px-3 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">Move</button>
                    </div>
                </div>
                @endif
                <a :href="'{{ url('/files') }}/' + getSelectedIds()[0] + '/download'" x-show="selectedIds.length === 1" class="text-blue-700 hover:underline font-medium">Download</a>
                <button @click="clearSelection()" class="text-slate-500 hover:text-slate-700">Clear</button>
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
