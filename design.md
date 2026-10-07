# Financial Bot — Design System & Arquitetura

Documento de referência para manter o projeto consistente. Qualquer tela ou endpoint novo deve
seguir isto antes de inventar um padrão próprio. Base gerada pela skill `create-crm` a partir do
padrão do peppercore-admin — mantenha este arquivo atualizado conforme o projeto crescer.

## Stack

- **Frontend**: Vue 3 (Composition API, `<script setup>`), Vue Router 4, Pinia, Vite, SCSS + Tailwind,
  AG Grid, ECharts, Font Awesome, SweetAlert2.
- **Backend**: Laravel 13 (PHP 8.3), PostgreSQL, Redis (cache/sessão/fila), auth via bearer token
  custom (`AuthMiddleware`), auditoria via `spatie/laravel-activitylog`.

## 1. Design tokens

Fonte única de verdade: [`frontend/src/styles/_colors.scss`](frontend/src/styles/_colors.scss), exposta como
CSS vars em [`main.scss`](frontend/src/styles/main.scss) (`--color-*`). Nunca hardcode hex em componente novo —
use a var. A marca é azul por padrão (`--color-primary`); vermelho é reservado só para `--color-danger`
(erro/exclusão) — nunca use vermelho como cor de marca/ação primária.

| Token | Valor | Uso |
|---|---|---|
| `--color-primary` | `#2563eb` | Marca, ação primária, foco |
| `--color-secondary` | `#4f46e5` | Acentos secundários |
| `--color-success` / `-soft` | `#059669` / `#ecfdf5` | Estado positivo |
| `--color-warning` / `-soft` | `#d97706` / `#fffbeb` | Estado de atenção |
| `--color-danger` / `-soft` | `#dc2626` / `#fef2f2` | Estado negativo (a única cor "vermelha" do sistema) |
| `--color-info` / `-soft` | `#2563eb` / `#eff6ff` | Ação neutra informativa |
| `--color-bg-app` | `#f4f6f9` | Fundo da área de conteúdo |
| `--color-bg-card` / `-muted` / `-hover` | `#fff` / `#f1f5f9` / `#f8fafc` | Superfícies |
| `--color-text` / `-secondary` / `-muted` / `-faint` | escala de cinza-azulado | Hierarquia tipográfica |
| `--color-border` / `-strong` | `#e2e8f0` / `#cbd5e1` | Bordas |

Espaçamento: múltiplos de 4px (8/12/16/20/24), sem escala fixa em var. Raio: `--radius-md` (10px, controles),
`--radius-lg`/`--radius-xl` (12–16px, cards/painéis), `--radius-full` (pills/badges/avatares). Sombra:
`--shadow-sm` (repouso), `--shadow-md` (hover), `--shadow-lg` (dropdowns). Fonte: `--font-sans` = Outfit.

## 2. Componentes base

Reutilize antes de criar algo novo (`frontend/src/components/utils/` e `ui/`):

- **Button** (`utils/Button.vue`) — variants `primary | create | edit | danger | secondary | ghost`,
  sizes `sm | md | lg`. Convenção: Incluir = `create` (azul info), Alterar = `edit` (verde), Excluir = `danger`
  (vermelho — único lugar do sistema onde vermelho aparece).
- **Input / TextArea / Switch** (`utils/`) — todos usam `defineModel`, prop `label`, mesmo `field-shell`
  visual (borda, foco com anel `--color-primary-soft`). `Input` suporta `mask` (`cpf`, `cnpj`, `cpf-cnpj`,
  `phone`, `cep`) via `frontend/src/utils/masks.js`.
- **Select** (`utils/Select.vue`) — dropdown próprio (não `<select>` nativo): `defineModel`, `label`,
  `options` = `[{ value, label, icon?, color? }]`, `placeholder` vira a opção vazia; teclado completo
  (↑↓, Enter, Esc, Home/End, digitar para pular) e `@change`. Opção com `color` aparece como
  `StatusBadge`. Opções de enum vêm da API (ex. `GET /customers/statuses`), nunca hardcoded.
  É a **lista base** do sistema: `searchable` (busca sem acento), `multiple` (v-model = array),
  `clearable`, `loading`, `disabled`. Seletor de entidade = wrapper fino que só carrega as opções e
  herda o resto por `$attrs` — ex. `components/finance/CategorySelect.vue`:
  `<CategorySelect v-model="ids" type="expense" multiple clearable />`. Crie `UserSelect`, `CustomerSelect`
  etc. do mesmo jeito; nunca reimplemente busca/múltipla seleção num componente específico.
