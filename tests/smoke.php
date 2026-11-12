#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support.php';
require dirname(__DIR__) . '/src/FixtureGenerator.php';

use FiQueLaBench\FixtureGenerator;
use FiQueLaBench\Support;

function fail(string $reason): never
{
    throw new RuntimeException($reason);
}

$temporary = sys_get_temp_dir() . '/fiquela-bench-test-' . bin2hex(random_bytes(6));
try {
    $fixtures = $temporary . '/fixtures';
    $target = FixtureGenerator::generate(80, $fixtures);
    $original = json_decode((string) file_get_contents($target . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    FixtureGenerator::generate(80, $fixtures, true);
    $new = json_decode((string) file_get_contents($target . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($original['files'] !== $new['files']) {
        fail('Fixture generation is not deterministic');
    }
    $worker = Support::workerResult([PHP_BINARY, Support::root() . '/bin/worker.php', '--scenario=native_csv_scan', '--size=80', '--fixtures=' . $fixtures], 30);
    if ($worker['rows_output'] !== 80 || $worker['total_ms'] <= 0) {
        fail('Native worker failed');
    }
    $report = $temporary . '/report';
    $run = Support::process([PHP_BINARY, Support::root() . '/bin/run.php', '--sizes=80', '--scenarios=native_csv_scan', '--repeat=2', '--warmup=0', '--no-generate', '--fixtures=' . $fixtures, '--out=' . $report], 60);
    if ($run['exit_code'] !== 0) {
        fail('Runner failed: ' . $run['stderr'] . $run['stdout']);
    }
    if (!is_file($report . '/results.json') || !is_file($report . '/summary.csv') || !is_file($report . '/summary.md')) {
        fail('Report artifacts missing');
    }
    $comparison = Support::process([PHP_BINARY, Support::root() . '/bin/compare.php', '--baseline=' . $report, '--candidate=' . $report, '--threshold=10', '--fail-on-regression'], 30);
    if ($comparison['exit_code'] !== 0 || !str_contains($comparison['stdout'], 'stable')) {
        fail('Compare failed');
    }
    $profile = Support::process([PHP_BINARY, Support::root() . '/bin/profile.php', '--type=phase', '--scenario=native_csv_scan', '--size=80', '--fixtures=' . $fixtures, '--no-generate', '--out=' . $temporary . '/profile'], 30);
    if ($profile['exit_code'] !== 0 || !is_file($temporary . '/profile/profile.json')) {
        fail('Phase profile failed: ' . $profile['stderr']);
    }
    echo "OK: deterministic fixtures, worker, isolated runner, JSON/CSV/Markdown, comparison, phase profiler.\n";
    echo "NOTE: FiQueLa integration requires composer install; native test does not verify FQL scenarios.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SMOKE FAILED: {$e->getMessage()}\n");
    exit(1);
} finally {
    // Delete only the uniquely named directory created by this smoke test.
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
