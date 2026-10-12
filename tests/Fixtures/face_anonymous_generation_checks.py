"""Generated raster checks for source-bound anonymous face generation only."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("anonymous_generation_detector", ROOT / "scripts/face_detector.py")
detector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(detector)


class AnonymousGenerationProducerChecks(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="plos-anonymous-generation-producer-")
        self.root = Path(self.temporary.name)
        self.source = self.root / "generated-source.png"
        self.write_source(self.source, (120, 100), (15, 45, 75))
        self.source_hash = hashlib.sha256(self.source.read_bytes()).hexdigest()
        self.addCleanup(self.temporary.cleanup)

    @staticmethod
    def write_source(path, dimensions, color):
        Image.new("RGB", dimensions, color).save(path, "PNG")

    def detect(self, path=None, max_pixels=1_000_000):
        return detector.detect_faces(
            str(path or self.source), save_crops=False, output_dir=None,
            source_bound_generation=True, max_source_bytes=1_000_000,
            max_pixels=max_pixels, max_header_pixels=max_pixels,
            max_detector_dimension=1600,
        )

    def test_embeddings_use_the_same_bounded_raster_and_keep_encoded_pixel_binding(self):
        captured = {}

        def locations(image, number_of_times_to_upsample, model):
            captured["detector_image"] = image
            self.assertEqual(image.shape, (100, 120, 3))
            self.assertEqual(tuple(image[0, 0]), (15, 45, 75))
            self.assertEqual(number_of_times_to_upsample, 1)
            self.assertEqual(model, "hog")
            return [(10, 80, 60, 20)]

        def encodings(image, found_locations, model):
            self.assertEqual(model, "small")
            captured["encoding_image"] = image
            self.assertIs(image, captured["detector_image"])
            self.assertEqual(found_locations, [(10, 80, 60, 20)])
            return [np.linspace(-0.25, 0.25, 128)]

        with patch.object(detector.face_recognition, "face_locations", side_effect=locations), \
                patch.object(detector.face_recognition, "face_encodings", side_effect=encodings):
            result = self.detect()

        self.assertTrue(result["success"], result.get("error_code"))
        self.assertEqual(result["source_sha256"], self.source_hash)
        self.assertEqual(result["coordinate_basis"], "encoded_pixels")
        self.assertEqual(result["face_region_convention"], "normalized_top_left")
        self.assertEqual(result["engine_provenance"], detector.engine_provenance())
        self.assertEqual(result["detector_provenance"]["source_sha256"], self.source_hash)
        self.assertEqual(result["detector_provenance"]["source_dimensions"], [120, 100])
        self.assertEqual(result["detector_provenance"]["detector_dimensions"], [120, 100])
        self.assertEqual(result["faces"][0]["source_sha256"], self.source_hash)
        self.assertEqual(result["faces"][0]["normalized"], {
            "x": 20 / 120, "y": 10 / 100, "w": 60 / 120, "h": 50 / 100,
        })
        self.assertEqual(result["faces"][0]["embedding"], np.linspace(-0.25, 0.25, 128).tolist())
        self.assertEqual(result["faces"][0]["embedding_model"], "dlib_face_recognition_resnet_model_v1")

    def test_source_change_during_detection_fails_closed_for_face_and_zero_face_results(self):
        cases = [("one-face", [(10, 80, 60, 20)]), ("zero-face", [])]
        for label, found in cases:
            with self.subTest(label=label):
                self.write_source(self.source, (120, 100), (15, 45, 75))

                def change_source(image, number_of_times_to_upsample, model):
                    self.assertEqual(tuple(image[0, 0]), (15, 45, 75))
                    self.write_source(self.source, (120, 100), (85, 55, 25))
                    return found

                with patch.object(detector.face_recognition, "face_locations", side_effect=change_source), \
                        patch.object(detector.face_recognition, "face_encodings", return_value=[np.zeros(128)]):
                    result = self.detect()

                self.assertFalse(result["success"])
                self.assertIn("Source changed", result["error"])
                self.assertNotIn("faces", result)
                self.assertNotIn("face_count", result)

    def test_large_generation_uses_bounded_full_resolution_tiles_and_deduplicates_overlap(self):
        self.write_source(self.source, (1800, 1700), (15, 45, 75))
        self.source_hash = hashlib.sha256(self.source.read_bytes()).hexdigest()
        calls = []

        def locations(image, number_of_times_to_upsample, model):
            self.assertEqual(number_of_times_to_upsample, 1)
            self.assertEqual(model, "hog")
            calls.append(image.shape[:2])
            if len(calls) == 1:
                self.assertEqual(image.shape[:2], (1511, 1600))
                return [(89, 800, 178, 711)]  # Coarse pass finds the same face as the tiles.
            if len(calls) == 2:
                return [(100, 900, 200, 800)]
            if len(calls) == 3:
                return [(100, 129, 200, 29)]  # Same global face through tile overlap.
            if len(calls) == 4:
                return [(72, 200, 172, 100)]
            return []

        def encodings(image, found_locations, model):
            self.assertEqual(image.shape, (1700, 1800, 3))
            self.assertEqual(model, "small")
            self.assertEqual(found_locations, [(100, 900, 200, 800), (800, 200, 900, 100)])
            return [np.full(128, 0.1), np.full(128, 0.2)]

        with patch.object(detector.face_recognition, "face_locations", side_effect=locations), \
                patch.object(detector.face_recognition, "face_encodings", side_effect=encodings):
            result = self.detect(max_pixels=8_000_000)

        self.assertTrue(result["success"], result.get("error_code"))
        self.assertEqual(len(calls), 5)
        self.assertEqual(result["face_count"], 2)
        self.assertEqual(result["faces"][0]["normalized"], {
            "x": 800 / 1800, "y": 100 / 1700, "w": 100 / 1800, "h": 100 / 1700,
        })
        self.assertEqual(result["faces"][1]["normalized"], {
            "x": 100 / 1800, "y": 800 / 1700, "w": 100 / 1800, "h": 100 / 1700,
        })
        coverage = result["detector_provenance"]["tile_coverage"]
        self.assertEqual(coverage["method"], "full_resolution_overlap_v1")
        self.assertEqual(coverage["boxes"], [[0, 0, 1029, 972], [771, 0, 1029, 972],
                                             [0, 728, 1029, 972], [771, 728, 1029, 972]])
        self.assertLessEqual(coverage["processed_pixels"], coverage["maximum_pixels"])

    def test_tile_pixel_budget_overflow_holds_before_detector_calls(self):
        self.write_source(self.source, (3800, 3800), (15, 45, 75))
        calls = []
        with patch.object(detector.face_recognition, "face_locations", side_effect=lambda *args, **kwargs: calls.append(True)):
            result = self.detect(max_pixels=16_000_000)

        self.assertFalse(result["success"])
        self.assertEqual(result["error_code"], "generation_tile_resource_limit")
        self.assertEqual(calls, [])

    def test_overlap_dedup_is_stable_and_does_not_merge_neighboring_faces(self):
        boxes = [(10, 60, 50, 20), (11, 61, 51, 21), (10, 100, 50, 60)]
        expected = [(10, 60, 50, 20), (10, 100, 50, 60)]
        self.assertEqual(detector._deduplicate_generation_boxes(boxes), expected)
        self.assertEqual(detector._deduplicate_generation_boxes(list(reversed(boxes))), expected)

    def test_version_only_cli_matches_engine_provenance_returned_by_detector(self):
        environment = os.environ.copy()
        environment.update(OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1", MKL_NUM_THREADS="1",
                           PYTHONDONTWRITEBYTECODE="1")
        process = subprocess.run(["/usr/bin/python3", str(ROOT / "scripts/face_detector.py"), "--version-only"],
                                 capture_output=True, text=True, timeout=30, env=environment)
        self.assertEqual(process.returncode, 0, process.stderr)
        report = json.loads(process.stdout)
        self.assertTrue(report["success"])
        self.assertEqual(report["engine_provenance"], detector.engine_provenance())

    def test_source_bound_cli_requires_private_output_and_every_resource_bound(self):
        output = self.root / "detector.json"
        base = ["/usr/bin/python3", str(ROOT / "scripts/face_detector.py"), "--image", str(self.source),
                "--output", str(output), "--source-bound-generation"]
        bounded = ["--max-source-bytes", "1000000", "--max-pixels", "1000000",
                   "--max-header-pixels", "1000000", "--max-detector-dimension", "1600"]
        environment = os.environ.copy()
        environment.update(OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1", MKL_NUM_THREADS="1",
                           PYTHONDONTWRITEBYTECODE="1")

        missing_private = subprocess.run(base + bounded, capture_output=True, text=True, timeout=30, env=environment)
        self.assertEqual(missing_private.returncode, 2)
        self.assertIn("private output and every resource limit", missing_private.stderr)

        for missing_option in ["--max-source-bytes", "--max-pixels", "--max-header-pixels", "--max-detector-dimension"]:
            with self.subTest(missing_option=missing_option):
                arguments = [value for index, value in enumerate(bounded)
                            if value != missing_option and (index == 0 or bounded[index - 1] != missing_option)]
                missing_bound = subprocess.run(base + ["--private-output"] + arguments,
                                               capture_output=True, text=True, timeout=30, env=environment)
                self.assertEqual(missing_bound.returncode, 2)
                self.assertIn("private output and every resource limit", missing_bound.stderr)


if __name__ == "__main__":
    unittest.main()
