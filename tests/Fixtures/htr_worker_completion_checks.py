"""Execute actual HTR worker with wholly stubbed model, torch and image modules."""
import contextlib
import importlib.util
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("htr_worker_under_test", ROOT / "scripts/htr_transcribe.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)

class Row(list):
    def tolist(self):
        return list(self)

class Image:
    def __init__(self, sizes, frame=0):
        self.sizes, self.frame = sizes, frame
        self.n_frames = len(sizes)
    @property
    def size(self):
        return self.sizes[self.frame]
    @property
    def width(self):
        return self.size[0]
    @property
    def height(self):
        return self.size[1]
    def seek(self, frame):
        self.frame = frame
    def convert(self, mode):
        return Image([self.size])
    def crop(self, box):
        return Image([(box[2] - box[0], box[3] - box[1])])
    def __enter__(self):
        return self
    def __exit__(self, *args):
        pass
    def close(self):
        pass

class Probability:
    def max(self):
        return self
    def cpu(self):
        return .99

class CompletionChecks(unittest.TestCase):
    def run_worker(self, sequences=None, sizes=None, eos=2, score_count=None, fail_at=None, strict=False):
        sequences = sequences or [[0, 42, 2]]
        sizes = sizes or [(100, 100)]
        generated = []
        loader_calls = []
        class Model:
            generation_config = types.SimpleNamespace(eos_token_id=eos)
            def to(self, device): return self
            def eval(self): pass
            def generate(self, pixels, **options):
                generated.append(options)
                index = len(generated) - 1
                if index == fail_at:
                    raise RuntimeError("synthetic decode error")
                sequence = sequences[min(index, len(sequences) - 1)]
                scores = [object()] * (score_count if score_count is not None else max(0, len(sequence) - 1))
                return types.SimpleNamespace(sequences=[Row(sequence)], scores=scores)
        class Processor:
            def __call__(self, **kwargs):
                return types.SimpleNamespace(pixel_values=types.SimpleNamespace(to=lambda device: object()))
            def batch_decode(self, sequences, **kwargs):
                return [" retained partial text "]
        def load_processor(*args, **kwargs):
            self.assertEqual("1", os.environ["HF_HUB_OFFLINE"])
            self.assertEqual("1", os.environ["TRANSFORMERS_OFFLINE"])
            self.assertEqual("1", os.environ["HF_HUB_DISABLE_TELEMETRY"])
            loader_calls.append(kwargs)
            return Processor()
        def load_model(*args, **kwargs):
            loader_calls.append(kwargs)
            return Model()
        modules = {
            "torch": types.SimpleNamespace(cuda=types.SimpleNamespace(is_available=lambda: False),
                no_grad=contextlib.nullcontext, softmax=lambda *args, **kwargs: Probability()),
            "transformers": types.SimpleNamespace(TrOCRProcessor=types.SimpleNamespace(from_pretrained=load_processor),
                VisionEncoderDecoderModel=types.SimpleNamespace(from_pretrained=load_model)),
            "PIL": types.SimpleNamespace(Image=types.SimpleNamespace(open=lambda path: Image(sizes))),
        }
        with tempfile.TemporaryDirectory(prefix="plos-htr-worker-test-") as directory:
            path = Path(directory) / "synthetic-source"
            path.write_bytes(b"synthetic source; no image/model data")
            options = {"schema": "plos.cli.htr.v1", "model_path": directory, "device": "cpu", "max_new_tokens": 128} if strict else None
            with patch.dict("sys.modules", modules), patch.dict(os.environ, {"HF_HUB_OFFLINE": "0", "TRANSFORMERS_OFFLINE": "0", "HF_HUB_DISABLE_TELEMETRY": "0"}):
                result = worker.transcribe(str(path), cli_options=options)
        self.assertTrue(all(call["max_new_tokens"] == 128 for call in generated))
        self.assertTrue(all(call["output_scores"] for call in generated))
        self.assertTrue(all(call["local_files_only"] is True for call in loader_calls))
        self.assertTrue(all(call["trust_remote_code"] is False for call in loader_calls))
        return result, generated, loader_calls

    def test_eos_full_single_strip_is_complete(self):
        result, calls, _ = self.run_worker()
        self.assertTrue(result["complete"])
        self.assertEqual("eos", result["termination"])
        self.assertEqual((1, 1), (result["total_strips"], result["processed_strips"]))
        self.assertEqual(128, result["max_new_tokens"])
        self.assertEqual(.99, result["confidence"])
        self.assertEqual(1, len(calls))

    def test_high_confidence_token_cap_preserves_partial_but_is_not_complete(self):
        result, _, _ = self.run_worker(sequences=[[0] + [42] * 128])
        self.assertFalse(result["complete"])
        self.assertEqual("token_limit", result["termination"])
        self.assertEqual(.99, result["confidence"])
        self.assertEqual("retained partial text", result["text"])

    def test_short_non_eos_is_not_complete(self):
        result, _, _ = self.run_worker(sequences=[[0, 42]])
        self.assertFalse(result["complete"])
        self.assertEqual("non_eos", result["termination"])

    def test_missing_or_malformed_eos_contract_is_not_complete(self):
        for eos in (None, [], True, [True], "2"):
            with self.subTest(eos=eos):
                result, _, _ = self.run_worker(eos=eos)
                self.assertFalse(result["complete"])
                self.assertEqual("missing_eos_contract", result["termination"])
                self.assertEqual("retained partial text", result["text"])

    def test_multi_strip_all_eos_is_complete(self):
        result, calls, _ = self.run_worker(sizes=[(100, 360)])
        self.assertTrue(result["complete"])
        self.assertEqual((3, 3), (result["total_strips"], result["processed_strips"]))
        self.assertEqual(3, len(calls))

    def test_one_non_eos_strip_holds_whole_result(self):
        result, _, _ = self.run_worker(sizes=[(100, 360)], sequences=[[0, 42, 2], [0, 42], [0, 42, 2]])
        self.assertFalse(result["complete"])
        self.assertEqual("non_eos", result["termination"])
        self.assertEqual(3, result["processed_strips"])

    def test_twenty_strip_cap_does_not_claim_full_source_coverage(self):
        result, calls, _ = self.run_worker(sizes=[(100, 2520)])
        self.assertFalse(result["complete"])
        self.assertEqual("partial_coverage", result["termination"])
        self.assertEqual((21, 20), (result["total_strips"], result["processed_strips"]))
        self.assertEqual(20, len(calls))

    def test_omitted_tiny_final_strip_is_still_counted(self):
        result, calls, _ = self.run_worker(sizes=[(100, 370)])
        self.assertFalse(result["complete"])
        self.assertEqual("partial_coverage", result["termination"])
        self.assertEqual((4, 3), (result["total_strips"], result["processed_strips"]))
        self.assertEqual(3, len(calls))

    def test_other_source_frames_are_counted_but_not_newly_inferred(self):
        result, calls, _ = self.run_worker(sizes=[(100, 100), (100, 360)])
        self.assertFalse(result["complete"])
        self.assertEqual("partial_coverage", result["termination"])
        self.assertEqual((4, 1), (result["total_strips"], result["processed_strips"]))
        self.assertEqual(2, result["source_frame_count"])
        self.assertEqual(1, len(calls))

    def test_worker_error_retains_prior_partial_text_and_fixed_hold_reason(self):
        result, _, _ = self.run_worker(sizes=[(100, 360)], fail_at=1)
        self.assertFalse(result["complete"])
        self.assertEqual("error", result["termination"])
        self.assertEqual("retained partial text", result["text"])
        self.assertEqual((3, 1), (result["total_strips"], result["processed_strips"]))

    def test_strict_cli_success_retains_existing_contract(self):
        result, _, loaders = self.run_worker(strict=True)
        self.assertTrue(result["complete"])
        self.assertEqual("plos.cli.htr-result.v1", result["schema"])
        self.assertNotIn("termination", result)
        self.assertTrue(all(call["local_files_only"] and not call["trust_remote_code"] for call in loaders))

    def test_strict_cli_cap_remains_incomplete(self):
        result, _, _ = self.run_worker(sequences=[[0] + [42] * 128], strict=True)
        self.assertFalse(result["complete"])
        self.assertEqual("incomplete_generation", result["error"])

if __name__ == "__main__":
    unittest.main()
