import { CONFIG } from '../config.js';
import { mysqlQuery } from '../db/mysql.js';
import { logger } from '../util/logger.js';
import { discoverModels, type OllamaModel } from './client.js';

export type ModelCapability = 'code' | 'sql' | 'general' | 'vision' | 'embedding';

interface ModelSelectorPreferences {
  profileNames: string[];
  roleNames: string[];
  capabilityHints: string[];
  useCaseHints: string[];
}

interface AuthorityInstanceRow {
  id: number;
  instance_id: string;
  base_url: string | null;
  priority: number;
  config: string | Record<string, unknown> | null;
  routability: string | null;
  gpu_target: string | null;
  host_affinity: string | null;
  compat_runtime_family: string | null;
  compat_backend: string | null;
  compat_status: string | null;
}

interface AuthorityProfileRow {
  profile_name: string;
  model_name: string;
}

interface AuthorityModelRow {
  instance_id: string;
  base_url: string | null;
  priority: number;
  model_name: string;
  profile: string | null;
  status: string;
  capabilities: string[] | string | null;
  use_cases: string[] | string | null;
  quality_rating: number | null;
  routability: string | null;
  gpu_target: string | null;
  host_affinity: string | null;
  compat_status: string | null;
}

interface SelectorInstance {
  instanceId: string;
  baseUrl: string | null;
  priority: number;
  config: Record<string, unknown>;
  models: Record<string, string>;
  defaultModel: string | null;
  routability: string | null;
  gpuTarget: string | null;
  hostAffinity: string | null;
  compatRuntimeFamily: string | null;
  compatBackend: string | null;
  compatStatus: string | null;
}

interface SelectorCandidate {
  instanceId: string;
  baseUrl: string | null;
  priority: number;
  modelName: string;
  profile: string | null;
  status: string;
  capabilities: string[];
  useCases: string[];
  qualityRating: number | null;
  routability: string | null;
  gpuTarget: string | null;
  hostAffinity: string | null;
  compatStatus: string | null;
}

interface ModelAuthorityContext {
  instances: SelectorInstance[];
  profiles: Map<string, string>;
  candidates: SelectorCandidate[];
}

interface AuthorityCacheEntry {
  context: ModelAuthorityContext;
  expiresAt: number;
}

const AUTHORITY_CACHE_TTL_MS = 60_000;

const PREFERENCES: Record<ModelCapability, ModelSelectorPreferences> = {
  general: {
    profileNames: ['default', 'standard', 'quality'],
    roleNames: ['standard'],
    capabilityHints: ['text'],
    useCaseHints: ['general_text', 'summarization', 'analysis'],
  },
  code: {
    profileNames: ['coding'],
    roleNames: ['coding'],
    capabilityHints: ['code', 'text'],
    useCaseHints: ['code_generation', 'code_review', 'debugging', 'maintenance'],
  },
  sql: {
    profileNames: ['coding'],
    roleNames: ['coding'],
    capabilityHints: ['code', 'sql', 'text'],
    useCaseHints: ['sql', 'code_generation', 'transforms'],
  },
  vision: {
    profileNames: ['vision'],
    roleNames: ['vision'],
    capabilityHints: ['vision'],
    useCaseHints: ['image_analysis', 'ocr_assist', 'visual_qa'],
  },
  embedding: {
    profileNames: ['embedding'],
    roleNames: ['embedding'],
    capabilityHints: ['embedding'],
    useCaseHints: ['embedding', 'rag', 'similarity'],
  },
};

let authorityCache: AuthorityCacheEntry | null = null;

function decodeJsonArray(value: string[] | string | null): string[] {
  if (Array.isArray(value)) {
    return value
      .map((item) => String(item).trim().toLowerCase())
      .filter(Boolean);
  }

  if (typeof value !== 'string' || value.trim() === '') {
    return [];
  }

  try {
    const decoded = JSON.parse(value);
    if (Array.isArray(decoded)) {
      return decoded
        .map((item) => String(item).trim().toLowerCase())
        .filter(Boolean);
    }
  } catch {
    // Ignore malformed JSON and fall back to string parsing below.
  }

  return value
    .split(',')
    .map((item) => item.trim().toLowerCase())
    .filter(Boolean);
}

function decodeConfig(value: string | Record<string, unknown> | null): Record<string, unknown> {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    return value;
  }

  if (typeof value !== 'string' || value.trim() === '') {
    return {};
  }

  try {
    const decoded = JSON.parse(value);
    if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
      return decoded as Record<string, unknown>;
    }
  } catch {
    // Ignore malformed JSON and return empty object below.
  }

  return {};
}

export function normalizeUrl(url: string | null | undefined): string {
  if (!url) return '';

  try {
    const parsed = new URL(url);
    return `${parsed.protocol}//${parsed.host}${parsed.pathname.replace(/\/+$/, '')}`.replace(/\/+$/, '');
  } catch {
    return url.trim().replace(/\/+$/, '');
  }
}

