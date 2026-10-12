<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const JOB = 'knowledge_graph_catchup';

    private const PRIMARY_COMMAND = 'rag:build-knowledge-graph --limit=125 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=85 --instance=primary --model-role=standard';

    private const MANAGED_COMMAND = 'rag:build-knowledge-graph --limit=125 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=85 --instance=managed-secondary --model-role=standard';

    private const PRIMARY_NOTES = '2026-08-21 routing repair: use always-on .252 primary Ollama. The secondary .87 Ollama endpoint is controller-managed and normally offline; direct scheduler pinning bypassed activation and produced failed attempts before fallback. Return this job to .87 only through a controller-owned batch inference boundary.';

    private const MANAGED_NOTES = '2026-08-21 managed .87 route: process at most three documents under one exact MHC-06 lease for ollama_secondary qwen3:8b. PLOS starts loopback Ollama only for the batch, admits only .252 ingress, disables tools/fallback/cascade/cache, verifies exact provider receipts, and requires controller release plus ingress/runtime cleanup.';

    public function up(): void
    {
        $this->replace(self::PRIMARY_COMMAND, self::PRIMARY_NOTES, self::MANAGED_COMMAND, self::MANAGED_NOTES);
    }

    public function down(): void
    {
        $this->replace(self::MANAGED_COMMAND, self::MANAGED_NOTES, self::PRIMARY_COMMAND, self::PRIMARY_NOTES);
    }

    private function replace(string $expectedCommand, string $expectedNotes, string $command, string $notes): void
    {
        $row = DB::table('scheduled_jobs')->where('name', self::JOB)->first(['id', 'command', 'notes']);
        if ($row === null) {
            throw new RuntimeException('Managed knowledge-graph batch routing requires its exact scheduled job.');
        }
        if ($row->command === $command && $row->notes === $notes) {
            return;
        }
        if ($row->command !== $expectedCommand || $row->notes !== $expectedNotes) {
            throw new RuntimeException('Refusing to overwrite drifted knowledge-graph batch routing.');
        }

        $affected = DB::table('scheduled_jobs')
            ->where('id', $row->id)
            ->where('command', $expectedCommand)
            ->where('notes', $expectedNotes)
            ->update([
                'command' => $command,
                'notes' => $notes,
                'updated_at' => now(),
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('Knowledge-graph batch routing changed during promotion.');
        }
    }
};
