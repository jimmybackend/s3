<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use OTPHP\TOTP;
use RuntimeException;

final class PersonalTotpService
{
    public function __construct(private PersonalAwsConfig $config)
    {
    }

    /** @return array<int,array{id:string,label:string}> */
    public function accounts(): array
    {
        return $this->config->publicAccounts();
    }

    /** @return array{account_id:string,label:string,code:string,remaining:int,note:string} */
    public function generate(string $accountId): array
    {
        $accountId = trim($accountId);
        $account = $this->config->account($accountId);

        if ($account === null) {
            throw new RuntimeException('Cuenta TOTP no disponible.');
        }

        $totp = TOTP::create($account['secret']);
        $totp->setLabel($account['label']);
        $totp->setIssuer($this->config->issuer());

        return [
            'account_id' => $accountId,
            'label' => $account['label'],
            'code' => $totp->now(),
            'remaining' => 30 - (time() % 30),
            'note' => $account['note'],
        ];
    }
}
