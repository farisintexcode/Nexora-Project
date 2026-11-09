<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;

class ReportRenderer
{
    public function render(array $snapshot, string $kind): string
    {
        if ($kind === 'csv') {
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, [...array_values($snapshot['columns']), 'Snapshot (UTC)'], escape: '');
            foreach ($snapshot['rows'] as $row) {
                $values = [];
                foreach (array_keys($snapshot['columns']) as $column) {
                    $value = $row[$column] ?? '';
                    $values[] = $this->csv(is_array($value) ? implode("\n", array_column($value, 'url')) : $value);
                }
                fputcsv($stream, [...$values, $snapshot['as_of']], escape: '');
            }
            rewind($stream);
            $bytes = stream_get_contents($stream);
            fclose($stream);

            return $bytes;
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://']);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.tasks', compact('snapshot'))->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return $pdf->output();
    }

    private function csv(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[\s]*[=+@\-\t\r\n]/u', $value) ? "'".$value : $value;
    }
}
