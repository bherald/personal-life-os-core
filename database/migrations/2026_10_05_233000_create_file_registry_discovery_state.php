<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_registry_discovery_scopes', function (Blueprint $table) {
            $table->id();
            $table->char('scope_key', 64)->unique('fr_discovery_scope_key_unique');
            $table->text('scope_path');
            $table->text('connector_fingerprint');
            $table->text('qualification_fingerprint');
            $table->boolean('compute_hash');
            $table->uuid('epoch_uuid');
            $table->unsignedBigInteger('revision')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('discovery_complete')->default(false);
            $table->boolean('intake_complete')->default(false);
            foreach (['directory_records', 'files_discovered', 'intake_attempts', 'registered', 'updated', 'unchanged', 'duplicates', 'errors'] as $counter) {
                $table->unsignedBigInteger($counter)->default(0);
            }
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->string('last_reason', 100)->nullable();
            $table->timestamps(6);
        });

        Schema::create('file_registry_discovery_work', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_id')->constrained('file_registry_discovery_scopes');
            $table->uuid('epoch_uuid');
            $table->string('kind', 12);
            $table->uuid('first_epoch_uuid');
            $table->text('path');
            $table->char('path_hash', 64);
            $table->unsignedInteger('depth');
            $table->string('state', 20)->default('pending');
            $table->text('checkpoint');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('not_before', 6)->nullable();
            $table->dateTime('last_served_at', 6)->nullable();
            $table->unsignedBigInteger('last_served_revision')->nullable();
            $table->string('last_reason', 100)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->timestamps(6);
            $table->unique(['scope_id', 'epoch_uuid', 'kind', 'path_hash'], 'fr_discovery_work_path_unique');
            $table->index(['scope_id', 'kind', 'state', 'not_before', 'last_served_revision'], 'fr_discovery_work_due_index');
        });
    }

    public function down(): void
    {
        foreach (['file_registry_discovery_work', 'file_registry_discovery_scopes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Discovery custody is populated; archive it explicitly before removing its schema.');
            }
        }
        Schema::dropIfExists('file_registry_discovery_work');
        Schema::dropIfExists('file_registry_discovery_scopes');
    }
};
