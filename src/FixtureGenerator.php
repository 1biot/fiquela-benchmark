<?php

declare(strict_types=1);

namespace FiQueLaBench;

use RuntimeException;

final class FixtureGenerator
{
    public const VERSION = 1;
    private const CATEGORIES = 50;

    public static function priceCents(int $i): int
    {
        return 1000 + (($i * 37) % 49000);
    }

    public static function acceptedByPrice(int $rows, int $thresholdCents = 20000): int
    {
        $accepted = 0;
        for ($i = 1; $i <= $rows; $i++) {
            if (self::priceCents($i) > $thresholdCents) {
                $accepted++;
            }
        }
        return $accepted;
    }

    public static function generate(int $rows, string $base, bool $force = false): string
    {
        if ($rows < 1) {
            throw new RuntimeException('rows must be >= 1');
        }
        $directory = rtrim($base, '/\\') . '/' . $rows;
        Support::ensureDir($directory);
        $manifestPath = $directory . '/manifest.json';
        $targetNames = ['products.csv', 'products.xml', 'products.ndjson', 'products.json', 'categories.csv'];
        if (!$force && is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && ($manifest['version'] ?? null) === self::VERSION && ($manifest['rows'] ?? null) === $rows) {
                $present = true;
                foreach ($targetNames as $name) {
                    if (!is_file($directory . '/' . $name)
                        || !isset($manifest['files'][$name]['sha256'])
                        || hash_file('sha256', $directory . '/' . $name) !== $manifest['files'][$name]['sha256']) {
                        $present = false;
                    }
                }
                if ($present) {
                    return $directory;
                }
            }
        }

        $handles = [];
        $names = ['csv' => 'products.csv', 'xml' => 'products.xml', 'ndjson' => 'products.ndjson', 'json' => 'products.json'];
        try {
            foreach ($names as $key => $name) {
                $handle = fopen($directory . '/' . $name . '.tmp', 'wb');
                if ($handle === false) {
                    throw new RuntimeException("Cannot create fixture {$name}");
                }
                $handles[$key] = $handle;
            }
            fputcsv($handles['csv'], ['id', 'name', 'price', 'category_id', 'quantity', 'region'], ',', '"', '');
            fwrite($handles['xml'], "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<SHOP>\n");
            fwrite($handles['json'], '{"data":{"items":[');
            for ($i = 1; $i <= $rows; $i++) {
                $row = self::row($i);
                fputcsv($handles['csv'], array_values(array_intersect_key($row, array_flip(['id', 'name', 'price', 'category_id', 'quantity', 'region']))), ',', '"', '');
                $nested = $row + ['meta' => ['region' => $row['region'], 'tier' => $i % 5]];
                $json = json_encode($nested, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                fwrite($handles['ndjson'], $json . "\n");
                fwrite($handles['json'], ($i === 1 ? '' : ',') . $json);
                fwrite($handles['xml'], '  <SHOPITEM>'
                    . '<id>' . $row['id'] . '</id>'
                    . '<name>' . htmlspecialchars($row['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</name>'
                    . '<price>' . $row['price'] . '</price>'
                    . '<category_id>' . $row['category_id'] . '</category_id>'
                    . '<quantity>' . $row['quantity'] . '</quantity>'
                    . '<region>' . $row['region'] . '</region>'
                    . "</SHOPITEM>\n");
            }
            fwrite($handles['xml'], "</SHOP>\n");
            fwrite($handles['json'], "]}}\n");
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
        foreach ($names as $name) {
            if (!rename($directory . '/' . $name . '.tmp', $directory . '/' . $name)) {
                throw new RuntimeException("Cannot rename fixture {$name}");
            }
        }
        $categoriesPath = $directory . '/categories.csv';
        $categories = fopen($categoriesPath . '.tmp', 'wb');
        if ($categories === false) {
            throw new RuntimeException('Cannot create categories fixture');
        }
        try {
            fputcsv($categories, ['id', 'label'], ',', '"', '');
            for ($i = 0; $i < self::CATEGORIES; $i++) {
                fputcsv($categories, [$i, 'Category ' . $i], ',', '"', '');
            }
        } finally {
            fclose($categories);
        }
        if (!rename($categoriesPath . '.tmp', $categoriesPath)) {
            throw new RuntimeException('Cannot rename categories fixture');
        }
        $files = [];
        foreach ($targetNames as $name) {
            $files[$name] = ['bytes' => filesize($directory . '/' . $name), 'sha256' => hash_file('sha256', $directory . '/' . $name)];
        }
        Support::jsonFile($manifestPath, ['version' => self::VERSION, 'rows' => $rows, 'categories' => self::CATEGORIES, 'files' => $files]);
        return $directory;
    }

    /** @return array<string, int|float|string> */
    public static function row(int $i): array
    {
        return [
            'id' => $i,
            'name' => 'Item ' . str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT),
            'price' => self::priceCents($i) / 100,
            'category_id' => $i % self::CATEGORIES,
            'quantity' => ($i % 12) + 1,
            'region' => ['north', 'south', 'east', 'west'][$i % 4],
        ];
    }
}
