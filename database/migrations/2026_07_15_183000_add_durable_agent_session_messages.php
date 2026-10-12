<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_sessions') || Schema::hasTable('agent_session_messages')) {
            return;
        }

        Schema::create('agent_session_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_session_id')->constrained('agent_sessions')->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->string('role', 32);
            $table->mediumText('content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('token_estimate')->default(0);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agent_session_id', 'ordinal'], 'agent_session_messages_session_ordinal_unique');
            $table->index(['agent_session_id', 'occurred_at'], 'agent_session_messages_session_time_index');
            $table->index('occurred_at', 'agent_session_messages_time_index');
        });

        DB::table('agent_sessions')
            ->select('id', 'messages', 'message_count', 'last_activity_at', 'updated_at', 'created_at')
            ->orderBy('id')
            ->chunkById(500, function ($sessions): void {
                $rows = [];

                foreach ($sessions as $session) {
                    $messages = json_decode((string) ($session->messages ?? ''), true);
                    if (! is_array($messages) || $messages === []) {
                        continue;
                    }

                    $firstOrdinal = max(1, (int) $session->message_count - count($messages) + 1);
                    foreach (array_values($messages) as $index => $message) {
                        if (! is_array($message)) {
                            continue;
                        }

                        $content = (string) ($message['content'] ?? '');
                        $metadata = $message['metadata'] ?? null;
                        $occurredAt = $message['timestamp']
                            ?? $session->last_activity_at
                            ?? $session->updated_at
                            ?? $session->created_at
                            ?? now();
                        try {
                            $occurredAt = Carbon::parse($occurredAt)->toDateTimeString();
                        } catch (Throwable) {
                            $occurredAt = now()->toDateTimeString();
                        }

                        $rows[] = [
                            'agent_session_id' => (int) $session->id,
                            'ordinal' => $firstOrdinal + $index,
                            'role' => mb_substr((string) ($message['role'] ?? 'unknown'), 0, 32),
                            'content' => $content,
                            'metadata' => is_array($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
                            'token_estimate' => (int) (strlen($content) / 1.5),
                            'occurred_at' => $occurredAt,
                            'created_at' => now(),
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('agent_session_messages')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_session_messages');
    }
};
