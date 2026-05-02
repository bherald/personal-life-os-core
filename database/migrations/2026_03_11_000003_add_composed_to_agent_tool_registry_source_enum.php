<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE agent_tool_registry MODIFY source ENUM('config','manual','agent_proposed','composed') DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE agent_tool_registry MODIFY source ENUM('config','manual','agent_proposed') DEFAULT 'manual'");
    }
};
