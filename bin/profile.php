#!/usr/bin/env php
<?php

declare(strict_types=1);

foreach (['Support', 'Scenarios', 'FixtureGenerator', 'Report'] as $name) {
    require dirname(__DIR__) . '/src/' . $name . '.php';
}

use FiQueLaBench\FixtureGenerator;
use FiQueLaBench\Scenarios;
use FiQueLaBench\Support;

try {
    $opt = Support::options($argv);
    $type = Support::string($opt, 'type', 'phase');
    if (!in_array($type, ['phase', 'explain', 'xdebug'], true)) {
        throw new InvalidArgumentException('--type must be phase, explain or xdebug');
    }
    $scenario = Support::string($opt, 'scenario', 'csv_where');
    if (!isset(Scenarios::all()[$scenario])) {
        throw new InvalidArgumentException("Unknown scenario {$scenario}");
    }
    $size = Support::integer($opt, 'size', 10000, 1);
    $timeout = Support::integer($opt, 'timeout', 3600, 1);
    $fixtures = Support::absolute(Support::string($opt, 'fixtures', Support::defaultFixtures()));
    $out = Support::absolute(Support::string($opt, 'out', Support::root() . '/profiles/' . $scenario . '-' . $size . '-' . $type . '-' . gmdate('Ymd-His')));
    $memory = Support::string($opt, 'memory', '1024M');
    if (!preg_match('/^\d+[KMG]?$/i', $memory)) {
        throw new InvalidArgumentException('Invalid --memory');
    }
    Support::ensureDir($out);
    if (!isset($opt['no-generate'])) {
        FixtureGenerator::generate($size, $fixtures);
    }
    $command = [PHP_BINARY, '-d', 'memory_limit=' . $memory];
    $env = [];
    if ($type === 'xdebug') {
        if (!extension_loaded('xdebug')) {
            throw new RuntimeException('Xdebug is not loaded in PHP CLI. Install/enable xdebug, then rerun with --type=xdebug.');
        }
        $command = array_merge($command, [
            '-d', 'xdebug.mode=profile',
            '-d', 'xdebug.output_dir=' . $out,
            '-d', 'xdebug.output_name=cachegrind.out.%p',
            '-d', 'xdebug.start_with_request=yes',
        ]);
        $env['XDEBUG_MODE'] = 'profile';
    }
    $command = array_merge($command, [Support::root() . '/bin/worker.php', '--scenario=' . $scenario, '--size=' . $size, '--fixtures=' . $fixtures]);
    if ($type === 'explain') {
        $command[] = '--explain';
    }
    $result = Support::workerResult($command, $timeout, $env);
    Support::jsonFile($out . '/profile.json', [
        'type' => $type, 'scenario' => $scenario, 'size' => $size, 'timestamp' => gmdate('c'),
        'result' => $result,
    ]);
    echo "Profile result: {$out}/profile.json\n";
    printf("Prepare: %.3f ms, execute: %.3f ms, consume: %.3f ms, total: %.3f ms\n",
        $result['prepare_ms'], $result['execute_ms'], $result['consume_ms'], $result['total_ms']);
    if ($type === 'explain') {
        echo "\nFiQueLa EXPLAIN ANALYZE phases:\n";
        printf("%-20s %9s %9s %12s %8s\n", 'Phase', 'In', 'Out', 'Time (ms)', 'Time %');
        foreach ($result['explain_rows'] ?? [] as $phase) {
            printf("%-20s %9s %9s %12s %8s\n",
                $phase['phase'] ?? '?', $phase['rows_in'] ?? '-', $phase['rows_out'] ?? '-',
                $phase['time_ms'] ?? '-', $phase['duration_pct'] ?? '-');
        }
    }
    if ($type === 'xdebug') {
        $profiles = glob($out . '/cachegrind.out.*') ?: [];
        if ($profiles === []) {
            throw new RuntimeException("Xdebug did not produce callgrind output in {$out}");
        }
        echo "Cachegrind file: {$profiles[0]}\nOpen it with KCachegrind/QCachegrind.\n";
        echo "Profiler timings are NOT comparable with unprofiled benchmarks (profiling adds overhead).\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
