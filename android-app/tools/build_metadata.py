"""Produce provenance for the exact checked-out commit, not the PR merge ref."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

out = Path(sys.argv[1])
source = Path('app/build.gradle').read_text()
commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
apks = list(out.glob('*.apk'))
if len(apks) != 1:
    raise SystemExit('Expected exactly one APK')
apk = apks[0]
digest = hashlib.sha256(apk.read_bytes()).hexdigest()
info = {
    'applicationId': 'com.arcadecloud.drive',
    'versionName': re.search(r"versionName '([^']+)'", source).group(1),
    'versionCode': int(re.search(r'versionCode (\d+)', source).group(1)),
    'commit': commit, 'apk': apk.name, 'sha256': digest,
    'workflow': os.getenv('GITHUB_RUN_ID', 'local'),
    'physical_device_tests': 'not performed',
    'production_end_to_end_tests': 'not performed; backend deployment requires approval',
}
(out / 'build-info.json').write_text(json.dumps(info, indent=2) + '\n')
(out / 'SHA256SUMS').write_text(f'{digest}  {apk.name}\n')
