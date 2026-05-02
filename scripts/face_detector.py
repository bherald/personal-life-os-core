#!/usr/bin/env python3
"""
Face Detection and Embedding Service

Detects faces in images and generates 128-dimensional embeddings using dlib/face_recognition.
Outputs JSON with face locations, embeddings, and cropped face images.

Usage:
    python face_detector.py --image /path/to/image.jpg --output /path/to/output.json
    python face_detector.py --image /path/to/image.jpg --output-dir /path/to/faces/ --save-crops
    python face_detector.py --batch /path/to/images.txt --output-dir /path/to/output/

Requirements:
    pip install face_recognition pillow numpy

Model provenance:
    See docs/FACE-RECOGNITION.md. Do not commit dlib model weights or private
    media fixtures to the public repository.
"""

import argparse
import json
import os
import sys
from pathlib import Path

try:
    import face_recognition
    import numpy as np
    from PIL import Image
except ImportError as e:
    print(json.dumps({
        "success": False,
        "error": f"Missing dependency: {e}. Install with: pip install face_recognition pillow numpy"
    }))
    sys.exit(1)


def detect_faces(image_path: str, save_crops: bool = False, output_dir: str = None) -> dict:
    """
    Detect faces in an image and generate embeddings.

    Returns:
        dict with success, faces array (location, embedding, crop_path), and metadata
    """
    try:
        if not os.path.exists(image_path):
            return {"success": False, "error": f"File not found: {image_path}"}

        # Load image
        image = face_recognition.load_image_file(image_path)

        # Get image dimensions
        height, width = image.shape[:2]

        # Detect face locations (top, right, bottom, left)
        # Use HOG model - faster and works well on CPU
        # CNN is more accurate but requires GPU and is extremely slow on CPU
        face_locations = face_recognition.face_locations(image, model="hog")

        if not face_locations:
            return {
                "success": True,
                "faces": [],
                "face_count": 0,
                "image_width": width,
                "image_height": height,
                "image_path": image_path
            }

        # Generate face embeddings (128-dimensional vectors)
        face_encodings = face_recognition.face_encodings(image, face_locations)

        faces = []
        for i, (location, encoding) in enumerate(zip(face_locations, face_encodings)):
            top, right, bottom, left = location

            # Normalize coordinates to 0-1 range (MWG-rs compatible)
            face_data = {
                "index": i,
                "location": {
                    "top": top,
                    "right": right,
                    "bottom": bottom,
                    "left": left
                },
                "normalized": {
                    "x": left / width,
                    "y": top / height,
                    "w": (right - left) / width,
                    "h": (bottom - top) / height
                },
                "embedding": encoding.tolist(),  # 128-dim vector
                "embedding_model": "dlib_face_recognition_resnet_model_v1"
            }

            # Save face crop if requested
            if save_crops and output_dir:
                crop_filename = f"face_{i}_{os.path.basename(image_path)}"
                crop_path = os.path.join(output_dir, crop_filename)

                # Add padding around face (20%)
                pad_h = int((bottom - top) * 0.2)
                pad_w = int((right - left) * 0.2)
                crop_top = max(0, top - pad_h)
                crop_bottom = min(height, bottom + pad_h)
                crop_left = max(0, left - pad_w)
                crop_right = min(width, right + pad_w)

                face_image = image[crop_top:crop_bottom, crop_left:crop_right]
                pil_image = Image.fromarray(face_image)
                pil_image.save(crop_path, "JPEG", quality=90)

                face_data["crop_path"] = crop_path

            faces.append(face_data)

        return {
            "success": True,
            "faces": faces,
            "face_count": len(faces),
            "image_width": width,
            "image_height": height,
            "image_path": image_path
        }

    except Exception as e:
        return {"success": False, "error": str(e), "image_path": image_path}


def compare_faces(embedding1: list, embedding2: list, tolerance: float = 0.6) -> dict:
    """
    Compare two face embeddings and return similarity score.

    Args:
        embedding1: 128-dim face embedding
        embedding2: 128-dim face embedding
        tolerance: Distance threshold (lower = stricter, default 0.6)

    Returns:
        dict with distance, is_match, and confidence
    """
    try:
        enc1 = np.array(embedding1)
        enc2 = np.array(embedding2)

        # Euclidean distance
        distance = np.linalg.norm(enc1 - enc2)

        # Convert distance to confidence (0-1)
        # Distance of 0 = perfect match (confidence 1.0)
        # Distance of 1.0 = no match (confidence 0.0)
        confidence = max(0, 1 - (distance / 1.0))

        return {
            "success": True,
            "distance": float(distance),
            "is_match": distance <= tolerance,
            "confidence": float(confidence),
            "tolerance": tolerance
        }
    except Exception as e:
        return {"success": False, "error": str(e)}


