<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Contacts Persistence Service (DI-2)
 *
 * Syncs Nextcloud contacts to MySQL and indexes them into RAG.
 * Designed to run as a scheduled job every 6 hours.
 *
 * Pipeline: Nextcloud CardDAV → contacts (MySQL) → rag_documents (PostgreSQL)
 */
class ContactsPersistenceService
{
    private NextcloudContactsService $contactsService;
    private RAGService $ragService;

    public function __construct(NextcloudContactsService $contactsService, RAGService $ragService)
    {
        $this->contactsService = $contactsService;
        $this->ragService = $ragService;
    }

    /**
     * Full sync: pull from Nextcloud, persist to MySQL, index to RAG.
     *
     * @return array Summary of sync results
     */
    public function syncAndIndex(): array
    {
        $results = [
            'sync' => ['fetched' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0],
            'rag' => ['indexed' => 0, 'skipped' => 0, 'errors' => 0],
        ];

        try {
            // Step 1: Sync from Nextcloud → MySQL
            $syncResult = $this->syncFromNextcloud();
            $results['sync'] = $syncResult;

            Log::info('ContactsPersistence: Sync complete', $results['sync']);
        } catch (Exception $e) {
            Log::error('ContactsPersistence: Sync failed', ['error' => $e->getMessage()]);
            $results['sync']['errors']++;
            return $results;
        }

        try {
            // Step 2: Index unindexed contacts to RAG
            $ragResult = $this->indexUnindexedContacts();
            $results['rag'] = $ragResult;

            Log::info('ContactsPersistence: RAG indexing complete', $results['rag']);
        } catch (Exception $e) {
            Log::error('ContactsPersistence: RAG indexing failed', ['error' => $e->getMessage()]);
            $results['rag']['errors']++;
        }

        return $results;
    }

