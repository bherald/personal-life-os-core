# Native And ML Package Review

Status: public-release engineering review, refreshed 2026-04-28.

Purpose: record the release posture for optional Python, native, ML, GPU, and
model-adjacent packages that are not fully captured by Composer/npm license
tools. This is engineering triage, not legal advice.

## Decision

No hard blocker was found for a source-only MIT public repository if these rules
hold:

1. Python media/GPU packages stay package-manager/operator-installed.
2. PLOS does not vendor GPL/LGPL wheels, NVIDIA CUDA packages, dlib model
   files, spaCy pipelines, Whisper/model caches, or Hugging Face/Ollama model
   weights.
3. GPU support is documented as optional/experimental until a host-specific
   CUDA/PyTorch proof exists.
4. Any future redistributable image or bundled media profile must be reviewed
   again, especially for `igraph`, `leidenalg`, `psycopg2-binary`, NVIDIA CUDA
   packages, and model weights.

## Package Matrix

| Package or group | Tier | License signal | Why PLOS uses it | Public-release posture |
| --- | --- | --- | --- | --- |
| `psycopg2-binary` | core/media/gpu | LGPL-family metadata signal. | Python access to PostgreSQL/pgvector helper paths. | Allow as operator-installed package-manager dependency. Do not vendor wheels or create a binary distribution without review. |
| `igraph` | media/gpu | Upstream `python-igraph` / igraph sources signal GPL-2.0 or GPL-2.0-or-later. | Optional graph/community-detection helpers. | Copyleft watch item. Keep optional/operator-installed. Do not bundle into a permissive public artifact. |
| `leidenalg` | media/gpu | GPL-3.0-or-later classifier / upstream signal. | Leiden community detection over igraph graphs. | Copyleft watch item. Keep optional/operator-installed. Do not bundle into a permissive public artifact. |
| `dlib` | media/gpu | Boost Software License signal. | Face detection and encoding backend. | Allow as package-manager dependency. Do not commit wheels or model `.dat` files. |
| `face_recognition` | media/gpu | Permissive upstream signal; verify exact SPDX during final signoff. | High-level face encoding API. | Allow as package-manager dependency. Do not copy implementation code. |
| `face_recognition_models` | media/gpu | Package/model metadata needs explicit final verification. | Pretrained dlib face models used by `face_recognition`. | Watch item. Operator-installed only; do not vendor model files. The current `setuptools==80.9.0` pin exists because this package imports `pkg_resources`. |
| `setuptools==80.9.0` | media/gpu | MIT. | Compatibility pin for `face_recognition_models` / `pkg_resources`. | Allow with technical-debt note. Revisit when the face-recognition dependency path is replaced or patched. |
| `hdbscan` | media/gpu | Permissive upstream signal; pip report field was blank in the current snapshot. | Face clustering. | Allow pending final metadata confirmation. |
| `numpy`, `scipy`, `scikit-learn`, `Pillow` | core/media/gpu | Standard permissive scientific Python signals. | Numerical, ML, and image helpers. | Allow as package-manager dependencies. |
| `spaCy` and Explosion support packages | media/gpu | MIT/permissive signals for library code; downloaded model pipelines carry their own terms. | NLP extraction and parsing. | Allow as package-manager dependencies. Do not vendor spaCy model pipelines. |
| `certifi` | media/gpu | MPL 2.0. | TLS root bundle used by requests stack. | Allow as dependency; keep noted because MPL is not MIT/BSD/Apache. |
| `torch`, `torchvision`, `triton` | gpu | PyTorch/torchvision runtime metadata varies by wheel/index; triton reports MIT. | Optional transformer, Whisper, and embedding runtime. | Operator-installed only. Users must select a host-matching PyTorch wheel/index. Do not treat the generic GPU constraints snapshot as a universal install lock. |
| NVIDIA CUDA Python package family | gpu | NVIDIA software-license/proprietary package signals on CUDA packages; some metadata blank. | Optional CUDA runtime resolved by GPU stack. | Do not bundle. Host-specific operator install only. Hard review gate before any redistributable GPU image. |
| `openai-whisper` and `tiktoken` | gpu | Upstream MIT signal; pip report field was blank in the current snapshot. | Optional speech/transcription path. | Allow as operator-installed packages. Do not vendor model caches. |
| `transformers`, `sentence-transformers`, `huggingface_hub`, `tokenizers`, `safetensors` | gpu | Apache/permissive library signals; downloaded model cards vary. | Optional embeddings and transformer model loading. | Allow libraries as dependencies. Model weights stay external and must be reviewed by exact model/tag when recommended. |

## 2026-04-28 Audit Evidence

- `scripts/audit-licenses.sh` passed with 16 warnings and confirmed all three
  Python profiles have pinned constraints files.
- Current media constraints pin `psycopg2-binary==2.9.12`,
  `face-recognition==1.3.0`, `dlib==19.24.9`, `hdbscan==0.8.42`,
  `igraph==0.11.9`, `leidenalg==0.10.2`, `spacy==3.8.14`,
  `certifi==2026.4.22`, `face_recognition_models==0.3.0`, and
  `setuptools==80.9.0`.
- Current GPU constraints additionally pin `torch==2.11.0`,
  `torchvision==0.26.0`, `triton==3.6.0`, `transformers==4.57.6`,
  `sentence-transformers==5.4.1`, `tokenizers==0.22.2`,
  `openai-whisper==20250625`, `safetensors==0.7.0`, and
  `tiktoken==0.12.0`.

## Release Rules

- Source-only MIT public release: acceptable with current operator-installed
  posture and documented warnings.
- Media profile: acceptable as optional, but not a fully permissive bundled
  media artifact while `igraph`/`leidenalg` are included.
- GPU profile: publish as optional/experimental unless a clean GPU host proof is
  completed. The current `requirements-gpu.constraints.txt` is a resolver
  snapshot, not a universal CUDA install plan.
- Docker images: do not bake GPL graph packages, CUDA wheels, model weights, or
  private assets into redistributable images without a new review.
- Model/runtime assets: keep aligned with `docs/model-runtime-license-map.md`.

## Follow-Up Before A Public Tag

1. Re-run Python resolver reports for core, media, and GPU constraints.
2. Confirm SPDX/license metadata for packages whose pip report was blank:
   `dlib`, `hdbscan`, `charset-normalizer`, `spacy-loggers`, `tqdm`, `wasabi`,
   `torch`, `torchvision`, `openai-whisper`, `tiktoken`, and
   `face_recognition_models`.
3. Decide whether `igraph`/`leidenalg` remain optional GPL-signaled extras or
   should be replaced for a fully permissive media profile.
4. Keep `psycopg2-binary` and `certifi` notices visible for any future binary
   distribution.
5. For GPU users, document host-specific PyTorch/CUDA wheel selection or
   regenerate constraints on the target host.

## Related Files

- `requirements-core.constraints.txt`
- `requirements-media.constraints.txt`
- `requirements-gpu.constraints.txt`
- `docs/python-constraints-license-snapshot.md`
- `docs/model-runtime-license-map.md`
- `THIRD_PARTY.md`
- `NOTICE.md`
- `scripts/audit-licenses.sh`
