#!/usr/bin/env bash
set -euo pipefail

repo="${PLOS_PUBLIC_GITHUB_REPO:-}"
run_limit=6
strict_public_core=false
strict_latest_workflows=false
required_workflows=()

usage() {
    printf 'Usage: %s --repo owner/name [--run-limit N] [--strict-public-core] [--strict-latest-workflows] [--require-workflow NAME]\n' "$0"
    printf '\n'
    printf 'Read-only public GitHub release monitor using gh. Prints aggregate repo,\n'
    printf 'workflow, issue/PR, and traffic posture without printing credential values.\n'
    printf 'Use --strict-public-core to fail on public-core settings drift.\n'
    printf 'Use --strict-latest-workflows to fail when latest workflow runs are not green.\n'
    printf 'Use --require-workflow with strict latest checks to fail when a named workflow is absent from the latest-run window.\n'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            usage
            exit 0
            ;;
        --repo)
            if [[ -z "${2:-}" ]]; then
                usage >&2
                exit 2
            fi
            repo="$2"
            shift 2
            ;;
        --run-limit)
            if [[ -z "${2:-}" || ! "$2" =~ ^[0-9]+$ ]]; then
                usage >&2
                exit 2
            fi
            run_limit="$2"
            shift 2
            ;;
        --strict-public-core)
            strict_public_core=true
            shift
            ;;
        --strict-latest-workflows)
            strict_latest_workflows=true
            shift
            ;;
        --require-workflow)
            if [[ -z "${2:-}" ]]; then
                usage >&2
                exit 2
            fi
            strict_latest_workflows=true
            required_workflows+=("$2")
            shift 2
            ;;
        *)
            usage >&2
            exit 2
            ;;
    esac
done

if [[ -z "$repo" ]]; then
    printf 'FAIL: provide --repo owner/name or set PLOS_PUBLIC_GITHUB_REPO.\n' >&2
    exit 2
fi

