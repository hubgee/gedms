@php
    $style = $style ?? 'drive';
    $allowDrop = $canCreateFolder ?? false;
    $currentUserId = auth()->id();
    $isNormalDrive = !in_array($viewMode ?? null, ['shared','shared-by-me','locked','archived','favorites']);
    $dropFolderId = isset($folder) ? ($folder?->id ?? '') : '';
    $dropSpaceId = isset($space) ? ($space->id ?? '') : '';
@endphp
<div class="flex-1 overflow-auto p-4 {{ $style === 'dropbox' ? 'p-6' : '' }}" id="drop-zone"
     x-data="{ contextMenu: null, contextX: 0, contextY: 0, shareModalOpen: false, shareTarget: null }"
     @if($allowDrop) x-on:dragover.prevent="dragOver = true" x-on:dragleave="dragOver = false" x-on:drop.prevent="handleDrop($event, {{ $dropFolderId ?: 'null' }}, {{ $dropSpaceId ?: 'null' }})" @endif
     :class="dragOver ? 'ring-2 ring-slate-400 ring-inset' : ''"
     @contextmenu.prevent="contextMenu = null">
    @if($allowDrop && isset($space))
    <form id="drop-upload-form" action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" class="hidden" data-chunked-upload>
        @csrf
        <input type="hidden" name="space_id" value="{{ $space->id }}">
        <input type="hidden" name="folder_id" value="{{ $folder?->id }}">
        <input type="file" name="file[]" id="drop-file-input">
    </form>
    <form id="bulk-paste-form" action="{{ route('files.bulk-paste') }}" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="operation" value="cut">
        <input type="hidden" name="folder_id" value="">
        <input type="hidden" name="space_id" value="{{ $space->id ?? '' }}">
        <div data-items></div>
    </form>
    @endif

    <div x-show="viewMode === 'list'" class="space-y-0">
        <table class="min-w-full {{ $style === 'drive' ? 'gov-drive-table' : '' }} {{ $style === 'dropbox' ? 'text-sm' : '' }} {{ $style === 'nextcloud' ? 'text-sm' : '' }}">
            <thead class="{{ $style === 'drive' ? '' : 'table-header' }}">
                <tr>
                    <th class="w-10"><input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" @change="toggleAll($event)"></th>
                    @if($style === 'nextcloud' && isset($sortUrl))
                    <th><a href="{{ $sortUrl('name', ($sortBy ?? 'name') === 'name' && ($sortOrder ?? 'asc') === 'asc' ? 'desc' : 'asc') }}" class="hover:text-slate-700 {{ ($sortBy ?? '') === 'name' ? 'text-blue-600 font-semibold' : '' }}">Name</a></th>
                    <th><a href="{{ $sortUrl('size', ($sortBy ?? '') === 'size' && ($sortOrder ?? 'asc') === 'asc' ? 'desc' : 'asc') }}" class="hover:text-slate-700 {{ ($sortBy ?? '') === 'size' ? 'text-blue-600 font-semibold' : '' }}">Size</a></th>
                    <th><a href="{{ $sortUrl('modified', ($sortBy ?? '') === 'modified' && ($sortOrder ?? 'asc') === 'asc' ? 'desc' : 'asc') }}" class="hover:text-slate-700 {{ ($sortBy ?? '') === 'modified' ? 'text-blue-600 font-semibold' : '' }}">Modified</a></th>
                    @else
                    <th>Name</th>
                    <th>{{ $style === 'nextcloud' ? 'Size' : 'Metadata' }}</th>
                    <th>Modified</th>
                    @endif
                    @if(in_array($viewMode ?? '', ['shared', 'shared-by-me']))
                    <th>Shared by</th>
                    @endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($folders as $f)
                    @php $isLockedByMe = $f->locked_by && $f->locked_by === $currentUserId; $isCreator = $f->created_by && $f->created_by === $currentUserId; $canMoveFolder = $isNormalDrive && ($isCreator || !$f->locked_by || $isLockedByMe); @endphp
                    <tr class="table-row {{ $canMoveFolder ? 'cursor-grab active:cursor-grabbing' : '' }}"
                        @if($canMoveFolder) draggable="true"
                        @dragstart="$event.dataTransfer.setData('application/x-gedms-drag', JSON.stringify({type:'folder',id:{{ $f->id }}})); $event.dataTransfer.effectAllowed = 'move'"
                        @endif
                        @if($allowDrop && $canMoveFolder) @dragover.prevent.stop @drop.prevent.stop="$parent.$parent.handleDrop($event, {{ $f->id }}, {{ $space->id ?? 'null' }})" @endif
                        @contextmenu.prevent.stop="contextMenu = { type: 'folder', id: {{ $f->id }}, name: '{{ addslashes($f->name) }}', locked_by: {{ $f->locked_by ?? 'null' }}, isLockedByMe: {{ $isLockedByMe ? 'true' : 'false' }}, isCreator: {{ $isCreator ? 'true' : 'false' }} }; contextX = $event.clientX; contextY = $event.clientY">
                        <td><input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 file-checkbox" value="{{ $f->id }}" data-type="folder"></td>
                        <td>
                            <a href="{{ route('files.index', in_array($viewMode ?? '', ['shared', 'shared-by-me']) ? ['view' => $viewMode, 'folder' => $f->id] : ['space' => $space->id ?? null, 'folder' => $f->id]) }}" class="font-medium text-slate-800 hover:text-slate-600 flex items-center gap-2"
                               @click="selectedFile = null">
                                <svg class="w-5 h-5 text-slate-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                {{ $f->name }}
                            </a>
                        </td>
                        <td class="text-slate-500">{{ $style === 'nextcloud' ? '—' : '—' }}</td>
                        <td class="text-slate-500">{{ $f->updated_at->format($style === 'nextcloud' ? 'M j, Y' : 'm.d.Y') }}</td>
                        @if(in_array($viewMode ?? '', ['shared', 'shared-by-me']))
                        <td class="text-slate-500">—</td>
                        @endif
                        <td>
                            @if($f->locked_by && $f->created_by !== $currentUserId)
                                <span class="text-xs text-slate-400">Locked</span>
                            @else
                                <form action="{{ route('folders.destroy', $f) }}" method="POST" class="inline" onsubmit="return confirm('Delete this folder?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 text-sm hover:underline">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @foreach ($files as $file)
                    @php
                        $metadata = $file->document
                            ? implode(', ', array_filter([$file->document->documentType?->name, $file->document->ministry?->name, $file->document->department?->name, $file->document->sensitivityLevel?->name]))
                            : '—';
                    @endphp
                    @php $fileLockedByMe = $file->locked_by && $file->locked_by === $currentUserId; @endphp
                    @php $canMoveFile = $isNormalDrive && !$file->trashed() && ($fileLockedByMe || !$file->locked_by); @endphp
                    <tr class="table-row {{ $canMoveFile ? 'cursor-grab active:cursor-grabbing' : '' }}" :class="selectedFile && selectedFile.id === {{ $file->id }} ? 'bg-indigo-50/80' : ''"
                        @if($canMoveFile) draggable="true"
                        @dragstart="$event.dataTransfer.setData('application/x-gedms-drag', JSON.stringify({type:'file',id:{{ $file->id }}})); $event.dataTransfer.effectAllowed = 'move'"
                        @endif
                        @contextmenu.prevent.stop="contextMenu = { type: 'file', id: {{ $file->id }}, name: '{{ addslashes($file->name) }}', hasDocument: {{ $file->document ? 'true' : 'false' }}, trashed: {{ $file->trashed() ? 'true' : 'false' }}, isFavorited: {{ in_array($file->id, $favoritedIds ?? []) ? 'true' : 'false' }}, locked_by: {{ $file->locked_by ?? 'null' }}, isLockedByMe: {{ $fileLockedByMe ? 'true' : 'false' }} }; contextX = $event.clientX; contextY = $event.clientY">
                        <td><input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 file-checkbox" value="{{ $file->id }}" data-type="file"></td>
                        <td>
                            <a href="#" @click.prevent="selectFile({{ json_encode(['id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'version' => $file->version, 'versions' => $file->versions->map(fn($v) => ['version' => $v->version, 'creator' => $v->creator?->name, 'date' => $v->created_at->format('M j, Y')])->values()->toArray(), 'doc_type' => $file->document?->documentType?->name, 'ministry' => $file->document?->ministry?->name, 'department' => $file->document?->department?->name, 'owner' => $file->document?->owner?->name, 'sensitivity' => $file->document?->sensitivityLevel?->name, 'tags' => $file->tags->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->toArray(), 'locked_by' => $file->locked_by, 'isLockedByMe' => $file->locked_by && $file->locked_by === $currentUserId]) }})"
                               class="font-medium text-slate-800 hover:text-slate-600 flex items-center gap-2">
                                @if(!($file->trashed()))
                                <form action="{{ route('files.favorite', $file) }}" method="POST" class="inline shrink-0" @click.stop>
                                    @csrf
                                    <button type="submit" class="p-0.5 rounded hover:bg-slate-100" title="{{ in_array($file->id, $favoritedIds ?? []) ? 'Remove from favorites' : 'Add to favorites' }}">
                                        <svg class="w-4 h-4 {{ in_array($file->id, $favoritedIds ?? []) ? 'text-amber-500 fill-amber-500' : 'text-slate-300 hover:text-amber-400' }}" fill="{{ in_array($file->id, $favoritedIds ?? []) ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                    </button>
                                </form>
                                @endif
                                @include('files.partials.file-icon', ['file' => $file])
                                {{ $file->name }}
                            </a>
                            <span class="text-xs text-slate-400 ml-7">v{{ $file->version }}</span>
                        </td>
                        <td class="text-slate-600 text-sm">{{ $style === 'nextcloud' ? (($file->size >= 1048576 ? round($file->size / 1048576, 1) . ' MB' : ($file->size >= 1024 ? round($file->size / 1024) . ' KB' : $file->size . ' B'))) : $metadata }}</td>
                        <td class="text-slate-500">{{ $file->updated_at->format($style === 'nextcloud' ? 'M j, Y' : 'm.d.Y') }}</td>
                        @if(in_array($viewMode ?? '', ['shared', 'shared-by-me']))
                        <td class="text-slate-600 text-sm">{{ ($sharedByMap[$file->id] ?? null)?->name ?? '—' }}</td>
                        @endif
                        <td class="space-x-2">
                            <a href="{{ route('files.download', $file) }}" class="text-slate-600 hover:underline text-sm">Download</a>
                            @if ($file->trashed())
                                <form action="{{ route('files.restore', $file) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="text-emerald-600 hover:underline text-sm">Restore</button>
                                </form>
                                <form action="{{ route('files.destroy', $file) }}" method="POST" class="inline" onsubmit="return confirm('Delete permanently? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline text-sm">Delete permanently</button>
                                </form>
                            @else
                                @if (!$file->document)
                                    <a href="{{ route('files.promote', $file) }}" class="text-emerald-600 hover:underline text-sm">Promote</a>
                                @endif
                                <form action="{{ route('files.update', $file) }}" method="POST" enctype="multipart/form-data" class="inline" id="ver-{{ $file->id }}">
                                    @csrf
                                    <input type="hidden" name="action" value="version">
                                    <input type="file" name="file" required class="hidden" onchange="this.form.submit()" id="ver-input-{{ $file->id }}">
                                    <button type="button" onclick="document.getElementById('ver-input-{{ $file->id }}').click()" class="text-slate-600 hover:underline text-sm">New version</button>
                                </form>
                                <form action="{{ route('files.destroy', $file) }}" method="POST" class="inline" onsubmit="return confirm('Delete?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline text-sm">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($folders->isEmpty() && $files->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center mb-4">
                    <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                </div>
                @if(($viewMode ?? '') === 'shared')
                    <p class="text-sm font-medium text-slate-600">No files shared with you</p>
                @elseif(($viewMode ?? '') === 'shared-by-me')
                    <p class="text-sm font-medium text-slate-600">No files or folders shared by you</p>
                @elseif(($viewMode ?? '') === 'locked')
                    <p class="text-sm font-medium text-slate-600">No locked files</p>
                @elseif(($viewMode ?? '') === 'archived')
                    <p class="text-sm font-medium text-slate-600">No archived files</p>
                @elseif(($viewMode ?? '') === 'favorites')
                    <p class="text-sm font-medium text-slate-600">No favorites yet</p>
                @else
                    <p class="text-sm font-medium text-slate-600">No files in this folder</p>
                    <p class="text-xs text-slate-400 mt-1">Drop files here, use Upload, or create a folder to get started.</p>
                @endif
            </div>
        @endif
    </div>

    <div x-show="viewMode === 'grid'" x-cloak class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-4 {{ in_array($style, ['dropbox','nextcloud']) ? 'gap-5 p-6' : 'p-4' }}">
        @foreach ($folders as $i => $f)
            @php
                $folderColor = match ($i % 4) { 0 => 'text-indigo-600', 1 => 'text-emerald-600', 2 => 'text-amber-600', default => 'text-slate-600' };
                $gridIsLockedByMe = $f->locked_by && $f->locked_by === $currentUserId;
                $gridIsCreator = $f->created_by && $f->created_by === $currentUserId;
            @endphp
            <a href="{{ route('files.index', in_array($viewMode ?? '', ['shared', 'shared-by-me']) ? ['view' => $viewMode, 'folder' => $f->id] : ['space' => $space->id ?? null, 'folder' => $f->id]) }}"
               class="block p-4 rounded-xl border border-slate-200/80 bg-white hover:border-indigo-200 hover:shadow-md hover:bg-slate-50/50 text-center transition-all duration-200 {{ $style === 'dropbox' ? 'p-5' : '' }} {{ ($isNormalDrive && ($gridIsCreator || !$f->locked_by || $gridIsLockedByMe)) ? 'cursor-grab active:cursor-grabbing' : '' }}"
               @click="selectedFile = null"
               @if($isNormalDrive && ($gridIsCreator || !$f->locked_by || $gridIsLockedByMe)) draggable="true"
               @dragstart="$event.dataTransfer.setData('application/x-gedms-drag', JSON.stringify({type:'folder',id:{{ $f->id }}})); $event.dataTransfer.effectAllowed = 'move'"
               @endif
               @if($allowDrop && $isNormalDrive && ($gridIsCreator || !$f->locked_by || $gridIsLockedByMe)) @dragover.prevent.stop @drop.prevent.stop="$parent.$parent.handleDrop($event, {{ $f->id }}, {{ $space->id ?? 'null' }})" @endif
               @contextmenu.prevent.stop="contextMenu = { type: 'folder', id: {{ $f->id }}, name: '{{ addslashes($f->name) }}', locked_by: {{ $f->locked_by ?? 'null' }}, isLockedByMe: {{ $gridIsLockedByMe ? 'true' : 'false' }}, isCreator: {{ $gridIsCreator ? 'true' : 'false' }} }; contextX = $event.clientX; contextY = $event.clientY">
                <svg class="w-12 h-12 mx-auto {{ $folderColor }} mb-2 {{ $style === 'dropbox' ? 'w-14 h-14' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                <span class="text-sm font-medium text-slate-800 truncate block">{{ $f->name }}</span>
            </a>
        @endforeach
        @foreach ($files as $file)
            @php $gridFileLockedByMe = $file->locked_by && $file->locked_by === $currentUserId; @endphp
            @php $gridCanMoveFile = $isNormalDrive && !$file->trashed() && ($gridFileLockedByMe || !$file->locked_by); @endphp
            <a href="#" @click.prevent="selectFile({{ json_encode(['id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'version' => $file->version, 'versions' => $file->versions->map(fn($v) => ['version' => $v->version, 'creator' => $v->creator?->name, 'date' => $v->created_at->format('M j, Y')])->values()->toArray(), 'locked_by' => $file->locked_by, 'isLockedByMe' => $file->locked_by && $file->locked_by === $currentUserId]) }})"
               class="block p-4 rounded-xl border border-slate-200/80 bg-white hover:border-indigo-200 hover:shadow-md hover:bg-slate-50/50 text-center transition-all duration-200 {{ $style === 'dropbox' ? 'p-5' : '' }} {{ $gridCanMoveFile ? 'cursor-grab active:cursor-grabbing' : '' }}"
               :class="selectedFile && selectedFile.id === {{ $file->id }} ? 'ring-2 ring-indigo-500 border-indigo-200 bg-indigo-50/30' : ''"
               @if($gridCanMoveFile) draggable="true"
               @dragstart="$event.dataTransfer.setData('application/x-gedms-drag', JSON.stringify({type:'file',id:{{ $file->id }}})); $event.dataTransfer.effectAllowed = 'move'"
               @endif
               @contextmenu.prevent.stop="contextMenu = { type: 'file', id: {{ $file->id }}, name: '{{ addslashes($file->name) }}', hasDocument: {{ $file->document ? 'true' : 'false' }}, trashed: {{ $file->trashed() ? 'true' : 'false' }}, isFavorited: {{ in_array($file->id, $favoritedIds ?? []) ? 'true' : 'false' }}, locked_by: {{ $file->locked_by ?? 'null' }}, isLockedByMe: {{ $gridFileLockedByMe ? 'true' : 'false' }} }; contextX = $event.clientX; contextY = $event.clientY">
                <div class="flex justify-center mb-2">
                    @include('files.partials.file-icon', ['file' => $file, 'size' => $style === 'dropbox' ? 'w-14 h-14' : 'w-12 h-12'])
                </div>
                <span class="text-sm font-medium text-slate-800 truncate block">{{ $file->name }}</span>
                <span class="text-xs text-slate-400">v{{ $file->version }}</span>
            </a>
        @endforeach
        @if ($folders->isEmpty() && $files->isEmpty())
            <div class="col-span-full flex flex-col items-center justify-center py-16 text-slate-500">
                <p class="text-sm font-medium text-slate-600">No files in this folder</p>
                <p class="text-xs text-slate-400 mt-1">Drop files here or use Upload</p>
            </div>
        @endif
    </div>

    {{-- Right-click context menu --}}
    <div x-show="contextMenu" x-cloak
         @click.outside="contextMenu = null"
         :style="contextMenu ? 'position: fixed; left: ' + contextX + 'px; top: ' + contextY + 'px; z-index: 9997;' : ''"
         class="bg-white rounded-lg shadow-xl border border-slate-200 py-1 min-w-[180px]">
        <template x-if="contextMenu?.type === 'folder'">
            <div>
                <a :href="contextMenu ? '/files?space=' + ({{ $space->id ?? 'null' }}) + '&folder=' + contextMenu.id : '#'" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Open</a>
                @if($isNormalDrive)
                <button type="button" x-show="contextMenu && (contextMenu.isCreator || !contextMenu.locked_by || contextMenu.isLockedByMe)" @click="$parent.$parent.openRenameModal({ type: 'folder', id: contextMenu.id, name: contextMenu.name }); contextMenu = null" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Rename</button>
                <button type="button" x-show="contextMenu && (contextMenu.isCreator || !contextMenu.locked_by || contextMenu.isLockedByMe)" @click="$parent.$parent.cutToClipboard([{ type: 'folder', id: contextMenu.id }]); contextMenu = null" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cut</button>
                <button type="button" x-show="contextMenu && $parent.$parent.hasClipboard()" @click="$parent.$parent.submitPaste(contextMenu.id)" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Paste</button>
                @endif
                <button type="button" @click="shareTarget = { type: 'folder', id: contextMenu.id }; shareModalOpen = true; contextMenu = null" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Share</button>
                <form :action="'{{ url('/folders') }}/' + contextMenu?.id + '/unlock'" method="POST" class="block" @click.stop x-show="contextMenu?.isLockedByMe">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Unlock</button>
                </form>
                <form :action="'{{ url('/folders') }}/' + contextMenu?.id + '/lock'" method="POST" class="block" @click.stop x-show="contextMenu && !contextMenu.isLockedByMe && !contextMenu.locked_by">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Lock</button>
                </form>
                <form :action="'{{ url('/folders') }}/' + contextMenu?.id" method="POST" class="block" @click.stop x-show="contextMenu && (contextMenu.isCreator || !contextMenu.locked_by)">
                    @csrf
                    @method('DELETE')
                    <button type="submit" onclick="return confirm('Delete this folder?')" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-slate-50">Delete</button>
                </form>
            </div>
        </template>
        <template x-if="contextMenu?.type === 'file'">
            <div>
                <a :href="contextMenu ? '{{ url('/files') }}/' + contextMenu.id + '/download' : '#'" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Download</a>
                @if($isNormalDrive)
                <button type="button" x-show="contextMenu && !contextMenu.trashed && (contextMenu.isLockedByMe || !contextMenu.locked_by)" @click="$parent.$parent.openRenameModal({ type: 'file', id: contextMenu.id, name: contextMenu.name }); contextMenu = null" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Rename</button>
                <button type="button" x-show="contextMenu && !contextMenu.trashed && (contextMenu.isLockedByMe || !contextMenu.locked_by)" @click="$parent.$parent.copyToClipboard([{ type: 'file', id: contextMenu.id }]); contextMenu = null" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Copy</button>
                <button type="button" x-show="contextMenu && !contextMenu.trashed && (contextMenu.isLockedByMe || !contextMenu.locked_by)" @click="$parent.$parent.cutToClipboard([{ type: 'file', id: contextMenu.id }]); contextMenu = null" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cut</button>
                @endif
                <form :action="'{{ url('/files') }}/' + contextMenu?.id + '/favorite'" method="POST" class="block" @click.stop>
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" x-text="contextMenu?.isFavorited ? 'Remove from favorites' : 'Add to favorites'"></button>
                </form>
                <button type="button" x-show="!contextMenu?.trashed" @click="shareTarget = { type: 'file', id: contextMenu.id }; shareModalOpen = true; contextMenu = null" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Share</button>
                <form :action="'{{ url('/files') }}/' + contextMenu?.id + '/unlock'" method="POST" class="block" @click.stop x-show="contextMenu?.isLockedByMe">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Unlock</button>
                </form>
                <form :action="'{{ url('/files') }}/' + contextMenu?.id + '/lock'" method="POST" class="block" @click.stop x-show="contextMenu && !contextMenu.isLockedByMe && !contextMenu.locked_by && !contextMenu.trashed">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Lock</button>
                </form>
                <a :href="contextMenu && !contextMenu.trashed ? '{{ url('/files') }}/' + contextMenu.id + '/promote' : '#'" x-show="contextMenu && !contextMenu.hasDocument && !contextMenu.trashed" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Promote to document</a>
                <form :action="'{{ url('/files') }}/' + contextMenu?.id" method="POST" x-show="contextMenu && !contextMenu.trashed" class="block" @click.stop>
                    @csrf
                    @method('DELETE')
                    <button type="submit" onclick="return confirm('Delete?')" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-slate-50">Delete</button>
                </form>
                <form :action="'{{ url('/files') }}/' + contextMenu?.id" method="POST" x-show="contextMenu?.trashed" class="block" @click.stop>
                    @csrf
                    @method('DELETE')
                    <button type="submit" onclick="return confirm('Delete permanently?')" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-slate-50">Delete permanently</button>
                </form>
            </div>
        </template>
    </div>

    {{-- Rename modal --}}
    @if($isNormalDrive)
    <div x-show="$parent.$parent.renameModalOpen" x-cloak
         class="fixed inset-0 z-[99998] flex items-center justify-center bg-black/40"
         @click.self="$parent.$parent.closeRenameModal()"
         x-transition>
        <div x-show="$parent.$parent.renameModalOpen" x-cloak @click.stop
             class="bg-white rounded-xl shadow-xl border border-slate-200 p-6 w-full max-w-md mx-4">
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Rename</h3>
            <form :action="$parent.$parent.renameTarget?.type === 'folder' ? '{{ url('/folders') }}/' + $parent.$parent.renameTarget?.id : '{{ url('/files') }}/' + $parent.$parent.renameTarget?.id" method="POST" @submit="$parent.$parent.closeRenameModal()">
                @csrf
                <input type="hidden" name="action" value="rename">
                <input type="text" name="name" :value="$parent.$parent.renameName"
                       @input="$parent.$parent.renameName = $event.target.value"
                       class="w-full px-3 py-2 rounded-lg border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 mb-4">
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Rename</button>
                    <button type="button" @click="$parent.$parent.closeRenameModal()" class="px-4 py-2 text-sm text-slate-600 hover:bg-slate-100 rounded-lg">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Share modal (Google Drive style: search users + copy link) --}}
    <div x-show="shareModalOpen" x-cloak
         class="fixed inset-0 z-[99998] flex items-center justify-center bg-black/40"
         @click.self="shareModalOpen = false"
         x-transition>
        <div x-show="shareModalOpen" x-cloak
             @click.stop
             class="bg-white rounded-xl shadow-xl border border-slate-200 p-6 w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto"
             x-data="{
                shareSearchQuery: '',
                shareSearchResults: [],
                shareSelectedUser: null,
                shareSearchLoading: false,
                shareSearchOpen: false,
                copiedLink: false,
                _searchDebounce: null,
                searchUsers() {
                    clearTimeout(this._searchDebounce);
                    if (this.shareSearchQuery.trim().length < 2) { this.shareSearchResults = []; this.shareSearchOpen = false; return; }
                    this._searchDebounce = setTimeout(async () => {
                        this.shareSearchLoading = true;
                        try {
                            const r = await fetch('{{ route("users.search") }}?q=' + encodeURIComponent(this.shareSearchQuery.trim()));
                            this.shareSearchResults = await r.json();
                            this.shareSearchOpen = true;
                        } finally { this.shareSearchLoading = false; }
                    }, 250);
                },
                selectUser(u) { this.shareSelectedUser = u; this.shareSearchQuery = u.name; this.shareSearchOpen = false; },
                clearUser() { this.shareSelectedUser = null; this.shareSearchQuery = ''; this.shareSearchOpen = false; },
                async copyLink() {
                    const type = this.shareTarget?.type;
                    const id = this.shareTarget?.id;
                    if (!type || !id) return;
                    const url = '{{ route("share-link.create") }}';
                    const perm = this.$refs.permSelect?.value || 'view';
                    const token = document.querySelector('meta[name=csrf-token]')?.content || '';
                    try {
                        const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: JSON.stringify({ type: type, id: id, permission: perm }) });
                        if (!r.ok) {
                            const err = await r.text();
                            throw new Error(err || 'Request failed');
                        }
                        const d = await r.json();
                        const linkUrl = d.url;
                        if (!linkUrl) throw new Error('No URL returned');
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(linkUrl);
                        } else {
                            const ta = document.createElement('textarea');
                            ta.value = linkUrl;
                            ta.style.position = 'fixed';
                            ta.style.left = '-9999px';
                            document.body.appendChild(ta);
                            ta.select();
                            document.execCommand('copy');
                            document.body.removeChild(ta);
                        }
                        this.copiedLink = true;
                        setTimeout(() => this.copiedLink = false, 2000);
                    } catch (e) {
                        console.error('Copy link error:', e);
                        alert('Could not copy link. Please check the console for details.');
                    }
                }
             }"
             x-init="$watch('shareSearchQuery', () => searchUsers())"
             x-transition>
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Share</h3>
            <form :action="shareTarget?.type === 'file' ? '{{ url('/files') }}/' + shareTarget?.id + '/share' : '{{ url('/folders') }}/' + shareTarget?.id + '/share'" method="POST" @submit="if (!shareSelectedUser) { $event.preventDefault(); }">
                @csrf
                <div class="space-y-4">
                    <div class="relative" @click.outside="shareSearchOpen = false">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Share with</label>
                        <div class="relative">
                            <input type="text"
                                   x-model="shareSearchQuery"
                                   placeholder="Search users by name or email…"
                                   class="input-field w-full pr-8"
                                   autocomplete="off"
                                   @focus="if (shareSearchQuery.length >= 2) shareSearchOpen = true"
                                   @keydown.escape="shareSearchOpen = false">
                            <template x-if="shareSelectedUser">
                                <button type="button" @click="clearUser()" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" title="Clear">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </template>
                            <input type="hidden" name="user_id" :value="shareSelectedUser?.id || ''">
                            <div x-show="shareSearchOpen && (shareSearchResults.length > 0 || shareSearchLoading)" x-cloak
                                 class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg py-1 max-h-48 overflow-y-auto z-10">
                                <template x-if="shareSearchLoading"><div class="px-3 py-2 text-sm text-slate-500">Searching…</div></template>
                                <template x-for="u in shareSearchResults" :key="u.id">
                                    <button type="button" @click="selectUser(u)" class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 flex flex-col">
                                        <span class="font-medium text-slate-800" x-text="u.name"></span>
                                        <span class="text-xs text-slate-500" x-text="u.email"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-slate-500" x-show="shareSelectedUser" x-cloak>
                            <span x-text="shareSelectedUser?.name + ' (' + shareSelectedUser?.email + ')'"></span>
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Permission</label>
                        <select name="permission" x-ref="permSelect" class="input-field w-full">
                            <option value="view">View only</option>
                            <option value="edit">Can edit</option>
                        </select>
                    </div>
                    <p class="text-xs text-slate-500" x-show="shareTarget?.type === 'folder'" x-cloak>Folder contents will be shared. Recipients can open and view files inside.</p>
                    <div class="pt-3 border-t border-slate-200">
                        <p class="text-sm font-medium text-slate-700 mb-2">Anyone with the link</p>
                        <p class="text-xs text-slate-500 mb-2">Anyone who has the link can access (login required).</p>
                        <button type="button" @click="copyLink()"
                                class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <span x-text="copiedLink ? 'Copied!' : 'Copy link'"></span>
                        </button>
                    </div>
                </div>
                <div class="flex gap-2 mt-6">
                    <button type="submit" class="btn-primary" :disabled="!shareSelectedUser">Share</button>
                    <button type="button" @click="shareModalOpen = false; shareTarget = null" class="btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
