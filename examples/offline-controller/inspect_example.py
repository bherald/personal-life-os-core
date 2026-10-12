#!/usr/bin/env python3
"""Inspect disabled fictional controller data. No network or host operations."""

import argparse
import json
from pathlib import Path


def inspect(topology_path, observations_path):
    topology = json.loads(topology_path.read_text())
    observations = json.loads(observations_path.read_text())
    if topology.get("schema") != "plos.offline_controller.topology_example.v1" or topology.get("fictional") is not True:
        raise ValueError("only explicitly fictional topology examples are supported")
    if observations.get("schema") != "plos.offline_controller.observations_example.v1" or observations.get("fictional") is not True:
        raise ValueError("only explicitly fictional observations are supported")
    seen = set()
    rendered = []
    for host in topology["hosts"]:
        host_id = host["host_id"]
        if host_id in seen or host["controller_enabled"] is not False:
            raise ValueError("unique disabled example hosts required")
        seen.add(host_id)
        relative = Path(host["manifest"])
        if relative.is_absolute() or len(relative.parts) != 1:
            raise ValueError("example manifest must be beside topology")
        manifest_path = topology_path.parent / relative
        if manifest_path.is_symlink():
            raise ValueError("example manifest cannot be a symlink")
        manifest = json.loads(manifest_path.read_text())
        if manifest["host_id"] != host_id or manifest["execution_mode"] != "serial":
            raise ValueError("example host identity/serial posture differs")
        for flag in ("automatic_activation_enabled", "compatible_residency_enabled", "concurrent_inference_enabled"):
            if manifest.get(flag) is not False:
                raise ValueError("example activation/residency/concurrency must be disabled")
        targets = []
        for target_id, spec in manifest["targets"].items():
            if spec.get("activation_allowed") is not False or spec.get("state") != "staged":
                raise ValueError("example target must remain staged and disabled")
            observed = observations["targets"][host_id + "/" + target_id]
            listeners = observed["listeners"]
            if any(type(listener) is not str for listener in listeners):
                raise ValueError("example listener addresses must be strings")
            loopback = sum(address in ("127.0.0.1", "::1") for address in listeners)
            task = observed["task"]
            if task is not None and (not isinstance(task, dict) or not isinstance(task.get("State"), str)):
                raise ValueError("example task requires an explicit State string")
            # Field names match native Get-PLOSRuntimeStatus; fixture data only.
            targets.append({"target": target_id, "model": spec["model"], "engine": spec["engine"],
                            "policy_state": spec["state"], "activation_allowed": False,
                            "task_present": task is not None, "task_state": task["State"] if task else "Missing",
                            "listener_count": len(listeners), "loopback_listener_count": loopback,
                            "external_listener_count": len(listeners) - loopback,
                            "loopback_only": loopback > 0 and loopback == len(listeners), "port": spec["port"]})
        rendered.append({"host_id": host_id, "targets": targets})
    for route in topology["routes"]:
        if route.get("enabled") is not False or route["host_id"] not in seen:
            raise ValueError("example routes must remain disabled and refer to a known host")
        manifest = json.loads((topology_path.parent / next(h["manifest"] for h in topology["hosts"] if h["host_id"] == route["host_id"])).read_text())
        if route["target"] not in manifest["targets"]:
            raise ValueError("route target is not present in the exact host example")
    return {"schema": "plos.offline_controller.example_status.v1", "fictional": True,
            "host_operations_performed": False, "contains_prompt_or_response_content": False,
            "mutation_available": False, "hosts": rendered, "result": "example_only"}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--topology", type=Path, default=Path(__file__).with_name("topology.example.json"))
    parser.add_argument("--observations", type=Path, default=Path(__file__).with_name("observations.example.json"))
    args = parser.parse_args()
    try:
        print(json.dumps(inspect(args.topology, args.observations), indent=2))
    except (OSError, ValueError, KeyError, TypeError) as error:
        parser.exit(2, f"example inspector: {error}\n")


if __name__ == "__main__":
    main()
