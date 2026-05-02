# Python Constraints License Snapshot

Status: public-release working snapshot, generated 2026-04-27 from pip
`--dry-run --report` metadata on Linux x86_64, Python 3.12, default PyPI
indexes, then adjusted by disposable media-tier install/import proof. Refreshed
against local audit output on 2026-04-28 and clean-host media proof VM installed
metadata on 2026-05-01.

Purpose: record the license/provenance watch items that are not visible from
the requirements files alone. The fuller native/ML package review is in
`docs/native-ml-package-review.md`. This is engineering release triage, not
legal advice. Re-run the pip reports and update these files whenever the
constraints change.

## Constraint Files

| File | Basis | Release posture |
| --- | --- | --- |
| `requirements-core.constraints.txt` | Passing public smoke environment. | Core reproducibility aid. |
| `requirements-media.constraints.txt` | Resolver dry run plus disposable media venv install/import proof in the local verification environment and a separate Ubuntu proof VM. | Clean-host install evidence exists; still needs final dependency/license signoff before formal public tagging. |
| `requirements-gpu.constraints.txt` | Resolver-only dry run for optional GPU/transformer dependencies. | Platform-sensitive; needs host-specific PyTorch/CUDA validation. |

## Core Installed Metadata Snapshot

On 2026-04-29, `scripts/snapshot-python-licenses.sh --tier=core --install`
generated `docs/public-release/python-license-snapshot-core.json` and
`docs/public-release/python-license-snapshot-core.md` from a disposable
virtualenv plus `requirements-core.constraints.txt`.

The core snapshot covers 4 installed packages: 2 permissive, 1 watch item, and
1 LGPL-family item. The watch rows are `psycopg2-binary` with `LGPL with
exceptions` metadata and `tqdm` with `MPL-2.0 AND MIT` metadata.

## Media Installed Metadata Snapshot

On 2026-05-01, after the media tier installed successfully on a separate Ubuntu
24.04.4 proof VM, `scripts/snapshot-python-licenses.sh --tier=media --venv=.venv`
generated `docs/public-release/python-license-snapshot-media.json` and
`docs/public-release/python-license-snapshot-media.md` from the installed proof
virtualenv plus `requirements-media.constraints.txt`.

The media snapshot covers 57 installed packages: 50 permissive, 2 watch items,
2 LGPL-family items, 1 GPL-family item, and 2 other/unclassified rows. It is
stronger evidence than the earlier resolver-only report, but it is still
release-diligence metadata rather than legal advice or final signoff. Keep the
media tier optional/operator-installed unless the GPL/LGPL watch items are
accepted deliberately or formally reviewed.

## Media Tier Watch Items

| Package | Pinned version | Metadata signal | Public-release posture |
| --- | ---: | --- | --- |
| `igraph` | 0.11.9 | pip report did not expose a license field; upstream `python-igraph`/igraph sources signal GPL-2.0/GPL-2.0-or-later. | Optional/operator-installed graph package. Do not vendor. Review before treating a permissive public media tier as release-ready. |
| `leidenalg` | 0.10.2 | pip classifier reports GPLv3-or-later. | Optional/operator-installed graph package. Do not vendor. Review before treating a permissive public media tier as release-ready. |
| `psycopg2-binary` | 2.9.12 | pip classifier reports LGPL-family terms. | Operator-installed dependency for PostgreSQL helper paths; review redistribution obligations. |
| `certifi` | 2026.4.22 | pip classifier reports MPL 2.0. | Normal dependency, but keep in watch list because MPL is not MIT/BSD/Apache. |
| `setuptools` | 80.9.0 | MIT, but intentionally pinned below 81. | Compatibility pin: `face_recognition_models 0.3.0` imports `pkg_resources`, and setuptools 81+ broke that import path in this environment. Revisit when face-recognition dependencies are replaced or patched. |
| `dlib`, `hdbscan`, `charset-normalizer`, `spacy-loggers`, `tqdm`, `wasabi` | see constraints | pip report metadata was blank or incomplete for the field inspected. | Verify upstream metadata during formal dependency signoff. |

