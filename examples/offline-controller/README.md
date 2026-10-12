# Offline model-host controller — PLOS 2.1 research example

PLOS separates application routing from model-host lifecycle and inference.
An application can select an approved local provider on another machine while
that machine's controller guards model residency, admission, and release. This
package exposes useful native contracts and a runnable fictional two-host
example without publishing an operator's installed host configuration.

It is a research example included with PLOS 2.1, not a deployment-ready Windows
controller or an activated route. Every sample controller, route, and model
activation is disabled. No model weights or engines are bundled.

## Run without a model or network

```sh
python3 examples/offline-controller/inspect_example.py
python3 -m unittest discover -s examples/offline-controller/tests -v
```

Python 3.9 or newer; standard library only. The inspector reads fictional JSON
fixtures, verifies disabled posture and exact host/target references, and prints
the target-status fields shown in [status.example.json](status.example.json).
It does not contact hosts, select an operational route, launch processes, or
grant admission. Changing a sample to enabled is refused.

On a separately reviewed Windows test environment, the optional synthetic native
contract check is:

```powershell
powershell -NoProfile -File examples/offline-controller/contracts/self-test.ps1
```

That check supplies fictional task/listener observations to the extracted
functions. It performs no runtime enumeration or launches. Windows PowerShell
syntax and runtime execution were not verified on the Linux preparation host;
the command is a qualification step, not a claimed passed check.

## What is included

| Artifact | Purpose | Boundary |
| --- | --- | --- |
| `contracts/controller-contract.ps1` | 9 native PLOS functions: exact property schemas, target allowlisting, shared-engine collision checks, runtime-status rendering, hashes and bearer-token validation | Verbatim reviewed functions; no controller bind, Serve branch, activation, or release |
| `contracts/native-launch-contract.ps1` | 2 native PLOS functions: exact launch/executable/model/runtime-file hashes and safe argument construction | Reads and verifies a caller-supplied launch projection; no process-start branch |
| `example-host-a.json`, `example-host-b.json` | Illustrate existing host-manifest fields, serial custody, disabled staged targets and resource groups | Fictional paths, tasks, identities and capacity numbers; not installable manifests |
| `topology.example.json` | Two local provider/host mappings and disabled role routes | Illustrative topology contract, not a new PLOS runtime registry |
| `observations.example.json`, `status.example.json` | Missing-task/no-listener example and rendered target status | Synthetic fixtures, not live health or readiness receipts |
| `inspect_example.py`, `tests/` | Reproducible offline fixture inspection and behavior checks | No operational controller or inference transport |

Native status rendering supports explicit supplied observations. Without one,
`Get-PLOSRuntimeStatus` queries Windows scheduled tasks and TCP listeners, so
callers must retain the synthetic observation argument when reproducing this
example. Native token checks require a private `$TokenPath`, strict file ACLs,
and a correctly formed bearer token; no token contents are provided here.

The topology uses RFC 5737 documentation addresses `198.51.100.10`,
`198.51.100.11`, `192.0.2.20`, and `192.0.2.21`. They are fictional, are not
working LAN endpoints, and must not be treated as connection instructions.
`127.0.0.1` and `::1` retain their real loopback meaning.
[RFC 5737](https://www.rfc-editor.org/rfc/rfc5737).

Read [DISTRIBUTED-OFFLINE.md](DISTRIBUTED-OFFLINE.md) for the control flow, serial
custody, qualification gaps, and what a real deployment requires. Sources and
extraction details are in [NOTICE.md](NOTICE.md). MIT licensed.
