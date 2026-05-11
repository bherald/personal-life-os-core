#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"
cd "$ROOT"

failures=0

print_header() {
    printf '\n== %s ==\n' "$1"
}

flag_lines() {
    local title="$1"
    shift

    local output count limit
    limit="${PUBLIC_AUDIT_LIMIT:-80}"
    output="$("$@" || true)"
    output="$(printf '%s\n' "$output" | sed '/^$/d')"
    if [[ -n "$output" ]]; then
        count="$(printf '%s\n' "$output" | wc -l | tr -d ' ')"
        print_header "$title"
        printf '%s\n' "$output" | sed -n "1,${limit}p"
        if (( count > limit )); then
            printf '... %d more omitted. Re-run with PUBLIC_AUDIT_LIMIT=%d or inspect directly.\n' "$((count - limit))" "$count"
        fi
        failures=$((failures + 1))
    fi
}

public_privacy_scan_excludes=(
    ':!.claude.json'
    ':!.mcp.json'
    ':!CLAUDE.md'
    ':!docs/active-priority-list.md'
    ':!docs/PROJECT.md'
    ':!docs/PROD-MAINTENANCE.md'
    ':!docs/future-enhancements.md'
    ':!docs/papers-and-newsletters'
    ':!docs/papers-and-newsletters/**'
    ':!docs/planning'
    ':!docs/planning/**'
    ':!docs/plos-focus-report-*'
    ':!docs/plos-research-ledger.md'
    ':!mcp-server/node_modules'
    ':!mcp-servers/plos/node_modules'
    ':!vendor'
    ':!scripts/guards/public-release-audit.sh'
    ':!tests/Feature/Quality/FixturesProvenanceTest.php'
    ':!tests/Feature/Quality/PublicExportPackagingTest.php'
)
public_username_scan_excludes=(
    "${public_privacy_scan_excludes[@]}"
    ':!.github/FUNDING.yml'
)
public_candidate_scan_paths=(.)

