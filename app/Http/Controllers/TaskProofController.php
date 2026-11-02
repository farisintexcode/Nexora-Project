<?php

namespace App\Http\Controllers;

use App\Actions\Tasks\TaskProof;
use App\Models\Document;
use App\Models\Task;
use App\Services\Tasks\RiderAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class TaskProofController extends Controller
{
    public function __invoke(Request $request, Document $document, TaskProof $proof, RiderAccess $access): Response
    {
        abort_unless($document->owner_type === 'task' && $document->document_type_code === 'proof_image' && $document->isCleared(), 404);
        $task = Task::query()->findOrFail($document->owner_id);
        $actor = $request->user();
        abort_unless(($actor->can('admin.access') && Gate::forUser($actor)->allows('view', $task)) ||
            ($access->current($actor, $task) && $document->uploaded_by === $actor->id), 404);
        try {
            $bytes = $proof->bytes($document);
        } catch (\RuntimeException) {
            abort(503, 'Private proof is temporarily unavailable.');
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="proof.jpg"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