- **StatusBadge** (`ui/StatusBadge.vue`) — pill de enum: `label`, `color` (tom: `info | success | warning |
  danger | neutral`, mapeado para `--color-*`/`--color-*-soft`, **ou** um hex escolhido pelo usuário, como a cor
  de uma categoria financeira) e `icon` (classe Font Awesome ou emoji). O enum no backend define
  `color()`/`icon()` (ex. `CustomerStatus`); vermelho (`danger`) só para estados de erro/destrutivos.
- **Campo booleano** (ativo/inativo, sim/não) sempre usa `Switch` — nunca checkbox nativo.
- **AgGrid** (`utils/AgGrid.vue`) — grid padrão para listagens, com paginação customizada embutida (não
  usar a paginação nativa do AG Grid). `columnTypes` prontos: `date`, `datetime`, `money`, `boolean` (ícone
  ✓/✕; rótulos via `cellRendererParams: { trueLabel, falseLabel }`) e `badge` (enum como `StatusBadge`,
  `cellRendererParams: { options: () => opcoes.value }`). Renderer novo com Vue → embrulhe com
  `cellComponent()` do próprio `AgGrid.vue` (função crua devolvendo VNode sai em branco).
- **Modal** (`utils/Modal.vue`) — `v-model`, slots `default`/`footer`, fecha com Esc ou clique fora. Prop
  `size` (`sm` 440px padrão / `md` 720px·80vh / `lg` 960px·85vh).
- **PageHeader** (`ui/PageHeader.vue`) — título + subtítulo + ícone, primeiro elemento de toda página.
- **FormActions** (`ui/FormActions.vue`) — par Salvar/Cancelar padrão de todo formulário, sempre no fim.
- **ActivityLogModal** (`ui/ActivityLogModal.vue`) — modal genérico de "Histórico de Alterações" (busca,
  filtro por Criações/Edições/Exclusões, diff ANTES/DEPOIS por campo). Props `subject-type` (chave curta
  registrada no backend, ex. `user`) + `subject-id`. Consome `GET /api/activity-log`. Reaproveite em
  qualquer tela nova que precise mostrar o histórico de um registro — não recrie a busca/filtro/diff do zero.
  Ver seção 7.
- **EChart** (`ui/EChart.vue`) — wrapper fino sobre `echarts`: prop `option` (spec do ECharts) + `height`,
  cuida de `init`/`resize`/`dispose`. Uma métrica por série usa uma cor sólida só; rótulo direto na barra;
  tooltip sempre ligado.
- **Confirmações e toasts**: sempre via `frontend/src/plugins/swal.js` (`swal.confirm`,
  `swal.confirmDelete`, `swal.toastSuccess/Error`). Nunca `window.confirm`/`alert`.

Ao precisar de badges/etc. que não vieram nesta base mínima, copie o padrão equivalente do
peppercore-admin (mesma convenção de `defineModel` + `field-shell`) em vez de inventar uma API diferente.

## 3. Utilitários globais (`frontend/src/styles/form.scss`)

Classes globais já prontas — não recriar em `<style scoped>` de cada página: `.page`/`.fade-in` (wrapper de
página com fade), `.page-header`, `.form-grid` + `.col-1`…`.col-12` (grid 12 colunas responsivo, colapsa para
1 coluna em telas pequenas), `.form-card`, `.form-actions`, `.form-error`, `.toolbar`/`.toolbar__actions`,
`.search-field`. `.field-label` e `.field-control` também são globais e usados pelos componentes de `utils/`.

## 4. Padrões de página

- Toda página começa com `<div class="page fade-in">` + `<PageHeader>`.
- **Listagem** (`[Model]List.vue`, ex. `pages/user/UserList.vue`): toolbar com busca (`Input type="search"`
  + debounce de 400ms) à esquerda, ações (Incluir/Alterar/Histórico/Excluir) à direita, depois `AgGrid`
  dentro de `.grid-wrap`. Exclusão sempre passa por `swal.confirmDelete`, nunca é imediata.
- **Formulário** (`[Model]Form.vue`, ex. `pages/user/UserForm.vue`): único componente para criar/editar,
  rotas `/model/form` e `/model/form/:id`, detecção de modo por `route.params.id`, campos em `.form-grid`
  com colunas `.col-N`, `FormActions` no fim.
- Nova página → adicione a rota em `frontend/src/router/index.js` (dentro do `children` do `AppLayout`) com
  `meta.permission` e, se for navegável, `meta.menu` — sidebar e busca global saem daí (seção 8). Nunca
  deixe item de menu sem rota real por trás.
