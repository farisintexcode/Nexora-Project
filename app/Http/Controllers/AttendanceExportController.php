<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Attendance\AttendanceStatus;
use App\Services\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceExportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, AttendanceRegister $register, AuditWriter $audit): Response
    {
        Gate::authorize('export', Attendance::class);
        $format = $request->validate(['format' => ['required', 'in:csv,print']])['format'];
        $criteria = $register->criteria(session('attendance.filters.'.$request->user()->id, []));
        $asOf = now()->toImmutable();
        $rows = DB::transaction(function () use ($request, $register, $criteria, $asOf, $audit) {
            $rows = $register->query($request->user(), $criteria, asOf: $asOf)->orderBy('service_date')->orderBy('staff_no')->orderBy('rider_public_id')->limit(5001)->get();
            if ($rows->count() > 5000) {
                throw ValidationException::withMessages(['export' => 'Narrow attendance filters to at most 5,000 rows for this export.']);
            }
            $audit->record($request->user(), 'attendance.exported', metadata: ['status' => 'generated'], module: 'M10', entityType: 'attendance');

            return $rows;
        });
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($format === 'print') {
            return response()->view('reports.attendance', compact('rows', 'criteria', 'asOf'), 200, $headers);
        }

        return response()->streamDownload(function () use ($rows, $asOf): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Staff number', 'Rider', 'Service date', 'Status', 'Late', 'Late minutes', 'Worked minutes', 'Unpaid break minutes', 'Raw in (MYT)', 'Raw out (MYT)', 'Effective in (MYT)', 'Effective out (MYT)', 'Policy version', 'As of (MYT)'], escape: '');
            $time = fn (?string $value): string => $value ? CarbonImmutable::parse($value)->timezone(config('dcms.business_timezone'))->format('Y-m-d H:i:s') : '';
            foreach ($rows as $row) {
                $values = [$row->staff_no, $row->rider_name, $row->service_date, AttendanceStatus::LABELS[$row->result_status], $row->is_late ? 'Yes' : 'No', $row->late_minutes, $row->worked_minutes, $row->break_minutes, $time($row->raw_clock_in_at), $time($row->raw_clock_out_at), $time($row->effective_clock_in_at), $time($row->effective_clock_out_at), $row->policy_code ? $row->policy_code.' v'.$row->policy_version : 'Unscheduled', $asOf->timezone(config('dcms.business_timezone'))->format('Y-m-d H:i:s')];
                fputcsv($stream, array_map(fn ($value): string => preg_match('/^[\s]*[=+@\-\t\r\n]/u', (string) $value) ? "'".$value : (string) $value, $values), escape: '');
            }
            fclose($stream);
        }, 'nexora-attendance-'.$asOf->format('Ymd-His').'.csv', [...$headers, 'Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
