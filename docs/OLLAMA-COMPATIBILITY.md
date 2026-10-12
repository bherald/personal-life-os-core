# Ollama Compatibility Notes

**Last Updated:** 2026-07-15

## Public Position

PLOS supports local AI through Ollama-compatible HTTP endpoints. The public
repository does not ship an Ollama binary, model weights, or host-specific GPU
builds. Operators choose and maintain their own runtime, then point PLOS at it
with environment variables and setup checks.

The durable rule is conservative: treat the local model runtime as an external
dependency with explicit compatibility proof before it becomes part of a
recommended install path.

## Runtime Policy

- Keep the public baseline runtime-agnostic: any service that implements the
  expected Ollama-compatible API can be configured.
- Do not require GPU support for a core install. GPU and transformer workloads
  belong to media/full profiles and should degrade visibly when unavailable.
- Evaluate binary upgrades on a sidecar endpoint before changing a production
  routing target.
- Track runtime version, model tags, memory fit, and observed failure modes in
  operator-local notes or release records.
- Prefer public setup checks over hidden assumptions. `setup:doctor` should
  warn when configured Ollama models are missing from `/api/tags`.

Operator-local routing note for the current PLOS deployment: prefer the
secondary Ollama host first for local LLM work. Treat the primary GPU-backed
Ollama path as the second-choice fallback route unless a specific diagnostic or
maintenance step intentionally pins otherwise.

## Model Adoption Rules

A model is not approved just because it appears in an Ollama library or runs
once. New candidates should be evaluated on:

- Local run path and offline behavior.
- Memory fit on the target host.
- JSON and tool-call compliance where structured output is required.
- Wrong-subject rejection and refusal quality for sensitive workflows.
- Domain usefulness, especially bounded genealogy extraction and evidence
  summarization.

Cloud-tagged or externally hosted model routes do not satisfy the local-only
operating goal by themselves. They may be useful fallbacks, but public docs
should label them as optional external providers.

## Structured Output Contract

PLOS uses Ollama's native JSON Schema `format` request field for pipeline tasks
whose `output_schema` identifier exists in `config/ollama_output_schemas.php`.
The identifier, not an arbitrary caller-provided schema, crosses the application
boundary. `OllamaStructuredOutputSchemaService` resolves it from the registry,
and `AIRouter` validates the returned JSON locally with Opis before reporting
success. Text outputs and unregistered identifiers do not claim enforcement.

The prompt still describes the expected fields and uses temperature zero for
deterministic extraction. Constrained syntax does not prove factual quality,
identity, source relevance, or permission to write. Existing evals,
deterministic cross-checks, human review, and Genea operator-only face/photo
decisions remain authoritative. Prompt compression remains enforced off.

The 2026-07-13 production canaries confirmed schema-constrained `/api/generate`
on the oldest deployed runtime, Ollama `0.17.7`, and on the active secondary
`0.18.3`; Jetson reported `0.30.8` but retained its embedding/health-only role.
The raw secondary `qwen3:8b` eval still scored 80% after inventing a current
document year and repeating spouse details with a misattributed birth year.
This is a model-quality failure, not a transport success claim. The deployed
Genea worker then passed the end-to-end boundary canary by source-grounding years
and deterministically removing duplicate or misattributed details. Local models
remain suitable for bounded extraction, classification, and review assistance;
coding, identity reasoning, proof conclusions, and canonical FT writes still
require stronger role evidence plus deterministic or operator review gates. No
runtime upgrade, model promotion, routing change, or new Jetson text role was
made by this integration.

Run the protected local-model pack with:

```bash
php artisan ollama:eval-run <model> --json
php artisan ollama:eval-run <model> --instance=ollama_jetson --record --json
php artisan ollama:eval-scorecard --json --compact
```

`ollama:eval-run` reports legacy `score`/new `score_pct` percentages alongside
unambiguous `score_ratio` values and a read-only
`plos.ollama_eval_assessment.v1` block. Promotion eligibility requires the
complete active baseline pack, every case passing, every critical regression
passing, and the normalized promotion threshold. A selected `--case` run can
diagnose one role but is always incomplete for promotion. JSON-shaped cases
accept one exact JSON document only; prose, markdown fences, or extracted JSON
fragments fail format compliance. Suite `plos.genea_local_model.v2` passes the
same registered schema through every admitted provider transport and validates
the final document again with Opis inside the evaluator. All nine active
structured cases must pass complete schema validation; wrong nested types,
enum drift, missing fields, or extra properties block promotion even when
top-level keys and semantic labels look acceptable. Schema compliance is not
factual proof, so fixture value checks, grounded fields,
wrong-subject/collateral decoys, and human review remain separate gates.

For reproducible host/model evidence, pin one table-owned Ollama instance with
`--instance` and opt in to `--record`. The durable
`plos.ollama_eval_ledger.v1` record fingerprints the active cases, fixtures,
thresholds, regressions, pipeline profiles, and output schemas. It stores content-free case
outcomes only. A retry that crosses actual providers/models, or a provider that
does not match the requested instance, produces `hold_mixed_target`; it cannot
be promotion evidence. Recording is evidence capture, not route mutation.

Compression-off does not remove lossless capability tests. Only cases marked
`requires_lossy_transform` are disabled; the lossless multipage packet baseline
continues to run. This evaluation never promotes a model, changes a route, or
writes genealogy data.

## GPU And Quantization Experiments

Acceleration work such as CUDA-specific builds, quantized KV-cache experiments,
or alternate `llama.cpp` forks should start as bench-only sidecars:

1. Run the experiment on a non-routable endpoint.
2. Record the runtime source, build flags, model tag, context length, memory
   footprint, throughput, and error cases.
3. Compare quality against the existing route on representative PLOS prompts.
4. Promote only after the route is stable, reproducible, and easy to roll back.

This keeps public PLOS from encoding one operator's GPU generation, LAN host,
or private benchmarking path as a project requirement.

## Practical Next Steps

1. Keep `.env.example` focused on endpoint URLs and model names, not private
   host paths.
2. Use `php artisan setup:doctor --profile=gpu` to surface configured local AI
   and media prerequisites.
3. Keep model/version scorecards in release notes or private operator records.
4. Add public compatibility notes only after an experiment is reproducible on a
   clean install path.
