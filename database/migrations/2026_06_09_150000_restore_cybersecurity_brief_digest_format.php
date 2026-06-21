<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WORKFLOW_NAME = 'Cybersecurity News Brief';

    private const DIGEST_PROMPT = <<<'PROMPT'
🔐 CYBERSECURITY NEWS BRIEF - TODAY'S EDITION

ANTI-HALLUCINATION RULES:
1. ZERO FABRICATION - Use ONLY articles from the provided cybersecurity RSS/search data.
2. NO MADE-UP CONTENT - Do not invent headlines, CVEs, threat actors, affected products, impact, patch status, or source names.
3. If input data is null/empty/contains errors, respond exactly:
   🚫 Unable to retrieve cybersecurity news at this time.
4. REQUIRED: Include the original direct source URL for every item, but do not turn the whole brief into a link list.
5. Your job is only to select, prioritize, summarize, and format provided articles.

TASK:
Create a scan-friendly mobile Pushover HTML cybersecurity digest. It should read like a compact briefing, not one or two lines of hyperlinks.

OUTPUT FORMAT:
🔐 <b><font color="#2E86DE">CYBERSECURITY NEWS BRIEF - {TODAY_SHORT}</font></b>
<font color="#778CA3">Compiled from security RSS feeds</font>

<b><font color="#EE5A6F">🚨 CRITICAL ALERTS</font></b>
• <b>[headline]</b>
  [One sentence, 18-32 words, explaining impact and practical action.]
  <font color="#778CA3">[Source] | Published: mm/dd HH:mm</font> <a href="[direct URL]">Read</a>

<b><font color="#FED330">⚠️ IMPORTANT NEWS</font></b>
• <b>[headline]</b>
  [One sentence, 18-32 words, explaining why it matters.]
  <font color="#778CA3">[Source] | Published: mm/dd HH:mm</font> <a href="[direct URL]">Read</a>

<b><font color="#45AAF2">📰 GENERAL SECURITY NEWS</font></b>
• <b>[headline]</b>
  [One sentence, 18-32 words, with useful context.]
  <font color="#778CA3">[Source] | Published: mm/dd HH:mm</font> <a href="[direct URL]">Read</a>

SECTION RULES:
- Use Critical for active exploitation, urgent patches, breaches, ransomware, credential theft, supply-chain compromises, and internet-scale risk.
- Use Important for major vulnerabilities, defensive guidance, notable threat activity, and platform security updates.
- Use General only for lower-urgency items worth retaining.
- Omit empty sections.
- Prefer 8-12 total stories when enough source material exists; never exceed 12.
- Keep summaries informative. Do not collapse items into one-line hyperlinks.
- If the brief grows too long, drop lower-priority General items before shortening Critical summaries.
- Use only Pushover-safe HTML tags: <b>, <font color="#RRGGBB">, and <a href="URL">text</a>.
- Do not use Markdown links, raw URLs outside href attributes, tables, code fences, or unsupported HTML.
PROMPT;

    private const LINKED_DIGEST_PROMPT = <<<'PROMPT'
🔐 CYBERSECURITY NEWS BRIEF - LINKED DIGEST

**ANTI-HALLUCINATION RULES:**
1. ZERO FABRICATION - Use ONLY articles from the provided cybersecurity RSS/search data.
2. NO MADE-UP CONTENT - Do not invent headlines, CVEs, threat actors, affected products, impact, or patch status.
3. If input data is null/empty/contains errors, respond exactly:
   🚫 Unable to retrieve cybersecurity news at this time.
4. REQUIRED: Every item must include the original direct source URL as a Pushover HTML hyperlink.
5. Your job is only to select, prioritize, and format provided articles.

**TASK:**
Create a compact mobile Pushover HTML cybersecurity brief using the short digest + links format.

**OUTPUT FORMAT:**
🔐 <b>CYBER BRIEF - {TODAY_SHORT}</b>
🚨 <a href="[direct URL]">[short headline]</a> - [8-14 words explaining impact/action]. <font color="#778CA3">[Source]</font>
⚠️ <a href="[direct URL]">[short headline]</a> - [8-14 words explaining impact/action]. <font color="#778CA3">[Source]</font>

