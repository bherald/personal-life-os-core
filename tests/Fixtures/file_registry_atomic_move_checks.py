from __future__ import annotations

import importlib.util
import os
import pathlib
import tempfile
import unittest
from unittest.mock import patch


ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("file_registry_atomic_move", ROOT / "scripts/file_registry_atomic_move.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(MODULE)


class AtomicMoveChecks(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="plos-atomic-move-")
        self.root = pathlib.Path(self.temp.name)
        self.source = self.root / "source.bin"
        self.target = self.root / "quarantine" / "source.bin"
        self.target.parent.mkdir()
        self.source.write_bytes(b"original bytes")
        self.expected = self.pin(self.source)

    def tearDown(self) -> None:
        self.temp.cleanup()

    @staticmethod
    def pin(path: pathlib.Path) -> dict[str, object]:
        metadata = path.stat()
        import hashlib
        return {"dev": metadata.st_dev, "ino": metadata.st_ino, "size": metadata.st_size,
                "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}

    def test_atomic_noreplace_moves_exact_source(self) -> None:
        result = MODULE.move_pinned(str(self.source), str(self.target), self.expected)
        self.assertEqual("moved", result["state"])
        self.assertEqual(self.expected, result["moved"])
        self.assertFalse(self.source.exists())
        self.assertEqual(b"original bytes", self.target.read_bytes())

    def test_destination_created_at_syscall_is_not_overwritten(self) -> None:
        def race(source_dir_fd: int, source_name: bytes, target_dir_fd: int, target_name: bytes) -> None:
            self.target.write_bytes(b"concurrent destination")
            MODULE._rename_noreplace(source_dir_fd, source_name, target_dir_fd, target_name)

        result = MODULE.move_pinned(str(self.source), str(self.target), self.expected, race)
        self.assertEqual("held", result["state"])
        self.assertEqual(b"original bytes", self.source.read_bytes())
        self.assertEqual(b"concurrent destination", self.target.read_bytes())

    def test_source_replacement_in_final_gap_is_moved_but_held_for_recovery(self) -> None:
        def race(source_dir_fd: int, source_name: bytes, target_dir_fd: int, target_name: bytes) -> None:
            replacement = self.root / "replacement.bin"
            replacement.write_bytes(b"concurrent replacement")
            os.replace(replacement, self.source)
            MODULE._rename_noreplace(source_dir_fd, source_name, target_dir_fd, target_name)

        result = MODULE.move_pinned(str(self.source), str(self.target), self.expected, race)
        self.assertEqual("recovery_required", result["state"])
        self.assertEqual("moved_postimage_mismatch", result["reason"])
        self.assertEqual(b"concurrent replacement", self.target.read_bytes())
        self.assertFalse(self.source.exists())

    def test_wrong_moved_inode_is_retained_and_reported(self) -> None:
        def race(source_dir_fd: int, source_name: bytes, target_dir_fd: int, target_name: bytes) -> None:
            MODULE._rename_noreplace(source_dir_fd, source_name, target_dir_fd, target_name)
            self.target.write_bytes(b"post-move replacement")

        result = MODULE.move_pinned(str(self.source), str(self.target), self.expected, race)
        self.assertEqual("recovery_required", result["state"])
        self.assertEqual("moved_postimage_mismatch", result["reason"])
        self.assertTrue(self.target.exists())

    def test_new_source_path_after_move_is_retained_and_reported(self) -> None:
        def race(source_dir_fd: int, source_name: bytes, target_dir_fd: int, target_name: bytes) -> None:
            MODULE._rename_noreplace(source_dir_fd, source_name, target_dir_fd, target_name)
            self.source.write_bytes(b"new source path")

        result = MODULE.move_pinned(str(self.source), str(self.target), self.expected, race)
        self.assertEqual("recovery_required", result["state"])
        self.assertEqual("source_path_reoccupied_after_move", result["reason"])
        self.assertEqual(self.expected, result["moved"])
        self.assertEqual(b"new source path", self.source.read_bytes())
        self.assertEqual(b"original bytes", self.target.read_bytes())

    def test_missing_noreplace_syscall_holds_without_mutation(self) -> None:
        def unavailable(*_args: object) -> None:
            raise OSError(38, "Function not implemented")

        result = MODULE.move_pinned(str(self.source), str(self.target), self.expected, unavailable)
        self.assertEqual("held", result["state"])
        self.assertEqual("noreplace_unavailable", result["reason"])
        self.assertEqual(b"original bytes", self.source.read_bytes())
        self.assertFalse(self.target.exists())

    def test_directory_fsync_failure_is_recovery_required_after_move(self) -> None:
        calls = []

        def fail_second_directory_sync(directory_fd: int) -> None:
            calls.append(directory_fd)
            if len(calls) == 1:
                os.fsync(directory_fd)
                return
            raise OSError(5, "injected directory fsync failure")

        result = MODULE.move_pinned(str(self.source), str(self.target), self.expected,
                                    fsync_call=fail_second_directory_sync)

        self.assertEqual("recovery_required", result["state"])
        self.assertEqual("directory_fsync_failed", result["reason"])
        self.assertEqual(2, len(calls))
        self.assertFalse(self.source.exists())
        self.assertEqual(b"original bytes", self.target.read_bytes())


if __name__ == "__main__":
    unittest.main()
