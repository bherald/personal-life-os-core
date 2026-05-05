# Ollama Compatibility Notes

**Last Updated:** 2026-04-27

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
