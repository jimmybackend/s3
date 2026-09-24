<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationDropAccountRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function upsertGoogle(
        string $issuer,
        string $subject,
        string $email,
        string $displayName,
        string $pictureUrl
    ): array {
        $identityHash = hash('sha256', $issuer . "\0" . $subject);
        $accountId = 'fda_' . substr(
            hash('sha256', "federationdrop-account\0" . $issuer . "\0" . $subject),
            0,
            48
        );

        $stmt = $this->db->prepare(
            "INSERT INTO FederationDropAccounts
                (AccountId, PrimaryEmail, DisplayName, PictureUrl, Status, CreatedAt, LastLoginAt, UpdatedAt)
             VALUES (?, ?, ?, ?, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                PrimaryEmail=VALUES(PrimaryEmail),
                DisplayName=VALUES(DisplayName),
                PictureUrl=VALUES(PictureUrl),
                LastLoginAt=UTC_TIMESTAMP(6),
                UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la cuenta Google FederationDrop.', 500);
        $stmt->bind_param('ssss', $accountId, $email, $displayName, $pictureUrl);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar la cuenta Google FederationDrop: ' . $message, 500);
        }
        $stmt->close();

        $provider = 'google';
        $verified = 1;
        $identity = $this->db->prepare(
            "INSERT INTO FederationDropIdentities
                (IdentityHash, AccountId, Provider, Issuer, ProviderSubject, Email, EmailVerified,
                 CreatedAt, LastLoginAt, UpdatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                AccountId=VALUES(AccountId),
                Email=VALUES(Email),
                EmailVerified=VALUES(EmailVerified),
                LastLoginAt=UTC_TIMESTAMP(6),
                UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$identity) throw new FederationException('No se pudo preparar la identidad Google FederationDrop.', 500);
        $identity->bind_param(
            'ssssssi',
            $identityHash,
            $accountId,
            $provider,
            $issuer,
            $subject,
            $email,
            $verified
        );
        if (!$identity->execute()) {
            $message = $identity->error;
            $identity->close();
            throw new FederationException('No se pudo guardar la identidad Google FederationDrop: ' . $message, 500);
        }
        $identity->close();

        $account = $this->findActive($accountId);
        if ($account === null) {
            throw new FederationException('La cuenta Google FederationDrop no quedó activa.', 500);
        }
        return $account + [
            'identity_hash' => $identityHash,
            'issuer' => $issuer,
            'subject' => $subject,
            'provider' => 'google',
        ];
    }

    public function findActive(string $accountId): ?array
    {
        if (!preg_match('/\Afda_[a-f0-9]{48}\z/', $accountId)) return null;

        $stmt = $this->db->prepare(
            "SELECT AccountId, PrimaryEmail, DisplayName, PictureUrl, Status, CreatedAt, LastLoginAt, UpdatedAt
             FROM FederationDropAccounts
             WHERE AccountId=? AND Status='active'
             LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar la cuenta FederationDrop.', 500);
        $stmt->bind_param('s', $accountId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo consultar la cuenta FederationDrop: ' . $message, 500);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) return null;
        return [
            'account_id' => (string)$row['AccountId'],
            'email' => (string)$row['PrimaryEmail'],
            'name' => is_string($row['DisplayName'] ?? null) ? (string)$row['DisplayName'] : '',
            'picture' => is_string($row['PictureUrl'] ?? null) ? (string)$row['PictureUrl'] : '',
            'status' => (string)$row['Status'],
            'created_at' => (string)$row['CreatedAt'],
            'last_login_at' => (string)$row['LastLoginAt'],
            'updated_at' => (string)$row['UpdatedAt'],
        ];
    }

    public function identityMatches(string $accountId, string $issuer, string $subject): bool
    {
        $hash = hash('sha256', $issuer . "\0" . $subject);
        $stmt = $this->db->prepare(
            "SELECT 1
             FROM FederationDropIdentities
             WHERE IdentityHash=? AND AccountId=? AND Provider='google' AND EmailVerified=1
             LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo validar la identidad FederationDrop.', 500);
        $stmt->bind_param('ss', $hash, $accountId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new FederationException('No se pudo validar la identidad FederationDrop.', 500);
        }
        $found = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $found;
    }
}