- **Submenu**: `meta.menu.group: 'finance'` aninha o item sob o grupo declarado em `MENU_GROUPS`
  (`frontend/src/permissions.js`, com label/ícone/ordem). O grupo só aparece se o usuário puder ver ao menos um
  filho. Novo grupo = nova entrada em `MENU_GROUPS`; o `SidebarMenuItem` já renderiza os `children`.

## 5. Arquitetura backend (Laravel)

Controller fino → Service → Model. Nunca lógica de negócio no Controller.

- **Model**: atributos `#[Fillable([...])]` / `#[Hidden([...])]`, casts via método `casts()`.
- **Service** (`app/Service/`): uma classe por recurso, métodos `list/index/create/update/delete` recebendo
  arrays já validados. Ex.: `App\Service\UserService`.
- **Controller**: valida (`$request->validate`) e delega ao Service, devolve `response()->json(...)`.
- **Rotas** (`routes/api.php`): REST simples dentro do grupo `Route::middleware(['auth', 'permission'])`, com `->prefix('recurso')`: `GET /`,
  `GET /{id}`, `POST /`, `PUT /{id}`, `DELETE /{id}`. Rotas mais específicas (ex. `/summary`) sempre
  declaradas antes das genéricas com parâmetro (`/{id}`) no mesmo grupo.
- Novo recurso → Model + migration + Service + Controller + bloco de rotas, seguindo exatamente o padrão de
  `App\Http\Controllers\UserController` / `App\Service\UserService`. Exemplo completo de módulo de
  negócio: **Clientes** (`Customer`, `CustomerService`, `CustomerController`, `pages/customer/`).
- **Enums** (`app/Enums/`): backed enum com valores em inglês + `label()` em português + `color()`/`icon()`
  e `use HasOptions` (`app/Enums/Concerns/HasOptions.php`) para `options()` de selects (ex. `CustomerStatus`). Cast no model, `Rule::enum` na validação, valores congelados na migration.
- **Normalização** no model (mutators `Attribute::set`): e-mail minúsculo, telefone/CEP só dígitos — vale
  para qualquer caminho de escrita (API, rota pública, seeder, tinker). As máscaras do front formatam.
- **Regras de domínio** que o banco não expressa por constraint simples ficam também num `saving` do model
  (ex. "e-mail ou telefone" em `Customer`), além da validação e de um `CHECK` no Postgres quando possível.
- **Exclusão**: `User` é físico; registros de negócio com histórico (ex. `Customer`) usam `SoftDeletes`.
- **APIs externas**: só dentro de um Service dedicado (ex. `App\Service\ZipCodeService` → ViaCEP), com
  `Http::timeout`, tratamento de indisponibilidade/resposta inválida e cache; config em
  `config/services.php`. O front chama a nossa API, nunca o provider — trocar de provider = mudar o Service.
- **Rotas públicas**: `Route::prefix('public')->middleware('throttle:public')` em `routes/api.php`; mesmas
  regras de validação/domínio da rota autenticada, sem aceitar campos internos (ex. status) e devolvendo só
  uma confirmação. Ações públicas entram na lista explícita do `AuthorizationTest`.

## 6. Arquitetura frontend (Vue)

- `pages/<recurso>/` — telas roteadas. `components/ui/` — componentes de composição de página.
  `components/utils/` — inputs e primitivos de formulário/grid. `components/layout/` — casca do app
  (sidebar, layout). `services/api.js` — único ponto de fetch autenticado (`apiFetch`, injeta Bearer token,
  trata 401 deslogando). Chame sempre **sem** o prefixo: `apiFetch('/users')` — o `API_URL`
  (vindo de `VITE_API_URL`) é prefixado lá dentro, então trocar o prefixo da API é só mudar o `.env`.
- Nunca `fetch()` direto numa página — sempre `apiFetch`.

## 7. Auditoria (histórico de alterações)

`app/Models/Concerns/Auditable.php` é uma trait fina sobre `spatie/laravel-activitylog` — qualquer model que
precise de histórico usa `use Auditable;` e nada mais (loga só os atributos fillable que realmente mudaram,
com quem fez e quando). Hoje aplicada em `User`. Para adicionar a um novo model:

1. `use App\Models\Concerns\Auditable;` + `use Auditable;` no model.
2. Registre a chave curta do model em `ActivityLogController::SUBJECT_MAP` (ex. `'client' => Client::class`).
3. Se o model tiver um campo sensível (senha, token, etc.), sobrescreva `getActivitylogOptions()` no próprio
   model adicionando `->logExcept([...])` — veja `User::getActivitylogOptions()` como referência (exclui
   `password`).
