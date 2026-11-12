#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support.php';
require dirname(__DIR__) . '/src/Scenarios.php';
require dirname(__DIR__) . '/src/FixtureGenerator.php';
require dirname(__DIR__) . '/src/Bootstrap.php';

use FiQueLaBench\Bootstrap;
use FiQueLaBench\Scenarios;
use FiQueLaBench\Support;

/** Returns the Linux process high water mark in KiB, if available. */
function processPeakRssKb(): ?int
{
    if (!is_readable('/proc/self/status')) {
        return null;
    }
    $status = file_get_contents('/proc/self/status');
    if ($status !== false && preg_match('/^VmHWM:\s+(\d+)\s+kB/m', $status, $matches)) {
        return (int) $matches[1];
    }
    return null;
}

/** @return Generator<array<string, string|int|float>> */
function nativeCsvRows(string $filename): Generator
{
    $handle = fopen($filename, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Cannot open {$filename}");
    }
    try {
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($header)) {
            throw new RuntimeException('CSV has no header');
        }
        while (($fields = fgetcsv($handle)) !== false) {
            if (count($fields) !== count($header)) {
                throw new RuntimeException('Malformed CSV record');
            }
            yield array_combine($header, $fields);
        }
    } finally {
        fclose($handle);
    }
}

try {
    $opt = Support::options($argv);
    $scenario = Support::string($opt, 'scenario', 'csv_where');
    if (!isset(Scenarios::all()[$scenario])) {
        throw new InvalidArgumentException("Unknown scenario {$scenario}");
    }
    $size = Support::integer($opt, 'size', 10000, 1);
    $fixtures = Support::absolute(Support::string($opt, 'fixtures', Support::defaultFixtures()));
    $directory = $fixtures . '/' . $size;
    $manifestPath = $directory . '/manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException("Missing fixture manifest: {$manifestPath}; run bin/generate.php");
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['rows'] ?? null) !== $size || ($manifest['version'] ?? null) !== \FiQueLaBench\FixtureGenerator::VERSION) {
        throw new RuntimeException('Fixture manifest/version mismatch. Regenerate the fixtures.');
    }
    if (preg_match('/[\s()]/', $directory)) {
        throw new RuntimeException('FQL file paths must not contain whitespace or parentheses; use a fixture path without them.');
    }
    $explain = isset($opt['explain']);
    if ($explain && $scenario === 'native_csv_scan') {
        throw new RuntimeException('EXPLAIN ANALYZE is available only for FQL scenarios');
    }
    $sql = Scenarios::sql($scenario, $directory);
    if ($explain) {
        $sql = 'EXPLAIN ANALYZE ' . $sql;
    }
    $preparedStart = hrtime(true);
    if ($scenario === 'native_csv_scan') {
        $query = null;
    } else {
        Bootstrap::autoloadFiQueLa();
        $query = \FQL\Query\Provider::fql($sql);
    }
    $preparedEnd = hrtime(true);

    $executeStart = hrtime(true);
    if ($scenario === 'native_csv_scan') {
        $results = nativeCsvRows($directory . '/products.csv');
    } else {
        $results = $query->execute();
    }
    $executeEnd = hrtime(true);

    $consumeStart = hrtime(true);
    $rows = 0;
    $hash = hash_init('sha256');
    $last = null;
    $explainRows = [];
    foreach ($results as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Expected array row; unexpected result type: ' . get_debug_type($row));
        }
        // Sparse fingerprint adds negligible overhead compared with hashing every row.
        if ($rows === 0 || ($rows % 1024) === 0) {
            hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $last = $row;
        $rows++;
        if ($explain) {
            $explainRows[] = $row;
        }
    }
    if ($last !== null) {
        hash_update($hash, json_encode($last, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
    $consumeEnd = hrtime(true);

    if (!$explain) {
        $expected = Scenarios::expectedCount($scenario, $size);
        if ($rows !== $expected) {
            throw new RuntimeException("Unexpected row count for {$scenario}: got {$rows}, expected {$expected}");
        }
    }
    $totalMs = ($consumeEnd - $preparedStart) / 1e6;
    $response = [
        'scenario' => $scenario,
        'size' => $size,
        'engine' => $scenario === 'native_csv_scan' ? 'native_php_reference' : 'fiquela',
        'rows_output' => $rows,
        'rows_input_nominal' => $size,
        'fingerprint_sampled' => hash_final($hash),
        'prepare_ms' => ($preparedEnd - $preparedStart) / 1e6,
        'execute_ms' => ($executeEnd - $executeStart) / 1e6,
        'consume_ms' => ($consumeEnd - $consumeStart) / 1e6,
        'total_ms' => $totalMs,
        'nominal_input_rows_per_second' => $totalMs > 0 ? $size / ($totalMs / 1000) : null,
        'php_peak_bytes' => memory_get_peak_usage(true),
        'process_peak_rss_kib' => processPeakRssKb(),
        'result_mode' => is_object($results) ? $results::class : get_debug_type($results),
        'explain_rows' => $explain ? $explainRows : null,
    ];
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
}
