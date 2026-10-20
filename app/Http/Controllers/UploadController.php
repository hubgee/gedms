<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\StorageSpace;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP adapter for resumable uploads.
 *
 * Deliberately thin: validation, model resolution and JSON shaping only. Every
 * rule (access, placement, chunk sizing, expiry, reconciliation) lives in
 * UploadService, so the transport could be lifted into a standalone app later
 * without re-implementing any behaviour.
 *
 * Chunks are sent as multipart/form-data so PHP's upload handling and
 * UploadedFile::isValid() still protect against truncated bodies.
 */
class UploadController extends Controller
{
    public function __construct(
        protected UploadService $uploadService,
    ) {
        $this->middleware('can:create-files');
    }

    /** Start a new upload session, or resume the matching one. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'space_id' => ['required', 'integer', 'exists:storage_spaces,id'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
            'name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'max:255'],
            'sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        $space = StorageSpace::findOrFail($validated['space_id']);
        $folder = isset($validated['folder_id'])
            ? Folder::findOrFail($validated['folder_id'])
            : null;

        $session = $this->uploadService->init(
            $request->user(),
            $space,
            $folder,
            $validated['name'],
            $validated['mime_type'] ?? null,
            (int) $validated['size'],
            $validated['fingerprint'],
            $validated['sha256'] ?? null,
        );

        return response()->json(
            $this->uploadService->status($session, $request->user()),
            201,
        );
    }

    /** Resume state: which chunk indexes still have to be sent. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $session = $this->uploadService->findForUser($uuid, $request->user());

        return response()->json(
            $this->uploadService->status($session, $request->user()),
        );
    }

    /** Store one chunk. Re-sending an index is safe. */
    public function chunk(Request $request, string $uuid, int $index): JsonResponse
    {
        $validated = $request->validate([
            'chunk' => ['required', 'file'],
            'sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        $session = $this->uploadService->findForUser($uuid, $request->user());

        $session = $this->uploadService->putChunk(
            $session,
            $request->user(),
            $index,
            $validated['chunk'],
            $validated['sha256'] ?? null,
        );

        // Compact on purpose: the full missing list is fetched from show() when
        // a client resumes, so a 1,280-chunk upload does not ship that array on
        // every single chunk response.
        return response()->json([
            'uuid' => $session->uuid,
            'status' => $session->status,
            'received_bytes' => (int) $session->received_bytes,
            'total_bytes' => (int) $session->total_bytes,
            'total_chunks' => (int) $session->total_chunks,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ]);
    }

    /** Finalise the upload and queue the assembly job. */
    public function complete(Request $request, string $uuid): JsonResponse
    {
        $session = $this->uploadService->findForUser($uuid, $request->user());
        $session = $this->uploadService->complete($session, $request->user());

        return response()->json(
            $this->uploadService->status($session, $request->user()),
        );
    }

    /** Cancel a session and drop the bytes received so far. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $session = $this->uploadService->findForUser($uuid, $request->user());

        $this->uploadService->cancel($session, $request->user());

        return response()->json([
            'uuid' => $session->uuid,
            'status' => $session->status,
        ]);
    }
}
