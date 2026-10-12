import argparse
import hashlib
import json
import os
import sys

parser = argparse.ArgumentParser()
parser.add_argument("--source", required=True)
parser.add_argument("--target", required=True)
parser.add_argument("--expected-dev", required=True)
parser.add_argument("--expected-ino", required=True)
parser.add_argument("--expected-size", required=True)
parser.add_argument("--expected-sha256", required=True)
args = parser.parse_args()

replacement = args.source + ".replacement"
with open(replacement, "wb") as stream:
    stream.write(b"concurrent replacement")
os.replace(replacement, args.source)
os.replace(args.source, args.target)
metadata = os.stat(args.target)
with open(args.target, "rb") as stream:
    digest = hashlib.sha256(stream.read()).hexdigest()
print(json.dumps({"state": "recovery_required", "reason": "moved_postimage_mismatch",
                  "moved": {"dev": metadata.st_dev, "ino": metadata.st_ino,
                            "size": metadata.st_size, "sha256": digest}},
                 sort_keys=True, separators=(",", ":")))
sys.exit(1)
