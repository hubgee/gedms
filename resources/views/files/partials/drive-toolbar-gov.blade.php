@php $style = $style ?? 'drive'; @endphp
<div class="gov-drive-toolbar flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="flex rounded-lg border border-slate-200 bg-slate-50/50 p-0.5">
            <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-md transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            </button>
            <button @click="viewMode = 'grid'" :class="viewMode === 'grid' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" class="p-2 rounded-md transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            </button>
        </div>
        <form action="{{ route('search.index') }}" method="GET" class="hidden sm:block">
            <input type="hidden" name="scope" value="files">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="search" name="q" placeholder="Search files…" class="pl-9 pr-4 py-2 text-sm rounded-lg border border-slate-200 bg-slate-50/50 w-48 focus:bg-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors">
            </div>
        </form>
    </div>
    <div class="flex items-center gap-2">
        @if($canCreateFolder ?? false)
        <form action="{{ route('folders.store') }}" method="POST" class="flex gap-2">
            @csrf
            <input type="hidden" name="space_id" value="{{ $space->id }}">
            <input type="hidden" name="parent_id" value="{{ $folder?->id }}">
            <input type="text" name="name" placeholder="New folder" required class="rounded-lg border-slate-200 text-sm py-2 px-3 w-32 focus:border-indigo-500 focus:ring-indigo-500">
            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Folder
            </button>
        </form>
        <form action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" class="inline" id="upload-form" data-chunked-upload>
            @csrf
            <input type="hidden" name="space_id" value="{{ $space->id }}">
            <input type="hidden" name="folder_id" value="{{ $folder?->id }}">
            <input type="file" name="file[]" required class="hidden" id="file-input" multiple>
            <button type="button" onclick="document.getElementById('file-input').click()" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-indigo-700 rounded-lg hover:bg-indigo-800 shadow-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                Upload
            </button>
        </form>
        <p class="text-[11px] text-slate-500 mt-1">Large files upload in resumable chunks; the transfer survives a reload.</p>
        @endif
    </div>
</div>
