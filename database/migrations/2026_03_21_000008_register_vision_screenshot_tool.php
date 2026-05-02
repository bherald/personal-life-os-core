<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N116: Register vision_screenshot as an agent tool
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT IGNORE INTO agent_tool_registry
                (name, description, service_class, method, category, risk_level, parameters, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                'vision_screenshot',
                'Screenshot a URL via Puppeteer and extract text/data using vision AI (LLaVA/Claude). Generic tool for scraping blocked sites, reading documents, or capturing visual data.',
                'App\\Services\\VisionScreenshotService',
                'screenshotAndExtract',
                'utility',
                'read',
                json_encode([
                    'required' => ['url'],
                    'optional' => ['extraction_prompt', 'wait_for', 'selector', 'full_page'],
                ]),
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'vision_screenshot'");
    }
};
