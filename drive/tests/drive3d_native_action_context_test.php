<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/View/Drive3dActionContextView.php';
use ArcadeCloud\Drive\View\Drive3dActionContextView;
$page = '<main><nav id="osLauncher">Desktop must stay out</nav><section class="os-window os-explorer-window"><button data-key="user/a.txt">Árbol</button></section><div id="fileContextMenu">Actions</div><div id="folderContextMenu">Folders</div><div class="modal" id="securityFileModal"><script>alert(1)</script>Security</div></main><script src="js/so.js?v=1"></script><script src="js/so-power.js"></script><script src="js/arcadecloud-updater.js"></script>';
$context = ['csrf'=>'fixture-csrf','route'=>'user/','rootRoute'=>'user/'];
$result = Drive3dActionContextView::payload($page,$context);
foreach ([
    $result['ok'] === true,
    $result['context'] === $context,
    str_contains($result['html'],'Árbol'),
    str_contains($result['html'],'securityFileModal'),
    !str_contains($result['html'],'osLauncher'),
    !str_contains($result['html'],'<script'),
    $result['scripts'] === ['js/so.js?v=1'],
] as $check) if (!$check) throw new RuntimeException('Native context contract failed');
$drive = file_get_contents(dirname(__DIR__).'/dataword3d.php');
$os = file_get_contents(dirname(__DIR__).'/so.php');
$classic = file_get_contents(dirname(__DIR__).'/s3.php');
if (!str_contains($drive,'data-background-tasks') || !str_contains($drive,'osTaskCenterButton')) throw new RuntimeException('3D task center missing');
if (!str_contains($os,'js/background-tasks.js') || !str_contains($classic,'js/move-tasks.js')) throw new RuntimeException('Shared task modules missing');
echo "Native authorized markup, UTF-8, script isolation and shared task wiring OK\n";
