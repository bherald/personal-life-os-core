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
import hashlib
import io
import json
import os
import sys
import tempfile
import importlib.metadata
import importlib.util
import stat
import time
import math

# Pin native detector code before import and each used checkpoint around its
# constructor. Never describe an already-loaded object with a later file digest.
_ENGINE_FILES = {}
_ENGINE_OBJECTS = {}


def _tile_intervals(length, limit, overlap_fraction=0.25):
    if length <= 0 or limit <= 0 or not 0 <= overlap_fraction < 1:
        raise ValueError("tile_geometry_invalid")
    if length <= limit:
        return [(0, length)]
    stride = max(1, int(math.floor(limit * (1 - overlap_fraction))))
    count = int(math.ceil(length / stride))
    tile_length = min(limit, int(math.ceil(length / (count - overlap_fraction * (count - 1)))))
    if count < 2 or tile_length < 1:
        raise ValueError("tile_geometry_invalid")
    starts = [int(round(index * (length - tile_length) / (count - 1))) for index in range(count)]
    return [(start, tile_length) for start in starts]


def _generation_tiles(width, height, limit, max_pixels):
    x_intervals = _tile_intervals(width, limit)
    y_intervals = _tile_intervals(height, limit)
    tiles = [[x, y, tile_width, tile_height]
             for y, tile_height in y_intervals for x, tile_width in x_intervals]
    # Bound both per-call dimensions and cumulative HOG work. A pathological
    # narrow raster can otherwise create thousands of tiny detector calls.
    max_tiles = max(1, (max_pixels + (limit * limit) - 1) // (limit * limit) * 4)
    source_pixels = width * height
    detector_width = max(1, round(width * min(1, limit / width, limit / height)))
    detector_height = max(1, round(height * min(1, limit / width, limit / height)))
    processed_pixels = detector_width * detector_height
    processed_pixels += sum(tile_width * tile_height for _, _, tile_width, tile_height in tiles)
    if source_pixels > max_pixels or len(tiles) > max_tiles or processed_pixels > max_pixels:
        raise RuntimeError("generation_tile_resource_limit")
    return tiles, processed_pixels, max_tiles


def _box_iou(first, second):
    top = max(first[0], second[0])
    right = min(first[1], second[1])
    bottom = min(first[2], second[2])
    left = max(first[3], second[3])
    intersection = max(0, bottom - top) * max(0, right - left)
    if not intersection:
        return 0.0
    first_area = max(0, first[2] - first[0]) * max(0, first[1] - first[3])
    second_area = max(0, second[2] - second[0]) * max(0, second[1] - second[3])
    union = first_area + second_area - intersection
    return intersection / union if union else 0.0


def _deduplicate_generation_boxes(boxes):
    accepted = []
    for box in sorted(boxes, key=lambda value: (value[0], value[3], value[2], value[1])):
        if not any(_box_iou(box, existing) >= 0.5 for existing in accepted):
            accepted.append(box)
    return accepted


def _checkpoint(path):
    path = os.path.realpath(path)
    seconds = float(os.environ.get("PLOS_FACE_ENGINE_PROOF_SECONDS", "10"))
    if not math.isfinite(seconds) or seconds <= 0:
        raise RuntimeError("engine_checkpoint_budget")
    deadline = time.monotonic() + seconds
    maximum = int(os.environ.get("PLOS_FACE_ENGINE_PROOF_MAX_BYTES", str(128 * 1024 * 1024)))
    before = os.stat(path)
    if not stat.S_ISREG(before.st_mode) or before.st_size <= 0 or before.st_size > maximum:
        raise RuntimeError("engine_checkpoint_unavailable")
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        opened = os.fstat(stream.fileno())
        total = 0
        while chunk := stream.read(64 * 1024):
            total += len(chunk)
            if total > maximum or time.monotonic() > deadline:
                raise RuntimeError("engine_checkpoint_budget")
            digest.update(chunk)
        after = os.fstat(stream.fileno())
    current = os.stat(path)
    fingerprint = lambda info: (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
    if not fingerprint(before) == fingerprint(opened) == fingerprint(after) == fingerprint(current):
        raise RuntimeError("engine_checkpoint_changed")
    return path, fingerprint(current), digest.hexdigest()


def _verify_engine_files():
    if set(_ENGINE_FILES) != {"descriptor_sha256", "landmarks_small_sha256", "detector_binary_sha256"}:
        raise RuntimeError("engine_checkpoint_unknown")
    for pin in _ENGINE_FILES.values():
        if _checkpoint(pin[0]) != pin:
            raise RuntimeError("engine_checkpoint_changed")
    for attribute, loaded in _ENGINE_OBJECTS.items():
        if getattr(face_recognition.api, attribute) is not loaded:
            raise RuntimeError("engine_loaded_object_changed")


def _load_face_engine():
    if "face_recognition" in sys.modules or "_dlib_pybind11" in sys.modules:
        raise RuntimeError("engine_load_provenance_unknown")
    binary = importlib.util.find_spec("_dlib_pybind11")
    if binary is None or not binary.origin:
        raise RuntimeError("engine_detector_binary_unknown")
    pin = _checkpoint(binary.origin)
    import dlib
    import face_recognition_models
    if _checkpoint(binary.origin) != pin:
        raise RuntimeError("engine_checkpoint_changed")
    _ENGINE_FILES["detector_binary_sha256"] = pin
    small = os.path.realpath(face_recognition_models.pose_predictor_five_point_model_location())
    originals = {name: getattr(dlib, name) for name in ("shape_predictor", "face_recognition_model_v1")}

    def constructor(name, path, *args, **kwargs):
        key = "descriptor_sha256" if name == "face_recognition_model_v1" else "landmarks_small_sha256" if os.path.realpath(path) == small else None
        before = _checkpoint(path) if key else None
        loaded = originals[name](path, *args, **kwargs)
        if key:
            if _checkpoint(path) != before or key in _ENGINE_FILES:
                raise RuntimeError("engine_checkpoint_changed")
            _ENGINE_FILES[key] = before
            _ENGINE_OBJECTS["face_encoder" if key == "descriptor_sha256" else "pose_predictor_5_point"] = loaded
        return loaded

    try:
        for name in originals:
            setattr(dlib, name, lambda path, *args, _name=name, **kwargs: constructor(_name, path, *args, **kwargs))
        import face_recognition as recognition
    finally:
        for name, original in originals.items():
            setattr(dlib, name, original)
    _ENGINE_OBJECTS["face_detector"] = recognition.api.face_detector
    return recognition


try:
    face_recognition = _load_face_engine()
    import numpy as np
    from PIL import Image
    _verify_engine_files()
except ImportError as e:
    print(json.dumps({"success": False, "error": f"Missing dependency: {e}"}))
    sys.exit(1)
except Exception:
    print(json.dumps({"success": False, "error_code": "engine_checkpoint_proof_held"}))
    sys.exit(1)


def engine_provenance(detector="hog"):
    _verify_engine_files()
    versions = {}
    for package in ("face-recognition", "face-recognition-models", "dlib", "numpy", "Pillow"):
        try:
            versions[package] = importlib.metadata.version(package)
        except importlib.metadata.PackageNotFoundError:
            versions[package] = None
    return {"schema": "plos.face_generation_engine.v2", "python": sys.version.split()[0],
            "versions": versions, "detector": detector, "embedding_model": "dlib_face_recognition_resnet_model_v1",
            "checkpoints": {key: pin[2] for key, pin in sorted(_ENGINE_FILES.items())}}


def detect_faces(image_path: str, save_crops: bool = False, output_dir: str = None,
                 detect_only: bool = False, max_source_bytes: int = None,
                 max_pixels: int = None, private_output: bool = False,
                 max_header_pixels: int = None, source_preview: bool = False,
                 max_preview_dimension: int = 1600,
                 max_detector_dimension: int = None,
                 source_bound_generation: bool = False,
                 selected_region: dict = None) -> dict:
    """
    Detect faces in an image and generate embeddings.

    Returns:
        dict with success, faces array (location, embedding, crop_path), and metadata
    """
    error_code = "detector_failed_or_resource_held"
    try:
        if selected_region is not None and (not source_bound_generation or detect_only or source_preview or not max_detector_dimension):
            raise ValueError("selected_region_requires_source_bound_generation")
        if not os.path.exists(image_path):
            return {"success": False, "error": f"File not found: {image_path}"}

        # Decode and fingerprint the same immutable byte snapshot.
        with open(image_path, "rb") as source:
            source_bytes = source.read(max_source_bytes + 1) if max_source_bytes else source.read()
        if max_source_bytes and len(source_bytes) > max_source_bytes:
            return {"success": False, "error": "Source byte limit exceeded", "error_code": "source_byte_limit"}
        source_sha256 = hashlib.sha256(source_bytes).hexdigest()
        def source_still_current():
            with open(image_path, "rb") as current:
                snapshot = current.read(max_source_bytes + 1) if max_source_bytes else current.read()
            return hashlib.sha256(snapshot).hexdigest() == source_sha256

        error_code = "source_decode_invalid"
        detector_provenance = None
        bounded_detector = (detect_only or source_bound_generation) and not source_preview and max_detector_dimension is not None
        with Image.open(io.BytesIO(source_bytes)) as header:
            original_width, original_height = header.size
            draft_box = (0, 0, original_width, original_height)
            reduced = False
            detector_scale = min(1, max_detector_dimension / original_width, max_detector_dimension / original_height) if bounded_detector else 1
            detector_size = (max(1, round(original_width * detector_scale)), max(1, round(original_height * detector_scale)))
            bounded_source = detect_only or source_bound_generation
            if max_pixels and original_width * original_height > max_pixels:
                if not bounded_source or header.format != "JPEG" or not max_header_pixels or original_width * original_height > max_header_pixels:
                    return {"success": False, "error": "Source pixel limit exceeded", "error_code": "source_pixel_limit"}
            if bounded_source and header.format == "JPEG" and ((bounded_detector and detector_scale < 1) or (max_pixels and original_width * original_height > max_pixels)):
                draft = header.draft("RGB", detector_size if bounded_detector else (max(1, original_width // 2), max(1, original_height // 2)))
                if not draft or (max_pixels and header.width * header.height > max_pixels):
                    return {"success": False, "error": "Decoded pixel limit exceeded", "error_code": "decoded_pixel_limit"}
                draft_box = draft[1]
                reduced = header.size != (original_width, original_height)
            # PNG may load pixels while reading eXIf: admission must precede this.
            orientation = header.getexif().get(274, 1)
            decoded_dimensions = list(header.size)
            image = None
            if bounded_source:
                with header.convert("RGB") as rgb:
                    error_code = "detector_failed_or_resource_held"
                    if bounded_detector:
                        resampled = rgb.size != detector_size or tuple(draft_box) != (0, 0, rgb.width, rgb.height)
                        # Fractional JPEG draft extents exclude decoder padding at odd edges.
                        raster = rgb.resize(detector_size, Image.Resampling.LANCZOS, box=draft_box) if resampled else rgb
                        image = np.array(raster)
                        if raster is not rgb:
                            raster.close()
                        detector_provenance = {"source_sha256": source_sha256,
                            "source_dimensions": [original_width, original_height],
                            "decoded_dimensions": decoded_dimensions, "draft_box": list(draft_box),
                            "detector_dimensions": list(detector_size), "resize_box": list(draft_box),
                            "method": "lanczos" if resampled else "identity",
                            "original_scale": [original_width / detector_size[0], original_height / detector_size[1]],
                            "reduced": detector_size != (original_width, original_height),
                            "model": "hog", "upsample": 1}
                    else:
                        image = np.array(rgb)
        orientation_provenance = {
            "exif_orientation": orientation if orientation in range(1, 9) else None,
            "transform": "none",
            "coordinate_basis": "encoded_pixels"
        }
        if not bounded_source:
            image = face_recognition.load_image_file(io.BytesIO(source_bytes))

        # Get image dimensions
        height, width = image.shape[:2]
        scale_x = original_width / width if bounded_detector else original_width / (draft_box[2] - draft_box[0])
        scale_y = original_height / height if bounded_detector else original_height / (draft_box[3] - draft_box[1])
        decode_provenance = {"method": "jpeg_draft" if reduced else "full",
                             "source_dimensions": [original_width, original_height],
                             "decoded_dimensions": decoded_dimensions, "draft_box": list(draft_box),
                             "original_scale": [original_width / draft_box[2], original_height / draft_box[3]], "reduced": reduced}
        if source_preview:
            if not detect_only or not output_dir:
                return {"success": False, "error": "Source preview requires private anonymous output"}
            os.makedirs(output_dir, mode=0o700, exist_ok=True)
            os.chmod(output_dir, 0o700)
            # Use the fractional draft extent, including odd original dimensions,
            # so the preview retains the original normalized encoded-pixel axes.
            preview_scale = min(1, max_preview_dimension / original_width, max_preview_dimension / original_height)
            preview_size = (max(1, round(original_width * preview_scale)), max(1, round(original_height * preview_scale)))
            preview = Image.fromarray(image).resize(preview_size, Image.Resampling.LANCZOS, box=draft_box)
            preview_path = os.path.join(output_dir, "source-preview.jpg")
            preview.save(preview_path, "JPEG", quality=90)
            os.chmod(preview_path, 0o600)
            if not source_still_current():
                return {"success": False, "error": "Source changed during source preview"}
            return {"success": True, "source_sha256": source_sha256,
                    "image_width": original_width, "image_height": original_height,
                    "preview_path": preview_path, "preview_dimensions": list(preview.size),
                    "face_region_convention": "normalized_top_left", "coordinate_basis": "encoded_pixels",
                    "orientation_provenance": orientation_provenance, "decode_provenance": decode_provenance}

        # Detect face locations (top, right, bottom, left)
        # Use HOG model - faster and works well on CPU
        # CNN is more accurate but requires GPU and is extremely slow on CPU
        error_code = "detector_failed_or_resource_held"
        engine_before = engine_provenance("operator_selected_region" if selected_region is not None else "hog")
        planned_tiles = None
        planned_tile_pixels = None
        planned_max_tiles = None
        if source_bound_generation and selected_region is None and bounded_detector and detector_scale < 1:
            try:
                planned_tiles, planned_tile_pixels, planned_max_tiles = _generation_tiles(
                    original_width, original_height, max_detector_dimension, max_pixels)
            except RuntimeError:
                raise RuntimeError("generation_tile_resource_limit")
        tile_coverage = None
        if selected_region is not None:
            if set(selected_region) != {"x", "y", "w", "h"} or any(
                isinstance(value, bool) or not isinstance(value, (int, float)) or not np.isfinite(value)
                for value in selected_region.values()
            ):
                raise ValueError("selected_region_invalid")
            x, y, w, h = (selected_region[key] for key in ("x", "y", "w", "h"))
            if x < 0 or y < 0 or w <= 0 or h <= 0 or x + w > 1 or y + h > 1:
                raise ValueError("selected_region_invalid")
            # Cover the reviewed logical ROI with deterministic encoded-raster
            # pixel edges; preserve both boxes instead of claiming subpixel input.
            left, top = int(np.floor(x * width)), int(np.floor(y * height))
            right, bottom = int(np.ceil((x + w) * width)), int(np.ceil((y + h) * height))
            if left >= right or top >= bottom or right > width or bottom > height:
                raise ValueError("selected_region_invalid")
            face_locations = [(top, right, bottom, left)]
            detector_provenance.update({"model": "operator_selected_region", "upsample": 0,
                "selected_region": selected_region, "encoding_raster_box": [top, right, bottom, left]})
        else:
            face_locations = face_recognition.face_locations(image, number_of_times_to_upsample=1, model="hog") if bounded_detector else face_recognition.face_locations(image, model="hog")
            if planned_tiles is not None:
                with Image.open(io.BytesIO(source_bytes)) as full_header:
                    if full_header.size != (original_width, original_height):
                        raise RuntimeError("source_decode_invalid")
                    with full_header.convert("RGB") as full_rgb:
                        full_image = np.array(full_rgb)
                scale_x = original_width / width
                scale_y = original_height / height
                source_boxes = [(
                    max(0, min(original_height, int(round(top * scale_y)))),
                    max(0, min(original_width, int(round(right * scale_x)))),
                    max(0, min(original_height, int(round(bottom * scale_y)))),
                    max(0, min(original_width, int(round(left * scale_x)))),
                ) for top, right, bottom, left in face_locations]
                for x, y, tile_width, tile_height in planned_tiles:
                    tile_image = full_image[y:y + tile_height, x:x + tile_width]
                    tile_faces = face_recognition.face_locations(
                        tile_image, number_of_times_to_upsample=1, model="hog")
                    source_boxes.extend((top + y, right + x, bottom + y, left + x)
                                        for top, right, bottom, left in tile_faces)
                face_locations = _deduplicate_generation_boxes(source_boxes)
                if len(face_locations) > 100:
                    raise RuntimeError("generation_face_count_limit")
                image = full_image
                height, width = image.shape[:2]
                scale_x = scale_y = 1
                tile_coverage = {"method": "full_resolution_overlap_v1", "boxes": planned_tiles,
                    "processed_pixels": planned_tile_pixels, "maximum_pixels": max_pixels,
                    "maximum_tiles": planned_max_tiles}
                detector_provenance["tile_coverage"] = tile_coverage

        if not face_locations:
            if not source_still_current():
                return {"success": False, "error": "Source changed during face detection", "image_path": image_path}
            return {
                "success": True,
                "faces": [],
                "face_count": 0,
                "image_width": original_width,
                "image_height": original_height,
                "image_path": image_path,
                "source_sha256": source_sha256,
                "face_region_convention": "normalized_top_left",
                "coordinate_basis": "encoded_pixels",
                "orientation_provenance": orientation_provenance,
                "engine_provenance": engine_provenance("operator_selected_region" if selected_region is not None else "hog"),
                "decode_provenance": decode_provenance,
                **({"detector_provenance": detector_provenance} if bounded_detector else {})
            }

        # Generate face embeddings (128-dimensional vectors)
        _verify_engine_files()
        face_encodings = [None] * len(face_locations) if detect_only else face_recognition.face_encodings(image, face_locations, model="small")

        if engine_provenance("operator_selected_region" if selected_region is not None else "hog") != engine_before:
            raise RuntimeError("engine_checkpoint_changed")

        faces = []
        for i, (location, encoding) in enumerate(zip(face_locations, face_encodings)):
            top, right, bottom, left = location
            if detect_only:
                top, bottom = max(0, top), min(height if bounded_detector else draft_box[3], bottom)
                left, right = max(0, left), min(width if bounded_detector else draft_box[2], right)
                if top >= bottom or left >= right:
                    continue

            # Normalize raw encoded-pixel top-left coordinates to the 0-1 range.
            face_data = {
                "index": i,
                "location": {
                    "top": top * scale_y if reduced or bounded_detector else top,
                    "right": right * scale_x if reduced or bounded_detector else right,
                    "bottom": bottom * scale_y if reduced or bounded_detector else bottom,
                    "left": left * scale_x if reduced or bounded_detector else left
                },
                "normalized": {
                    "x": left * scale_x / original_width,
                    "y": top * scale_y / original_height,
                    "w": (right - left) * scale_x / original_width,
                    "h": (bottom - top) * scale_y / original_height
                },
                "source_sha256": source_sha256,
                "face_region_convention": "normalized_top_left"
            }
            if not detect_only:
                face_data["embedding"] = encoding.tolist()
                face_data["embedding_model"] = "dlib_face_recognition_resnet_model_v1"
            if selected_region is not None:
                face_data["normalized"] = selected_region.copy()

            # Save face crop if requested
            if save_crops and output_dir:
                region_key = [top, right, bottom, left, i] + ([decode_provenance, detector_provenance] if reduced or bounded_detector else [])
                region_hash = hashlib.sha256(json.dumps(region_key).encode()).hexdigest()[:16]
                crop_filename = f"face_{source_sha256}_{region_hash}_{i}.jpg"
                crop_path = os.path.join(output_dir, crop_filename)
                os.makedirs(output_dir, mode=0o700 if private_output else 0o777, exist_ok=True)
                if private_output:
                    os.chmod(output_dir, 0o700)

                # Add padding around face (20%)
                pad_h = int((bottom - top) * 0.2)
                pad_w = int((right - left) * 0.2)
                crop_top = max(0, int(top - pad_h))
                crop_bottom = min(height, int(bottom + pad_h))
                crop_left = max(0, int(left - pad_w))
                crop_right = min(width, int(right + pad_w))

                face_image = image[crop_top:crop_bottom, crop_left:crop_right]
                reuse = False
                if os.path.isfile(crop_path):
                    try:
                        with Image.open(crop_path) as cached:
                            cached.verify()
                            reuse = cached.format == "JPEG"
                    except Exception:
                        pass
                if not reuse:
                    temporary = None
                    try:
                        with tempfile.NamedTemporaryFile(dir=output_dir, prefix=".face-", delete=False) as target:
                            temporary = target.name
                        Image.fromarray(face_image).save(temporary, "JPEG", quality=90)
                        current_umask = os.umask(0)
                        os.umask(current_umask)
                        os.chmod(temporary, 0o600 if private_output else 0o666 & ~current_umask)
                        os.replace(temporary, crop_path)
                        temporary = None
                    finally:
                        if temporary is not None:
                            os.unlink(temporary)

                face_data["crop_path"] = crop_path
                face_data["crop_provenance"] = {"reduced": reduced or (bounded_detector and detector_provenance["reduced"]), "decoded_dimensions": decoded_dimensions,
                                                "coordinate_basis": "detector_encoded_pixels" if bounded_detector else "decoded_encoded_pixels", "source_sha256": source_sha256,
                                                **({"detector_dimensions": [width, height]} if bounded_detector else {})}
                if private_output:
                    os.chmod(crop_path, 0o600)

            faces.append(face_data)

        if not source_still_current():
            return {"success": False, "error": "Source changed during face detection", "image_path": image_path}

        return {
            "success": True,
            "source_sha256": source_sha256,
            "faces": faces,
            "face_count": len(faces),
            "image_width": original_width,
            "image_height": original_height,
            "image_path": image_path,
            "face_region_convention": "normalized_top_left",
            "coordinate_basis": "encoded_pixels",
            "orientation_provenance": orientation_provenance,
            "engine_provenance": engine_provenance("operator_selected_region" if selected_region is not None else "hog"),
            "decode_provenance": decode_provenance,
                **({"detector_provenance": detector_provenance} if bounded_detector else {})
        }

    except MemoryError as e:
        return {"success": False, "error_code": "detector_memory_limit"} if detect_only or source_bound_generation else {"success": False, "error": str(e), "image_path": image_path}
    except Exception as e:
        error_code_result = (str(e) if str(e) in {"generation_tile_resource_limit", "generation_face_count_limit"}
                             else error_code)
        return {"success": False, "error": str(e), "image_path": image_path,
                **({"error_code": error_code_result if isinstance(e, OSError) or error_code_result != error_code else "detector_failed_or_resource_held"}
                   if detect_only or source_bound_generation else {})}


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
    parser.add_argument("--detect-only", action="store_true", help="Anonymous boxes and crops without embeddings")
    parser.add_argument("--private-output", action="store_true", help="Private directory and artifact permissions")
    parser.add_argument("--max-source-bytes", type=int, help="Bound encoded source snapshot bytes")
    parser.add_argument("--max-pixels", type=int, help="Bound decoded source pixels")
    parser.add_argument("--max-header-pixels", type=int, help="Preview-only JPEG header pixel bound before draft decode")
    parser.add_argument("--source-preview", action="store_true", help="Render source preview without running detection or embeddings")
    parser.add_argument("--max-preview-dimension", type=int, default=1600, help="Maximum rendered source-preview dimension")
    parser.add_argument("--max-detector-dimension", type=int, help="Anonymous-only detector raster long-edge bound")
    parser.add_argument("--source-bound-generation", action="store_true", help="Generate anonymous embeddings only from a bounded immutable source snapshot")
    parser.add_argument("--selected-region-json", help="One explicit reviewed normalized encoded-pixel ROI; source-bound generation only")
    parser.add_argument("--version-only", action="store_true", help="Report installed detector and embedding implementation versions")
    parser.add_argument("--compare", nargs=2, help="Compare two embedding JSON files")
    parser.add_argument("--find-matches", help="JSON file with target embedding and known embeddings")
    parser.add_argument("--tolerance", type=float, default=0.6, help="Face match tolerance (default: 0.6)")

    args = parser.parse_args()
    if args.version_only:
        try:
            print(json.dumps({"success": True, "engine_provenance": engine_provenance()}, separators=(",", ":")))
        except Exception:
            print(json.dumps({"success": False, "error_code": "engine_checkpoint_proof_held"}))
        return
    if (args.detect_only or args.source_bound_generation) and (not args.image or args.batch or args.compare or args.find_matches):
        parser.error("--detect-only requires a single --image source")
    if args.source_preview and (not args.detect_only or not args.output_dir):
        parser.error("--source-preview requires --detect-only and --output-dir")
    if args.max_detector_dimension is not None and (not args.detect_only and not args.source_bound_generation or args.source_preview):
        parser.error("--max-detector-dimension requires anonymous detection")
    if args.source_bound_generation and (not args.private_output or not args.max_source_bytes or not args.max_pixels or not args.max_header_pixels or not args.max_detector_dimension):
        parser.error("Source-bound generation requires private output and every resource limit")
    selected_region = None
    if args.selected_region_json is not None:
        if not args.source_bound_generation or not args.image or args.detect_only or args.source_preview or len(args.selected_region_json) > 1024:
            parser.error("Selected region requires one source-bound image generation")
        selected_region = json.loads(args.selected_region_json)
        if not isinstance(selected_region, dict):
            parser.error("Selected region must be an object")
    if any(value is not None and value < 1 for value in [args.max_source_bytes, args.max_pixels, args.max_header_pixels, args.max_preview_dimension, args.max_detector_dimension]):
        parser.error("Source limits must be positive")

    result = None

    if args.image:
        # Single image processing
        result = detect_faces(args.image, args.save_crops, args.output_dir, args.detect_only,
                              args.max_source_bytes, args.max_pixels, args.private_output,
                              args.max_header_pixels, args.source_preview, args.max_preview_dimension, args.max_detector_dimension,
                              args.source_bound_generation, selected_region)

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
        if args.private_output:
            os.chmod(args.output, 0o600)
        print(f"Output written to {args.output}")
    else:
        print(output_json)


if __name__ == "__main__":
    main()
