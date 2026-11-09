"""Provisioning/rotation tests use only a temporary private configuration."""
import hashlib
import json
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile

command = Path(__file__).resolve().parents[1] / 'bin/provision_legal_case_service.py'
with tempfile.TemporaryDirectory() as directory:
    path = Path(directory) / 'private.json'
    original = {'instance_id': 'test_installation_1234', 'grants': {'owner1': {'user_id': 1, 'token_hash': 'a' * 64, 'enabled': True, 'expires_at': 1234567890}}}
    path.write_text(json.dumps(original))
    def run(*extra):
        return subprocess.run([sys.executable, str(command), '--config', str(path), *extra], capture_output=True, text=True)
    first = run()
    assert first.returncode == 0, first.stderr
    result = json.loads(path.read_text())
    assert result['instance_id'] == original['instance_id']
    assert result['grants']['owner1'] == original['grants']['owner1']
    token = re.search(r"'token' => '([a-f0-9]{64})'", first.stdout).group(1)
    service = result['grants']['legal-case-service']
    assert service['scope'] == 'case-service' and 'user_id' not in service
    assert service['token_hash'] == hashlib.sha256(token.encode()).hexdigest()
    assert stat.S_IMODE(path.stat().st_mode) == 0o640
    assert all(stat.S_IMODE(p.stat().st_mode) == 0o600 for p in Path(directory).glob('*.backup-*'))
    before = path.read_bytes()
    assert run().returncode != 0 and path.read_bytes() == before
    assert run('--grant-id', 'owner1', '--rotate').returncode != 0 and path.read_bytes() == before
    assert run('--rotate').returncode == 0
    rotated = json.loads(path.read_text())
    assert rotated['grants']['owner1'] == original['grants']['owner1']
    assert rotated['grants']['legal-case-service']['token_hash'] != service['token_hash']
    target = Path(directory) / 'target.json'
    path.rename(target)
    path.symlink_to(target)
    assert run('--rotate').returncode != 0
print('PASS: preserves owners/identity, private modes/backups, explicit rotation, owner collision and symlink rejection')
