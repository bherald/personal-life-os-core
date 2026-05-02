#!/usr/bin/env python3
"""
Get embedding from fine-tuned model.

Usage:
    python3 get_embedding.py --model_dir /path/to/model --text "query text"

Returns JSON with embedding array.
"""

import argparse
import json
import sys
import os

import torch
from sentence_transformers import SentenceTransformer


def main():
    parser = argparse.ArgumentParser(description='Get embedding from fine-tuned model')
    parser.add_argument('--model_dir', required=True, help='Path to fine-tuned model directory')
    parser.add_argument('--text', required=True, help='Text to embed')

    args = parser.parse_args()

    if not os.path.exists(args.model_dir):
        print(json.dumps({'error': 'Model directory not found'}))
        sys.exit(1)

    try:
        # Load fine-tuned model
        device = 'cuda' if torch.cuda.is_available() else 'cpu'
        model = SentenceTransformer(args.model_dir, device=device)

        # Generate embedding
        embedding = model.encode(args.text, convert_to_numpy=True)

        # Output as JSON
        result = {
            'embedding': embedding.tolist(),
            'dimension': len(embedding),
        }

        print(json.dumps(result))

    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)


if __name__ == '__main__':
    main()
