<?php

namespace Tests\Unit\Services;

use App\Services\AgentRoutingShadowService;
use Tests\TestCase;

class AgentRoutingShadowServiceTest extends TestCase
{
    private AgentRoutingShadowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AgentRoutingShadowService;
    }

    public function test_rare_terms_and_field_weights_produce_deterministic_ranking(): void
    {
        $agents = [
            'review-helper' => $this->agent('Review Helper', ['review'], 'Review code and prose.'),
            'terraform-review' => $this->agent('Terraform Review', ['infrastructure'], 'Review Terraform infrastructure.'),
            'document-review' => $this->agent('Document Review', ['documents'], 'Review Word documents.'),
        ];

        $selection = $this->service->select('review terraform', $agents, null, 3);

        $this->assertSame('terraform-review', $selection['candidate_agent_ids'][0]);
        $this->assertSame(2, $selection['query_term_count']);
        $this->assertFalse($selection['raw_task_included']);
        $this->assertFalse($selection['changes_routing_authority']);
    }

    public function test_agent_identity_is_weighted_above_capability_and_description(): void
    {
        $agents = [
            'slides' => $this->agent('Slides', ['presentation'], 'Create presentations.'),
            'presentations' => $this->agent('Presentations', ['slides'], 'Create and edit slides.'),
        ];

        $selection = $this->service->select('slides', $agents, null, 2);

        $this->assertSame(['slides', 'presentations'], $selection['candidate_agent_ids']);
    }

    public function test_unmatched_query_returns_no_candidates_and_excludes_current_agent(): void
    {
        $agents = [
            'spreadsheet-agent' => $this->agent('Spreadsheet Agent', ['tabular_data'], 'Analyze tables.'),
        ];

        $unmatched = $this->service->select('render a video', $agents);
        $excluded = $this->service->select('tabular data', $agents, 'spreadsheet-agent');

        $this->assertSame([], $unmatched['candidate_agent_ids']);
        $this->assertSame([], $excluded['candidate_agent_ids']);
        $this->assertSame(0, $excluded['candidate_count']);
    }

    public function test_character_ngram_challenger_adds_distinct_morphology_and_typo_recall(): void
    {
        $morphologyAgents = [
            'presentations' => $this->agent('Presentations', [], 'Create visual decks.'),
            'spreadsheets' => $this->agent('Spreadsheets', [], 'Analyze tabular data.'),
        ];
        $typoAgents = [
            'postgresql' => $this->agent('PostgreSQL', [], 'Manage a relational database.'),
            'postscript' => $this->agent('PostScript', [], 'Render printable documents.'),
        ];

        $morphologyBaseline = $this->service->selectByMethod(
            AgentRoutingShadowService::METHOD_FIELDED_BM25,
            'presentation',
            $morphologyAgents
        );
        $morphologyChallenger = $this->service->selectByMethod(
            AgentRoutingShadowService::METHOD_CHARACTER_NGRAM,
            'presentation',
            $morphologyAgents
        );
        $typoBaseline = $this->service->selectByMethod(
            AgentRoutingShadowService::METHOD_FIELDED_BM25,
            'postgrez',
            $typoAgents
        );
        $typoChallenger = $this->service->selectByMethod(
            AgentRoutingShadowService::METHOD_CHARACTER_NGRAM,
            'postgrez',
            $typoAgents
        );

        $this->assertSame([], $morphologyBaseline['candidate_agent_ids']);
        $this->assertSame('presentations', $morphologyChallenger['candidate_agent_ids'][0]);
        $this->assertSame([], $typoBaseline['candidate_agent_ids']);
        $this->assertSame('postgresql', $typoChallenger['candidate_agent_ids'][0]);
    }

    public function test_character_ngram_challenger_matches_cjk_and_rejects_unrelated_text(): void
    {
        $agents = [
            'slides' => $this->agent('演示文稿', [], '创建幻灯片。'),
            'sheets' => $this->agent('电子表格', [], '分析表格数据。'),
        ];

        $cjk = $this->service->selectByMethod(
            AgentRoutingShadowService::METHOD_CHARACTER_NGRAM,
            '帮我制作演示文稿',
            $agents
        );
        $unrelated = $this->service->selectByMethod(
            AgentRoutingShadowService::METHOD_CHARACTER_NGRAM,
            'xyzzy',
            $agents
        );

        $this->assertSame(['slides'], $cjk['candidate_agent_ids']);
        $this->assertSame([], $unrelated['candidate_agent_ids']);
        $this->assertFalse($cjk['raw_task_included']);
        $this->assertFalse($cjk['changes_routing_authority']);
    }

    public function test_default_shadow_exposes_character_ngram_as_challenger_only(): void
    {
        $agents = [
            'presentations' => $this->agent('Presentations', [], 'Create visual decks.'),
        ];

        $selection = $this->service->select('presentation', $agents);
        $challenger = $selection['challengers'][AgentRoutingShadowService::METHOD_CHARACTER_NGRAM];

        $this->assertSame(AgentRoutingShadowService::METHOD_FIELDED_BM25, $selection['method']);
        $this->assertSame([], $selection['candidate_agent_ids']);
        $this->assertSame(['presentations'], $challenger['candidate_agent_ids']);
        $this->assertSame(AgentRoutingShadowService::METHOD_CHARACTER_NGRAM, $challenger['method']);
    }

    public function test_fielded_bm25_already_retains_specialists_for_reviewed_compound_query(): void
    {
        $agents = [
            'rust-format' => $this->agent('Rust Format', [], 'Format Rust source code.'),
            'rust-lint' => $this->agent('Rust Lint', [], 'Fix Rust source lint errors.'),
            'rust-review' => $this->agent('Rust Review', [], 'Review Rust source code.'),
            'ci-fix' => $this->agent('CI Fix', [], 'Diagnose failing GitHub Actions checks.'),
        ];

        $selection = $this->service->selectByMethod(
            AgentRoutingShadowService::METHOD_FIELDED_BM25,
            'format and review Rust source code, and then diagnose failing GitHub Actions checks',
            $agents,
            null,
            4
        );

        $this->assertContains('rust-format', array_slice($selection['candidate_agent_ids'], 0, 3));
        $this->assertContains('ci-fix', array_slice($selection['candidate_agent_ids'], 0, 3));
    }

    public function test_query_and_candidate_bounds_are_reported(): void
    {
        $agents = [];
        for ($index = 0; $index <= 1000; $index++) {
            $id = sprintf('agent-%04d', $index);
            $agents[$id] = $this->agent($id, ['match'], 'Bounded candidate.');
        }

        $selection = $this->service->select(str_repeat('match ', 5000), $agents, null, 100);

        $this->assertTrue($selection['query_truncated']);
        $this->assertTrue($selection['candidate_set_truncated']);
        $this->assertSame(1000, $selection['candidate_count']);
        $this->assertCount(50, $selection['candidate_agent_ids']);
        $this->assertTrue($selection['challengers'][AgentRoutingShadowService::METHOD_CHARACTER_NGRAM]['query_truncated']);
        $this->assertTrue($selection['challengers'][AgentRoutingShadowService::METHOD_CHARACTER_NGRAM]['candidate_set_truncated']);
    }

    /** @return array<string,mixed> */
    private function agent(string $name, array $capabilities, string $description): array
    {
        return [
            'name' => $name,
            'capabilities' => $capabilities,
            'description' => $description,
            'is_active' => true,
        ];
    }
}