load_public_candidate_scan_paths() {
    local path
    local destination="${TMPDIR:-/tmp}/plos-public-release-audit-candidate"
    local paths=()

    if [[ ! -x scripts/public-export.sh ]]; then
        return
    fi

    while IFS= read -r path; do
        [[ -n "$path" ]] || continue
        [[ "$path" == Public\ export\ destination:* ]] && continue
        [[ "$path" == Allowlisted\ tracked\ files:* ]] && continue
        [[ -f "$path" ]] || continue

        paths+=("$path")
    done < <(scripts/public-export.sh --dry-run "$destination" 2>/dev/null || true)

    if ((${#paths[@]} > 0)); then
        public_candidate_scan_paths=("${paths[@]}")
    fi
}

scan_real_secret_assignments() {
    local assignment_pattern
    assignment_pattern='(^|[^A-Za-z0-9_{$])((?![A-Z0-9_]*_ENV\b)[A-Z0-9_]*(?i:api[_-]?key|access[_-]?key|secret|password|passwd|pwd|client[_-]?secret|api[_-]?token|access[_-]?token|auth[_-]?token|bearer[_-]?token|refresh[_-]?token|webhook[_-]?url)[A-Z0-9_]*|["\x27](?![a-z0-9_.-]*[_-]env["\x27])[a-z0-9_.-]*(?i:api[_-]?key|access[_-]?key|secret|password|passwd|pwd|client[_-]?secret|api[_-]?token|access[_-]?token|auth[_-]?token|bearer[_-]?token|refresh[_-]?token|webhook[_-]?url)[a-z0-9_.-]*["\x27])\s*(?:=>|[:=])\s*["\x27]?(?!(?:$|["\x27]?$|null\b|false\b|true\b|changeme\b|change-[a-z0-9-]*\b|example\b|example_|your-|<|redacted|placeholder|public-|test-|dummy|\$\{|\$|\.|\{|\[|[A-Za-z_][A-Za-z0-9_]*::|CONFIG\.|process\.env|config\(|env\(|escapeshellarg\())[A-Za-z0-9_./+=:@%?&{}$!#-]{12,}'

    git grep -n -I -P -e "$assignment_pattern" -- "${public_candidate_scan_paths[@]}" "${public_privacy_scan_excludes[@]}"
}

scan_credentialed_urls() {
    local url_pattern placeholder_pattern
    url_pattern='[a-z][a-z0-9+.-]*://[^/[:space:]:@]+:[^/[:space:]:@]+@'
    placeholder_pattern='://(user|username|example|placeholder):(pass|password|secret|placeholder)@'

    git grep -n -I -E -e "$url_pattern" -- "${public_candidate_scan_paths[@]}" "${public_privacy_scan_excludes[@]}" \
        | rg -v -i -e "$placeholder_pattern"
}

scan_dev_agent_reference_paths() {
    printf '%s\n' "${public_candidate_scan_paths[@]}" \
        | rg -i '(^|/)(\.cline|\.roo|\.continue|\.aider|\.openhands|\.goose|\.swe-agent|claude-code-source|claude-code-prompts|cline-rules|roo-rules)(/|$|\.|-)'
}

scan_tracked_runtime_storage_payloads() {
    # Blocks storage/app/dev-agent/traces, storage/agent-handoffs,
    # storage/claude-work, storage/tools, and any future tracked runtime
    # storage payload outside the public placeholder .gitignore files.
    git ls-files storage \
        | rg -v '^(storage/app/\.gitignore|storage/app/private/\.gitignore|storage/app/public/\.gitignore|storage/framework/\.gitignore|storage/framework/cache/\.gitignore|storage/framework/cache/data/\.gitignore|storage/framework/sessions/\.gitignore|storage/framework/testing/\.gitignore|storage/framework/views/\.gitignore|storage/logs/\.gitignore)$'
}

print_header "Public release audit"
printf 'Scope: tracked public-extraction candidate files only. Private-only docs are excluded by path.\n'
printf 'This is a blocker finder for public extraction, not a private-prod deploy gate.\n'

if [[ ! -f LICENSE && ! -f LICENSE.md ]]; then
    print_header "Missing public license"
    printf 'No root LICENSE or LICENSE.md exists. Pick the public project license before publishing.\n'
    failures=$((failures + 1))
fi

for schema_dump in database/schema/mysql-schema.sql database/schema/pgsql-schema.sql; do
    if ! git ls-files --error-unmatch "$schema_dump" >/dev/null 2>&1; then
        print_header "Missing public schema dump"
        printf '%s is required because the historical baseline migration does not create first-generation tables.\n' "$schema_dump"
        failures=$((failures + 1))
    fi
done

load_public_candidate_scan_paths

flag_lines "Tracked local/private control files" \
    git ls-files -- \
        '.claude.json' \
        '.mcp.json' \
        '.serena' \
        '.env' \
        '.env.production' \
        'CREDENTIALS.md' \
        'CLAUDE.md'

flag_lines "Tracked archives, screenshots, or generated bundles needing review" \
    bash -c "git ls-files -- '*.zip' '*.tar' '*.tar.gz' '*.tgz' '*.7z' '*.sqlite' '*.db' '*.dump' '*.pem' '*.key' '*.p12' '*.pfx' '*.xpi' 'scheduled-jobs-*.png' 'public/build/**' ':!mcp-server/node_modules/**'"

flag_lines "Vendored dependencies tracked in git" \
    bash -c "git ls-files | rg '(^|/)(node_modules|vendor)/'"

flag_lines "Tracked runtime storage payloads requiring public-extraction review" \
    scan_tracked_runtime_storage_payloads

flag_lines "Real secret assignments with non-placeholder values" \
    scan_real_secret_assignments

flag_lines "Provider/platform token shapes" \
    git grep -n -I -P '\b(sk-(?:proj-)?[A-Za-z0-9_-]{20,}|sk-ant-[A-Za-z0-9_-]{20,}|gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{22,}|xox[baprs]-[A-Za-z0-9-]{20,}|AKIA[0-9A-Z]{16}|SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})\b' -- "${public_candidate_scan_paths[@]}" \
        "${public_privacy_scan_excludes[@]}"

flag_lines "Private provider billing/access assumptions" \
    git grep -n -I -E '(Max [Ss]ubscription|Claude Max|Included in Max|no API costs)' -- "${public_candidate_scan_paths[@]}" \
        "${public_privacy_scan_excludes[@]}"

flag_lines "Private keys, certificates, or encrypted key material" \
    git grep -n -I -E -e '-----BEGIN (RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----|-----BEGIN CERTIFICATE-----|BEGIN AGE ENCRYPTED FILE|OPENSSH PRIVATE KEY' -- "${public_candidate_scan_paths[@]}" \
        "${public_privacy_scan_excludes[@]}"

flag_lines "Credentialed URLs" \
    scan_credentialed_urls

flag_lines "Private paths, hosts, users, and compute labels" \
    git grep -n -I -E '(/home/bill|/Users/bill|C:\\Users\\bill|D:\\master|/MASTER(/|$)|192\.168\.8\.|ai-wphc-production|prod_252|gpu_252|cpu_252|prod_87|gpu_87|cpu_87|\.252_only|\.87_only|bill@|bherald|id_rsa|id_ed25519)' -- "${public_candidate_scan_paths[@]}" \
        "${public_username_scan_excludes[@]}"

flag_lines "Public-candidate files referencing non-exported planning paths" \
    git grep -n -I -F 'docs/planning/' -- "${public_candidate_scan_paths[@]}" \
        "${public_privacy_scan_excludes[@]}"

flag_lines "Files containing private paths, LAN hosts, usernames, or machine-specific values" \
    git grep -l -I -E '(/home/bill|192\.168\.8\.|ai-wphc-production|bill@|bherald)' -- "${public_candidate_scan_paths[@]}" \
        "${public_username_scan_excludes[@]}"

flag_lines "Operator-specific Nextcloud library root literal (/MASTER)" \
    git grep -n -I -E '/MASTER' -- \
        app \
        config \
        database \
        resources \
        routes \
        scripts \
        docs/AGENT-SAFETY-CARDS.md \
        docs/AIService-LLM-Gateway.md \
        docs/FACE-RECOGNITION.md \
        docs/face-metadata-writeback.md \
        docs/OLLAMA-COMPATIBILITY.md \
        docs/architecture.md \
        docs/personal-connectors.md \
        docs/README.md \
        docs/plos-runtime-architecture.md \
        docs/plos-task-lease-contract.md \
        docs/public-install-prerequisites.md \
        docs/public-release-readiness.md \
        docs/queue-placement-policy.md \
        docs/schema-reference.md \
        ':!scripts/guards/public-release-audit.sh' \
        ':!tests/Feature/Quality/PublicExportPackagingTest.php'

flag_lines "Operator first-name leak (Bill)" \
    git grep -n -I -E '\bBill\b' -- \
        app \
        config \
        database \
        resources \
        routes \
        ':!app/Console/Commands/EmailSuggestionsCommand.php' \
        ':!app/Http/Controllers/Api/EmailController.php' \
        ':!app/Nodes/ResearchTopicRunner.php' \
        ':!app/Services/AIAutoTagService.php' \
        ':!app/Services/BillDetectionService.php' \
        ':!app/Services/EmailSuggestionService.php' \
        ':!app/Services/FaceMatcherService.php' \
        ':!app/Services/Genealogy/DuplicateDetectionService.php' \
        ':!app/Services/Genealogy/GenealogyService.php' \
        ':!app/Services/Genealogy/GenealogyMediaService.php' \
        ':!app/Services/Genealogy/NameVariantService.php' \
        ':!app/Services/Genealogy/Support/GivenNameVariants.php' \
        ':!resources/agents/skills/file-curator/SKILL.md' \
        ':!resources/js/src/views/EmailQueueView.vue'

flag_lines "Operator username default ('bill') in SSH/Nextcloud helpers" \
    git grep -n -I -E "(\\?\\? *'bill'|= *'bill';|files:scan +bill|trashbin:cleanup +bill)" -- \
        app \
        config \
        database \
        ':!scripts/guards/public-release-audit.sh'

flag_lines "Public-bound source containing private genealogy/person literals" \
    git grep -n -I -E '(William_P_Herald|\bHerald\b|\bMancini\b|bherald|/home/bill|192\.168\.8\.)' -- \
        .github \
        app \
        config \
        database \
        docker \
        docs/AGENT-SAFETY-CARDS.md \
        docs/AIService-LLM-Gateway.md \
        docs/OLLAMA-COMPATIBILITY.md \
        docs/architecture.md \
        docs/README.md \
        docs/plos-runtime-architecture.md \
        docs/plos-task-lease-contract.md \
        docs/public-install-prerequisites.md \
        docs/public-release-readiness.md \
        docs/queue-placement-policy.md \
        docs/schema-reference.md \
        mcp-server \
        mcp-servers \
        resources \
        routes \
        scripts \
        tests/Feature/Console \
        tests/Feature/Quality \
        tests/Fixtures \
        tests/Support/ScenarioHarness \
        tests/Unit/Setup \
        README.md \
        THIRD_PARTY.md \
        .env.example \
        docker-compose.yml \
        docker-compose.personal.example.yml \
        ':!.github/FUNDING.yml' \
        ':!scripts/guards/public-release-audit.sh' \
        ':!scripts/bench' \
        ':!tests/Feature/Quality/FixturesProvenanceTest.php' \
        ':!tests/Fixtures/PROVENANCE.md' \
        ':!mcp-server/node_modules' \
        ':!mcp-servers/plos/node_modules'

flag_lines "Files containing private database names or historical credential literals" \
    git grep -n -I -E '(bh123|bh123_rag|automation_framework|automation_rag|automator_rag)' -- . \
        ':!.claude.json' \
        ':!.mcp.json' \
        ':!CLAUDE.md' \
        ':!docs/PROJECT.md' \
        ':!docs/PROD-MAINTENANCE.md' \
        ':!docs/future-enhancements.md' \
        ':!docs/papers-and-newsletters' \
        ':!docs/papers-and-newsletters/**' \
        ':!docs/planning' \
        ':!docs/planning/**' \
        ':!docs/plos-focus-report-*' \
        ':!docs/plos-research-ledger.md' \
        ':!mcp-server/node_modules' \
        ':!vendor' \
        ':!scripts/guards/public-release-audit.sh'

flag_lines "Brand/trademark file paths to rename or private-only gate" \
    bash -c "git ls-files app resources routes config database docs tailwind.config.js ':!docs/planning' ':!docs/planning/**' | rg -i '(lcars|star[ _-]?trek|starfleet|federation|tricorder|viewscreen|comm[ _-]?badge|warp[ _-]?drive|impulse[ _-]?engine|(^|[^[:alnum:]_])(tng|voy|ds9)([^[:alnum:]_]|$))' | rg -v '^docs/(PROJECT.md|plos-focus-report-.*|public-release-readiness.md|public-release/npm-license-snapshot\\.(json|md)|canonical-docs-archive-.*\\.zip)$'"

flag_lines "Brand/trademark terms to replace or private-only gate" \
    git grep -n -i -I -E '(lcars|star[ _-]?trek|starfleet|federation|tricorder|viewscreen|comm[ _-]?badge|warp[ _-]?drive|impulse[ _-]?engine|(^|[^[:alnum:]_])(tng|voy|ds9)([^[:alnum:]_]|$))' -- app resources routes config database docs tailwind.config.js \
        ':!docs/canonical-docs-archive-*.zip' \
        ':!docs/PROJECT.md' \
        ':!docs/planning' \
        ':!docs/planning/**' \
        ':!docs/plos-focus-report-*' \
        ':!docs/public-release-readiness.md' \
        ':!docs/public-release/npm-license-snapshot.json' \
        ':!docs/public-release/npm-license-snapshot.md'

flag_lines "Legacy private project brand terms to replace or private-only gate" \
    git grep -n -I -E '(WPHC|AI-WPHC|ai-wphc|wphc)' -- app resources routes config database docs composer.json package.json package-lock.json mcp-server mcp-servers browser-extensions thunderbird-extension tests \
        ':!docs/canonical-docs-archive-*.zip' \
        ':!docs/PROJECT.md' \
        ':!docs/PROD-MAINTENANCE.md' \
        ':!docs/future-enhancements.md' \
        ':!docs/papers-and-newsletters' \
        ':!docs/papers-and-newsletters/**' \
        ':!docs/planning' \
        ':!docs/planning/**' \
        ':!docs/plos-focus-report-*' \
        ':!docs/plos-research-ledger.md' \
        ':!mcp-server/node_modules' \
        ':!mcp-servers/plos/node_modules'

flag_lines "PhotoPrism provenance language requiring review" \
    git grep -n -I -E '(PhotoPrism-adapted|PhotoPrism-inspired|adapted directly from PhotoPrism|adapted from PhotoPrism|ported from PhotoPrism|PhotoPrism integration|PhotoPrism RAG|adapted from LibrePhotos|ported from LibrePhotos|adapted from digiKam|ported from digiKam|adapted from Joplin|ported from Joplin)' -- app resources routes config database docs \
        ':!docs/canonical-docs-archive-*.zip' \
        ':!docs/PROJECT.md' \
        ':!docs/planning' \
        ':!docs/planning/**' \
        ':!docs/plos-focus-report-*' \
        ':!docs/face-metadata-writeback.md' \
        ':!docs/public-release-readiness.md'

flag_lines "Dev-agent reference files requiring public-release review" \
    scan_dev_agent_reference_paths

flag_lines "Dev-agent copied-provenance language requiring review" \
    git grep -n -I -E '(adapted from (Claude Code|Clawcode|Cline|Roo Code|Aider|Continue|OpenHands|SWE-agent|goose)|ported from (Claude Code|Clawcode|Cline|Roo Code|Aider|Continue|OpenHands|SWE-agent|goose)|copied from (Claude Code|Clawcode|Cline|Roo Code|Aider|Continue|OpenHands|SWE-agent|goose)|based on (Claude Code|Clawcode|Cline|Roo Code|Aider|Continue|OpenHands|SWE-agent|goose) source|Claude Code source tree|Clawcode source tree)' -- app resources routes config database docs scripts \
        ':!docs/canonical-docs-archive-*.zip' \
        ':!docs/PROJECT.md' \
        ':!docs/planning' \
        ':!docs/planning/**' \
        ':!docs/plos-focus-report-*' \
        ':!docs/clean-room-references.md' \
        ':!scripts/guards/public-release-audit.sh'

flag_lines "Unsupported genealogy API runtime surfaces" \
    git grep -n -I -E '(FAMILYSEARCH_APP_KEY|FAMILYSEARCH_APP_SECRET|FAMILYSEARCH_REDIRECT_URI|FAMILYSEARCH_ENVIRONMENT|FAMILYSEARCH_BASE_URL|ANCESTRY_PASSWORD|ANCESTRY_DNA_TEST_GUID|getFamilySearchAuthUrl|syncFamilySearchHints|connectFamilySearch|searchFamilySearchPublic|searchFamilySearch\(|searchAncestry\(|AncestryDnaProvider|FamilySearchProvider|Ancestry API needed|FamilySearch images can be downloaded via their API|OAuth2 flow \(FamilySearch|future Ancestry)' -- \
        app \
        config \
        resources \
        routes \
        docker-compose.yml \
        docker-compose.personal.example.yml \
        .env.example \
        ':!scripts/guards/public-release-audit.sh'

flag_lines "Public fixture private tokens requiring rewrite" \
    git grep -n -I -E '(Herald|Mancini|bherald|/MASTER/FT|/MASTER/Family Tree|/home/bill|192\.168\.8\.)' -- tests/Fixtures \
        ':!tests/Fixtures/PROVENANCE.md'

if (( failures > 0 )); then
    printf '\nResult: NOT PUBLIC-READY (%d blocker group(s)).\n' "$failures"
    printf 'Use docs/public-release-readiness.md as the cleanup plan.\n'
    exit 1
fi

printf '\nResult: public-release audit passed.\n'