4. Na tela de listagem/detalhe do recurso, adicione um botão "Histórico" que abre `<ActivityLogModal
   subject-type="..." :subject-id="..." />` (veja `pages/user/UserList.vue`).

O causer é resolvido via `Auth::guard()->user()` — por isso `AuthMiddleware` chama `Auth::setUser($user)`
além de `setUserResolver`, mesmo sem sessão/cookie. Não pule esse passo em middlewares customizados novos.

## 8. Permissões

Uma regra só, nos dois lados: **master passa direto; senão, a chave exata precisa estar atribuída ao
usuário** (`User::hasPermission` no backend, `can()` em `frontend/src/permissions.js`). O frontend só
esconde; quem bloqueia é a API.

- **Fonte**: `backend/config/permissions.php` (`modulo => [name, actions => [acao => descrição]]`), espelhado
  na tabela `permissions` e ligado a usuários por `permission_user` (FK com cascade). Altere pelo comando —
  ele mantém arquivo e banco juntos:
  `php artisan permission` (= `list`), `permission create leads.view --module-name=Leads --description=...`,
  `permission update leads.view --rename=leads.list`, `permission delete leads.list`, `permission sync`
  (depois de editar o arquivo à mão; também roda a cada boot via seeder).
- **Backend**: `#[Permission('leads.view')]` no método (ou classe) do controller; o middleware
  `CheckPermission` aplica. Rota de controller sem o atributo quebra o teste
  `AuthorizationTest::test_every_controller_route_declares_a_permission_or_is_explicitly_public`.
- **Usuário**: `hasPermission`, `givePermission`, `revokePermission`, `syncPermissions`, `permissionKeys`
  (cache por usuário, invalidado em toda alteração). `master` não é atribuível pela API.
- **Frontend**: rota com `meta.permission` (guard bloqueia) e `meta.menu = { label, icon, description,
  keywords, module, order, sidebar? }` (sidebar + busca global, já filtrados). Botões: `v-can="'leads.create'"`
  ou `$can(...)` / `auth.can(...)`. As permissões vêm de `/auth/me` (no load e no poll de 60s).
- **Novo módulo**: `permission create` para cada ação → `#[Permission]` nos métodos → `meta` nas rotas →
  `v-can` nos botões. A aba Permissões do usuário aparece sozinha com o módulo novo.
- Um não-master só concede/remove permissões que ele próprio tem, e não altera/exclui um master.

## 9. Rate limiting e IP do cliente

- Perfis em `backend/config/rate-limit.php` (`max_attempts` / `decay_seconds`, sobrescrevíveis por
  `RATE_LIMIT_*` no `.env`), registrados uma vez no `AppServiceProvider` como limiters nativos do Laravel.
  Use com `->middleware('throttle:<perfil>')`. Excesso → HTTP 429 + `Retry-After`/`X-RateLimit-*`.
- `default` (120/60s) já cobre todas as rotas `api` e `web`; `public` (5/30s) nas rotas públicas;
  `authentication` (5/30s) no login. Novo contexto = nova entrada no config, nenhum código novo.
- Chave = IP do cliente, resolvido pelo `TrustProxies` (`TRUSTED_PROXIES`, padrão: redes privadas — Nginx
  no host/Docker). Atrás do Cloudflare, adicione as faixas dele. Em produção, publique a porta do backend só
  em `127.0.0.1` (o Nginx acessa local); exposta direto, quem chega pelo gateway do Docker é "proxy confiável".

## 10. Redis

Usado para sessão (`SESSION_DRIVER=redis`), cache (`CACHE_STORE=redis`) e fila (`QUEUE_CONNECTION=redis`) —
configurado em `backend/.env`, serviço `redis` no `docker-compose.yml`. `GET /api/status` reporta a
conectividade do Postgres e do Redis; use-o para diagnosticar rapidamente um ambiente novo.

## 11. Módulo Financeiro

Núcleo independente da origem dos dados: web, Telegram e futuras importações (CSV, Open Finance) entram
todos por `TransactionService::create($data, TransactionSource, ?externalId)`.

- **Tabelas**: `finance_categories` (nome, emoji, cor hex, `type` income/expense/both, ativo, ordem; soft
  delete) e `finance_transactions` (`type`, `amount` decimal(15,2), `description`, `category_id`, `date` Y-m-d,
  `status` paid/pending, `source`, `external_id`; soft delete). `unique(source, external_id)` torna qualquer
  entrada idempotente: mesmo id na mesma origem devolve a transação original.
- **Regras**: valor como decimal string (nunca float), > 0, até 2 casas; a categoria precisa estar ativa e
  aceitar o tipo — garantido no `saving` do model `Transaction` (vale para qualquer caminho de escrita).
  O dashboard soma só `paid`.
