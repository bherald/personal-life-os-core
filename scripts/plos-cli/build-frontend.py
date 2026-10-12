#!/usr/bin/env python3
"""Build a pinned private Codex frontend or native executor without replacing Codex."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tomllib

UPSTREAM = "3d2ee51ca2d5db578f328aa75e20aa22c0197c9a"
ROOT = Path(__file__).resolve().parents[2]
PATCH = ROOT / "scripts/patches/codex/plos-frontend-0.153.4.patch"
NATIVE_PATCH = ROOT / "scripts/patches/codex/plos-native-executor-0.153.4.patch"
VT_ROOT = ROOT / 'scripts/patches/codex/windows-vt'
VT_FILES = ['CROSSTERM-LICENSE', 'plos_vt_decoder.rs', 'plos_vt_parse.rs', 'plos_windows_vt.rs', 'provenance.json']


def prepare_windows_vt(source):
    """Apply only to the already-verified base PLOS source in a dedicated checkout."""
    directory = source / 'codex-rs/tui/src/tui'
    changes = {}
    module = directory.parent / 'tui.rs'
    original = module.read_text()
    assert original.count('mod event_stream;') == 1
    changes[module] = original.replace('mod event_stream;', 'mod event_stream;\n'
        '#[cfg(all(windows, feature = "plos"))]\nmod plos_vt_decoder;\n'
        '#[cfg(all(windows, feature = "plos"))]\nmod plos_windows_vt;')
    events = directory / 'event_stream.rs'
    original = events.read_text()
    for token in ['pub struct CrosstermEventSource', 'impl Default for CrosstermEventSource',
                  'impl EventSource for CrosstermEventSource']:
        assert original.count(token) == 1
        original = original.replace(token, '#[cfg(not(all(windows, feature = "plos")))]\n' + token)
    original = original.replace('/// Real crossterm-backed event source.',
        '#[cfg(all(windows, feature = "plos"))]\n'
        'pub type CrosstermEventSource = super::plos_windows_vt::WindowsVtEventSource;\n\n'
        '/// Real crossterm-backed event source.')
    changes[events] = original
    console = directory / 'windows_console.rs'
    original = console.read_text()
    old = 'let requested_mode = input_record_mode(mode);'
    assert original.count(old) == 2
    changes[console] = original.replace(old,
        'let requested_mode = if crate::PLOS_FRONTEND { mode | ENABLE_VIRTUAL_TERMINAL_INPUT }\n'
        '        else { input_record_mode(mode) };')
    for name in VT_FILES:
        if name.endswith('.rs'):
            target = directory / name
            if target.exists():
                raise RuntimeError('Windows VT source already exists; use a fresh dedicated checkout.')
            changes[target] = (VT_ROOT / name).read_text()
    for target, text in changes.items():
        target.write_text(text)
    identity = ''.join(f'{name}:{hashlib.sha256((VT_ROOT / name).read_bytes()).hexdigest()}\n' for name in VT_FILES)
    return hashlib.sha256(identity.encode()).hexdigest()


def run(args, cwd, **kwargs):
    return subprocess.run(args, cwd=cwd, check=True, **kwargs)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--target", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--cargo", default="cargo")
    parser.add_argument("--jobs", default=3, type=int)
    parser.add_argument("--online", action="store_true", help="Permit fetching pinned build dependencies")
    parser.add_argument("--windows-vt", action="store_true", help="Build the reviewed native lossless VT input candidate")
    parser.add_argument("--native-executor", action="store_true", help="Build native Codex with profile loading and operator-controlled compaction")
    args = parser.parse_args()
    if args.jobs < 1:
        raise SystemExit("Build jobs must be positive.")
    if args.native_executor and args.windows_vt:
        raise SystemExit('Native executor and Windows frontend modes cannot be combined.')
    if args.windows_vt and os.name != 'nt':
        raise SystemExit('Windows VT qualification builds require the native Windows toolchain.')
    source = args.source.resolve(strict=True)
    head = run(["git", "rev-parse", "HEAD"], source, capture_output=True, text=True).stdout.strip()
    if head != UPSTREAM:
        raise SystemExit("Wrong Codex source commit; refusing to adapt another revision.")
    untracked = run(["git", "ls-files", "--others", "--exclude-standard", "--", "codex-rs"],
                    source, capture_output=True).stdout
    if untracked:
        raise SystemExit("Untracked source files require review; use a dedicated checkout.")
    patch_path = NATIVE_PATCH if args.native_executor else PATCH
    patch = patch_path.read_bytes()
    diff = run(["git", "diff", "HEAD", "--", "codex-rs"], source, capture_output=True).stdout
    if not diff:
        run(["git", "apply", "--check", str(patch_path)], source)
        run(["git", "apply", str(patch_path)], source)
    elif diff != patch:
        raise SystemExit("Source has changes other than the reviewed PLOS patch; use a dedicated checkout.")
    old = tomllib.loads(run(["git", "show", "HEAD:codex-rs/Cargo.lock"], source, capture_output=True, text=True).stdout)
    new = tomllib.loads((source / "codex-rs/Cargo.lock").read_text())
    external = lambda lock: [p for p in lock["package"] if "source" in p]
    if external(old) != external(new):
        raise SystemExit("External lockfile dependencies changed; provenance review is required.")
    windows_vt = prepare_windows_vt(source) if args.windows_vt else None
    environment = dict(os.environ, CARGO_TARGET_DIR=str(args.target.resolve()),
                       CARGO_PROFILE_DEV_DEBUG="0", CARGO_PROFILE_DEV_INCREMENTAL="false")
    command = [args.cargo, "build", "--locked", "-p", "codex-cli", "--bin", "codex", "-j", str(args.jobs)]
    if not args.native_executor:
        command.extend(["--features", "codex-tui/plos"])
    if not args.online:
        command.append("--offline")
    run(command, source / "codex-rs", env=environment)
    binary = args.target.resolve() / "debug" / ("codex.exe" if os.name == "nt" else "codex")
    refusal_cases = [] if args.native_executor else [["exec", "PLOS_BUILD_REFUSAL_CHECK"],
                 ["--remote", "ws://127.0.0.1:1", "exec", "PLOS_BUILD_REFUSAL_CHECK"],
                 ["resume", "00000000-0000-0000-0000-000000000000"]]
    for case in refusal_cases:
        refusal = subprocess.run([str(binary), *case], capture_output=True, text=True, timeout=15)
        if refusal.returncode == 0 or "requires its app-server endpoint" not in refusal.stderr:
            raise SystemExit("Frontend did not refuse embedded execution or unbound resume.")
    output = args.output.resolve()
    output.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
    temporary = output.with_name(output.name + ".building")
    strip_native_symbols = args.native_executor and sys.platform.startswith('linux')
    if strip_native_symbols:
        # Keep runtime symbols; omit unneeded symbols from the delivered Linux
        # executor. The unstripped build stays in Cargo's disposable cache.
        run(['strip', '--strip-unneeded', '-o', str(temporary), str(binary)], source)
    else:
        shutil.copyfile(binary, temporary)
    temporary.chmod(0o700)
    if args.native_executor:
        features = run([str(temporary), '--enable', 'plos_manual_compaction', '--enable', 'plos_lossless_skills', '--enable', 'plos_lossless_tool_output', 'features', 'list'],
                       source, capture_output=True, text=True).stdout
        for feature in ['plos_manual_compaction', 'plos_lossless_skills', 'plos_lossless_tool_output']:
            if not any(line.split() == [feature, 'stable', 'true'] for line in features.splitlines()):
                raise SystemExit(f'Native executor does not expose the required {feature} feature.')
    with temporary.open("rb") as built_file:
        digest = hashlib.file_digest(built_file, "sha256").hexdigest()
    temporary.replace(output)
    for name in ["LICENSE", "NOTICE"]:
        shutil.copyfile(ROOT / "scripts/patches/codex" / name, output.parent / ("CODEX-" + name))
    receipt = {"schema": "plos.cli.native-build.v1" if args.native_executor else "plos.cli.frontend-build.v1", "upstream_commit": UPSTREAM,
               "feature": "plos_manual_compaction" if args.native_executor else "codex-tui/plos", "patch_sha256": hashlib.sha256(patch).hexdigest(),
               "sha256": digest, "embedded_execution_refused": not args.native_executor,
               "external_dependencies_unchanged": True}
    if args.native_executor:
        receipt['required_features'] = ['plos_manual_compaction', 'plos_lossless_skills', 'plos_lossless_tool_output']
        receipt['symbols_stripped'] = strip_native_symbols
    if windows_vt:
        shutil.copyfile(VT_ROOT / 'CROSSTERM-LICENSE', output.parent / 'CROSSTERM-LICENSE')
        receipt['windows_vt_source_sha256'] = windows_vt
    output.with_name(output.name + ".json").write_text(json.dumps(receipt, indent=2) + "\n")
    print(json.dumps(receipt, indent=2))


if __name__ == "__main__":
    main()
