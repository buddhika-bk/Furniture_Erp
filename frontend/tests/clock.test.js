import test from 'node:test';
import assert from 'node:assert/strict';
import {sriLankaDate, clockText} from '../src/components/clock.js';

test('Sri Lanka clock crosses midnight at 18:30 UTC, independent of device timezone', () => {
  const before=new Date('2026-11-04T18:29:59Z'), after=new Date('2026-11-04T18:30:00Z');
  assert.equal(sriLankaDate(before),'2026-11-04');
  assert.equal(clockText(before),'23:59:59');
  assert.equal(sriLankaDate(after),'2026-11-05');
  assert.equal(clockText(after),'00:00:00');
});

test('Sri Lanka date handles year boundaries', () => {
  assert.equal(sriLankaDate(new Date('2026-12-31T20:00:00Z')),'2027-01-01');
  assert.equal(clockText(new Date('2026-12-31T20:00:00Z')),'01:30:00');
});
