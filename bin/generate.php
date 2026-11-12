#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support.php';
require dirname(__DIR__) . '/src/FixtureGenerator.php';

use FiQueLaBench\FixtureGenerator;
use FiQueLaBench\Support;

try {
    $options = Support::options($argv);
    $base = Support::absolute(Support::string($options, 'fixtures', Support::defaultFixtures()));
    foreach (Support::sizes($options) as $size) {
        $begin = microtime(true);
        $path = FixtureGenerator::generate($size, $base, isset($options['force']));
        printf("Generated/verified %d rows at %s (%.2fs)\n", $size, $path, microtime(true) - $begin);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
