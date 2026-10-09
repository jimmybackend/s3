"""GitOps fixed SSM contract: offline tests, never uses an AWS credential."""
import ast
import re
import subprocess
import unittest
from pathlib import Path

BASE=Path(__file__).resolve().parents[3]
SCRIPT_PATH=BASE / "tools/arcadecloud-aws-bridge/fastdrive_gitops.py"

class FixedGitOps(unittest.TestCase):
    def test_no_force_checkout_and_shell_syntax(self):
        text=SCRIPT_PATH.read_text(encoding="utf8")
        tree=ast.parse(text)
        script=next(ast.literal_eval(n.value) for n in tree.body
                    if isinstance(n,ast.Assign)
                    for t in n.targets if isinstance(t,ast.Name) and t.id=="SCRIPT")
        check=subprocess.run(["bash","-n"],input=script,text=True,capture_output=True)
        self.assertEqual(check.returncode,0,check.stderr)
        self.assertIn("merge --ff-only",script)
        self.assertIn("status --porcelain -uno",script)
        self.assertIn("LOCAL_UNTRACKED_CODE",script)
        self.assertIn("REVIEWED_COMMIT_INCLUDED",script)
        self.assertIn("merge-base --is-ancestor",script)
        self.assertIn("fastdrive_safe_worker_reload.php",script)
        self.assertNotIn("git reset --hard",script)
        self.assertNotIn("git push",script)
        self.assertNotIn("docker system prune",script)
        self.assertNotIn("install_guacamole_node.sh",script)
        self.assertNotIn("systemctl restart nginx",script)
    def test_php_guard_fixed_command(self):
        guard=(BASE/"drive/bin/fastdrive_safe_worker_reload.php").read_text()
        self.assertIn("ComputeNodeAdmissionLock",guard)
        self.assertIn("ServerTaskActivityProbe",guard)
        self.assertIn("Ec2InstanceIdentityService",guard)
        self.assertIn("arcadecloud-media-worker.service",guard)
        self.assertNotIn("deferred_office_active",guard)
        self.assertNotIn("'docker'",guard)
        self.assertNotIn("exec($_",guard)
    def test_php_lints(self):
        for f in ["drive/src/Media/MediaWorkerNodeService.php",
                  "drive/tests/idle_stop_office_regression.php",
                  "drive/bin/fastdrive_safe_worker_reload.php"]:
            result=subprocess.run(["php","-l",str(BASE/f)],capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)

if __name__=="__main__":
    unittest.main()
