<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_face_identities', function (Blueprint $table): void {
            $table->uuid('identity_uuid')->primary();
            $table->string('canonical_name');
            $table->string('status', 30)->default('operator_confirmed');
            $table->uuid('approval_receipt_id');
            $table->timestamps();
            // A name is a label, not a globally unique individual identifier.
            $table->index('canonical_name');
        });
        Schema::create('global_face_identity_aliases', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('identity_uuid');
            $table->string('alias');
            $table->char('alias_key', 64);
            $table->uuid('approval_receipt_id');
            $table->timestamps();
            $table->unique(['identity_uuid', 'alias_key'], 'global_face_alias_person_unique');
            $table->foreign('identity_uuid')->references('identity_uuid')->on('global_face_identities');
        });
        Schema::create('global_face_identity_genealogy_links', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('identity_uuid');
            $table->unsignedInteger('tree_id');
            $table->unsignedInteger('genealogy_person_id');
            $table->uuid('approval_receipt_id');
            $table->timestamps();
            $table->unique(['identity_uuid', 'tree_id', 'genealogy_person_id'], 'global_face_tree_person_unique');
            $table->unique(['tree_id', 'genealogy_person_id'], 'global_face_tree_person_identity_unique');
            $table->foreign('identity_uuid')->references('identity_uuid')->on('global_face_identities');
            $table->foreign('genealogy_person_id')->references('id')->on('genealogy_persons');
        });
        Schema::table('file_registry_faces', function (Blueprint $table): void {
            $table->uuid('global_identity_uuid')->nullable()->index();
            $table->uuid('global_identity_approval_receipt_id')->nullable();
            $table->foreign('global_identity_uuid')->references('identity_uuid')->on('global_face_identities');
        });
        // No source labels, legacy identities, aliases or confirmations are backfilled.
    }

    public function down(): void
    {
        if (DB::table('global_face_identities')->exists()) {
            throw new RuntimeException('Global identity decisions exist; rollback must preserve operator records.');
        }
        Schema::table('file_registry_faces', function (Blueprint $table): void {
            $table->dropForeign(['global_identity_uuid']);
            $table->dropIndex(['global_identity_uuid']);
            $table->dropColumn(['global_identity_uuid', 'global_identity_approval_receipt_id']);
        });
        Schema::drop('global_face_identity_genealogy_links');
        Schema::drop('global_face_identity_aliases');
        Schema::drop('global_face_identities');
    }
};
