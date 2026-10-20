import { test } from 'node:test'
import assert from 'node:assert/strict'
import { formatDate, timeAgo } from './date.js'

test('formatDate keeps date-only values on their day', () => {
  assert.equal(formatDate('2026-10-07'), '07/10/2026')
  assert.equal(formatDate(null), '')
})

test('timeAgo counts calendar days', () => {
  const now = new Date(2026, 9, 20, 9, 0)
  assert.equal(timeAgo(new Date(2026, 9, 20, 8, 0).toISOString(), now), 'hoje')
  assert.equal(timeAgo(new Date(2026, 9, 19, 23, 0).toISOString(), now), 'ontem')
  assert.equal(timeAgo(new Date(2026, 9, 15, 12, 0).toISOString(), now), 'há 5 dias')
})