if [[ ! "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
    printf 'FAIL: repo must be in owner/name form.\n' >&2
    exit 2
fi

if ! command -v gh >/dev/null 2>&1; then
    printf 'FAIL: gh is not installed or not on PATH.\n' >&2
    exit 1
fi

run_limit=$((run_limit < 1 ? 1 : run_limit))
run_limit=$((run_limit > 20 ? 20 : run_limit))

print_header() {
    printf '\n== %s ==\n' "$1"
}

strict_failures=()
strict_workflow_failures=()
declare -A latest_workflows_seen=()

strict_expect_line() {
    local haystack="$1"
    local expected="$2"
    local label="$3"

    if ! grep -qxF "$expected" <<< "$haystack"; then
        strict_failures+=("$label expected $expected")
    fi
}

traffic_line() {
    local endpoint="$1"
    local jq_filter="$2"
    local label="$3"
    local output

    if output="$(gh api "repos/$repo/$endpoint" --jq "$jq_filter" 2>/dev/null)"; then
        printf '%s: %s\n' "$label" "$output"
    else
        printf '%s: unavailable\n' "$label"
    fi
}

run_field() {
    local line="$1"
    local key="$2"
    local remainder="${line#* ${key}=}"

    if [[ "$remainder" == "$line" ]]; then
        printf 'unknown'
        return
    fi

    printf '%s' "${remainder%% *}"
}

print_header "Public GitHub Monitor"
printf 'Repository: %s\n' "$repo"
printf 'Mode: read-only aggregate check\n'

print_header "Repository"
repo_output="$(gh repo view "$repo" \
    --json url,visibility,isPrivate,description,defaultBranchRef,latestRelease,repositoryTopics,stargazerCount,forkCount,watchers \
    --jq '"url=\(.url)\nvisibility=\(.visibility)\nprivate=\(.isPrivate)\ndefault_branch=\(.defaultBranchRef.name)\nrelease=\(.latestRelease.tagName // "none")\nstars=\(.stargazerCount)\nforks=\(.forkCount)\nwatchers=\(.watchers.totalCount)\ntopics=\([.repositoryTopics[].name] | join(","))"')"
printf '%s\n' "$repo_output"

print_header "Repository Settings"
if settings="$(gh api "repos/$repo" --jq '"has_issues=\(.has_issues)\nhas_discussions=\(.has_discussions // false)\nlicense=\(.license.spdx_id // "none")\narchived=\(.archived)\ndisabled=\(.disabled)"' 2>/dev/null)"; then
    printf '%s\n' "$settings"
else
    settings="settings=unavailable"
    printf '%s\n' "$settings"
fi

print_header "Issue And PR Intake"
if issues="$(gh issue list --repo "$repo" --state open --limit 100 --json number --jq 'length' 2>/dev/null)"; then
    printf 'open_issues=%s\n' "$issues"
else
    printf 'open_issues=unavailable\n'
fi

if prs="$(gh pr list --repo "$repo" --state open --limit 100 --json number --jq 'length' 2>/dev/null)"; then
    printf 'open_prs=%s\n' "$prs"
else
    printf 'open_prs=unavailable\n'
fi

if runs="$(gh run list --repo "$repo" --limit "$run_limit" --json workflowName,status,conclusion,headBranch,headSha,displayTitle,createdAt --jq '.[] | "\(.createdAt) workflow=\(.workflowName) status=\(.status) conclusion=\(.conclusion // "none") branch=\(.headBranch) sha=\(.headSha[0:7]) title=\(.displayTitle)"' 2>/dev/null)"; then
    print_header "Latest Workflow Status"
    if [[ -n "$runs" ]]; then
        while IFS= read -r run_line; do
            workflow="${run_line#*workflow=}"
            workflow="${workflow%% status=*}"
            status="$(run_field "$run_line" status)"
            conclusion="$(run_field "$run_line" conclusion)"
            branch="$(run_field "$run_line" branch)"
            sha="$(run_field "$run_line" sha)"

            if [[ -n "${latest_workflows_seen[$workflow]+x}" ]]; then
                continue
            fi

            latest_workflows_seen[$workflow]=1
            printf 'workflow=%s latest_status=%s latest_conclusion=%s branch=%s sha=%s\n' \
                "$workflow" \
                "$status" \
                "$conclusion" \
                "$branch" \
                "$sha"

            if [[ "$status" != "completed" || "$conclusion" != "success" ]]; then
                strict_workflow_failures+=("$workflow latest_status=$status latest_conclusion=$conclusion branch=$branch sha=$sha")
            fi
        done <<< "$runs"
    else
        printf 'none\n'
        strict_workflow_failures+=("no workflow runs available")
    fi

    print_header "Recent Workflow Runs"
    if [[ -n "$runs" ]]; then
        printf '%s\n' "$runs"
    else
        printf 'none\n'
    fi
else
    print_header "Latest Workflow Status"
    printf 'unavailable\n'
    strict_workflow_failures+=("workflow runs unavailable")

    print_header "Recent Workflow Runs"
    printf 'unavailable\n'
fi

print_header "Traffic"
traffic_line "traffic/views" '"views=\(.count) uniques=\(.uniques)"' "views"
traffic_line "traffic/clones" '"clones=\(.count) uniques=\(.uniques)"' "clones"
traffic_line "traffic/popular/referrers" 'if length == 0 then "none" else .[0:5] | map("\(.referrer)=\(.count)/\(.uniques)") | join(",") end' "top_referrers"
traffic_line "traffic/popular/paths" 'if length == 0 then "none" else .[0:5] | map("\(.path)=\(.count)/\(.uniques)") | join(",") end' "top_paths"

if [[ "$strict_public_core" == "true" ]]; then
    print_header "Strict Public Core Check"
    strict_expect_line "$repo_output" "visibility=PUBLIC" "repository visibility"
    strict_expect_line "$repo_output" "private=false" "repository private flag"

    if grep -qxF "release=none" <<< "$repo_output"; then
        strict_failures+=("latest release expected not release=none")
    fi

    if [[ "$settings" == "settings=unavailable" ]]; then
        strict_failures+=("repository settings expected available")
    else
        strict_expect_line "$settings" "has_issues=true" "GitHub Issues"
        strict_expect_line "$settings" "has_discussions=false" "GitHub Discussions"
        strict_expect_line "$settings" "license=MIT" "repository license"
        strict_expect_line "$settings" "archived=false" "repository archived flag"
        strict_expect_line "$settings" "disabled=false" "repository disabled flag"
    fi

    if [[ "${#strict_failures[@]}" -gt 0 ]]; then
        for failure in "${strict_failures[@]}"; do
            printf 'STRICT FAIL: %s\n' "$failure"
        done
        exit 1
    fi

    printf 'Strict public-core check: pass\n'
fi

if [[ "$strict_latest_workflows" == "true" ]]; then
    print_header "Strict Latest Workflows Check"

    for required_workflow in "${required_workflows[@]}"; do
        if [[ -z "${latest_workflows_seen[$required_workflow]+x}" ]]; then
            strict_workflow_failures+=("$required_workflow required workflow missing from latest run window")
        fi
    done

    if [[ "${#strict_workflow_failures[@]}" -gt 0 ]]; then
        for failure in "${strict_workflow_failures[@]}"; do
            printf 'STRICT FAIL: %s\n' "$failure"
        done
        exit 1
    fi

    printf 'Strict latest-workflows check: pass\n'
fi

printf '\nResult: public GitHub monitor completed.\n'
