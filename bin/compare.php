#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support.php';

use FiQueLaBench\Support;

try {
    $opt = Support::options($argv);
    $baseline = Support::absolute(Support::string($opt, 'baseline', ''));
    $candidate = Support::absolute(Support::string($opt, 'candidate', ''));
    $metric = Support::string($opt, 'metric', 'time');
    [$field, $unit] = match ($metric) {
        'time' => ['stats', 'ms'],
        'php-peak' => ['php_peak_mib', 'MiB'],
        'rss-peak' => ['rss_peak_mib', 'MiB'],
        default => throw new InvalidArgumentException('--metric must be time, php-peak or rss-peak'),
    };
    $rawThreshold = Support::string($opt, 'threshold', '10');
    if (!is_numeric($rawThreshold)) {
        throw new InvalidArgumentException('Invalid --threshold');
    }
    $threshold = (float) $rawThreshold;
    if (!is_finite($threshold) || $threshold < 0 || $threshold > 1000) {
        throw new InvalidArgumentException('Invalid --threshold');
    }
    foreach ([$baseline, $candidate] as $path) {
        if (is_dir($path)) {
            $path .= '/results.json';
        }
        if (!is_file($path)) {
            throw new RuntimeException("Missing benchmark JSON at {$path}");
        }
        $files[] = $path;
    }
    $left = json_decode((string) file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
    $right = json_decode((string) file_get_contents($files[1]), true, 512, JSON_THROW_ON_ERROR);
    $indexes = [];
    foreach ([0 => $left, 1 => $right] as $side => $data) {
        foreach ($data['entries'] ?? [] as $entry) {
            $indexes[$side][$entry['scenario'] . ':' . $entry['size']] = $entry;
        }
    }
    $sameEnv = ($left['metadata']['php_version'] ?? '') === ($right['metadata']['php_version'] ?? '')
        && ($left['metadata']['host'] ?? '') === ($right['metadata']['host'] ?? '');
    if (!$sameEnv) {
        fwrite(STDERR, "WARNING: Different PHP version or host; comparison may not be meaningful.\n");
    }
    printf("%-28s %12s %12s %11s %s\n", 'Scenario:rows', "Baseline {$unit}", "Current {$unit}", 'Delta', 'Status');
    $regressions = 0;
    $count = 0;
    foreach ($indexes[1] ?? [] as $key => $current) {
        if (!isset($indexes[0][$key])) {
            continue;
        }
        $base = $indexes[0][$key];
        if (!isset($base[$field]['median'], $current[$field]['median'])) {
            fwrite(STDERR, "WARNING: {$metric} is unavailable in one or both reports for {$key}.\n");
            continue;
        }
        $before = (float) $base[$field]['median'];
        $after = (float) $current[$field]['median'];
        if ($before <= 0) {
            continue;
        }
        $delta = ($after / $before - 1) * 100;
        $status = $delta > $threshold ? 'REGRESSION' : ($delta < -$threshold ? 'IMPROVED' : 'stable');
        if ($status === 'REGRESSION') {
            $regressions++;
        }
        $count++;
        printf("%-28s %12.2f %12.2f %+10.1f%% %s\n", $key, $before, $after, $delta, $status);
    }
    if ($count === 0) {
        throw new RuntimeException("No matching scenario+size pairs with comparable {$metric} measurements");
    }
    printf("\nCompared %d pairs; %d regressions beyond %.1f%% threshold.\n", $count, $regressions, $threshold);
    if ($regressions && isset($opt['fail-on-regression'])) {
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
