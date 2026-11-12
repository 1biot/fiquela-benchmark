<?php

declare(strict_types=1);

namespace FiQueLaBench;

use RuntimeException;

final class Bootstrap
{
    public static function autoloadFiQueLa(): void
    {
        // If benchmarking an edited local checkout, set FIQUELA_AUTOLOAD=/absolute/path/vendor/autoload.php.
        $override = getenv('FIQUELA_AUTOLOAD');
        if ($override !== false && $override !== '') {
            if (!is_file($override)) {
                throw new RuntimeException("FIQUELA_AUTOLOAD does not exist: {$override}");
            }
            require_once $override;
        } else {
            $paths = [Support::root() . '/vendor/autoload.php', dirname(Support::root()) . '/FiQueLa/vendor/autoload.php'];
            foreach ($paths as $path) {
                if (is_file($path)) {
                    require_once $path;
                    break;
                }
            }
        }
        if (!class_exists(\FQL\Query\Provider::class)) {
            throw new RuntimeException('FiQueLa not found. Run composer install or set FIQUELA_AUTOLOAD.');
        }
    }
}
