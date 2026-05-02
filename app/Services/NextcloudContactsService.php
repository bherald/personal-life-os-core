<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Exception;

/**
 * Nextcloud Contacts Service
 *
 * Direct integration with Nextcloud Contacts via CardDAV API.
 * Provides contact management without relying on external packages.
 */
class NextcloudContactsService
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private const HTTP_CONNECT_TIMEOUT = 5;
    private const HTTP_TIMEOUT = 120;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.nextcloud.url', ''), '/');
        $this->username = (string) config('services.nextcloud.username', '');
        $this->password = config('services.nextcloud.password') ?? '';
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth($this->username, $this->password);
    }

    /**
     * Get list of available address books
     *
     * @return array Address books
     */
    public function getAddressBooks(): array
    {
        $url = "{$this->baseUrl}/remote.php/dav/addressbooks/users/{$this->username}/";

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
    <card:addressbook-description />
  </d:prop>
</d:propfind>
XML;

        try {
            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '1',
                ])
                ->send('PROPFIND', $url, ['body' => $xml]);

            if (!$response->successful()) {
                throw new Exception("PROPFIND request failed: " . $response->status());
            }

            return $this->parseAddressBooksResponse($response->body());

        } catch (Exception $e) {
            throw new Exception("Failed to get address books: " . $e->getMessage());
        }
    }

    /**
     * Parse PROPFIND response for address books
     */
    private function parseAddressBooksResponse(string $xml): array
    {
        $addressBooks = [];

        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $loaded = $dom->loadXML($xml);

            if (!$loaded) {
                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $hrefNode = $xpath->query('d:href', $response)->item(0);
                if (!$hrefNode) continue;

                $href = $hrefNode->nodeValue;

                $displaynameNode = $xpath->query('d:propstat/d:prop/d:displayname', $response)->item(0);
                $displayname = $displaynameNode ? $displaynameNode->nodeValue : '';

                // Check if it's an address book
                $addressbookNode = $xpath->query('d:propstat/d:prop/d:resourcetype/card:addressbook', $response)->item(0);
                $isAddressBook = $addressbookNode !== null;

                if ($isAddressBook && !empty($displayname)) {
                    $name = basename(rtrim($href, '/'));

                    $descriptionNode = $xpath->query('d:propstat/d:prop/card:addressbook-description', $response)->item(0);
                    $description = $descriptionNode ? $descriptionNode->nodeValue : '';

                    $addressBooks[] = [
                        'name' => $name,
                        'displayName' => $displayname,
                        'description' => $description,
                        'href' => $href,
                    ];
                }
            }

        } catch (Exception $e) {
            \Log::warning('Failed to parse address books XML', ['error' => $e->getMessage()]);
        }

        return $addressBooks;
    }

    /**
     * Get contacts from an address book
     *
     * @param string|null $addressBookName Address book name (defaults to first available)
     * @param int $limit Maximum number of contacts to return
     * @return array Contacts
     */
    public function getContacts(?string $addressBookName = null, int $limit = 100): array
    {
        // Get available address books
        $addressBooks = $this->getAddressBooks();
        if (empty($addressBooks)) {
            throw new Exception('No address books found');
        }

        // Use specified address book or first available
        if (!$addressBookName) {
            $addressBookName = $addressBooks[0]['name'];
        } else {
            // Check if specified address book exists
            $found = false;
            foreach ($addressBooks as $ab) {
                if ($ab['name'] === $addressBookName || $ab['displayName'] === $addressBookName) {
                    $addressBookName = $ab['name'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $addressBookName = $addressBooks[0]['name'];
            }
        }

        $url = "{$this->baseUrl}/remote.php/dav/addressbooks/users/{$this->username}/{$addressBookName}/";

        // CardDAV addressbook-query to get all contacts
        $xml = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
  <d:prop>
    <d:getetag />
    <card:address-data />
  </d:prop>
</card:addressbook-query>
XML;

        try {
            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '1',
                ])
                ->send('REPORT', $url, ['body' => $xml]);

            if (!$response->successful()) {
                throw new Exception("CardDAV request failed: " . $response->status());
            }

            return $this->parseContactsResponse($response->body(), $limit);

        } catch (Exception $e) {
            throw new Exception("Failed to get contacts: " . $e->getMessage());
        }
    }

    /**
     * Parse CardDAV XML response into contacts array
     */
    private function parseContactsResponse(string $xml, int $limit): array
    {
        $contacts = [];

        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $loaded = $dom->loadXML($xml);

            if (!$loaded) {
                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $responses = $xpath->query('//d:response');
            $count = 0;

            foreach ($responses as $response) {
                if ($count >= $limit) break;

                $addressDataNode = $xpath->query('d:propstat/d:prop/card:address-data', $response)->item(0);
                if (!$addressDataNode) continue;

                $vcard = $addressDataNode->nodeValue;
                $contact = $this->parseVCard($vcard);

                if ($contact) {
                    $hrefNode = $xpath->query('d:href', $response)->item(0);
                    if ($hrefNode) {
                        $contact['href'] = $hrefNode->nodeValue;
                        $contact['uid'] = basename(rtrim($hrefNode->nodeValue, '/'));
                    }

                    $contacts[] = $contact;
                    $count++;
                }
            }

        } catch (Exception $e) {
            \Log::warning('Failed to parse contacts XML', ['error' => $e->getMessage()]);
        }

        return $contacts;
    }

    /**
     * Parse vCard data into contact array
     */
    private function parseVCard(string $vcard): ?array
    {
        $lines = explode("\n", $vcard);
        $contact = [];
        $contact['rawVcard'] = $vcard;

        // Unfold line continuations first (lines starting with space/tab)
        $unfolded = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if (!empty($line) && ($line[0] === ' ' || $line[0] === "\t")) {
                // Continuation of previous line
                if (!empty($unfolded)) {
                    $unfolded[count($unfolded) - 1] .= substr($line, 1);
                }
            } else {
                $unfolded[] = $line;
            }
        }

        foreach ($unfolded as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $this->parseVCardLine($line, $contact);
            }
        }

        return !empty($contact) ? $contact : null;
    }

    /**
     * Parse a single vCard line
     */
    private function parseVCardLine(string $line, array &$contact): void
    {
        // Split on first colon
        $colonPos = strpos($line, ':');
        if ($colonPos === false) return;

        $property = substr($line, 0, $colonPos);
        $value = substr($line, $colonPos + 1);

        // Remove parameters (e.g., "TEL;TYPE=CELL" becomes "TEL")
        $semiPos = strpos($property, ';');
        if ($semiPos !== false) {
            $property = substr($property, 0, $semiPos);
        }

        switch ($property) {
            case 'FN':
                $contact['name'] = $this->unescapeVCardValue($value);
                break;
            case 'N':
                // Format: Family;Given;Additional;Prefix;Suffix
                $parts = explode(';', $value);
                $contact['familyName'] = $this->unescapeVCardValue($parts[0] ?? '');
                $contact['givenName'] = $this->unescapeVCardValue($parts[1] ?? '');
                break;
            case 'NICKNAME':
                $contact['nickname'] = $this->unescapeVCardValue($value);
                break;
            case 'EMAIL':
                if (!isset($contact['emails'])) {
                    $contact['emails'] = [];
                }
                $contact['emails'][] = $value;
                break;
            case 'TEL':
                if (!isset($contact['phones'])) {
                    $contact['phones'] = [];
                }
                $contact['phones'][] = $value;
                break;
            case 'ADR':
                // Format: PO Box;Ext Addr;Street;City;Region;Postal;Country
                if (!isset($contact['addresses'])) {
                    $contact['addresses'] = [];
                }
                $addrParts = explode(';', $value);
                $formatted = array_filter(array_map(fn($p) => trim($this->unescapeVCardValue($p)), $addrParts));
                if (!empty($formatted)) {
                    $contact['addresses'][] = implode(', ', $formatted);
                }
                break;
            case 'ORG':
                $contact['organization'] = $this->unescapeVCardValue($value);
                break;
            case 'TITLE':
                $contact['title'] = $this->unescapeVCardValue($value);
                break;
            case 'BDAY':
                $contact['birthday'] = $value;
                break;
            case 'NOTE':
                $contact['note'] = $this->unescapeVCardValue($value);
                break;
            case 'CATEGORIES':
                $contact['categories'] = array_map(
                    fn($c) => trim($this->unescapeVCardValue($c)),
                    explode(',', $value)
                );
                break;
            case 'UID':
                $contact['vcardUid'] = $value;
                break;
        }
    }

    /**
     * Search contacts by query string
     *
     * @param string $query Search query
     * @param string|null $addressBookName Address book name
     * @return array Matching contacts
     */
    public function searchContacts(string $query, ?string $addressBookName = null): array
    {
        // Get all contacts and filter in PHP
        // CardDAV search is complex and not well-supported, so we do client-side filtering
        $contacts = $this->getContacts($addressBookName, 500);

        $query = strtolower($query);
        $results = [];

        foreach ($contacts as $contact) {
            $searchableText = strtolower(implode(' ', [
                $contact['name'] ?? '',
                $contact['givenName'] ?? '',
                $contact['familyName'] ?? '',
                $contact['organization'] ?? '',
                implode(' ', $contact['emails'] ?? []),
                implode(' ', $contact['phones'] ?? []),
            ]));

            if (str_contains($searchableText, $query)) {
                $results[] = $contact;
            }
        }

        return $results;
    }

    /**
     * Get contact statistics
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        $addressBooks = $this->getAddressBooks();
        $totalContacts = 0;

        foreach ($addressBooks as $ab) {
            try {
                $contacts = $this->getContacts($ab['name'], 1000);
                $totalContacts += count($contacts);
            } catch (Exception $e) {
                // Skip address books that fail
            }
        }

        return [
            'addressBooks' => count($addressBooks),
            'totalContacts' => $totalContacts,
        ];
    }

    /**
     * Create or update a contact via CardDAV
     *
     * @param array $contact Contact data (name, familyName, givenName, emails, phones, birthday, groups, notes)
     * @param string|null $addressBookName Address book name (defaults to first available)
     * @param string|null $uid Existing contact UID to update (null to create new)
     * @return array Created/updated contact info
     */
    public function createOrUpdateContact(array $contact, ?string $addressBookName = null, ?string $uid = null): array
    {
        // Get available address books
        $addressBooks = $this->getAddressBooks();
        if (empty($addressBooks)) {
            throw new Exception('No address books found');
        }

        // Use specified address book or first available
        if (!$addressBookName) {
            $addressBookName = $addressBooks[0]['name'];
        }

        // Generate UID if not provided
        if (!$uid) {
            $uid = strtoupper(bin2hex(random_bytes(16))) . '.vcf';
        } elseif (!str_ends_with($uid, '.vcf')) {
            $uid .= '.vcf';
        }

        $url = "{$this->baseUrl}/remote.php/dav/addressbooks/users/{$this->username}/{$addressBookName}/{$uid}";

        // Build vCard
        $vcard = $this->buildVCard($contact);

        try {
            $response = $this->http()
                ->withBody($vcard, 'text/vcard; charset=utf-8')
                ->put($url);

            if (!$response->successful() && $response->status() !== 201 && $response->status() !== 204) {
                throw new Exception("CardDAV PUT failed: " . $response->status() . " - " . $response->body());
            }

            return [
                'success' => true,
                'uid' => $uid,
                'addressBook' => $addressBookName,
                'href' => "/remote.php/dav/addressbooks/users/{$this->username}/{$addressBookName}/{$uid}",
            ];

        } catch (Exception $e) {
            throw new Exception("Failed to create/update contact: " . $e->getMessage());
        }
    }

    /**
     * Delete a contact via CardDAV
     *
     * @param string $uid Contact UID
     * @param string|null $addressBookName Address book name
     * @return bool Success
     */
    public function deleteContact(string $uid, ?string $addressBookName = null): bool
    {
        // Get available address books
        $addressBooks = $this->getAddressBooks();
        if (empty($addressBooks)) {
            throw new Exception('No address books found');
        }

        // Use specified address book or first available
        if (!$addressBookName) {
            $addressBookName = $addressBooks[0]['name'];
        }

        if (!str_ends_with($uid, '.vcf')) {
            $uid .= '.vcf';
        }

        $url = "{$this->baseUrl}/remote.php/dav/addressbooks/users/{$this->username}/{$addressBookName}/{$uid}";

        try {
            $response = $this->http()
                ->delete($url);

            return $response->successful() || $response->status() === 204 || $response->status() === 404;

        } catch (Exception $e) {
            \Log::warning('Failed to delete contact', ['uid' => $uid, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Build vCard string from contact data
     */
    private function buildVCard(array $contact): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
        ];

        // UID
        $uid = $contact['uid'] ?? strtoupper(bin2hex(random_bytes(16)));
        $lines[] = 'UID:' . $uid;

        // Full name
        $fullName = $contact['name'] ?? trim(($contact['givenName'] ?? '') . ' ' . ($contact['familyName'] ?? ''));
        if ($fullName) {
            $lines[] = 'FN:' . $this->escapeVCardValue($fullName);
        }

        // Structured name: Family;Given;Additional;Prefix;Suffix
        $familyName = $contact['familyName'] ?? '';
        $givenName = $contact['givenName'] ?? '';
        $lines[] = 'N:' . $this->escapeVCardValue($familyName) . ';' . $this->escapeVCardValue($givenName) . ';;;';

        // Birthday (BDAY)
        if (!empty($contact['birthday'])) {
            $birthday = $contact['birthday'];
            // Convert to YYYYMMDD or YYYY-MM-DD format
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $birthday)) {
                $lines[] = 'BDAY:' . str_replace('-', '', substr($birthday, 0, 10));
            }
        }

        // Emails
        if (!empty($contact['emails'])) {
            foreach ((array)$contact['emails'] as $email) {
                $lines[] = 'EMAIL:' . $this->escapeVCardValue($email);
            }
        }

        // Phones
        if (!empty($contact['phones'])) {
            foreach ((array)$contact['phones'] as $phone) {
                $lines[] = 'TEL:' . $this->escapeVCardValue($phone);
            }
        }

        // Note
        if (!empty($contact['note'])) {
            $lines[] = 'NOTE:' . $this->escapeVCardValue($contact['note']);
        }

        // Categories/Groups
        if (!empty($contact['groups'])) {
            $groups = is_array($contact['groups']) ? implode(',', $contact['groups']) : $contact['groups'];
            $lines[] = 'CATEGORIES:' . $this->escapeVCardValue($groups);
        }

        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines);
    }

    /**
     * Unescape vCard values (reverse of escapeVCardValue)
     */
    private function unescapeVCardValue(string $value): string
    {
        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\;', ';', $value);
        $value = str_replace('\\\\', '\\', $value);
        return $value;
    }

    /**
     * Escape special characters for vCard values
     */
    private function escapeVCardValue(string $value): string
    {
        // Escape backslashes, semicolons, commas, and newlines
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace(',', '\\,', $value);
        $value = str_replace("\n", '\\n', $value);
        return $value;
    }
}
