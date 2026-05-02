<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updatePriority('ollama_primary', 5);
        $this->updatePriority('ollama_secondary', 15);
    }

    public function down(): void
    {
        $this->updatePriority('ollama_primary', 11);
        $this->updatePriority('ollama_secondary', 5);
    }

    private function updatePriority(string $instanceId, int $priority): void
    {
        DB::update(
            'UPDATE llm_instances
             SET priority = ?, updated_at = NOW()
             WHERE instance_id = ?',
            [$priority, $instanceId]
        );
    }
};
