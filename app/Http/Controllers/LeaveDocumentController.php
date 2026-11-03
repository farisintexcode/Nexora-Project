<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\Attendance\LeaveAccess;
use App\Services\Attendance\LeaveDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LeaveDocumentController extends Controller
{
    public function __invoke(Request $request, LeaveRequest $leave, LeaveAccess $access, LeaveDocument $files): Response
    {
        abort_unless($access->view($request->user(), $leave), 404);
        $doc = $leave->document;
        abort_unless($doc && $doc->owner_type === 'user' && $doc->owner_id === $leave->requested_by && $doc->uploaded_by === $leave->requested_by, 404);
        try {
            $bytes = $files->bytes($doc);
        } catch (\Throwable) {
            abort(503, 'The supporting document is temporarily unavailable.');
        }

        return response($bytes, 200, ['Content-Type' => $doc->mime_type, 'Content-Disposition' => 'attachment; filename="'.$doc->original_filename.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
