<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Sharing/ShareException.php';
require_once dirname(__DIR__).'/src/Sharing/ShareTokenStore.php';
use ArcadeCloud\Drive\Sharing\ShareTokenStore;
use ArcadeCloud\Drive\Sharing\ShareException;
function checkTimed(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function rejectTimed(callable $fn,int $status): void {try {$fn();}catch(ShareException $e){checkTimed($e->httpStatus()===$status,'Wrong denial');return;}throw new RuntimeException('Expected rejection');}
$path=tempnam(sys_get_temp_dir(),'timed-share-');
try {
 $store=new ShareTokenStore($path);
 $payload=['legal_bridge'=>true,'user_id'=>1,'file_id'=>17,'expira'=>date(DATE_ATOM,time()+86400),'archivo_key'=>'fixture/key'];
 $legacy=$store->create(['user_id'=>1,'file_id'=>17,'expira'=>date(DATE_ATOM,time()+86400)]);
 $a=$store->create($payload);checkTimed(strlen($a)===32,'Same token shape as desktop sharing');
 rejectTimed(fn()=>$store->create($payload,$legacy),403);
 rejectTimed(fn()=>$store->create(array_replace($payload,['file_id'=>18]),$a),403);
 checkTimed($store->find($a)!==null && $store->find($legacy)!==null,'Failed renewal preserves both tokens');
 $b=$store->create($payload,$a);checkTimed($store->find($a)===null && $store->find($b)!==null,'Renewal replaces the old share atomically');
 rejectTimed(fn()=>$store->revokeBridge($b,2),404);
 rejectTimed(fn()=>$store->revokeBridge($legacy,1),404);
 $store->revokeBridge($b,1);checkTimed($store->find($b)===null && $store->find($legacy)!==null,'Revocation is owner-bound and preserves ordinary desktop shares');
 file_put_contents($path,'{corrupt');$old=file_get_contents($path);
 rejectTimed(fn()=>$store->create($payload),500);checkTimed(file_get_contents($path)===$old,'Invalid existing store is never overwritten');
 echo "PASS: desktop token format, atomic renewal, exact owner/file, isolated revocation and store preservation\n";
} finally {unlink($path);}
