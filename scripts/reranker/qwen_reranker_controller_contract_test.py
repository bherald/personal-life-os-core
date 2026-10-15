import importlib.util
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

SPEC = importlib.util.spec_from_file_location("rerank_contract", Path(__file__).with_name("qwen_reranker_controller_contract.py"))
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class ControllerContractTest(unittest.TestCase):
    def fixture(self, base):
        (base / "qwen_reranker_server.py").write_text("gateway")
        (base / "qwen_reranker_controller_contract.py").write_text("contract")
        (base / "token").write_text("a" * 32)
        snapshot = base / "snapshot"
        snapshot.mkdir()
        files = []
        for name in ("config.json", "tokenizer.json", "tokenizer_config.json", "model.safetensors"):
            path = snapshot / name
            path.write_bytes(b"fixture")
            files.append({"path": name, "bytes": 7, "sha256": MODULE.file_sha256(path)})
        contract = {"wire_api": "rerank", "model": "fixture", "revision": "a" * 40, "controller_target": "fixture-target",
                    "device": "cpu", "scoring": "yes_no_softmax", "complete_input": True, "context_window": 1024,
                    "native_port": 9230, "backend_http_host": "127.0.0.1:9230",
                    "maximum_input_bytes": 2048, "maximum_documents": 3, "instruction": "Retrieve evidence.", "batch_size": 1, "threads": 4}
        value = {"schema": "plos.reranker_controller_runtime.v1", "target": "fixture-target", "model": "fixture", "revision": "a" * 40,
                 "port": 9230, "api_key_path": str(base / "token"), "snapshot_path": str(snapshot),
                 "gateway_sha256": MODULE.file_sha256(base / "qwen_reranker_server.py"),
                 "contract_module_sha256": MODULE.file_sha256(base / "qwen_reranker_controller_contract.py"), "files": files, "contract": contract}
        receipt = base / "receipt.json"
        receipt.write_text(json.dumps(value))
        return receipt, value

    def test_pinned_cpu_contract_is_content_free_and_exact(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            receipt, value = self.fixture(base)
            result = MODULE.load_contract(str(receipt), base)
            self.assertEqual(hashlib.sha256(receipt.read_bytes()).hexdigest(), result["_receipt_sha256"])
            self.assertEqual("cpu", result["contract"]["device"])
            payload = {"model": "fixture", "query": "  query  ", "instruction": "Retrieve evidence.",
                       "documents": [{"id": "one", "text": "  José tail  "}]}
            MODULE.validate_request(result, payload, len(json.dumps(payload).encode()))
            self.assertEqual("  José tail  ", payload["documents"][0]["text"])

    def test_artifact_drift_or_uncovered_file_refuses(self):
        for drift in ("changed", "extra"):
            with self.subTest(drift=drift), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                receipt, value = self.fixture(base)
                path = base / "snapshot" / ("model.safetensors" if drift == "changed" else "extra")
                path.write_bytes(b"changed")
                with self.assertRaises(ValueError):
                    MODULE.load_contract(str(receipt), base)

    def test_scoring_or_cpu_drift_refuses(self):
        for key, value in (("device", "cuda"), ("complete_input", False), ("scoring", "chat_json"),
                           ("backend_http_host", "198.51.100.17:9230"), ("native_port", 9231)):
            with self.subTest(key=key), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                receipt, body = self.fixture(base)
                body["contract"][key] = value
                receipt.write_text(json.dumps(body))
                with self.assertRaises(ValueError):
                    MODULE.load_contract(str(receipt), base)

    def test_request_cannot_change_model_instruction_or_envelope(self):
        controller = {"model": "fixture", "contract": {"instruction": "Evidence.", "maximum_input_bytes": 2048, "maximum_documents": 1}}
        for payload, size in (({"model": "other", "query": "q", "instruction": "Evidence.", "documents": [{}]}, 20),
                              ({"model": "fixture", "query": "q", "instruction": "Other.", "documents": [{}]}, 20),
                              ({"model": "fixture", "query": "q", "instruction": "Evidence.", "documents": [{}]}, 2049)):
            with self.subTest(payload=payload):
                with self.assertRaises(ValueError):
                    MODULE.validate_request(controller, payload, size)


if __name__ == "__main__":
    unittest.main()
