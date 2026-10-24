"""Exercise actual web controller, sessions, page guard and error view with fake authentication only."""
from pathlib import Path
import http.cookiejar
import json
import os
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

with tempfile.TemporaryDirectory(prefix='login-http-test-') as tmp:
    temp = Path(tmp)
    router = temp / 'router.php'
    router.write_text('''<?php
namespace ArcadeCloud\\Drive\\Core {
    final class DriveApplication {
        public function session() { return new \\ArcadeCloud\\Drive\\Security\\SessionManager(); }
        public function authenticationService() { return new FakeAuthentication(); }
    }
    final class FakeAuthentication {
        public function authenticate($email, $password, $ip) {
            file_put_contents(sys_get_temp_dir() . '/auth-calls', "verify\\n", FILE_APPEND);
            if ($password !== 'valid-test-password') return ['status' => 'invalid_credentials'];
            (new DriveApplication())->session()->establishAuthenticatedUser($email, 7, 'user');
            return ['status' => 'authenticated', 'system_role' => 'user'];
        }
    }
}
namespace {
    $root = ROOT_PATH;
    require_once $root . '/src/Security/LoginPageGuard.php';
    require_once $root . '/src/Http/Request.php';
    require_once $root . '/src/Http/Controller/AuthController.php';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/psesion.php') {
        (new \\ArcadeCloud\\Drive\\Http\\Controller\\AuthController(
            new \\ArcadeCloud\\Drive\\Core\\DriveApplication(),
            \\ArcadeCloud\\Drive\\Http\\Request::fromGlobals()
        ))->login();
    }
    if ($path === '/index.php') {
        \\ArcadeCloud\\Drive\\Security\\LoginPageGuard::enforce();
        echo 'PUBLIC_INDEX'; return;
    }
    if ($path === '/login.php' || $path === '/login-error.php') { require $root . $path; return; }
    if ($path === '/js/login-cooldown.js') { header('Content-Type: application/javascript'); readfile($root . $path); return; }
    http_response_code(404);
}
'''.replace('ROOT_PATH', json.dumps(str(ROOT))))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    log = (temp / 'server.log').open('w+')
    process = subprocess.Popen([PHP, '-n', '-d', f'sys_temp_dir={tmp}', '-d', f'session.save_path={tmp}', '-S', f'127.0.0.1:{port}', str(router)], stdout=log, stderr=log)
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(jar))

    def request(path, data=None, opener=None):
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            response = (opener or client).open(base + path, payload, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, response.headers, response.read().decode()

    def check(condition, message):
        if not condition:
            log.flush(); log.seek(0)
            raise AssertionError(message + '\n' + log.read())

    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): break
            except OSError: time.sleep(.05)
        check(request('/index.php')[0] == 200, 'initial index accessible')
        for attempt in range(1, 6):
            code, headers, _ = request('/psesion.php', {'email': 'member@example.test', 'password': 'incorrect'})
            check(code == 303 and headers.get('Location') == 'login-error.php', 'failure redirects locally')
            code, headers, body = request('/login-error.php?remaining=5&retry_after=0')
            if attempt < 5:
                check(code == 401 and f'{5-attempt} de 5' in body, 'exact attempts rendered from server state')
                check('href="login.php"' in body, 'retry offered before limit')
            else:
                check(code == 429 and int(headers['Retry-After']) >= 895, 'fifth failure gets full cooldown')
                check('Acceso bloqueado temporalmente' in body and 'href="index.php"' not in body, 'blocked page has no bypass link')
        calls = (temp / 'auth-calls').read_text()
        for path in ['/index.php', '/login.php']:
            code, headers, _ = request(path)
            check(code == 303 and headers['Location'] == 'login-error.php', 'blocked browser cannot return to login/index')
        request('/psesion.php', {'email': 'member@example.test', 'password': 'valid-test-password'})
        check((temp / 'auth-calls').read_text() == calls, 'even correct password not verified while blocked')
        # Deleting cookies cannot remove the server's account/IP block.
        other_client = urllib.request.build_opener(NoRedirect())
        code, headers, _ = request('/psesion.php', {'email': 'member@example.test', 'password': 'valid-test-password'}, other_client)
        check(code == 303 and headers['Location'] == 'login-error.php', 'fresh browser still cannot authenticate blocked pair')
        check((temp / 'auth-calls').read_text() == calls, 'fresh browser did not reach authentication')
        # Expire server state without sleeping for fifteen minutes.
        for path in (temp / 'arcadecloud-drive-login-rate').glob('*.json'):
            state = json.loads(path.read_text())
            if state.get('blocked_until'):
                state['blocked_until'] = int(time.time()) - 1
                path.write_text(json.dumps(state))
        code, _, body = request('/login-error.php')
        check(code == 401 and '5 de 5' in body and 'href="login.php"' in body, 'expiry restores retry')
        check(request('/index.php')[0] == 200, 'index returns after expiry')
        code, headers, _ = request('/psesion.php', {'email': 'member@example.test', 'password': 'valid-test-password'})
        check(code == 303 and headers['Location'] == 'so.php', 'successful login keeps existing OS destination')
        check(request('/index.php')[0] == 200, 'successful authentication clears page error context')
        # Malformed input counts as failure and never leaves this origin.
        code, headers, _ = request('/psesion.php', {'email': "invalid'email", 'password': 'incorrect'})
        check(code == 303 and headers['Location'] == 'login-error.php', 'malformed email remains local')
        check('4 de 5' in request('/login-error.php')[2], 'malformed email consumes one attempt')
        # Corrupt limiter state must fail closed.
        import hashlib
        ip_file = temp / 'arcadecloud-drive-login-rate' / ('ip-' + hashlib.sha256(b'127.0.0.1').hexdigest() + '.json')
        ip_file.write_text('{invalid')
        check(request('/index.php')[0] == 503, 'guard denies access if state cannot be read')
        check(request('/psesion.php', {'email': 'member@example.test', 'password': 'valid-test-password'})[0] == 503, 'controller fails closed on unreadable limiter')
        print('login HTTP flow regression: ok (retry, five failures, 429, guards, new cookie, expiry, success, malformed input, fail closed)')
    finally:
        process.terminate()
        process.wait(timeout=5)
        log.close()
