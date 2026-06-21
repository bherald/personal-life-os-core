<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Historical private identity quarantine was applied in the private
        // environment. Public-bound source must not embed private person names,
        // face labels, or operator-specific repair targets.
    }

    public function down(): void
    {
        // Operator-confirmed identity rejection. Do not restore automatically.
    }
};
