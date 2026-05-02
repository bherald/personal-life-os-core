<?php

/**
 * Agent Tool Registry - Migration Seed & Fallback
 *
 * IMPORTANT: Tool definitions are now stored in the `agent_tool_registry` database table.
 * AgentToolRegistryService reads from DB first, falls back to this file only if the table
 * doesn't exist (pre-migration).
 *
 * To add new tools: INSERT into agent_tool_registry table. No code changes needed.
 * Agents can also propose tools via proposeTool() for human approval.
 *
 * This file is kept as the seed source for the migration
 * (2026_02_22_070000_create_agent_tool_registry_table.php).
 *
 * To re-seed from this file:
 *   php artisan tinker --execute="$tools = config('agent_tools'); foreach($tools as $name => $def) { DB::table('agent_tool_registry')->updateOrInsert(['name' => $name], ['service_class' => $def['service'], 'method' => $def['method'], 'description' => $def['description'], 'parameters' => json_encode($def['parameters'] ?? []), 'permissions' => json_encode($def['permissions'] ?? []), 'source' => 'config']); }"
 */

return [];
