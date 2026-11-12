#!/usr/bin/env php
<?php

declare(strict_types=1);

foreach (['Support', 'FixtureGenerator', 'Scenarios', 'Report'] as $name) {
    require dirname(__DIR__) . '/src/' . $name . '.php';
}

use FiQueLaBench\FixtureGenerator;
use FiQueLaBench\Report;
use FiQueLaBench\Scenarios;
use FiQueLaBench\Support;

try {
    $opt = Support::options($argv);
    $sizes = Support::sizes($opt);
    $scenarios = Scenarios::select(Support::string($opt, 'scenarios', 'default'));
    $repeat = Support::integer($opt, 'repeat', 5, 1);
    $warmup = Support::integer($opt, 'warmup', 1);
    $timeout = Support::integer($opt, 'timeout', 3600, 1);
    $fixtures = Support::absolute(Support::string($opt, 'fixtures', Support::defaultFixtures()));
    $memory = Support::string($opt, 'memory', '1024M');
    $jit = Support::string($opt, 'jit', 'default');
    if (!in_array($jit, ['default', 'on', 'off'], true)) {
        throw new InvalidArgumentException('--jit must be default, on or off');
    }
    if (!preg_match('/^\d+[KMG]?$/i', $memory)) {
        throw new InvalidArgumentException('Invalid --memory value; use e.g. 1024M');
    }
    $output = Support::absolute(Support::string($opt, 'out', Support::root() . '/reports/' . gmdate('Ymd-His') . '-' . getmypid()));
    Support::ensureDir($output);
    $metadata = [
        'created_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'php_binary' => PHP_BINARY,
        'host' => php_uname(),
        'cpu_count' => (int) (getenv('NUMBER_OF_PROCESSORS') ?: (is_readable('/proc/cpuinfo') ? preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')) : 0)),
        'extensions' => get_loaded_extensions(),
        'opcache_enable_cli' => ini_get('opcache.enable_cli'),
        'opcache_jit' => ini_get('opcache.jit'),
        'opcache_jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
        'fiquela_version' => null,
        'fiquela_autoload' => getenv('FIQUELA_AUTOLOAD') ?: null,
        'sizes' => $sizes, 'scenarios' => $scenarios,
        'repeat' => $repeat, 'warmup' => $warmup, 'timeout' => $timeout,
        'memory' => $memory, 'requested_jit' => $jit,
    ];
    $sourceRoot = getenv('FIQUELA_AUTOLOAD')
        ? dirname(dirname((string) realpath((string) getenv('FIQUELA_AUTOLOAD'))))
        : dirname(Support::root()) . '/FiQueLa';
    if (is_dir($sourceRoot . '/.git')) {
        $commit = Support::process(['git', '-C', $sourceRoot, 'rev-parse', 'HEAD'], 10);
        if ($commit['exit_code'] === 0) {
            $metadata['fiquela_git_commit'] = $commit['stdout'];
            $status = Support::process(['git', '-C', $sourceRoot, 'status', '--porcelain', '--untracked-files=no'], 10);
            $metadata['fiquela_tracked_changes'] = $status['exit_code'] === 0 ? $status['stdout'] !== '' : null;
        }
    }
    if (is_file(Support::root() . '/vendor/composer/installed.json')) {
        $installed = json_decode((string) file_get_contents(Support::root() . '/vendor/composer/installed.json'), true);
        foreach (($installed['packages'] ?? $installed ?? []) as $package) {
            if (($package['name'] ?? '') === '1biot/fiquela') {
                $metadata['fiquela_version'] = $package['version'] ?? null;
            }
        }
    }
    $entries = [];
    $errors = [];
    foreach ($sizes as $size) {
        if (!isset($opt['no-generate'])) {
            echo "Preparing fixtures: {$size} rows...\n";
            FixtureGenerator::generate($size, $fixtures);
        }
        foreach ($scenarios as $scenario) {
            $command = [PHP_BINARY, '-d', 'memory_limit=' . $memory];
            if ($jit === 'on') {
                array_push($command, '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit_buffer_size=64M', '-d', 'opcache.jit=tracing');
            } elseif ($jit === 'off') {
                array_push($command, '-d', 'opcache.jit_buffer_size=0', '-d', 'opcache.jit=off');
            }
            array_push($command, Support::root() . '/bin/worker.php',
                '--scenario=' . $scenario, '--size=' . $size, '--fixtures=' . $fixtures);
            echo sprintf("%-20s %9d rows  ", $scenario, $size);
            try {
                for ($i = 0; $i < $warmup; $i++) {
                    Support::workerResult($command, $timeout);
                }
                $samples = [];
                $fp = null;
                for ($i = 0; $i < $repeat; $i++) {
                    $sample = Support::workerResult($command, $timeout);
                    if ($fp !== null && $fp !== $sample['fingerprint_sampled']) {
                        throw new RuntimeException('Sampled result fingerprint changed between repetitions');
                    }
                    $fp = $sample['fingerprint_sampled'];
                    $samples[] = $sample;
                }
                $times = array_map(static fn ($s) => (float) $s['total_ms'], $samples);
                $phpPeak = array_map(static fn ($s) => (float) $s['php_peak_bytes'] / 1048576, $samples);
                $rssPeak = array_values(array_map(static fn ($s) => (float) $s['process_peak_rss_kib'] / 1024,
                    array_filter($samples, static fn ($s) => $s['process_peak_rss_kib'] !== null)));
                $stats = Support::statistics($times);
                $entries[] = [
                    'scenario' => $scenario, 'size' => $size, 'description' => Scenarios::all()[$scenario]['description'],
                    'sql' => Scenarios::sql($scenario, $fixtures . '/' . $size), 'result_rows' => $samples[0]['rows_output'],
                    'fingerprint_sampled' => $fp, 'stats' => $stats,
                    'php_peak_mib' => Support::statistics($phpPeak), 'rss_peak_mib' => Support::statistics($rssPeak),
                    'nominal_input_rows_per_second' => $size / ($stats['median'] / 1000),
                    'samples' => $samples,
                ];
                printf("median %.2f ms  p95 %.2f ms  PHP %.1f MiB\n", $stats['median'], $stats['p95'], Support::statistics($phpPeak)['median']);
            } catch (Throwable $e) {
                $errors[] = ['scenario' => $scenario, 'size' => $size, 'message' => $e->getMessage()];
                echo "FAILED: {$e->getMessage()}\n";
            }
            Report::save($output, $metadata, $entries, $errors);
        }
    }
    echo "\nReport: {$output}/summary.md\nJSON:   {$output}/results.json\n";
    if ($errors !== []) {
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
