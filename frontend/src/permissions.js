// Permission rules shared by the router guard, sidebar, global search and v-can.
// Mirrors App\Models\User::hasPermission on the backend: master bypasses, otherwise
// exact key match. This is UX only — the API enforces the same rule on every request.

export function can(user, permission) {
  if (!permission) return true
  if (!user) return false
  return !!user.master || (user.permissions || []).includes(permission)
}

// A route is accessible when every matched record's meta.permission is granted.
export function canAccessRoute(user, route) {
  return (route.matched || [route]).every((record) => can(user, record.meta?.permission))
}

// Menu entries come from route meta (single source: path + permission + menu info).
// meta.menu = { label, icon, description?, keywords?, module?, order?, sidebar?, group? }
export function menuItems(routes, user) {
  return routes
    .filter((route) => route.meta?.menu && can(user, route.meta.permission))
    .map((route) => ({
      ...route.meta.menu,
      route: route.path,
      permission: route.meta.permission,
    }))
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
}

// Sidebar submenus: a route with meta.menu.group = 'finance' is nested under this entry.
// The group only shows when the user can access at least one of its children.
export const MENU_GROUPS = {
  finance: { label: 'Financeiro', icon: 'fa-solid fa-wallet', order: 30 },
}

// Turns the flat (already permission-filtered) menuItems into the sidebar tree.
export function menuTree(items) {
  const groups = {}
  const tree = []
  for (const item of items) {
    const group = MENU_GROUPS[item.group]
    if (!group) {
      tree.push(item)
      continue
    }
    if (!groups[item.group]) tree.push((groups[item.group] = { ...group, children: [] }))
    groups[item.group].children.push(item)
  }
  return tree.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
}

export function normalize(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim()
}

// Every typed word must match label, description, keywords, module or permission key.
// Items must already be permission-filtered (menuItems), so nothing protected leaks.
export function searchMenu(items, query, limit = 8) {
  const words = normalize(query).split(/\s+/).filter(Boolean)
  if (!words.length) return []

  return items
    .map((item) => {
      const label = normalize(item.label)
      const haystack = normalize(
        [item.label, item.description, item.module, item.permission, ...(item.keywords || [])].join(' '),
      )
      if (!words.every((word) => haystack.includes(word))) return null
      const score = label.startsWith(words[0]) ? 0 : label.includes(words[0]) ? 1 : 2
      return { item, score }
    })
    .filter(Boolean)
    .sort((a, b) => a.score - b.score || (a.item.order ?? 0) - (b.item.order ?? 0))
    .slice(0, limit)
    .map(({ item }) => item)
}
