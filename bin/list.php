#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Scenarios.php';

use FiQueLaBench\Scenarios;

printf("%-21s %-11s %-9s %s\n", 'Scenario', 'Group', 'Format', 'Purpose');
foreach (Scenarios::all() as $name => $entry) {
    printf("%-21s %-11s %-9s %s\n", $name, $entry['group'], $entry['format'], $entry['description']);
}
