<?php

namespace Tests\Feature\Services;

use App\DTOs\PolicyDecision;
use App\Services\OfflineAuditService;
use App\Services\OfflinePolicyService;
use App\Services\SystemConfigService;
use App\Services\UnifiedCli\CliFrameworkHttpScope;
use App\Services\UpstreamSourceDriftRuntimePolicyService;
use App\Services\UpstreamSourceDriftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class UpstreamScheduledPreparationTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $network = Mockery::mock(OfflinePolicyService::class);
        $network->shouldReceive('isInternetAccessEnabled')->andReturn(true);
        $network->shouldReceive('evaluateRemoteDomain')->andReturn(PolicyDecision::allow('Fixture public metadata', 'test'));
        $this->app->instance(OfflinePolicyService::class, $network);
        $this->previousConnection = DB::getDefaultConnection();
        config(['cache.default' => 'array', 'database.connections.upstream_preparation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('upstream_preparation_test');
        Schema::clearResolvedInstance('db.schema');
        Schema::connection('upstream_preparation_test')->create('system_configs', function (Blueprint $table): void {
            $table->id();
            foreach (['section', 'config_key', 'data_type'] as $field) {
                $table->string($field);
            }
            $table->text('config_value');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['section', 'config_key']);
        });
        Schema::connection('upstream_preparation_test')->create('scheduled_jobs', function (Blueprint $table): void {
            $table->id();
            foreach (['name', 'command', 'job_type', 'description', 'cron_expression', 'notification_mode', 'notes'] as $field) {
                $table->text($field)->nullable();
            }
            $table->boolean('enabled');
            $table->timestamps();
        });
        Schema::connection('upstream_preparation_test')->create('agent_review_queue', function (Blueprint $table): void {
            $table->id();
            $table->text('details')->nullable();
        });
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay());
        $this->value('upstream_source_drift_runtime_policy', ['schema' => UpstreamSourceDriftRuntimePolicyService::SCHEMA,
            'default_limit' => 20, 'max_limit' => 500, 'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 15, 'response_max_bytes' => 262144, 'max_request_timeout_seconds' => 60]);
        DB::table('scheduled_jobs')->insert(['id' => 182, 'name' => 'whiteroom_monitor_due_report',
            'command' => 'whiteroom:monitor --format=json --compact --due --limit=25', 'job_type' => 'command',
            'description' => 'Original monitor', 'cron_expression' => '42 7 * * *', 'enabled' => false,
            'notification_mode' => 'digest', 'notes' => json_encode(['operator_note' => 'preserve', 'safety' => ['read_only' => true]])]);
        $this->migration()->up();
        $policy = $this->readValue('upstream_source_drift_runtime_policy');
        $policy['preparation']['enabled'] = true; // Explicit activation only inside the isolated fixture.
        $policy['preparation']['priority_project_keys'] = ['colibri', 'kolibri-1-official-model'];
        unset($policy['preparation']['metadata_network_profile']);
        $this->value('upstream_source_drift_runtime_policy', $policy);
        $this->migration()->up();
        Http::preventStrayRequests();
        $this->fakeMetadata();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::disconnect('upstream_preparation_test');
        DB::setDefaultConnection($this->previousConnection);
        Schema::clearResolvedInstance('db.schema');
        parent::tearDown();
    }

    public function test_changed_sources_create_only_backend_work_with_exact_provenance(): void
    {
        $result = app(UpstreamSourceDriftService::class)->prepareScheduled([$this->source('one')], $this->policy());
        $this->assertSame(1, $result['new_backend_work']);
        $work = $this->work()[0];
        $this->assertSame('https://github.com/example/one', $work['source']['url']);
        $this->assertSame(str_repeat('b', 40), $work['observed_version']);
        $this->assertFalse($work['operator_actionable']);
        $this->assertFalse($work['pushover_allowed']);
        $this->assertSame('unverified', $work['lifecycle']['benefit']);
        $this->assertSame('unverified', $work['lifecycle']['license_qualification']);
        $this->assertSame('unverified', $work['lifecycle']['tests']);
        $this->assertFalse($work['provenance']['releases/latest']['release_revision_qualified']);
        $this->assertSame(['ref' => str_repeat('b', 40)], $work['provenance']['license']['query']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
        Http::assertSentCount(3);
    }

    public function test_fresh_current_sources_do_not_repeat_checks_or_create_work(): void
    {
        $source = $this->source('current', str_repeat('b', 40));
        $service = app(UpstreamSourceDriftService::class);
        $this->assertSame(0, $service->prepareScheduled([$source], $this->policy())['new_backend_work']);
        $next = $service->prepareScheduled([$source], $this->policy());
        $this->assertSame(0, $next['coverage']['checked']);
        $this->assertSame([], $this->work());
        Http::assertSentCount(1);
    }

    public function test_same_key_in_distinct_registries_and_urls_never_merges_and_reorder_is_stable(): void
    {
        $sources = [array_replace($this->source('one'), ['registry' => 'first']), array_replace($this->source('two'), ['key' => 'one', 'registry' => 'second'])];
        $service = app(UpstreamSourceDriftService::class);
        $first = $service->prepareScheduled($sources, $this->policy());
        $this->assertSame(2, $first['new_backend_work']);
        $this->assertCount(2, array_unique(array_column(array_column($this->work(), 'source'), 'url')));
        foreach (DB::table('system_configs')->where('config_key', 'like', 'upstream_source_check_%')->get() as $row) {
            $receipt = json_decode($row->config_value, true);
            $receipt['source'] = array_reverse($receipt['source'], true);
            DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($receipt)]);
        }
        $second = $service->prepareScheduled(array_reverse($sources), $this->policy());
        $this->assertSame(0, $second['coverage']['checked']);
        $this->assertCount(2, $this->work());
        Http::assertSentCount(6);
    }

    public function test_repeat_drift_preserves_deferred_owner_and_disposition(): void
    {
        $service = app(UpstreamSourceDriftService::class);
        $source = $this->source('deferred');
        $service->prepareScheduled([$source], $this->policy());
        $row = DB::table('system_configs')->where('config_key', 'like', 'upstream_source_work_%')->first();
        $work = json_decode($row->config_value, true);
        $work['owner'] = 'existing-owner';
        $work['status'] = 'deferred';
        $work['next_action'] = 'Resume after existing qualification';
        DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($work)]);
        $this->travel(2)->days();
        $again = $service->prepareScheduled([$source], $this->policy());
        $this->assertSame(0, $again['new_backend_work']);
        $this->assertSame($work, $this->work()[0]);
    }

    public function test_both_priority_watches_leave_cursor_capacity_and_all_other_sources_progress(): void
    {
        $this->fakeMetadata(['https://api.github.com/repos/example/colibri/commits*' => Http::response([], 500),
            'https://huggingface.co/api/models/Aleph-Alpha/Kolibri-1*' => Http::response([], 500)]);
        $sources = [$this->source('colibri'), $this->modelSource()];
        foreach (range(1, 5) as $number) {
            $sources[] = $this->source('other-'.$number, str_repeat('b', 40));
        }
        $service = app(UpstreamSourceDriftService::class);
        foreach (range(1, 5) as $tick) {
            $run = $service->prepareScheduled($sources, $this->policy());
            $this->assertSame(3, $run['coverage']['checked']);
            $this->assertSame(2, $run['coverage']['inconclusive']);
            $this->travel(2)->days();
        }
        $this->assertSame(7, DB::table('system_configs')->where('config_key', 'like', 'upstream_source_check_%')->count());
        $this->assertSame([], $this->work());
    }

    public function test_single_slot_policy_rotates_without_priority_starvation(): void
    {
        $sources = [$this->source('colibri', str_repeat('b', 40)), $this->modelSource(), $this->source('third', str_repeat('b', 40))];
        $policy = array_replace($this->policy(), ['limit' => 1]);
        $service = app(UpstreamSourceDriftService::class);
        foreach (range(1, 3) as $tick) {
            $service->prepareScheduled(array_reverse($sources), $policy);
            $this->travel(2)->days();
        }
        $this->assertSame(3, DB::table('system_configs')->where('config_key', 'like', 'upstream_source_check_%')->count());
    }

    public function test_rate_limit_holds_are_durable_and_retries_are_bounded(): void
    {
        $this->fakeMetadata(['https://api.github.com/repos/*/commits*' => Http::response([], 429)]);
        $service = app(UpstreamSourceDriftService::class);
        $sources = [$this->source('one'), $this->source('two')];
        $result = $service->prepareScheduled($sources, $this->policy());
        $this->assertSame(2, $result['coverage']['inconclusive']);
        $this->assertSame(['rate_limited', 'skipped_rate_limited'], array_column(array_column($result['checks'], 'result'), 'state'));
        $this->assertSame(0, $service->prepareScheduled($sources, $this->policy())['coverage']['checked']);
        Http::assertSentCount(1);
    }

    public function test_missing_or_malformed_state_stops_before_any_network_request(): void
    {
        $this->value(UpstreamSourceDriftService::PREPARATION_STATE_KEY, ['unknown' => 'retain']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cursor is malformed');
        try {
            app(UpstreamSourceDriftService::class)->prepareScheduled([$this->source('one')], $this->policy());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unsupported_sources_keep_explicit_coverage_holds(): void
    {
        $result = app(UpstreamSourceDriftService::class)->prepareScheduled([array_replace($this->modelSource(), ['source_url' => 'https://example.invalid/Aleph-Alpha/Kolibri-1'])], $this->policy());
        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, $result['coverage']['unsupported']);
        $state = $this->readValue(UpstreamSourceDriftService::PREPARATION_STATE_KEY);
        $this->assertSame('unsupported_official_metadata_probe', array_values($state['last_run']['unsupported_refs'])[0]['reason_code']);
        Http::assertNothingSent();
    }

    public function test_oversize_backend_receipt_rolls_back_without_truncating_or_losing_cursor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('storage capacity');
        try {
            app(UpstreamSourceDriftService::class)->prepareScheduled([array_replace($this->source('one'), ['next_action' => str_repeat('x', 70000)])], $this->policy());
        } finally {
            $this->assertSame([], $this->work());
            $this->assertNull($this->readValue(UpstreamSourceDriftService::PREPARATION_STATE_KEY)['cursor']);
            $this->assertSame(0, DB::table('system_configs')->where('config_key', 'like', 'upstream_source_check_%')->count());
        }
    }

    public function test_missing_recorded_revision_is_a_backend_hold_and_not_changed_work(): void
    {
        $result = app(UpstreamSourceDriftService::class)->prepareScheduled([array_replace($this->source('one'), ['source_version' => ''])], $this->policy());
        $this->assertSame(1, $result['coverage']['unsupported']);
        $this->assertSame(0, $result['new_backend_work']);
        $this->assertFalse($result['preparation_complete']);
        $this->assertSame([], $this->work());
        Http::assertNothingSent();
    }

    public function test_huggingface_checks_only_official_revision_and_card_metadata(): void
    {
        $result = app(UpstreamSourceDriftService::class)->inspect([$this->modelSource()], 5, 15, 262144)[0];
        $this->assertSame('current', $result['state']);
        $this->assertSame('apache-2.0', $result['model_card_license_claim']);
        $this->assertSame('unverified', $result['license_qualification']);
        $this->assertFalse($result['weights_downloaded']);
        $this->assertFalse($result['model_activation']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://huggingface.co/api/models/') && $request['expand'] === ['sha', 'lastModified', 'cardData']);
    }

    public function test_huggingface_wrong_identity_or_missing_revision_is_inconclusive(): void
    {
        foreach ([['sha' => 'not-a-sha'], ['sha' => str_repeat('a', 40), 'id' => 'different/model']] as $bad) {
            $this->fakeMetadata(['https://huggingface.co/api/models/*' => Http::response($bad)]);
            $result = app(UpstreamSourceDriftService::class)->inspect([$this->modelSource()], 5, 15, 262144)[0];
            $this->assertSame('invalid_response', $result['state']);
        }
    }

    public function test_registry_keeps_colibri_and_kolibri_as_distinct_supported_sources(): void
    {
        $sources = collect([
            array_replace($this->source('colibri'), ['repo' => 'https://github.com/JustVugg/colibri']),
            $this->modelSource(),
        ]);
        $engine = $sources->firstWhere('key', 'colibri');
        $model = $sources->firstWhere('key', 'kolibri-1-official-model');
        $this->assertSame('https://github.com/JustVugg/colibri', $engine['repo']);
        $this->assertSame('Aleph-Alpha/Kolibri-1', $model['identifier']);
        $this->assertTrue(app(UpstreamSourceDriftService::class)->supports($engine));
        $this->assertTrue(app(UpstreamSourceDriftService::class)->supports($model));
        $this->assertCount(2, $sources);
    }

    public function test_guarded_migration_preserves_schedule_flags_and_rollback_retains_receipts(): void
    {
        $job = DB::table('scheduled_jobs')->where('id', 182)->first();
        $this->assertSame('42 7 * * *', $job->cron_expression);
        $this->assertSame(0, $job->enabled);
        $this->assertSame('silent', $job->notification_mode);
        $this->assertSame('preserve', json_decode($job->notes, true)['operator_note']);
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame('whiteroom:monitor --format=json --compact --due --limit=25', DB::table('scheduled_jobs')->where('id', 182)->value('command'));
        $this->assertNotNull($this->readValue(UpstreamSourceDriftService::PREPARATION_STATE_KEY));
    }

    public function test_operator_schedule_and_policy_overrides_are_retained(): void
    {
        DB::table('scheduled_jobs')->where('id', 182)->update(['command' => 'operator:custom']);
        $policy = $this->readValue('upstream_source_drift_runtime_policy');
        $policy['preparation']['enabled'] = false;
        $policy['preparation']['limit'] = 1;
        $policy['response_max_bytes'] = 1024;
        $this->value('upstream_source_drift_runtime_policy', $policy);
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame('operator:custom', DB::table('scheduled_jobs')->where('id', 182)->value('command'));
        $this->assertFalse($this->readValue('upstream_source_drift_runtime_policy')['preparation']['enabled']);
        $this->assertSame(1, $this->readValue('upstream_source_drift_runtime_policy')['preparation']['limit']);
        $this->assertSame(1024, $this->readValue('upstream_source_drift_runtime_policy')['response_max_bytes']);
    }

    public function test_policy_missing_owner_or_excess_request_budget_holds_before_network(): void
    {
        foreach ([['owner' => ''], ['budget_seconds' => 10]] as $changes) {
            $policy = $this->readValue('upstream_source_drift_runtime_policy');
            $policy['preparation'] = array_replace($policy['preparation'], $changes);
            $this->value('upstream_source_drift_runtime_policy', $policy);
            $service = new UpstreamSourceDriftRuntimePolicyService(new SystemConfigService);
            try {
                $service->preparation();
                $this->fail('Expected invalid preparation policy to hold.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_rollback_does_not_overwrite_changed_operator_notes_or_model_schedule(): void
    {
        DB::table('scheduled_jobs')->insert(['id' => 190, 'name' => 'llm_portfolio_review', 'command' => 'ops:llm-portfolio-review',
            'cron_expression' => '14_day_policy_unchanged', 'enabled' => false, 'notes' => 'operator-owned']);
        DB::table('scheduled_jobs')->where('id', 182)->update(['notes' => json_encode(['operator_change' => 'retain'])]);
        $this->migration()->down();
        $this->assertSame(['operator_change' => 'retain'], json_decode(DB::table('scheduled_jobs')->where('id', 182)->value('notes'), true));
        $this->assertSame('whiteroom:monitor --format=json --compact --prepare', DB::table('scheduled_jobs')->where('id', 182)->value('command'));
        $this->assertSame('14_day_policy_unchanged', DB::table('scheduled_jobs')->where('id', 190)->value('cron_expression'));
        $this->assertSame('operator-owned', DB::table('scheduled_jobs')->where('id', 190)->value('notes'));
    }

    public function test_prepare_command_emits_backend_only_compact_receipt(): void
    {
        config(['whiteroom_projects.projects' => [$this->source('one')], 'whiteroom_projects.monitor_projects' => [], 'whiteroom_projects.research_sources' => []]);
        $this->assertSame(0, Artisan::call('whiteroom:monitor', ['--prepare' => true, '--format' => 'json', '--compact' => true]));
        $output = json_decode(Artisan::output(), true);
        $this->assertArrayNotHasKey('checks', $output);
        $this->assertFalse($output['operator_actionable']);
        $this->assertFalse($output['pushover_allowed']);
        $this->assertSame(1, $output['new_backend_work']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    public function test_declared_and_chunked_oversize_responses_hold_one_source_and_cursor_continues(): void
    {
        foreach ([['Content-Length' => '5000'], ['Transfer-Encoding' => 'chunked']] as $headers) {
            $this->fakeMetadata(['https://api.github.com/repos/example/oversize/commits*' => fn () => Http::response(str_repeat('x', 5000), 200, $headers)]);
            $sources = [$this->source('oversize'), $this->source('healthy', str_repeat('b', 40))];
            $run = app(UpstreamSourceDriftService::class)->prepareScheduled($sources, array_replace($this->policy(), ['response_max_bytes' => 1024]));
            $this->assertSame(2, $run['coverage']['checked']);
            $this->assertSame(1, $run['coverage']['inconclusive']);
            $this->assertSame(1, $run['coverage']['fresh_after']);
            $bad = collect($run['checks'])->first(fn ($check) => $check['source']['key'] === 'oversize');
            $this->assertContains($bad['result']['reason_code'], ['metadata_response_content_length_limit', 'metadata_response_body_byte_limit']);
            $this->assertArrayNotHasKey('response_sha256', $bad['result']);
            $this->travel(2)->days();
        }
    }

    public function test_oversize_auxiliary_metadata_retains_confirmed_drift_but_not_fresh_or_ready_evidence(): void
    {
        $this->fakeMetadata(['https://api.github.com/repos/example/oversize/license*' => fn () => Http::response(str_repeat('x', 5000))]);
        $run = app(UpstreamSourceDriftService::class)->prepareScheduled([$this->source('oversize'), $this->source('healthy', str_repeat('b', 40))],
            array_replace($this->policy(), ['response_max_bytes' => 1024]));
        $this->assertSame(2, $run['coverage']['checked']);
        $this->assertSame(1, $run['coverage']['inconclusive']);
        $this->assertSame(1, $run['coverage']['fresh_after']);
        $check = array_values(array_filter($run['checks'], fn ($check) => $check['source']['key'] === 'oversize'))[0];
        $this->assertTrue($check['revision_conclusive']);
        $this->assertFalse($check['conclusive']);
        $this->assertFalse($check['provenance_complete']);
        $this->assertSame('metadata_response_body_byte_limit', $check['provenance']['license']['reason_code']);
        $this->assertArrayNotHasKey('response_sha256', $check['provenance']['license']);
        $this->assertSame(1, $run['new_backend_work']);
        $this->assertSame('incomplete', $this->work()[0]['lifecycle']['metadata_provenance']);
        $this->assertSame('unverified', $this->work()[0]['lifecycle']['tests']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    public function test_accepted_response_with_oversized_selected_provenance_is_held_without_rolling_back_other_sources(): void
    {
        foreach (['github', 'hf'] as $provider) {
            $this->fakeMetadata($provider === 'github'
                ? ['https://api.github.com/repos/example/oversize/releases/latest' => fn () => Http::response(['tag_name' => str_repeat('x', 70000)])]
                : ['https://huggingface.co/api/models/*' => fn () => Http::response(['id' => 'Aleph-Alpha/Kolibri-1', 'sha' => str_repeat('d', 40),
                    'cardData' => ['license' => str_repeat('x', 70000)]])]);
            $source = $provider === 'github' ? $this->source('oversize') : $this->modelSource();
            $run = app(UpstreamSourceDriftService::class)->prepareScheduled([$source, $this->source('healthy-'.$provider, str_repeat('b', 40))], $this->policy());
            $this->assertSame(2, $run['coverage']['checked']);
            $this->assertSame(1, $run['coverage']['inconclusive']);
            $check = array_values(array_filter($run['checks'], fn ($check) => $check['source']['key'] === $source['key']))[0];
            $this->assertTrue($check['revision_conclusive']);
            $this->assertFalse($check['conclusive']);
            $this->assertFalse($check['provenance_complete']);
            $this->assertSame('metadata_receipt_storage_limit', $check['provenance']['reason_code']);
            $this->assertArrayNotHasKey('model_card_license_claim', $check['result']);
            $this->assertSame(1, $run['new_backend_work']);
            $this->assertNotNull($this->readValue(UpstreamSourceDriftService::PREPARATION_STATE_KEY)['cursor']);
            foreach ($this->work() as $work) {
                $this->assertSame('incomplete', $work['lifecycle']['metadata_provenance']);
                $this->assertStringNotContainsString(str_repeat('x', 100), json_encode($work));
            }
        }
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    public function test_transport_sink_and_progress_abort_before_unbounded_body_buffering(): void
    {
        foreach (['sink', 'progress'] as $guard) {
            $this->fakeMetadata(['https://api.github.com/repos/example/oversize/commits*' => function ($request, $options) use ($guard) {
                $this->assertTrue($options['stream']);
                $this->assertFalse($options['decode_content']);
                $this->assertFalse($options['allow_redirects']);
                if ($guard === 'sink') {
                    $options['sink']->write(str_repeat('x', 700));
                    $options['sink']->write(str_repeat('y', 700));
                } else {
                    $options['progress'](0, 2048, 0, 0);
                }
                $this->fail('Transport must abort before returning a response.');
            }]);
            $result = app(UpstreamSourceDriftService::class)->inspect([$this->source('oversize')], 5, 15, 1024)[0];
            $this->assertSame('unavailable', $result['state']);
            $this->assertSame('metadata_response_body_byte_limit', $result['reason_code']);
        }
    }

    public function test_missing_huggingface_identity_is_not_revision_evidence(): void
    {
        $this->fakeMetadata(['https://huggingface.co/api/models/*' => fn () => Http::response(['sha' => str_repeat('c', 40)])]);
        $result = app(UpstreamSourceDriftService::class)->inspect([$this->modelSource()], 5, 15, 262144)[0];
        $this->assertSame('invalid_response', $result['state']);
        $this->assertArrayNotHasKey('observed_version', $result);
    }

    public function test_internet_or_domain_policy_denial_preserves_inconclusive_receipts_without_requests(): void
    {
        foreach ([false, true] as $internet) {
            $policy = Mockery::mock(OfflinePolicyService::class);
            $policy->shouldReceive('isInternetAccessEnabled')->andReturn($internet);
            $policy->shouldReceive('evaluateRemoteDomain')->andReturn(PolicyDecision::deny('Fixture denial', 'test'));
            $this->app->instance(OfflinePolicyService::class, $policy);
            $result = app(UpstreamSourceDriftService::class)->prepareScheduled([$this->source('blocked')], $this->policy());
            $this->assertSame(1, $result['coverage']['inconclusive']);
            $this->assertSame('metadata_network_policy_refused', array_values($result['checks'])[0]['result']['reason_code']);
            $this->travel(2)->days();
        }
        Http::assertNothingSent();
    }

    public function test_existing_offline_workflow_http_scope_is_not_bypassed(): void
    {
        $scope = new CliFrameworkHttpScope;
        $result = $scope->run(['mode' => 'offline', 'host_addresses' => []], fn () => app(UpstreamSourceDriftService::class)->inspect([$this->source('blocked')], 5, 15, 262144)[0]);
        $this->assertSame('unavailable', $result['state']);
        Http::assertNothingSent();
    }

    public function test_whole_registered_scope_reaches_cadence_despite_daily_priority_failures(): void
    {
        $sources = [array_replace($this->source('colibri'), ['repo' => 'https://github.com/JustVugg/colibri']), $this->modelSource()];
        foreach (range(1, 209) as $number) {
            $sources[] = $this->source('fixture-repository-'.$number, str_repeat('b', 40));
        }
        foreach (range(1, 36) as $number) {
            $sources[] = array_replace($this->source('unsupported-fixture-'.$number), ['identifier_scheme' => 'unsupported_fixture']);
        }
        $this->assertCount(247, $sources);
        $this->assertCount(211, array_filter($sources, fn ($source) => app(UpstreamSourceDriftService::class)->supports($source)));
        $this->assertSame(10, $this->readValue('upstream_source_drift_runtime_policy')['preparation']['limit']);
        $this->fakeMetadata(['https://api.github.com/repos/JustVugg/colibri/commits*' => fn () => Http::response([], 500),
            'https://huggingface.co/api/models/Aleph-Alpha/Kolibri-1*' => fn () => Http::response([], 500),
            'https://export.arxiv.org/api/query*' => function ($request) {
                $feed = '<feed xmlns="http://www.w3.org/2005/Atom">';
                foreach (explode(',', $request['id_list']) as $id) {
                    $feed .= '<entry><id>https://arxiv.org/abs/'.$id.'v1</id><updated>2026-10-12T00:00:00Z</updated></entry>';
                }

                return Http::response($feed.'</feed>');
            }]);
        // Fixed daily cadence worst case: both selected priorities stay due/failing.
        foreach ($sources as &$source) {
            $source['monitor_cadence_days'] = in_array($source['key'], ['colibri', 'kolibri-1-official-model'], true) ? 1 : 30;
        }
        unset($source);
        $service = app(UpstreamSourceDriftService::class);
        foreach (range(1, 27) as $day) {
            $run = $service->prepareScheduled(array_reverse($sources), $this->policy(10));
            $this->assertLessThanOrEqual(10, $run['coverage']['checked']);
            $this->assertFalse($run['operator_actionable']);
            $this->assertFalse($run['preparation_complete']);
            $this->assertSame(36, $run['coverage']['unsupported']);
            if ($day === 1) {
                $this->assertSame(201, $run['coverage']['never_checked_after']);
                $this->assertSame(203, $run['coverage']['overdue_or_inconclusive_after']);
            }
            if ($day < 27) {
                $this->travel(1)->days();
            }
        }
        $this->assertSame(0, $run['coverage']['never_checked_after']);
        $this->assertSame(209, $run['coverage']['fresh_after']);
        $this->assertSame(2, $run['coverage']['overdue_or_inconclusive_after']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    private function source(string $key, ?string $version = null): array
    {
        return ['key' => $key, 'registry' => 'test_registry', 'repo' => 'https://github.com/example/'.$key,
            'identifier_scheme' => 'git_commit', 'identifier' => $version ?? str_repeat('a', 40),
            'source_version' => $version ?? str_repeat('a', 40), 'monitor_cadence_days' => 1,
            'next_action' => 'Preserve this exact source task.'];
    }

    private function modelSource(): array
    {
        return ['key' => 'kolibri-1-official-model', 'registry' => 'test_registry', 'source_url' => 'https://huggingface.co/Aleph-Alpha/Kolibri-1',
            'identifier_scheme' => 'hf_model_revision', 'identifier' => 'Aleph-Alpha/Kolibri-1',
            'source_version' => str_repeat('c', 40), 'monitor_cadence_days' => 1];
    }

    private function fakeMetadata(array $overrides = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($overrides + ['https://api.github.com/repos/*/commits*' => fn () => Http::response([['sha' => str_repeat('b', 40), 'commit' => ['committer' => ['date' => '2026-10-12T00:00:00Z']]]]),
            'https://api.github.com/repos/*/license*' => fn () => Http::response(['sha' => str_repeat('d', 40), 'license' => ['spdx_id' => 'MIT']]),
            'https://api.github.com/repos/*/releases/latest' => fn () => Http::response(['tag_name' => 'v1', 'target_commitish' => 'main']),
            'https://huggingface.co/api/models/*' => fn () => Http::response(['id' => 'Aleph-Alpha/Kolibri-1', 'sha' => str_repeat('c', 40), 'lastModified' => '2026-10-12T00:00:00Z', 'cardData' => ['license' => 'apache-2.0']])]);
    }

    private function value(string $key, array $value): void
    {
        DB::table('system_configs')->updateOrInsert(['section' => 'ops', 'config_key' => $key], ['config_value' => json_encode($value), 'data_type' => 'json']);
        Cache::forget('syscfg:ops.'.$key);
    }

    private function readValue(string $key): array
    {
        return json_decode(DB::table('system_configs')->where('section', 'ops')->where('config_key', $key)->value('config_value'), true);
    }

    public function test_explicit_metadata_scope_works_only_during_preparation_and_does_not_leak(): void
    {
        $settings = Mockery::mock(SystemConfigService::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => [
            'routing.active_profile' => 'resilient_local_first', 'routing.internet_access' => 'enabled',
            'routing.offline_mode' => 'disabled', 'cli.network' => 'online',
        ][$key] ?? $default);
        $audit = Mockery::mock(OfflineAuditService::class);
        $audit->shouldReceive('recordDecision')->zeroOrMoreTimes();
        $this->app->instance(OfflinePolicyService::class, new OfflinePolicyService($audit, $settings));
        $service = app(UpstreamSourceDriftService::class);
        $policy = $this->policy() + ['metadata_network_profile' => 'upstream_public_metadata'];
        $run = $service->prepareScheduled([$this->source('qualified')], $policy);
        $this->assertSame(1, $run['coverage']['checked']);
        $this->assertSame(0, $run['coverage']['inconclusive']);
        $this->assertSame(1, $run['new_backend_work']);
        $requests = count(Http::recorded());
        $ordinary = $service->inspect([$this->source('not-preparation')], 5, 15, 262144)[0];
        $this->assertSame('metadata_network_policy_refused', $ordinary['reason_code']);
        $this->assertCount($requests, Http::recorded());
        $repeat = $service->prepareScheduled([$this->source('qualified')], $policy);
        $this->assertSame(0, $repeat['coverage']['checked']);
        $this->assertCount($requests, Http::recorded());
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    public function test_profile_registration_preserves_job_cursor_receipts_retries_and_operator_override(): void
    {
        $migration = require database_path('migrations/2026_10_13_110000_scope_daily_upstream_official_metadata_get.php');
        $before = DB::table('system_configs')->orderBy('id')->get()->toArray();
        $migration->up(); // Disabled job must not silently acquire permission.
        $this->assertEquals($before, DB::table('system_configs')->orderBy('id')->get()->toArray());
        DB::table('scheduled_jobs')->where('id', 182)->update(['enabled' => true]);
        $this->value('upstream_source_check_retained', ['next_check_at' => '2026-10-14T05:58:53+00:00', 'status' => 'inconclusive']);
        $job = DB::table('scheduled_jobs')->where('id', 182)->first();
        $state = $this->readValue(UpstreamSourceDriftService::PREPARATION_STATE_KEY);
        $receipt = $this->readValue('upstream_source_check_retained');
        $original = $this->readValue('upstream_source_drift_runtime_policy');
        $migration->up();
        $post = $this->readValue('upstream_source_drift_runtime_policy');
        $this->assertSame('upstream_public_metadata', $post['preparation']['metadata_network_profile']);
        unset($post['preparation']['metadata_network_profile']);
        $this->assertSame($original, $post);
        $this->assertEquals($job, DB::table('scheduled_jobs')->where('id', 182)->first());
        $this->assertSame($state, $this->readValue(UpstreamSourceDriftService::PREPARATION_STATE_KEY));
        $this->assertSame($receipt, $this->readValue('upstream_source_check_retained'));
        $migration->down();
        $this->assertSame($original, $this->readValue('upstream_source_drift_runtime_policy'));
        $original['preparation']['metadata_network_profile'] = 'operator-held-profile';
        $this->value('upstream_source_drift_runtime_policy', $original);
        $migration->up();
        $migration->down();
        $this->assertSame($original, $this->readValue('upstream_source_drift_runtime_policy'));
    }

    public function test_scope_is_restored_after_preparation_failure(): void
    {
        $service = app(UpstreamSourceDriftService::class);
        $this->value(UpstreamSourceDriftService::PREPARATION_STATE_KEY, ['schema' => 'invalid']);
        try {
            $service->prepareScheduled([$this->source('failed')], $this->policy() + ['metadata_network_profile' => 'upstream_public_metadata']);
            $this->fail('Malformed cursor must hold.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cursor is malformed', $error->getMessage());
        }
        // The existing unscoped policy mock deliberately allows normal reads.
        $this->assertSame('drift', $service->inspect([$this->source('ordinary-after-failure')], 5, 15, 262144)[0]['state']);
    }

    private function policy(int $limit = 3): array
    {
        return array_replace($this->readValue('upstream_source_drift_runtime_policy')['preparation'], ['limit' => $limit]) + [
            'connect_timeout_seconds' => 5, 'request_timeout_seconds' => 15, 'response_max_bytes' => 262144];
    }

    private function work(): array
    {
        return DB::table('system_configs')->where('config_key', 'like', 'upstream_source_work_%')->get()->map(fn ($row) => json_decode($row->config_value, true))->all();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_12_210000_prepare_daily_upstream_metadata_followthrough.php');
    }
}
