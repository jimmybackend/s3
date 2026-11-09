#!/usr/bin/env python3
"""Explicit operator command; never run by HTTP or deployment. Preserves all owner grants."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import tempfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--config', default='/etc/arcadecloud-drive/legal-api.json')
parser.add_argument('--grant-id', default='legal-case-service')
parser.add_argument('--days', type=int, default=365)
parser.add_argument('--rotate', action='store_true', help='Replace an existing service credential with a new one')
args = parser.parse_args()
path = Path(args.config)
if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', args.grant_id) or not 1 <= args.days <= 3650:
    parser.error('Invalid service identifier or validity period')
if path.is_symlink() or not path.is_file():
    parser.error('An existing private configuration file, without symlinks, is required')
original = path.read_bytes()
data = json.loads(original)
if not isinstance(data, dict) or not re.fullmatch(r'[A-Za-z0-9_-]{16,80}', str(data.get('instance_id', ''))):
    parser.error('Invalid existing installation identity')
grants = data.get('grants')
if not isinstance(grants, dict):
    parser.error('Invalid existing grants')
previous = grants.get(args.grant_id)
if previous is not None and (not isinstance(previous, dict) or previous.get('scope') != 'case-service'):
    parser.error('This identifier belongs to an owner grant; no changes made')
if previous is not None and not args.rotate:
    parser.error('Service already exists. Use --rotate only when ready to update Legal immediately')
token = secrets.token_hex(32)
expires = int(time.time()) + args.days * 86400
grants[args.grant_id] = {'scope': 'case-service', 'token_hash': hashlib.sha256(token.encode()).hexdigest(), 'enabled': True, 'expires_at': expires}
info = path.stat()
# Backup is readable only by the operator. The final file preserves its owner/group.
backup = path.with_name(path.name + '.backup-' + str(time.time_ns()))
fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, 'wb') as stream:
    stream.write(original)
fd, temporary = tempfile.mkstemp(prefix='.legal-service-', dir=path.parent)
try:
    os.fchmod(fd, 0o640)
    os.fchown(fd, info.st_uid, info.st_gid)
    with os.fdopen(fd, 'w') as stream:
        json.dump(data, stream, indent=2)
        stream.write('\n')
        stream.flush()
        os.fsync(stream.fileno())
    if path.read_bytes() != original:
        raise SystemExit('Configuration changed concurrently. Retry after reviewing it; not replaced.')
    os.replace(temporary, path)
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
print('Servicio guardado. Se conservaron la identidad y las cuentas personales.')
print('Vence (UTC):', datetime.datetime.fromtimestamp(expires, datetime.timezone.utc).isoformat())
print('Añada o sustituya SOLO service dentro del bloque drive del archivo privado de Legal:')
print("'service' => ['grant_id' => '" + args.grant_id + "', 'token' => '" + token + "'],")
print('Guarde este secreto únicamente en el archivo privado de HostGator, fuera del repositorio y del navegador público.')
