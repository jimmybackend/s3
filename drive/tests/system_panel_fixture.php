<?php
declare(strict_types=1);
// CLI fixture: render production views without bootstrapping DB, AWS or a session.
if (PHP_SAPI !== 'cli' || getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') exit(64);
require_once dirname(__DIR__) . '/src/View/FederationOsAdminRenderer.php';
require_once dirname(__DIR__) . '/src/View/FederationModerationPageRenderer.php';
require_once dirname(__DIR__) . '/src/View/Ec2PanelHelper.php';
$view = $argv[1] ?? '';
if ($view === 'about') {
    (new ArcadeCloud\Drive\View\FederationOsAdminRenderer())->render(true, 'provider-fixture', 'server-fixture', true);
} elseif ($view === 'moderation') {
    (new ArcadeCloud\Drive\View\FederationModerationPageRenderer())->render('moderation-fixture', true);
} elseif ($view === 'ec2') {
    $region = 'us-east-1'; $state = 'all'; $csrf = 'ec2-fixture';
    $isOsSurface = true; $showServerConsole = false;
    $awsErr = $rdsErr = $err = null;
    $stylesVersion = $responsiveVersion = $toolVersion = $systemPanelVersion = $themeBridgeVersion = 1;
    define('RDP_INSTANCE_ID', 'i-00000000');
    $list = [['InstanceId'=>'i-12345678', 'Tags'=>[['Key'=>'Name','Value'=>'FastDrive']],
        'State'=>['Name'=>'stopped'], 'InstanceType'=>'c7i.xlarge',
        'Placement'=>['AvailabilityZone'=>'us-east-1a'], 'PrivateIpAddress'=>'10.0.0.1']];
    $dbList = [['id'=>'fixture-db', 'found'=>true, 'status'=>'stopped', 'target_type'=>'instance',
        'endpoint'=>str_repeat('long-', 20) . 'example.invalid']];
    $source = file_get_contents(dirname(__DIR__) . '/ec2.php');
    $template = substr($source, strpos($source, '<!doctype html>'));
    eval('use ArcadeCloud\\Drive\\View\\Ec2PanelHelper as H; ?>' . $template);
}
