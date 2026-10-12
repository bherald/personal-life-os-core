# Core CLI helpers in PLOS v2.1

The core helper source includes the launcher, native-turn adapter, MCP operation wrapper, framework worker, registry sync and restricted exporter. Relative imports and subprocess entrypoints are packaged together. Optional Lemma, Hister and Gortex bridges are separate integrations and are not bundled by this helper extraction.

## Requirements

Use the application's reviewed Composer/npm dependencies, including its MCP SDK and `ws` versions. PHP needs the documented extensions; selected local state uses SQLite. Python registry/export helpers use the standard library. The optional exact selected-model prompt counter needs its admitted tokenizer library and hash-verified operator-supplied renderer/tokenizer assets; it does not download or install them.

Interactive CLI operation requires a separately built and verified native frontend/executor. The package includes the pinned build recipe, patch inputs and license notices, not a compiled binary or private build receipt. `frontend-build.mjs` checks source/patch/notice/binary identities. Preserve Apache-2.0 notices and the separately licensed Windows VT input source. Windows-specific runtime checks need the actual platform and tools; passing Linux tests is not Windows qualification.

Supply private state, host profiles, controller/worker bindings, allowed tool policies and credentials locally through existing registered configuration. No private manifest, token, saved session or registry snapshot is shipped. Missing configuration is a visible setup/admission failure, not permission to pick another host or model.

## Remote registry preparation

Remote `sync-registry.py` now requires an explicit approved `--project` path; it does not guess a deployment directory. Local source continues to use the current installation. The restricted `registry-export-gate.py` derives the current local account from the OS account database and strips inherited environment variables while retaining a fixed PHP executable. Its local approved exporter files remain owner-private and hash-bound. Copying the helper does not install an SSH key or authorize a remote export.

## What has been checked

Source syntax checks cover the selected PHP, Python and JavaScript helpers. Synthetic exporter tests verify exact bytes, rejected command/source changes and removal of inherited secrets. Registry-sync tests use mocked subprocesses and cover private snapshot publication, explicit transport configuration, failure/timeout cleanup and missing-project refusal before connection. One Windows publication case remains skipped on Linux.

The selected framework/compaction/custody tests are separate proof of behavior boundaries. Local PHP/Node subprocess fixtures are not production jobs. These checks do not establish model quality, configured host readiness, native frontend compatibility or successful external delivery on a particular installation.
