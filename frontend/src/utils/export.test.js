import { test } from 'node:test'
import assert from 'node:assert/strict'
import { toCsv, toXlsx } from './export.js'

test('csv: BOM, ";" e aspas escapadas', () => {
  assert.equal(toCsv([['Nome', 'Obs'], ['Ana "A"', 'a;b']]), '﻿"Nome";"Obs"\r\n"Ana ""A""";"a;b"')
})

test('xlsx: zip válido com as partes e o conteúdo escapado', async () => {
  const buf = Buffer.from(await toXlsx([['A & B', 'x'], ['1', '2']]).arrayBuffer())
  assert.equal(buf.readUInt32LE(0), 0x04034b50)
  assert.equal(buf.readUInt16LE(buf.length - 22 + 10), 5) // 5 arquivos no diretório central
  const text = buf.toString('utf8')
  assert.match(text, /xl\/worksheets\/sheet1\.xml/)
  assert.match(text, /A &amp; B/)
  assert.match(text, /r="B2"/)
})
