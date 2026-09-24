<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationSyncCycleService
{
    public function __construct(private DriveApplication $app)
    {
    }

    public function syncOnce(): array
    {
        $replicaPresence = $this->guarded(
            fn(): array => (new FederationReplicaPresenceService($this->app))->announce(),
            [
                'configured' => true,
                'status' => 'degraded',
                'available' => false,
                'error' => 'No se pudo actualizar la presencia de la réplica en este ciclo.',
            ],
            'replica presence'
        );

        $customs = $this->guarded(
            fn(): array => (new FederationCustomsService($this->app))->processNext(),
            [
                'degraded' => true,
                'error' => 'La Aduana FederationCloud no pudo procesar su siguiente petición.',
            ],
            'customs'
        );

        $result = (new FederationGossipService($this->app))->syncOnce();
        $result['replica_presence'] = $replicaPresence;
        $result['customs'] = $customs;

        $result['access_requests'] = $this->guarded(
            fn(): array => (new FederationAccessService($this->app))->syncPending(5),
            ['degraded' => true, 'error' => 'La cola privada de solicitudes no pudo procesarse en este ciclo.'],
            'access sync'
        );
        $result['replicas'] = $this->guarded(
            fn(): array => (new FederationReplicaService($this->app))->syncPending(3, 2),
            ['degraded' => true, 'error' => 'La cola de réplicas no pudo procesarse en este ciclo.'],
            'replica sync'
        );
        $result['share_imports'] = $this->guarded(
            fn(): array => (new FederationShareDriveService($this->app))->syncPending(1),
            ['degraded' => true, 'error' => 'La cola de Compartidos no pudo procesarse en este ciclo.'],
            'Share import sync'
        );
        $result['public_imports'] = $this->guarded(
            fn(): array => (new FederationPublicImportService($this->app))->syncPending(1),
            ['degraded' => true, 'error' => 'La cola pública a Mi Drive no pudo procesarse en este ciclo.'],
            'public import sync'
        );
        $result['federation_drop'] = (new FederationDropWorkerService($this->app))->syncPending();

        return $result;
    }

    private function guarded(callable $operation, array $fallback, string $label): array
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            error_log('[FederationCloud ' . $label . '] ' . $e->getMessage());
            return $fallback;
        }
    }
}
