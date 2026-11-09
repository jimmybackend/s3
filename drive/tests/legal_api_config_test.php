<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Integration/LegalApiConfig.php';
use ArcadeCloud\Drive\Integration\LegalApiConfig;
function check(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function denied(callable $fn,int $code): void { try { $fn(); } catch (RuntimeException $e) { check($e->getCode()===$code,'Unexpected denial: '.$e->getCode()); return; } throw new RuntimeException('Access should have been denied'); }
$token=bin2hex(random_bytes(32));
$raw=['instance_id'=>'test_installation_1234','grants'=>['legal7'=>['user_id'=>2,'token_hash'=>hash('sha256',$token),'enabled'=>true,'expires_at'=>time()+3600]]];
$c=new LegalApiConfig($raw); $g=$c->authenticate('Bearer '.$token); check($g['user_id']===2,'Explicit identity');
denied(fn()=>$c->authenticate(''),401); denied(fn()=>$c->authenticate('Bearer '.str_repeat('0',64)),401);
$t=$c->ticket($g,123,'open'); $p=$c->redeem($t); check($p['file']===123 && $p['mode']==='open','Ticket roundtrip');
check(!str_contains($t,$token),'No API credential in ticket');
$pos=strpos($t,'.')+12; $bad=$t; $bad[$pos]=$bad[$pos]==='A'?'B':'A'; denied(fn()=>$c->redeem($bad),401);
$r=$raw; $r['grants']['legal7']['enabled']=false; denied(fn()=>(new LegalApiConfig($r))->redeem($t),401);
$r=$raw; $r['grants']['legal7']['expires_at']=time()-1; denied(fn()=>(new LegalApiConfig($r))->authenticate('Bearer '.$token),401);
$r=$raw; $r['grants']['legal7']['token_hash']=hash('sha256',random_bytes(32)); denied(fn()=>(new LegalApiConfig($r))->redeem($t),401);
$r=$raw; $r['instance_id']='other_installation_1234'; denied(fn()=>(new LegalApiConfig($r))->redeem($t),401);
// Forge an expired ticket using the test grant's private key, exercising actual expiry validation.
$iv=random_bytes(12);$tag='';$payload=json_encode(['file'=>123,'mode'=>'open','exp'=>time()-1,'instance'=>$raw['instance_id']]);
$cipher=openssl_encrypt($payload,'aes-256-gcm',hash('sha256',"legal-content-v1\0".$g['token_hash'],true),OPENSSL_RAW_DATA,$iv,$tag,'legal7');
$expired='legal7.'.rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'='); denied(fn()=>$c->redeem($expired),401);
echo "PASS: grants, explicit identity, opaque tickets, expiry, tampering, rotation, revocation, installation binding\n";


$serviceToken=bin2hex(random_bytes(32));$raw['grants']['case-service']=['scope'=>'case-service','token_hash'=>hash('sha256',$serviceToken),'enabled'=>true,'expires_at'=>time()+3600];
$c=new LegalApiConfig($raw);$service=$c->authenticate('Bearer '.$serviceToken);
check(!isset($service['user_id']) && $service['scope']==='case-service','Service is not an owner identity');
$shared=$c->ticket($service,123,'download',['share'=>str_repeat('a',64),'case'=>9]);
check($c->redeem($shared)['context']['case']===9,'Shared ticket binds the case and authorization reference');
denied(fn()=>$c->redeem($c->ticket($service,123,'open')),401);
$r=$raw;$r['grants']['case-service']['enabled']=false;denied(fn()=>(new LegalApiConfig($r))->redeem($shared),401);
$r=$raw;$r['grants']['case-service']['user_id']=2;denied(fn()=>(new LegalApiConfig($r))->authenticate('Bearer '.$serviceToken),401);
echo "PASS: service scope, case-bound tickets and immediate service revocation\n";
