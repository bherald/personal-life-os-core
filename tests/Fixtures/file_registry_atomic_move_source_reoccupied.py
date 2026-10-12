import argparse
import hashlib
import json
import os
import sys

parser = argparse.ArgumentParser()
parser.add_argument("--source", required=True)
parser.add_argument("--target", required=True)
parser.add_argument("--expected-dev", required=True, type=int)
parser.add_argument("--expected-ino", required=True, type=int)
parser.add_argument("--expected-size", required=True, type=int)
parser.add_argument("--expected-sha256", required=True)
args = parser.parse_args()

os.replace(args.source, args.target)
with open(args.source, "wb") as stream:
    stream.write(b"new source path")
target = os.stat(args.target)
source = os.stat(args.source)
with open(args.target, "rb") as stream:
    moved_hash = hashlib.sha256(stream.read()).hexdigest()
print(json.dumps({"state": "recovery_required", "reason": "source_path_reoccupied_after_move",
                  "moved": {"dev": target.st_dev, "ino": target.st_ino, "size": target.st_size,
                            "sha256": moved_hash},
                  "source_current": {"dev": source.st_dev, "ino": source.st_ino, "size": source.st_size,
                                     "same_inode": (source.st_dev, source.st_ino) == (target.st_dev, target.st_ino)}},
                 sort_keys=True, separators=(",", ":")))
sys.exit(1)
