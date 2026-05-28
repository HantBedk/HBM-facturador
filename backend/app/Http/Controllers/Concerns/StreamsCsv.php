<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

trait StreamsCsv
{
    /**
     * Stream a CSV file with UTF-8 BOM and semicolon separator (Excel-compatible).
     *
     * @param  string    $filename   Filename for the Content-Disposition header
     * @param  string[]  $headers    Column headers row
     * @param  iterable  $rows       Iterable of data rows; each item is passed to $rowMapper
     * @param  callable  $rowMapper  Maps each item to an array of string values
     * @param  int       $rowCap     Maximum rows to write (0 = unlimited)
     */
    protected function streamCsv(
        string $filename,
        array $headers,
        iterable $rows,
        callable $rowMapper,
        int $rowCap = 0
    ): StreamedResponse {
        return response()->streamDownload(function () use ($headers, $rows, $rowMapper, $rowCap) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');

            $n = 0;
            foreach ($rows as $item) {
                if ($rowCap > 0 && ++$n > $rowCap) {
                    break;
                }
                fputcsv($out, $rowMapper($item), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
