<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationDropWorkerService
{
    public function __construct(private DriveApplication $app)
    {
    }

    public function syncPending(): array
    {
        return [
            'public_sources' => $this->guarded(
                fn(): array => (new FederationDropService($this->app))->syncPaidPublicSources(2),
                'FederationDrop no pudo materializar recursos públicos pagados en este ciclo.',
                'public source sync'
            ),
            'ingress' => $this->guarded(
                fn(): array => (new FederationDropService($this->app))->syncPaidIngressSources(2),
                'FederationDrop no pudo centralizar uploads ingress en este ciclo.',
                'ingress sync'
            ),
            'ingress_cleanup' => $this->guarded(
                fn(): array => (new FederationDropIngressService($this->app))->cleanupLocalStale(7, 20),
                'No se pudieron limpiar ingress temporales en este ciclo.',
                'ingress cleanup sync'
            ),
        ];
    }

    private function guarded(callable $operation, string $publicError, string $logLabel): array
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            error_log('[FederationDrop ' . $logLabel . '] ' . $e->getMessage());
            return [
                'degraded' => true,
                'error' => $publicError,
            ];
        }
    }
}
