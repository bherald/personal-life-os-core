<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            Schema::create('bias_rating_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('alias')->unique();
                $table->string('canonical_source');
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['canonical_source', 'active']);
            });
        }

        DB::table('bias_rating_aliases')->updateOrInsert(
            ['alias' => 'bbci.co.uk'],
            [
                'canonical_source' => 'BBC News',
                'active' => true,
                'notes' => 'BBC RSS feed host emitted by feeds.bbci.co.uk after source-host normalization.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bias_rating_aliases');
    }
};
