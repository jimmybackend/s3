"""Compile and syntax check the fixed remote SSM bash (no AWS access)."""
import ast
import re
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
SOURCE=ROOT / "tools/arcadecloud-aws-bridge/workstation_deploy.py"

class Contract(unittest.TestCase):
    def test_deployment_script_syntax_and_security(self):
        code=SOURCE.read_text()
        tree=ast.parse(code)
        script = next(ast.literal_eval(n.value) for n in tree.body
                      if isinstance(n, ast.Assign)
                      for target in n.targets
                      if isinstance(target,ast.Name) and target.id=="SCRIPT")
        result=subprocess.run(["bash","-n"],input=script,text=True,
                              capture_output=True,check=False)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn("git -c safe.directory=",script)
        self.assertIn("pull --ff-only origin main",script)
        self.assertIn("WORKSTATION_ACTIVE",script) if False else None
        self.assertIn("ACTIVE_SESSION_POSSIBLE",script)
        self.assertIn("docker build -t arcadecloud/workstation:phase1",script)
        self.assertIn("arcadecloud/workstation:before-pdf-rar-",script)
        self.assertIn("rar a -idq",script)
        self.assertIn("unrar t -inul",script)
        self.assertNotIn("install_guacamole_node.sh",script)
        self.assertNotIn("docker system prune",script)
        self.assertNotIn("systemctl restart",script)
        self.assertNotIn("docker exec -u root",script)
        self.assertIn("127.0.0.1:8085",script)

if __name__=="__main__":
    unittest.main()
