# Model And Runtime License Map

Runtime code, model weights, converted artifacts, package wheels and service
images have separate terms. This map retains recorded upstream license signals
and sources for release review; it does not describe an installed private
runtime or certify current model terms. Verify the exact recommended tag,
revision and component terms before a release or redistribution.

The public source repository does not bundle model weights, dlib model files,
spaCy pipelines, Docker images, GPU packages or local AI runtime binaries.
Package constraints and license snapshots document dependencies; they do not
transfer permission to redistribute every resolved artifact.

## Local AI runtime and model references

| Asset | Recorded upstream signal and primary source | Release handling |
| --- | --- | --- |
| Ollama runtime | MIT runtime source: https://github.com/ollama/ollama | Install separately. Each served model has its own terms. |
| Llama 3.1 family (`llama3.1:8b`) | Llama 3.1 Community License, not a standard OSS license: https://github.com/meta-llama/llama-models/blob/main/models/llama3_1/MODEL_CARD.md | Do not describe as MIT/Apache. Verify the exact model and redistribution conditions. |
| Nomic embedding model | `nomic-ai/nomic-embed-text-v1.5` model card records Apache-2.0: https://huggingface.co/nomic-ai/nomic-embed-text-v1.5 | Operator-downloaded weights; preserve the model-card source. |
| LLaVA-family vision models | Base model and derivative terms vary: https://github.com/haotian-liu/LLaVA | Verify the exact tag and base model; do not assume one family-wide license. |
| `BAAI/bge-base-en-v1.5` | Model card records MIT: https://huggingface.co/BAAI/bge-base-en-v1.5 | Operator-downloaded model, not vendored. |
| `sentence-transformers/all-MiniLM-L6-v2` | Model card records Apache-2.0: https://huggingface.co/sentence-transformers/all-MiniLM-L6-v2 | Operator-downloaded model, not vendored. |
| `Qwen/Qwen3-Reranker-0.6B` | Model card records Apache-2.0: https://huggingface.co/Qwen/Qwen3-Reranker-0.6B | Verify exact artifact identity and terms. Installation does not promote a model into an application route. |
| Colibri runtime | Apache-2.0 upstream/fork records: https://github.com/JustVugg/colibri/releases/tag/v1.1.1 and https://github.com/bherald/colibri; additional release reference https://github.com/JustVugg/colibri/releases/tag/v1.10.1 | Preserve applicable Apache notices, modification records and source availability. Do not bundle local/custom binaries or treat upstream release metadata as installation proof. |
| GLM-5.2 and community conversion | Community conversion card records MIT and identifies the official base: https://huggingface.co/mastouri/GLM-5.2-colibri-E8-IQ3-with-int8-mtp and https://huggingface.co/zai-org/GLM-5.2 | Review both converted artifact and base-model terms; runtime licensing does not relicense weights. No routing/quality claim follows from the card. |

Model names remain configurable; the authoritative source for actual public
defaults is the shipped configuration and `.env.example`, not this catalogue.
Tags can change their underlying artifact. A source release's model examples
are not redistributed assets or measurements of another operator's system.

## Media, face, graph and speech dependencies

| Asset | Recorded signal and source | Release handling |
| --- | --- | --- |
| dlib code | Boost Software License signal: http://dlib.net/ | Dependency install only; review model/data terms separately. |
| dlib face model files | dlib-hosted assets referenced in `docs/FACE-RECOGNITION.md` | Keep external. Verify the exact downloaded file and its terms rather than inherit the library's code license. |
| `face_recognition` | Package source: https://github.com/ageitgey/face_recognition | Verify pinned package/license metadata; keep package-manager installation. |
| hdbscan, scikit-learn, scipy | Permissive package signals: https://github.com/scikit-learn-contrib/hdbscan, https://scikit-learn.org/, https://scipy.org/ | Review pinned transitive/native-wheel terms and host compatibility. |
| igraph / leidenalg | `python-igraph` GPL-2.0/GPL-2.0-or-later signals; leidenalg GPL-3.0-or-later signals: https://github.com/igraph/python-igraph, https://igraph.org/c/html/0.10.2/igraph-Licenses.html, https://github.com/vtraag/leidenalg | Optional graph tier. Review applicable copyleft obligations before distribution; do not erase this watch item from a permissively licensed source release. |
| spaCy `en_core_web_sm` | Model-card/license MIT signal: https://huggingface.co/spacy/en_core_web_sm | Operator-downloaded pipeline with separately checked asset identity. |
| OpenAI Whisper | MIT code/model terms recorded in upstream README: https://github.com/openai/whisper | Separate runtime/model installation; do not vendor weights. |
| PyTorch / torchvision | Platform/index-specific runtime wheels: https://pytorch.org/ | Choose a compatible CPU/CUDA wheel set; regenerate constraints where needed. |
| NVIDIA CUDA package family | Separate NVIDIA component terms: https://developer.nvidia.com/cuda-toolkit | GPU release watch item; no bundled CUDA package/cache. |
| Transformers / sentence-transformers | Libraries: https://github.com/huggingface/transformers and https://www.sbert.net/ | Libraries use package terms; downloaded model weights have their own model-card terms. |

## Dependency snapshots and external providers

Keep `requirements-core.constraints.txt`, `requirements-media.constraints.txt`
and `requirements-gpu.constraints.txt` aligned with their intended profiles.
A resolver snapshot is not clean-host installation evidence. Media/native/GPU
packages need platform, license and artifact review in addition to resolution.
See [Python constraints](python-constraints-license-snapshot.md) and
[native/ML review](native-ml-package-review.md).

External providers are optional. Public configuration must contain disabled
routes or placeholders, never provider credentials or account-specific access,
billing or subscription claims. Operators supply their own authorized accounts
and accept the relevant service terms.

## Release checks

1. Keep binaries, model caches, face `.dat` files and credentials out of Git.
2. Record a primary model-card source and exact applicable terms for each
   recommended model/tag, including converted/base components.
3. Preserve copyright, license, attribution, modification and source-availability
   obligations where applicable. Runtime and weight licenses remain distinct.
4. Review the dependency snapshots and optional copyleft/GPU/model watch items.
5. Run the documented setup/smoke checks on the intended install profile;
   warnings guide installation and do not establish redistribution rights.
