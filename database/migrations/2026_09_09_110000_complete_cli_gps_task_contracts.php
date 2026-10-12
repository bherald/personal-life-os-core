<?php

use App\Services\Genealogy\ResearchTaskService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const METHODS = ['create_research_task' => 'createTask', 'get_open_research_tasks' => 'getOpenTasks', 'assess_gps_compliance' => 'assessGPSCompliance'];

    private const TEXT = [
        'create_research_task' => [
            'description' => [
                'Create a research task for a person. Tasks are the tracking unit for GPS-documented research (GPS Element 1). Each search should be logged against a task. Creates only if no open task already exists for this person and question — always check get_open_research_tasks first to avoid duplicates.',
                'Create a GPS research task for a person. Duplicate protection, when enabled by operator policy, skips an open or in-progress task for the same person and byte-identical question. Always check get_open_research_tasks first.'],
            'returns_description' => [
                'Integer task_id if created, or null if a matching open task already exists.',
                'Integer GPS task ID if created; null when operator-configured duplicate protection skips an existing open or in-progress task. Existing task data is unchanged.'],
        ],
        'get_open_research_tasks' => [
            'description' => [
                'Get all open (unresolved) research tasks for a tree, optionally filtered by person. Returns tasks with their status, person, question, and logged search history. Call in the assess phase to find existing tasks before creating new ones — never duplicate tasks.',
                'Get open and in-progress GPS tasks for the selected tree, optionally filtered by person. Returns task fields, person names, log count and latest GPS score. Check this list before creating tasks.'],
            'returns_description' => [
                'Array of task objects: id, person_id, person_name, question, status, task_type, priority, searches_logged, created_at.',
                'Array of GPS task objects with task fields plus given_name, surname, log_count and gps_score. gps_score is 0-100 or null when no assessment exists.'],
        ],
        'assess_gps_compliance' => [
            'description' => [
                'Assess whether a research task meets Genealogical Proof Standard (GPS) compliance. Checks: exhaustive search (multiple repositories), complete citations, evidence correlation, conflict resolution, and written conclusion. Returns a compliance score and gap analysis with specific recommendations.',
                'Assess and save GPS compliance for the selected research task. Checks exhaustive search, citations, evidence analysis, conflict resolution and written conclusion. This operation writes an assessment.'],
            'returns_description' => [
                'Object: compliance_score (0.0-1.0), gps_elements[] (each with status: pass/fail/partial and recommendation), overall_assessment.',
                'Object with task_id, overall_score (0-100), gps_compliant, elements keyed 1-5 with scores and passed flags, and recommendations.'],
        ],
    ];

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        // Remove the unchanged default only. Accurate conditional descriptions
        // remain valid with protection disabled. Existing tasks are never removed.
        $this->change(false);
    }

    private function change(bool $install): void
    {
        DB::transaction(function () use ($install): void {
            foreach (self::METHODS as $name => $method) {
                $tool = DB::table('agent_tool_registry')->where('name', $name)
                    ->where('service_class', ResearchTaskService::class)->where('method', $method)->lockForUpdate()->first();
                if ($tool === null) {
                    continue;
                }
                $changes = [];
                if ($name === 'create_research_task') {
                    $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
                    // Only the aligned flat contract is changed. Preserve custom schemas.
                    if (! isset($parameters['type']) && ! array_is_list($parameters)) {
                        if ($install && ! array_key_exists('skip_existing_open', $parameters)) {
                            $parameters['skip_existing_open'] = ['type' => 'boolean', 'default' => true, 'x_context_only' => true];
                        } elseif (! $install) {
                            $parameter = $parameters['skip_existing_open'] ?? null;
                            if (is_array($parameter) && count($parameter) === 3 && ($parameter['type'] ?? null) === 'boolean'
                                && ($parameter['default'] ?? null) === true && ($parameter['x_context_only'] ?? null) === true) {
                                unset($parameters['skip_existing_open']);
                            }
                        }
                        if ($parameters !== json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR)) {
                            $changes['parameters'] = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                        }
                    }
                }
                if ($install) {
                    foreach (self::TEXT[$name] as $column => [$before, $after]) {
                        if (($tool->{$column} ?? null) === $before) {
                            $changes[$column] = $after;
                        }
                    }
                }
                if ($changes !== [] && DB::table('agent_tool_registry')->where('id', $tool->id)
                    ->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters])
                    ->update($changes + ['updated_at' => now()]) !== 1) {
                    throw new RuntimeException('GPS task contract changed during migration.');
                }
            }
        });
    }
};