- **Enums**: `TransactionType`, `TransactionStatus`, `TransactionSource`, `CategoryType` — nova origem
  (ex. CSV) = novo case em `TransactionSource` + um ponto de entrada que chame o `TransactionService`.
- **API**: `/api/finance/{dashboard,categories,transactions}` com `#[Permission('finance.*')]`.
- **Filtros**: um vocabulário só, `Transaction::scopeFiltered()` (from, to, type, category_id, category_ids[],
  source, status, min/max_amount, search) — usado pela listagem, pelo dashboard e pela exportação. No dashboard,
  `status` = `paid` (padrão) | `pending` | `all`, e os filtros valem também para o saldo anterior.
- **Exportação Excel**: `GET /api/finance/dashboard/export` (permissão `finance.export`, mesmos filtros do
  dashboard) → `FinanceExportService` (OpenSpout): abas Resumo (balancete 100% em fórmulas SOMASES sobre
  Transações), Mensal (receitas/despesas/resultado/saldo acumulado por mês, fórmulas), Transações (dados, cabeçalho
  fixo, autofiltro) e Categorias. Texto do usuário entra sempre como `StringCell` — nunca vira fórmula.
- **Telas**: `pages/finance/` — `FinanceDashboard` (filtros de período/categorias/origem/status + exportar;
  gráficos em `dashboardCharts.js`, uma pergunta por gráfico: novo gráfico = nova entrada no array), `CategoryList` (cards) / `CategoryForm`, `TransactionList` / `TransactionForm`.
  Presets de período em `utils/period.js`.

## 12. Bot do Telegram (`bot/`)

Projeto Node + TypeScript próprio (grammY, long polling), container `bot` no compose. **Nunca acessa o
banco**: só `/api/bot/*`.

- **Autenticação em duas camadas** (`App\Http\Middleware\BotMiddleware`, alias `bot`): (1) o container prova
  que é o bot com `Authorization: Bearer BOT_API_TOKEN` (comparação constante, falha fechada se vazio);
  (2) o usuário do Telegram (`X-Telegram-User-Id`) precisa ter ativado o bot e não estar revogado
  (`telegram_accounts.revoked_at`). A `TelegramAccount` vira o *causer* do activity log.
- **Ativação**: `BotActivationService` é o único lugar que valida o código (hoje `TELEGRAM_ACTIVATION_CODE`).
  Códigos por usuário/expiração/vínculo com `User` trocam só o `codeIsValid()`; revogação já é respeitada.
- **Limites** (`config/rate-limit.php`, chaveados por usuário do Telegram): `bot`, `bot-activation`
  (tentativas de código), `bot-transactions` (teto de lançamentos/hora).
- **Idempotência**: o bot envia `idempotency_key` = `chat:mensagem de confirmação`; o backend grava
  `external_id = "<telegram_user_id>:<key>"` — retry/clique duplo não duplica.
- **Conversa**: qualquer mensagem fora de um fluxo abre o menu (`flows/menu.ts`: Lançamento / Consultar /
  Cancelar). Consultar usa `GET /api/bot/finance/summary` — os mesmos agregados do dashboard web
  (`FinanceDashboardService`), formatados como balancete em `bot/src/balance.ts`.
- **Novo fluxo no bot**: um arquivo em `bot/src/flows/` com sua função `register*`, registrado em `index.ts`
  antes do `registerMenuFallback`, e um botão no menu principal. O bot só coleta e formata; regras e contas
  financeiras ficam no backend.

## 13. Checklist antes de abrir PR

- [ ] Usa tokens `--color-*` existentes, sem hex novo solto no componente. Vermelho só em `--color-danger`.
- [ ] Reaproveita `Button`/`Input`/`TextArea`/`Switch`/`Modal`/`AgGrid`/`EChart`/`PageHeader`/`FormActions`/
      `ActivityLogModal` em vez de recriar.
- [ ] Todo campo booleano usa `Switch`, nunca checkbox nativo.
- [ ] Toda exclusão passa por `swal.confirmDelete`, nunca é imediata ou usa `window.confirm`.
- [ ] Controller fino, regra de negócio no Service.
- [ ] Model novo com necessidade de histórico usa a trait `Auditable` (seção 7), não reinventa auditoria.
- [ ] Nenhum botão, ícone ou item de menu decorativo sem função real.
- [ ] Endpoint novo tem `#[Permission]`; rota nova tem `meta.permission`; ação protegida usa `v-can`.
- [ ] Endpoint público tem `throttle:public` e não aceita campos internos; chamada externa passa por Service.
