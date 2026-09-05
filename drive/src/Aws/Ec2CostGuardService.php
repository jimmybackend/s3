<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use DateTimeImmutable;
use DateTimeZone;

final class Ec2CostGuardService
{
    /** @var array<int,string> */
    private array $mustRunIds;

    /** @var array<int,string> */
    private array $priorityIds;

    public function __construct(
        private Ec2Gateway $ec2,
        private Ec2CronLogger $logger,
        private DateTimeZone $timezone,
        array $mustRunIds,
        ?array $priorityIds = null,
        private int $allowOnFromHour = 8,
        private int $stopAtHour = 16,
        private int $forceAtHour = 17
    ) {
        $this->mustRunIds = array_values(array_unique(array_filter(array_map('strval', $mustRunIds))));
        $this->priorityIds = $priorityIds === null
            ? $this->mustRunIds
            : array_values(array_unique(array_filter(array_map('strval', $priorityIds))));
    }

    public function run(): void
    {
        $now = new DateTimeImmutable('now', $this->timezone);
        $instances = $this->ec2->listInstancesById();

        $this->ensurePrimaryInstances($instances);
        $this->stopSecondaryInstancesIfNeeded($instances, $now);
    }

    /** @param array<string,array<string,mixed>> $instances */
    private function ensurePrimaryInstances(array $instances): void
    {
        foreach ($this->mustRunIds as $instanceId) {
            if (!isset($instances[$instanceId])) {
                $this->logger->log('ERROR: la instancia principal ' . $instanceId . ' no fue encontrada.');
                continue;
            }

            $state = Ec2Gateway::stateName($instances[$instanceId]);
            if (Ec2Gateway::isRunningLike($state)) {
                continue;
            }

            $this->logger->log(
                'PRIMARIA ' . $instanceId .
                " detectada en estado '{$state}'. Intentando encender."
            );

            $this->ec2->start($instanceId);

            $this->logger->log(
                'PRIMARIA ' . $instanceId .
                ' comando START enviado correctamente.'
            );
        }
    }

    /** @param array<string,array<string,mixed>> $instances */
    private function stopSecondaryInstancesIfNeeded(array $instances, DateTimeImmutable $now): void
    {
        if (!$this->secondaryMustBeOffNow($now)) {
            return;
        }

        $force = $this->shouldForceNow($now);

        foreach ($instances as $instanceId => $instance) {
            if (in_array($instanceId, $this->priorityIds, true)) {
                continue;
            }

            $state = Ec2Gateway::stateName($instance);
            if (!Ec2Gateway::isRunningLike($state)) {
                continue;
            }

            $this->logger->log(
                'SECUNDARIA ' . $instanceId .
                " detectada en estado '{$state}' fuera de horario (" . $now->format('H:i') . '). ' .
                'Intentando apagar' . ($force ? ' con FORCE=true' : ' con FORCE=false') . '.'
            );

            $this->ec2->stop($instanceId, $force);

            $this->logger->log(
                'SECUNDARIA ' . $instanceId .
                ' comando STOP enviado correctamente' . ($force ? ' con FORCE=true.' : '.')
            );
        }
    }

    private function secondaryMustBeOffNow(DateTimeImmutable $now): bool
    {
        $hour = (int)$now->format('H');

        return $hour < $this->allowOnFromHour || $hour >= $this->stopAtHour;
    }

    private function shouldForceNow(DateTimeImmutable $now): bool
    {
        return (int)$now->format('H') >= $this->forceAtHour;
    }
}
