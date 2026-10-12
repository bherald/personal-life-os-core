<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $inserted = DB::table('workflows')->insertOrIgnore(['name' => 'cyber_managed_model_suite', 'description' => 'Inactive model-led synthetic finding/fix/retest profile. Requires exact artifact, private connection binding, existing scoped CLI admission and verified lease cleanup. No automatic replay.', 'active' => false, 'error_handling' => 'stop']);
            if ($inserted === 1) {
                $id = DB::table('workflows')->where('name', 'cyber_managed_model_suite')->value('id');
                DB::table('workflow_nodes')->insert(['workflow_id' => $id, 'node_type' => 'Cyber\\ManagedModelSuite', 'node_order' => 1, 'timeout_seconds' => 2700]);
            }
        });
    }

    public function down(): void
    {
        DB::table('workflows')->where('name', 'cyber_managed_model_suite')->update(['active' => false]);
    }
};
