# License and extraction provenance

Copyright (c) 2026 PLOS Contributors. MIT licensed; see [LICENSE](LICENSE).

The bundled native functions are verbatim excerpts from PLOS source:

| Source | Included symbols |
| --- | --- |
| `scripts/plos-model-host-controller.ps1` | ConvertTo-PLOSJson, Assert-PLOSExactProperties, Assert-PLOSSharedHostTargetPair, Get-PLOSTarget, Get-PLOSRuntimeStatus, Get-PLOSStringSha256, Test-PLOSFixedTimeBytes, Assert-PLOSTokenFile, Test-PLOSToken |
| `scripts/plos-local-llm-run.ps1` | Read-PLOSNativeLaunch, ConvertTo-PLOSNativeArguments |

Extraction removes top-level installed host bindings and execution branches;
the selected function bodies are unchanged. No operator manifest, installed
artifact hash, local source receipt, credential, model weight, Windows identity,
deployment report or runtime data is included. Comments/license headers, the
Python inspector, Windows synthetic self-test, fictional JSON and documentation
were prepared for the PLOS 2.1 public example.

The full source controller is coupled to exact qualified lifecycle/adapters.
These excerpts are not a drop-in controller, and omission of those dependencies
does not waive their checks. The example status wrapper deliberately uses
`plos.offline_controller.example_status.v1`; it is not a valid operational
readiness or admission receipt. Its nested target fields illustrate the native
Get-PLOSRuntimeStatus structure exactly.

Microsoft Windows/PowerShell, Ollama, and inference engines retain their own
licenses. No third-party engine code, model binaries or weights are distributed.

Primary supporting references:

- [Microsoft Get-NetTCPConnection](https://learn.microsoft.com/en-us/powershell/module/nettcpip/get-nettcpconnection)
- [Ollama local FAQ](https://docs.ollama.com/faq)
- [RFC 5737 documentation addresses](https://www.rfc-editor.org/rfc/rfc5737)
