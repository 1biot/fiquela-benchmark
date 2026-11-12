<?php

declare(strict_types=1);

namespace FiQueLaBench;

use InvalidArgumentException;
use RuntimeException;

final class Support
{
    /** @return array<string, string|bool> */
    public static function options(array $argv): array
    {
        $options = [];
        foreach (array_slice($argv, 1) as $argument) {
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException("Unsupported positional argument: {$argument}");
            }
            $pair = explode('=', substr($argument, 2), 2);
            $options[$pair[0]] = count($pair) === 1 ? true : $pair[1];
        }
        return $options;
    }

    public static function string(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? $default;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("--{$key} requires a non-empty value");
        }
        return $value;
    }

    public static function integer(array $options, string $key, int $default, int $minimum = 0): int
    {
        $value = self::string($options, $key, (string) $default);
        if (!ctype_digit($value) || (float) $value > PHP_INT_MAX || (int) $value < $minimum) {
            throw new InvalidArgumentException("--{$key} must be an integer >= {$minimum}");
        }
        return (int) $value;
    }

    /** @return list<int> */
    public static function sizes(array $options, string $default = '10000,100000'): array
    {
        $sizes = [];
        foreach (explode(',', self::string($options, 'sizes', $default)) as $size) {
            if (!ctype_digit(trim($size)) || (int) $size < 1) {
                throw new InvalidArgumentException('Invalid --sizes entry');
            }
            $sizes[] = (int) $size;
        }
        return array_values(array_unique($sizes));
    }

    public static function jsonFile(string $path, mixed $data): void
    {
        self::ensureDir(dirname($path));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory {$dir}");
        }
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function defaultFixtures(): string
    {
        return self::root() . '/fixtures';
    }

    public static function absolute(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        return getcwd() . '/' . $path;
    }

    /** @return array{exit_code: int, stdout: string, stderr: string, timed_out: bool} */
    public static function process(array $command, int $timeout = 3600, array $envOverrides = []): array
    {
        $pipes = [];
        $env = array_merge(getenv() ?: [], ['XDEBUG_MODE' => 'off'], $envOverrides);
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot start child PHP process');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $exit = -1;
        $timedOut = false;
        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) - $started > $timeout) {
                $timedOut = true;
                proc_terminate($proc);
                usleep(100000);
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, 9);
                }
                break;
            }
            usleep(10000);
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($proc);
        if ($exit === -1) {
            $exit = $closed;
        }
        return ['exit_code' => $exit, 'stdout' => trim($stdout), 'stderr' => trim($stderr), 'timed_out' => $timedOut];
    }

    /** @return array<string, mixed> */
    public static function workerResult(array $command, int $timeout = 3600, array $env = []): array
    {
        $result = self::process($command, $timeout, $env);
        if ($result['exit_code'] !== 0 || $result['timed_out']) {
            throw new RuntimeException(sprintf(
                'Worker failed (exit %d%s): %s %s',
                $result['exit_code'],
                $result['timed_out'] ? ', timeout' : '',
                $result['stderr'],
                $result['stdout']
            ));
        }
        try {
            $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException("Worker did not return JSON: " . substr($result['stdout'], 0, 500), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid worker response');
        }
        return $decoded;
    }

    /** @param list<float> $data */
    public static function statistics(array $data): array
    {
        if ($data === []) {
            return [];
        }
        sort($data, SORT_NUMERIC);
        $count = count($data);
        $average = array_sum($data) / $count;
        $variance = 0.0;
        foreach ($data as $value) {
            $variance += ($value - $average) ** 2;
        }
        return [
            'n' => $count,
            'median' => self::percentile($data, .5),
            'p95' => self::percentile($data, .95),
            'mean' => $average,
            'stdev' => sqrt($variance / $count),
            'min' => $data[0],
            'max' => $data[$count - 1],
        ];
    }

    /** @param list<float> $sorted */
    private static function percentile(array $sorted, float $p): float
    {
        $position = (count($sorted) - 1) * $p;
        $low = (int) floor($position);
        $high = (int) ceil($position);
        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($position - $low);
    }
}