    /**
     * Sync contacts from all Nextcloud address books into MySQL.
     * Uses external_id (vCard UID) as dedup key.
     *
     * @return array Sync results
     */
    private function syncFromNextcloud(): array
    {
        $fetched = 0;
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $errors = 0;

        $addressBooks = $this->contactsService->getAddressBooks();

        foreach ($addressBooks as $ab) {
            try {
                // Fetch all contacts (no limit — contacts are typically < 1000)
                $contacts = $this->contactsService->getContacts($ab['name'], 5000);
                $fetched += count($contacts);

                foreach ($contacts as $contact) {
                    try {
                        $result = $this->upsertContact($contact, $ab['displayName']);

                        match ($result) {
                            'inserted' => $inserted++,
                            'updated' => $updated++,
                            'unchanged' => $unchanged++,
                            default => null,
                        };
                    } catch (Exception $e) {
                        Log::warning('ContactsPersistence: Failed to upsert contact', [
                            'name' => $contact['name'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                    }
                }
            } catch (Exception $e) {
                Log::warning('ContactsPersistence: Failed to fetch address book', [
                    'addressBook' => $ab['name'],
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        return compact('fetched', 'inserted', 'updated', 'unchanged', 'errors');
    }

    /**
     * Upsert a single contact into MySQL.
     * Returns 'inserted', 'updated', or 'unchanged'.
     */
    private function upsertContact(array $contact, string $addressBookName): string
    {
        $externalId = $contact['vcardUid'] ?? $contact['uid'] ?? null;
        if (!$externalId) {
            throw new Exception('Contact has no UID');
        }

        $fullName = $contact['name'] ?? trim(($contact['givenName'] ?? '') . ' ' . ($contact['familyName'] ?? ''));
        if (empty(trim($fullName))) {
            return 'unchanged'; // Skip contacts with no name
        }

        // Build raw vCard for change detection
        $rawVcard = $contact['rawVcard'] ?? '';
        $vcardHash = md5($rawVcard);

        // Check existing record
        $existing = DB::selectOne(
            "SELECT id, raw_vcard FROM contacts WHERE external_id = ?",
            [$externalId]
        );

        $data = [
            'full_name' => mb_substr($fullName, 0, 255),
            'first_name' => mb_substr($contact['givenName'] ?? '', 0, 100),
            'last_name' => mb_substr($contact['familyName'] ?? '', 0, 100),
            'nickname' => mb_substr($contact['nickname'] ?? '', 0, 100),
            'emails' => json_encode($contact['emails'] ?? []),
            'phones' => json_encode($contact['phones'] ?? []),
            'addresses' => json_encode($contact['addresses'] ?? []),
            'organization' => mb_substr($contact['organization'] ?? '', 0, 255),
            'title' => mb_substr($contact['title'] ?? '', 0, 255),
            'birthday' => $this->parseBirthday($contact['birthday'] ?? null),
            'notes' => $contact['note'] ?? null,
            'categories' => json_encode($contact['categories'] ?? []),
            'raw_vcard' => $rawVcard,
        ];

        if ($existing) {
            // Skip if vCard hasn't changed
            if (md5($existing->raw_vcard ?? '') === $vcardHash && !empty($rawVcard)) {
                return 'unchanged';
            }

            DB::update(
                "UPDATE contacts SET
                    full_name = ?, first_name = ?, last_name = ?, nickname = ?,
                    emails = ?, phones = ?, addresses = ?,
                    organization = ?, title = ?, birthday = ?, notes = ?,
                    categories = ?, raw_vcard = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $data['full_name'], $data['first_name'], $data['last_name'], $data['nickname'],
                    $data['emails'], $data['phones'], $data['addresses'],
                    $data['organization'], $data['title'], $data['birthday'], $data['notes'],
                    $data['categories'], $data['raw_vcard'], $existing->id,
                ]
            );

            return 'updated';
        }

        DB::insert(
            "INSERT INTO contacts
                (external_id, full_name, first_name, last_name, nickname,
                 emails, phones, addresses, organization, title, birthday,
                 notes, categories, raw_vcard, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $externalId,
                $data['full_name'], $data['first_name'], $data['last_name'], $data['nickname'],
                $data['emails'], $data['phones'], $data['addresses'],
                $data['organization'], $data['title'], $data['birthday'],
                $data['notes'], $data['categories'], $data['raw_vcard'],
            ]
        );

        return 'inserted';
    }

    /**
     * Parse birthday string into date format, or null.
     */
    private function parseBirthday(?string $birthday): ?string
    {
        if (empty($birthday)) {
            return null;
        }

        // Handle YYYYMMDD format
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $birthday, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // Handle YYYY-MM-DD format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
            return $birthday;
        }

        // Handle --MMDD (no year) — use 1900 as placeholder
        if (preg_match('/^--(\d{2})(\d{2})$/', $birthday, $m)) {
            return "1900-{$m[1]}-{$m[2]}";
        }

        return null;
    }

    /**
     * Index contacts that haven't been indexed to RAG yet.
     *
     * @param int $limit Max contacts to index per run
     * @return array Indexing results
     */
    public function indexUnindexedContacts(int $limit = 200): array
    {
        $indexed = 0;
        $skipped = 0;
        $errors = 0;

        $contacts = DB::select(
            "SELECT id, external_id, full_name, first_name, last_name, nickname,
                    emails, phones, addresses, organization, title, birthday,
                    notes, categories
             FROM contacts
             WHERE rag_indexed_at IS NULL
             ORDER BY full_name ASC
             LIMIT ?",
            [$limit]
        );

        foreach ($contacts as $contact) {
            try {
                $content = $this->buildRagContent($contact);

                if (empty(trim($content)) || strlen(trim($content)) < 20) {
                    DB::update(
                        "UPDATE contacts SET rag_indexed_at = NOW() WHERE id = ?",
                        [$contact->id]
                    );
                    $skipped++;
                    continue;
                }

                $metadata = [
                    'organization' => $contact->organization,
                    'birthday' => $contact->birthday,
                    'has_email' => !empty(json_decode($contact->emails ?? '[]', true)),
                    'has_phone' => !empty(json_decode($contact->phones ?? '[]', true)),
                ];

                $result = $this->ragService->indexDocument(
                    documentType: 'contact',
                    content: $content,
                    title: $contact->full_name ?? 'Contact',
                    metadata: $metadata,
                    sourceId: $contact->id,
                    sourceType: 'contact',
                );

                if ($result) {
                    DB::update(
                        "UPDATE contacts SET rag_indexed_at = NOW() WHERE id = ?",
                        [$contact->id]
                    );
                    $indexed++;
                } else {
                    // Mark as indexed to avoid re-processing
                    DB::update(
                        "UPDATE contacts SET rag_indexed_at = NOW() WHERE id = ?",
                        [$contact->id]
                    );
                    $skipped++;
                }
            } catch (Exception $e) {
                Log::warning('ContactsPersistence: Failed to index contact', [
                    'contact_id' => $contact->id,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        return ['indexed' => $indexed, 'skipped' => $skipped, 'errors' => $errors, 'total' => count($contacts)];
    }

    /**
     * Re-index contacts that have been updated since last RAG indexing.
     *
     * @return array Re-indexing results
     */
    public function reindexUpdatedContacts(): array
    {
        $reindexed = 0;

        $contacts = DB::select(
            "SELECT id FROM contacts
             WHERE rag_indexed_at IS NOT NULL
               AND updated_at > rag_indexed_at
             LIMIT 100"
        );

        foreach ($contacts as $contact) {
            DB::update(
                "UPDATE contacts SET rag_indexed_at = NULL WHERE id = ?",
                [$contact->id]
            );
            $reindexed++;
        }

        if ($reindexed > 0) {
            Log::info("ContactsPersistence: Marked {$reindexed} updated contacts for re-indexing");
        }

        return ['marked_for_reindex' => $reindexed];
    }

    /**
     * Build RAG-optimized text content from a contact.
     * Formats for natural language queries like "what is John's email" or "who works at Acme".
     */
    private function buildRagContent(object $contact): string
    {
        $parts = [];

        $parts[] = "Contact: {$contact->full_name}";

        if (!empty($contact->nickname)) {
            $parts[] = "Nickname: {$contact->nickname}";
        }

        // Emails
        $emails = json_decode($contact->emails ?? '[]', true);
        if (!empty($emails)) {
            $flat = array_map(fn($e) => is_array($e) ? ($e['value'] ?? $e['email'] ?? json_encode($e)) : (string) $e, $emails);
            $parts[] = "Email: " . implode(', ', array_filter($flat));
        }

        // Phones
        $phones = json_decode($contact->phones ?? '[]', true);
        if (!empty($phones)) {
            $flat = array_map(fn($p) => is_array($p) ? ($p['value'] ?? $p['number'] ?? json_encode($p)) : (string) $p, $phones);
            $parts[] = "Phone: " . implode(', ', array_filter($flat));
        }

        // Organization
        if (!empty($contact->organization)) {
            $orgLine = "Organization: {$contact->organization}";
            if (!empty($contact->title)) {
                $orgLine .= " ({$contact->title})";
            }
            $parts[] = $orgLine;
        } elseif (!empty($contact->title)) {
            $parts[] = "Title: {$contact->title}";
        }

        // Addresses
        $addresses = json_decode($contact->addresses ?? '[]', true);
        if (!empty($addresses)) {
            foreach ($addresses as $addr) {
                if (is_string($addr)) {
                    $parts[] = "Address: {$addr}";
                } elseif (is_array($addr)) {
                    $formatted = implode(', ', array_filter($addr));
                    if (!empty($formatted)) {
                        $parts[] = "Address: {$formatted}";
                    }
                }
            }
        }

        // Birthday
        if (!empty($contact->birthday)) {
            $parts[] = "Birthday: {$contact->birthday}";
        }

        // Categories
        $categories = json_decode($contact->categories ?? '[]', true);
        if (!empty($categories)) {
            $parts[] = "Groups: " . implode(', ', $categories);
        }

        // Notes
        if (!empty($contact->notes)) {
            $parts[] = "";
            $parts[] = trim($contact->notes);
        }

        return implode("\n", $parts);
    }

    /**
     * Get sync statistics.
     */
    public function getStats(): array
    {
        $total = DB::selectOne("SELECT COUNT(*) as c FROM contacts")->c;
        $indexed = DB::selectOne("SELECT COUNT(*) as c FROM contacts WHERE rag_indexed_at IS NOT NULL")->c;
        $unindexed = $total - $indexed;
        $stale = DB::selectOne(
            "SELECT COUNT(*) as c FROM contacts WHERE rag_indexed_at IS NOT NULL AND updated_at > rag_indexed_at"
        )->c;

        $ragDocs = 0;
        try {
            $ragDocs = DB::connection('pgsql_rag')
                ->selectOne("SELECT COUNT(*) as c FROM rag_documents WHERE source_type = 'contact'")->c;
        } catch (\Throwable $e) {
            // PostgreSQL may be unavailable
        }

        return [
            'total_contacts' => (int) $total,
            'rag_indexed' => (int) $indexed,
            'rag_unindexed' => (int) $unindexed,
            'rag_stale' => (int) $stale,
            'rag_documents' => (int) $ragDocs,
        ];
    }
}
