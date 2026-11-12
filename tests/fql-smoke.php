#!/usr/bin/env php
<?php

declare(strict_types=1);

foreach (['Support', 'FixtureGenerator', 'Scenarios'] as $name) {
    require dirname(__DIR__) . '/src/' . $name . '.php';
}

use FiQueLaBench\FixtureGenerator;
use FiQueLaBench\Scenarios;
use FiQueLaBench\Support;

$temporary = sys_get_temp_dir() . '/fiquela-fql-test-' . bin2hex(random_bytes(6));
try {
    $fixtures = $temporary . '/fixtures';
    FixtureGenerator::generate(80, $fixtures);
    foreach (Scenarios::select('all') as $scenario) {
        $response = Support::workerResult([PHP_BINARY, '-d', 'memory_limit=512M', Support::root() . '/bin/worker.php',
            '--scenario=' . $scenario, '--size=80', '--fixtures=' . $fixtures], 60);
        echo sprintf("OK %-20s %5d output rows\n", $scenario, $response['rows_output']);
    }
    $explain = Support::workerResult([PHP_BINARY, Support::root() . '/bin/worker.php',
        '--scenario=csv_where', '--size=80', '--fixtures=' . $fixtures, '--explain'], 60);
    if (count($explain['explain_rows'] ?? []) === 0) {
        throw new RuntimeException('EXPLAIN ANALYZE returned no phases');
    }
    echo 'OK EXPLAIN ANALYZE (' . count($explain['explain_rows']) . " phases)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FQL SMOKE FAILED: {$e->getMessage()}\n");
    exit(1);
} finally {
    if (is_dir($temporary)) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($walk as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temporary);
    }
}
