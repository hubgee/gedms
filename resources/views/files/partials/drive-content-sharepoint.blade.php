{{-- SharePoint: Heavy metadata — many columns --}}
<div class="flex-1 overflow-auto p-4" id="drop-zone"
     @dragover.prevent="dragOver = true" @dragleave="dragOver = false"
     @drop.prevent="handleDrop($event)"
     :class="dragOver ? 'ring-2 ring-slate-400 ring-inset' : ''">
    <form id="drop-upload-form" action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" class="hidden" data-chunked-upload>
        @csrf
        <input type="hidden" name="space_id" value="{{ $space->id }}">
        <input type="hidden" name="folder_id" value="{{ $folder?->id }}">
        <input type="file" name="file[]" id="drop-file-input">
    </form>

    <div x-show="viewMode === 'list'" class="space-y-0">
        <table class="min-w-full text-sm gov-drive-table">
            <thead>
                <tr>
                    <th class="w-10 py-2 px-2 text-left"><input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" @change="toggleAll($event)"></th>
                    <th class="py-2 px-2 text-left font-medium text-slate-600">Name</th>
                    <th class="py-2 px-2 text-left font-medium text-slate-600">Type</th>
                    <th class="py-2 px-2 text-left font-medium text-slate-600">Ministry</th>
                    <th class="py-2 px-2 text-left font-medium text-slate-600">Department</th>
                    <th class="py-2 px-2 text-left font-medium text-slate-600">Sensitivity</th>
                    <th class="py-2 px-2 text-left font-medium text-slate-600">Modified</th>
                    <th class="py-2 px-2 text-left font-medium text-slate-600">Version</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($folders as $f)
                    <tr class="hover:bg-slate-50 border-b border-slate-100">
                        <td class="py-2 px-2"><input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 file-checkbox" value="{{ $f->id }}"></td>
                        <td class="py-2 px-2">
                            <a href="{{ route('files.index', ['space' => $space->id, 'folder' => $f->id]) }}" class="font-medium text-slate-800 hover:text-blue-600 flex items-center gap-2" @click="selectedFile = null">
                                <svg class="w-5 h-5 text-amber-500 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                {{ $f->name }}
                            </a>
                        </td>
                        <td class="py-2 px-2 text-slate-500">Folder</td>
                        <td class="py-2 px-2 text-slate-500">—</td>
                        <td class="py-2 px-2 text-slate-500">—</td>
                        <td class="py-2 px-2 text-slate-500">—</td>
                        <td class="py-2 px-2 text-slate-500">{{ $f->updated_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2 px-2 text-slate-500">—</td>
                        <td class="py-2 px-2">
                            <form action="{{ route('folders.destroy', $f) }}" method="POST" class="inline" onsubmit="return confirm('Delete?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 text-sm hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                @foreach ($files as $file)
                    @php
                        $doc = $file->document;
                        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
                        $iconColor = in_array($ext, ['pdf']) ? 'text-red-500' : (in_array($ext, ['doc','docx']) ? 'text-blue-500' : 'text-slate-400');
                    @endphp
                    <tr class="hover:bg-slate-50 border-b border-slate-100" :class="selectedFile && selectedFile.id === {{ $file->id }} ? 'bg-indigo-50/80' : ''">
                        <td class="py-2 px-2"><input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 file-checkbox" value="{{ $file->id }}"></td>
                        <td class="py-2 px-2">
                            <a href="#" @click.prevent="selectFile({{ json_encode(['id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'version' => $file->version, 'versions' => $file->versions->map(fn($v) => ['version' => $v->version, 'creator' => $v->creator?->name, 'date' => $v->created_at->format('M j, Y')])->values()->toArray(), 'doc_type' => $file->document?->documentType?->name, 'ministry' => $file->document?->ministry?->name, 'department' => $file->document?->department?->name, 'owner' => $file->document?->owner?->name, 'sensitivity' => $file->document?->sensitivityLevel?->name]) }})"
                               class="font-medium text-slate-800 hover:text-blue-600 flex items-center gap-2">
                                <svg class="w-5 h-5 {{ $iconColor }} shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                                {{ $file->name }}
                            </a>
                        </td>
                        <td class="py-2 px-2 text-slate-600">{{ $doc?->documentType?->name ?? $ext ?: '—' }}</td>
                        <td class="py-2 px-2 text-slate-600">{{ $doc?->ministry?->name ?? '—' }}</td>
                        <td class="py-2 px-2 text-slate-600">{{ $doc?->department?->name ?? '—' }}</td>
                        <td class="py-2 px-2 text-slate-600">{{ $doc?->sensitivityLevel?->name ?? '—' }}</td>
                        <td class="py-2 px-2 text-slate-500">{{ $file->updated_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2 px-2 text-slate-500">v{{ $file->version }}</td>
                        <td class="py-2 px-2 space-x-2">
                            <a href="{{ route('files.download', $file) }}" class="text-slate-600 hover:underline text-sm">Download</a>
                            @if (!$file->document)
                                <a href="{{ route('files.promote', $file) }}" class="text-emerald-600 hover:underline text-sm">Promote</a>
                            @endif
                            <form action="{{ route('files.update', $file) }}" method="POST" enctype="multipart/form-data" class="inline" id="ver-{{ $file->id }}">
                                @csrf
                                <input type="hidden" name="action" value="version">
                                <input type="file" name="file" required class="hidden" onchange="this.form.submit()" id="ver-input-{{ $file->id }}">
                                <button type="button" onclick="document.getElementById('ver-input-{{ $file->id }}').click()" class="text-slate-600 hover:underline text-sm">New v</button>
                            </form>
                            <form action="{{ route('files.destroy', $file) }}" method="POST" class="inline" onsubmit="return confirm('Delete?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline text-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($folders->isEmpty() && $files->isEmpty())
            <div class="p-12 text-center text-slate-500">Drop files here or use Upload. Create a folder to start.</div>
        @endif
    </div>

    <div x-show="viewMode === 'grid'" x-cloak class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-4 p-4">
        @foreach ($folders as $i => $f)
            <a href="{{ route('files.index', ['space' => $space->id, 'folder' => $f->id]) }}"
               class="block p-4 rounded-lg border border-slate-200 hover:bg-slate-50 text-center" @click="selectedFile = null">
                <svg class="w-12 h-12 mx-auto text-amber-500 mb-2" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                <span class="text-sm font-medium text-slate-800 truncate block">{{ $f->name }}</span>
            </a>
        @endforeach
        @foreach ($files as $file)
            <a href="#" @click.prevent="selectFile({{ json_encode(['id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'versions' => $file->versions->map(fn($v) => ['version' => $v->version, 'creator' => $v->creator?->name, 'date' => $v->created_at->format('M j, Y')])->values()->toArray()]) }})"
               class="block p-4 rounded-lg border border-slate-200 hover:bg-slate-50 text-center"
               :class="selectedFile && selectedFile.id === {{ $file->id }} ? 'ring-2 ring-blue-500 bg-blue-50' : ''">
                <svg class="w-12 h-12 mx-auto text-slate-400 mb-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                <span class="text-sm font-medium text-slate-800 truncate block">{{ $file->name }}</span>
                <span class="text-xs text-slate-400">v{{ $file->version }}</span>
            </a>
        @endforeach
        @if ($folders->isEmpty() && $files->isEmpty())
            <div class="col-span-full p-12 text-center text-slate-500">No files.</div>
        @endif
    </div>
</div>