function canonicalProfile(value: string): string {
  const normalized = value.trim().toLowerCase();
  if (normalized === 'standard') return 'default';
  return normalized;
}

function statusScore(status: string): number {
  switch ((status || 'discovered').toLowerCase()) {
    case 'vetted':
      return 120;
    case 'testing':
      return 60;
    case 'discovered':
      return 20;
    default:
      return 0;
  }
}

function getMappedModels(instance: SelectorInstance, prefs: ModelSelectorPreferences): string[] {
  const mapped = new Set<string>();

  for (const roleName of prefs.roleNames) {
    const candidate = instance.models[roleName];
    if (candidate) {
      mapped.add(candidate);
    }
  }

  if (prefs.roleNames.includes('standard') && instance.defaultModel) {
    mapped.add(instance.defaultModel);
  }

  return [...mapped];
}

function hasAnyHint(values: string[], hints: string[]): boolean {
  return hints.some((hint) => values.includes(hint.toLowerCase()));
}

function scoreCandidate(
  candidate: SelectorCandidate,
  prefs: ModelSelectorPreferences,
  preferredInstanceId: string | null
): number {
  let score = statusScore(candidate.status) + (candidate.qualityRating ?? 0);

  if (preferredInstanceId && candidate.instanceId === preferredInstanceId) {
    score += 200;
  }

  if (candidate.profile && prefs.profileNames.includes(canonicalProfile(candidate.profile))) {
    score += 180;
  }

  if (hasAnyHint(candidate.capabilities, prefs.capabilityHints)) {
    score += 140;
  }

  if (hasAnyHint(candidate.useCases, prefs.useCaseHints)) {
    score += 100;
  }

  score += Math.max(0, 40 - candidate.priority);

  return score;
}

/**
 * 3a authority defense-in-depth: candidate/instance must have routability allowed
 * (or undeclared during bootstrap) and must not be marked compat_status='stale'.
 * The DB query already filters these, but the in-memory check prevents regressions
 * when tests construct context fixtures directly.
 */
function isAuthorityAllowed(row: {
  routability: string | null;
  compatStatus: string | null;
}): boolean {
  if (row.routability !== null && row.routability !== 'allowed') {
    return false;
  }
  if (row.compatStatus === 'stale') {
    return false;
  }
  return true;
}

export function pickModelFromAuthority(
  capability: ModelCapability,
  discoveredModels: OllamaModel[],
  context: ModelAuthorityContext,
  ollamaHost: string = CONFIG.ollama.host
): string | null {
  if (discoveredModels.length === 0) {
    return null;
  }

  const prefs = PREFERENCES[capability];
  const availableNames = new Set(discoveredModels.map((model) => model.name));
  const normalizedHost = normalizeUrl(ollamaHost);
  const routableInstances = context.instances.filter(isAuthorityAllowed);
  const preferredInstance = routableInstances.find((instance) => normalizeUrl(instance.baseUrl) === normalizedHost) ?? null;

  if (preferredInstance) {
    for (const modelName of getMappedModels(preferredInstance, prefs)) {
      if (availableNames.has(modelName)) {
        return modelName;
      }
    }
  }

  for (const profileName of prefs.profileNames) {
    const profileModel = context.profiles.get(profileName);
    if (profileModel && availableNames.has(profileModel)) {
      return profileModel;
    }
  }

  const rankedCandidates = context.candidates
    .filter((candidate) => availableNames.has(candidate.modelName))
    .filter(isAuthorityAllowed)
    .map((candidate) => ({
      candidate,
      score: scoreCandidate(candidate, prefs, preferredInstance?.instanceId ?? null),
    }))
    .sort((left, right) => {
      const byScore = right.score - left.score;
      if (byScore !== 0) return byScore;
      return left.candidate.modelName.localeCompare(right.candidate.modelName);
    });

  if (rankedCandidates.length > 0 && rankedCandidates[0].score > 0) {
    return rankedCandidates[0].candidate.modelName;
  }

  // 3a authority: even the "no-authority" fallback must not pick a model that
  // belongs exclusively to a blocked/stale instance. Prefer the largest discovered
  // model that has either no authority row or an allowed row.
  const blockedModelNames = new Set(
    context.candidates
      .filter((candidate) => !isAuthorityAllowed(candidate))
      .map((candidate) => candidate.modelName)
  );
  const allowedCandidateNames = new Set(
    context.candidates
      .filter(isAuthorityAllowed)
      .map((candidate) => candidate.modelName)
  );
  const authoritySafe = discoveredModels.filter((model) => {
    if (allowedCandidateNames.has(model.name)) return true;
    return !blockedModelNames.has(model.name);
  });
  const pool = authoritySafe.length > 0 ? authoritySafe : discoveredModels;
  const sized = [...pool].sort((left, right) => right.size - left.size);
  return sized[0]?.name ?? pool[0]?.name ?? null;
}

