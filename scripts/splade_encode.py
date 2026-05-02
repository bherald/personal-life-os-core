#!/usr/bin/env python3
"""
SPLADE sparse encoding for RAG-3 three-way hybrid search.

Input (stdin JSON): {"texts": ["text1", "text2", ...]}
Output (stdout JSON): {"vectors": [{"indices": [1,5,23,...], "weights": [0.8,0.3,0.1,...]}, ...]}

Model: naver/splade-cocondenser-ensembledistil (~500MB, CPU-friendly)
First run downloads model to ~/.cache/huggingface/
"""

import sys
import json
import os

# Suppress HuggingFace/torch warnings before importing transformer stack so
# stdout stays machine-readable JSON for ComputeRouterService callers.
os.environ["TRANSFORMERS_VERBOSITY"] = "error"
os.environ["HF_HUB_DISABLE_PROGRESS_BARS"] = "1"
os.environ["HF_HUB_DISABLE_IMPLICIT_TOKEN"] = "1"

def main():
    try:
        input_data = json.loads(sys.stdin.read())
    except json.JSONDecodeError as e:
        print(json.dumps({"error": str(e), "vectors": [], "vocab_size": 0}))
        sys.exit(1)

    texts = input_data.get("texts", [])

    if not texts:
        print(json.dumps({"vectors": [], "vocab_size": 0}))
        return

    # Suppress HuggingFace/torch warnings that contaminate stdout via 2>&1.
    import warnings
    warnings.filterwarnings("ignore")

    # Lazy import — only load heavy deps when actually called
    import torch
    from transformers import AutoModelForMaskedLM, AutoTokenizer
    import logging
    logging.getLogger("transformers").setLevel(logging.ERROR)

    model_name = os.environ.get("SPLADE_MODEL", "naver/splade-cocondenser-ensembledistil")
    cache_dir = os.environ.get("HF_HOME", os.path.expanduser("~/.cache/huggingface"))

    tokenizer = AutoTokenizer.from_pretrained(model_name, cache_dir=cache_dir)
    model = AutoModelForMaskedLM.from_pretrained(model_name, cache_dir=cache_dir)
    model.eval()

    vectors = []
    with torch.no_grad():
        for text in texts:
            # Truncate to model max length
            inputs = tokenizer(text, return_tensors="pt", max_length=512, truncation=True, padding=True)
            output = model(**inputs)

            # SPLADE: log(1 + ReLU(logits)) aggregated over tokens
            logits = output.logits
            splade_vec = torch.max(torch.log1p(torch.relu(logits)), dim=1).values.squeeze()

            # Extract non-zero entries (sparse representation)
            nonzero = splade_vec.nonzero().squeeze(-1)
            indices = nonzero.tolist()
            weights = splade_vec[nonzero].tolist()

            # Ensure lists
            if isinstance(indices, int):
                indices = [indices]
                weights = [weights]

            vectors.append({
                "indices": indices,
                "weights": [round(w, 4) for w in weights],
            })

    vocab_size = tokenizer.vocab_size
    print(json.dumps({"vectors": vectors, "vocab_size": vocab_size}))

if __name__ == "__main__":
    main()
