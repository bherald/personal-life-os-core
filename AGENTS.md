# Repository instructions

PLOS is the Personal Life Operating System. Preserve source content, uncertainty
and operator decisions. Use SQL or Laravel's query builder with bound parameters
for new persistence, and follow existing project formatting.

Never commit credentials, sessions, databases, real private photos, personal
records or deployment bindings. Fixtures must be fictional or carry verified
reuse provenance. Keep required upstream licenses and notices.

Face identity and sensitive actions require operator approval. Detection and
model confidence do not authorize an identity, merge, deletion or metadata write.

Before publication, run the privacy audit, provenance checks, dependency audits,
build and focused public tests. Validate schema-changing tests with isolated
SQLite or a dedicated test database; never mutate another developer's database.

Use separate checkouts for concurrent work. `mcp-server/src` and
`mcp-servers/plos/src` are authoritative; their compiled `dist` is generated.

For web changes, reuse the Decision Desk navy/teal semantic controls and verify
desktop and mobile rendering. Run Pint on changed PHP.
