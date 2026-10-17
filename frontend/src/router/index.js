import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { canAccessRoute, menuItems } from '@/permissions'
import { swal } from '@/plugins/swal'
import AppLayout from '@/layouts/AppLayout.vue'
import Login from '@/pages/Login.vue'
import Forbidden from '@/pages/Forbidden.vue'
import Dashboard from '@/pages/Dashboard.vue'
import UserList from '@/pages/user/UserList.vue'
import UserForm from '@/pages/user/UserForm.vue'
import CustomerList from '@/pages/customer/CustomerList.vue'
import CustomerForm from '@/pages/customer/CustomerForm.vue'
import FinanceDashboard from '@/pages/finance/FinanceDashboard.vue'
import CategoryList from '@/pages/finance/CategoryList.vue'
import CategoryForm from '@/pages/finance/CategoryForm.vue'
import TransactionList from '@/pages/finance/TransactionList.vue'
import TransactionForm from '@/pages/finance/TransactionForm.vue'
import CompanyList from '@/pages/company/CompanyList.vue'
import CompanyForm from '@/pages/company/CompanyForm.vue'


// Single source for navigation: meta.permission protects the route (guard), and
// meta.menu puts it in the sidebar and global search, filtered by the same permission.
// Keys must exist in backend/config/permissions.php, which the API enforces.
const routes = [
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: { public: true },
  },
  {
    path: '/',
    component: AppLayout,
    children: [
      {
        path: '',
        name: 'Home',
        // Redirects resolve before beforeEach, so restore the session here too.
        redirect: () => {
          useAuthStore().restoreSession()
          return firstAccessibleRoute()
        },
      },
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: Dashboard,
        meta: {
          permission: 'dashboard.view',
          menu: {
            label: 'Dashboard',
            icon: 'fa-solid fa-gauge-high',
            description: 'Indicadores e gráficos gerais',
            keywords: ['início', 'home', 'painel', 'kpi', 'gráficos'],
            module: 'Geral',
            order: 10,
          },
        },
      },
      {
        path: 'customer',
        name: 'CustomerList',
        component: CustomerList,
        meta: {
          permission: 'customers.view',
          menu: {
            label: 'Clientes',
            icon: 'fa-solid fa-address-book',
            description: 'Leads, clientes em aquecimento, ativos e cancelados',
            keywords: ['leads', 'contatos', 'carteira', 'funil', 'crm'],
            module: 'Comercial',
            order: 15,
          },
        },
      },
      {
        path: 'customer/form',
        name: 'CustomerCreate',
        component: CustomerForm,
        meta: {
          permission: 'customers.create',
          menu: {
            label: 'Novo cliente',
            icon: 'fa-solid fa-user-plus',
            description: 'Cadastrar um cliente ou lead',
            keywords: ['cadastrar', 'incluir', 'adicionar', 'lead'],
            module: 'Comercial',
            order: 16,
            sidebar: false,
          },
        },
      },
      {
        path: 'customer/form/:id',
        name: 'CustomerEdit',
        component: CustomerForm,
        props: true,
        meta: { permission: 'customers.update' },
      },
      // Financeiro: meta.menu.group nests these under "Financeiro" in the sidebar.
      {
        path: 'finance/dashboard',
        name: 'FinanceDashboard',
        component: FinanceDashboard,
        meta: {
          permission: 'finance.dashboard',
          menu: {
            label: 'Dashboard',
            icon: 'fa-solid fa-chart-pie',
            description: 'Receitas, despesas, saldo e gráficos por período',
            keywords: ['financeiro', 'saldo', 'gráficos', 'receitas', 'despesas', 'resumo'],
            module: 'Financeiro',
            group: 'finance',
            order: 31,
          },
        },
      },
      {
        path: 'finance/categories',
        name: 'CategoryList',
        component: CategoryList,
        meta: {
          permission: 'finance.category.view',
          menu: {
            label: 'Categorias',
            icon: 'fa-solid fa-tags',
            description: 'Categorias de receitas e despesas',
            keywords: ['financeiro', 'classificação', 'tipos'],
            module: 'Financeiro',
            group: 'finance',
            order: 32,
          },
        },
      },
      {
        path: 'finance/categories/form',
        name: 'CategoryCreate',
        component: CategoryForm,
        meta: {
          permission: 'finance.category.create',
          menu: {
            label: 'Nova categoria',
            icon: 'fa-solid fa-tag',
            description: 'Cadastrar uma categoria financeira',
            keywords: ['cadastrar', 'incluir', 'financeiro'],
            module: 'Financeiro',
            order: 34,
            sidebar: false,
          },
        },
      },
      {
        path: 'finance/categories/form/:id',
        name: 'CategoryEdit',
        component: CategoryForm,
        props: true,
        meta: { permission: 'finance.category.update' },
      },
      {
        path: 'finance/transactions',
        name: 'TransactionList',
        component: TransactionList,
        meta: {
          permission: 'finance.transaction.view',
          menu: {
            label: 'Transações',
            icon: 'fa-solid fa-money-bill-transfer',
            description: 'Lançamentos de receitas e despesas (web, Telegram, importações)',
            keywords: ['financeiro', 'lançamentos', 'receitas', 'despesas', 'extrato', 'telegram'],
            module: 'Financeiro',
            group: 'finance',
            order: 33,
          },
        },
      },
      {
        path: 'finance/transactions/form',
        name: 'TransactionCreate',
        component: TransactionForm,
        meta: {
          permission: 'finance.transaction.create',
          menu: {
            label: 'Nova transação',
            icon: 'fa-solid fa-plus',
            description: 'Lançar uma receita ou despesa',
            keywords: ['lançar', 'receita', 'despesa', 'incluir', 'financeiro'],
            module: 'Financeiro',
            order: 35,
            sidebar: false,
          },
        },
      },
      {
        path: 'finance/transactions/form/:id',
        name: 'TransactionEdit',
        component: TransactionForm,
        props: true,
        meta: { permission: 'finance.transaction.view' },
      },
      {
        path: 'user',
        name: 'UserList',
        component: UserList,
        meta: {
          permission: 'users.view',
          menu: {
            label: 'Usuários',
            icon: 'fa-solid fa-users',
            description: 'Listar e gerenciar usuários do sistema',
            keywords: ['contas', 'administradores', 'acesso', 'equipe'],
            module: 'Administração',
            order: 20,
          },
        },
      },
      {
        path: 'user/form',
        name: 'UserCreate',
        component: UserForm,
        meta: {
          permission: 'users.create',
          menu: {
            label: 'Novo usuário',
            icon: 'fa-solid fa-user-plus',
            description: 'Cadastrar um usuário',
            keywords: ['cadastrar', 'incluir', 'adicionar'],
            module: 'Administração',
            order: 21,
            sidebar: false,
          },
        },
      },
      {
        path: 'user/form/:id',
        name: 'UserEdit',
        component: UserForm,
        props: true,
        meta: { permission: 'users.update' },
      },
      {
        path: 'restricted/company',
        name: 'CompanyList',
        component: CompanyList,
        meta: {
          master: true,
          menu: {
            label: 'Empresas',
            icon: 'fa-solid fa-building',
            description: 'Empresas clientes, dados cadastrais e licença de acesso',
            keywords: ['restrita', 'master', 'empresa', 'cnpj', 'licença', 'token', 'tenant'],
            module: 'Restrita',
            order: 900,
            group: 'restricted',
          },
        },
      },
      {
        path: 'restricted/company/form',
        name: 'CompanyCreate',
        component: CompanyForm,
        meta: {
          master: true,
          menu: {
            label: 'Nova empresa',
            icon: 'fa-solid fa-building-circle-check',
            description: 'Cadastrar uma empresa cliente',
            keywords: ['cadastrar', 'incluir', 'empresa', 'cnpj'],
            module: 'Restrita',
            order: 901,
            sidebar: false,
          },
        },
      },
      {
        path: 'restricted/company/form/:id',
        name: 'CompanyEdit',
        component: CompanyForm,
        props: true,
        meta: { master: true },
      },
      {
        path: 'forbidden',
        name: 'Forbidden',
        component: Forbidden,
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export function navigationItems(user) {
  return menuItems(router.getRoutes(), user)
}

function firstAccessibleRoute() {
  const [first] = navigationItems(useAuthStore().user).filter((item) => item.sidebar !== false)
  return first?.route ?? '/forbidden'
}

let userRefreshed = false

router.beforeEach(async (to, from) => {
  const auth = useAuthStore()
  auth.restoreSession()

  const isPublicRoute = to.meta.public === true
  const hasValidSession = auth.isAuthenticated && !auth.isTokenExpired()

  if (isPublicRoute) {
    if (hasValidSession) {
      return '/'
    }
    return true
  }

  if (!hasValidSession) {
    return '/login'
  }

  // Permissions in localStorage may be stale: load the real ones once per page load.
  if (!userRefreshed) {
    userRefreshed = true
    await auth.refreshUser().catch(() => {})
    if (!auth.isAuthenticated) return '/login'
  }

  if (!canAccessRoute(auth.user, to)) {
    swal.toastError('Você não possui permissão para acessar esta página.')
    // Stay where the user was; on a fresh load there is nowhere to stay.
    return from.matched.length ? false : firstAccessibleRoute()
  }

  return true
})

// Login resets the one-time refresh so the next session starts from the API too.
router.afterEach((to) => {
  if (to.name === 'Login') userRefreshed = false
})

export default router
