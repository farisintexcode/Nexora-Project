<?php

namespace App\Actions\Reports;

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\TaskReports;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageReports
{
    public function __construct(private TaskReports $reports, private AuditWriter $audit) {}

    /**
     * Builds a report file for immediate download. Nothing is stored; the download is audited.
     *
     * @return array{name: string, mime: string, bytes: string}
     */
    public function download(User $actor, array $criteria, string $kind): array
    {
        $this->reports->authorize($actor, true);
        abort_unless(in_array($kind, ['csv', 'pdf'], true), 422);
        $snapshot = $this->reports->snapshot($actor, $criteria);
        try {
            $bytes = app(ReportRenderer::class)->render($snapshot, $kind);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['report' => 'This report could not be prepared. Try narrowing the filters.']);
        }
        $this->audit->record($actor, 'report.downloaded', (string) Str::uuid7(), ['status' => 'completed'], module: 'M09', entityType: 'report');

        return ['name' => 'nexora-'.$snapshot['criteria']['family'].'-'.now()->timezone(config('dcms.business_timezone'))->format('Ymd-His').'.'.$kind, 'mime' => $kind === 'csv' ? 'text/csv' : 'application/pdf', 'bytes' => $bytes];
    }
}
