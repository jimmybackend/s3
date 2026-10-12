<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Activity/AccountingProfile.php';
use ArcadeCloud\Drive\Activity\AccountingProfile;

$family = AccountingProfile::describe('family');
if ($family['mode'] !== 'family' || $family['charges_enabled'] !== false) {
    throw new RuntimeException('Family must be non-billing default');
}
$unknown = AccountingProfile::describe('unknown');
if ($unknown['mode'] !== 'family') throw new RuntimeException('Unknown profile must fail closed');
$business = AccountingProfile::describe('business_preview');
if ($business['mode'] !== 'business_preview' || $business['charges_enabled'] !== false) {
    throw new RuntimeException('Business preview must not bill');
}
$codes = array_column($business['service_catalog'], 'code');
if (!in_array('hearing_video_transcription', $codes, true)
    || !in_array('legal_document_digitization', $codes, true)) {
    throw new RuntimeException('Professional catalog incomplete');
}
echo "OK accounting family default and disabled business preview.\n";
