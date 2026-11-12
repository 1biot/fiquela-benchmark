<?php

declare(strict_types=1);

namespace FiQueLaBench;

use InvalidArgumentException;

final class Scenarios
{
    /** @return array<string, array{description:string, format:string, group:string, expected:string, sql:string|null}> */
    public static function all(): array
    {
        return [
            'csv_scan' => [
                'description' => 'Full CSV scan / SELECT *', 'format' => 'csv', 'group' => 'default', 'expected' => 'all',
                'sql' => 'SELECT * FROM csv({products_csv}).*',
            ],
            'csv_projection' => [
                'description' => 'CSV projection of two columns', 'format' => 'csv', 'group' => 'default', 'expected' => 'all',
                'sql' => 'SELECT id, price FROM csv({products_csv}).*',
            ],
            'csv_where' => [
                'description' => 'Selective WHERE on CSV', 'format' => 'csv', 'group' => 'default', 'expected' => 'price',
                'sql' => 'SELECT id, name, price FROM csv({products_csv}).* WHERE price > 200',
            ],
            'csv_functions' => [
                'description' => 'Scalar expressions and function calls', 'format' => 'csv', 'group' => 'default', 'expected' => 'price',
                'sql' => 'SELECT id, UPPER(name) AS upper_name, ROUND(price * 1.21, 2) AS gross FROM csv({products_csv}).* WHERE price > 200',
            ],
            'csv_group' => [
                'description' => 'Group by and aggregation', 'format' => 'csv', 'group' => 'default', 'expected' => 'categories',
                'sql' => 'SELECT category_id, COUNT(id) AS num_items, SUM(price) AS revenue FROM csv({products_csv}).* GROUP BY category_id',
            ],
            'csv_distinct' => [
                'description' => 'DISTINCT on repeating keys', 'format' => 'csv', 'group' => 'default', 'expected' => 'categories',
                'sql' => 'SELECT DISTINCT category_id FROM csv({products_csv}).*',
            ],
            'csv_topk' => [
                'description' => 'ORDER BY price DESC LIMIT 100', 'format' => 'csv', 'group' => 'default', 'expected' => 'top100',
                'sql' => 'SELECT id, price FROM csv({products_csv}).* ORDER BY price DESC LIMIT 100',
            ],
            'csv_join' => [
                'description' => 'LEFT JOIN CSV with 50-row lookup', 'format' => 'csv', 'group' => 'default', 'expected' => 'all',
                'sql' => 'SELECT p.id, p.name, c.label AS category FROM csv({products_csv}).* AS p LEFT JOIN csv({categories_csv}).* AS c ON p.category_id = c.id',
            ],
            'csv_union_all' => [
                'description' => 'Two-branch UNION ALL', 'format' => 'csv', 'group' => 'advanced', 'expected' => 'all',
                'sql' => 'SELECT id, price FROM csv({products_csv}).* WHERE price <= 200 UNION ALL SELECT id, price FROM csv({products_csv}).* WHERE price > 200',
            ],
            'csv_full_sort' => [
                'description' => 'Full ORDER BY (memory-intensive)', 'format' => 'csv', 'group' => 'heavy', 'expected' => 'all',
                'sql' => 'SELECT id, name, price FROM csv({products_csv}).* ORDER BY price DESC',
            ],
            'xml_scan' => [
                'description' => 'Stream XML SHOPITEM', 'format' => 'xml', 'group' => 'default', 'expected' => 'all',
                'sql' => 'SELECT id, name, price FROM xml({products_xml}).SHOP.SHOPITEM',
            ],
            'xml_where' => [
                'description' => 'Filter XML SHOPITEM', 'format' => 'xml', 'group' => 'default', 'expected' => 'price',
                'sql' => 'SELECT id, name, price FROM xml({products_xml}).SHOP.SHOPITEM WHERE price > 200',
            ],
            'ndjson_where' => [
                'description' => 'NDJSON streaming and WHERE', 'format' => 'ndjson', 'group' => 'default', 'expected' => 'price',
                'sql' => 'SELECT id, name, price FROM ndJson({products_ndjson}).* WHERE price > 200',
            ],
            'jsonstream_where' => [
                'description' => 'JSON streaming and WHERE', 'format' => 'json', 'group' => 'default', 'expected' => 'price',
                'sql' => 'SELECT id, name, price FROM jsonFile({products_json}).data.items WHERE price > 200',
            ],
            'ndjson_nested' => [
                'description' => 'Nested field accessor (NDJSON)', 'format' => 'ndjson', 'group' => 'advanced', 'expected' => 'all',
                'sql' => 'SELECT id, meta.region AS region FROM ndJson({products_ndjson}).*',
            ],
            'native_csv_scan' => [
                'description' => 'Reference PHP fgetcsv loop only (not semantically equivalent)', 'format' => 'csv', 'group' => 'reference', 'expected' => 'all',
                'sql' => null,
            ],
        ];
    }

    /** @return list<string> */
    public static function select(string $names): array
    {
        $all = self::all();
        if ($names === 'default') {
            return array_keys(array_filter($all, static fn (array $v): bool => $v['group'] === 'default'));
        }
        if ($names === 'all') {
            return array_keys(array_filter($all, static fn (array $v): bool => $v['group'] !== 'reference'));
        }
        $selected = array_values(array_unique(array_map('trim', explode(',', $names))));
        foreach ($selected as $name) {
            if (!isset($all[$name])) {
                throw new InvalidArgumentException("Unknown scenario: {$name}");
            }
        }
        return $selected;
    }

    public static function sql(string $scenario, string $directory): ?string
    {
        $entry = self::all()[$scenario] ?? throw new InvalidArgumentException("Unknown scenario {$scenario}");
        if ($entry['sql'] === null) {
            return null;
        }
        $directory = rtrim($directory, '/\\');
        $replacements = [];
        foreach (['csv', 'xml', 'ndjson', 'json'] as $extension) {
            $replacements['{products_' . $extension . '}'] = $directory . '/products.' . $extension;
        }
        $replacements['{categories_csv}'] = $directory . '/categories.csv';
        return strtr($entry['sql'], $replacements);
    }

    public static function expectedCount(string $scenario, int $size): int
    {
        $expectation = self::all()[$scenario]['expected'] ?? throw new InvalidArgumentException("Unknown {$scenario}");
        return match ($expectation) {
            'all' => $size,
            'price' => FixtureGenerator::acceptedByPrice($size),
            'categories' => min(50, $size),
            'top100' => min(100, $size),
            default => throw new InvalidArgumentException("Unknown expectation {$expectation}"),
        };
    }
}
