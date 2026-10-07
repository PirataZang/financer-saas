import { test } from 'node:test'
import assert from 'node:assert/strict'
import { MASKS } from './masks.js'

test('phone: shows formatted, sends digits only (10 or 11)', () => {
  assert.equal(MASKS.phone.format('47999990000'), '(47) 99999-0000')
  assert.equal(MASKS.phone.format('4733334444'), '(47) 3333-4444')
  assert.equal(MASKS.phone.unmask('(47) 99999-0000'), '47999990000')
  assert.equal(MASKS.phone.format('479999900001234'), '(47) 99999-0000')
})

test('cep: shows 00000-000, sends 8 digits', () => {
  assert.equal(MASKS.cep.format('89160000'), '89160-000')
  assert.equal(MASKS.cep.unmask('89160-000'), '89160000')
  assert.equal(MASKS.cep.format('891600001'), '89160-000')
})
