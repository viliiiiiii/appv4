<?php
declare(strict_types=1);

namespace App\Support;

final class Cache
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?: sys_get_temp_dir() . '/punchlist-cache';
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }

    private function path(string $key): string
    {
        $hash = sha1($key);
        return $this->directory . '/' . $hash . '.cache.php';
    }

    public function remember(string $key, int $seconds, callable $callback): mixed
    {
        $path = $this->path($key);
        if (is_file($path)) {
            $payload = @unserialize((string)file_get_contents($path));
            if (is_array($payload)) {
                [$expires, $value] = $payload;
                if ($expires === 0 || $expires >= time()) {
                    return $value;
                }
            }
        }

        $value = $callback();
        $this->put($key, $value, $seconds);

        return $value;
    }

    public function put(string $key, mixed $value, int $seconds): void
    {
        $path = $this->path($key);
        $expires = $seconds > 0 ? time() + $seconds : 0;
        file_put_contents($path, serialize([$expires, $value]), LOCK_EX);
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}