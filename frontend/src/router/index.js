import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usePortalStore } from '@/stores/portal'
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
import GoalList from '@/pages/finance/GoalList.vue'
import GoalForm from '@/pages/finance/GoalForm.vue'
import CompanyList from '@/pages/company/CompanyList.vue'
import CompanyForm from '@/pages/company/CompanyForm.vue'
import Settings from '@/pages/settings/Settings.vue'
import ProjectList from '@/pages/project/ProjectList.vue'
import ProjectForm from '@/pages/project/ProjectForm.vue'
import AnnouncementList from '@/pages/announcement/AnnouncementList.vue'
import AnnouncementForm from '@/pages/announcement/AnnouncementForm.vue'
import DocumentList from '@/pages/document/DocumentList.vue'
import ImportList from '@/pages/import/ImportList.vue'
import ImportWizard from '@/pages/import/ImportWizard.vue'
import ImportDetail from '@/pages/import/ImportDetail.vue'
import DocumentEditor from '@/pages/document/DocumentEditor.vue'
// Portal do cliente: lazy chunks, so the CRM bundle doesn't carry the portal screens.
const PortalLayout = () => import('@/layouts/PortalLayout.vue')
const PortalLogin = () => import('@/pages/portal/PortalLogin.vue')
const PortalHome = () => import('@/pages/portal/PortalHome.vue')
const PortalProject = () => import('@/pages/portal/PortalProject.vue')
const PortalBills = () => import('@/pages/portal/PortalBills.vue')
const PortalAnnouncements = () => import('@/pages/portal/PortalAnnouncements.vue')


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
  // Portal do cliente: another access (customer session, stores/portal.js), outside the CRM shell.
  // meta.portal routes skip the CRM guard; /portal/<slug>/entrar is its login.
  {
    path: '/portal/:slug/entrar',
    name: 'PortalLogin',
    component: PortalLogin,
    meta: { portal: true, public: true },
  },
  {
    path: '/portal/:slug',
    component: PortalLayout,
    meta: { portal: true },
    children: [
      { path: '', name: 'PortalHome', component: PortalHome },
      { path: 'contas', name: 'PortalBills', component: PortalBills },
      { path: 'avisos', name: 'PortalAnnouncements', component: PortalAnnouncements },
      { path: 'projetos/:id', name: 'PortalProject', component: PortalProject, props: true },
    ],
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
      // Portal do cliente: what the company publishes for its customers (meta.menu.group 'portal').
      {
        path: 'project',
        name: 'ProjectList',
        component: ProjectList,
        meta: {
          permission: 'projects.view',
          menu: {
            label: 'Projetos',
            icon: 'fa-solid fa-diagram-project',
            description: 'Projetos dos clientes, com etapas e progresso',
            keywords: ['portal', 'cliente', 'etapas', 'progresso', 'entrega', 'acompanhamento'],
            module: 'Portal do cliente',
            group: 'portal',
            order: 17,
          },
        },
      },
      {
        path: 'project/form',
        name: 'ProjectCreate',
        component: ProjectForm,
        meta: {
          permission: 'projects.create',
          menu: {
            label: 'Novo projeto',
            icon: 'fa-solid fa-plus',
            description: 'Cadastrar o projeto de um cliente',
            keywords: ['cadastrar', 'incluir', 'portal', 'etapas'],
            module: 'Portal do cliente',
            order: 18,
            sidebar: false,
          },
        },
      },
      {
        path: 'project/form/:id',
        name: 'ProjectEdit',
        component: ProjectForm,
        props: true,
        meta: { permission: 'projects.update' },
      },
      {
        path: 'announcement',
        name: 'AnnouncementList',
        component: AnnouncementList,
        meta: {
          permission: 'announcements.view',
          menu: {
            label: 'Avisos',
            icon: 'fa-solid fa-bullhorn',
            description: 'Comunicados publicados no portal do cliente',
            keywords: ['portal', 'cliente', 'comunicado', 'mural', 'notícia', 'recado'],
            module: 'Portal do cliente',
            group: 'portal',
            order: 18,
          },
        },
      },
      {
        path: 'announcement/form',
        name: 'AnnouncementCreate',
        component: AnnouncementForm,
        meta: {
          permission: 'announcements.create',
          menu: {
            label: 'Novo aviso',
            icon: 'fa-solid fa-bullhorn',
            description: 'Publicar um aviso no portal do cliente',
            keywords: ['publicar', 'comunicado', 'portal'],
            module: 'Portal do cliente',
            order: 19,
            sidebar: false,
          },
        },
      },
      {
        path: 'announcement/form/:id',
        name: 'AnnouncementEdit',
        component: AnnouncementForm,
        props: true,
        meta: { permission: 'announcements.update' },
      },
      // Documentos: templates com variáveis ({{Cliente.name}}) que viram PDF ao emitir.
      {
        path: 'document',
        name: 'DocumentList',
        component: DocumentList,
        meta: {
          permission: 'documents.view',
          menu: {
            label: 'Documentos',
            icon: 'fa-solid fa-file-lines',
            description: 'Templates de documento com variáveis, emitidos em PDF',
            keywords: ['contrato', 'proposta', 'orçamento', 'template', 'modelo', 'pdf', 'emitir', 'variáveis'],
            module: 'Documentos',
            order: 25,
          },
        },
      },
      {
        path: 'document/form',
        name: 'DocumentCreate',
        component: DocumentEditor,
        meta: {
          permission: 'documents.create',
          menu: {
            label: 'Novo documento',
            icon: 'fa-solid fa-file-circle-plus',
            description: 'Criar um template de documento',
            keywords: ['criar', 'contrato', 'proposta', 'modelo', 'editor'],
            module: 'Documentos',
            order: 26,
            sidebar: false,
          },
        },
      },
      {
        path: 'document/form/:id',
        name: 'DocumentEdit',
        component: DocumentEditor,
        props: true,
        meta: { permission: 'documents.update' },
      },
      // Importação: planilha → registros (meta.menu.group 'import'). O wizard e o detalhe não têm item de menu.
      {
        path: 'import/customer',
        name: 'ImportCustomerList',
        component: ImportList,
        props: { type: 'customer' },
        meta: {
          permission: 'imports.view',
          menu: {
            label: 'Clientes',
            icon: 'fa-solid fa-address-book',
            description: 'Importar clientes de uma planilha Excel ou CSV',
            keywords: ['importar', 'planilha', 'excel', 'csv', 'carga', 'upload', 'clientes'],
            module: 'Importação',
            group: 'import',
            order: 21,
          },
        },
      },
      {
        path: 'import/customer/new',
        name: 'ImportCustomerNew',
        component: ImportWizard,
        props: { type: 'customer' },
        meta: { permission: 'imports.create' },
      },
      {
        path: 'import/customer/:id',
        name: 'ImportCustomerDetail',
        component: ImportDetail,
        props: (route) => ({ id: route.params.id, type: 'customer' }),
        meta: { permission: 'imports.view' },
      },
      {
        path: 'import/user',
        name: 'ImportUserList',
        component: ImportList,
        props: { type: 'user' },
        meta: {
          permission: 'imports.view',
          menu: {
            label: 'Usuários',
            icon: 'fa-solid fa-users',
            description: 'Importar usuários de uma planilha Excel ou CSV',
            keywords: ['importar', 'planilha', 'excel', 'csv', 'carga', 'upload', 'usuários', 'acesso'],
            module: 'Importação',
            group: 'import',
            order: 22,
          },
        },
      },
      {
        path: 'import/user/new',
        name: 'ImportUserNew',
        component: ImportWizard,
        props: { type: 'user' },
        meta: { permission: 'imports.create' },
      },
      {
        path: 'import/user/:id',
        name: 'ImportUserDetail',
        component: ImportDetail,
        props: (route) => ({ id: route.params.id, type: 'user' }),
        meta: { permission: 'imports.view' },
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
        path: 'finance/goals',
        name: 'GoalList',
        component: GoalList,
        meta: {
          permission: 'finance.goal.view',
          menu: {
            label: 'Metas',
            icon: 'fa-solid fa-bullseye',
            description: 'Metas de investimento e tetos de gastos, com o dinheiro separado de cada uma',
            keywords: ['financeiro', 'objetivos', 'investimento', 'reserva', 'teto', 'orçamento', 'limite'],
            module: 'Financeiro',
            group: 'finance',
            order: 33.5,
          },
        },
      },
      {
        path: 'finance/goals/form',
        name: 'GoalCreate',
        component: GoalForm,
        meta: {
          permission: 'finance.goal.create',
          menu: {
            label: 'Nova meta',
            icon: 'fa-solid fa-bullseye',
            description: 'Cadastrar uma meta de investimento ou teto de gastos',
            keywords: ['cadastrar', 'incluir', 'financeiro', 'objetivo'],
            module: 'Financeiro',
            order: 36,
            sidebar: false,
          },
        },
      },
      {
        path: 'finance/goals/form/:id',
        name: 'GoalEdit',
        component: GoalForm,
        props: true,
        meta: { permission: 'finance.goal.update' },
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
        // No permission: Preferências is everyone's; each company tab checks its own (settings.*).
        path: 'settings',
        name: 'Settings',
        component: Settings,
        meta: {
          menu: {
            label: 'Configurações',
            icon: 'fa-solid fa-gear',
            description: 'Empresa, aparência, financeiro, integrações, bot do Telegram e preferências',
            keywords: ['configuração', 'ajustes', 'telegram', 'bot', 'vínculo', 'código', 'aparência', 'cor', 'tema',
              'marca', 'fuso', 'horário', 'preferências', 'densidade', 'página inicial', 'empresa', 'cnpj',
              'integração', 'mercado pago', 'pix', 'conectar'],
            module: 'Configurações',
            order: 800,
          },
        },
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
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

export function navigationItems(user) {
  return menuItems(router.getRoutes(), user)
}

// Configurações › Preferências "página inicial", while the user can still open it; else the first menu entry.
function firstAccessibleRoute() {
  const items = navigationItems(useAuthStore().user).filter((item) => item.sidebar !== false)
  const start = useAuthStore().preferences.start_page
  return items.find((item) => item.route === start)?.route ?? items[0]?.route ?? '/forbidden'
}

let userRefreshed = false

// Portal do cliente: only its own session counts (a CRM login doesn't open it, and vice versa).
function portalGuard(to) {
  const portal = usePortalStore()
  portal.restore()
  const loggedIn = portal.sessionFor(to.params.slug)
  if (to.meta.public) return loggedIn ? { name: 'PortalHome', params: { slug: to.params.slug } } : true
  return loggedIn || { name: 'PortalLogin', params: { slug: to.params.slug } }
}

router.beforeEach(async (to, from) => {
  if (to.meta.portal) return portalGuard(to)

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
