<?php
declare(strict_types=1);
// Controller integration against isolated collaborators; no production DB or AWS access.
namespace ArcadeCloud\Drive\Core {
    final class DriveApplication {
        public function __construct(private \ArcadeCloud\Drive\Security\SessionManager $session) {}
        public function session() { return $this->session; }
        public function userStoragePath() { return new \ArcadeCloud\Drive\Storage\UserStoragePath(); }
        public function authenticationRepository() { return new class {
            public function findUserByEmail($email) { return ['id'=>2,'userstatus'=>($GLOBALS['case']==='revoked'?'Inactivo':'Activo'),'password'=>($GLOBALS['case']==='password_changed'?'new-hash':'hash')]; }
        }; }
        public function authenticationService() { return new class($this->session) {
            public function __construct(private $session) {}
            public function authenticate($email,$password,$ip) {
                if ($password !== 'test-secret') return ['status'=>'invalid_credentials'];
                $this->session->establishAuthenticatedUser($email,2,'user');
                return ['status'=>'authenticated'];
            }
        }; }
        public function fileRecordLocator() { return new class {
            public function requireReadableByKey($uid,$key) { throw new \RuntimeException('Access denied', $GLOBALS['case'] === 'locked' ? 423 : 403); }
        }; }
        public function uploadDestinationService() { return new class {
            public function resolve($uid,$route) { if($uid !== 2) throw new \LogicException('Injected identity'); return $route; }
        }; }
        public function fileListService() { return new class {
            public function load($uid,$route,$query) { if($uid !== 2) throw new \LogicException('Injected identity'); return ['rows'=>[['id_'=>1,'Nombre'=>'visible.txt','Encriptado'=>'physical.txt','Tamano'=>10,'Ruta'=>$route,'Fecha'=>'2026-10-21','AccessType'=>'normal','PasswordHash'=>'do-not-leak']], 'page'=>1,'pages'=>1,'total'=>1]; }
        }; }
        public function folderRepository() { return new class { public function listChildrenPage(...$args) { return []; } }; }
        public function folderQueryService() { return new class { public function breadcrumbsForUser(...$args) { return [['label'=>'Mi Drive','route'=>'Data2/']]; } }; }
    }
}
namespace ArcadeCloud\Drive\Security {
    final class LoginRateLimiter {
        public function allow(...$args) { return $GLOBALS['case'] !== 'rate_limit'; }
        public function registerFailure(...$args) {}
        public function clear(...$args) {}
    }
}
namespace {
    final class Config { public const RUTA_RAIZ = 'Data/'; }
    spl_autoload_register(static function(string $class): void {
        $prefix = 'ArcadeCloud\\Drive\\';
        if(str_starts_with($class,$prefix)) require_once __DIR__.'/../src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    });
    if(count($argv) === 1) {
        $cases = ['anonymous'=>401,'csrf'=>403,'login_method'=>405,'login_bad'=>401,'login_ok'=>200,'rate_limit'=>429,'revoked'=>401,'password_changed'=>401,'idle_expired'=>401,'absolute_expired'=>401,'session_method'=>405,'foreign_route'=>403,'traversal'=>403,'browse'=>200,'foreign_file'=>403,'locked'=>423,'logout_csrf'=>403,'logout'=>200];
        foreach($cases as $case=>$expected) {
            $process = proc_open([PHP_BINARY,__FILE__,$case], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $out = stream_get_contents($pipes[1]); $status = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            $json = json_decode($out,true);
            if($exit !== 0 || $status !== (string)$expected || !is_array($json)) throw new \RuntimeException("$case: expected $expected; got $status; $out");
            if($case === 'login_ok' && ($json['user_id'] !== 2 || strlen($json['csrf']) !== 64)) throw new \RuntimeException('Authentication failed');
            if($case === 'browse' && (str_contains($out,'do-not-leak') || $json['files'][0]['key'] !== 'Data2/physical.txt')) throw new \RuntimeException('Catalog boundary failed');
        }
        echo 'native_api_authorization_regression: '.count($cases)." scenarios passed\n"; exit;
    }
    $case = $argv[1]; $GLOBALS['case'] = $case;
    register_shutdown_function(static function(): void { fwrite(STDERR,(string)(http_response_code() ?: 200)); });
    $session = new \ArcadeCloud\Drive\Security\SessionManager(); $session->start();
    if(!in_array($case,['anonymous','csrf','login_method','login_bad','login_ok','rate_limit'],true)) {
        $session->establishAuthenticatedUser('native@example.test',2,'user');
        $session->set('native_issued_at',time()); $session->set('native_last_seen',time());
        $session->set('native_credential_fingerprint',hash('sha256','hash'));
    }
    if($case === 'idle_expired') $session->set('native_last_seen',time()-1801);
    if($case === 'absolute_expired') $session->set('native_issued_at',time()-43201);
    $session->set('upload_csrf','test-csrf');
    $action = match($case) {
        'csrf','login_method','login_bad','login_ok','rate_limit'=>'login',
        'foreign_route','traversal','browse'=>'browse', 'foreign_file','locked'=>'file',
        'logout','logout_csrf'=>'logout',default=>'session'
    };
    $method = in_array($case,['csrf','login_bad','login_ok','rate_limit','session_method','logout','logout_csrf'],true)?'POST':'GET';
    $request = new \ArcadeCloud\Drive\Http\Request(['REQUEST_METHOD'=>$method,'HTTP_X_DRIVE_CSRF'=>in_array($case,['csrf','logout_csrf'])?'wrong':'test-csrf','REMOTE_ADDR'=>'127.0.0.1'],
        ['action'=>$action,'route'=>$case==='foreign_route'?'Data/':($case==='traversal'?'Data2/../Data/':'Data2/'),'key'=>'Data/foreign.txt','user_id'=>999],
        ['email'=>'native@example.test','password'=>$case==='login_bad'?'wrong':'test-secret','user_id'=>999]);
    (new \ArcadeCloud\Drive\Http\Controller\NativeDriveController(new \ArcadeCloud\Drive\Core\DriveApplication($session),$request))->dispatch();
}
