#!/usr/bin/env python3
"""
Embedding Fine-Tuning Script for PLOS RAG System

Fine-tunes nomic-embed-text on domain-specific data using
contrastive learning with sentence-transformers.

Usage:
    python3 train_embeddings.py \
        --training_file /path/to/training_pairs.jsonl \
        --eval_file /path/to/eval_pairs.jsonl \
        --output_dir /path/to/output \
        --base_model nomic-ai/nomic-embed-text-v1.5 \
        --epochs 3 \
        --batch_size 32 \
        --job_id ft_abc123
"""

import argparse
import json
import os
import sys
from datetime import datetime
from typing import List, Dict, Any

import torch
from torch.utils.data import DataLoader
from sentence_transformers import SentenceTransformer, InputExample, losses
from sentence_transformers.evaluation import EmbeddingSimilarityEvaluator


def load_training_data(filepath: str) -> List[InputExample]:
    """Load training pairs from JSONL file."""
    examples = []

    with open(filepath, 'r') as f:
        for line in f:
            data = json.loads(line.strip())

            if 'positive' in data:
                # Positive pair
                examples.append(InputExample(
                    texts=[data['anchor'], data['positive']],
                    label=1.0
                ))
            elif 'negative' in data:
                # Negative pair
                examples.append(InputExample(
                    texts=[data['anchor'], data['negative']],
                    label=0.0
                ))

    return examples


def load_eval_data(filepath: str) -> tuple:
    """Load evaluation pairs for similarity evaluation."""
    sentences1 = []
    sentences2 = []
    scores = []

    with open(filepath, 'r') as f:
        for line in f:
            data = json.loads(line.strip())

            if 'positive' in data:
                sentences1.append(data['anchor'])
                sentences2.append(data['positive'])
                scores.append(1.0)
            elif 'negative' in data:
                sentences1.append(data['anchor'])
                sentences2.append(data['negative'])
                scores.append(0.0)

    return sentences1, sentences2, scores


def save_progress(output_dir: str, progress: Dict[str, Any]):
    """Save training progress to JSON file."""
    progress_file = os.path.join(output_dir, 'training_progress.json')
    with open(progress_file, 'w') as f:
        json.dump(progress, f, indent=2, default=str)


def main():
    parser = argparse.ArgumentParser(description='Fine-tune embedding model')
    parser.add_argument('--training_file', required=True, help='Path to training JSONL')
    parser.add_argument('--eval_file', required=True, help='Path to eval JSONL')
    parser.add_argument('--output_dir', required=True, help='Output directory')
    parser.add_argument('--base_model', default='nomic-ai/nomic-embed-text-v1.5')
    parser.add_argument('--epochs', type=int, default=3)
    parser.add_argument('--batch_size', type=int, default=32)
    parser.add_argument('--learning_rate', type=float, default=2e-5)
    parser.add_argument('--warmup_ratio', type=float, default=0.1)
    parser.add_argument('--job_id', required=True, help='Job ID for tracking')

    args = parser.parse_args()

    # Create output directory
    os.makedirs(args.output_dir, exist_ok=True)

    # Initialize progress tracking
    progress = {
        'job_id': args.job_id,
        'status': 'initializing',
        'started_at': datetime.now().isoformat(),
        'base_model': args.base_model,
        'epochs': args.epochs,
        'batch_size': args.batch_size,
    }
    save_progress(args.output_dir, progress)

    print(f"[{args.job_id}] Loading base model: {args.base_model}")

    # Check for GPU
    device = 'cuda' if torch.cuda.is_available() else 'cpu'
    print(f"[{args.job_id}] Using device: {device}")

    # Load base model
    try:
        model = SentenceTransformer(args.base_model, device=device)
        progress['status'] = 'model_loaded'
        save_progress(args.output_dir, progress)
    except Exception as e:
        # Try alternative model name
        try:
            model = SentenceTransformer('sentence-transformers/all-MiniLM-L6-v2', device=device)
            print(f"[{args.job_id}] Fallback to all-MiniLM-L6-v2")
            progress['actual_model'] = 'all-MiniLM-L6-v2'
            save_progress(args.output_dir, progress)
        except Exception as e2:
            progress['status'] = 'error'
            progress['error'] = str(e2)
            save_progress(args.output_dir, progress)
            print(f"[{args.job_id}] Error loading model: {e2}")
            sys.exit(1)

    # Load training data
    print(f"[{args.job_id}] Loading training data...")
    train_examples = load_training_data(args.training_file)
    progress['training_examples'] = len(train_examples)
    save_progress(args.output_dir, progress)
    print(f"[{args.job_id}] Loaded {len(train_examples)} training examples")

    # Create data loader
    train_dataloader = DataLoader(
        train_examples,
        shuffle=True,
        batch_size=args.batch_size
    )

    # Define loss function - Contrastive Loss for similarity learning
    train_loss = losses.CosineSimilarityLoss(model)

    # Load evaluation data
    print(f"[{args.job_id}] Loading evaluation data...")
    eval_sentences1, eval_sentences2, eval_scores = load_eval_data(args.eval_file)

    evaluator = EmbeddingSimilarityEvaluator(
        eval_sentences1,
        eval_sentences2,
        eval_scores,
        name='domain-eval'
    )

    progress['eval_examples'] = len(eval_scores)
    progress['status'] = 'training'
    save_progress(args.output_dir, progress)

    # Calculate warmup steps
    total_steps = len(train_dataloader) * args.epochs
    warmup_steps = int(total_steps * args.warmup_ratio)

    print(f"[{args.job_id}] Starting training...")
    print(f"[{args.job_id}] Total steps: {total_steps}, Warmup: {warmup_steps}")

    # Train model
    try:
        model.fit(
            train_objectives=[(train_dataloader, train_loss)],
            evaluator=evaluator,
            epochs=args.epochs,
            warmup_steps=warmup_steps,
            output_path=args.output_dir,
            show_progress_bar=True,
            evaluation_steps=500,
            save_best_model=True,
            optimizer_params={'lr': args.learning_rate}
        )

        progress['status'] = 'completed'
        progress['completed_at'] = datetime.now().isoformat()

    except Exception as e:
        progress['status'] = 'error'
        progress['error'] = str(e)
        save_progress(args.output_dir, progress)
        print(f"[{args.job_id}] Training error: {e}")
        sys.exit(1)

    # Final evaluation
    print(f"[{args.job_id}] Running final evaluation...")
    final_score = evaluator(model, args.output_dir)
    progress['final_eval_score'] = final_score
    save_progress(args.output_dir, progress)

    print(f"[{args.job_id}] Training completed!")
    print(f"[{args.job_id}] Final evaluation score: {final_score:.4f}")
    print(f"[{args.job_id}] Model saved to: {args.output_dir}")

    # Export model info
    model_info = {
        'base_model': args.base_model,
        'fine_tuned_at': datetime.now().isoformat(),
        'training_pairs': len(train_examples),
        'eval_score': final_score,
        'epochs': args.epochs,
        'device': device,
    }

    with open(os.path.join(args.output_dir, 'model_info.json'), 'w') as f:
        json.dump(model_info, f, indent=2)


if __name__ == '__main__':
    main()
