#!/usr/bin/env python3
"""Authenticated loopback Qwen reranker sidecar for read-only PLOS evals."""

from __future__ import annotations

import argparse
import hmac
import hashlib
import json
import os
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse
from pathlib import Path

DEFAULT_MODEL = "Qwen/Qwen3-Reranker-0.6B"
DEFAULT_REVISION = "e61197ed45024b0ed8a2d74b80b4d909f1255473"
MAX_BODY_BYTES = 256 * 1024
MAX_DOCUMENTS = 50
MAX_TEXT_CHARS = 32_000


def env_int(name: str, default: int, minimum: int, maximum: int) -> int:
    try:
        value = int(os.environ.get(name, str(default)))
    except ValueError as exc:
        raise RuntimeError(f"{name} must be an integer") from exc
    if value < minimum or value > maximum:
        raise RuntimeError(f"{name} must be between {minimum} and {maximum}")
    return value


def runtime_config() -> dict[str, object]:
    token = os.environ.get("PLOS_RERANKER_TOKEN", "")
    if len(token) < 32:
        raise RuntimeError("PLOS_RERANKER_TOKEN must contain at least 32 characters")

    device = os.environ.get("PLOS_RERANKER_DEVICE", "cpu").strip().lower()
    if device not in {"cpu", "cuda"}:
        raise RuntimeError("PLOS_RERANKER_DEVICE must be cpu or cuda")

    return {
        "token": token,
        "model": os.environ.get("PLOS_RERANKER_MODEL", DEFAULT_MODEL).strip(),
        "revision": os.environ.get("PLOS_RERANKER_REVISION", DEFAULT_REVISION).strip(),
        "device": device,
        "port": env_int("PLOS_RERANKER_PORT", 9230, 1024, 65535),
        "max_length": env_int("PLOS_RERANKER_MAX_LENGTH", 1024, 128, 8192),
        "batch_size": env_int("PLOS_RERANKER_BATCH_SIZE", 4, 1, 16),
        "threads": env_int("PLOS_RERANKER_THREADS", 6, 1, 32),
        "local_only": os.environ.get("PLOS_RERANKER_LOCAL_ONLY", "1") != "0",
    }


def allowed_host(value: str | None, port: int) -> bool:
    return value in {f"127.0.0.1:{port}", f"localhost:{port}"}


def allowed_origin(value: str | None) -> bool:
    if not value:
        return True
    parsed = urlparse(value)
    return parsed.scheme in {"http", "https"} and parsed.hostname in {"127.0.0.1", "localhost"}


def bearer_authorized(value: str | None, expected_token: str) -> bool:
    if not value or not value.startswith("Bearer "):
        return False
    return hmac.compare_digest(value[7:], expected_token)


def request_shutdown(server: ThreadingHTTPServer) -> None:
    # A non-daemon worker prevents interpreter teardown from racing native model cleanup.
    threading.Thread(target=server.shutdown, daemon=False).start()


def validate_payload(payload: object) -> tuple[str, list[dict[str, str]], str]:
    if not isinstance(payload, dict):
        raise ValueError("request body must be a JSON object")

    query = payload.get("query", "")
    instruction = payload.get("instruction", "")
    documents = payload.get("documents")
    if not isinstance(query, str) or not query.strip() or len(query) > MAX_TEXT_CHARS:
        raise ValueError("query length is invalid")
    if not isinstance(instruction, str) or not instruction.strip() or len(instruction) > 2_000:
        raise ValueError("instruction length is invalid")
    if not isinstance(documents, list) or not 1 <= len(documents) <= MAX_DOCUMENTS:
        raise ValueError("documents must be a bounded non-empty list")

    normalized: list[dict[str, str]] = []
    seen: set[str] = set()
    for item in documents:
        if not isinstance(item, dict):
            raise ValueError("each document must be an object")
        identifier = str(item.get("id", "")).strip()
        text = item.get("text", "")
        if not identifier or len(identifier) > 120 or identifier in seen:
            raise ValueError("document ids must be unique and bounded")
        if not isinstance(text, str) or not text.strip() or len(text) > MAX_TEXT_CHARS:
            raise ValueError("document text length is invalid")
        seen.add(identifier)
        normalized.append({"id": identifier, "text": text})

    return query, normalized, instruction


