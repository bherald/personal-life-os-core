# Dependency review

The earlier Tailwind3 build-chain gate was resolved by the reviewed public-only Tailwind 4 migration. All three final npm audits report zero vulnerabilities on 2026-10-12. Audit gates remain enabled. See [frontend qualification](ui-dependency-upgrade-2026-10-12.md) for browser requirements, visual differences and the checked scope.

The source-only license audit passes with 25warnings for separately licensed dependencies and optional media/GPU redistribution review; no missing metadata or exception is hidden. Model/runtime artifacts remain operator-installed and separately licensed.
