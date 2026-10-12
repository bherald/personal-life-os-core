#!/usr/bin/env python3
"""Filter only individually reviewed public-audit findings at exact file bytes."""

import argparse
import datetime
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import sys

# Payload-path, token-shape and credentialed-URL gates are never suppressible.
LINE_RULES = {
    "Real secret assignments with non-placeholder values",
    "Private keys, certificates, or encrypted key material",
    "Private paths, hosts, users, and compute labels",
    "Public-bound source containing private genealogy/person literals",
    "PhotoPrism provenance language requiring review",
    "Dev-agent copied-provenance language requiring review",
}
FILE_RULES = {"Files containing private paths, LAN hosts, usernames, or machine-specific values"}
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
FINDING = re.compile(r"([^:]+):([1-9][0-9]*):(.*)\Z")


class InvalidReview(ValueError):
    pass


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidReview("duplicate JSON property")
        result[key] = value
    return result


def digest(data):
    return hashlib.sha256(data).hexdigest()


def load_reviews(root, manifest):
    data = json.loads(manifest.read_text(encoding="utf-8"), object_pairs_hook=unique_object)
    if not isinstance(data, dict) or set(data) != {"version", "reviews"} or type(data["version"]) is not int or data["version"] != 1 or not isinstance(data["reviews"], list):
        raise InvalidReview("invalid manifest structure")
    reviews = {}
    for entry in data["reviews"]:
        common = {"rule", "path", "file_sha256", "reason", "reviewer", "reviewed_at"}
        if not isinstance(entry, dict) or set(entry) not in (common, common | {"line", "line_sha256"}):
            raise InvalidReview("invalid review fields")
        for field in common:
            if not isinstance(entry[field], str) or not entry[field].strip():
                raise InvalidReview("missing review value")
        try:
            timestamp = datetime.datetime.fromisoformat(entry["reviewed_at"].replace("Z", "+00:00"))
            if timestamp.tzinfo is None:
                raise ValueError()
        except ValueError as error:
            raise InvalidReview("review timestamp must include timezone") from error
        path = PurePosixPath(entry["path"])
        if path.is_absolute() or ".." in path.parts or str(path) != entry["path"] or ":" in entry["path"] or "\\" in entry["path"] or any(ord(char) < 32 for char in entry["path"]):
            raise InvalidReview("review path must be a normalized repository-relative path")
        source = root / entry["path"]
        if not source.resolve().is_relative_to(root) or source.is_symlink():
            raise InvalidReview("review path escapes repository or is a symlink")
        if not SHA256.fullmatch(entry["file_sha256"]):
            raise InvalidReview("invalid file hash")
        line = entry.get("line")
        if line is None:
            if entry["rule"] not in FILE_RULES:
                raise InvalidReview("file allowance is forbidden for this rule")
        elif entry["rule"] not in LINE_RULES or type(line) is not int or line < 1 or not isinstance(entry["line_sha256"], str) or not SHA256.fullmatch(entry["line_sha256"]):
            raise InvalidReview("invalid line allowance or forbidden rule")
        key = (entry["rule"], entry["path"], line)
        if key in reviews:
            raise InvalidReview("duplicate finding review")
        reviews[key] = entry
    return reviews


def filter_findings(root, reviews, rule, findings):
    files = {}
    for finding in findings:
        match = FINDING.fullmatch(finding)
        path, line, text = (match[1], int(match[2]), match[3]) if match else (finding, None, None)
        review = reviews.get((rule, path, line))
        if review is None:
            yield finding
            continue
        if path not in files:
            source = root / path
            try:
                files[path] = source.read_bytes() if source.is_file() and not source.is_symlink() and source.resolve().is_relative_to(root) else None
            except OSError:
                files[path] = None
        content = files[path]
        if content is None or digest(content) != review["file_sha256"]:
            yield finding
            continue
        if line is not None:
            lines = content.splitlines()
            if line > len(lines) or digest(lines[line - 1]) != review["line_sha256"] or lines[line - 1] != text.encode("utf-8"):
                yield finding


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--rule")
    parser.add_argument("--validate", action="store_true")
    args = parser.parse_args()
    if not args.validate and not args.rule:
        parser.error("--rule is required when filtering")
    try:
        root = args.root.resolve(strict=True)
        reviews = load_reviews(root, args.manifest)
        if not args.validate:
            for finding in filter_findings(root, reviews, args.rule, sys.stdin.read().splitlines()):
                print(finding)
    except (OSError, ValueError, TypeError, KeyError) as error:
        # Do not repeat malformed input, source lines or paths in diagnostics.
        print("Public-audit review manifest invalid; audit stopped (" + type(error).__name__ + ").", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