**EMOJI GUIDE:**
- 🚨 actively exploited, critical patch, breach, ransomware
- ⚠️ important vulnerability, major incident, exposed data
- 🛡️ security update, mitigation, defensive guidance
- 📦 supply-chain/package/library issue
- 🎯 targeted attack, phishing, espionage
- 🔑 identity, credentials, authentication, account takeover
- 🌐 internet-scale platform/cloud/network issue

**LENGTH AND DELIVERY RULES:**
- One line per reported issue.
- Use a few useful words after each link, but keep each line compact.
- Include 6-8 items when available; never exceed 8 items.
- Keep total output under 1,800 characters so it fits in two Pushover packets.
- Prioritize exploited vulnerabilities, urgent patches, breaches, ransomware, credential theft, and supply-chain attacks.
- If too many stories exist, omit lower-priority items instead of making the brief longer.
- Use only Pushover-safe HTML: <b>, <font color="#RRGGBB">, and <a href="URL">text</a>.
PROMPT;

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->nodeIds('AIFormatter') as $nodeId) {
                $this->upsertConfig($nodeId, 'prompt', self::DIGEST_PROMPT);
                $this->upsertConfig($nodeId, 'response_format', 'text');
                $this->upsertConfig($nodeId, 'pushover_format', 'html');
                $this->upsertConfig($nodeId, 'prefer_preformatted', 'false');
            }

            foreach ($this->nodeIds('PushoverNotify') as $nodeId) {
                $this->upsertConfig($nodeId, 'format_type', 'html');
                $this->upsertConfig($nodeId, 'source_group', 'workflow_routine_updates');
                $this->upsertConfig($nodeId, 'inter_chunk_delay_seconds', '3');
                $this->upsertConfig($nodeId, 'part_timestamps_enabled', '1');
                $this->upsertConfig($nodeId, 'part_headers_enabled', '1');
                $this->deleteConfig($nodeId, 'max_delivery_parts');
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->nodeIds('AIFormatter') as $nodeId) {
                $this->upsertConfig($nodeId, 'prompt', self::LINKED_DIGEST_PROMPT);
                $this->upsertConfig($nodeId, 'response_format', 'text');
                $this->upsertConfig($nodeId, 'pushover_format', 'html');
                $this->deleteConfigIfValue($nodeId, 'prefer_preformatted', 'false');
            }

            foreach ($this->nodeIds('PushoverNotify') as $nodeId) {
                $this->upsertConfig($nodeId, 'format_type', 'html');
                $this->upsertConfig($nodeId, 'max_delivery_parts', '2');
                $this->deleteConfigIfValue($nodeId, 'inter_chunk_delay_seconds', '3');
                $this->deleteConfigIfValue($nodeId, 'part_timestamps_enabled', '1');
                $this->deleteConfigIfValue($nodeId, 'part_headers_enabled', '1');
            }
        });
    }

    /**
     * @return list<int>
     */
    private function nodeIds(string $nodeType): array
    {
        return DB::table('workflow_nodes')
            ->join('workflows', 'workflows.id', '=', 'workflow_nodes.workflow_id')
            ->where('workflows.name', self::WORKFLOW_NAME)
            ->where(function ($query) use ($nodeType): void {
                $query->where('workflow_nodes.node_type', $nodeType)
                    ->orWhere('workflow_nodes.node_type', 'like', '%\\'.$nodeType);
            })
            ->pluck('workflow_nodes.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function upsertConfig(int $nodeId, string $key, string $value): void
    {
        DB::table('workflow_node_configs')->updateOrInsert(
            [
                'workflow_node_id' => $nodeId,
                'config_key' => $key,
            ],
            [
                'config_value' => $value,
            ]
        );
    }

    private function deleteConfig(int $nodeId, string $key): void
    {
        DB::table('workflow_node_configs')
            ->where('workflow_node_id', $nodeId)
            ->where('config_key', $key)
            ->delete();
    }

    private function deleteConfigIfValue(int $nodeId, string $key, string $value): void
    {
        DB::table('workflow_node_configs')
            ->where('workflow_node_id', $nodeId)
            ->where('config_key', $key)
            ->where('config_value', $value)
            ->delete();
    }
};
