import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildImageGenerateCommand,
  buildMediaAnalyzeCommand,
  plosImageGenerateInput,
  plosMediaAnalyzeInput,
} from './plos-offline-media.js';

test('media analyze command safely encodes prompt and stays dry-run by default', () => {
  const input = plosMediaAnalyzeInput.parse({
    path: '/tmp/family photo.jpg',
    prompt: 'describe && touch /tmp/should-not-run',
    model: 'qwen3.5:9b',
  });
  const command = buildMediaAnalyzeCommand(input);

  assert.match(command, /php artisan 'media:offline-analyze' '--json' '--path=\/tmp\/family photo\.jpg'/);
  assert.match(command, /'--prompt-b64=/);
  assert.match(command, /'--model=qwen3\.5:9b'/);
  assert.match(command, /'--dry-run'/);
  assert.doesNotMatch(command, /touch \/tmp\/should-not-run/);
  assert.doesNotMatch(command, /--url|--token/);
});

test('media analyze execute mode requires the explicit execute gate', () => {
  const command = buildMediaAnalyzeCommand(plosMediaAnalyzeInput.parse({
    path: '/tmp/input.png',
    execute: true,
  }));

  assert.match(command, /'--execute'/);
  assert.doesNotMatch(command, /'--dry-run'/);
});

test('image generate command safely encodes prompt and forwards bounded controls', () => {
  const input = plosImageGenerateInput.parse({
    prompt: 'a fox; curl https://invalid.example',
    width: 768,
    height: 512,
    steps: 6,
    guidance_scale: 3.5,
    model: 'pixart-sigma-1024',
    seed: 271828,
    execute: true,
  });
  const command = buildImageGenerateCommand(input);

  assert.match(command, /php artisan 'media:image-generate' '--json'/);
  assert.match(command, /'--prompt-b64=/);
  assert.match(command, /'--model=pixart-sigma-1024'/);
  assert.match(command, /'--width=768'/);
  assert.match(command, /'--height=512'/);
  assert.match(command, /'--steps=6'/);
  assert.match(command, /'--guidance-scale=3\.5'/);
  assert.match(command, /'--seed=271828'/);
  assert.match(command, /'--execute'/);
  assert.doesNotMatch(command, /curl https:\/\/invalid\.example/);
  assert.doesNotMatch(command, /--url|--token/);
});

test('image generate is dry-run by default and omits an unspecified seed', () => {
  const command = buildImageGenerateCommand(plosImageGenerateInput.parse({
    prompt: 'watercolor landscape',
  }));

  assert.match(command, /'--width=1024'/);
  assert.match(command, /'--height=1024'/);
  assert.match(command, /'--model=sdxl-lightning'/);
  assert.doesNotMatch(command, /--steps=/);
  assert.doesNotMatch(command, /--guidance-scale=/);
  assert.match(command, /'--dry-run'/);
  assert.doesNotMatch(command, /--seed=/);
});

test('image generate rejects dimensions outside the Artisan contract', () => {
  assert.throws(() => plosImageGenerateInput.parse({
    prompt: 'invalid dimensions',
    width: 300,
  }));
  assert.throws(() => plosImageGenerateInput.parse({
    prompt: 'oversized dimensions',
    height: 1088,
  }));
  assert.throws(() => plosImageGenerateInput.parse({
    prompt: 'invalid seed',
    seed: 4_294_967_296,
  }));
});

test('image generate accepts the operator-approved private SANA candidate', () => {
  const input = plosImageGenerateInput.parse({
    prompt: 'experimental watercolor study',
    model: 'sana-sprint-1.6b',
  });

  assert.equal(input.model, 'sana-sprint-1.6b');
  assert.match(buildImageGenerateCommand(input), /'--model=sana-sprint-1\.6b'/);
});