class QwenReranker:
    def __init__(self, config: dict[str, object]) -> None:
        self.config = config
        self.lock = threading.Lock()
        self.request_lock = threading.Lock()
        self.busy = False
        self.started_at = time.time()
        self._load()

    def _load(self) -> None:
        import torch
        from transformers import AutoModelForCausalLM, AutoTokenizer

        torch.set_num_threads(int(self.config["threads"]))
        model_name = str(self.config["model"])
        revision = str(self.config["revision"])
        local_only = bool(self.config["local_only"])
        device = str(self.config["device"])
        if device == "cuda" and not torch.cuda.is_available():
            raise RuntimeError("CUDA requested but unavailable")

        self.torch = torch
        self.tokenizer = AutoTokenizer.from_pretrained(
            self.config.get("snapshot_path", model_name),
            revision=revision,
            padding_side="left",
            local_files_only=local_only,
        )
        dtype = torch.float16 if device == "cuda" else torch.float32
        self.model = AutoModelForCausalLM.from_pretrained(
            self.config.get("snapshot_path", model_name),
            revision=revision,
            dtype=dtype,
            local_files_only=local_only,
        ).eval().to(device)
        self.device = device
        self.false_token_id = self.tokenizer.convert_tokens_to_ids("no")
        self.true_token_id = self.tokenizer.convert_tokens_to_ids("yes")
        self.prefix_tokens = self.tokenizer.encode(
            "<|im_start|>system\nJudge whether the Document meets the requirements based on the Query and the Instruct provided. Note that the answer can only be \"yes\" or \"no\".<|im_end|>\n<|im_start|>user\n",
            add_special_tokens=False,
        )
        self.suffix_tokens = self.tokenizer.encode(
            "<|im_end|>\n<|im_start|>assistant\n<think>\n\n</think>\n\n",
            add_special_tokens=False,
        )

    def score(self, query: str, documents: list[dict[str, str]], instruction: str) -> list[float]:
        pairs = [
            f"<Instruct>: {instruction}\n<Query>: {query}\n<Document>: {document['text']}"
            for document in documents
        ]
        # Preflight every complete pair before the first model call. A later
        # oversized pair cannot produce a partial ranking of earlier documents.
        tokenized = self.tokenizer(pairs, padding=False, truncation=False, add_special_tokens=False, return_attention_mask=False)
        maximum = int(self.config["max_length"])
        for index, input_ids in enumerate(tokenized["input_ids"]):
            if len(input_ids) + len(self.prefix_tokens) + len(self.suffix_tokens) > maximum:
                raise ValueError("reranker_input_budget_exceeded")
            tokenized["input_ids"][index] = self.prefix_tokens + input_ids + self.suffix_tokens
        tokenized = {"input_ids": tokenized["input_ids"]}
        scores: list[float] = []
        batch_size = int(self.config["batch_size"])
        with self.lock, self.torch.inference_mode():
            for offset in range(0, len(documents), batch_size):
                batch = {key: value[offset : offset + batch_size] for key, value in tokenized.items()}
                inputs = self.tokenizer.pad(batch, padding=True, return_tensors="pt").to(self.device)
                logits = self.model(**inputs).logits[:, -1, :]
                binary_logits = self.torch.stack(
                    [logits[:, self.false_token_id], logits[:, self.true_token_id]], dim=1,
                )
                probabilities = self.torch.nn.functional.softmax(binary_logits, dim=1)[:, 1]
                scores.extend(float(value) for value in probabilities.detach().cpu().tolist())
        return scores


