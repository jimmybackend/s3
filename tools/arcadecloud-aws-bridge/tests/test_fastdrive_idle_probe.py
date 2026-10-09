"""Checks the read-only remote health script without contacting AWS."""
import ast
import subprocess
import unittest
from pathlib import Path
BASE=Path(__file__).resolve().parents[3]
SRC=BASE/"tools/arcadecloud-aws-bridge/fastdrive_idle_probe.py"

class LiveProbeContract(unittest.TestCase):
    def test_fixed_commands_and_syntax(self):
        code=SRC.read_text()
        tree=ast.parse(code)
        script=next(ast.literal_eval(n.value) for n in tree.body
                    if isinstance(n,ast.Assign) for v in n.targets
                    if isinstance(v,ast.Name) and v.id=="SCRIPT")
        lint=subprocess.run(["bash","-n"],input=script,text=True,capture_output=True)
        self.assertEqual(lint.returncode,0,lint.stderr)
        self.assertIn("SELECT Status, IdleSince",script)
        self.assertIn("Ec2InstanceIdentityService",script)
        self.assertIn("MediaProcessingJobRepository",script)
        self.assertIn("OfficeActivityProbe",script)
        self.assertIn("LOCAL_CLI_TASK",script)
        self.assertIn("PROCESS_%s_AGE_MIN",script)
        self.assertNotIn("ps aux",script)
        self.assertNotIn("pgrep -af",script)
        self.assertNotIn("StopInstances",script)
        self.assertNotIn("systemctl restart",script)
        self.assertNotIn("git reset",script)
        php=script.split("php -d display_errors=0 -r '",1)[1].rsplit("\n'",1)[0]
        test=subprocess.run(["php","-l"],input="<?php\n"+php,text=True,capture_output=True)
        self.assertEqual(test.returncode,0,test.stdout+test.stderr)
if __name__=="__main__":
    unittest.main()