## GPU Tier Watch Items

The GPU tier inherits the media-tier watch items and adds platform-sensitive
runtime packages:

| Package group | Pinned versions | Metadata signal | Public-release posture |
| --- | --- | --- | --- |
| PyTorch / torchvision / triton | `torch==2.11.0`, `torchvision==0.26.0`, `triton==3.6.0` | pip metadata was blank for torch/torchvision in this report; triton reports MIT classifier. | Operator-installed runtime. Choose the host-appropriate PyTorch wheel/index before using the constraints as install evidence. |
| NVIDIA CUDA package family | `cuda-toolkit==13.0.2`, `cuda-bindings==13.2.0`, `nvidia-*` CUDA 13 packages | pip metadata includes NVIDIA software-license/proprietary signals on several packages and blank metadata on others. | Do not bundle. Treat as GPU release-signoff items and host-specific runtime dependencies. |
| `openai-whisper`, `tiktoken` | see constraints | pip report metadata was blank for the field inspected. | Verify upstream metadata/model terms during formal signoff; do not vendor model caches. |

## Media Install Evidence

On 2026-04-27, a disposable local verification virtualenv successfully
installed `requirements-media.txt` with `requirements-media.constraints.txt`
and imported the main media packages: `dlib 19.24.9`, `face_recognition_models
0.3.0`, `hdbscan 0.8.42`, `igraph 0.11.9`, `leidenalg 0.10.2`, `numpy 2.4.4`,
`Pillow 11.3.0`, `psycopg2-binary 2.9.12`, `scipy 1.17.1`, `scikit-learn
1.8.0`, and `spacy 3.8.14`.

On 2026-05-01, the separate Ubuntu proof VM repeated the media install from the
history-free public export after installing required media OS packages. `dlib`
19.24.9 built from source, `face_recognition_models` 0.3.0 built a local wheel,
and the media setup doctor then reported 101 pass, 9 warn, 0 fail, 1 skip with
Docker-remapped MySQL, Redis, and PostgreSQL/pgvector service ports. The
remaining warnings were optional add-ons: Nextcloud data path, LibreOffice /
`soffice`, spaCy `en_core_web_sm`, Tika, SearXNG, Playwright Chromium, and
external dlib model files.

The first import proof failed with `ModuleNotFoundError: No module named
'pkg_resources'` because the resolver-selected setuptools release did not
provide the import path expected by `face_recognition_models 0.3.0`. Pinning
`setuptools==80.9.0` fixed the import. This is a compatibility pin, not a
long-term dependency strategy.

## 2026-04-28 Audit Refresh

`scripts/audit-licenses.sh` passed and confirmed pinned constraints for
`requirements-core.txt`, `requirements-media.txt`, and `requirements-gpu.txt`.
The warnings remain unchanged in posture: media constraints include GPL-signaled
`igraph`/`leidenalg` and LGPL-signaled `psycopg2-binary`; GPU constraints are
platform-sensitive and include NVIDIA software-license/proprietary package
signals. No redistributable wheel, CUDA package, model cache, or face model file
is part of the source-only public release packet.

## Release Rules

1. Keep these packages as package-manager/operator-installed dependencies.
2. Do not vendor GPL, LGPL, NVIDIA runtime, model, or binary assets into the
   MIT public source tree.
3. Regenerate and review this snapshot before a tagged public release or any
   Python constraints update.
4. Keep `scripts/audit-licenses.sh`, `NOTICE.md`, `THIRD_PARTY.md`, and
   `docs/model-runtime-license-map.md` aligned with this file.

## Source Pointers

- https://github.com/igraph/python-igraph
- https://igraph.org/c/html/0.10.2/igraph-Licenses.html
- https://github.com/vtraag/leidenalg
- https://developer.nvidia.com/cuda-toolkit
- https://www.psycopg.org/docs/install.html
