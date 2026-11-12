<?php

declare(strict_types=1);

namespace FiQueLaBench;

final class Report
{
    /** @param list<array<string,mixed>> $entries */
    public static function save(string $directory, array $metadata, array $entries, array $errors): void
    {
        Support::ensureDir($directory);
        Support::jsonFile($directory . '/results.json', [
            'schema_version' => 1,
            'metadata' => $metadata,
            'entries' => $entries,
            'errors' => $errors,
        ]);
        $csv = fopen($directory . '/summary.csv', 'wb');
        fputcsv($csv, ['scenario', 'size', 'n', 'median_ms', 'p95_ms', 'mean_ms', 'stdev_ms', 'median_peak_php_mib', 'median_peak_rss_mib', 'nominal_rows_per_second']);
        foreach ($entries as $entry) {
            fputcsv($csv, [
                $entry['scenario'], $entry['size'], $entry['stats']['n'],
                round($entry['stats']['median'], 4), round($entry['stats']['p95'], 4),
                round($entry['stats']['mean'], 4), round($entry['stats']['stdev'], 4),
                round($entry['php_peak_mib']['median'], 2),
                isset($entry['rss_peak_mib']['median']) ? round($entry['rss_peak_mib']['median'], 2) : '',
                round($entry['nominal_input_rows_per_second'], 2),
            ]);
        }
        fclose($csv);
        $lines = [
            '# FiQueLa benchmark report', '',
            '- Run: `' . $metadata['created_at'] . '`',
            '- PHP: `' . $metadata['php_version'] . '`',
            '- Host: `' . str_replace('|', '\\|', $metadata['host']) . '`',
            '- FiQueLa: `' . ($metadata['fiquela_version'] ?? 'unknown / local checkout') . '`',
            '- Warmups: ' . $metadata['warmup'] . ', measured iterations: ' . $metadata['repeat'],
            '',
            '| Scenario | Rows | Median ms | p95 ms | PHP peak MiB | RSS peak MiB | Nominal rows/s |',
            '|---|---:|---:|---:|---:|---:|---:|',
        ];
        foreach ($entries as $entry) {
            $lines[] = sprintf('| `%s` | %d | %.2f | %.2f | %.1f | %s | %s |',
                $entry['scenario'], $entry['size'], $entry['stats']['median'], $entry['stats']['p95'],
                $entry['php_peak_mib']['median'],
                isset($entry['rss_peak_mib']['median']) ? sprintf('%.1f', $entry['rss_peak_mib']['median']) : 'n/a',
                number_format($entry['nominal_input_rows_per_second'], 0, '.', ' ')
            );
        }
        if ($errors !== []) {
            $lines[] = '';
            $lines[] = '## Failed scenarios';
            foreach ($errors as $error) {
                $lines[] = '- `' . $error['scenario'] . '` (' . $error['size'] . '): ' . str_replace(["\r", "\n"], ' ', $error['message']);
            }
        }
        $lines[] = '';
        $lines[] = 'Throughput uses nominal fixture input rows, **not** observed rows scanned. `EXPLAIN ANALYZE` and Xdebug profiler are separate runs and intentionally excluded from timings.';
        $lines[] = 'PHP peak counts Zend-allocated memory; Linux process RSS can include native extension allocations.';
        file_put_contents($directory . '/summary.md', implode("\n", $lines) . "\n");
    }
}
