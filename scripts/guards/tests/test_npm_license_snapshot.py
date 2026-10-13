"""Exact-version license evidence must survive fresh dependency installation."""

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]


class LicenseSnapshotTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="plos-license-snapshot-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        subprocess.run(["git", "init", "--quiet", str(self.root)], check=True)
        (self.root / "scripts").mkdir()
        shutil.copy2(ROOT / "scripts/snapshot-npm-licenses.sh", self.root / "scripts/snapshot-npm-licenses.sh")
        self.docs = self.root / "docs/public-release"
        self.docs.mkdir(parents=True)
        self.manifest = self.root / "node_modules/example/package.json"
        self.manifest.parent.mkdir(parents=True)
        self.manifest.write_text(json.dumps({"name": "example", "version": "1.2.3", "license": "MIT"}))
        (self.root / "package-lock.json").write_text(json.dumps({"packages": {"node_modules/example": {"version": "1.2.3"}}}))
        self.review = {"name": "example", "version": "1.2.3", "license": "MIT", "repository": "https://example.com/source", "source_url": "https://example.com/registry/example/1.2.3", "stable_snapshot": True}
        self.write_review()

    def write_review(self):
        (self.docs / "npm-reviewed-metadata.json").write_text(json.dumps({"packages": {"example@1.2.3": self.review}}))

    def run_snapshot(self, check=False):
        command = ["bash", "scripts/snapshot-npm-licenses.sh"]
        if check:
            command.append("--check")
        return subprocess.run(command, cwd=self.root, text=True, capture_output=True)

    def test_same_snapshot_with_and_without_installed_manifest(self):
        self.assertEqual(self.run_snapshot().returncode, 0)
        self.manifest.unlink()
        result = self.run_snapshot(check=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_review_cannot_override_conflicting_installed_license(self):
        self.manifest.write_text(json.dumps({"name": "example", "version": "1.2.3", "license": "GPL-3.0-only"}))
        result = self.run_snapshot()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Reviewed license metadata conflicts", result.stderr)

    def test_review_cannot_supply_a_different_version(self):
        self.manifest.unlink()
        self.review["version"] = "9.9.9"
        self.write_review()
        self.assertEqual(self.run_snapshot().returncode, 0)
        row = json.loads((self.docs / "npm-license-snapshot.json").read_text())["trees"][0]["packages"][0]
        self.assertEqual(row["license"], "")
        self.assertEqual(row["bucket"], "missing")


if __name__ == "__main__":
    unittest.main()
