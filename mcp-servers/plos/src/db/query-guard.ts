import { CONFIG } from '../config.js';

// Statements that are NEVER allowed
const BLOCKED_PATTERNS = [
  /\b(DROP|TRUNCATE|DELETE|ALTER|CREATE|GRANT|REVOKE|RENAME)\b/i,
  /\bINTO\s+OUTFILE\b/i,
  /\bLOAD\s+DATA\b/i,
  /\bSET\s+(GLOBAL|SESSION)\b/i,
  /\bLOCK\s+TABLES?\b/i,
  /\bUNLOCK\s+TABLES?\b/i,
];

// Only UPDATE/INSERT allowed when explicitly opted in
const WRITE_PATTERNS = [
  /\b(UPDATE|INSERT|REPLACE)\b/i,
];

const READ_QUERY_PREFIX = /^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i;
const LIMITABLE_QUERY_PREFIX = /^\s*(SELECT|WITH)\b/i;

export interface QueryGuardResult {
  allowed: boolean;
  reason?: string;
  sanitizedSql?: string;
}

/**
 * Validate SQL query for safety. Default is read-only.
 * Returns sanitized SQL with LIMIT appended if missing.
 */
export function guardQuery(sql: string, allowWrite = false): QueryGuardResult {
  const trimmed = sql.trim();
  const withoutTrailingSemicolon = trimmed.replace(/;\s*$/, '');

  if (withoutTrailingSemicolon.includes(';')) {
    return { allowed: false, reason: 'Multiple SQL statements are not allowed' };
  }

  // Block dangerous statements always
  for (const pattern of BLOCKED_PATTERNS) {
    if (pattern.test(trimmed)) {
      return { allowed: false, reason: `Blocked: matches forbidden pattern ${pattern}` };
    }
  }

  // Block writes unless explicitly allowed
  if (!allowWrite) {
    if (!READ_QUERY_PREFIX.test(trimmed)) {
      return { allowed: false, reason: 'Only read-only SQL statements are allowed by default' };
    }

    for (const pattern of WRITE_PATTERNS) {
      if (pattern.test(trimmed)) {
        return { allowed: false, reason: 'Write operations require allowWrite=true' };
      }
    }
  }

  // Auto-append LIMIT if SELECT without one
  let sanitized = trimmed;
  if (
    LIMITABLE_QUERY_PREFIX.test(sanitized) &&
    !/\bLIMIT\s+\d+\b/i.test(sanitized) &&
    !/\bFETCH\s+FIRST\s+\d+\s+ROWS\s+ONLY\b/i.test(sanitized)
  ) {
    // Remove trailing semicolon before adding LIMIT
    sanitized = sanitized.replace(/;\s*$/, '');
    sanitized += ` LIMIT ${CONFIG.query.maxRows}`;
  }

  return { allowed: true, sanitizedSql: sanitized };
}