async function loadModelAuthorityContext(forceRefresh = false): Promise<ModelAuthorityContext> {
  const now = Date.now();
  if (!forceRefresh && authorityCache && authorityCache.expiresAt > now) {
    return authorityCache.context;
  }

  // 3a authority: MCP selection must consume the same authority surface Laravel
  // uses — routability, gpu_target, host_affinity, compat_runtime_family,
  // compat_backend, compat_status. Blocked and stale rows are excluded at the
  // query layer so downstream scoring/selection cannot accidentally resurrect
  // them.
  const [instanceRows, profileRows, modelRows] = await Promise.all([
    mysqlQuery(`
      SELECT id, instance_id, base_url, priority, config,
             routability, gpu_target, host_affinity,
             compat_runtime_family, compat_backend, compat_status
      FROM llm_instances
      WHERE instance_type = 'ollama'
        AND is_active = 1
        AND routability = 'allowed'
        AND (compat_status IS NULL OR compat_status <> 'stale')
      ORDER BY priority ASC, id ASC
    `) as Promise<AuthorityInstanceRow[]>,
    mysqlQuery(`
      SELECT profile_name, model_name
      FROM llm_model_profiles
      WHERE enabled = 1
    `) as Promise<AuthorityProfileRow[]>,
    mysqlQuery(`
      SELECT
        li.instance_id,
        li.base_url,
        li.priority,
        om.model_name,
        om.profile,
        om.status,
        om.capabilities,
        om.use_cases,
        om.quality_rating,
        li.routability,
        li.gpu_target,
        li.host_affinity,
        li.compat_status
      FROM ollama_models om
      JOIN llm_instances li ON li.id = om.instance_id
      WHERE li.instance_type = 'ollama'
        AND li.is_active = 1
        AND li.routability = 'allowed'
        AND (li.compat_status IS NULL OR li.compat_status <> 'stale')
        AND om.is_available = 1
      ORDER BY li.priority ASC, om.model_name ASC
    `) as Promise<AuthorityModelRow[]>,
  ]);

  const context: ModelAuthorityContext = {
    instances: instanceRows.map((row) => {
      const config = decodeConfig(row.config);
      const models = decodeConfig(typeof config.models === 'string'
        ? config.models
        : (config.models as Record<string, unknown> | null) ?? {});

      return {
        instanceId: row.instance_id,
        baseUrl: row.base_url,
        priority: row.priority,
        config,
        models: Object.fromEntries(
          Object.entries(models)
            .map(([key, value]) => [key, String(value)])
            .filter(([, value]) => value.trim() !== '')
        ),
        defaultModel: typeof config.default_model === 'string' ? config.default_model : null,
        routability: row.routability,
        gpuTarget: row.gpu_target,
        hostAffinity: row.host_affinity,
        compatRuntimeFamily: row.compat_runtime_family,
        compatBackend: row.compat_backend,
        compatStatus: row.compat_status,
      };
    }),
    profiles: new Map(
      profileRows
        .map((row) => [canonicalProfile(row.profile_name), row.model_name] as const)
        .filter(([, modelName]) => typeof modelName === 'string' && modelName.trim() !== '')
    ),
    candidates: modelRows.map((row) => ({
      instanceId: row.instance_id,
      baseUrl: row.base_url,
      priority: row.priority,
      modelName: row.model_name,
      profile: row.profile ? canonicalProfile(row.profile) : null,
      status: row.status,
      capabilities: decodeJsonArray(row.capabilities),
      useCases: decodeJsonArray(row.use_cases),
      qualityRating: row.quality_rating,
      routability: row.routability,
      gpuTarget: row.gpu_target,
      hostAffinity: row.host_affinity,
      compatStatus: row.compat_status,
    })),
  };

  authorityCache = {
    context,
    expiresAt: now + AUTHORITY_CACHE_TTL_MS,
  };

  return context;
}

export function clearModelAuthorityCache(): void {
  authorityCache = null;
}

/**
 * Select best model for a given capability.
 * Uses DB-backed model authority first, intersected with models actually
 * available on the configured Ollama host.
 */
export async function selectModel(capability: ModelCapability): Promise<string> {
  const models = await discoverModels();
  if (models.length === 0) throw new Error('No Ollama models available');

  try {
    const context = await loadModelAuthorityContext();
    const selected = pickModelFromAuthority(capability, models, context, CONFIG.ollama.host);

    if (selected) {
      logger.debug('Model selected from DB authority', {
        capability,
        model: selected,
        host: CONFIG.ollama.host,
      });
      return selected;
    }
  } catch (error) {
    logger.warn('DB-backed model selection failed, falling back to local inventory ordering', {
      capability,
      error: (error as Error).message,
    });
  }

  const fallback = [...models].sort((left, right) => right.size - left.size)[0]?.name ?? models[0].name;
  logger.warn('Model selector using generic fallback', {
    capability,
    model: fallback,
    host: CONFIG.ollama.host,
  });
  return fallback;
}
