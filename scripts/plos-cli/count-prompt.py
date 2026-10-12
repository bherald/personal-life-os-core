#!/usr/bin/env python3
"""Count the selected Colibri GLM prompt with its exact local runtime assets.

One private JSON request on stdin, one complete JSON response on stdout.
No inference, downloads, approximation, truncation or prompt repair.
"""
import base64
import contextlib
import copy
import hashlib
import io
import json
from pathlib import Path
import sys
import types


def verified_asset(definition):
    if not isinstance(definition, dict) or set(definition) != {'path', 'sha256'}:
        raise ValueError('Invalid runtime asset reference.')
    path = Path(definition['path'])
    if not path.is_absolute() or path.is_symlink() or not path.is_file():
        raise ValueError('Runtime asset is unavailable.')
    data = path.read_bytes()
    if hashlib.sha256(data).hexdigest() != definition['sha256']:
        raise ValueError('Runtime asset identity changed.')
    return path, data


def module_from_bytes(name, asset):
    path, source = asset
    module = types.ModuleType(name)
    module.__file__ = str(path)
    sys.modules[name] = module
    exec(compile(source, str(path), 'exec'), module.__dict__)
    return module


def count_prompt(request):
    if request.get('driver') != 'colibri_glm':
        raise ValueError('Selected tokenizer driver is unavailable.')
    if type(request.get('enable_thinking')) is not bool:
        raise ValueError('Explicit thinking selection is required for exact counting.')
    assets = request.get('assets', {})
    legacy_assets = {'renderer', 'tokenizer', 'family_registry', 'v4_dsml'}
    if set(assets) not in (legacy_assets, legacy_assets | {'v41_dsml'}):
        raise ValueError('Complete selected runtime assets are required.')
    # Verify every dependency before any runtime source executes. Execute the
    # verified bytes, rather than reopening source files after verification.
    verified = {name: verified_asset(value) for name, value in assets.items()}
    import tokenizers
    if tokenizers.__version__ != request.get('tokenizers_version'):
        raise ValueError('Installed tokenizer library differs from selected binding.')
    tokenizer = tokenizers.Tokenizer.from_str(verified['tokenizer'][1].decode('utf-8'))
    tokenizer.no_truncation()
    tokenizer.no_padding()
    module_from_bytes('family_registry', verified['family_registry'])
    module_from_bytes('v4_dsml', verified['v4_dsml'])
    # A new renderer may import V4.1 only from its exact verified asset. Never
    # satisfy a missing pin from an ambient PYTHONPATH or the runtime directory.
    if 'v41_dsml' in verified:
        module_from_bytes('v41_dsml', verified['v41_dsml'])
    else:
        sys.modules['v41_dsml'] = None
    renderer = module_from_bytes('plos_selected_renderer', verified['renderer'])
    if renderer.ARCH != 'glm':
        raise ValueError('Selected renderer architecture differs from GLM.')
    messages, tools = request.get('messages'), request.get('tools')
    if not isinstance(messages, list) or not isinstance(tools, list):
        raise ValueError('Complete ordered messages and native tools are required.')
    original = copy.deepcopy((messages, tools))
    prompt = renderer.render_chat(messages, enable_thinking=request['enable_thinking'],
                                  reasoning_effort=request.get('reasoning_effort'),
                                  tools=tools or None, tool_choice='auto' if tools else None)
    if original != (messages, tools) or not isinstance(prompt, str):
        raise ValueError('Renderer changed input or returned an invalid prompt.')
    encoded = prompt.encode('utf-8')
    tokens = len(tokenizer.encode(prompt, add_special_tokens=False).ids)
    return {'tokens': tokens, 'prompt_sha256': hashlib.sha256(encoded).hexdigest(),
            'prompt_base64': base64.b64encode(encoded).decode('ascii'),
            'asset_sha256': {name: value['sha256'] for name, value in assets.items()}}


def main():
    raw = sys.stdin.buffer.read()
    try:
        # Third-party imports/renderers must not put prompt text or diagnostics
        # on the protocol streams. Errors below intentionally omit their text.
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            request = json.loads(raw)
            result = count_prompt(request)
        result['request_sha256'] = hashlib.sha256(raw).hexdigest()
        sys.stdout.write(json.dumps(result, ensure_ascii=False, separators=(',', ':')) + '\n')
    except Exception:
        sys.stdout.write('{"error":"Selected-model prompt counting failed; no inference was performed."}\n')
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
