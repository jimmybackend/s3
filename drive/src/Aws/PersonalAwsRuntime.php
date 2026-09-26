<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use ArcadeCloud\Drive\Security\PersonalToolAccessService;
use ArcadeCloud\Drive\Security\SessionManager;

final class PersonalAwsRuntime
{
    private PersonalAwsConfig $config;
    private SessionManager $session;
    private ?PersonalToolAccessService $access = null;
    private ?PersonalTotpService $totp = null;

    public function __construct(?string $configPath = null)
    {
        $path = trim((string)($configPath ?? getenv('ARCADECLOUD_PERSONAL_AWS_CONFIG') ?: ''));
        if ($path === '') {
            $path = '/etc/arcadecloud-drive/personal-aws.json';
        }

        $this->config = new PersonalAwsConfig($path);
        $this->session = new SessionManager();
    }

    public function config(): PersonalAwsConfig
    {
        return $this->config;
    }

    public function access(): PersonalToolAccessService
    {
        return $this->access ??= new PersonalToolAccessService(
            $this->session,
            $this->config,
            1
        );
    }

    public function totp(): PersonalTotpService
    {
        return $this->totp ??= new PersonalTotpService($this->config);
    }

    public function ec2Gateway(?string $region = null): Ec2Gateway
    {
        return new Ec2Gateway($region ?? \Config::getRegion());
    }

    public function rdsGateway(?string $region = null): RdsGateway
    {
        return new RdsGateway($region ?? \Config::getRegion());
    }
}
