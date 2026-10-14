import assert from 'node:assert/strict';
import test from 'node:test';
import { buildGenerationCode, generate } from './client.js';
import { pickRegisteredRoute } from './model-selector.js';

const authority = { routability: 'allowed', gpuTarget: 'none', hostAffinity: 'fixture-host',
  compatRuntimeFamily: 'fixture', compatBackend: 'llama_cpp', compatStatus: 'authoritative' };
const instance = { instanceId: 'replacement-provider', baseUrl: 'http://registered.test:12345', priority: 1,
  config: { route_policy: { allowed_model_roles: ['standard'], require_explicit_instance: true } },
  models: { standard: 'registered-model', coding: 'unadmitted-model' }, defaultModel: 'registered-model', ...authority };
const candidate = { instanceId: instance.instanceId, baseUrl: instance.baseUrl, priority: 1, modelName: 'registered-model',
  profile: 'standard', status: 'vetted', capabilities: ['text'], useCases: [], qualityRating: 1, ...authority };
const context = { instances: [instance], profiles: new Map<string, string>(), candidates: [candidate] };

test('registered route retains instance and role and refuses missing, blocked and disallowed choices', () => {
  assert.deepEqual(pickRegisteredRoute('general', context), { instanceId: instance.instanceId, model: candidate.modelName, role: 'standard' });
  assert.equal(pickRegisteredRoute('code', context), null);
  assert.equal(pickRegisteredRoute('general', { ...context, candidates: [] }), null);
  assert.equal(pickRegisteredRoute('general', { ...context, instances: [{ ...instance, routability: 'blocked' }] }), null);
  assert.equal(pickRegisteredRoute('general', { ...context, candidates: [{ ...candidate, compatStatus: 'stale' }] }), null);
});

test('bridge preserves original input and requires exact registered selection without fallback', () => {
  const original = 'Critical beginning.\n' + '$(`danger`)\n'.repeat(6000) + 'Critical ending.\n';
  const route = { instanceId: instance.instanceId, model: candidate.modelName, role: 'standard' };
  const code = buildGenerationCode(route, original, { system: 'Preserve all citations.', num_predict: 128 });
  const encoded = code.match(/base64_decode\('([A-Za-z0-9+/=]+)'\)/)?.[1];
  assert.ok(encoded);
  const request = JSON.parse(Buffer.from(encoded, 'base64').toString());
  assert.equal(request.prompt, original);
  assert.equal(request.config.system_prompt, 'Preserve all citations.');
  assert.deepEqual(request.config.allowed_ollama_instance_ids, [route.instanceId]);
  assert.equal(request.config.ollama_required_no_fallback, true);
  assert.equal(request.config.disable_external_fallback, true);
  assert.ok(code.includes('AIService::class)->process'));
  assert.ok(!code.includes(original));
});

test('bridge accepts only complete success from the requested provider and model; failures are never retried', async () => {
  const route = { instanceId: instance.instanceId, model: candidate.modelName, role: 'standard' };
  const exact = { success: true, provider: route.instanceId, model: route.model, response: 'Complete response.\n' };
  const wrap = (result: unknown) => '[REMOTE] tinker (200 chars)\n\n' + JSON.stringify(result);
  assert.equal((await generate(route, 'fixture', undefined, async () => wrap(exact))).response, exact.response);
  for (const output of [wrap({ ...exact, provider: 'wrong-provider' }), wrap({ ...exact, model: 'wrong-model' }),
    wrap({ ...exact, success: false }), '[TIMEOUT] partial', wrap(exact) + '\n... [truncated at 50KB]']) {
    let calls = 0;
    await assert.rejects(generate(route, 'fixture', undefined, async () => { calls++; return output; }));
    assert.equal(calls, 1);
  }
});
