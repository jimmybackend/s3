<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationDropStripeCheckoutService
{
    public function __construct(
        private FederationDropConfig $config,
        private FederationDropRepository $repository,
        private FederationDropStripeClient $client,
        private FederationDropStripeWebhookVerifier $verifier
    ) {
    }

    public function createCheckout(array $row): array
    {
        $this->config->assertReady();

        $dropId = (string)($row['DropId'] ?? '');
        $amount = (int)($row['AmountCents'] ?? -1);
        $currency = strtolower((string)($row['Currency'] ?? ''));
        $email = (string)($row['OwnerEmail'] ?? '');
        if (!preg_match('/\Afdp_[A-Za-z0-9_-]{16,80}\z/', $dropId)
            || $amount <= 0
            || !preg_match('/\A[a-z]{3}\z/', $currency)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new FederationException('Orden FederationDrop inválida para Stripe.', 500);
        }

        $metadata = [
            'arcadecloud_drop_id' => $dropId,
            'arcadecloud_quote_fingerprint' => $this->quoteFingerprint($row),
            'expected_size_bytes' => (string)(int)$row['ExpectedSizeBytes'],
            'retention_days' => (string)(int)$row['RetentionDays'],
            'max_downloads' => (string)(int)$row['MaxDownloads'],
            'source_domain' => substr((string)($row['SourceDomain'] ?? ''), 0, 255),
        ];

        $name = 'FederationDrop · ' . trim((string)$row['OriginalName']);
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 120);
        else $name = substr($name, 0, 120);

        $description = sprintf(
            '%d día(s) · hasta %d descarga(s) · %d byte(s)',
            (int)$row['RetentionDays'],
            (int)$row['MaxDownloads'],
            (int)$row['ExpectedSizeBytes']
        );

        $session = $this->client->createCheckoutSession([
            'mode' => 'payment',
            'success_url' => $this->config->publicUrl . '/?payment_return=' . rawurlencode($dropId)
                . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->config->publicUrl . '/?manage=' . rawurlencode($dropId),
            'client_reference_id' => $dropId,
            'customer_email' => $email,
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $amount,
                    'product_data' => [
                        'name' => $name,
                        'description' => $description,
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
        ]);

        return [
            'checkout_session_id' => (string)$session['id'],
            'url' => (string)$session['url'],
        ];
    }

    public function handleWebhook(string $rawBody, string $signatureHeader): array
    {
        $this->config->assertStripeWebhookReady();
        $event = $this->verifier->verify($rawBody, $signatureHeader);

        if (isset($event['livemode']) && is_bool($event['livemode'])
            && $event['livemode'] !== $this->client->expectedLiveMode()) {
            throw new FederationException('Stripe livemode no coincide con la clave configurada.', 400);
        }

        $type = (string)($event['type'] ?? '');
        return match ($type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->normalizeCheckout($event, 'paid'),
            'checkout.session.async_payment_failed' => $this->normalizeCheckout($event, 'failed'),
            'charge.refunded' => $this->normalizeRefund($event),
            default => [
                'accepted' => true,
                'processed' => false,
                'event_id' => (string)$event['id'],
                'event_type' => $type,
                'reason' => 'event-not-used',
            ],
        };
    }

    private function normalizeCheckout(array $event, string $status): array
    {
        $session = $event['data']['object'] ?? null;
        if (!is_array($session) || ($session['object'] ?? null) !== 'checkout.session') {
            throw new FederationException('Webhook Stripe no contiene Checkout Session válida.', 400);
        }

        $sessionId = (string)($session['id'] ?? '');
        if (!str_starts_with($sessionId, 'cs_') || (string)($session['mode'] ?? '') !== 'payment') {
            throw new FederationException('Checkout Session Stripe inválida para FederationDrop.', 400);
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $dropId = (string)($session['client_reference_id'] ?? '');
        $metadataDropId = (string)($metadata['arcadecloud_drop_id'] ?? '');
        if ($dropId === '' || !hash_equals($dropId, $metadataDropId)) {
            throw new FederationException('Stripe no coincide con la orden FederationDrop.', 400);
        }

        $row = $this->repository->find($dropId);
        if ($row === null) throw new FederationException('Orden FederationDrop de Stripe no encontrada.', 404);

        $fingerprint = (string)($metadata['arcadecloud_quote_fingerprint'] ?? '');
        if ($fingerprint === '' || !hash_equals($this->quoteFingerprint($row), $fingerprint)) {
            throw new FederationException('La cotización FederationDrop ligada a Stripe cambió.', 409);
        }

        $amount = (int)($session['amount_total'] ?? -1);
        $currency = strtolower((string)($session['currency'] ?? ''));
        if ($amount !== (int)$row['AmountCents'] || $currency !== strtolower((string)$row['Currency'])) {
            throw new FederationException('Importe o moneda Stripe no coincide con FederationDrop.', 409);
        }

        if ($status === 'paid' && (string)($session['payment_status'] ?? '') !== 'paid') {
            return [
                'accepted' => true,
                'processed' => false,
                'event_id' => (string)$event['id'],
                'event_type' => (string)($event['type'] ?? ''),
                'reason' => 'payment-not-paid',
            ];
        }

        $paymentIntent = (string)($session['payment_intent'] ?? '');
        if ($status === 'paid' && !str_starts_with($paymentIntent, 'pi_')) {
            throw new FederationException('Stripe no entregó PaymentIntent para la orden pagada.', 400);
        }
        $reference = str_starts_with($paymentIntent, 'pi_') ? $paymentIntent : $sessionId;

        return [
            'accepted' => true,
            'processed' => true,
            'event_id' => (string)$event['id'],
            'event_type' => (string)($event['type'] ?? ''),
            'drop_id' => $dropId,
            'provider' => 'stripe',
            'reference' => $reference,
            'checkout_session_id' => $sessionId,
            'status' => $status,
            'amount_cents' => $amount,
            'currency' => strtoupper($currency),
            'was_paid' => (string)$row['PaymentStatus'] === 'paid',
        ];
    }

    private function normalizeRefund(array $event): array
    {
        $charge = $event['data']['object'] ?? null;
        if (!is_array($charge) || ($charge['object'] ?? null) !== 'charge') {
            throw new FederationException('Webhook Stripe no contiene Charge válido.', 400);
        }

        if (($charge['refunded'] ?? false) !== true) {
            return [
                'accepted' => true,
                'processed' => false,
                'event_id' => (string)$event['id'],
                'event_type' => (string)($event['type'] ?? ''),
                'reason' => 'partial-refund-not-final',
            ];
        }

        $paymentIntent = (string)($charge['payment_intent'] ?? '');
        if (!str_starts_with($paymentIntent, 'pi_')) {
            throw new FederationException('Charge Stripe reembolsado sin PaymentIntent válido.', 400);
        }

        $row = $this->repository->findByPaymentReference('stripe', $paymentIntent);
        if ($row === null) {
            return [
                'accepted' => true,
                'processed' => false,
                'event_id' => (string)$event['id'],
                'event_type' => (string)($event['type'] ?? ''),
                'reason' => 'refund-not-for-federationdrop',
            ];
        }

        $amount = (int)($charge['amount'] ?? -1);
        $refunded = (int)($charge['amount_refunded'] ?? -1);
        $currency = strtolower((string)($charge['currency'] ?? ''));
        if ($amount !== (int)$row['AmountCents']
            || $refunded < $amount
            || $currency !== strtolower((string)$row['Currency'])) {
            throw new FederationException('Reembolso Stripe no coincide con la orden FederationDrop.', 409);
        }

        return [
            'accepted' => true,
            'processed' => true,
            'event_id' => (string)$event['id'],
            'event_type' => (string)($event['type'] ?? ''),
            'drop_id' => (string)$row['DropId'],
            'provider' => 'stripe',
            'reference' => $paymentIntent,
            'status' => 'refunded',
            'amount_cents' => $amount,
            'currency' => strtoupper($currency),
            'was_paid' => (string)$row['PaymentStatus'] === 'paid',
        ];
    }

    private function quoteFingerprint(array $row): string
    {
        return hash('sha256', implode('|', [
            (string)($row['DropId'] ?? ''),
            (string)(int)($row['AmountCents'] ?? 0),
            strtoupper((string)($row['Currency'] ?? '')),
            (string)(int)($row['ExpectedSizeBytes'] ?? 0),
            (string)(int)($row['RetentionDays'] ?? 0),
            (string)(int)($row['MaxDownloads'] ?? 0),
        ]));
    }
}
