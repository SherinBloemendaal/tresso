<?php

declare(strict_types=1);

namespace App\Controller;

use App\Scan\DownloadPolicy;
use App\Scan\VirusScanner;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness and dependency check used by Docker and load balancers.
 *
 * `scanner` reports the virus scanner: ok, off (no CLAMAV_HOST: files are marked as not scanned), unreachable
 * (scans wait and retry), or unconfigured: ATTACHMENTS_REQUIRE_SCAN is on without a scanner, so no attachment could
 * ever be opened. Only that last one makes the API unhealthy: a misconfiguration has to show before users find it.
 */
final readonly class HealthController
{
    public function __construct(
        private Connection $connection,
        #[Autowire(env: 'REDIS_URL')]
        private string $redisUrl,
        private VirusScanner $scanner,
        private DownloadPolicy $downloads,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => $this->connection->executeQuery('SELECT 1')->fetchOne()),
            'redis' => $this->check(function (): void {
                $url = parse_url($this->redisUrl);
                $redis = new \Redis();
                $redis->connect($url['host'] ?? 'localhost', $url['port'] ?? 6379, 1.0);
                $redis->ping();
                $redis->close();
            }),
        ];

        $scanner = $this->scanner();
        $healthy = !\in_array(false, $checks, true) && 'unconfigured' !== $scanner;

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => [...array_map(static fn (bool $ok): string => $ok ? 'ok' : 'failing', $checks), 'scanner' => $scanner]],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function scanner(): string
    {
        return self::scannerState($this->scanner->isEnabled(), $this->downloads->requiresScan(), fn (): bool => $this->check(fn () => $this->scanner->engine()));
    }

    /**
     * @param callable(): bool $reachable
     */
    public static function scannerState(bool $enabled, bool $required, callable $reachable): string
    {
        if (!$enabled) {
            return $required ? 'unconfigured' : 'off';
        }

        return $reachable() ? 'ok' : 'unreachable';
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
