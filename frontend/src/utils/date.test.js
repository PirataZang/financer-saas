import { test } from 'node:test'
import assert from 'node:assert/strict'
import { formatDate, sinceTime, timeAgo } from './date.js'

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

test('sinceTime goes from minutes to hours to the date', () => {
  const now = new Date(2026, 9, 27, 12, 0)
  assert.equal(sinceTime(new Date(2026, 9, 27, 11, 59, 40).toISOString(), now), 'agora')
  assert.equal(sinceTime(new Date(2026, 9, 27, 11, 48).toISOString(), now), 'há 12 min')
  assert.equal(sinceTime(new Date(2026, 9, 27, 9, 0).toISOString(), now), 'há 3 h')
  assert.equal(sinceTime(new Date(2026, 9, 25, 9, 5).toISOString(), now), '25/10, 09:05')
})
