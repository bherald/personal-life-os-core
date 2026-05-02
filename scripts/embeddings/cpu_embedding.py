#!/usr/bin/env python3
"""
CPU embedding fallback using BAAI/bge-base-en-v1.5 via transformers.

Reads text from stdin, outputs JSON with 768-dim embedding to stdout.
Uses transformers AutoModel (no sentence-transformers dependency).
Model auto-downloads on first run (~440MB) to HuggingFace cache.

Usage:
    echo "text to embed" | python3 cpu_embedding.py
    python3 cpu_embedding.py --text "text to embed"
    python3 cpu_embedding.py --check  # verify model loads

Output: {"embedding": [...768 floats...], "dimension": 768, "model": "BAAI/bge-base-en-v1.5"}
Error:  {"error": "message"}
"""

import argparse
import json
import sys
import os

# Force CPU-only — this is the CPU fallback, never use GPU
os.environ['CUDA_VISIBLE_DEVICES'] = ''

MODEL_NAME = 'BAAI/bge-base-en-v1.5'
MAX_LENGTH = 512  # bge-base-en-v1.5 max sequence length


def load_model():
    """Load tokenizer and model. Downloads on first use."""
    import torch
    from transformers import AutoTokenizer, AutoModel

    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME)
    model = AutoModel.from_pretrained(MODEL_NAME)
    model.eval()
    return tokenizer, model


def generate_embedding(text, tokenizer, model):
    """Generate a 768-dim embedding for the given text."""
    import torch

    # BGE models recommend prepending "Represent this sentence: " for retrieval
    # but for general-purpose embedding we skip the prefix for consistency
    encoded = tokenizer(
        text,
        padding=True,
        truncation=True,
        max_length=MAX_LENGTH,
        return_tensors='pt',
    )

    with torch.no_grad():
        outputs = model(**encoded)

    # CLS token pooling (standard for BGE models)
    embedding = outputs.last_hidden_state[:, 0, :]

    # L2 normalize
    embedding = torch.nn.functional.normalize(embedding, p=2, dim=1)

    return embedding[0].tolist()


def main():
    parser = argparse.ArgumentParser(description='CPU embedding fallback')
    parser.add_argument('--text', help='Text to embed (alternative to stdin)')
    parser.add_argument('--check', action='store_true', help='Verify model loads and exit')
    args = parser.parse_args()

    try:
        tokenizer, model = load_model()

        if args.check:
            # Verify with a test embedding
            test_emb = generate_embedding('test', tokenizer, model)
            print(json.dumps({
                'status': 'ok',
                'model': MODEL_NAME,
                'dimension': len(test_emb),
            }))
            return

        # Get text from --text arg or stdin
        if args.text:
            text = args.text
        else:
            text = sys.stdin.read().strip()

        if not text:
            print(json.dumps({'error': 'No text provided'}))
            sys.exit(1)

        embedding = generate_embedding(text, tokenizer, model)

        print(json.dumps({
            'embedding': embedding,
            'dimension': len(embedding),
            'model': MODEL_NAME,
        }))

    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)


if __name__ == '__main__':
    main()
