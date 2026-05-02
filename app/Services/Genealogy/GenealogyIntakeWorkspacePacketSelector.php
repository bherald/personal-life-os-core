<?php

namespace App\Services\Genealogy;

class GenealogyIntakeWorkspacePacketSelector
{
    /**
     * Select a single packet from a run snapshot and composed workspace, returning a normalized bundle.
     * Pure, deterministic, non-mutating. Returns null if no match found.
     */
    public function selectPacket(array $run, array $workspace, ?string $packetKey = null, ?string $packetLabel = null): ?array
    {
        $key = trim((string) ($packetKey ?? ''));
        $label = trim((string) ($packetLabel ?? ''));

        if ($key === '' && $label === '') {
            return null;
        }

        $packet = self::findInPackets((array) ($run['packets'] ?? []), $key, $label);
        if ($packet === null) {
            return null;
        }

        $queueEntry = self::findInBuckets((array) ($workspace['queue'] ?? []), $key, $label);
        $draftEntry = self::findInBuckets((array) ($workspace['draft_plan'] ?? []), $key, $label);

        // Prefer queue presentation/action, fall back to draft
        $presentation = ($queueEntry['presentation'] ?? null) ?? ($draftEntry['presentation'] ?? null);
        $action = ($queueEntry['action'] ?? null) ?? ($draftEntry['action'] ?? null);

        return [
            'packet' => $packet,
            'queue_entry' => $queueEntry,
            'draft_entry' => $draftEntry,
            'presentation' => $presentation,
            'action' => $action,
        ];
    }

    private static function findInPackets(array $packets, string $key, string $label): ?array
    {
        // First pass: match by key
        if ($key !== '') {
            foreach ($packets as $packet) {
                if ((string) ($packet['packet_key'] ?? '') === $key) {
                    return $packet;
                }
            }
        }

        // Second pass: match by label
        if ($label !== '') {
            foreach ($packets as $packet) {
                if ((string) ($packet['packet_label'] ?? '') === $label) {
                    return $packet;
                }
            }
        }

        return null;
    }

    private static function findInBuckets(array $section, string $key, string $label): ?array
    {
        foreach (['ready_packets', 'blocked_packets', 'pending_packets'] as $bucket) {
            foreach ((array) ($section[$bucket] ?? []) as $entry) {
                $entryKey = (string) ($entry['packet_key'] ?? '');
                $entryLabel = (string) ($entry['packet_label'] ?? '');

                if ($key !== '' && $entryKey === $key) {
                    return $entry;
                }
                if ($label !== '' && $entryLabel === $label) {
                    return $entry;
                }
            }
        }

        return null;
    }
}
