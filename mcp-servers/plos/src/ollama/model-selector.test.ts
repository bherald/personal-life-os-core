import assert from 'node:assert/strict';
import test from 'node:test';
import type { OllamaModel } from './client.js';
import { normalizeUrl, pickModelFromAuthority } from './model-selector.js';

test('normalizeUrl strips trailing slash and preserves host', () => {
  assert.equal(normalizeUrl('http://127.0.0.2:11434/'), 'http://127.0.0.2:11434');
});

// 3a authority: every SelectorInstance/SelectorCandidate fixture now carries
// the six authority fields so the isAuthorityAllowed filter can enforce them.
const ALLOWED_INSTANCE_AUTHORITY = {
  routability: 'allowed' as const,
  gpuTarget: 'ada_12gb' as const,
  hostAffinity: 'local-secondary' as const,
  compatRuntimeFamily: 'ollama_0_18+' as const,
  compatBackend: 'llama_cpp' as const,
  compatStatus: 'authoritative' as const,
};

const ALLOWED_CANDIDATE_AUTHORITY = {
  routability: 'allowed' as const,
  gpuTarget: 'ada_12gb' as const,
  hostAffinity: 'local-secondary' as const,
  compatStatus: 'authoritative' as const,
};

test('pickModelFromAuthority prefers host-mapped role model when available', () => {
  const discovered: OllamaModel[] = [
    { name: 'qwen3:8b', size: 10, modified_at: '2026-04-15T00:00:00Z' },
    { name: 'hf.co/bartowski/Codestral-22B-v0.1-GGUF:Q3_K_M', size: 22, modified_at: '2026-04-15T00:00:00Z' },
  ];

  const context = {
    instances: [
      {
        instanceId: 'ollama_secondary',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        config: {},
        models: {
          standard: 'qwen3:8b',
          coding: 'hf.co/bartowski/Codestral-22B-v0.1-GGUF:Q3_K_M',
        },
        defaultModel: 'qwen3:8b',
        ...ALLOWED_INSTANCE_AUTHORITY,
      },
    ],
    profiles: new Map([
      ['default', 'qwen3:8b'],
      ['coding', 'codestral:22b'],
    ]),
    candidates: [
      {
        instanceId: 'ollama_secondary',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        modelName: 'hf.co/bartowski/Codestral-22B-v0.1-GGUF:Q3_K_M',
        profile: 'coding',
        status: 'vetted',
        capabilities: ['text', 'code'],
        useCases: ['code_generation'],
        qualityRating: 8,
        ...ALLOWED_CANDIDATE_AUTHORITY,
      },
    ],
  };

  assert.equal(
    pickModelFromAuthority('code', discovered, context, 'http://127.0.0.2:11434/'),
    'hf.co/bartowski/Codestral-22B-v0.1-GGUF:Q3_K_M'
  );
});

test('pickModelFromAuthority falls back to enabled profile model when role mapping is absent', () => {
  const discovered: OllamaModel[] = [
    { name: 'qwen3:8b', size: 10, modified_at: '2026-04-15T00:00:00Z' },
    { name: 'gemma3:4b', size: 4, modified_at: '2026-04-15T00:00:00Z' },
  ];

  const context = {
    instances: [
      {
        instanceId: 'ollama_secondary',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        config: {},
        models: {},
        defaultModel: null,
        ...ALLOWED_INSTANCE_AUTHORITY,
      },
    ],
    profiles: new Map([
      ['default', 'qwen3:8b'],
      ['fast', 'gemma3:4b'],
    ]),
    candidates: [],
  };

  assert.equal(
    pickModelFromAuthority('general', discovered, context, 'http://127.0.0.2:11434'),
    'qwen3:8b'
  );
});

test('pickModelFromAuthority ranks vetted inventory rows when profile row is unavailable locally', () => {
  const discovered: OllamaModel[] = [
    { name: 'openhermes:latest', size: 7, modified_at: '2026-04-15T00:00:00Z' },
    { name: 'nous-hermes2:34b', size: 34, modified_at: '2026-04-15T00:00:00Z' },
  ];

  const context = {
    instances: [
      {
        instanceId: 'ollama_secondary',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        config: {},
        models: {},
        defaultModel: null,
        ...ALLOWED_INSTANCE_AUTHORITY,
      },
    ],
    profiles: new Map([['creative', 'dolphin-llama3:8b']]),
    candidates: [
      {
        instanceId: 'ollama_secondary',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        modelName: 'openhermes:latest',
        profile: 'creative',
        status: 'vetted',
        capabilities: ['text', 'uncensored'],
        useCases: ['creative_writing'],
        qualityRating: 7,
        ...ALLOWED_CANDIDATE_AUTHORITY,
      },
      {
        instanceId: 'ollama_secondary',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        modelName: 'nous-hermes2:34b',
        profile: 'default',
        status: 'vetted',
        capabilities: ['text', 'uncensored'],
        useCases: ['creative_writing'],
        qualityRating: 9,
        ...ALLOWED_CANDIDATE_AUTHORITY,
      },
    ],
  };

  assert.equal(
    pickModelFromAuthority('general', discovered, context, 'http://127.0.0.2:11434'),
    'nous-hermes2:34b'
  );
});

