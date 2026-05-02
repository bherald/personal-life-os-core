# Model And Runtime License Map

Status: public-release working map, last reviewed 2026-04-28.

Purpose: keep model/runtime licensing separate from PLOS source licensing. The
public repository does not redistribute model weights, dlib `.dat` files, spaCy
pipelines, Docker images, or local AI runtimes. Operators install those assets
separately and accept upstream terms.

Python package license watch items for the constraints snapshots live in
`docs/python-constraints-license-snapshot.md`; the optional native/ML/GPU
package review matrix lives in `docs/native-ml-package-review.md`.

This is engineering release triage, not legal advice. Re-check upstream model
cards before a tagged release, especially when changing recommended model names.

## Core Local AI Defaults

| Asset | Where PLOS references it | Upstream license signal | Public-release posture |
| --- | --- | --- | --- |
| Ollama runtime | `.env.example`, `config/setup.php`, AI routing docs | Ollama source is MIT; model weights served by Ollama carry separate model terms. Source: https://github.com/ollama/ollama | Runtime only. Do not bundle Ollama or model blobs. |
| `llama3.1:8b` | `.env.example` default `OLLAMA_MODEL`; setup doctor default | Llama 3.1 Community License, not a standard OSS license. Source: https://github.com/meta-llama/llama-models/blob/main/models/llama3_1/MODEL_CARD.md | Optional operator-pulled model. Do not describe as MIT/Apache or redistribute weights. |
| `nomic-embed-text` / `nomic-ai/nomic-embed-text-v1.5` | `.env.example` default embedding model; embedding training scripts | Hugging Face model card reports Apache-2.0. Source: https://huggingface.co/nomic-ai/nomic-embed-text-v1.5 | Operator-pulled embedding model. Preserve model-card link in setup docs. |
| `llava:7b` | `.env.example` default vision model | LLaVA-family weights and derivatives vary by base model; verify the exact Ollama tag/model card before recommending. Source project: https://github.com/haotian-liu/LLaVA | Optional vision model. Do not redistribute or assume a single license for all tags. |
| `BAAI/bge-base-en-v1.5` | `scripts/embeddings/cpu_embedding.py` fallback | Hugging Face model card reports MIT. Source: https://huggingface.co/BAAI/bge-base-en-v1.5 | Operator-downloaded transformer model; do not vendor. |
| `sentence-transformers/all-MiniLM-L6-v2` | fallback in embedding training script | Hugging Face model list reports Apache-2.0. Source: https://huggingface.co/sentence-transformers/all-MiniLM-L6-v2 | Operator-downloaded fallback model; do not vendor. |

## Media, OCR, Face, And Speech Assets