class RerankerHandler(BaseHTTPRequestHandler):
    server_version = "PLOSReranker/1"

    def log_message(self, format_string: str, *args: object) -> None:
        sys.stderr.write("reranker_http status=%s\n" % (args[1] if len(args) > 1 else "unknown"))

    def _send(self, status: int, payload: dict[str, object]) -> None:
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def _authorize(self) -> bool:
        config = self.server.runtime.config  # type: ignore[attr-defined]
        port = int(config["port"])
        if not allowed_host(self.headers.get("Host"), port):
            self._send(403, {"error": "forbidden_host"})
            return False
        if not allowed_origin(self.headers.get("Origin")):
            self._send(403, {"error": "forbidden_origin"})
            return False
        if not bearer_authorized(self.headers.get("Authorization"), str(config["token"])):
            self._send(401, {"error": "unauthorized"})
            return False
        return True

    def do_GET(self) -> None:
        if not self._authorize():
            return
        if self.path != "/health":
            self._send(404, {"error": "not_found"})
            return
        runtime = self.server.runtime  # type: ignore[attr-defined]
        self._send(
            200,
            {
                "status": "ok",
                "model": runtime.config["model"],
                "revision": runtime.config["revision"],
                "device": runtime.device,
                "uptime": round(time.time() - runtime.started_at, 3),
                "controller": ({"schema": "plos.reranker_controller_runtime.v1",
                    "model": runtime.config["model"], "revision": runtime.config["revision"],
                    "receipt_sha256": runtime.config["controller"]["_receipt_sha256"],
                    "gateway_sha256": runtime.config["controller"]["gateway_sha256"],
                    "offline_only": True, "complete_input": True, "scoring": "yes_no_softmax",
                    "busy": runtime.busy} if runtime.config.get("controller") else None),
                "security": {
                    "authRequired": True,
                    "hostProtection": True,
                    "originProtection": True,
                    "contentLogging": False,
                },
            },
        )

    def do_POST(self) -> None:
        if not self._authorize():
            return
        if self.path == "/shutdown" and not self.server.runtime.config.get("controller"):
            self._send(200, {"status": "shutting_down"})
            request_shutdown(self.server)
            return
        if self.path != "/rerank":
            self._send(404, {"error": "not_found"})
            return

        try:
            content_length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self._send(400, {"error": "invalid_content_length"})
            return
        if content_length <= 0 or content_length > MAX_BODY_BYTES:
            self._send(413, {"error": "body_too_large"})
            return

        runtime = self.server.runtime
        if not runtime.request_lock.acquire(blocking=False):
            self._send(409, {"error": "reranker_work_busy"})
            return
        runtime.busy = True
        try:
            raw = self.rfile.read(content_length)
            payload = json.loads(raw)
            if runtime.config.get("controller"):
                from qwen_reranker_controller_contract import validate_request
                validate_request(runtime.config["controller"], payload, len(raw))
            query, documents, instruction = validate_payload(payload)
            started = time.monotonic()
            scores = self.server.runtime.score(query, documents, instruction)  # type: ignore[attr-defined]
            self._send(
                200,
                {
                    "status": "pass",
                    "request_sha256": hashlib.sha256(raw).hexdigest(),
                    "complete_input": True, "scoring": "yes_no_softmax",
                    "controller_receipt_sha256": (runtime.config["controller"]["_receipt_sha256"] if runtime.config.get("controller") else None),
                    "scores": [
                        {"id": document["id"], "score": round(score, 8)}
                        for document, score in zip(documents, scores, strict=True)
                    ],
                    "model": self.server.runtime.config["model"],  # type: ignore[attr-defined]
                    "revision": self.server.runtime.config["revision"],  # type: ignore[attr-defined]
                    "device": self.server.runtime.device,  # type: ignore[attr-defined]
                    "duration_ms": round((time.monotonic() - started) * 1000),
                },
            )
        except (ValueError, json.JSONDecodeError) as exc:
            self._send(422, {"error": str(exc)})
        except Exception:
            self._send(500, {"error": "inference_failed"})
        finally:
            runtime.busy = False
            runtime.request_lock.release()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--download-only", action="store_true")
    parser.add_argument("--controller-receipt")
    args = parser.parse_args()
    if args.controller_receipt:
        if args.download_only:
            raise ValueError("controller_download_not_permitted")
        from qwen_reranker_controller_contract import load_contract
        controller = load_contract(args.controller_receipt, Path(__file__).resolve().parent)
        contract = controller["contract"]
        os.environ["HF_HUB_OFFLINE"] = "1"
        os.environ["TRANSFORMERS_OFFLINE"] = "1"
        config = {"controller": controller, "token": controller["_token"],
            "model": controller["model"], "revision": controller["revision"], "device": "cpu",
            "snapshot_path": controller["_snapshot"], "local_only": True, "port": controller["port"],
            "max_length": contract["context_window"], "batch_size": contract["batch_size"], "threads": contract["threads"]}
    else:
        config = runtime_config()
    runtime = QwenReranker(config)
    if args.download_only:
        print(json.dumps({"status": "ready", "model": config["model"], "revision": config["revision"], "device": runtime.device}))
        return 0

    server = ThreadingHTTPServer(("127.0.0.1", int(config["port"])), RerankerHandler)
    server.runtime = runtime  # type: ignore[attr-defined]
    print(
        json.dumps({"status": "listening", "host": "127.0.0.1", "port": config["port"], "model": config["model"], "revision": config["revision"]}),
        flush=True,
    )
    server.serve_forever()
    server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
