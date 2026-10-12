import argparse
import errno
import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
from file_registry_atomic_move import move_pinned

parser = argparse.ArgumentParser()
parser.add_argument("--source", required=True)
parser.add_argument("--target", required=True)
parser.add_argument("--expected-dev", required=True, type=int)
parser.add_argument("--expected-ino", required=True, type=int)
parser.add_argument("--expected-size", required=True, type=int)
parser.add_argument("--expected-sha256", required=True)
args = parser.parse_args()
sync_count = 0


def fail_second_directory_sync(directory_fd: int) -> None:
    global sync_count
    sync_count += 1
    if sync_count == 1:
        import os
        os.fsync(directory_fd)
        return
    raise OSError(errno.EIO, "injected directory fsync failure")


result = move_pinned(args.source, args.target, {"dev": args.expected_dev, "ino": args.expected_ino,
                                               "size": args.expected_size, "sha256": args.expected_sha256},
                     fsync_call=fail_second_directory_sync)
print(json.dumps(result, sort_keys=True, separators=(",", ":")))
sys.exit(0 if result["state"] == "moved" else 1)
