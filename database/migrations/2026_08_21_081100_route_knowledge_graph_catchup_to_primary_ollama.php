<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const JOB = 'knowledge_graph_catchup';

    private const OLD_COMMAND = 'rag:build-knowledge-graph --limit=125 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=85 --instance=secondary --model-role=standard';

    private const NEW_COMMAND = 'rag:build-knowledge-graph --limit=125 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=85 --instance=primary --model-role=standard';

    private const OLD_NOTES = 'TODO-001 KG catch-up lane: 2026-04-24 stability pass showed zero 24h/7d failures and about 1,500 KG docs/day. Raised catch-up cap 100 to 125 and kept budget below 90-minute scheduler timeout.';

    private const NEW_NOTES = '2026-08-21 routing repair: use always-on .252 primary Ollama. The secondary .87 Ollama endpoint is controller-managed and normally offline; direct scheduler pinning bypassed activation and produced failed attempts before fallback. Return this job to .87 only through a controller-owned batch inference boundary.';

    public function up(): void
    {
        $this->replace(self::OLD_COMMAND, self::OLD_NOTES, self::NEW_COMMAND, self::NEW_NOTES);
    }

    public function down(): void
    {
        $this->replace(self::NEW_COMMAND, self::NEW_NOTES, self::OLD_COMMAND, self::OLD_NOTES);
    }

    private function replace(string $expectedCommand, string $expectedNotes, string $command, string $notes): void
    {
        $row = DB::table('scheduled_jobs')->where('name', self::JOB)->first(['id', 'command', 'notes']);
        if ($row === null) {
            throw new \RuntimeException('Knowledge-graph catch-up routing repair requires its exact scheduled job.');
        }
        if ($row->command === $command && $row->notes === $notes) {
            return;
        }
        if ($row->command !== $expectedCommand || $row->notes !== $expectedNotes) {
            throw new \RuntimeException('Refusing to overwrite drifted knowledge-graph catch-up routing.');
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
            throw new \RuntimeException('Knowledge-graph catch-up routing changed during repair.');
        }
    }
};
