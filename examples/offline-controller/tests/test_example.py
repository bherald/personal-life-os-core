import copy
import json
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from inspect_example import inspect


class OfflineControllerExampleTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.addCleanup(self.tmp.cleanup)
        for name in ("topology.example.json", "observations.example.json", "example-host-a.json", "example-host-b.json"):
            shutil.copyfile(ROOT / name, self.root / name)

    def result(self):
        return inspect(self.root / "topology.example.json", self.root / "observations.example.json")

    def change(self, name, change):
        path = self.root / name
        data = json.loads(path.read_text())
        change(data)
        path.write_text(json.dumps(data))

    def test_reproducible_missing_host_fixture_no_admission(self):
        result = self.result()
        expected = json.loads((ROOT / "status.example.json").read_text())
        self.assertEqual(result, expected)
        self.assertEqual(len(result["hosts"]), 2)
        self.assertFalse(result["host_operations_performed"])
        self.assertFalse(result["mutation_available"])
        self.assertFalse(result["contains_prompt_or_response_content"])
        for host in result["hosts"]:
            for target in host["targets"]:
                self.assertEqual(target["task_state"], "Missing")
                self.assertFalse(target["activation_allowed"])

    def test_listener_health_does_not_grant_activation(self):
        def update(data):
            data["targets"]["example-host-a/example_chat_a"] = {
                "task": {"State": "Running"}, "listeners": ["127.0.0.1", "198.51.100.10"]}
        self.change("observations.example.json", update)
        target = self.result()["hosts"][0]["targets"][0]
        self.assertTrue(target["task_present"])
        self.assertEqual(target["external_listener_count"], 1)
        self.assertFalse(target["loopback_only"])
        self.assertFalse(target["activation_allowed"])

    def test_enabled_host_route_or_model_is_refused(self):
        mutations = [
            ("topology.example.json", lambda d: d["hosts"][0].update(controller_enabled=True)),
            ("topology.example.json", lambda d: d["routes"][0].update(enabled=True)),
            ("example-host-a.json", lambda d: d["targets"]["example_chat_a"].update(activation_allowed=True)),
        ]
        for name, mutation in mutations:
            with self.subTest(name=name):
                previous = (self.root / name).read_bytes()
                self.change(name, mutation)
                with self.assertRaises(ValueError):
                    self.result()
                (self.root / name).write_bytes(previous)

    def test_residency_concurrency_and_host_identity_drift_refused(self):
        for field, value in [("concurrent_inference_enabled", True), ("compatible_residency_enabled", True),
                             ("automatic_activation_enabled", True), ("host_id", "other-host"), ("execution_mode", "parallel")]:
            with self.subTest(field=field):
                previous = (self.root / "example-host-a.json").read_bytes()
                self.change("example-host-a.json", lambda d: d.update({field: value}))
                with self.assertRaises(ValueError):
                    self.result()
                (self.root / "example-host-a.json").write_bytes(previous)

    def test_unknown_exact_route_target_refused(self):
        self.change("topology.example.json", lambda d: d["routes"][0].update(target="unknown"))
        with self.assertRaises(ValueError):
            self.result()

    def test_manifest_traversal_and_symlink_refused(self):
        self.change("topology.example.json", lambda d: d["hosts"][0].update(manifest="../private.json"))
        with self.assertRaises(ValueError):
            self.result()
        self.change("topology.example.json", lambda d: d["hosts"][0].update(manifest="linked.json"))
        (self.root / "linked.json").symlink_to(self.root / "example-host-a.json")
        with self.assertRaises(ValueError):
            self.result()

    def test_native_excerpts_have_no_execution_or_mutation_branches(self):
        for name in ("controller-contract.ps1", "native-launch-contract.ps1"):
            source = (ROOT / "contracts" / name).read_text()
            self.assertNotRegex(source, r"\b(?:Start-Process|Stop-Process|Start-ScheduledTask|Stop-ScheduledTask|Invoke-RestMethod|HttpListener)\b")
            without_functions = re.sub(r"^function [A-Za-z0-9-]+ \{\n.*?^\}", "", source, flags=re.M | re.S)
            self.assertTrue(all(not line.strip() or line.lstrip().startswith("#") for line in without_functions.splitlines()))

    def test_cli_only_emits_synthetic_packet(self):
        result = subprocess.run([sys.executable, str(ROOT / "inspect_example.py")], check=True, capture_output=True, text=True)
        self.assertEqual(json.loads(result.stdout)["result"], "example_only")

    @unittest.skipUnless(shutil.which("pwsh"), "PowerShell absent; Windows qualification remains required")
    def test_native_synthetic_selftest_if_powershell_available(self):
        result = subprocess.run(["pwsh", "-NoProfile", "-File", str(ROOT / "contracts/self-test.ps1")], check=True, capture_output=True, text=True)
        data = json.loads(result.stdout)
        self.assertEqual(data["result"], "pass")
        self.assertFalse(data["process_started"])


if __name__ == "__main__":
    unittest.main()
