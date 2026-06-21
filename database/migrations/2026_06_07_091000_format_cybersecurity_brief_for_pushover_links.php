<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WORKFLOW_NAME = 'Cybersecurity News Brief';

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

    private const PREVIOUS_PROMPT = <<<'PROMPT'
🔐 CYBERSECURITY NEWS BRIEF - TODAY''S EDITION

**ANTI-HALLUCINATION RULES:**
1. ZERO FABRICATION - Use ONLY articles from the provided RSS feed data
2. NO MADE-UP CONTENT - Do not invent headlines or information
3. If input data is null/empty, respond: "🚫 Unable to retrieve cybersecurity news at this time."
4. INCLUDE ALL RELEVANT ARTICLES - Do NOT skip important security stories
5. Your job is to format and organize provided articles ONLY

**TASK:**
Transform cybersecurity RSS feed articles into a concise mobile-friendly brief.

**FORMATTING:**
- Use emojis for visual appeal (🚨 critical, ⚠️ important, 🔒 encryption, 💉 malware, 🎯 attacks, etc.)
- Organize by severity/importance
- Brief summaries (1-2 sentences each)
- Include source with bias rating
- Include publication date at the end of each article in format: mm/dd/yyyy HH:mm (24-hour time)
- Maximum 15 most important stories

**OUTPUT FORMAT:**

🔐 CYBERSECURITY NEWS BRIEF
━━━━━━━━━━━━━━━━━━━━━━

**🚨 CRITICAL ALERTS**
• [Headline]
  Summary in 1-2 sentences.
  Source: [Source Name] [bias emoji] | Published: mm/dd/yyyy HH:mm

**⚠️ IMPORTANT NEWS**
• [Headline]
  Brief summary.
  Source: [Source Name] [bias emoji] | Published: mm/dd/yyyy HH:mm

**📰 GENERAL SECURITY NEWS**
• [Headline]
  Summary.
  Source: [Source Name] [bias emoji] | Published: mm/dd/yyyy HH:mm

━━━━━━━━━━━━━━━━━━━━━━
📊 Compiled from security RSS feeds
PROMPT;

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->nodeIds('AIFormatter') as $nodeId) {
                $this->upsertConfig($nodeId, 'prompt', self::LINKED_DIGEST_PROMPT);
                $this->upsertConfig($nodeId, 'response_format', 'text');
                $this->upsertConfig($nodeId, 'pushover_format', 'html');
            }

            foreach ($this->nodeIds('PushoverNotify') as $nodeId) {
                $this->upsertConfig($nodeId, 'format_type', 'html');
                $this->upsertConfig($nodeId, 'max_delivery_parts', '2');
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->nodeIds('AIFormatter') as $nodeId) {
                $this->upsertConfig($nodeId, 'prompt', self::PREVIOUS_PROMPT);
                $this->deleteConfigIfValue($nodeId, 'pushover_format', 'html');
            }

            foreach ($this->nodeIds('PushoverNotify') as $nodeId) {
                $this->deleteConfigIfValue($nodeId, 'format_type', 'html');
                $this->deleteConfigIfValue($nodeId, 'max_delivery_parts', '2');
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

    private function deleteConfigIfValue(int $nodeId, string $key, string $value): void
    {
        DB::table('workflow_node_configs')
            ->where('workflow_node_id', $nodeId)
            ->where('config_key', $key)
            ->where('config_value', $value)
            ->delete();
    }
};
