<?php

declare(strict_types=1);

namespace TechRecruit\Security;

final class RateLimiter
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = rtrim(
            $storageDir ?? dirname(__DIR__, 2) . '/storage/rate-limit',
            '/'
        );
    }

    /**
     * @return array{allowed:bool,retry_after:int,remaining:int,attempts:int,blocked_until:int}
     */
    public function consume(
        string $scope,
        string $key,
        int $maxAttempts,
        int $windowSeconds,
        int $blockSeconds = 0
    ): array {
        if ($maxAttempts < 1 || $windowSeconds < 1) {
            return [
                'allowed' => true,
                'retry_after' => 0,
                'remaining' => max(0, $maxAttempts),
                'attempts' => 0,
                'blocked_until' => 0,
            ];
        }

        $effectiveBlockSeconds = $blockSeconds > 0 ? $blockSeconds : $windowSeconds;

        $result = $this->withStateLock($scope, $key, function (array $state, int $now) use ($maxAttempts, $windowSeconds, $effectiveBlockSeconds): array {
            $state['attempts'] = (int) ($state['attempts'] ?? 0);
            $state['window_started_at'] = (int) ($state['window_started_at'] ?? $now);
            $state['blocked_until'] = (int) ($state['blocked_until'] ?? 0);

            if ($state['blocked_until'] > $now) {
                $retryAfter = max(1, $state['blocked_until'] - $now);

                return [
                    'state' => $state,
                    'result' => [
                        'allowed' => false,
                        'retry_after' => $retryAfter,
                        'remaining' => 0,
                        'attempts' => $state['attempts'],
                        'blocked_until' => $state['blocked_until'],
                    ],
                ];
            }

            if (($now - $state['window_started_at']) >= $windowSeconds) {
                $state['attempts'] = 0;
                $state['window_started_at'] = $now;
                $state['blocked_until'] = 0;
            }

            $state['attempts']++;
            $remaining = max(0, $maxAttempts - $state['attempts']);
            $allowed = $state['attempts'] <= $maxAttempts;

            if (!$allowed) {
                $state['blocked_until'] = max($state['blocked_until'], $now + $effectiveBlockSeconds);
            }

            $retryAfter = !$allowed ? max(1, $state['blocked_until'] - $now) : 0;

            return [
                'state' => $state,
                'result' => [
                    'allowed' => $allowed,
                    'retry_after' => $retryAfter,
                    'remaining' => $remaining,
                    'attempts' => $state['attempts'],
                    'blocked_until' => $state['blocked_until'],
                ],
            ];
        });

        if (random_int(1, 100) === 1) {
            $this->gc();
        }

        return $result;
    }

    /**
     * @return array{blocked:bool,retry_after:int,blocked_until:int}
     */
    public function blocked(string $scope, string $key): array
    {
        return $this->withStateLock($scope, $key, static function (array $state, int $now): array {
            $blockedUntil = (int) ($state['blocked_until'] ?? 0);
            $isBlocked = $blockedUntil > $now;

            return [
                'state' => $state,
                'result' => [
                    'blocked' => $isBlocked,
                    'retry_after' => $isBlocked ? max(1, $blockedUntil - $now) : 0,
                    'blocked_until' => $blockedUntil,
                ],
            ];
        });
    }

    public function clear(string $scope, string $key): void
    {
        $filePath = $this->stateFilePath($scope, $key);

        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0770, true);
        }
    }

    private function gc(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }

        $threshold = time() - 172800;

        foreach (glob($this->storageDir . '/*.json') ?: [] as $filePath) {
            $lastModified = @filemtime($filePath);

            if ($lastModified === false || $lastModified >= $threshold) {
                continue;
            }

            @unlink($filePath);
        }
    }

    /**
     * @param callable(array<string, int>, int): array{state:array<string, int>,result:array<string, mixed>} $callback
     * @return array<string, mixed>
     */
    private function withStateLock(string $scope, string $key, callable $callback): array
    {
        $this->ensureStorageDirectory();

        $filePath = $this->stateFilePath($scope, $key);
        $handle = fopen($filePath, 'c+');

        if ($handle === false) {
            throw new \RuntimeException('Falha ao abrir arquivo de rate limit.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Falha ao travar arquivo de rate limit.');
            }

            $state = $this->readState($handle);
            $now = time();
            $next = $callback($state, $now);
            $nextState = is_array($next['state'] ?? null) ? $next['state'] : $state;
            $nextState['updated_at'] = $now;

            $this->writeState($handle, $nextState);
            flock($handle, LOCK_UN);

            return is_array($next['result'] ?? null) ? $next['result'] : [];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, int>
     */
    private function readState($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if (!is_string($raw) || trim($raw) === '') {
            return [
                'attempts' => 0,
                'window_started_at' => 0,
                'blocked_until' => 0,
                'updated_at' => 0,
            ];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [
                'attempts' => 0,
                'window_started_at' => 0,
                'blocked_until' => 0,
                'updated_at' => 0,
            ];
        }

        return [
            'attempts' => (int) ($decoded['attempts'] ?? 0),
            'window_started_at' => (int) ($decoded['window_started_at'] ?? 0),
            'blocked_until' => (int) ($decoded['blocked_until'] ?? 0),
            'updated_at' => (int) ($decoded['updated_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, int> $state
     */
    private function writeState($handle, array $state): void
    {
        rewind($handle);
        ftruncate($handle, 0);

        $payload = json_encode($state, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            $payload = '{}';
        }

        fwrite($handle, $payload);
        fflush($handle);
    }

    private function stateFilePath(string $scope, string $key): string
    {
        $safeScope = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower(trim($scope))) ?: 'scope';
        $hash = hash('sha256', trim($scope) . '|' . trim($key));

        return $this->storageDir . '/' . $safeScope . '-' . $hash . '.json';
    }
}
