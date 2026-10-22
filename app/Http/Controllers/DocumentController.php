<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\SensitivityLevel;
use App\Models\WorkflowStepInstance;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Services\WorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $documentService,
        protected WorkflowService $workflowService
    ) {}

    public function index(Request $request): View
    {
        $user = Auth::user();
        $user->ministry_id || abort(403, 'You must belong to a ministry to view documents');

        $query = Document::with(['file', 'documentType', 'ministry', 'department', 'owner', 'sensitivityLevel'])
            ->where('ministry_id', $user->ministry_id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('document_type_id')) {
            $query->where('document_type_id', $request->document_type_id);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('sensitivity_level_id')) {
            $query->where('sensitivity_level_id', $request->sensitivity_level_id);
        }

        $documents = $query->latest()->get();
        $documentTypes = DocumentType::where('is_active', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('ministry_id')->orWhere('ministry_id', $user->ministry_id);
            })
            ->get();
        $departments = $user->ministry?->departments ?? collect();
        $sensitivityLevels = \App\Models\SensitivityLevel::orderBy('sort_order')->get();

        return view('documents.index', [
            'documents' => $documents,
            'documentTypes' => $documentTypes,
            'departments' => $departments,
            'sensitivityLevels' => $sensitivityLevels,
        ]);
    }

    public function show(Document $document): View
    {
        $this->authorize('view', $document);
        $document->load(['file', 'documentType', 'ministry', 'department', 'owner', 'sensitivityLevel', 'versions.creator', 'versions.approver', 'workflowInstances.stepInstances.workflowStep']);

        $activeWorkflow = $document->workflowInstances->firstWhere('status', 'in_progress');
        $currentSteps = $activeWorkflow ? $this->workflowService->getCurrentSteps($activeWorkflow) : collect();

        $docWorkflowIds = $document->workflowInstances->pluck('id');
        $docStepIds = \App\Models\WorkflowStepInstance::whereIn('workflow_instance_id', $docWorkflowIds)->pluck('id');

        $auditTrail = AuditLog::where(function ($q) use ($document, $docWorkflowIds, $docStepIds) {
            $q->where(function ($q2) use ($document) {
                $q2->where('subject_type', Document::class)->where('subject_id', $document->id);
            })
                ->orWhere(function ($q2) use ($docWorkflowIds) {
                    $q2->where('subject_type', \App\Models\WorkflowInstance::class)->whereIn('subject_id', $docWorkflowIds);
                })
                ->orWhere(function ($q2) use ($docStepIds) {
                    $q2->where('subject_type', \App\Models\WorkflowStepInstance::class)->whereIn('subject_id', $docStepIds);
                });
        })
            ->with('user')
            ->latest('created_at')
            ->limit(50)
            ->get();

        return view('documents.show', [
            'document' => $document,
            'activeWorkflow' => $activeWorkflow,
            'currentSteps' => $currentSteps,
            'auditTrail' => $auditTrail,
        ]);
    }

    public function promoteForm(File $file): View
    {
        $user = Auth::user();
        $ministryId = $user->ministry_id ?? $user->department?->ministry_id;
        $ministryId || abort(403, 'You must belong to a ministry to promote files');
        $file->document && abort(400, 'This file has already been promoted');
        $file->storageSpace && app(\App\Services\SpaceService::class)->userCanAccess($file->storageSpace, $user) || abort(403);

        $documentTypes = DocumentType::where('is_active', true)
            ->where(function ($q) use ($ministryId) {
                $q->whereNull('ministry_id')->orWhere('ministry_id', $ministryId);
            })
            ->orderBy('name')
            ->get();
        $sensitivityLevels = SensitivityLevel::orderBy('sort_order')->get();

        return view('documents.promote', [
            'file' => $file,
            'documentTypes' => $documentTypes,
            'sensitivityLevels' => $sensitivityLevels,
        ]);
    }

    public function promote(Request $request, File $file): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'document_type_id' => 'required|exists:document_types,id',
            'ministry_id' => 'required|exists:ministries,id',
            'department_id' => 'required|exists:departments,id',
            'owner_id' => 'required|exists:users,id',
            'sensitivity_level_id' => 'required|exists:sensitivity_levels,id',
            'requires_workflow' => 'boolean',
        ]);

        $data = $request->all();
        $data['requires_workflow'] = $request->boolean('requires_workflow');
        $document = $this->documentService->promote($file, $data, Auth::user());

        return redirect()->route('documents.show', $document)
            ->with('success', 'File promoted to official document.');
    }

    public function submitForReview(Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $this->documentService->submitForReview($document, Auth::user());

        return back()->with('success', 'Document submitted for review.');
    }

    public function promoteToApproved(Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        if ($document->status !== Document::STATUS_DRAFT) {
            return back()->with('error', 'Only draft documents can be promoted to approved.');
        }
        if (($document->requires_workflow ?? true)) {
            return back()->with('error', 'This document requires workflow approval. Use Submit for Review instead.');
        }
        $this->documentService->promoteToApproved($document, Auth::user());

        return back()->with('success', 'Document promoted to approved.');
    }

    public function approve(Document $document): RedirectResponse
    {
        $this->authorize('approve', $document);
        $this->documentService->approve($document, Auth::user());

        return back()->with('success', 'Document approved.');
    }

    public function reject(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('approve', $document);
        $this->documentService->reject($document, $request->input('comment', ''), Auth::user());

        return back()->with('success', 'Document rejected.');
    }

    public function archive(Document $document): RedirectResponse
    {
        $this->authorize('approve', $document);
        $this->documentService->archive($document, Auth::user());

        return back()->with('success', 'Document archived.');
    }

    public function checkOut(Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $this->documentService->checkOut($document, Auth::user());

        return back()->with('success', 'Document checked out.');
    }

    public function checkIn(Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $document->file || abort(400, 'Cannot check in: the associated file is no longer available.');
        $this->documentService->checkIn($document, $document->file, Auth::user());

        return back()->with('success', 'Document checked in.');
    }

    public function cancelCheckOut(Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $this->documentService->cancelCheckOut($document, Auth::user());

        return back()->with('success', 'Check-out cancelled.');
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        $document->file || abort(404, 'The associated file is no longer available.');
        app(\App\Services\AuditService::class)->log('document.download', Document::class, $document->id, null, ['title' => $document->title]);
        $docVersion = $document->versions()->where('version', $document->current_version)->first();
        $docVersion || abort(404);
        $fileVersion = $docVersion->fileVersion;
        $file = $document->file;

        return app(FileService::class)->streamStoredFile(
            $fileVersion->storage_path,
            $file->name,
            $file->mime_type,
            'attachment',
            $file->storageSpace,
        );
    }

    public function approveWorkflowStep(Document $document, WorkflowStepInstance $workflowStepInstance): RedirectResponse
    {
        $this->authorize('view', $document);
        abort_unless($workflowStepInstance->workflowInstance->document_id === $document->id, 403);
        $this->workflowService->approveStep($workflowStepInstance, Auth::user(), request('comment'));

        return back()->with('success', 'Step approved.');
    }

    public function rejectWorkflowStep(Request $request, Document $document, WorkflowStepInstance $workflowStepInstance): RedirectResponse
    {
        $this->authorize('view', $document);
        abort_unless($workflowStepInstance->workflowInstance->document_id === $document->id, 403);
        $this->workflowService->rejectStep($workflowStepInstance, Auth::user(), $request->input('comment'));

        return back()->with('success', 'Step rejected. Document returned to draft.');
    }

    public function toggleLegalHold(Document $document): RedirectResponse
    {
        Auth::user()->can('manage-retention-disposition') || abort(403);
        $document->update(['legal_hold' => ! $document->legal_hold]);

        return back()->with('success', $document->legal_hold ? 'Legal hold applied.' : 'Legal hold removed.');
    }
}
