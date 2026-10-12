"""Generated pixel fixtures; no real faces, model inference, or private source images."""
import hashlib
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("face_detector_under_test", ROOT / "scripts/face_detector.py")
detector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(detector)

class SnapshotChecks(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="plos-face-detector-")
        self.root = Path(self.temporary.name)
        self.crops = self.root / "crops"
        self.a = self.root / "a" / "same.png"
        self.b = self.root / "b" / "same.png"
        self.write(self.a, (255, 0, 0))
        self.write(self.b, (0, 0, 255))
        self.locations = patch.object(detector.face_recognition, "face_locations", return_value=[(20, 70, 70, 20)])
        self.encodings = patch.object(detector.face_recognition, "face_encodings", return_value=[np.full(128, 0.1)])
        self.locations.start()
        self.encodings.start()
        self.addCleanup(self.locations.stop)
        self.addCleanup(self.encodings.stop)
        self.addCleanup(self.temporary.cleanup)

    def write(self, path, color):
        path.parent.mkdir(exist_ok=True)
        Image.new("RGB", (100, 100), color).save(path)

    def detect(self, path):
        return detector.detect_faces(str(path), True, str(self.crops))

    def test_selected_region_encodes_exact_box_without_detection(self):
        region = {"x": .12345678, "y": .22, "w": .31, "h": .42}
        with patch.object(detector.face_recognition, "face_locations", side_effect=AssertionError("HOG must not run")), patch.object(detector.face_recognition, "face_encodings", return_value=[np.full(128, .1)]) as encode:
            result = detector.detect_faces(str(self.a), True, str(self.crops), source_bound_generation=True, max_detector_dimension=100, selected_region=region)
        self.assertTrue(result["success"], result)
        self.assertEqual(result["faces"][0]["normalized"], region)
        self.assertEqual(encode.call_args.args[1], [(22, 44, 64, 12)])
        self.assertEqual(result["detector_provenance"]["model"], "operator_selected_region")
        self.assertEqual(result["engine_provenance"]["detector"], "operator_selected_region")
        self.assertEqual(result["detector_provenance"]["encoding_raster_box"], [22, 44, 64, 12])

    def test_selected_region_rejects_unbounded_or_invalid_requests(self):
        region = {"x": .1, "y": .2, "w": .3, "h": .4}
        for options in [{}, {"source_bound_generation": True}, {"source_bound_generation": True, "max_detector_dimension": 100, "detect_only": True}]:
            result = detector.detect_faces(str(self.a), True, str(self.crops), selected_region=region, **options)
            self.assertFalse(result["success"], options)
        for invalid in [{**region, "x": float("nan")}, {**region, "w": 0}, {**region, "x": .9}, {**region, "x": True}, {**region, "extra": 1}]:
            result = detector.detect_faces(str(self.a), True, str(self.crops), source_bound_generation=True, max_detector_dimension=100, selected_region=invalid)
            self.assertFalse(result["success"], invalid)

    def test_same_basename_different_bytes_cannot_overwrite_crop(self):
        a, b = self.detect(self.a), self.detect(self.b)
        self.assertTrue(a["success"] and b["success"])
        self.assertNotEqual(a["faces"][0]["crop_path"], b["faces"][0]["crop_path"])
        for result, channel in [(a, 0), (b, 2)]:
            with Image.open(result["faces"][0]["crop_path"]) as image:
                self.assertGreater(image.getpixel((20, 20))[channel], 240)

    def test_changed_bytes_same_path_use_new_crop_and_unchanged_reuses(self):
        first = self.detect(self.a)
        crop = first["faces"][0]["crop_path"]
        os.utime(crop, (100, 100))
        second = self.detect(self.a)
        self.assertEqual(first["source_sha256"], hashlib.sha256(self.a.read_bytes()).hexdigest())
        self.assertEqual(crop, second["faces"][0]["crop_path"])
        self.assertEqual(os.stat(crop).st_mtime, 100)
        self.write(self.a, (0, 0, 255))
        third = self.detect(self.a)
        self.assertNotEqual(crop, third["faces"][0]["crop_path"])
        self.assertTrue(Path(crop).is_file())

    def test_changed_region_uses_independent_crop(self):
        first = self.detect(self.a)
        with patch.object(detector.face_recognition, "face_locations", return_value=[(10, 60, 60, 10)]):
            second = self.detect(self.a)
        self.assertNotEqual(first["faces"][0]["crop_path"], second["faces"][0]["crop_path"])

    def test_decode_and_hash_use_same_snapshot_and_source_race_fails_closed(self):
        load_snapshot = detector.face_recognition.load_image_file
        def replace_before_decode(snapshot):
            self.write(self.a, (0, 0, 255))
            return load_snapshot(snapshot)
        def encode_snapshot(image, locations, model):
            self.assertEqual(model, "small")
            self.assertEqual(tuple(image[0, 0]), (255, 0, 0))
            return [np.full(128, 0.1)]
        with patch.object(detector.face_recognition, "load_image_file", side_effect=replace_before_decode), patch.object(detector.face_recognition, "face_encodings", side_effect=encode_snapshot):
            result = self.detect(self.a)
        self.assertFalse(result["success"])
        self.assertIn("Source changed", result["error"])
        self.assertNotIn("faces", result)
        self.assertEqual(list(self.crops.glob(".face-*")), [])

    def test_no_face_source_race_is_not_published_as_fresh_zero_faces(self):
        def replace_source(image, model):
            self.write(self.a, (0, 0, 255))
            return []
        with patch.object(detector.face_recognition, "face_locations", side_effect=replace_source):
            result = self.detect(self.a)
        self.assertFalse(result["success"])
        self.assertNotIn("face_count", result)

    def test_corrupt_existing_bound_crop_is_rebuilt_atomically(self):
        first = self.detect(self.a)
        crop = Path(first["faces"][0]["crop_path"])
        crop.write_bytes(b"broken JPEG")
        result = self.detect(self.a)
        self.assertTrue(result["success"])
        with Image.open(crop) as image:
            image.verify()
            self.assertEqual(image.format, "JPEG")
        self.assertEqual(list(self.crops.glob(".face-*")), [])

if __name__ == "__main__":
    unittest.main()
