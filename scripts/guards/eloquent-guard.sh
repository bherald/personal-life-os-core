#!/bin/bash
# Generic PLOS raw-SQL guard hook
# Scans edited PHP files for Eloquent/QueryBuilder usage after edits

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)

if [ -z "$FILE_PATH" ] || [[ ! "$FILE_PATH" =~ \.php$ ]]; then
    exit 0
fi

if [[ "$FILE_PATH" =~ /database/migrations/ ]] || \
   [[ "$FILE_PATH" =~ /tests/ ]] || \
   [[ "$FILE_PATH" =~ /config/ ]] || \
   [[ "$FILE_PATH" =~ /vendor/ ]]; then
    exit 0
fi

if [ ! -f "$FILE_PATH" ]; then
    exit 0
fi

VIOLATIONS=""

if grep -P '^\s*[^/*].*::where\s*\(' "$FILE_PATH" 2>/dev/null | grep -qv '//'; then
    VIOLATIONS="${VIOLATIONS}  - ::where() calls found\n"
fi

if grep -P '^\s*[^/*].*::find\s*\(' "$FILE_PATH" 2>/dev/null | grep -v '//' | grep -qv 'str_contains\|strpos\|array_find\|preg_\|Cache::'; then
    VIOLATIONS="${VIOLATIONS}  - ::find() calls found\n"
fi

if grep -qP '::findOrFail\s*\(' "$FILE_PATH" 2>/dev/null; then
    VIOLATIONS="${VIOLATIONS}  - ::findOrFail() calls found\n"
fi

if grep -P '^\s*[^/*].*::all\s*\(\s*\)' "$FILE_PATH" 2>/dev/null | grep -qv '//'; then
    VIOLATIONS="${VIOLATIONS}  - ::all() calls found\n"
fi

if grep -P '^\s*[^/*].*->save\s*\(\s*\)' "$FILE_PATH" 2>/dev/null | grep -qv '//'; then
    VIOLATIONS="${VIOLATIONS}  - ->save() calls found\n"
fi

if grep -P '^\s*[^/*].*DB::table\s*\(' "$FILE_PATH" 2>/dev/null | grep -qv '//'; then
    VIOLATIONS="${VIOLATIONS}  - DB::table() QueryBuilder usage found\n"
fi

if grep -qP '->belongsTo\s*\(|->hasMany\s*\(|->hasOne\s*\(|->belongsToMany\s*\(' "$FILE_PATH" 2>/dev/null; then
    VIOLATIONS="${VIOLATIONS}  - Eloquent relationship definitions found\n"
fi

if [ -n "$VIOLATIONS" ]; then
    BASENAME=$(basename "$FILE_PATH")
    cat <<WARN
{
  "hookSpecificOutput": {
    "hookEventName": "PostToolUse",
    "decision": "block",
    "reason": "RAW SQL GUARD: Potential Eloquent/QueryBuilder usage in ${BASENAME}. PLOS requires raw SQL (DB::select/insert/update/delete). Patterns found:\\n$(echo -e "$VIOLATIONS" | sed 's/\"/\\\"/g' | tr -d '\n')If false positives, you may proceed."
  }
}
WARN
    exit 0
fi

exit 0
