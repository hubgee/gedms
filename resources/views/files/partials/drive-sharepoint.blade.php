{{-- Option B — Records/Compliance: Fixed right panel, heavy metadata, formal table --}}
<div class="gov-drive flex min-h-[540px]">
    <div class="gov-drive-card flex flex-1 overflow-hidden">
        <aside class="gov-drive-sidebar w-56 shrink-0 flex flex-col">
            @if(in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived']))
            <div class="px-4 py-3 border-b border-slate-200 bg-white">
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">View</h3>
                <p class="text-sm font-semibold text-slate-800 mt-0.5">
                    {{ ($viewMode ?? '') === 'shared' ? 'Shared with you' : (($viewMode ?? '') === 'shared-by-me' ? 'Shared by me' : (($viewMode ?? '') === 'locked' ? 'Locked Files' : 'Archived')) }}
                </p>
            </div>
            <div class="flex-1 p-3 text-sm text-slate-500">
                @if(($viewMode ?? '') === 'shared') Files others have shared with you.
                @elseif(($viewMode ?? '') === 'shared-by-me') Files and folders you have shared with others.
                @elseif(($viewMode ?? '') === 'locked') Files you have checked out.
                @else Deleted files. Restore or permanently remove.
                @endif
            </div>
            @else
            <div class="px-4 py-3 border-b border-slate-200 bg-white">
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Records</h3>
                <p class="text-sm font-semibold text-slate-800 mt-0.5">Classification</p>
            </div>
            <nav class="flex-1 overflow-y-auto p-3 space-y-0.5">
                <a href="{{ route('files.index', ['space' => $space->id]) }}"
                   class="gov-drive-tree-link {{ !$folder ? 'active' : 'text-slate-700' }}">
                    <svg class="w-4 h-4 shrink-0 opacity-80" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                    <span>Root</span>
                </a>
                @foreach ($folderTree as $node)
                    @include('files.partials.folder-tree-node-gov', ['node' => $node, 'currentFolder' => $folder, 'spaceId' => $space->id])
                @endforeach
            </nav>
            @endif
        </aside>
        <div class="flex-1 min-w-0 flex flex-col border-r border-slate-100">
            @include('files.partials.drive-toolbar-gov', ['style' => 'sharepoint'])
            @include('files.partials.drive-content-sharepoint')
        </div>
        {{-- Fixed right panel — preview + metadata --}}
        <div class="w-80 xl:w-96 shrink-0 flex flex-col bg-slate-50/50 border-l border-slate-200">
            <template x-if="selectedFile">
                <div class="flex-1 flex flex-col min-h-0">
                    @include('files.partials.preview-body-gov')
                    <div class="p-4 bg-white border-t border-slate-200 text-sm">
                        <h4 class="text-xs font-bold uppercase tracking-widest text-slate-500 mb-3">Document details</h4>
                        <dl class="space-y-2">
                            <div class="flex justify-between"><dt class="text-slate-500">Version</dt><dd class="font-medium text-slate-800" x-text="selectedFile?.version ?? '—'"></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Type</dt><dd class="text-slate-800 truncate max-w-[140px]" x-text="selectedFile?.mime_type ?? '—'"></dd></div>
                        </dl>
                    </div>
                </div>
            </template>
            <div x-show="!selectedFile" class="flex-1 flex flex-col items-center justify-center p-8 text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-600">Select a file</p>
                <p class="text-xs text-slate-400 mt-1">Preview and metadata will appear here</p>
            </div>
        </div>
    </div>
</div>