| Asset | Where PLOS references it | Upstream license signal | Public-release posture |
| --- | --- | --- | --- |
| dlib code | `requirements-media.txt`, face scripts | dlib is commonly distributed under the Boost Software License. Source: http://dlib.net/ | Dependency only. Keep package-manager install path. |
| dlib face model files | `docs/FACE-RECOGNITION.md`, `config/setup.php` asset checks | Large `.dat` files are downloaded by the operator from dlib-hosted URLs with checksums recorded in `docs/FACE-RECOGNITION.md`. | Do not commit model files. Setup doctor warns when absent. |
| `face_recognition` | `requirements-media.txt`, face scripts | Project repository advertises a Python face-recognition package built on dlib; package/license metadata should be verified before formal release. Source: https://github.com/ageitgey/face_recognition | Dependency only. Do not copy implementation. |
| hdbscan / scikit-learn / scipy | `requirements-media.txt`, face clustering and analysis helpers | Package-manager dependencies with permissive license signals, but transitive/native wheels should be reviewed from the pinned constraints snapshot. Sources: https://github.com/scikit-learn-contrib/hdbscan, https://scikit-learn.org/, https://scipy.org/ | Operator-installed dependencies. Keep out of source and verify pinned metadata before release. |
| igraph / leidenalg | `requirements-media.txt`, `requirements-media.constraints.txt`, community-detection scripts | GPL-family package signals: `python-igraph` is labeled GPL-2.0/GPL-2.0-or-later by upstream sources, and `leidenalg` package metadata/GitHub text signals GPL-3.0-or-later. Sources: https://github.com/igraph/python-igraph, https://igraph.org/c/html/0.10.2/igraph-Licenses.html, and https://github.com/vtraag/leidenalg | Optional media/genealogy tier only. Keep operator-installed, do not vendor, and treat as a copyleft release-signoff item before a permissive public tag. |
| spaCy `en_core_web_sm` | `config/setup.php`, media/full setup doctor | Hugging Face model card/license file reports MIT. Source: https://huggingface.co/spacy/en_core_web_sm | Operator downloads with `python -m spacy download en_core_web_sm`. |
| OpenAI Whisper | `requirements-gpu.txt`, transcription setup | Whisper code and model weights are released under MIT per upstream README. Source: https://github.com/openai/whisper | Operator-installed dependency/model cache; do not vendor weights. |
| PyTorch / torchvision | `requirements-gpu.txt`, `requirements-gpu.constraints.txt` | Runtime wheels vary by CPU/CUDA index and platform. The current GPU constraints are a default PyPI/Linux/Python 3.12 resolver snapshot and may not match another CUDA host. Source: https://pytorch.org/ | Operator-installed runtime. Public docs should tell users to choose a matching wheel/index or regenerate host-specific constraints. |
| NVIDIA CUDA Python package family | `requirements-gpu.constraints.txt` when default PyPI resolves CUDA wheels | CUDA package terms and host fit are separate from the PLOS source license and can change with the PyTorch wheel/index. Source: https://developer.nvidia.com/cuda-toolkit | Do not bundle CUDA packages or model caches. Treat as release-signoff items for GPU installs. |
| Transformers / sentence-transformers | `requirements-gpu.txt`, embedding scripts | Library code is package-manager managed; downloaded models carry separate model-card terms. Sources: https://github.com/huggingface/transformers and https://www.sbert.net/ | Keep libraries as dependencies and model weights external. |

## Python Constraint Snapshots

| File | Basis | Release posture |
| --- | --- | --- |
| `requirements-core.constraints.txt` | Passing public smoke environment on 2026-04-27. | Core public reproducibility aid. Keep aligned with `scripts/public-smoke.sh` and CI. |
| `requirements-media.constraints.txt` | Resolver-only dry run on Linux x86_64, Python 3.12, default PyPI indexes plus local media install proof. | Useful for repeatability; native face/NLP/graph posture is recorded in `docs/native-ml-package-review.md`, but foreign clean-host install evidence is still needed. |
| `requirements-gpu.constraints.txt` | Resolver-only dry run on Linux x86_64, Python 3.12, default PyPI indexes. | Platform-sensitive. Review PyTorch/CUDA/model package terms in `docs/native-ml-package-review.md` and regenerate for host-specific CUDA wheels before treating as production install evidence. |

The 2026-04-28 license audit confirmed these files are present and still flags
the media/GPU tiers as operator-installed extras, not redistributable binary or
model bundles.

## Optional External/Cloud Providers

| Provider | PLOS relationship | Public-release posture |
| --- | --- | --- |
| Claude Code CLI / Anthropic | Optional AIService provider/fallback in private or explicitly enabled installs. | Do not make it required for public core. CLI auth files and Claude memory stay private. |
| OpenAI, Gemini, Mistral, Groq, OpenRouter, Cohere, SambaNova, Cerebras | Optional external provider rows/config. | Public defaults should remain disabled or placeholder-only; users bring API keys and accept service terms. |

## Release Rules

1. Do not commit model binaries, `.dat` face model files, generated model caches,
   or provider credentials.
2. Keep model names configurable. Public defaults are suggestions, not bundled
   assets.
3. If the public README recommends a specific model, this map must include a
   source URL and current license signal for that exact model/tag.
4. Treat Ollama tags as pointers. The tag may resolve to a base model with a
   different license than the runtime.
5. Run `php artisan setup:doctor --profile=gpu` to detect missing model/runtime
   prerequisites; warnings are install guidance, not redistributed assets.
