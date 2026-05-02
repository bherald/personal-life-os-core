# Clean-Room References

PLOS can learn from other projects without copying them. This document defines
the public clean-room boundary for reference projects, standards, and
developer-agent tools.

## Reference-Only Projects

Examples include Gramps, Gramps Web, webtrees, PhotoPrism, LibrePhotos, Joplin,
Nextcloud, SearXNG, Thunderbird, Codex CLI, Clawcode, Cline, Roo Code, Aider,
Continue, OpenHands, SWE-agent, goose, and similar systems.

Some of these projects use GPL, AGPL, LGPL, Apache, MIT, MPL, or mixed
licensing. Their upstream license files remain authoritative.

## Allowed Uses

- Feature comparison.
- Workflow analysis.
- Public API, file format, and standards compatibility.
- Data-model concepts described independently.
- Public behavior tests written from observed behavior or documentation.
- Non-code architecture notes.
- Dependency and license impact review.

## Disallowed Uses

- Copying GPL/AGPL implementation code into the MIT public core.
- Translating source line-for-line.
- Porting prompt, rule, workflow, hook, skill, or system files from another
  developer-agent project.
- Importing upstream fixtures without license and provenance review.
- Using private production data as public examples.
- Reusing screenshots, icons, UI copy, comments, or documentation wording
  without explicit permission and attribution review.

## Evidence Expected Before Implementation

Before building from reference research, keep a short record of:

- source list and public URLs;
- non-code behavior notes;
- standards or API compatibility targets;
- independent design decision;
- fixture provenance;
- dependency and license impact;
- public/private boundary review.

## Face And Media Rule

Face-region metadata work should be described through XMP/MWG/IPTC fields and
ExifTool-compatible read/write behavior. Do not copy photo-manager
implementation logic.

## Genealogy Rule

GEDCOM and public archival standards may be used as compatibility targets.
Subscription or account-gated genealogy providers should remain manual,
browser-only, or private opt-in unless their terms and technical access are
explicitly reviewed.

## Developer-Agent Rule

Developer-agent tools can inform PLOS approval gates, ignored-file concepts,
trace logging, local-model fallback, and command allowlists. Do not copy their
source, prompts, agent definitions, rules, docs wording, or test fixtures.

Before citing a developer-agent project publicly, verify the current repository
URL, license text, docs URL, and whether any enterprise or proprietary subtrees
exist.

`docs/research-provenance.md` keeps the current developer-agent watch list.
Claw Code and similar Claude Code analogues require extra caution because their
public descriptions are tied to leaked-source architecture claims. Treat them
as citation-only until repository identity, license, and provenance are
reviewed.
