import { test } from 'node:test'
import assert from 'node:assert/strict'
import { contrastWithWhite } from './theme.js'

test('contrast of white text: default blue passes AA, light colors fail', () => {
  assert.ok(contrastWithWhite('#2563eb') >= 4.5)
  assert.ok(contrastWithWhite('#000000') > 20)
  assert.ok(contrastWithWhite('#facc15') < 3)
  assert.equal(Math.round(contrastWithWhite('#ffffff')), 1)
})
