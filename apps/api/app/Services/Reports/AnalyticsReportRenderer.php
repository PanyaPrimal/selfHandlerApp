<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

class AnalyticsReportRenderer
{
    /** @param array<string,mixed> $workspace */
    public function csv(array $workspace, string $locale): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new \RuntimeException('Could not create the report stream.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($this->metadataRows($workspace, $locale) as $row) {
            $this->writeCsv($stream, $row);
        }
        $this->writeCsv($stream, []);
        $this->writeCsv($stream, [
            $this->text('period_start', $locale), $this->text('period_end', $locale),
            $this->text('value', $locale), $this->text('state', $locale),
            $this->text('samples', $locale), $this->text('reason', $locale),
        ]);
        foreach ($workspace['points'] as $point) {
            $this->writeCsv($stream, [
                $point['bucket_start'], $point['bucket_end'], $point['value'] ?? '',
                $this->text('states.'.($point['state'] ?? 'empty'), $locale),
                (string) ($point['sample_count'] ?? 0), $this->reasons($point['reasons'] ?? [], $locale),
            ]);
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return is_string($content) ? $content : '';
    }

    /** @param array<string,mixed> $workspace */
    public function pdf(array $workspace, string $locale): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('chroot', base_path());
        $options->set('tempDir', sys_get_temp_dir());
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->html($workspace, $locale), 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @param array<string,mixed> $workspace @return list<list<string>> */
    private function metadataRows(array $workspace, string $locale): array
    {
        $period = $workspace['period'];
        $metric = $workspace['metric'];
        $trend = $workspace['trend'];
        $rows = [
            [$this->text('title', $locale)],
            [$this->text('generated_at', $locale), Carbon::now('UTC')->toIso8601String()],
            [$this->text('metric', $locale), $this->text('metrics.'.$metric['key'], $locale)],
            [$this->text('range', $locale), $period['from'].' — '.$period['to']],
            [$this->text('granularity', $locale), $this->text('granularities.'.$period['granularity'], $locale)],
            [$this->text('aggregation', $locale), $this->text('aggregations.'.$metric['operator'], $locale)],
            [$this->text('unit', $locale), $this->unit($metric, $workspace['currency'] ?? null, $locale)],
            [$this->text('available_points', $locale), (string) $trend['available_points']],
            [$this->text('total_intervals', $locale), (string) $trend['total_buckets']],
            [$this->text('first', $locale), (string) ($trend['first'] ?? '')],
            [$this->text('last', $locale), (string) ($trend['last'] ?? '')],
            [$this->text('delta', $locale), (string) ($trend['delta'] ?? '')],
            [$this->text('slope', $locale), (string) ($trend['slope_per_bucket'] ?? '')],
        ];
        if (is_array($workspace['comparison'] ?? null)) {
            $comparison = $workspace['comparison'];
            $rows[] = [$this->text('comparison', $locale), $comparison['previous']['from'].' — '.$comparison['previous']['to']];
            $rows[] = [$this->text('current_value', $locale), (string) ($comparison['current']['value'] ?? '')];
            $rows[] = [$this->text('previous_value', $locale), (string) ($comparison['previous']['value'] ?? '')];
            $rows[] = [$this->text('absolute_delta', $locale), (string) ($comparison['absolute_delta'] ?? '')];
            $rows[] = [$this->text('percentage_delta', $locale), (string) ($comparison['percentage_delta'] ?? '')];
        }

        return $rows;
    }

    /** @param resource $stream @param list<string> $row */
    private function writeCsv($stream, array $row): void
    {
        $safe = array_map(function (string $cell): string {
            return preg_match('/^[=+\-@\t\r]/u', $cell) ? "'".$cell : $cell;
        }, $row);
        fputcsv($stream, $safe, ',', '"', '', "\r\n");
    }

    /** @param array<string,mixed> $workspace */
    private function html(array $workspace, string $locale): string
    {
        $escape = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $metadata = '';
        foreach ($this->metadataRows($workspace, $locale) as $row) {
            $metadata .= '<tr><th>'.$escape($row[0]).'</th><td>'.$escape($row[1] ?? '').'</td></tr>';
        }
        $points = '';
        foreach ($workspace['points'] as $point) {
            $points .= '<tr><td>'.$escape($point['bucket_start']).'</td><td>'.$escape($point['bucket_end'])
                .'</td><td>'.$escape($point['value'] ?? '—').'</td><td>'
                .$escape($this->text('states.'.($point['state'] ?? 'empty'), $locale)).'</td><td>'
                .$escape($point['sample_count'] ?? 0).'</td><td>'
                .$escape($this->reasons($point['reasons'] ?? [], $locale)).'</td></tr>';
        }

        return '<!doctype html><html lang="'.$escape($locale).'"><head><meta charset="UTF-8"><style>'
            .'@page{margin:18mm}body{font-family:"DejaVu Sans",sans-serif;color:#17211b;font-size:10px}'
            .'h1{font-size:20px;margin:0 0 10px}p{color:#536158}table{width:100%;border-collapse:collapse;margin:0 0 14px}'
            .'th,td{border:1px solid #ccd5ce;padding:5px;text-align:left;vertical-align:top}th{background:#eef3ef}'
            .'.meta th{width:32%}.note{font-size:9px}</style></head><body><h1>'.$escape($this->text('title', $locale))
            .'</h1><p>'.$escape($this->text('subtitle', $locale)).'</p><table class="meta">'.$metadata.'</table><table><thead><tr><th>'
            .$escape($this->text('period_start', $locale)).'</th><th>'.$escape($this->text('period_end', $locale)).'</th><th>'
            .$escape($this->text('value', $locale)).'</th><th>'.$escape($this->text('state', $locale)).'</th><th>'
            .$escape($this->text('samples', $locale)).'</th><th>'.$escape($this->text('reason', $locale))
            .'</th></tr></thead><tbody>'.$points.'</tbody></table><p class="note">'.$escape($this->text('evidence_note', $locale))
            .'</p></body></html>';
    }

    /** @param list<string> $reasons */
    private function reasons(array $reasons, string $locale): string
    {
        return implode('; ', array_map(function (string $reason) use ($locale): string {
            if (str_starts_with($reason, 'missing_fx:')) {
                return trans('reports.reasons.missing_fx', ['currency' => substr($reason, 11)], $locale);
            }

            return $this->text('reasons.missing_evidence', $locale);
        }, $reasons));
    }

    /** @param array<string,mixed> $metric */
    private function unit(array $metric, ?string $currency, string $locale): string
    {
        return $metric['unit'] === 'currency' && $currency ? $currency : $this->text('units.'.$metric['unit'], $locale);
    }

    private function text(string $key, string $locale): string
    {
        return trans('reports.'.$key, [], $locale);
    }
}
