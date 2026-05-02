<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enhancement #36: Context Engineering
     *
     * Creates tables for prompt templates, few-shot examples, and usage logging
     * to support intelligent context management for AI interactions.
     */
    public function up(): void
    {
        // Prompt Templates Table
        DB::statement("
            CREATE TABLE IF NOT EXISTS prompt_templates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                template_key VARCHAR(100) NOT NULL COMMENT 'Unique identifier for template lookup',
                name VARCHAR(255) NOT NULL COMMENT 'Human-readable name',
                description TEXT NULL COMMENT 'Template purpose description',
                template_text TEXT NOT NULL COMMENT 'Template with {{variable}} placeholders',
                task_type VARCHAR(50) DEFAULT 'general' COMMENT 'Task category: general, research, coding, creative, etc.',
                variables JSON NULL COMMENT 'Expected variables with descriptions',
                few_shot_examples JSON NULL COMMENT 'Embedded examples for this template',
                version INT UNSIGNED DEFAULT 1 COMMENT 'Template version for history',
                is_active TINYINT(1) DEFAULT 1 COMMENT 'Active version flag',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_template_key (template_key),
                INDEX idx_task_type (task_type),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Few-Shot Examples Table
        DB::statement("
            CREATE TABLE IF NOT EXISTS few_shot_examples (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_type VARCHAR(50) NOT NULL COMMENT 'Task category this example is for',
                input_text TEXT NOT NULL COMMENT 'Example input/prompt',
                output_text TEXT NOT NULL COMMENT 'Expected/ideal output',
                quality_score TINYINT UNSIGNED DEFAULT 5 COMMENT 'Quality rating 1-10',
                source VARCHAR(100) NULL COMMENT 'Where this example came from',
                is_active TINYINT(1) DEFAULT 1 COMMENT 'Whether to include in selection',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_task_type (task_type),
                INDEX idx_quality (quality_score),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Context Usage Log Table (for analytics)
        DB::statement("
            CREATE TABLE IF NOT EXISTS context_usage_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_type VARCHAR(50) NOT NULL,
                system_tokens INT UNSIGNED DEFAULT 0,
                example_tokens INT UNSIGNED DEFAULT 0,
                context_tokens INT UNSIGNED DEFAULT 0,
                message_tokens INT UNSIGNED DEFAULT 0,
                total_tokens INT UNSIGNED DEFAULT 0,
                budget_limit INT UNSIGNED DEFAULT 0,
                compression_applied TINYINT(1) DEFAULT 0,
                success TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_task_type (task_type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default prompt templates
        $this->seedDefaultTemplates();
    }

    /**
     * Seed default prompt templates following Anthropic best practices
     */
    private function seedDefaultTemplates(): void
    {
        $templates = [
            [
                'template_key' => 'research_analysis',
                'name' => 'Research Analysis',
                'description' => 'Template for analyzing research topics with structured output',
                'task_type' => 'research',
                'template_text' => <<<'TEMPLATE'
You are a research analyst. Analyze the topic: {{topic}}

Focus areas:
{{#each focus_areas}}- {{this}}
{{/each}}

Requirements:
- Cite sources where possible
- Distinguish facts from interpretations
- Note any conflicting information
- Provide confidence levels for claims

Output format:
1. Summary (2-3 sentences)
2. Key findings (bullet points)
3. Sources used
4. Confidence assessment
TEMPLATE,
                'variables' => json_encode([
                    'topic' => 'The research topic to analyze',
                    'focus_areas' => 'Array of specific areas to focus on',
                ]),
            ],
            [
                'template_key' => 'code_review',
                'name' => 'Code Review',
                'description' => 'Template for AI-assisted code review',
                'task_type' => 'coding',
                'template_text' => <<<'TEMPLATE'
Review the following {{language}} code for:
- Correctness and potential bugs
- Security vulnerabilities
- Performance concerns
- Code style and readability
- Best practices adherence

Context: {{context}}

Code to review:
```{{language}}
{{code}}
```

Provide specific, actionable feedback with line references where applicable.
TEMPLATE,
                'variables' => json_encode([
                    'language' => 'Programming language',
                    'context' => 'What the code is supposed to do',
                    'code' => 'The code to review',
                ]),
            ],
            [
                'template_key' => 'summarization',
                'name' => 'Content Summarization',
                'description' => 'Template for summarizing documents and content',
                'task_type' => 'general',
                'template_text' => <<<'TEMPLATE'
Summarize the following content in {{style}} style.

Target length: {{length}}
Key aspects to cover: {{aspects}}

Content:
{{content}}

Output a clear, concise summary that captures the essential information.
TEMPLATE,
                'variables' => json_encode([
                    'style' => 'Summary style: concise, detailed, executive, technical',
                    'length' => 'Target length: brief, moderate, comprehensive',
                    'aspects' => 'Specific aspects to emphasize',
                    'content' => 'Content to summarize',
                ]),
            ],
            [
                'template_key' => 'classification',
                'name' => 'Content Classification',
                'description' => 'Template for classifying content into categories',
                'task_type' => 'general',
                'template_text' => <<<'TEMPLATE'
Classify the following content into one of these categories:
{{#each categories}}- {{this}}
{{/each}}

Content to classify:
{{content}}

Respond with:
1. Category: [selected category]
2. Confidence: [high/medium/low]
3. Reasoning: [brief explanation]
TEMPLATE,
                'variables' => json_encode([
                    'categories' => 'Array of possible categories',
                    'content' => 'Content to classify',
                ]),
            ],
            [
                'template_key' => 'factual_qa',
                'name' => 'Factual Q&A',
                'description' => 'Template for factual question answering with source citation',
                'task_type' => 'research',
                'template_text' => <<<'TEMPLATE'
Answer the following question using ONLY the provided context.

CRITICAL: If the answer is not clearly supported by the context, say "I cannot determine this from the provided information."

Question: {{question}}

Context:
{{context}}

Provide your answer with:
1. Direct answer (if determinable)
2. Supporting evidence from context
3. Confidence level
TEMPLATE,
                'variables' => json_encode([
                    'question' => 'The question to answer',
                    'context' => 'Context information to use for answering',
                ]),
            ],
            [
                'template_key' => 'email_draft',
                'name' => 'Email Draft',
                'description' => 'Template for drafting professional emails',
                'task_type' => 'creative',
                'template_text' => <<<'TEMPLATE'
Draft a {{tone}} email for the following purpose:
{{purpose}}

Recipient context: {{recipient}}
Key points to include:
{{#each points}}- {{this}}
{{/each}}

Additional instructions: {{instructions}}

Format as a complete email with subject line.
TEMPLATE,
                'variables' => json_encode([
                    'tone' => 'Tone: professional, friendly, formal, casual',
                    'purpose' => 'Purpose of the email',
                    'recipient' => 'Who the email is for',
                    'points' => 'Array of key points to cover',
                    'instructions' => 'Any additional requirements',
                ]),
            ],
            [
                'template_key' => 'data_extraction',
                'name' => 'Data Extraction',
                'description' => 'Template for extracting structured data from text',
                'task_type' => 'general',
                'template_text' => <<<'TEMPLATE'
Extract the following information from the text:

Fields to extract:
{{#each fields}}
- {{this.name}}: {{this.description}}
{{/each}}

Text:
{{text}}

Output as JSON with the specified fields. Use null for fields that cannot be determined.
TEMPLATE,
                'variables' => json_encode([
                    'fields' => 'Array of {name, description} objects for fields to extract',
                    'text' => 'Text to extract from',
                ]),
            ],
        ];

        foreach ($templates as $template) {
            DB::insert("
                INSERT INTO prompt_templates
                (template_key, name, description, task_type, template_text, variables, version, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())
            ", [
                $template['template_key'],
                $template['name'],
                $template['description'],
                $template['task_type'],
                $template['template_text'],
                $template['variables'],
            ]);
        }

        // Seed some default few-shot examples
        $examples = [
            [
                'task_type' => 'classification',
                'input_text' => 'The quarterly revenue increased by 15% compared to last year.',
                'output_text' => 'Category: Financial\nConfidence: high\nReasoning: Contains financial metrics (revenue, percentage growth) and time comparison.',
                'quality_score' => 9,
            ],
            [
                'task_type' => 'summarization',
                'input_text' => 'A long article about climate change impacts on agriculture...',
                'output_text' => 'Climate change is significantly affecting global agriculture through increased drought frequency, shifting growing seasons, and pest migration. Key impacts include 10-25% crop yield reductions in vulnerable regions.',
                'quality_score' => 8,
            ],
            [
                'task_type' => 'data_extraction',
                'input_text' => 'Contact John Smith at john.smith@example.com or call 555-123-4567.',
                'output_text' => '{"name": "John Smith", "email": "john.smith@example.com", "phone": "555-123-4567"}',
                'quality_score' => 10,
            ],
        ];

        foreach ($examples as $example) {
            DB::insert("
                INSERT INTO few_shot_examples
                (task_type, input_text, output_text, quality_score, source, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'seed', 1, NOW(), NOW())
            ", [
                $example['task_type'],
                $example['input_text'],
                $example['output_text'],
                $example['quality_score'],
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS context_usage_log');
        DB::statement('DROP TABLE IF EXISTS few_shot_examples');
        DB::statement('DROP TABLE IF EXISTS prompt_templates');
    }
};
