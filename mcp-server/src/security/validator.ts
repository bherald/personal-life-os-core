import { z } from 'zod';

// Input validation schemas
export const schemas = {
  workflowList: z.object({
    active_only: z.boolean().optional(),
  }),

  workflowGet: z.object({
    name: z.string().min(1),
  }),

  workflowRun: z.object({
    name: z.string().min(1),
  }),

  executionList: z.object({
    workflow_name: z.string().optional(),
    limit: z.number().min(1).max(500).optional(),
  }),

  executionGet: z.object({
    run_id: z.number().positive(),
  }),

  artisanExecute: z.object({
    command: z.string().min(1),
    args: z.array(z.string()).optional(),
  }),

  nodeCreate: z.object({
    name: z.string().regex(/^[A-Z][a-zA-Z0-9]*$/, 'Must be PascalCase'),
  }),

  genealogyContext: z.object({
    tree_id: z.number().int().positive(),
    person_ids: z.array(z.number().int().positive()).max(100).optional(),
    family_ids: z.array(z.number().int().positive()).max(100).optional(),
    media_ids: z.array(z.number().int().positive()).max(100).optional(),
    source_ids: z.array(z.number().int().positive()).max(100).optional(),
    text_limit: z.number().int().min(0).max(5000).optional(),
  }),

  genealogyPersonGet: z.object({
    tree_id: z.number().int().positive(),
    person_id: z.number().int().positive().optional(),
    person_key: z.string().min(1).max(80).optional(),
    text_limit: z.number().int().min(0).max(5000).optional(),
  }).refine((args) => Boolean(args.person_id || args.person_key), {
    message: 'person_id or person_key is required',
  }),

  genealogyFamilyGet: z.object({
    tree_id: z.number().int().positive(),
    family_id: z.number().int().positive().optional(),
    family_key: z.string().min(1).max(80).optional(),
    text_limit: z.number().int().min(0).max(5000).optional(),
  }).refine((args) => Boolean(args.family_id || args.family_key), {
    message: 'family_id or family_key is required',
  }),

  genealogySourceGet: z.object({
    tree_id: z.number().int().positive(),
    source_id: z.number().int().positive().optional(),
    source_key: z.string().min(1).max(80).optional(),
    text_limit: z.number().int().min(0).max(5000).optional(),
  }).refine((args) => Boolean(args.source_id || args.source_key), {
    message: 'source_id or source_key is required',
  }),

  genealogySearch: z.object({
    tree_id: z.number().int().positive(),
    kind: z.enum(['person', 'family', 'source', 'media']),
    query: z.string().min(1).max(255),
    limit: z.number().int().min(1).max(100).optional(),
    text_limit: z.number().int().min(0).max(5000).optional(),
  }),

  genealogyTreeStats: z.object({
    tree_id: z.number().int().positive(),
  }),

  genealogyBatch: z.object({
    tree_id: z.number().int().positive(),
    dry_run: z.boolean().optional(),
    confirm: z.boolean().optional(),
    reason: z.string().min(8).max(500),
    sources: z.array(z.object({
      id: z.number().int().positive().optional(),
      key: z.string().min(1).max(80).optional(),
      title: z.string().min(1).max(500).optional(),
      author: z.string().max(500).optional(),
      publication: z.string().max(4000).optional(),
      repository: z.string().max(255).optional(),
      url: z.string().max(2000).optional(),
      notes: z.string().max(8000).optional(),
      source_quality: z.enum(['original', 'derivative', 'authored']).optional(),
      source_category: z.enum(['original', 'derivative', 'authored']).optional(),
      information_quality: z.enum(['primary', 'secondary', 'undetermined']).optional(),
    })).max(50).optional(),
    media_updates: z.array(z.object({
      id: z.number().int().positive(),
      media_type: z.enum(['photo', 'document', 'certificate', 'census', 'military', 'obituary', 'headstone', 'video', 'audio', 'other']).optional(),
      analysis_status: z.enum(['pending', 'processing', 'completed', 'failed', 'skipped']).optional(),
      enrichment_status: z.enum(['pending', 'processing', 'completed', 'failed', 'skipped']).optional(),
      enrichment_error: z.string().nullable().optional(),
      ai_description: z.string().max(8000).optional(),
      transcription_text: z.string().max(200000).optional(),
      transcription_source: z.enum(['manual', 'ocr', 'ai']).optional(),
    })).max(100).optional(),
    persons: z.array(z.object({
      id: z.number().int().positive().optional(),
      key: z.string().min(1).max(80).optional(),
      given_name: z.string().max(255).optional(),
      surname: z.string().max(255).optional(),
      sex: z.enum(['M', 'F', 'U']).optional(),
      nickname: z.string().max(255).optional(),
      birth_date: z.string().max(50).optional(),
      birth_place: z.string().max(1000).optional(),
      death_date: z.string().max(50).optional(),
      death_place: z.string().max(1000).optional(),
      notes_append: z.string().max(8000).optional(),
      living: z.boolean().optional(),
    })).max(200).optional(),
    families: z.array(z.object({
      id: z.number().int().positive().optional(),
      key: z.string().min(1).max(80).optional(),
      husband_id: z.number().int().positive().optional(),
      husband_key: z.string().min(1).max(80).optional(),
      wife_id: z.number().int().positive().optional(),
      wife_key: z.string().min(1).max(80).optional(),
      marriage_date: z.string().max(50).optional(),
      marriage_place: z.string().max(1000).optional(),
      notes: z.string().max(8000).optional(),
    })).max(200).optional(),
    children: z.array(z.object({
      family_id: z.number().int().positive().optional(),
      family_key: z.string().min(1).max(80).optional(),
      person_id: z.number().int().positive().optional(),
      person_key: z.string().min(1).max(80).optional(),
      father_relationship: z.enum(['Natural', 'Adopted', 'Step', 'Foster', 'Unknown']).optional(),
      mother_relationship: z.enum(['Natural', 'Adopted', 'Step', 'Foster', 'Unknown']).optional(),
      birth_order: z.number().int().positive().nullable().optional(),
    })).max(500).optional(),
    person_media: z.array(z.object({
      person_id: z.number().int().positive().optional(),
      person_key: z.string().min(1).max(80).optional(),
      media_id: z.number().int().positive(),
      notes: z.string().max(500).optional(),
      face_confirmed: z.boolean().optional(),
    })).max(500).optional(),
    family_media: z.array(z.object({
      family_id: z.number().int().positive().optional(),
      family_key: z.string().min(1).max(80).optional(),
      media_id: z.number().int().positive(),
    })).max(500).optional(),
    citations: z.array(z.object({
      source_id: z.number().int().positive().optional(),
      source_key: z.string().min(1).max(80).optional(),
      person_id: z.number().int().positive().optional(),
      person_key: z.string().min(1).max(80).optional(),
      family_id: z.number().int().positive().optional(),
      family_key: z.string().min(1).max(80).optional(),
      media_id: z.number().int().positive().optional(),
      fact_type: z.string().min(1).max(50),
      page: z.string().max(255).optional(),
      quality: z.number().int().min(0).max(100).optional(),
      evidence_type: z.enum(['direct', 'indirect', 'negative']).optional(),
      information_type: z.enum(['primary', 'secondary', 'indeterminate']).optional(),
      evidence_analysis: z.string().max(8000).optional(),
      text: z.string().max(8000).optional(),
    })).max(1000).optional(),
    rag_touch: z.object({
      person_ids: z.array(z.number().int().positive()).max(500).optional(),
      person_keys: z.array(z.string().min(1).max(80)).max(500).optional(),
      source_ids: z.array(z.number().int().positive()).max(500).optional(),
      source_keys: z.array(z.string().min(1).max(80)).max(500).optional(),
      media_ids: z.array(z.number().int().positive()).max(500).optional(),
    }).optional(),
  }),
};

export function validateInput<T>(schema: z.ZodSchema<T>, input: unknown): T {
  try {
    return schema.parse(input);
  } catch (error) {
    if (error instanceof z.ZodError) {
      const messages = error.errors.map(e => `${e.path.join('.')}: ${e.message}`);
      throw new Error(`Validation failed: ${messages.join(', ')}`);
    }
    throw error;
  }
}
