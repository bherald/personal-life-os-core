#!/usr/bin/env python3
"""
Batch Face Detector - loads model once, processes many images
Reads image paths from stdin (one per line, format: id|path)
Outputs JSON per line for each processed image

Model provenance:
    The two .dat files are third-party dlib model assets. See
    docs/FACE-RECOGNITION.md for source URLs and checksums.
"""

import sys
import json
import os

# Load heavy libraries once
import dlib
import numpy as np
from PIL import Image

# Load models once at startup
detector = dlib.get_frontal_face_detector()
predictor_path = os.path.join(os.path.dirname(__file__), 'shape_predictor_68_face_landmarks.dat')
encoder_path = os.path.join(os.path.dirname(__file__), 'dlib_face_recognition_resnet_model_v1.dat')

sp = None
facerec = None
if os.path.exists(predictor_path):
    sp = dlib.shape_predictor(predictor_path)
if os.path.exists(encoder_path):
    facerec = dlib.face_recognition_model_v1(encoder_path)

print(json.dumps({"status": "ready", "has_encoder": facerec is not None}), flush=True)

def process_image(file_id, image_path):
    """Process a single image and return results"""
    result = {
        "id": file_id,
        "success": False,
        "faces": [],
        "face_count": 0
    }

    try:
        if not os.path.exists(image_path):
            result["error"] = f"File not found: {image_path}"
            return result

        img = Image.open(image_path)

        # Convert to RGB if needed
        if img.mode != 'RGB':
            img = img.convert('RGB')

        img_array = np.array(img)
        width, height = img.size

        # Detect faces
        dets = detector(img_array, 1)

        faces = []
        for i, d in enumerate(dets):
            face = {
                "index": i,
                "location": {
                    "top": d.top(),
                    "right": d.right(),
                    "bottom": d.bottom(),
                    "left": d.left()
                },
                "normalized": {
                    "x": d.left() / width,
                    "y": d.top() / height,
                    "w": (d.right() - d.left()) / width,
                    "h": (d.bottom() - d.top()) / height
                }
            }

            # Get embedding if available
            if sp and facerec:
                try:
                    shape = sp(img_array, d)
                    embedding = facerec.compute_face_descriptor(img_array, shape)
                    face["embedding"] = list(embedding)
                except Exception as e:
                    face["embedding_error"] = str(e)

            faces.append(face)

        result["success"] = True
        result["faces"] = faces
        result["face_count"] = len(faces)
        result["image_width"] = width
        result["image_height"] = height

    except Exception as e:
        result["error"] = str(e)

    return result

# Process stdin line by line
for line in sys.stdin:
    line = line.strip()
    if not line:
        continue

    # Parse input: id|path
    parts = line.split('|', 1)
    if len(parts) != 2:
        print(json.dumps({"error": f"Invalid input format: {line}"}), flush=True)
        continue

    file_id, image_path = parts
    result = process_image(file_id, image_path)
    print(json.dumps(result), flush=True)
