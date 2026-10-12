"""Generated raster tests; no private photographs or identity inference."""
import importlib.util
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("preview_detector", ROOT / "scripts/face_detector.py")
detector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(detector)


class AnonymousPreviewChecks(unittest.TestCase):
    def patterned_source(self, path, dimensions):
        # Synthetic texture exercises decode/HOG without private photographs.
        tile = Image.new("RGB", (96, 96))
        tile.putdata([((x * 19 + y * 3) % 256, (x * 5 + y * 17) % 256, (x * 11 + y * 7) % 256)
                      for y in range(96) for x in range(96)])
        with Image.new("RGB", dimensions) as image:
            for y in range(0, dimensions[1], 96):
                for x in range(0, dimensions[0], 96):
                    image.paste(tile, (x, y))
            image.save(path)
        tile.close()

    def bounded_subprocess(self, source, dimensions):
        output = self.root / "preview.json"
        command = ["/usr/bin/prlimit", "--as=1073741824", "--", "/usr/bin/python3", str(ROOT / "scripts/face_detector.py"),
                   "--image", str(source), "--output", str(output), "--detect-only", "--private-output",
                   "--max-source-bytes", "16777216", "--max-pixels", "16000000", "--max-header-pixels", "32000000",
                   "--max-detector-dimension", "1600"]
        environment = os.environ.copy()
        environment.update(OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1", MKL_NUM_THREADS="1", PYTHONDONTWRITEBYTECODE="1")
        process = subprocess.run(command, capture_output=True, text=True, timeout=120, env=environment)
        self.assertEqual(process.returncode, 0, "Bounded detector process did not complete")
        result = json.loads(output.read_text())
        self.assertTrue(result["success"], result.get("error_code"))
        self.assertEqual([result["image_width"], result["image_height"]], list(dimensions))
        self.assertEqual(result["source_sha256"], hashlib.sha256(source.read_bytes()).hexdigest())
        self.assertLessEqual(max(result["detector_provenance"]["detector_dimensions"]), 1600)
        self.assertIsInstance(result["faces"], list)
        self.assertTrue(all("embedding" not in face for face in result["faces"]))
        self.assertEqual(os.stat(output).st_mode & 0o777, 0o600)
        return result

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="plos-anonymous-detector-test-")
        self.root = Path(self.temporary.name)
        self.source = self.root / "generated.jpg"
        self.crops = self.root / "private-crops"
        exif = Image.Exif()
        exif[274] = 6
        Image.new("RGB", (100, 80), (128, 128, 128)).save(self.source, exif=exif)
        self.addCleanup(self.temporary.cleanup)

    def test_detector_only_omits_embeddings_and_preserves_raw_orientation_coordinates(self):
        with patch.object(detector.face_recognition, "face_locations", return_value=[(10, 60, 40, 20)]), patch.object(detector.face_recognition, "face_encodings", side_effect=AssertionError("Embedding generation forbidden")):
            result = detector.detect_faces(str(self.source), True, str(self.crops), True, 16777216, 16000000, True, max_detector_dimension=1600)
        self.assertTrue(result["success"])
        self.assertEqual([result["image_width"], result["image_height"]], [100, 80])
        self.assertEqual(result["orientation_provenance"]["exif_orientation"], 6)
        self.assertEqual(result["orientation_provenance"]["transform"], "none")
        self.assertEqual(result["coordinate_basis"], "encoded_pixels")
        face = result["faces"][0]
        self.assertEqual(face["normalized"], {"x": 0.2, "y": 0.125, "w": 0.4, "h": 0.375})
        self.assertEqual(result["detector_provenance"]["method"], "identity")
        self.assertNotIn("embedding", face)
        self.assertNotIn("embedding_model", face)
        self.assertEqual(os.stat(self.crops).st_mode & 0o777, 0o700)
        self.assertEqual(os.stat(face["crop_path"]).st_mode & 0o777, 0o600)

    def test_byte_and_pixel_limits_fail_before_model_processing(self):
        with patch.object(detector.face_recognition, "face_locations", side_effect=AssertionError("Detector should be held")):
            byte_result = detector.detect_faces(str(self.source), detect_only=True, max_source_bytes=1, max_pixels=16000000)
            pixel_result = detector.detect_faces(str(self.source), detect_only=True, max_source_bytes=16777216, max_pixels=7999)
        self.assertFalse(byte_result["success"])
        self.assertIn("byte limit", byte_result["error"])
        self.assertFalse(pixel_result["success"])
        self.assertIn("pixel limit", pixel_result["error"])

    def test_actual_generated_12mp_detector_fits_one_gib_subprocess_without_embeddings(self):
        for extension in ["png", "jpg"]:
            with self.subTest(extension=extension):
                source = self.root / ("generated-patterned-12mp." + extension)
                self.patterned_source(source, (4000, 3000))
                result = self.bounded_subprocess(source, (4000, 3000))
                self.assertEqual(result["detector_provenance"]["detector_dimensions"], [1600, 1200])

    def test_actual_patterned_odd_17mp_detector_fits_same_resource_guards(self):
        source = self.root / "generated-patterned-odd-17mp.jpg"
        self.patterned_source(source, (4101, 4103))
        result = self.bounded_subprocess(source, (4101, 4103))
        self.assertEqual(result["decode_provenance"]["draft_box"], [0, 0, 2050.5, 2051.5])
        self.assertEqual(result["detector_provenance"]["detector_dimensions"], [1599, 1600])

    def test_bounded_raster_maps_odd_draft_extent_and_raw_exif_axes_exactly(self):
        for dimensions in [(4101, 4103), (4103, 4101)]:
            for orientation in [2, 6, 8]:
                with self.subTest(dimensions=dimensions, orientation=orientation):
                    source = self.root / "odd-axis.jpg"
                    exif = Image.Exif()
                    exif[274] = orientation
                    Image.new("RGB", dimensions, (128, 128, 128)).save(source, exif=exif)
                    ratio = min(1600 / dimensions[0], 1600 / dimensions[1])
                    width, height = [round(axis * ratio) for axis in dimensions]
                    def boxes(image, model, number_of_times_to_upsample):
                        self.assertEqual(image.shape, (height, width, 3))
                        self.assertEqual(model, "hog")
                        self.assertEqual(number_of_times_to_upsample, 1)
                        return [(0, width, height, 0), (100, 700, 600, 200)]
                    with patch.object(detector.face_recognition, "face_locations", side_effect=boxes), patch.object(detector.face_recognition, "face_encodings", side_effect=AssertionError("Embedding forbidden")):
                        result = detector.detect_faces(str(source), True, str(self.crops), True, 16777216, 16000000, True, 32000000, max_detector_dimension=1600)
                    self.assertTrue(result["success"], result.get("error_code"))
                    proof = result["detector_provenance"]
                    self.assertEqual(proof["resize_box"], result["decode_provenance"]["draft_box"])
                    self.assertEqual(proof["method"], "lanczos")
                    self.assertEqual(result["faces"][0]["normalized"], {"x": 0, "y": 0, "w": 1, "h": 1})
                    self.assertAlmostEqual(result["faces"][1]["normalized"]["x"], 200 / width)
                    self.assertAlmostEqual(result["faces"][1]["location"]["bottom"], 600 * dimensions[1] / height)
                    self.assertEqual(result["orientation_provenance"]["exif_orientation"], orientation)
                    self.assertEqual(result["orientation_provenance"]["transform"], "none")
                    crop = result["faces"][1]["crop_provenance"]
                    self.assertEqual(crop["coordinate_basis"], "detector_encoded_pixels")
                    self.assertEqual(crop["detector_dimensions"], [width, height])
                    self.assertTrue(crop["reduced"])
                    self.assertEqual(os.stat(result["faces"][1]["crop_path"]).st_mode & 0o777, 0o600)

    def test_preview_failure_codes_only_classify_proven_failures(self):
        for exception, expected in [(MemoryError(), "detector_memory_limit"), (RuntimeError("unknown"), "detector_failed_or_resource_held")]:
            with patch.object(detector.face_recognition, "face_locations", side_effect=exception):
                result = detector.detect_faces(str(self.source), detect_only=True, max_pixels=16000000, max_detector_dimension=1600)
            self.assertEqual(result["error_code"], expected)
        invalid = self.root / "invalid-image"
        invalid.write_bytes(b"not an encoded image")
        result = detector.detect_faces(str(invalid), detect_only=True, max_pixels=16000000, max_detector_dimension=1600)
        self.assertEqual(result["error_code"], "source_decode_invalid")
        with patch.object(Image.Image, "resize", side_effect=OSError("transform failed")):
            result = detector.detect_faces(str(self.source), detect_only=True, max_pixels=16000000, max_detector_dimension=40)
        self.assertEqual(result["error_code"], "detector_failed_or_resource_held")

    def test_embedding_producer_preserves_full_input_and_has_no_preview_transform(self):
        with patch.object(detector.face_recognition, "face_locations", return_value=[(10, 60, 40, 20)]) as locations, patch.object(detector.face_recognition, "face_encodings", return_value=[detector.np.zeros(128)]) as encodings:
            result = detector.detect_faces(str(self.source), True, str(self.crops))
        self.assertTrue(result["success"])
        self.assertEqual(locations.call_args.args[0].shape, (80, 100, 3))
        encodings.assert_called_once()
        self.assertNotIn("detector_provenance", result)
        self.assertNotIn("detector_dimensions", result["faces"][0]["crop_provenance"])
        self.assertEqual(len(result["faces"][0]["embedding"]), 128)
        location = result["faces"][0]["location"]
        self.assertEqual(location, {"top": 10, "right": 60, "bottom": 40, "left": 20})
        self.assertTrue(all(type(value) is int for value in location.values()))
        self.assertEqual(json.loads(json.dumps(location)), location)

    def test_generated_odd_17mp_jpeg_draft_maps_actual_extent_to_original_pixels(self):
        source = self.root / "generated-odd-17mp.jpg"
        exif = Image.Exif()
        exif[274] = 6
        Image.new("RGB", (4101, 4101), (128, 128, 128)).save(source, exif=exif)
        with patch.object(detector.face_recognition, "face_locations", return_value=[(100, 700, 600, 200)]), patch.object(detector.face_recognition, "face_encodings", side_effect=AssertionError("Embedding forbidden")):
            result = detector.detect_faces(str(source), True, str(self.crops), True, 16777216, 16000000, True, 32000000)
        self.assertTrue(result["success"], result.get("error"))
        self.assertEqual(result["source_sha256"], hashlib.sha256(source.read_bytes()).hexdigest())
        self.assertEqual([result["image_width"], result["image_height"]], [4101, 4101])
        proof = result["decode_provenance"]
        self.assertEqual(proof["method"], "jpeg_draft")
        self.assertEqual(proof["decoded_dimensions"], [2051, 2051])
        self.assertEqual(proof["draft_box"], [0, 0, 2050.5, 2050.5])
        self.assertEqual(proof["original_scale"], [2, 2])
        face = result["faces"][0]
        self.assertEqual(face["location"], {"top": 200, "right": 1400, "bottom": 1200, "left": 400})
        self.assertEqual(face["normalized"], {"x": 400 / 4101, "y": 200 / 4101, "w": 1000 / 4101, "h": 1000 / 4101})
        self.assertTrue(face["crop_provenance"]["reduced"])
        self.assertEqual(result["orientation_provenance"]["exif_orientation"], 6)
        self.assertEqual(result["orientation_provenance"]["transform"], "none")
        self.assertNotIn("embedding", face)

    def test_actual_odd_17mp_source_preview_fits_same_bounded_process_without_detection(self):
        source = self.root / "generated-large-preview.jpg"
        Image.new("RGB", (4101, 4103), (128, 128, 128)).save(source)
        output = self.root / "large-preview.json"
        command = ["/usr/bin/prlimit", "--as=1073741824", "--", "/usr/bin/python3", str(ROOT / "scripts/face_detector.py"),
                   "--image", str(source), "--output", str(output), "--output-dir", str(self.crops),
                   "--detect-only", "--source-preview", "--private-output", "--max-source-bytes", "16777216",
                   "--max-pixels", "16000000", "--max-header-pixels", "32000000", "--max-preview-dimension", "1600"]
        environment = os.environ.copy()
        environment.update(OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1", MKL_NUM_THREADS="1", PYTHONDONTWRITEBYTECODE="1")
        process = subprocess.run(command, capture_output=True, text=True, timeout=120, env=environment)
        self.assertEqual(process.returncode, 0, process.stderr)
        result = json.loads(output.read_text())
        self.assertTrue(result["success"], result.get("error"))
        self.assertEqual([result["image_width"], result["image_height"]], [4101, 4103])
        self.assertEqual(result["source_sha256"], hashlib.sha256(source.read_bytes()).hexdigest())
        self.assertEqual(result["decode_provenance"]["decoded_dimensions"], [2051, 2052])
        self.assertEqual(result["decode_provenance"]["draft_box"], [0, 0, 2050.5, 2051.5])
        self.assertEqual(result["preview_dimensions"], [1599, 1600])
        self.assertNotIn("faces", result)
        self.assertNotIn("embedding", result)
        with Image.open(result["preview_path"]) as image:
            self.assertEqual(list(image.size), result["preview_dimensions"])
            self.assertNotIn(274, image.getexif())

    def test_oversized_png_and_jpeg_over_header_budget_hold_before_detection(self):
        for name, dimensions in [("too-large.png", (4101, 4101)), ("too-large.jpg", (8001, 4001))]:
            source = self.root / name
            Image.new("RGB", dimensions, (128, 128, 128)).save(source)
            with patch.object(detector.face_recognition, "face_locations", side_effect=AssertionError("Detector forbidden")), patch.object(Image.Image, "getexif", side_effect=AssertionError("EXIF must follow admission")):
                result = detector.detect_faces(str(source), detect_only=True, max_source_bytes=16777216, max_pixels=16000000, max_header_pixels=32000000)
            self.assertFalse(result["success"])
            self.assertIn("pixel limit", result["error"])


if __name__ == "__main__":
    unittest.main()
