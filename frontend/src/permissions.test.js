import { test } from 'node:test'
import assert from 'node:assert/strict'
import { can, canAccessRoute, menuItems, menuTree, searchMenu } from './permissions.js'

const routes = [
  { path: '/dashboard', meta: { permission: 'dashboard.view', menu: { label: 'Dashboard', order: 1, keywords: ['inicio'] } } },
  { path: '/user', meta: { permission: 'users.view', menu: { label: 'Usuários', module: 'Administração', order: 2 } } },
  { path: '/user/form', meta: { permission: 'users.create', menu: { label: 'Novo usuário', sidebar: false, order: 3 } } },
  { path: '/user/form/:id', meta: { permission: 'users.update' } },
  { path: '/login', meta: { public: true } },
]

const viewer = { master: false, permissions: ['users.view'] }
const master = { master: true, permissions: [] }

test('can: exact key, master bypass, no implicit grants', () => {
  assert.equal(can(viewer, 'users.view'), true)
  assert.equal(can(viewer, 'users.delete'), false)
  assert.equal(can(viewer, 'users.update'), false)
  assert.equal(can(master, 'anything.at.all'), true)
  assert.equal(can(null, 'users.view'), false)
  assert.equal(can(viewer, undefined), true)
})

test('menu shows allowed entries and hides the rest', () => {
  assert.deepEqual(menuItems(routes, viewer).map((i) => i.route), ['/user'])
  assert.deepEqual(menuItems(routes, master).map((i) => i.route), ['/dashboard', '/user', '/user/form'])
  assert.deepEqual(menuItems(routes, { permissions: [] }), [])
})

test('protected route is blocked without permission', () => {
  const edit = { matched: [{ meta: {} }, { meta: { permission: 'users.update' } }] }
  assert.equal(canAccessRoute(viewer, edit), false)
  assert.equal(canAccessRoute({ permissions: ['users.update'] }, edit), true)
  assert.equal(canAccessRoute(master, edit), true)
})

test('search never returns resources the user cannot access', () => {
  const items = menuItems(routes, viewer)
  assert.deepEqual(searchMenu(items, 'dashboard'), [])
  assert.deepEqual(searchMenu(items, 'novo usuario'), [])
  assert.deepEqual(searchMenu(items, 'usuarios').map((i) => i.route), ['/user'])
})

test('search matches label, accents, keywords, module and ranks label prefix first', () => {
  const items = menuItems(routes, master)
  assert.deepEqual(searchMenu(items, 'INÍCIO').map((i) => i.route), ['/dashboard'])
  assert.deepEqual(searchMenu(items, 'administracao').map((i) => i.route), ['/user'])
  assert.deepEqual(searchMenu(items, 'usu').map((i) => i.route), ['/user', '/user/form'])
  assert.deepEqual(searchMenu(items, '   '), [])
})

test('grouped routes nest under their sidebar group, which hides when empty', () => {
  const financeRoutes = [
    ...routes,
    { path: '/finance/dashboard', meta: { permission: 'finance.dashboard', menu: { label: 'Dashboard', group: 'finance', order: 31 } } },
    { path: '/finance/transactions', meta: { permission: 'finance.transaction.view', menu: { label: 'Transações', group: 'finance', order: 33 } } },
  ]

  const tree = menuTree(menuItems(financeRoutes, master))
  assert.deepEqual(tree.map((i) => i.label), ['Dashboard', 'Usuários', 'Novo usuário', 'Financeiro'])
  assert.deepEqual(tree[3].children.map((i) => i.route), ['/finance/dashboard', '/finance/transactions'])

  const onlyTransactions = menuTree(menuItems(financeRoutes, { permissions: ['finance.transaction.view'] }))
  assert.deepEqual(onlyTransactions[0].children.map((i) => i.route), ['/finance/transactions'])
  assert.deepEqual(menuTree(menuItems(financeRoutes, viewer)).map((i) => i.label), ['Usuários'])
})
