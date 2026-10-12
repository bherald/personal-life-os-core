<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('file_content_extraction_provenance')) {
            return;
        }

        Schema::create('file_content_extraction_provenance', function (Blueprint $table): void {
            $table->unsignedBigInteger('file_registry_id')->primary();
            $table->json('metadata');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_content_extraction_provenance');
    }
};
