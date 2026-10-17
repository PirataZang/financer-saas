import { test } from 'node:test'
import assert from 'node:assert/strict'
import { MASKS } from './masks.js'

test('phone: shows formatted, sends digits only (10 or 11)', () => {
  assert.equal(MASKS.phone.format('47999990000'), '(47) 99999-0000')
  assert.equal(MASKS.phone.format('4733334444'), '(47) 3333-4444')
  assert.equal(MASKS.phone.unmask('(47) 99999-0000'), '47999990000')
  assert.equal(MASKS.phone.format('479999900001234'), '(47) 99999-0000')
})

test('money: shows 1.234,56, sends the API decimal, types from the cents', () => {
  assert.equal(MASKS.money.format('1234.56'), '1.234,56')
  assert.equal(MASKS.money.format('150.5'), '150,50')
  assert.equal(MASKS.money.format('7'), '7,00')
  assert.equal(MASKS.money.format(''), '')
  assert.equal(MASKS.money.unmask('1.234,56'), '1234.56')
  // Typing 1, 5, 0 → 0,01 → 0,15 → 1,50 (the input re-formats after each key).
  assert.equal(MASKS.money.unmask('1'), '0.01')
  assert.equal(MASKS.money.unmask('0,015'), '0.15')
  assert.equal(MASKS.money.unmask('0,150'), '1.50')
  assert.equal(MASKS.money.unmask('0,0'), '')
  assert.equal(MASKS.money.unmask('9.999.999.999.999,999'), '9999999999999.99')
})

test('cep: shows 00000-000, sends 8 digits', () => {
  assert.equal(MASKS.cep.format('89160000'), '89160-000')
  assert.equal(MASKS.cep.unmask('89160-000'), '89160000')
  assert.equal(MASKS.cep.format('891600001'), '89160-000')
})
