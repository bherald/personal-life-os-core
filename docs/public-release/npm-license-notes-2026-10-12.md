# npm dependency license notes

This source release refers to npm dependencies through package manifests and
lockfiles. Their individual licenses apply to the dependencies. The repository's
license does not replace those terms.

The platform packages for esbuild 0.28.1 and Rollup 4.62.2, and fsevents
2.3.2/2.3.3, have MIT licenses in their exact published npm registry metadata.
These optional platform entries can lack license fields in a local lockfile.
The release license snapshot records the reviewed upstream metadata.
Preserve upstream copyright and license notices when redistributing their
software. Rollup's complete upstream license file also includes notices for
its bundled dependencies.

For the dependencies used here, the selected options are MIT for JSZip 3.10.1,
Apache-2.0 for DOMPurify 3.4.16, and MIT for expand-template 2.0.3. Their original
alternative-license expressions remain recorded in the snapshot. Retain the
applicable upstream notices and license texts when distributing their code or
bundles.

`@modelcontextprotocol/server-everything@2026.7.4` refers to an upstream LICENSE
that its npm archive omits. Its exact published source revision is
`6dd0a683e198783e30feabf7abaf42f925bd18b1`. The full license at that revision
covers Apache-2.0 code and specification contributions, legacy MIT
contributions, and CC-BY-4.0 documentation. It is preserved separately with
its original transition statement; it is not treated as a choice between
licenses for the entire package.

The data license for caniuse-lite 1.0.30001812 is CC-BY-4.0; retain upstream
attribution and identify adaptations when redistributing its data. argparse
2.0.1 carries the full Python-2.0 license history, and d3-flextree 2.1.2 uses
WTFPL. The release snapshot keeps these watch items visible.

jackspeak 3.4.3, minimatch 10.2.5, minipass 7.1.3,
package-json-from-dist 1.0.1, path-scurry 1.11.1 and sax 1.6.0 use the
[Blue Oak Model License 1.0.0](https://blueoakcouncil.org/license/1.0.0).
Its notice condition permits providing that license text or link to recipients.

Primary source licenses:

- [esbuild 0.28.1](https://github.com/evanw/esbuild/blob/v0.28.1/LICENSE.md)
- [Rollup 4.62.2 and bundled notices](https://github.com/rollup/rollup/blob/v4.62.2/LICENSE.md)
- [fsevents 2.3.3](https://github.com/fsevents/fsevents/blob/v2.3.3/LICENSE)
- [JSZip 3.10.1](https://github.com/Stuk/jszip/blob/v3.10.1/LICENSE.markdown)
- [DOMPurify 3.4.16](https://github.com/cure53/DOMPurify/blob/3.4.16/LICENSE)
- [MCP reference server exact source license](https://github.com/modelcontextprotocol/servers/blob/6dd0a683e198783e30feabf7abaf42f925bd18b1/LICENSE)

This notice must match the final snapshot and the software actually included
in the release. It does not replace upstream license texts or bundled notices.
