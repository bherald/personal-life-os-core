<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_registry_image_audit_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->char('binding_key', 64)->unique('fr_image_audit_binding_unique');
            $table->text('binding');
            $table->text('checkpoint');
            $table->unsignedBigInteger('revision')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('file_registry_image_audit_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('checkpoint_id');
            $table->string('source_kind', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('tree_id')->nullable();
            $table->text('source_snapshot');
            $table->char('observed_sha256', 64)->nullable();
            $table->text('source_fingerprint');
            $table->uuid('epoch_uuid');
            $table->dateTime('observed_at', 6);
            $table->timestamps(6);
            $table->unique(['checkpoint_id', 'source_kind', 'source_id'], 'fr_image_audit_observation_unique');
            $table->foreign('checkpoint_id', 'fr_image_audit_checkpoint_fk')
                ->references('id')->on('file_registry_image_audit_checkpoints')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['file_registry_image_audit_observations', 'file_registry_image_audit_checkpoints'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Retain populated image audit custody and inspection evidence during rollback.');
            }
        }

        Schema::dropIfExists('file_registry_image_audit_observations');
        Schema::dropIfExists('file_registry_image_audit_checkpoints');
    }
};
