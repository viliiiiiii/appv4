<?php
declare(strict_types=1);

namespace App\Bootstrap;

final class AppBootstrap
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        require_once __DIR__ . '/../../helpers.php';

        self::$booted = true;

        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
    }
}