<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationDropStripeClient.php';
require_once dirname(__DIR__) . '/src/Federation/FederationDropStripeWebhookVerifier.php';

use ArcadeCloud\Drive\Federation\FederationDropStripeClient;
use ArcadeCloud\Drive\Federation\FederationDropStripeWebhookVerifier;
use ArcadeCloud\Drive\Federation\FederationException;

function stripeDropOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$key = 'sk_' . 'test_' . 'syntheticFederationDrop123';
$checkoutSeen = false;
$client = new FederationDropStripeClient(
    $key,
    function (string $method, string $url, array $headers, string $body) use (&$checkoutSeen): array {
        stripeDropOk($method === 'POST', 'Stripe client uses POST.');
        stripeDropOk($url === 'https://api.stripe.com/v1/checkout/sessions', 'Stripe Checkout endpoint is exact.');
        stripeDropOk(
            ($headers['authorization'] ?? '') === 'Basic ' . base64_encode(('sk_' . 'test_' . 'syntheticFederationDrop123') . ':'),
            'Stripe client uses server-side Basic authorization.'
        );
        parse_str($body, $form);
        stripeDropOk(($form['mode'] ?? null) === 'payment', 'Stripe Checkout mode is payment.');
        stripeDropOk(($form['line_items'][0]['price_data']['unit_amount'] ?? null) === '1234', 'Dynamic amount reaches Stripe.');
        stripeDropOk(($form['metadata']['arcadecloud_drop_id'] ?? null) === 'fdp_test123456789012', 'Drop id reaches Stripe metadata.');
        $checkoutSeen = true;
        return [200, json_encode([
            'id' => 'cs_test_federationdrop001',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/federationdrop-test',
        ], JSON_THROW_ON_ERROR), []];
    }
);

$session = $client->createCheckoutSession([
    'mode' => 'payment',
    'success_url' => 'https://drive.example.test/federationdrop/?ok=1',
    'cancel_url' => 'https://drive.example.test/federationdrop/?cancel=1',
    'client_reference_id' => 'fdp_test123456789012',
    'line_items' => [[
        'price_data' => [
            'currency' => 'mxn',
            'unit_amount' => 1234,
            'product_data' => ['name' => 'FederationDrop'],
        ],
        'quantity' => 1,
    ]],
    'metadata' => ['arcadecloud_drop_id' => 'fdp_test123456789012'],
]);
stripeDropOk($checkoutSeen, 'Stripe Checkout request was emitted.');
stripeDropOk(($session['id'] ?? '') === 'cs_test_federationdrop001', 'Stripe Checkout response was accepted.');
stripeDropOk($client->expectedLiveMode() === false, 'Test Stripe key selects test mode.');

$secret = 'whsec_' . 'syntheticFederationDropWebhook';
$now = 1790200000;
$verifier = new FederationDropStripeWebhookVerifier($secret, 300, static fn(): int => $now);
$event = [
    'id' => 'evt_federationdrop_001',
    'object' => 'event',
    'type' => 'checkout.session.completed',
    'livemode' => false,
    'data' => ['object' => ['id' => 'cs_test_federationdrop001', 'object' => 'checkout.session']],
];
$body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $secret);
$verified = $verifier->verify($body, $signature);
stripeDropOk(($verified['id'] ?? '') === 'evt_federationdrop_001', 'Valid Stripe-Signature is accepted.');

$bad = false;
try {
    $verifier->verify($body, 't=' . $now . ',v1=' . str_repeat('0', 64));
} catch (FederationException) {
    $bad = true;
}
stripeDropOk($bad, 'Invalid Stripe-Signature is rejected.');

$expired = false;
$old = $now - 301;
try {
    $verifier->verify($body, 't=' . $old . ',v1=' . hash_hmac('sha256', $old . '.' . $body, $secret));
} catch (FederationException) {
    $expired = true;
}
stripeDropOk($expired, 'Expired Stripe-Signature is rejected.');

echo "FederationDrop Stripe primitives: OK\n";
