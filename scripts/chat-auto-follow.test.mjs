import assert from 'node:assert/strict';
import test from 'node:test';

import {
  CHAT_BOTTOM_THRESHOLD_PX,
  isNearScrollBottom,
  shouldAutoFollow,
} from '../resources/js/src/utils/chatAutoFollow.js';

test('near-bottom detection uses a stable pixel threshold', () => {
  assert.equal(CHAT_BOTTOM_THRESHOLD_PX, 32);
  assert.equal(isNearScrollBottom({ scrollHeight: 1000, scrollTop: 568, clientHeight: 400 }), true);
  assert.equal(isNearScrollBottom({ scrollHeight: 1000, scrollTop: 567, clientHeight: 400 }), false);
});

test('auto-follow does not fight operator or active scrolling', () => {
  assert.equal(shouldAutoFollow({ isFollowing: true }), true);
  assert.equal(shouldAutoFollow({ isFollowing: true, userScrolledUp: true }), false);
  assert.equal(shouldAutoFollow({ isFollowing: true, activeScroll: true }), false);
  assert.equal(shouldAutoFollow({ isFollowing: false }), false);
});
