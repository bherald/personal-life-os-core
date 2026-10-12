# How local models can serve an offline application across hosts

Offline inference does not require the application and every model to share
one machine. A private LAN can connect an application host to separately
qualified inference hosts. Model artifacts, licenses, binaries, and required
dependencies must be prepared before disconnected use. Network access between
hosts is distinct from Internet access; verify each engine's actual egress.

## Three different jobs

**Routing** selects a provider and exact target for a task. In PLOS, existing
provider/table records and role policy own that selection. The controller
coordinator chooses an eligible exact target and refuses an unapproved preferred
target. Its current host-controller policy is bound to one expected host identity;
the two-host sample illustrates provider distribution, not a claim that the
existing controller service automatically manages a controller fleet.

**Host lifecycle** manages residency and admission on one host. The native
controller delegates to its guarded lifecycle adapter: obtain custody, inspect
the exact runtime and resources, start/load only an approved target, verify
readiness, retain heartbeat ownership, then release owned resources. The reviewed
contract admits serial execution and rejects concurrent inference and compatible
residency. A second process listening on a port is not authorization to use it.

**Inference transport** sends the request through the existing admitted engine
interface. The controller is not a replacement OpenAI/Ollama model API. A
controller readiness/status response is control-plane evidence and does not
authorize dispatch or certify that an engine can answer the requested modality.

```mermaid
sequenceDiagram
    participant App as Application / registered route
    participant Policy as Table-owned role and target policy
    participant Ctrl as Qualified host controller
    participant Engine as Local engine on selected host
    App->>Policy: Resolve exact approved provider and target
    Policy-->>App: Host/target/capability and custody requirements
    App->>Ctrl: Plan / exact admission and ownership checks
    Ctrl-->>App: Admission result or refusal
    App->>Engine: Dispatch through existing admitted interface
    App->>Ctrl: Heartbeat / release exact owned work
    Note over App,Engine: Conceptual sequence; disabled examples send no requests
```

## A fictional two-host topology

`example-host-a` owns a serial group containing a chat target and an embedding
target. `example-host-b` owns a separate serial group with equivalent example
targets. Two disabled provider routes illustrate sending standard text work to
host A and embedding work to host B. A serial group on A does not create a
global lock on B, but independent groups alone do not authorize concurrent jobs:
each provider, slot, resource budget, host capability and controller lease needs
its own admission. The sample does not implement automatic load balancing,
failover, shared GPU memory, distributed training, or concurrent inference.

The native shared-host collision rule allows specific shared Ollama daemon
relationships only when exact task/port/engine identities agree. Distinct models
do not become safe to run together because they share that daemon. Other engine
adapters have their own rules; do not generalize a shared-port exception into
unrestricted multiplexing.

## What the existing PLOS code demonstrates

- `ModelHostControllerPolicyService` normalizes table-owned policy and projects
  explicit lifecycle parameters into reviewed host manifests. It rejects wrong
  host identity and unsupported execution/concurrency posture; projection does
  not silently replace missing explicit controls with defaults.
- `ModelHostControllerCoordinatorService` translates roles into exact eligible
  model/engine/resource groups. Disabled control leaves planning local; automatic
  activation requires both enabled policy and separate automatic activation
  permission, and operator-only targets remain gated.
- `ModelHostControllerClient` enforces the pinned transport identity and reads
  its bearer token from a private file. Transport configuration is distinct from
  activation policy. Token contents must stay outside cached configuration.
- The Windows controller validates the exact manifest, token boundary, request
  shape, target identity and dependent lifecycle script identity before routing
  guarded lifecycle operations. Its full installation also includes specialized
  adapters and qualification evidence that this public example does not export.
- `Read-PLOSNativeLaunch` checks the launch projection hash, executable/model
  hashes, optional runtime-file inventory, absolute Windows paths and the exact
  `--model` argument binding before its original caller can launch anything.

The bundled excerpts preserve those useful native checks. They do not supply
custody acquisition/recovery, database-backed policy projection, network binding,
the full lifecycle state machine, qualified scheduled tasks, model adapters,
or inference dispatch. Those remain integration prerequisites in the existing
PLOS framework, not gaps this demo fills with a second controller abstraction.

## Qualifying a real installation

Choose an integration owner and a bounded host/target scope. Verify engine and
model licensing, platform support, executable/model hashes, storage, RAM/VRAM,
commit/pagefile budget, process ownership, expected ports, tool/modality support,
and a rollback path. Generate manifests from the authoritative registered policy;
keep private filesystem bindings, identities, installed hashes and receipts
outside the public example. Never promote the `UNQUALIFIED_*` placeholders.

Keep controllers and engines on loopback behind an explicitly approved private
transport. Restrict the permitted application caller, secure the token file and
its ACL, and qualify any proxy/firewall changes separately. An API that responds
to health requests is not necessarily authenticated or admitted. Ollama binds
loopback by default; local-only mode is available via `OLLAMA_NO_CLOUD=1`, but
neither setting proves the whole application has no other network routes.
[Ollama local FAQ](https://docs.ollama.com/faq).

Run model-free contract checks on the actual Windows target first. Then review a
non-mutating plan and exact identity/resource results. Enable only the specifically
approved route and controller capability, with current operator authority. Start
the chosen target through the existing lifecycle path, verify its exact model,
admit one bounded workload, track heartbeat and release receipts, and verify
cleanup. Stop on unknown ownership, identity mismatch, unresolved prior dispatch,
resource refusal or missing release evidence. Health reads, passed tests and
prior approvals do not independently grant a new activation.

The public package makes no claim that those installation checks have passed on
your hardware. Its optional Windows synthetic self-test is unexecuted in this
release preparation; Linux tests cover the fictional JSON inspector and package
boundaries only.
