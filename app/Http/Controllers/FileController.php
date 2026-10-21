<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\FileShare;
use App\Models\Folder;
use App\Models\FolderShare;
use App\Models\User;
use App\Models\StorageSpace;
use App\Models\Tag;
use App\Services\FileService;
use App\Services\SpaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        protected FileService $fileService,
        protected SpaceService $spaceService
    ) {
        $this->middleware('can:view-files')->only([
            'index', 'download', 'preview', 'comments', 'toggleFavorite',
        ]);
        $this->middleware('can:create-files')->only(['store']);
        $this->middleware('can:edit-files')->only([
            'update', 'copy', 'bulkMove', 'bulkCopy', 'bulkPaste',
            'lock', 'unlock', 'restore', 'storeComment', 'syncTags',
        ]);
        $this->middleware('can:delete-files')->only(['destroy', 'bulkDestroy']);
        $this->middleware('can:share-files')->only([
            'share', 'createOrGetShareLink', 'createOrGetShareLinkUnified',
        ]);
    }

    public function index(Request $request): View
    {
        $viewMode = $request->get('view'); // my, department, shared, locked, archived
        $spaceId = $request->get('space');
        $folderId = $request->get('folder');
        $user = Auth::user();
        $spaces = $this->spaceService->spacesForUser($user);

        $space = null;
        $folder = null;
        $folders = collect();
        $files = collect();
        $folderTree = [];
        $sharedByMap = collect();
        $driveStyle = 'drive';
        $isHub = false;
        $hubSections = collect();
        $hubDepartments = collect();
        $canCreateFolder = false;

        $sortBy = in_array($request->get('sort'), ['name', 'size', 'modified']) ? $request->get('sort') : 'name';
        $sortOrder = strtolower($request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($viewMode, ['shared', 'shared-by-me', 'locked', 'archived', 'favorites'])) {
            $space = $spaces->first();
            $driveStyle = $this->resolveDriveStyle($space, $user);

            if ($viewMode === 'shared-by-me') {
                $fileShareIds = FileShare::where('shared_by_user_id', $user->id)->pluck('file_id');
                $sharedFolderIds = FolderShare::where('shared_by_user_id', $user->id)->pluck('folder_id');
                $descendantFolderIds = $this->getDescendantFolderIds($sharedFolderIds);
                $allAccessibleFolderIds = $sharedFolderIds->merge($descendantFolderIds)->unique();

                $folder = $folderId ? Folder::find($folderId) : null;
                if ($folder && ! $allAccessibleFolderIds->contains($folder->id)) {
                    $folder = null;
                }

                $folderOrderCol = $sortBy === 'modified' ? 'updated_at' : 'name';
                $fileOrderCol = match ($sortBy) {
                    'size' => 'size',
                    'modified' => 'updated_at',
                    default => 'name',
                };

                if ($folder) {
                    $folders = Folder::whereIn('id', $allAccessibleFolderIds)
                        ->where('parent_id', $folder->id)
                        ->orderBy($folderOrderCol, $sortOrder)
                        ->get();
                    $files = File::where('folder_id', $folder->id)
                        ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'storageSpace', 'folder', 'tags'])
                        ->orderBy($fileOrderCol, $sortOrder)
                        ->get()
                        ->values();
                    $folderTree = [];
                } else {
                    $sharedFolders = Folder::whereIn('id', $sharedFolderIds)
                        ->orderBy($folderOrderCol, $sortOrder)
                        ->get();
                    $folders = $sharedFolders;
                    $files = File::whereIn('id', $fileShareIds)
                        ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'storageSpace', 'folder', 'tags'])
                        ->orderBy($fileOrderCol, $sortOrder)
                        ->get()
                        ->values();
                    $folderTree = [];
                }

                $fileIds = $files->pluck('id');
                $sharedByMap = $this->buildSharedByMap($fileIds, $user->id, true);
            } elseif ($viewMode === 'favorites') {
                $sharedByMap = collect();
                $fileIds = $user->fileFavorites()->pluck('file_id');
                $files = File::whereIn('id', $fileIds)
                    ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'storageSpace', 'folder', 'tags'])
                    ->get()
                    ->sortBy($sortBy === 'modified' ? 'updated_at' : ($sortBy === 'size' ? 'size' : 'name'), SORT_REGULAR, $sortOrder === 'desc')
                    ->values();
            } elseif ($viewMode === 'shared') {
                $fileShareIds = FileShare::where('shared_with_user_id', $user->id)->pluck('file_id');
                $sharedFolderIds = FolderShare::where('shared_with_user_id', $user->id)->pluck('folder_id');
                $descendantFolderIds = $this->getDescendantFolderIds($sharedFolderIds);
                $allAccessibleFolderIds = $sharedFolderIds->merge($descendantFolderIds)->unique();

                $folder = $folderId ? Folder::find($folderId) : null;
                if ($folder && ! $allAccessibleFolderIds->contains($folder->id)) {
                    $folder = null;
                }

                $folderOrderCol = $sortBy === 'modified' ? 'updated_at' : 'name';
                $fileOrderCol = match ($sortBy) {
                    'size' => 'size',
                    'modified' => 'updated_at',
                    default => 'name',
                };

                if ($folder) {
                    $folders = Folder::whereIn('id', $allAccessibleFolderIds)
                        ->where('parent_id', $folder->id)
                        ->orderBy($folderOrderCol, $sortOrder)
                        ->get();
                    $files = File::where('folder_id', $folder->id)
                        ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'storageSpace', 'folder', 'tags'])
                        ->orderBy($fileOrderCol, $sortOrder)
                        ->get()
                        ->values();
                    $folderTree = [];
                } else {
                    $sharedFolders = Folder::whereIn('id', $sharedFolderIds)
                        ->orderBy($folderOrderCol, $sortOrder)
                        ->get();
                    $folders = $sharedFolders;
                    $files = File::whereIn('id', $fileShareIds)
                        ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'storageSpace', 'folder', 'tags'])
                        ->orderBy($fileOrderCol, $sortOrder)
                        ->get()
                        ->values();
                    $folderTree = [];
                }

                $fileIds = $files->pluck('id');
                $sharedByMap = $this->buildSharedByMap($fileIds, $user->id, false);
            } elseif ($viewMode === 'locked') {
                $sharedByMap = collect();
                $files = File::where('locked_by', $user->id)
                    ->whereIn('storage_space_id', $spaces->pluck('id'))
                    ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'tags'])
                    ->get()
                    ->sortBy($sortBy === 'modified' ? 'updated_at' : ($sortBy === 'size' ? 'size' : 'name'), SORT_REGULAR, $sortOrder === 'desc')
                    ->values();
            } else {
                $sharedByMap = collect();
                $files = File::onlyTrashed()
                    ->whereIn('storage_space_id', $spaces->pluck('id'))
                    ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'tags'])
                    ->get()
                    ->sortBy($sortBy === 'modified' ? 'deleted_at' : ($sortBy === 'size' ? 'size' : 'name'), SORT_REGULAR, $sortOrder === 'desc')
                    ->values();
            }
        } else {
            if ($viewMode === 'department' && $user->department_id) {
                $deptSpace = $spaces->firstWhere('owner_department_id', $user->department_id);
                if ($deptSpace && ! $spaceId) {
                    $spaceId = $deptSpace->id;
                }
            }

            $space = $spaceId
                ? $spaces->firstWhere('id', $spaceId)
                : $spaces->first();
            $space || abort(404);

            if ($space->type === \App\Models\StorageSpace::TYPE_SECTION) {
                $this->fileService->ensureMandatoryFolders($space, $user);
            }

            $isHub = in_array($space->type, [\App\Models\StorageSpace::TYPE_DEPARTMENT, \App\Models\StorageSpace::TYPE_MINISTRY]);
            $hubSections = collect();
            $hubDepartments = collect();
            if ($isHub) {
                if ($space->type === \App\Models\StorageSpace::TYPE_DEPARTMENT) {
                    $dept = $space->ownerDepartment;
                    $allSections = $dept ? $dept->sections()->with('storageSpace')->orderBy('name')->get() : collect();
                    $hubSections = $allSections->filter(fn ($s) => $s->storageSpace && $this->spaceService->userCanAccess($s->storageSpace, $user));
                } else {
                    $ministry = $space->ownerMinistry;
                    $allDepts = $ministry ? $ministry->departments()->with(['sections.storageSpace'])->orderBy('name')->get() : collect();
                    $hubDepartments = $allDepts->map(function ($dept) use ($user) {
                        $dept->setRelation('sections', $dept->sections->filter(fn ($s) => $s->storageSpace && $this->spaceService->userCanAccess($s->storageSpace, $user)));
                        return $dept;
                    });
                }
            }

            $folder = $isHub ? null : ($folderId ? Folder::find($folderId) : null);
            $folder && $folder->storage_space_id !== $space->id && abort(403);
            $folder && ! $this->fileService->userCanAccessFolder($folder, $user) && abort(403);

            if ($isHub) {
                $folders = collect();
                $files = collect();
                $folderTree = [];
            } else {
                $folderOrderCol = $sortBy === 'modified' ? 'updated_at' : 'name';
                $allFolders = $space->allFolders()
                    ->where('parent_id', $folder?->id)
                    ->orderBy($folderOrderCol, $sortOrder)
                    ->get();
                $folders = $allFolders->filter(fn ($f) => $this->fileService->userCanAccessFolder($f, $user))->values();

                $fileOrderCol = match ($sortBy) {
                    'size' => 'size',
                    'modified' => 'updated_at',
                    default => 'name',
                };
                $allFiles = $space->files()
                    ->where('folder_id', $folder?->id)
                    ->with(['creator', 'versions.creator', 'document.documentType', 'document.ministry', 'document.department', 'document.sensitivityLevel', 'document.owner', 'tags'])
                    ->orderBy($fileOrderCol, $sortOrder)
                    ->get();
                $files = $allFiles->filter(fn ($file) => $this->fileService->userCanAccessFile($file, $user))->values();

                $folderTree = $this->buildFolderTree($space->allFolders()->whereNull('parent_id')->orderBy('name')->get());
            }
            $driveStyle = $this->resolveDriveStyle($space, $user);
        }

        $breadcrumbs = $this->buildBreadcrumbs($space, $folder, $viewMode ?? null);

        $canCreateFolder = $space && ! $isHub && ! in_array($viewMode ?? null, ['shared', 'shared-by-me', 'locked', 'archived', 'favorites'])
            ? $this->fileService->canCreateInLocation($space, $folder, $user)
            : false;

        $favoritedIds = $user->fileFavorites()->pluck('file_id')->toArray();
        $flatFolderOptions = ($space && !($isHub ?? false)) ? $this->buildFlatFolderOptions($this->buildFolderTree($space->allFolders()->whereNull('parent_id')->orderBy('name')->get())) : [];

        $initialFileData = null;
        $fileId = $request->get('file');
        if ($fileId && $files) {
            $target = $files->firstWhere('id', (int) $fileId);
            if ($target) {
                $initialFileData = [
                    'id' => $target->id,
                    'name' => $target->name,
                    'mime_type' => $target->mime_type,
                    'version' => $target->version,
                    'versions' => $target->versions->map(fn ($v) => ['version' => $v->version, 'creator' => $v->creator?->name, 'date' => $v->created_at->format('M j, Y')])->values()->toArray(),
                    'doc_type' => $target->document?->documentType?->name,
                    'ministry' => $target->document?->ministry?->name,
                    'department' => $target->document?->department?->name,
                    'owner' => $target->document?->owner?->name,
                    'sensitivity' => $target->document?->sensitivityLevel?->name,
                    'tags' => $target->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->toArray(),
                    'locked_by' => $target->locked_by,
                    'isLockedByMe' => $target->locked_by && $target->locked_by === $user->id,
                ];
            }
        }

        return view('files.index', [
            'spaces' => $spaces,
            'space' => $space,
            'folder' => $folder,
            'folders' => $folders,
            'files' => $files,
            'folderTree' => $folderTree,
            'driveStyle' => $driveStyle,
            'viewMode' => $viewMode,
            'breadcrumbs' => $breadcrumbs,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'isHub' => $isHub,
            'hubSections' => $hubSections,
            'hubDepartments' => $hubDepartments,
            'tags' => $space ? \App\Models\Tag::where('storage_space_id', $space->id)->get() : collect(),
            'favoritedIds' => $favoritedIds,
            'flatFolderOptions' => $flatFolderOptions,
            'canCreateFolder' => $canCreateFolder ?? false,
            'sharedByMap' => $sharedByMap,
            'initialFileData' => $initialFileData,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'space_id' => 'required|exists:storage_spaces,id',
            'folder_id' => 'nullable|exists:folders,id',
            'file' => 'required|array|min:1',
            'file.*' => 'required|file|max:51200',
        ]);

        $space = StorageSpace::findOrFail($request->space_id);
        $folder = $request->folder_id ? Folder::findOrFail($request->folder_id) : null;

        $uploaded = $request->file('file');

        foreach ($uploaded as $file) {
            if ($file && $file->isValid()) {
                $this->fileService->upload($file, $space, $folder, Auth::user());
            }
        }

        $count = count(array_filter($uploaded, fn ($f) => $f && $f->isValid()));
        return back()->with('success', $count === 1 ? 'File uploaded.' : "{$count} files uploaded.");
    }

    public function download(File $file): StreamedResponse
    {
        return $this->fileService->download($file, Auth::user());
    }

    public function preview(Request $request, File $file): StreamedResponse
    {
        return $this->fileService->preview($file, Auth::user(), $request);
    }

    public function update(Request $request, File $file): RedirectResponse
    {
        $request->validate([
            'action' => 'required|in:rename,version,move',
            'name' => 'required_if:action,rename|nullable|string|max:255',
            'file' => 'required_if:action,version|nullable|file|max:51200',
            'folder_id' => 'required_if:action,move|nullable|exists:folders,id',
        ]);

        match ($request->action) {
            'rename' => $this->fileService->rename($file, $request->name ?? $file->name, Auth::user()),
            'version' => $this->fileService->updateVersion($file, $request->file('file'), Auth::user()),
            'move' => $this->fileService->move($file, $request->folder_id ? Folder::find($request->folder_id) : null, Auth::user()),
        };

        return back()->with('success', 'File updated.');
    }

    public function destroy(File $file): RedirectResponse
    {
        $this->fileService->delete($file, Auth::user());
        return back()->with('success', 'File deleted.');
    }

    public function restore(File $file): RedirectResponse
    {
        $this->fileService->restore($file, Auth::user());
        return back()->with('success', 'File restored.');
    }

    private function buildSharedByMap(\Illuminate\Support\Collection $fileIds, int $userId, bool $sharedByMe): \Illuminate\Support\Collection
    {
        if ($fileIds->isEmpty()) {
            return collect();
        }
        $map = collect();
        $files = File::whereIn('id', $fileIds)->with('folder')->get()->keyBy('id');

        if ($sharedByMe) {
            $sharer = User::find($userId);
            foreach ($fileIds as $fid) {
                $map[$fid] = $sharer;
            }
            return $map;
        }

        $directShares = FileShare::whereIn('file_id', $fileIds)->where('shared_with_user_id', $userId)->with('sharedBy')->get();
        foreach ($directShares as $fs) {
            $map[$fs->file_id] = $fs->sharedBy;
        }

        $folderShareUserIds = FolderShare::where('shared_with_user_id', $userId)->with('sharedBy')->get()->keyBy('folder_id');
        foreach ($files as $file) {
            if (isset($map[$file->id])) {
                continue;
            }
            $f = $file->folder;
            while ($f) {
                $fs = $folderShareUserIds->get($f->id);
                if ($fs && $fs->sharedBy) {
                    $map[$file->id] = $fs->sharedBy;
                    break;
                }
                $f = $f->parent;
            }
        }

        return $map;
    }

    private function getDescendantFolderIds($folderIds): \Illuminate\Support\Collection
    {
        $ids = collect($folderIds);
        $descendants = collect();
        $current = $ids;
        while ($current->isNotEmpty()) {
            $children = Folder::whereIn('parent_id', $current)->pluck('id');
            $descendants = $descendants->merge($children);
            $current = $children;
        }
        return $descendants->unique();
    }

    private function buildBreadcrumbs($space, $folder, ?string $viewMode): array
    {
        if (in_array($viewMode, ['shared', 'shared-by-me']) && $folder) {
            $label = $viewMode === 'shared' ? 'Shared with you' : 'Shared by me';
            $items = [['name' => $label, 'url' => route('files.index', ['view' => $viewMode])]];
            $current = $folder;
            $chain = [];
            while ($current) {
                array_unshift($chain, ['name' => $current->name, 'url' => route('files.index', ['view' => $viewMode, 'folder' => $current->id])]);
                $current = $current->parent;
            }
            $last = array_pop($chain);
            if ($last) {
                $chain[] = ['name' => $last['name'], 'url' => null];
            }
            return array_merge($items, $chain);
        }
        if (in_array($viewMode, ['shared', 'shared-by-me', 'locked', 'archived', 'favorites']) || ! $space) {
            $label = match ($viewMode) {
                'shared' => 'Shared with you',
                'shared-by-me' => 'Shared by me',
                'locked' => 'Locked Files',
                'archived' => 'Deleted files',
                'favorites' => 'Favorites',
                default => 'Files',
            };
            return [['name' => $label, 'url' => null]];
        }
        $items = [['name' => $space->name, 'url' => route('files.index', ['space' => $space->id])]];
        $current = $folder;
        $chain = [];
        while ($current) {
            array_unshift($chain, ['name' => $current->name, 'url' => route('files.index', ['space' => $space->id, 'folder' => $current->id])]);
            $current = $current->parent;
        }
        return array_merge($items, $chain);
    }

    private function resolveDriveStyle(StorageSpace $space, $user): string
    {
        $allowed = ['drive', 'sharepoint', 'dropbox', 'nextcloud'];
        $legacy = ['default' => 'drive', 'classic' => 'sharepoint', 'compact' => 'drive', 'card' => 'dropbox', 'nc' => 'nextcloud'];
        $style = null;

        if ($space->owner_department_id) {
            $dept = \App\Models\Department::find($space->owner_department_id);
            $style = $dept?->drive_style;
        }
        if (! $style && $user->department_id) {
            $dept = \App\Models\Department::find($user->department_id);
            $style = $dept?->drive_style;
        }

        $style = $style ?? 'nextcloud';
        return in_array($style, $allowed) ? $style : ($legacy[$style] ?? 'nextcloud');
    }

    private function buildFolderTree($folders): array
    {
        return $folders->map(function (Folder $f) {
            return [
                'id' => $f->id,
                'name' => $f->name,
                'children' => $this->buildFolderTree($f->children()->orderBy('name')->get()),
            ];
        })->toArray();
    }

    private function buildFlatFolderOptions(array $tree, int $depth = 0): array
    {
        $result = [];
        foreach ($tree as $node) {
            $result[] = ['id' => $node['id'], 'name' => str_repeat('— ', $depth) . $node['name']];
            $result = array_merge($result, $this->buildFlatFolderOptions($node['children'] ?? [], $depth + 1));
        }
        return $result;
    }

    public function share(Request $request, File $file): RedirectResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'permission' => 'required|in:view,edit',
        ]);
        $shareWith = \App\Models\User::findOrFail($request->user_id);
        $this->fileService->share($file, $shareWith, $request->permission, Auth::user());
        return back()->with('success', 'File shared.');
    }

    public function createOrGetShareLink(Request $request, File $file): JsonResponse
    {
        $request->validate(['permission' => 'nullable|in:view,edit']);
        $permission = $request->get('permission', 'view');
        $link = $this->fileService->createOrGetShareLink($file, $permission, Auth::user());
        $url = route('shared.access', ['token' => $link->token], true);
        return response()->json(['url' => $url, 'token' => $link->token]);
    }

    public function createOrGetShareLinkUnified(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:file,folder',
            'id' => 'required|integer|min:1',
            'permission' => 'nullable|in:view,edit',
        ]);
        $permission = $request->get('permission', 'view');
        $user = Auth::user();

        if ($request->type === 'file') {
            $file = File::findOrFail($request->id);
            $link = $this->fileService->createOrGetShareLink($file, $permission, $user);
        } else {
            $folder = \App\Models\Folder::findOrFail($request->id);
            $link = $this->fileService->createOrGetFolderShareLink($folder, $permission, $user);
        }

        $url = route('shared.access', ['token' => $link->token], true);
        return response()->json(['url' => $url, 'token' => $link->token]);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $request->validate(['items' => 'required|array', 'items.*.id' => 'integer', 'items.*.type' => 'required|in:file,folder']);
        try {
            $count = $this->fileService->bulkDelete($request->items, Auth::user());
            return back()->with('success', "{$count} item(s) deleted.");
        } catch (\AuthorizationException $e) {
            return back()->with('error', 'You are not authorized to delete these items.');
        } catch (\Throwable $e) {
            return back()->with('error', 'An error occurred while deleting items: ' . $e->getMessage());
        }
    }

    public function bulkMove(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'folder_id' => 'nullable|exists:folders,id',
        ]);
        $count = $this->fileService->bulkMove($request->ids, $request->folder_id, Auth::user());
        return back()->with('success', "{$count} item(s) moved.");
    }

    public function bulkCopy(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'folder_id' => 'nullable|exists:folders,id',
            'space_id' => 'required|exists:storage_spaces,id',
        ]);
        $space = StorageSpace::findOrFail($request->space_id);
        $folder = $request->folder_id ? Folder::findOrFail($request->folder_id) : null;
        $count = 0;
        foreach ($request->ids as $id) {
            $file = File::find($id);
            if ($file) {
                try {
                    $this->fileService->copy($file, $folder, $space, Auth::user());
                    $count++;
                } catch (\Throwable) {
                    // skip files that can't be copied
                }
            }
        }
        return back()->with('success', "{$count} item(s) copied.");
    }

    public function bulkPaste(Request $request): RedirectResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.type' => 'required|in:file,folder',
            'items.*.id' => 'required|integer',
            'operation' => 'required|in:copy,cut',
            'folder_id' => 'nullable|exists:folders,id',
            'space_id' => 'required|exists:storage_spaces,id',
        ]);
        $space = StorageSpace::findOrFail($request->space_id);
        $folder = $request->folder_id ? Folder::findOrFail($request->folder_id) : null;
        $user = Auth::user();
        $count = 0;
        $fileIds = [];
        $folderIds = [];
        foreach ($request->items as $item) {
            if ($item['type'] === 'file') {
                $fileIds[] = (int) $item['id'];
            } else {
                $folderIds[] = (int) $item['id'];
            }
        }
        if ($request->operation === 'copy') {
            foreach ($fileIds as $id) {
                $file = File::find($id);
                if ($file) {
                    try {
                        $this->fileService->copy($file, $folder, $space, $user);
                        $count++;
                    } catch (\Throwable) {
                        // skip
                    }
                }
            }
            return back()->with('success', "{$count} file(s) copied.");
        }
        if ($request->operation === 'cut') {
            $count += $this->fileService->bulkMove($fileIds, $folder?->id, $user);
            $targetParent = $folder;
            foreach ($folderIds as $id) {
                $f = Folder::find($id);
                if ($f) {
                    try {
                        $this->fileService->moveFolder($f, $targetParent, $user);
                        $count++;
                    } catch (\Throwable) {
                        // skip
                    }
                }
            }
            return back()->with('success', "{$count} item(s) moved.");
        }
        return back()->with('error', 'Invalid operation.');
    }

    public function copy(Request $request, File $file): RedirectResponse
    {
        $request->validate([
            'folder_id' => 'nullable|exists:folders,id',
            'space_id' => 'required|exists:storage_spaces,id',
        ]);
        $space = StorageSpace::findOrFail($request->space_id);
        $folder = $request->folder_id ? Folder::findOrFail($request->folder_id) : null;
        $this->fileService->copy($file, $folder, $space, Auth::user());
        return back()->with('success', 'File copied.');
    }

    public function lock(File $file): RedirectResponse
    {
        $this->fileService->lockFile($file, Auth::user());
        return back()->with('success', 'File locked.');
    }

    public function unlock(File $file): RedirectResponse
    {
        $this->fileService->unlockFile($file, Auth::user());
        return back()->with('success', 'File unlocked.');
    }

    public function toggleFavorite(File $file): RedirectResponse
    {
        $fav = $this->fileService->toggleFavorite($file, Auth::user());
        return back()->with('success', $fav ? 'Added to favorites.' : 'Removed from favorites.');
    }

    public function comments(File $file): JsonResponse
    {
        $this->fileService->authorizeView($file, Auth::user());
        $comments = $file->comments()->with('user')->get();
        return response()->json($comments->map(fn ($c) => [
            'id' => $c->id,
            'body' => $c->body,
            'user_name' => $c->user->name,
            'created_at' => $c->created_at->format('M j, Y g:i A'),
        ]));
    }

    public function storeComment(Request $request, File $file): JsonResponse
    {
        $request->validate(['body' => 'required|string|max:2000']);
        $comment = $this->fileService->addComment($file, $request->body, Auth::user());
        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'user_name' => $comment->user->name,
            'created_at' => $comment->created_at->format('M j, Y g:i A'),
        ]);
    }

    public function syncTags(Request $request, File $file): JsonResponse
    {
        $request->validate(['tag_ids' => 'array', 'tag_ids.*' => 'integer|exists:tags,id', 'tag_names' => 'array', 'tag_names.*' => 'string|max:50']);
        $tagIds = $request->tag_ids ?? [];
        foreach ($request->tag_names ?? [] as $name) {
            $name = trim($name);
            if ($name) {
                $tag = $this->fileService->findOrCreateTag($file->storageSpace, $name, null, Auth::user());
                $tagIds[] = $tag->id;
            }
        }
        $this->fileService->syncTags($file, array_unique($tagIds), Auth::user());
        return response()->json(['tags' => $file->fresh()->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])]);
    }
}
