import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const upstream = '3d2ee51ca2d5db578f328aa75e20aa22c0197c9a';
export const noticeNames = ['CODEX-LICENSE', 'CODEX-NOTICE'];
export const nativeFeatures = ['plos_manual_compaction', 'plos_lossless_skills', 'plos_lossless_tool_output'];
const vtFiles = ['CROSSTERM-LICENSE', 'plos_vt_decoder.rs', 'plos_vt_parse.rs', 'plos_windows_vt.rs', 'provenance.json'];

export async function digest(filename) {
    const hash = createHash('sha256');
    for await (const chunk of createReadStream(filename)) hash.update(chunk);
    return hash.digest('hex');
}

export async function verifyFrontend(frontend) {
    const receipt = JSON.parse(await readFile(`${frontend}.json`, 'utf8'));
    if (receipt.schema !== 'plos.cli.frontend-build.v1'
        || receipt.upstream_commit !== upstream
        || receipt.feature !== 'codex-tui/plos'
        || receipt.embedded_execution_refused !== true
        || receipt.external_dependencies_unchanged !== true
        || (process.platform === 'win32' && typeof receipt.windows_vt_source_sha256 !== 'string')
        || receipt.patch_sha256 !== await digest(path.join(root, 'scripts/patches/codex/plos-frontend-0.153.4.patch'))
        || receipt.sha256 !== await digest(frontend)) {
        throw new Error('Frontend build identity does not match the reviewed PLOS build.');
    }
    for (const name of noticeNames) {
        if (await digest(path.join(path.dirname(frontend), name))
            !== await digest(path.join(root, 'scripts/patches/codex', name.replace('CODEX-', '')))) {
            throw new Error(`Frontend ${name} is missing or changed.`);
        }
    }
    if (receipt.windows_vt_source_sha256 !== undefined) {
        const folder = path.join(root, 'scripts/patches/codex/windows-vt');
        const identity = (await Promise.all(vtFiles.map(async name => `${name}:${await digest(path.join(folder, name))}\n`))).join('');
        if (receipt.windows_vt_source_sha256 !== createHash('sha256').update(identity).digest('hex')
            || await digest(path.join(path.dirname(frontend), 'CROSSTERM-LICENSE'))
                !== await digest(path.join(folder, 'CROSSTERM-LICENSE'))) {
            throw new Error('Native Windows VT input source or license does not match the reviewed candidate.');
        }
    }
    return receipt;
}

export async function verifyNativeExecutor(executable) {
    const receipt = JSON.parse(await readFile(`${executable}.json`, 'utf8'));
    if (receipt.schema !== 'plos.cli.native-build.v1'
        || receipt.upstream_commit !== upstream
        || receipt.feature !== 'plos_manual_compaction'
        || receipt.embedded_execution_refused !== false
        || receipt.external_dependencies_unchanged !== true
        || JSON.stringify(receipt.required_features) !== JSON.stringify(nativeFeatures)
        || receipt.patch_sha256 !== await digest(path.join(root, 'scripts/patches/codex/plos-native-executor-0.153.4.patch'))
        || receipt.sha256 !== await digest(executable)) {
        throw new Error('Native executor build identity does not match the reviewed PLOS build.');
    }
    for (const name of noticeNames) {
        if (await digest(path.join(path.dirname(executable), name))
            !== await digest(path.join(root, 'scripts/patches/codex', name.replace('CODEX-', '')))) {
            throw new Error(`Native executor ${name} is missing or changed.`);
        }
    }
    return receipt;
}
