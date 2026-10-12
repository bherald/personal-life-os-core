import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildAudioTtsCommand,
  buildRerankCommand,
} from './plos-offline-modalities.js';

test('audio TTS command base64-encodes text and stays dry-run by default', () => {
  const command = buildAudioTtsCommand({
    text: 'hello && touch /tmp/should-not-run',
    execute: false,
    probe: false,
    on_prod: false,
    voice: '',
    model: '',
    output: '',
  });

  assert.match(command, /php artisan 'audio:tts' '--json'/);
  assert.match(command, /'--text-b64=/);
  assert.match(command, /'--dry-run'/);
  assert.doesNotMatch(command, /touch \/tmp\/should-not-run/);
});

test('audio TTS probe does not require text', () => {
  const command = buildAudioTtsCommand({
    text: '',
    execute: false,
    probe: true,
    on_prod: false,
    voice: '',
    model: '',
    output: '',
  });

  assert.match(command, /'--probe'/);
  assert.match(command, /'--dry-run'/);
  assert.doesNotMatch(command, /--text-b64/);
});

test('rerank command base64-encodes query and documents', () => {
  const command = buildRerankCommand({
    query: 'genealogy transcription',
    documents: [
      { id: 'a', text: 'genealogy media transcription' },
      { id: 'b', text: 'weather report' },
    ],
    limit: 4,
    use_embeddings: false,
    on_prod: false,
  });

  assert.match(command, /php artisan 'rag:rerank' '--json'/);
  assert.match(command, /'--query-b64=/);
  assert.match(command, /'--documents-json-b64=/);
  assert.match(command, /'--limit=4'/);
  assert.match(command, /'--no-embeddings'/);
  assert.doesNotMatch(command, /genealogy transcription/);
});
