<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DocumentTypeController;
use App\Http\Controllers\Admin\RetentionRuleController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\SensitivityLevelController;
use App\Http\Controllers\Admin\WorkflowDefinitionController;
use App\Http\Controllers\ApprovalDashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\MemoController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/shared/{token}', [\App\Http\Controllers\SharedController::class, 'access'])->name('shared.access')->middleware(['web']);

Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/search', [SearchController::class, 'index'])->name('search.index');
    Route::get('/users/search', [\App\Http\Controllers\UserSearchController::class, 'index'])->name('users.search');
    Route::get('/approvals', [ApprovalDashboardController::class, 'index'])->name('approvals.index')->middleware('can:approve-documents');
    Route::get('/manual', fn () => view('manual'))->name('manual')->middleware('can:view manual');

    Route::resource('memos', MemoController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/memos/{memo}/send', [MemoController::class, 'send'])->name('memos.send');
    Route::post('/memos/{memo}/acknowledge', [MemoController::class, 'acknowledge'])->name('memos.acknowledge');

    Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('index');
        Route::get('/department', [AdminDashboardController::class, 'department'])->name('department.index')->middleware('can:manage-department');
        Route::get('/department/settings', [AdminDashboardController::class, 'departmentSettings'])->name('department.settings')->middleware('can:manage-department');
        Route::put('/department/drive-style', [AdminDashboardController::class, 'updateDriveStyle'])->name('department.drive-style')->middleware('can:manage-department');
        Route::put('/department/mandatory-folders', [AdminDashboardController::class, 'updateMandatoryFolders'])->name('department.mandatory-folders')->middleware('can:manage-department');
        Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [\App\Http\Controllers\Admin\UserController::class, 'create'])->name('users.create');
        Route::post('/users', [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [\App\Http\Controllers\Admin\UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->name('users.update');
        Route::get('/ministries', [\App\Http\Controllers\Admin\MinistryController::class, 'index'])->name('ministries.index');
        Route::get('/ministries/create', [\App\Http\Controllers\Admin\MinistryController::class, 'create'])->name('ministries.create')->middleware('can:manage-ministry');
        Route::post('/ministries', [\App\Http\Controllers\Admin\MinistryController::class, 'store'])->name('ministries.store')->middleware('can:manage-ministry');
        Route::get('/departments', [\App\Http\Controllers\Admin\DepartmentController::class, 'index'])->name('departments.index');
        Route::get('/departments/create', [\App\Http\Controllers\Admin\DepartmentController::class, 'create'])->name('departments.create');
        Route::post('/departments', [\App\Http\Controllers\Admin\DepartmentController::class, 'store'])->name('departments.store');
        Route::get('/units', [\App\Http\Controllers\Admin\UnitController::class, 'index'])->name('units.index');
        Route::get('/sections', [\App\Http\Controllers\Admin\SectionController::class, 'index'])->name('sections.index');
        Route::get('/sections/create', [\App\Http\Controllers\Admin\SectionController::class, 'create'])->name('sections.create');
        Route::post('/sections', [\App\Http\Controllers\Admin\SectionController::class, 'store'])->name('sections.store');
        Route::resource('document-types', DocumentTypeController::class)->except(['show']);
        Route::resource('sensitivity-levels', SensitivityLevelController::class)->except(['show']);
        Route::resource('workflows', WorkflowDefinitionController::class)->except(['show']);
        Route::resource('retention-rules', RetentionRuleController::class)->except(['show']);
        Route::get('roles', [RolePermissionController::class, 'index'])->name('roles.index');
        Route::put('roles', [RolePermissionController::class, 'update'])->name('roles.update');
        Route::post('roles', [RolePermissionController::class, 'storeRole'])->name('roles.store');
        Route::put('roles/{role}', [RolePermissionController::class, 'updateRole'])->name('roles.update-role');
        Route::delete('roles/{role}', [RolePermissionController::class, 'destroyRole'])->name('roles.destroy');
        Route::post('permissions', [RolePermissionController::class, 'storePermission'])->name('permissions.store');
        Route::delete('permissions/{permission}', [RolePermissionController::class, 'destroyPermission'])->name('permissions.destroy');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('reports', fn () => view('admin.reports'))->name('reports.index');
    });

    Route::prefix('files')->name('files.')->group(function () {
        Route::get('/', [FileController::class, 'index'])->name('index');
        Route::post('/', [FileController::class, 'store'])->name('store');
        Route::post('/bulk-destroy', [FileController::class, 'bulkDestroy'])->name('bulk-destroy');
        Route::post('/bulk-move', [FileController::class, 'bulkMove'])->name('bulk-move');
        Route::post('/bulk-copy', [FileController::class, 'bulkCopy'])->name('bulk-copy');
        Route::post('/bulk-paste', [FileController::class, 'bulkPaste'])->name('bulk-paste');
        Route::post('/{file}/copy', [FileController::class, 'copy'])->name('copy');
        Route::get('/{file}/promote', [DocumentController::class, 'promoteForm'])->name('promote');
        Route::get('/{file}/download', [FileController::class, 'download'])->name('download')->withTrashed();
        Route::get('/{file}/preview', [FileController::class, 'preview'])->name('preview')->withTrashed();
        Route::post('/{file}', [FileController::class, 'update'])->name('update');
        Route::delete('/{file}', [FileController::class, 'destroy'])->name('destroy')->withTrashed();
        Route::post('/{file}/restore', [FileController::class, 'restore'])->name('restore')->withTrashed();
        Route::post('/{file}/lock', [FileController::class, 'lock'])->name('lock');
        Route::post('/{file}/unlock', [FileController::class, 'unlock'])->name('unlock');
        Route::post('/{file}/share', [FileController::class, 'share'])->name('share');
        Route::post('/{file}/share-link', [FileController::class, 'createOrGetShareLink'])->name('share-link');
        Route::post('/{file}/favorite', [FileController::class, 'toggleFavorite'])->name('favorite')->withTrashed();
        Route::get('/{file}/comments', [FileController::class, 'comments'])->name('comments.index')->withTrashed();
        Route::post('/{file}/comments', [FileController::class, 'storeComment'])->name('comments.store');
        Route::post('/{file}/tags', [FileController::class, 'syncTags'])->name('tags.sync');
    });

    Route::prefix('uploads')->name('uploads.')->group(function () {
        Route::post('/', [UploadController::class, 'store'])->name('store');
        Route::get('/{uuid}', [UploadController::class, 'show'])->whereUuid('uuid')->name('show');
        Route::put('/{uuid}/chunks/{index}', [UploadController::class, 'chunk'])
            ->whereUuid('uuid')
            ->whereNumber('index')
            ->name('chunks.store');
        Route::delete('/{uuid}', [UploadController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
    });

    Route::prefix('documents')->name('documents.')->group(function () {
        Route::get('/', [DocumentController::class, 'index'])->name('index');
        Route::post('/promote/{file}', [DocumentController::class, 'promote'])->name('promote');
        Route::get('/{document}', [DocumentController::class, 'show'])->name('show');
        Route::get('/{document}/download', [DocumentController::class, 'download'])->name('download');
        Route::post('/{document}/submit', [DocumentController::class, 'submitForReview'])->name('submit');
        Route::post('/{document}/promote-to-approved', [DocumentController::class, 'promoteToApproved'])->name('promote-to-approved');
        Route::post('/{document}/approve', [DocumentController::class, 'approve'])->name('approve');
        Route::post('/{document}/reject', [DocumentController::class, 'reject'])->name('reject');
        Route::post('/{document}/archive', [DocumentController::class, 'archive'])->name('archive');
        Route::post('/{document}/checkout', [DocumentController::class, 'checkOut'])->name('checkout');
        Route::post('/{document}/checkin', [DocumentController::class, 'checkIn'])->name('checkin');
        Route::post('/{document}/cancel-checkout', [DocumentController::class, 'cancelCheckOut'])->name('cancel-checkout');
        Route::post('/{document}/workflow/{workflowStepInstance}/approve', [DocumentController::class, 'approveWorkflowStep'])->name('workflow.approve');
        Route::post('/{document}/workflow/{workflowStepInstance}/reject', [DocumentController::class, 'rejectWorkflowStep'])->name('workflow.reject');
        Route::post('/{document}/legal-hold', [DocumentController::class, 'toggleLegalHold'])->name('legal-hold');
    });

    Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::post('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
    Route::post('/folders/{folder}/lock', [FolderController::class, 'lock'])->name('folders.lock');
    Route::post('/folders/{folder}/unlock', [FolderController::class, 'unlock'])->name('folders.unlock');
    Route::post('/folders/{folder}/share', [FolderController::class, 'share'])->name('folders.share');
    Route::post('/folders/{folder}/share-link', [FolderController::class, 'createOrGetShareLink'])->name('folders.share-link');
    Route::post('/share-link', [FileController::class, 'createOrGetShareLinkUnified'])->name('share-link.create');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');
});

require __DIR__.'/auth.php';
