{{-- Option A — Government: Clean, structured, full tree, right drawer --}}
<div class="gov-drive flex flex-col min-h-[540px]">
    <div class="gov-drive-card flex overflow-hidden flex-1">
        <aside class="gov-drive-sidebar w-60 shrink-0 flex flex-col">
            @if(in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived']))
            <div class="px-4 py-3 border-b border-slate-200 bg-white">
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">View</h3>
                <p class="text-sm font-semibold text-slate-800 mt-0.5">
                    @if(($viewMode ?? '') === 'shared') Shared with you
                    @elseif(($viewMode ?? '') === 'shared-by-me') Shared by me
                    @elseif(($viewMode ?? '') === 'locked') Locked Files
                    @elseif(($viewMode ?? '') === 'archived') Archived
                    @endif
                </p>
            </div>
            <div class="flex-1 p-3 text-sm text-slate-500">
                @if(($viewMode ?? '') === 'shared')
                    Files others have shared with you.
                @elseif(($viewMode ?? '') === 'shared-by-me')
                    Files and folders you have shared with others.
                @elseif(($viewMode ?? '') === 'locked')
                    Files you have checked out.
                @elseif(($viewMode ?? '') === 'archived')
                    Deleted files. Restore or permanently remove.
                @endif
            </div>
            @else
            <div class="px-4 py-3 border-b border-slate-200 bg-white">
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">File locations</h3>
                <p class="text-sm font-semibold text-slate-800 mt-0.5">Folders</p>
            </div>
            <nav class="flex-1 overflow-y-auto p-3 space-y-0.5">
                <a href="{{ route('files.index', ['space' => $space->id]) }}"
                   class="gov-drive-tree-link {{ !$folder ? 'active' : 'text-slate-700' }}">
                    <svg class="w-5 h-5 shrink-0 opacity-80" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                    <span>Root</span>
                </a>
                @foreach ($folderTree as $node)
                    @include('files.partials.folder-tree-node-gov', ['node' => $node, 'currentFolder' => $folder, 'spaceId' => $space->id])
                @endforeach
            </nav>
            @endif
        </aside>
        <div class="flex-1 min-w-0 flex flex-col">
            @include('files.partials.drive-toolbar-gov', ['style' => 'drive'])
            @include('files.partials.drive-content', ['style' => 'drive'])
        </div>
    </div>
</div>
