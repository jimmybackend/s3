<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\System;

final class Ec2InstanceIdentityService
{
    private const IMDS_BASE = 'http://169.254.169.254/latest';

    /** @return array{instance_id:string,region:string,availability_zone:string} */
    public function current(): array
    {
        if (!function_exists('curl_init')) {
            return $this->emptyIdentity();
        }

        $token = $this->request(
            self::IMDS_BASE . '/api/token',
            [],
            true
        );
        if ($token === '') {
            return $this->emptyIdentity();
        }

        $headers = ['X-aws-ec2-metadata-token: ' . $token];
        $instanceId = $this->request(self::IMDS_BASE . '/meta-data/instance-id', $headers);
        $availabilityZone = $this->request(
            self::IMDS_BASE . '/meta-data/placement/availability-zone',
            $headers
        );

        $region = '';
        $document = $this->request(
            self::IMDS_BASE . '/dynamic/instance-identity/document',
            $headers
        );
        if ($document !== '') {
            $decoded = json_decode($document, true);
            if (is_array($decoded)) {
                $region = trim((string)($decoded['region'] ?? ''));
            }
        }

        if (
            $region === ''
            && preg_match('/^([a-z]{2}(?:-gov)?-[a-z]+-\d)[a-z]$/', $availabilityZone, $match)
        ) {
            $region = $match[1];
        }

        if (!preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)) {
            $instanceId = '';
        }
        if (!preg_match('/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region)) {
            $region = '';
        }

        return [
            'instance_id' => $instanceId,
            'region' => $region,
            'availability_zone' => $availabilityZone,
        ];
    }

    /** @return array{instance_id:string,region:string,availability_zone:string} */
    private function emptyIdentity(): array
    {
        return [
            'instance_id' => '',
            'region' => '',
            'availability_zone' => '',
        ];
    }

    private function request(string $url, array $headers = [], bool $putToken = false): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return '';
        }

        if ($putToken) {
            $headers[] = 'X-aws-ec2-metadata-token-ttl-seconds: 60';
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS => 500,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($putToken) {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
        }

        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return is_string($body) && $status >= 200 && $status < 300
            ? trim($body)
            : '';
    }
}
