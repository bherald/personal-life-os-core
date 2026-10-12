# Exact public-audit reviews

The audit detects suspicious expressions. A reviewed detector expression,
synthetic fixture or public attribution can resemble private content. Keep the
scan enabled and review individual findings in `public-audit-reviews.json`.
This is a release preparation aid, not permission to publish or identify people.

Each entry requires the exact audit group title (`rule`), normalized repository
`path`, SHA-256 of the entire file bytes (`file_sha256`), a specific reason,
reviewer and ISO-8601 `reviewed_at` timestamp with timezone. For a `path:line:text`
finding, also require a positive `line` and SHA-256 of that exact line without
its trailing newline (`line_sha256`). The source text is not stored in the
manifest. To compute a line hash, hash `Path(path).read_bytes().splitlines()[line - 1]`.

Review the entire source file before adding a filename-only finding; filename
reviews apply only to the aggregate private-reference report. They do not
suppress the corresponding line report. Keep one entry per finding and rule.

Only the helper's listed content-review groups permit allowances. Token-shape,
credentialed-URL, tracked control-file, archive, vendored-dependency and runtime
payload gates cannot be suppressed. Do not add a secret, private identity,
private host/path or confidential evidence to an allowance. Remove or generalize
that content in the public preparation tree.

A changed file invalidates every review for it, including when the originally
reviewed line stays unchanged. Changed line numbers/text, missing files and
symlinks remain findings. Malformed JSON, missing reasons/review metadata,
duplicate entries, invalid paths and unsupported rule allowances stop the audit.
There are no filename globs or regular-expression allowances.

Run from the repository root:

```bash
python3 scripts/guards/public-audit-filter.py --root . \
  --manifest scripts/guards/public-audit-reviews.json --validate
python3 scripts/guards/tests/test_public_audit_filter.py
scripts/guards/public-release-audit.sh
```

Review the manifest diff and rerun the audit on the final staged public candidate
bytes. A passed audit is one prerequisite; licenses, installation checks,
release documentation and exact operator approval remain separate requirements.
