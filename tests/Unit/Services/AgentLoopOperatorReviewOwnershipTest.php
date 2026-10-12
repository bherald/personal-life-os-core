<?php

namespace Tests\Unit\Services;

use App\Controllers\NotificationController;
use App\Services\AgentActionabilityGateService;
use App\Services\AgentAuditService;
use App\Services\AgentDSGovernanceService;
use App\Services\AgentLoopService;
use App\Services\AgentOutputQualityGateService;
use App\Services\ReviewTypeRegistryService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentLoopOperatorReviewOwnershipTest extends TestCase
{
    public static function operatorDefinitions(): array
    {
        return [
            'face identity handler' => ['global_face_identity', ['service_class' => 'App\\Services\\Review\\GlobalFaceIdentityReviewService', 'approve_method' => 'approve']],
            'genealogy evidence handler' => ['genealogy_evidence_asset_capture', ['service_class' => 'App\\Services\\Genealogy\\GenealogyEvidenceAssetCaptureDecisionService', 'approve_method' => 'approve']],
            'composition handler' => ['tool_composition', ['service_class' => 'App\\Services\\ToolCompositionService', 'approve_method' => 'approveComposition']],
            'model decision handler' => ['ai_model_update', ['service_class' => 'App\\Services\\Ops\\AiModelUpdateReviewDecisionService', 'approve_method' => 'approve']],
            'genealogy policy without handler' => ['genealogy_merge', ['actionability_state' => 'operator_actionable']],
            'decision SQL' => ['qualified_decision', ['approve_sql' => 'UPDATE agent_review_queue SET status = ? WHERE id = ?']],
            'identity action metadata' => ['qualified_identity', ['actions' => [['name' => 'link']]]],
            'inspection only' => ['genealogy_review_packet', ['ui_schema' => ['inspection_only' => true]]],
            'unknown policy state' => ['qualified_unknown_policy', ['actionability_state' => 'unreviewed_policy']],
            'null policy state' => ['qualified_missing_policy', ['actionability_state' => null]],
            'empty policy state' => ['qualified_empty_policy', ['actionability_state' => '']],
            'different source connection' => ['qualified_domain_queue', ['source_connection' => 'pgsql_rag']],
            'missing source connection' => ['qualified_missing_origin', ['source_connection' => null]],
        ];
    }

    #[DataProvider('operatorDefinitions')]
    public function test_operator_decisions_remain_pending_despite_confidence_routine_title_or_explicit_review_ready(string $type, array $definition): void
    {
        $this->submission($type, array_replace($this->informationalDefinition($type), $definition), 'pending');
    }

    public function test_unknown_review_type_remains_pending(): void
    {
        $this->submission('unregistered_identity_decision', null, 'pending');
    }

    public function test_incomplete_registry_metadata_remains_pending(): void
    {
        $this->submission('incomplete_definition', ['source_table' => 'agent_review_queue'], 'pending');
    }

    public function test_registered_informational_output_preserves_auto_approval(): void
    {
        $this->submission('qualified_status', $this->informationalDefinition('qualified_status'), 'approved');
    }

    public function test_agent_row_only_status_sql_keeps_informational_compatibility(): void
    {
        $this->submission('qualified_status', array_replace($this->informationalDefinition('qualified_status'), [
            'approve_sql' => "UPDATE agent_review_queue SET status = 'approved', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
            'reject_sql' => "UPDATE agent_review_queue SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
        ]), 'approved');
    }

    public function test_other_domain_source_cannot_borrow_agent_row_auto_approval(): void
    {
        $this->submission('qualified_status', array_replace($this->informationalDefinition('qualified_status'), ['source_table' => 'genealogy_persons']), 'pending');
    }

    public function test_other_domain_sql_cannot_borrow_agent_source_metadata(): void
    {
        $this->submission('qualified_status', array_replace($this->informationalDefinition('qualified_status'), ['approve_sql' => "UPDATE genealogy_persons SET status = 'approved' WHERE id = ?"]), 'pending');
    }

    public function test_gate_classified_routine_technical_history_keeps_existing_non_review_behavior(): void
    {
        $this->submission('alert', $this->informationalDefinition('alert'), 'approved', [], false, null, [
            'state' => 'observe_only', 'review_hub' => false, 'reasons' => ['routine_status'],
        ]);
    }

    public function test_unknown_domain_decision_with_routine_title_and_missing_registry_is_not_auto_approved(): void
    {
        $this->submission('unknown_domain_decision', null, 'pending', [], false, null, [
            'state' => 'observe_only', 'review_hub' => false, 'reasons' => ['routine_status'],
        ]);
    }

    public function test_unknown_domain_decision_with_routine_title_and_unavailable_registry_is_not_auto_approved(): void
    {
        $this->submission('unknown_domain_decision', null, 'pending', [], true, null, [
            'state' => 'observe_only', 'review_hub' => false, 'reasons' => ['routine_status'],
        ]);
    }

    public function test_caller_observe_only_state_cannot_hide_an_unknown_decision(): void
    {
        $this->submission('unregistered_identity_decision', null, 'pending', [], false, null, [
            'state' => 'observe_only', 'review_hub' => false, 'reasons' => ['explicit_actionability_state'],
        ]);
    }

    public function test_ds_operator_confirmation_requirement_remains_pending(): void
    {
        $this->submission('qualified_status', $this->informationalDefinition('qualified_status'), 'pending', ['requires_operator_confirmation_for_write' => true]);
    }

    public function test_registry_lookup_failure_remains_pending(): void
    {
        $this->submission('qualified_status', $this->informationalDefinition('qualified_status'), 'pending', [], true);
    }

    public function test_finding_type_alias_cannot_borrow_informational_auto_approval(): void
    {
        $this->submission('qualified_status', $this->informationalDefinition('qualified_status'), 'pending', [], false, 'global_face_identity');
    }

    public function test_pushover_acknowledgement_never_resolves_or_writes_operator_review(): void
    {
        $service = Mockery::mock(AgentLoopService::class)->makePartial();
        $service->shouldReceive('resolveReview')->never();
        DB::shouldReceive('select')->once()->andReturn([(object) [
            'id' => 42, 'token' => 'synthetic-review-token', 'details' => json_encode(['pushover_receipt' => 'synthetic-receipt']),
        ]]);
        DB::shouldReceive('update')->never();
        $notifier = Mockery::mock(NotificationController::class);
        $notifier->shouldReceive('checkPushoverReceipt')->once()->with('synthetic-receipt')
            ->andReturn(['success' => true, 'acknowledged' => true, 'expired' => false]);
        $notifier->shouldReceive('send')->never();
        $this->app->instance(NotificationController::class, $notifier);

        $this->assertSame(['checked' => 1, 'approved' => 0, 'acknowledged' => 1], $service->pollPushoverReceipts());
    }

    public function test_expired_receipt_keeps_existing_pending_metadata_cleanup_only(): void
    {
        $service = Mockery::mock(AgentLoopService::class)->makePartial();
        $service->shouldReceive('resolveReview')->never();
        DB::shouldReceive('select')->once()->andReturn([(object) [
            'id' => 42, 'token' => 'synthetic-review-token', 'details' => json_encode(['pushover_receipt' => 'synthetic-receipt']),
        ]]);
        DB::shouldReceive('update')->once()->withArgs(fn ($sql, $params) => str_contains($sql, "JSON_REMOVE(details, '$.pushover_receipt')") && $params === [42])->andReturn(1);
        $notifier = Mockery::mock(NotificationController::class);
        $notifier->shouldReceive('checkPushoverReceipt')->once()->andReturn(['success' => true, 'acknowledged' => false, 'expired' => true]);
        $this->app->instance(NotificationController::class, $notifier);

        $this->assertSame(['checked' => 1, 'approved' => 0, 'acknowledged' => 0], $service->pollPushoverReceipts());
    }

    private function informationalDefinition(string $name): array
    {
        return ['name' => $name, 'source_connection' => 'mysql', 'source_table' => 'agent_review_queue', 'service_class' => null,
            'approve_method' => null, 'reject_method' => null, 'approve_sql' => null, 'reject_sql' => null,
            'ui_schema' => [], 'actions' => [['name' => 'ignore']], 'actionability_state' => 'review_ready'];
    }

    private function submission(string $type, ?array $definition, string $expectedStatus, array $governance = [], bool $lookupFails = false, ?string $findingType = null, array $gateOverrides = []): void
    {
        $registry = Mockery::mock(ReviewTypeRegistryService::class);
        $registry->shouldReceive('isInspectionOnly')->andReturn(false);
        if ($lookupFails) {
            $registry->shouldReceive('getType')->andThrow(new \RuntimeException('Synthetic unavailable policy'));
        } else {
            $registry->shouldReceive('getType')->with($type)->andReturn($definition);
            if ($findingType !== null) {
                $registry->shouldReceive('getType')->with($findingType)->andReturn(array_replace($this->informationalDefinition($findingType), ['service_class' => 'SyntheticIdentityDecisionService']));
            }
        }
        $this->app->instance(ReviewTypeRegistryService::class, $registry);
        $this->app->instance(AgentActionabilityGateService::class, new class($gateOverrides) extends AgentActionabilityGateService
        {
            public function __construct(private array $overrides) {}

            public function classifyReviewSubmission(array $params, array $details): array
            {
                return array_replace(['state' => self::STATE_REVIEW_READY, 'review_hub' => true, 'pushover_allowed' => false], $this->overrides);
            }
        });
        $this->app->instance(AgentOutputQualityGateService::class, new class extends AgentOutputQualityGateService
        {
            public function enrichReviewDetails(array $params, array $details): array
            {
                return $details;
            }
        });
        $ds = Mockery::mock(AgentDSGovernanceService::class);
        $ds->shouldReceive('classifyReviewSubmission')->once()->andReturn($governance);
        $this->app->instance(AgentDSGovernanceService::class, $ds);
        DB::shouldReceive('selectOne')->once()->andReturn(null);
        DB::shouldReceive('insert')->once()->withArgs(fn ($sql, $params) => str_contains($sql, 'INSERT INTO agent_review_queue') && $params[8] === $expectedStatus)->andReturn(true);
        DB::shouldReceive('update')->never();
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('lastInsertId')->once()->andReturn('42');
        DB::shouldReceive('getPdo')->once()->andReturn($pdo);
        $audit = Mockery::mock(AgentAuditService::class);
        $audit->shouldReceive('recordReviewSubmission')->once();
        $this->app->instance(AgentAuditService::class, $audit);
        $notifier = Mockery::mock(NotificationController::class);
        $notifier->shouldReceive('send')->never();
        $this->app->instance(NotificationController::class, $notifier);

        $result = app(AgentLoopService::class)->submitForReview(['agent_id' => 'synthetic-review-producer',
            'review_type' => $type, 'finding_type' => $findingType, 'title' => 'Routine status: all clear',
            'summary' => 'A source-backed decision still requires the operator.', 'details' => [],
            'confidence' => 0.99, 'priority' => 1, 'notify' => false, 'actionability_state' => 'review_ready']);

        $this->assertSame($expectedStatus, $result['status']);
        $this->assertSame($expectedStatus === 'approved', $result['auto_approved']);
        $this->assertSame($expectedStatus === 'pending', $result['review_created']);
    }
}
