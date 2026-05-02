<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemPromptController extends Controller
{
    /**
     * Get the system prompt for a specific conversation
     */
    public function getConversationPrompt($id)
    {
        // Get conversation using raw SQL
        $sql = "SELECT * FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1";
        $conversations = DB::select($sql, [$id]);
        $conversation = $conversations[0] ?? null;

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'system_prompt' => $conversation->system_prompt,
            'is_using_default' => is_null($conversation->system_prompt),
        ]);
    }

    /**
     * Update the system prompt for a specific conversation
     */
    public function updateConversationPrompt(Request $request, $id)
    {
        $validated = $request->validate([
            'system_prompt' => 'nullable|string|max:5000',
        ]);

        // Check if conversation exists using raw SQL
        $sql = "SELECT id FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1";
        $conversations = DB::select($sql, [$id]);

        if (empty($conversations)) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found'
            ], 404);
        }

        // Get the system_prompt value, handling both empty string and null
        $systemPrompt = $request->input('system_prompt', null);
        if ($systemPrompt === '') {
            $systemPrompt = null;
        }

        DB::update(
            "UPDATE conversations SET system_prompt = ?, updated_at = ? WHERE id = ?",
            [$systemPrompt, now(), $id]
        );

        return response()->json([
            'success' => true,
            'message' => 'System prompt updated',
            'system_prompt' => $systemPrompt,
        ]);
    }

    /**
     * Clear the system prompt for a specific conversation (revert to default)
     */
    public function clearConversationPrompt($id)
    {
        // Check if conversation exists using raw SQL
        $sql = "SELECT id FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1";
        $conversations = DB::select($sql, [$id]);

        if (empty($conversations)) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found'
            ], 404);
        }

        DB::update(
            "UPDATE conversations SET system_prompt = NULL, updated_at = ? WHERE id = ?",
            [now(), $id]
        );

        return response()->json([
            'success' => true,
            'message' => 'System prompt cleared, now using default',
        ]);
    }

    /**
     * Get the global default system prompt
     */
    public function getDefaultPrompt()
    {
        // Get config using raw SQL
        $sql = "SELECT * FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1";
        $configs = DB::select($sql, ['ai_settings', 'default_system_prompt']);
        $config = $configs[0] ?? null;

        $defaultValue = $config ? $config->config_value : null;
        $hardcodedFallback = 'You are a helpful AI assistant. Be concise and direct - answer the specific question first. No unnecessary recommendations unless asked.';

        return response()->json([
            'success' => true,
            'default_system_prompt' => $defaultValue,
            'hardcoded_fallback' => $hardcodedFallback,
            'description' => 'When a conversation has no custom system prompt, it will use the default. If default is not set, the hardcoded fallback is used.',
        ]);
    }

    /**
     * Update the global default system prompt
     */
    public function updateDefaultPrompt(Request $request)
    {
        $validated = $request->validate([
            'default_system_prompt' => 'required|string|max:5000',
        ]);

        // Check if config exists using raw SQL
        $sql = "SELECT COUNT(*) as count FROM system_configs WHERE section = ? AND config_key = ?";
        $exists = (DB::select($sql, ['ai_settings', 'default_system_prompt'])[0]->count ?? 0) > 0;

        if ($exists) {
            DB::update(
                "UPDATE system_configs SET config_value = ?, updated_at = ? WHERE section = ? AND config_key = ?",
                [$validated['default_system_prompt'], now(), 'ai_settings', 'default_system_prompt']
            );
        } else {
            DB::insert(
                "INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                ['ai_settings', 'default_system_prompt', $validated['default_system_prompt'], 'string', 'Default system prompt for AI conversations', now(), now()]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Default system prompt updated',
            'default_system_prompt' => $validated['default_system_prompt'],
        ]);
    }
}
