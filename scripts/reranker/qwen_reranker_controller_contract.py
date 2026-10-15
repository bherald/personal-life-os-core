"""Pinned offline CPU execution of the existing Qwen yes/no reranker."""
from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def load_contract(filename: str, base: Path) -> dict:
    base = base.resolve(strict=True)
    receipt = Path(filename).resolve(strict=True)
    if receipt.parent != base:
        raise ValueError("reranker_receipt_scope_changed")
    raw = receipt.read_bytes()
    value = json.loads(raw)
    fields = {"schema", "target", "model", "revision", "port", "api_key_path", "snapshot_path",
              "gateway_sha256", "contract_module_sha256", "files", "contract"}
    if not isinstance(value, dict) or set(value) != fields or value["schema"] != "plos.reranker_controller_runtime.v1":
        raise ValueError("reranker_receipt_unqualified")
    for key in ("target", "model", "revision", "api_key_path", "snapshot_path"):
        if not isinstance(value[key], str) or not value[key]:
            raise ValueError("reranker_identity_missing")
    if not re.fullmatch(r"[a-f0-9]{40}", value["revision"]):
        raise ValueError("reranker_revision_unpinned")
    for key, name in (("gateway_sha256", "qwen_reranker_server.py"),
                      ("contract_module_sha256", "qwen_reranker_controller_contract.py")):
        if not isinstance(value[key], str) or not re.fullmatch(r"[a-f0-9]{64}", value[key]) or file_sha256(base / name) != value[key]:
            raise ValueError("reranker_runtime_bytes_changed")
    if type(value["port"]) is not int or not 1024 <= value["port"] <= 65535:
        raise ValueError("reranker_endpoint_unqualified")
    token_path = Path(value["api_key_path"]).resolve(strict=True)
    if token_path.parent != base:
        raise ValueError("reranker_credential_scope_changed")
    token = token_path.read_text(encoding="utf-8").strip()
    if len(token) < 32 or any(c.isspace() for c in token):
        raise ValueError("reranker_credential_unqualified")
    snapshot = Path(value["snapshot_path"]).resolve(strict=True)
    if base not in snapshot.parents or not snapshot.is_dir():
        raise ValueError("reranker_artifact_scope_changed")
    rows = value["files"]
    if not isinstance(rows, list) or not rows:
        raise ValueError("reranker_artifact_manifest_missing")
    covered = set()
    for row in rows:
        if not isinstance(row, dict) or set(row) != {"path", "bytes", "sha256"}:
            raise ValueError("reranker_artifact_manifest_invalid")
        rel = row["path"]
        if not isinstance(rel, str) or Path(rel).is_absolute() or ".." in Path(rel).parts or rel in covered:
            raise ValueError("reranker_artifact_manifest_invalid")
        path = (snapshot / rel).resolve(strict=True)
        if snapshot not in path.parents or not path.is_file() or type(row["bytes"]) is not int or row["bytes"] < 1:
            raise ValueError("reranker_artifact_manifest_invalid")
        if path.stat().st_size != row["bytes"] or not isinstance(row["sha256"], str) or not re.fullmatch(r"[a-f0-9]{64}", row["sha256"]) or file_sha256(path) != row["sha256"]:
            raise ValueError("reranker_artifact_bytes_changed")
        covered.add(rel)
    required = {"config.json", "tokenizer.json", "tokenizer_config.json", "model.safetensors"}
    actual = {p.relative_to(snapshot).as_posix() for p in snapshot.rglob("*") if p.is_file()}
    if not required <= covered or actual != covered:
        raise ValueError("reranker_artifact_coverage_incomplete")
    contract = value["contract"]
    if not isinstance(contract, dict) or contract.get("wire_api") != "rerank" or contract.get("model") != value["model"] or contract.get("revision") != value["revision"] or contract.get("controller_target") != value["target"]:
        raise ValueError("reranker_contract_identity_changed")
    if contract.get("device") != "cpu" or contract.get("scoring") != "yes_no_softmax" or contract.get("complete_input") is not True:
        raise ValueError("reranker_scoring_contract_changed")
    if contract.get("native_port") != value["port"] or contract.get("backend_http_host") not in (f"127.0.0.1:{value['port']}", f"localhost:{value['port']}"):
        raise ValueError("reranker_backend_host_unbound")
    for key, lower, upper in (("context_window", 128, 8192), ("batch_size", 1, 16), ("threads", 1, 32),
                              ("maximum_documents", 1, 50), ("maximum_input_bytes", 1024, 262144)):
        if type(contract.get(key)) is not int or not lower <= contract[key] <= upper:
            raise ValueError("reranker_contract_limit_invalid")
    if not isinstance(contract.get("instruction"), str) or not contract["instruction"].strip():
        raise ValueError("reranker_instruction_missing")
    value["_receipt_sha256"] = hashlib.sha256(raw).hexdigest()
    value["_token"] = token
    value["_snapshot"] = str(snapshot)
    return value


def validate_request(controller: dict, payload: dict, byte_count: int) -> None:
    contract = controller["contract"]
    if set(payload) != {"model", "query", "instruction", "documents"} or payload.get("model") != controller["model"] or payload.get("instruction") != contract["instruction"]:
        raise ValueError("reranker_request_contract_changed")
    if byte_count > contract["maximum_input_bytes"] or not isinstance(payload.get("documents"), list) or not 1 <= len(payload["documents"]) <= contract["maximum_documents"]:
        raise ValueError("reranker_input_budget_exceeded")
