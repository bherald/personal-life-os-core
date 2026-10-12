<?php

namespace App\Services\UnifiedCli;

/** Read-only saved display. Never restores process ownership or model context. */
final class CliNativePresentation
{
    public function __construct(private readonly string $stateDirectory) {}

    public function turns(array $session): array
    {
        $turns = $session['turns'];
        $directory = $this->stateDirectory.'/native-processes';
        if (! is_dir($directory) || is_link($directory)) {
            return $turns;
        }
        $indices = array_flip(array_column($turns, 'id'));
        $groups = [];
        foreach (glob($directory.'/*.ui.jsonl') ?: [] as $file) {
            if (is_link($file) || ! is_file($file)
                || ! preg_match('/^([a-f0-9-]{36})\.ui\.jsonl$/D', basename($file), $match)) {
                continue;
            }
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            $items = [];
            $completed = [];
            $turnId = null;
            $time = null;
            $damaged = false;
            try {
                while (($line = fgets($handle)) !== false) {
                    $event = json_decode($line, true);
                    $params = $event['params'] ?? null;
                    if (! str_ends_with($line, "\n") || ! is_array($params)) {
                        $damaged = true;
                        break;
                    }
                    if (($params['threadId'] ?? null) !== $session['id']
                        || ! isset($indices[$params['turnId'] ?? ''])
                        || ($turnId !== null && $turnId !== $params['turnId'])) {
                        $items = [];
                        break;
                    }
                    $turnId = $params['turnId'];
                    $time ??= $event['recorded_at'] ?? 0;
                    $method = $event['method'] ?? '';
                    if (in_array($method, ['item/started', 'item/completed'], true)) {
                        $item = $params['item'] ?? [];
                        $id = $item['id'] ?? '';
                        $valid = ($id === $match[1] && ($item['type'] ?? '') === 'commandExecution')
                            || ($id === $match[1].'-host' && ($item['type'] ?? '') === 'agentMessage');
                        if (! $valid || ($method === 'item/started' && isset($items[$id]))) {
                            $damaged = true;
                            break;
                        }
                        $items[$id] = $item;
                        $completed[$id] = $method === 'item/completed';
                    } elseif ($method === 'item/commandExecution/outputDelta'
                        && ($params['itemId'] ?? '') === $match[1]
                        && isset($items[$match[1]]) && is_string($params['delta'] ?? null)) {
                        $items[$match[1]]['aggregatedOutput'] .= $params['delta'];
                    } else {
                        $damaged = true;
                        break;
                    }
                }
            } finally {
                fclose($handle);
            }
            foreach ($items as $id => &$item) {
                if (($item['type'] ?? '') === 'commandExecution' && ($damaged || ! ($completed[$id] ?? false))) {
                    // A saved event is not evidence of a current live process.
                    $item = ['type' => 'agentMessage', 'id' => $id,
                        'text' => 'Saved native command: '.($item['command'] ?? '')."\n"
                            .($item['aggregatedOutput'] ?? '')."\n"
                            .'Outcome unverified in this saved display; inspect the original process journal. No action was replayed.'];
                }
            }
            unset($item);
            if ($items !== []) {
                $groups[] = ['turn' => $turnId, 'time' => $time, 'items' => array_values($items)];
            }
        }
        usort($groups, fn ($a, $b) => $a['time'] <=> $b['time']);
        $insertions = [];
        foreach ($groups as $group) {
            $index = $indices[$group['turn']];
            $known = array_column($turns[$index]['items'], 'id');
            $items = array_values(array_filter($group['items'], fn ($item) => ! in_array($item['id'], $known, true)));
            $offset = $insertions[$index] ?? 1;
            array_splice($turns[$index]['items'], $offset, 0, $items);
            $insertions[$index] = $offset + count($items);
        }

        return $turns;
    }
}
