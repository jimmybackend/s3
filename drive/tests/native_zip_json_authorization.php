<?php
declare(strict_types=1);
// Actual ZIP gateway, with isolated account/catalog/Lambda/cost collaborators. No AWS calls.
namespace ArcadeCloud\Drive\Core { final class ApplicationKernel { public static function app() { return $GLOBALS['app']; } } }
namespace ArcadeCloud\Drive\Activity {
    final class ActivityCostRecorder {
        public static function fromDatabase($db) { return new self(); }
        public static function correlation(...$args) { return 'test'; }
        public function success(...$args) {}
        public function failure(...$args) {}
    }
}
namespace Aws\Lambda {
    final class LambdaClient {
        public function __construct($config) {}
        public function invoke($request) {
            $GLOBALS['invoked']++;
            $payload=json_decode($request['Payload'],true);
            if ($payload['key'] !== 'Data2/a.zip') throw new \RuntimeException('Wrong catalog key');
            $body=$payload['action']==='list'
                ? ['ok'=>true,'items'=>[['name'=>'doc.txt','path'=>'doc.txt','type'=>'file']],'page'=>1,'page_size'=>100,'total'=>1]
                : ['ok'=>true,'download_url'=> $GLOBALS['case']==='bad_url' ? 'https://evil.example/test' : 'https://unitbucket.s3.us-east-1.amazonaws.com/output'];
            return ['Payload'=>json_encode(['statusCode'=>200,'body'=>json_encode($body)])];
        }
    }
}
namespace {
    require_once __DIR__.'/../src/Http/JsonResponse.php';
    require_once __DIR__.'/../src/Application/ZipLambdaExplorerService.php';
    require_once __DIR__.'/../src/Application/ZipExplorerService.php';
    if(count($argv)===1) {
        $cases=['disabled'=>[503,0],'anonymous'=>[401,0],'foreign'=>[403,0],'locked'=>[403,0],'list'=>[200,1],'download'=>[200,1],'bad_url'=>[502,1]];
        foreach($cases as $case=>[$status,$calls]) {
            $p=proc_open([PHP_BINARY,__FILE__,$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
            $json=json_decode($out,true);
            if($exit!==0 || !is_array($json) || !str_ends_with($err,"STATUS=$status CALLS=$calls")) throw new \RuntimeException("$case: $out $err");
            if($case==='list' && $json['items'][0]['name']!=='doc.txt') throw new \RuntimeException('Listing lost');
            if($case==='download' && !str_starts_with($json['download_url'],'https://unitbucket.s3.')) throw new \RuntimeException('Download missing');
            if(in_array($case,['foreign','locked','bad_url']) && str_contains($out,'evil.example')) throw new \RuntimeException('Unsafe URL exposed');
        }
        echo "native_zip_json_authorization: 7 isolated gateway scenarios passed\n";exit;
    }
    $case=$argv[1];$GLOBALS['case']=$case;$GLOBALS['invoked']=0;
    register_shutdown_function(static function(){fwrite(STDERR,'STATUS='.(http_response_code()?:200).' CALLS='.$GLOBALS['invoked']);});
    putenv('ARCADECLOUD_ZIP_LAMBDA_ENABLED='.($case==='disabled'?'0':'1'));
    putenv('ARCADECLOUD_ZIP_LAMBDA_FUNCTION=unit');putenv('ARCADECLOUD_ZIP_LAMBDA_REGION=us-east-1');
    $_GET=['format'=>'json','archivo'=>'Data2/a.zip'];if(in_array($case,['download','bad_url']))$_GET['file']='doc.txt';
    $GLOBALS['app']=new class {
        public function session(){return new class {public function start(){}public function isAuthenticated(){return $GLOBALS['case']!=='anonymous';}public function userId(){return 2;}};}
        public function fileRecordLocator(){return new class {public function requireReadableByKey($uid,$key){if(in_array($GLOBALS['case'],['foreign','locked']))throw new \RuntimeException('Denied');return ['_key'=>'Data2/a.zip','id_'=>7,'Nombre'=>'a.zip'];}};}
        public function db(){return null;}
        public function bucket(){return 'unitbucket';}
    };
    \ArcadeCloud\Drive\Application\ZipExplorerService::handle();
}
