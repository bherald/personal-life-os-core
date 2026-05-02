<?php

namespace App\Console\Commands;

class OfflineProgrammingCommand extends OfflineDevAssistCommand
{
    protected $signature = 'offline:programming
        {prompt?* : Prompt to run once. If omitted, starts an interactive shell}
        {--profile=offline_dev_assist : Activate a routing profile before starting}
        {--role=coding : Session model role (fast|standard|quality|coding|vision|embedding|uncensored)}
        {--approval=read-only : Session approval mode (read-only|repo-write)}
        {--max-iterations=5 : Maximum MCP/tool iterations per request}
        {--json : Emit JSON for one-shot calls}';

    protected $description = 'Alias for offline:dev-assist, tuned for offline/local programming sessions.';
}
