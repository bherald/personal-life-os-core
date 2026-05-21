<?php

namespace App\Services\Genealogy\SourceAudit;

use Illuminate\Support\Facades\DB;

class SourceAuditWorkbookTagResolver
{
    /**
     * @return array{success: bool, tag?: string, record_type?: string, record_id?: int, tree_id?: int, label?: string, record_url?: string, person_id?: int|null, family_id?: int|null, error?: string}
     */
    public function resolveTag(int $treeId, string $tag): array
    {
        $parsed = $this->parseTag($tag);
        if ($parsed === null) {
            return ['success' => false, 'error' => 'invalid_workbook_tag'];
        }

        return $this->resolveRecord($treeId, $parsed['record_type'], $parsed['number'], $parsed['tag']);
    }

    /**
     * @return array{record_type: string, prefix: string, number: int, tag: string}|null
     */
    public function parseTag(string $tag): ?array
    {
        $tag = strtoupper(trim($tag));
        $tag = trim($tag, "#@ \t\n\r\0\x0B");

        if (preg_match('/^([IF])0*([0-9]{1,12})$/', $tag, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[2];
        if ($number <= 0) {
            return null;
        }

        $prefix = strtoupper($matches[1]);

        return [
            'record_type' => $prefix === 'I' ? 'person' : 'family',
            'prefix' => $prefix,
            'number' => $number,
            'tag' => '#'.$prefix.$number.'#',
        ];
    }

    /**
     * @return list<array{record_type: string, prefix: string, number: int, tag: string}>
     */
    public function extractTags(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/#?\b([IF])0*([0-9]{1,12})\b#?/i', $text, $matches, PREG_SET_ORDER);

        $tags = [];
        foreach ($matches as $match) {
            $parsed = $this->parseTag($match[0]);
            if ($parsed !== null) {
                $tags[$parsed['tag']] = $parsed;
            }
        }

        return array_values($tags);
    }

    /**
     * @return array{success: bool, tag?: string, record_type?: string, record_id?: int, tree_id?: int, label?: string, record_url?: string, person_id?: int|null, family_id?: int|null, error?: string}|null
     */
    public function resolveFirstTagInText(int $treeId, string $text): ?array
    {
        foreach ($this->extractTags($text) as $tag) {
            $resolved = $this->resolveRecord($treeId, $tag['record_type'], $tag['number'], $tag['tag']);
            if ($resolved['success'] ?? false) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array{success: bool, tag?: string, record_type?: string, record_id?: int, tree_id?: int, label?: string, record_url?: string, person_id?: int|null, family_id?: int|null, error?: string}
     */
    public function resolveRecord(int $treeId, string $recordType, int $number, ?string $preferredTag = null): array
    {
        $recordType = strtolower(trim($recordType));
        if (! in_array($recordType, ['person', 'family'], true) || $number <= 0) {
            return ['success' => false, 'error' => 'invalid_workbook_record'];
        }

        return $recordType === 'person'
            ? $this->resolvePerson($treeId, $number, $preferredTag)
            : $this->resolveFamily($treeId, $number, $preferredTag);
    }

    /**
     * @return array{success: bool, tag: string, record_type: string, record_id: int, tree_id: int, label: string, record_url: string, person_id: int, family_id: null}|array{success: false, error: string}
     */
    private function resolvePerson(int $treeId, int $number, ?string $preferredTag): array
    {
        $gedcomId = 'I'.$number;
        $person = DB::table('genealogy_persons')
            ->where('tree_id', $treeId)
            ->where(function ($query) use ($number, $gedcomId): void {
                $query->where('gedcom_id', $gedcomId)
                    ->orWhere('id', $number);
            })
            ->orderByRaw('CASE WHEN gedcom_id = ? THEN 0 ELSE 1 END', [$gedcomId])
            ->orderBy('id')
            ->first();

        if (! $person) {
            return ['success' => false, 'error' => 'person_tag_not_found'];
        }

        $personId = (int) $person->id;

        return [
            'success' => true,
            'tag' => $preferredTag ?: '#I'.$number.'#',
            'record_type' => 'person',
            'record_id' => $personId,
            'tree_id' => $treeId,
            'label' => $this->personLabel($person),
            'record_url' => url('/genealogy?person='.$personId),
            'person_id' => $personId,
            'family_id' => null,
        ];
    }

    /**
     * @return array{success: bool, tag: string, record_type: string, record_id: int, tree_id: int, label: string, record_url: string, person_id: int|null, family_id: int}|array{success: false, error: string}
     */
    private function resolveFamily(int $treeId, int $number, ?string $preferredTag): array
    {
        $gedcomId = 'F'.$number;
        $family = DB::table('genealogy_families')
            ->where('tree_id', $treeId)
            ->where(function ($query) use ($number, $gedcomId): void {
                $query->where('gedcom_id', $gedcomId)
                    ->orWhere('id', $number);
            })
            ->orderByRaw('CASE WHEN gedcom_id = ? THEN 0 ELSE 1 END', [$gedcomId])
            ->orderBy('id')
            ->first();

        if (! $family) {
            return ['success' => false, 'error' => 'family_tag_not_found'];
        }

        $husband = $family->husband_id
            ? DB::table('genealogy_persons')->where('tree_id', $treeId)->where('id', (int) $family->husband_id)->first()
            : null;
        $wife = $family->wife_id
            ? DB::table('genealogy_persons')->where('tree_id', $treeId)->where('id', (int) $family->wife_id)->first()
            : null;
        $personId = (int) ($family->husband_id ?: ($family->wife_id ?: 0));

        return [
            'success' => true,
            'tag' => $preferredTag ?: '#F'.$number.'#',
            'record_type' => 'family',
            'record_id' => (int) $family->id,
            'tree_id' => $treeId,
            'label' => trim($this->personLabel($husband).' + '.$this->personLabel($wife), ' +') ?: 'Family '.$family->id,
            'record_url' => $personId > 0 ? url('/genealogy?person='.$personId) : url('/genealogy'),
            'person_id' => $personId > 0 ? $personId : null,
            'family_id' => (int) $family->id,
        ];
    }

    private function personLabel(?object $person): string
    {
        if (! $person) {
            return '';
        }

        return trim(implode(' ', array_filter([
            (string) ($person->given_name ?? ''),
            (string) ($person->surname ?? ''),
        ], static fn (string $part): bool => trim($part) !== '')));
    }
}
