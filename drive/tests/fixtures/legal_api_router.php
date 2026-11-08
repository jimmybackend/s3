<?php
declare(strict_types=1);
namespace Aws\S3 {
    /** S3 bytes are deliberately simulated; no AWS calls or credentials in integration tests. */
    class S3Client {
        public function headObject(array $args): array {
            if (str_contains($args['Key'],'missing')) throw new \Aws\S3\Exception\S3Exception('missing',404);
            return ['ContentLength'=>12,'ContentType'=>'application/pdf'];
        }
        public function getObject(array $args): array {
            $s='%PDF-fixture';
            if (isset($args['Range']) && preg_match('/bytes=(\d+)-(\d+)/',$args['Range'],$m)) $s=substr($s,(int)$m[1],(int)$m[2]-(int)$m[1]+1);
            return ['Body'=>new \LegalFixtureBody($s)];
        }
    }
}
namespace Aws\S3\Exception { class S3Exception extends \RuntimeException { public function getStatusCode(): int { return $this->getCode(); } } }
namespace {
    if (getenv('LEGAL_DRIVE_TEST_MODE')!=='1' || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)) { http_response_code(404);exit; }
    final class LegalFixtureBody {
        private int $offset=0;
        public function __construct(private string $s) {}
        public function eof(): bool { return $this->offset>=strlen($this->s); }
        public function read(int $n): string { $r=substr($this->s,$this->offset,$n);$this->offset+=strlen($r);return $r; }
    }
    final class Config {
        public const RUTA_RAIZ='Data/';
        public static function getS3(): \Aws\S3\S3Client { return new \Aws\S3\S3Client(); }
        public static function getBucket(): string { return 'test-bucket'; }
    }
    spl_autoload_register(static function(string $c): void {
        $prefix='ArcadeCloud\\Drive\\';
        if (str_starts_with($c,$prefix)) require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($c,strlen($prefix))).'.php';
    });
    $db=new mysqli('127.0.0.1',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DRIVE_DB'),(int)getenv('TEST_DB_PORT'));
    $db->set_charset('utf8mb4');
    $_SERVER['HTTPS']='on';
    $app=\ArcadeCloud\Drive\Core\DriveApplication::boot($db);
    (new \ArcadeCloud\Drive\Http\Controller\LegalDriveController($app))->dispatch(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/api/legal-content.php');
}
