@php $style = $style ?? 'drive'; @endphp
<div class="p-3 border-b border-slate-200 {{ $style === 'sharepoint' ? 'bg-slate-50' : '' }} {{ in_array($style, ['drive','dropbox']) ? 'bg-slate-50' : '' }} flex flex-wrap items-center justify-between gap-2">
    <div class="flex items-center gap-2">
        <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-blue-100 text-blue-600' : 'hover:bg-slate-100 text-slate-600'" class="p-2 rounded transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
        </button>
        <button @click="viewMode = 'grid'" :class="viewMode === 'grid' ? 'bg-blue-100 text-blue-600' : 'hover:bg-slate-100 text-slate-600'" class="p-2 rounded transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
        </button>
        <form action="{{ route('search.index') }}" method="GET" class="hidden sm:block">
            <input type="hidden" name="scope" value="files">
            <div class="relative">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="search" name="q" placeholder="Search" class="pl-8 pr-3 py-1.5 text-sm rounded-lg border border-slate-300 w-40 focus:border-blue-500 focus:ring-blue-500">
            </div>
        </form>
    </div>
    <div class="flex gap-2">
        <form action="{{ route('folders.store') }}" method="POST" class="flex gap-1">
            @csrf
            <input type="hidden" name="space_id" value="{{ $space->id }}">
            <input type="hidden" name="parent_id" value="{{ $folder?->id }}">
            <input type="text" name="name" placeholder="New folder" required class="input-field text-sm py-1.5 w-28">
            <button type="submit" class="btn-secondary text-sm py-1.5">+ Folder</button>
        </form>
        <form action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" class="flex gap-1" id="upload-form" data-chunked-upload>
            @csrf
            <input type="hidden" name="space_id" value="{{ $space->id }}">
            <input type="hidden" name="folder_id" value="{{ $folder?->id }}">
            <input type="file" name="file[]" required class="hidden" id="file-input" multiple>
            <button type="button" onclick="document.getElementById('file-input').click()" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Upload
        </button>
        </form>
        <p class="text-[11px] text-slate-500 mt-1">Large files upload in resumable chunks.</p>
    </div>
</div>
