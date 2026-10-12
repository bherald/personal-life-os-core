#!/usr/bin/env python3
"""Byte-bound review and public-audit confidentiality regression tests; no DB/network."""

import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

GUARDS = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("public_audit_filter", GUARDS / "public-audit-filter.py")
FILTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(FILTER)
LINE_RULE = "Real secret assignments with non-placeholder values"
FILE_RULE = next(iter(FILTER.FILE_RULES))


class PublicAuditReviewTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.path = "app/example.php"
        (self.root / "app").mkdir()
        self.source = self.root / self.path
        self.line = "    'secret_clear' => 'explicit_clear_secret_flag_only',"
        self.source.write_text(self.line + "\n")
        self.manifest = self.root / "reviews.json"

    def review(self, rule=LINE_RULE, whole_file=False):
        content = self.source.read_bytes()
        review = dict(rule=rule, path=self.path, file_sha256=hashlib.sha256(content).hexdigest(),
                      reason="Synthetic inventory descriptor; contains no credential value.",
                      reviewer="test-reviewer", reviewed_at="2026-10-12T17:00:00Z")
        if not whole_file:
            review.update(line=1, line_sha256=hashlib.sha256(content.splitlines()[0]).hexdigest())
        return review

    def write_manifest(self, entries):
        self.manifest.write_text(json.dumps(dict(version=1, reviews=entries)))
        return FILTER.load_reviews(self.root, self.manifest)

    def filtered(self, reviews, findings, rule=LINE_RULE):
        return list(FILTER.filter_findings(self.root, reviews, rule, findings))

    def test_exact_review_filters_only_the_reviewed_rule_and_source_line(self):
        reviews = self.write_manifest([self.review()])
        finding = f"{self.path}:1:{self.line}"
        self.assertEqual([], self.filtered(reviews, [finding]))
        self.assertEqual([finding], self.filtered(reviews, [finding], "Unreviewed rule"))
        spoof = f"{self.path}:1:modified finding text"
        self.assertEqual([spoof], self.filtered(reviews, [spoof]))

    def test_any_file_mutation_invalidates_review_even_when_original_line_is_unchanged(self):
        reviews = self.write_manifest([self.review()])
        finding = f"{self.path}:1:{self.line}"
        self.source.write_text(self.line + "\nadditional code\n")
        self.assertEqual([finding], self.filtered(reviews, [finding]))

    def test_modified_synthetic_line_fails_closed(self):
        reviews = self.write_manifest([self.review()])
        updated = self.line.replace("explicit_clear_secret_flag_only", "credential_value_changed")
        self.source.write_text(updated + "\n")
        finding = f"{self.path}:1:{updated}"
        self.assertEqual([finding], self.filtered(reviews, [finding]))

    def test_filename_findings_require_a_distinct_exact_file_review(self):
        line_reviews = self.write_manifest([self.review()])
        self.assertEqual([self.path], self.filtered(line_reviews, [self.path], FILE_RULE))
        file_reviews = self.write_manifest([self.review(FILE_RULE, whole_file=True)])
        self.assertEqual([], self.filtered(file_reviews, [self.path], FILE_RULE))
        self.source.write_text(self.line + "\nnew content\n")
        self.assertEqual([self.path], self.filtered(file_reviews, [self.path], FILE_RULE))

    def test_missing_source_stays_a_finding(self):
        reviews = self.write_manifest([self.review()])
        self.source.unlink()
        finding = f"{self.path}:1:{self.line}"
        self.assertEqual([finding], self.filtered(reviews, [finding]))

    def test_review_manifest_requires_reasons_valid_hashes_paths_and_review_metadata(self):
        for field, value in [("reason", ""), ("reviewer", ""), ("file_sha256", "bad"),
                             ("reviewed_at", "2026-10-12"), ("path", "../outside"),
                             ("line", True)]:
            with self.subTest(field=field):
                review = self.review()
                review[field] = value
                with self.assertRaises(FILTER.InvalidReview):
                    self.write_manifest([review])

    def test_path_token_and_credential_url_gates_cannot_be_exempted(self):
        for rule in ["Provider/platform token shapes", "Credentialed URLs", "Tracked local/private control files"]:
            with self.subTest(rule=rule):
                with self.assertRaises(FILTER.InvalidReview):
                    self.write_manifest([self.review(rule)])

    def test_duplicate_or_malformed_manifest_fails_closed(self):
        with self.assertRaises(FILTER.InvalidReview):
            self.write_manifest([self.review(), self.review()])
        self.manifest.write_text('{"version":1,"version":1,"reviews":[]}')
        with self.assertRaises(FILTER.InvalidReview):
            FILTER.load_reviews(self.root, self.manifest)

    def test_symlink_is_never_exempted(self):
        reviews = self.write_manifest([self.review()])
        target = self.root / "other.php"
        self.source.rename(target)
        self.source.symlink_to(target)
        finding = f"{self.path}:1:{self.line}"
        self.assertEqual([finding], self.filtered(reviews, [finding]))
        with self.assertRaises(FILTER.InvalidReview):
            FILTER.load_reviews(self.root, self.manifest)

    def test_audit_blocks_added_token_host_and_private_runtime_payload(self):
        guard_dir = self.root / "scripts/guards"
        guard_dir.mkdir(parents=True)
        for name in ["public-release-audit.sh", "public-audit-filter.py"]:
            shutil.copyfile(GUARDS / name, guard_dir / name)
        self.source.write_text(self.line + "\n")
        self.write_manifest([self.review()])
        shutil.copyfile(self.manifest, guard_dir / "public-audit-reviews.json")
        (self.root / "LICENSE").write_text("Synthetic audit fixture\n")
        schema = self.root / "database/schema"
        schema.mkdir(parents=True)
        for name in ["mysql-schema.sql", "pgsql-schema.sql"]:
            (schema / name).write_text("-- Synthetic baseline\n")
        subprocess.run(["git", "init", "-q", str(self.root)], check=True)
        self.stage()
        clean = self.audit()
        self.assertEqual(0, clean.returncode, clean.stdout)
        self.source.write_text(self.line + '\nprovider_token = "' + "sk" + "-proj-" + "a" * 24 + '"\n' +
                               'private_path = "' + "/home/" + "bill" + '/data"\n')
        payload = self.root / "storage/app/private/example.json"
        payload.parent.mkdir(parents=True)
        payload.write_text('{}\n')
        self.stage()
        blocked = self.audit()
        self.assertNotEqual(0, blocked.returncode)
        for group in [LINE_RULE, "Provider/platform token shapes", "Private paths, hosts, users, and compute labels",
                      "Tracked runtime storage payloads requiring public-extraction review"]:
            self.assertIn("== " + group + " ==", blocked.stdout)

    def stage(self):
        subprocess.run(["git", "add", "."], cwd=self.root, check=True)

    def audit(self):
        return subprocess.run(["bash", "scripts/guards/public-release-audit.sh"], cwd=self.root,
                              text=True, capture_output=True)


if __name__ == "__main__":
    unittest.main()
