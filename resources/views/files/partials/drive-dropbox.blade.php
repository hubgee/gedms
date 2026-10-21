{{-- Option C — Modern: Collapsible tree, slide-over preview, spacious cards --}}
<div class="gov-drive flex flex-col min-h-[540px]" x-data="{ treeOpen: false }">
    <div class="gov-drive-card flex flex-1 overflow-hidden">
        {{-- Collapsible sidebar --}}
        <aside class="shrink-0 transition-all duration-300 ease-out" :class="treeOpen ? 'w-56' : 'w-16'">
            <div class="h-full flex flex-col border-r border-slate-200 bg-gradient-to-b from-slate-50 to-white" :class="!treeOpen && 'items-center'">
                <div class="flex items-center justify-between px-3 py-3 border-b border-slate-200 shrink-0" :class="!treeOpen && 'flex-col gap-2'">
                    <div x-show="treeOpen" x-cloak>
                        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">{{ in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived']) ? 'View' : 'Quick access' }}</h3>
                        <p class="text-sm font-semibold text-slate-800">{{ in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived']) ? (($viewMode ?? '') === 'shared' ? 'Shared with you' : (($viewMode ?? '') === 'shared-by-me' ? 'Shared by me' : (($viewMode ?? '') === 'locked' ? 'Locked Files' : 'Archived'))) : 'Folders' }}</p>
                    </div>
                    <button @click="treeOpen = !treeOpen" class="p-2 rounded-lg hover:bg-slate-100 text-slate-600 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" :class="treeOpen ? 'rotate-180' : ''">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                        </svg>
                    </button>
                </div>
                <nav class="flex-1 overflow-y-auto p-2 space-y-0.5" :class="!treeOpen && 'flex flex-col items-center'">
                    <a href="{{ route('files.index', ['space' => $space->id]) }}"
                       class="gov-drive-tree-link {{ !$folder ? 'active' : 'text-slate-700' }}"
                       :class="!treeOpen && 'collapsed'"
                       title="Root">
                        <svg class="w-5 h-5 shrink-0 opacity-80" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                        <span x-show="treeOpen" x-cloak class="truncate">Root</span>
                    </a>
                    @foreach ($folderTree as $node)
                        @include('files.partials.folder-tree-node-gov-collapsed', ['node' => $node, 'currentFolder' => $folder, 'spaceId' => $space->id])
                    @endforeach
                </nav>
            </div>
        </aside>
        <div class="flex-1 min-w-0 flex flex-col">
            @include('files.partials.drive-toolbar-gov', ['style' => 'dropbox'])
            @include('files.partials.drive-content', ['style' => 'dropbox'])
        </div>
    </div>
</div>
