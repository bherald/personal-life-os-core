import assert from 'node:assert/strict';
import test from 'node:test';
import { ArtisanExecutor } from '../dist/integrations/artisan.js';

test('MCP exposes observation command without admitting the fixture scanner', () => {
  const executor = new ArtisanExecutor();
  assert.equal(executor.isCommandAllowed('os-tools:inventory'), true);
  assert.equal(executor.isCommandAllowed('os-tools:discover'), true);
  assert.equal(executor.isCommandAllowed('os-tools:review'), true);
  assert.equal(executor.isCommandAllowed('os-tools:review-enable'), false);
  assert.equal(executor.isCommandAllowed('os-tools:discover;id'), false);
  assert.equal(executor.isCommandAllowed('os-tools:fixture'), false);
  assert.equal(executor.isCommandAllowed('migrate'), false);
  assert.equal(executor.getAvailableCommands().filter(row => row.command === 'os-tools:inventory').length, 1);
});
