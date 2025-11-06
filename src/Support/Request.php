<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $files;

    private function __construct(array $query, array $body, array $server, array $files)
    {
        $this->query  = $query;
        $this->body   = $body;
        $this->server = $server;
        $this->files  = $files;
    }

    public static function capture(): self
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $body = $_POST;

        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '[]', true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $body = $decoded;
            } else {
                $body = [];
            }
        }

        return new self($_GET, $body, $_SERVER, $_FILES);
    }

    public function method(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri = (string)($this->server['REQUEST_URI'] ?? '/');
        $question = strpos($uri, '?');
        if ($question !== false) {
            $uri = substr($uri, 0, $question);
        }

        $scriptName = $this->server['SCRIPT_NAME'] ?? '';
        if ($scriptName && str_starts_with($uri, $scriptName)) {
            $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            $suffix = substr($uri, strlen($scriptName));
            if ($scriptDir === '') {
                $uri = $suffix ?: '/';
            } else {
                $uri = $scriptDir . ($suffix ?: '');
            }
        }

        if (isset($this->server['PATH_INFO']) && $this->server['PATH_INFO'] !== '') {
            $pathInfo = (string)$this->server['PATH_INFO'];
            if (str_contains($scriptName, '.php')) {
                $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
                $uri = ($scriptDir ? $scriptDir : '') . '/' . ltrim($pathInfo, '/');
            } else {
                $uri = $pathInfo;
            }
        }

        return '/' . ltrim($uri, '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queries(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function inputs(): array
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $needle = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$needle])) {
            return (string)$this->server[$needle];
        }

        if (isset($this->server[$name])) {
            return (string)$this->server[$name];
        }

        return $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if (!$header) {
            return null;
        }
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        return trim(substr($header, 7));
    }

    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('Accept', ''));
        return str_contains($accept, 'application/json');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }
}