import importlib.util
import os
import unittest
from pathlib import Path
from unittest.mock import Mock, patch


MODULE_PATH = Path(__file__).with_name("qwen_reranker_server.py")
SPEC = importlib.util.spec_from_file_location("qwen_reranker_server", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(MODULE)


class RerankerPolicyTest(unittest.TestCase):
    def test_loopback_host_and_origin_policy(self):
        self.assertTrue(MODULE.allowed_host("127.0.0.1:9230", 9230))
        self.assertTrue(MODULE.allowed_host("localhost:9230", 9230))
        self.assertFalse(MODULE.allowed_host("attacker.example:9230", 9230))
        self.assertTrue(MODULE.allowed_origin(None))
        self.assertTrue(MODULE.allowed_origin("http://localhost:9230"))
        self.assertFalse(MODULE.allowed_origin("https://attacker.example"))

    def test_bearer_auth_uses_exact_token(self):
        token = "a" * 32
        self.assertTrue(MODULE.bearer_authorized(f"Bearer {token}", token))
        self.assertFalse(MODULE.bearer_authorized("Bearer wrong", token))
        self.assertFalse(MODULE.bearer_authorized(None, token))

    def test_payload_requires_unique_bounded_documents(self):
        query, documents, instruction = MODULE.validate_payload(
            {
                "query": "target",
                "instruction": "Retrieve direct evidence.",
                "documents": [{"id": "one", "text": "Evidence."}],
            }
        )
        self.assertEqual("target", query)
        self.assertEqual("one", documents[0]["id"])
        self.assertEqual("Retrieve direct evidence.", instruction)

        with self.assertRaises(ValueError):
            MODULE.validate_payload(
                {
                    "query": "target",
                    "instruction": "Retrieve direct evidence.",
                    "documents": [
                        {"id": "one", "text": "First."},
                        {"id": "one", "text": "Second."},
                    ],
                }
            )

    def test_payload_preserves_original_whitespace_and_unicode(self):
        original = "  José\r\n\tEvidence tail  "
        query, documents, instruction = MODULE.validate_payload({
            "query": original, "instruction": original,
            "documents": [{"id": "one", "text": original}],
        })
        self.assertEqual((original, original, original), (query, documents[0]["text"], instruction))

    def test_nontext_document_is_not_coerced(self):
        with self.assertRaises(ValueError):
            MODULE.validate_payload({"query": "query", "instruction": "instruction",
                "documents": [{"id": "one", "text": 123}]})

    def test_later_oversized_pair_refuses_before_any_model_call(self):
        runtime = MODULE.QwenReranker.__new__(MODULE.QwenReranker)
        runtime.config = {"max_length": 8, "batch_size": 1}
        runtime.prefix_tokens = [1]
        runtime.suffix_tokens = [2]
        runtime.tokenizer = Mock(return_value={"input_ids": [[1], list(range(7))]})
        runtime.model = Mock()
        with self.assertRaisesRegex(ValueError, "reranker_input_budget_exceeded"):
            runtime.score("  query tail  ", [{"id": "first", "text": "first"},
                {"id": "last", "text": "  Original José tail  "}], "instruction")
        runtime.model.assert_not_called()
        runtime.tokenizer.assert_called_once_with([
            "<Instruct>: instruction\n<Query>:   query tail  \n<Document>: first",
            "<Instruct>: instruction\n<Query>:   query tail  \n<Document>:   Original José tail  ",
        ], padding=False, truncation=False, add_special_tokens=False, return_attention_mask=False)

    def test_runtime_config_requires_strong_token_and_valid_device(self):
        original = os.environ.copy()
        try:
            os.environ["PLOS_RERANKER_TOKEN"] = "short"
            with self.assertRaises(RuntimeError):
                MODULE.runtime_config()
            os.environ["PLOS_RERANKER_TOKEN"] = "a" * 32
            os.environ["PLOS_RERANKER_DEVICE"] = "external"
            with self.assertRaises(RuntimeError):
                MODULE.runtime_config()
        finally:
            os.environ.clear()
            os.environ.update(original)

    def test_shutdown_worker_is_non_daemon(self):
        server = Mock()
        worker = Mock()
        with patch.object(MODULE.threading, "Thread", return_value=worker) as thread:
            MODULE.request_shutdown(server)

        thread.assert_called_once_with(target=server.shutdown, daemon=False)
        worker.start.assert_called_once_with()


if __name__ == "__main__":
    unittest.main()
