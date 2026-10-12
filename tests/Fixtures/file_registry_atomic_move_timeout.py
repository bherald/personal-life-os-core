import argparse
import pathlib
import sys
import time

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
result = move_pinned(args.source, args.target, {"dev": args.expected_dev, "ino": args.expected_ino,
                                               "size": args.expected_size, "sha256": args.expected_sha256})
if result.get("state") != "moved":
    print("{}")
    sys.exit(1)
time.sleep(3)
print("{}")