def find_matches(target_embedding: list, known_embeddings: list, tolerance: float = 0.6) -> dict:
    """
    Find all faces that match the target embedding.

    Args:
        target_embedding: 128-dim embedding to search for
        known_embeddings: List of {"id": ..., "embedding": [...]} objects
        tolerance: Distance threshold

    Returns:
        dict with matches sorted by confidence
    """
    try:
        target = np.array(target_embedding)
        matches = []

        for known in known_embeddings:
            known_enc = np.array(known["embedding"])
            distance = np.linalg.norm(target - known_enc)
            confidence = max(0, 1 - (distance / 1.0))

            if distance <= tolerance:
                matches.append({
                    "id": known.get("id"),
                    "distance": float(distance),
                    "confidence": float(confidence)
                })

        # Sort by confidence descending
        matches.sort(key=lambda x: x["confidence"], reverse=True)

        return {
            "success": True,
            "matches": matches,
            "match_count": len(matches),
            "tolerance": tolerance
        }
    except Exception as e:
        return {"success": False, "error": str(e)}


def process_batch(image_list_file: str, output_dir: str, save_crops: bool = False) -> dict:
    """
    Process a batch of images from a file (one path per line).

    Returns:
        dict with results for each image
    """
    try:
        os.makedirs(output_dir, exist_ok=True)

        with open(image_list_file, 'r') as f:
            image_paths = [line.strip() for line in f if line.strip()]

        results = []
        total_faces = 0
        errors = 0

        for image_path in image_paths:
            result = detect_faces(image_path, save_crops, output_dir)
            results.append(result)

            if result["success"]:
                total_faces += result.get("face_count", 0)
            else:
                errors += 1

        return {
            "success": True,
            "images_processed": len(image_paths),
            "total_faces": total_faces,
            "errors": errors,
            "results": results
        }
    except Exception as e:
        return {"success": False, "error": str(e)}


def main():
    parser = argparse.ArgumentParser(description="Face Detection and Embedding Service")
    parser.add_argument("--image", help="Path to single image to process")
    parser.add_argument("--batch", help="Path to file containing image paths (one per line)")
    parser.add_argument("--output", help="Output JSON file path")
    parser.add_argument("--output-dir", help="Output directory for batch processing")
    parser.add_argument("--save-crops", action="store_true", help="Save cropped face images")
    parser.add_argument("--compare", nargs=2, help="Compare two embedding JSON files")
    parser.add_argument("--find-matches", help="JSON file with target embedding and known embeddings")
    parser.add_argument("--tolerance", type=float, default=0.6, help="Face match tolerance (default: 0.6)")

    args = parser.parse_args()

    result = None

    if args.image:
        # Single image processing
        result = detect_faces(args.image, args.save_crops, args.output_dir)

    elif args.batch:
        # Batch processing
        if not args.output_dir:
            result = {"success": False, "error": "Batch processing requires --output-dir"}
        else:
            result = process_batch(args.batch, args.output_dir, args.save_crops)

    elif args.compare:
        # Compare two embeddings
        with open(args.compare[0]) as f1, open(args.compare[1]) as f2:
            emb1 = json.load(f1)
            emb2 = json.load(f2)
        result = compare_faces(emb1, emb2, args.tolerance)

    elif args.find_matches:
        # Find matches for target embedding
        with open(args.find_matches) as f:
            data = json.load(f)
        result = find_matches(
            data["target_embedding"],
            data["known_embeddings"],
            args.tolerance
        )
    else:
        parser.print_help()
        sys.exit(1)

    # Output result
    output_json = json.dumps(result, indent=2)

    if args.output:
        with open(args.output, 'w') as f:
            f.write(output_json)
        print(f"Output written to {args.output}")
    else:
        print(output_json)


if __name__ == "__main__":
    main()
