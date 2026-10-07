import { test } from 'node:test'
import assert from 'node:assert/strict'
import { PERIODS, periodRange, toDateString } from './period.js'

const today = new Date(2026, 0, 15, 23, 30) // 15/jan, late night

test('presets resolve to local inclusive ranges', () => {
  assert.deepEqual(periodRange('today', today), { from: '2026-01-15', to: '2026-01-15' })
  assert.deepEqual(periodRange('7d', today), { from: '2026-01-09', to: '2026-01-15' })
  assert.deepEqual(periodRange('30d', today), { from: '2025-12-17', to: '2026-01-15' })
  assert.deepEqual(periodRange('month', today), { from: '2026-01-01', to: '2026-01-15' })
  assert.deepEqual(periodRange('3m', today), { from: '2025-11-01', to: '2026-01-15' })
  assert.deepEqual(periodRange('year', today), { from: '2026-01-01', to: '2026-01-15' })
  assert.equal(periodRange('custom', today), null)
})

test('every preset except custom has a range', () => {
  for (const { value } of PERIODS) assert.equal(periodRange(value, today) === null, value === 'custom', value)
})

test('dates are local, never UTC-shifted', () => {
  assert.equal(toDateString(new Date(2026, 9, 7, 23, 59)), '2026-10-07')
})