test('pickModelFromAuthority refuses candidates with non-allowed routability (3a authority)', () => {
  const discovered: OllamaModel[] = [
    { name: 'blocked-model:7b', size: 7, modified_at: '2026-04-18T00:00:00Z' },
    { name: 'allowed-model:7b', size: 7, modified_at: '2026-04-18T00:00:00Z' },
  ];

  const context = {
    instances: [
      {
        instanceId: 'ollama_blocked',
        baseUrl: 'http://127.0.0.1:11434',
        priority: 5,
        config: {},
        models: {},
        defaultModel: null,
        ...ALLOWED_INSTANCE_AUTHORITY,
        routability: 'blocked' as const,
      },
      {
        instanceId: 'ollama_allowed',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        config: {},
        models: {},
        defaultModel: null,
        ...ALLOWED_INSTANCE_AUTHORITY,
      },
    ],
    profiles: new Map(),
    candidates: [
      {
        instanceId: 'ollama_blocked',
        baseUrl: 'http://127.0.0.1:11434',
        priority: 5,
        modelName: 'blocked-model:7b',
        profile: 'default',
        status: 'vetted',
        capabilities: ['text'],
        useCases: ['general_text'],
        qualityRating: 9,
        ...ALLOWED_CANDIDATE_AUTHORITY,
        routability: 'blocked' as const,
      },
      {
        instanceId: 'ollama_allowed',
        baseUrl: 'http://127.0.0.2:11434',
        priority: 10,
        modelName: 'allowed-model:7b',
        profile: 'default',
        status: 'testing',
        capabilities: ['text'],
        useCases: ['general_text'],
        qualityRating: 3,
        ...ALLOWED_CANDIDATE_AUTHORITY,
      },
    ],
  };

  assert.equal(
    pickModelFromAuthority('general', discovered, context, 'http://127.0.0.2:11434'),
    'allowed-model:7b'
  );
});

test('pickModelFromAuthority refuses candidates with stale compat_status (3a authority)', () => {
  const discovered: OllamaModel[] = [
    { name: 'stale-model:7b', size: 8, modified_at: '2026-04-18T00:00:00Z' },
    { name: 'fresh-model:7b', size: 7, modified_at: '2026-04-18T00:00:00Z' },
  ];

  const context = {
    instances: [
      {
        instanceId: 'ollama_stale',
        baseUrl: 'http://127.0.0.3:11434',
        priority: 5,
        config: {},
        models: {},
        defaultModel: null,
        ...ALLOWED_INSTANCE_AUTHORITY,
        compatStatus: 'stale' as const,
      },
      {
        instanceId: 'ollama_fresh',
        baseUrl: 'http://127.0.0.4:11434',
        priority: 10,
        config: {},
        models: {},
        defaultModel: null,
        ...ALLOWED_INSTANCE_AUTHORITY,
      },
    ],
    profiles: new Map(),
    candidates: [
      {
        instanceId: 'ollama_stale',
        baseUrl: 'http://127.0.0.3:11434',
        priority: 5,
        modelName: 'stale-model:7b',
        profile: 'default',
        status: 'vetted',
        capabilities: ['text'],
        useCases: ['general_text'],
        qualityRating: 9,
        ...ALLOWED_CANDIDATE_AUTHORITY,
        compatStatus: 'stale' as const,
      },
      {
        instanceId: 'ollama_fresh',
        baseUrl: 'http://127.0.0.4:11434',
        priority: 10,
        modelName: 'fresh-model:7b',
        profile: 'default',
        status: 'testing',
        capabilities: ['text'],
        useCases: ['general_text'],
        qualityRating: 3,
        ...ALLOWED_CANDIDATE_AUTHORITY,
      },
    ],
  };

  assert.equal(
    pickModelFromAuthority('general', discovered, context, 'http://127.0.0.4:11434'),
    'fresh-model:7b'
  );
});
