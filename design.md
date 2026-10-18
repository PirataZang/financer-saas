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

**Cor da marca por empresa** (Configurações › Aparência): `--color-primary` é trocada em tempo de execução
(`utils/theme.js`); `--color-primary-hover/-soft/-muted/-glow` derivam dela com `color-mix` em `main.scss`. Por isso
`$color-primary*` no Sass são `var(...)`: **nunca** passe-os a funções Sass (`rgba()`, `mix()`) — use
`color-mix(in srgb, #{$color-primary} 28%, transparent)`. A tela só salva cores com contraste ≥ 4,5:1 para texto branco.

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
  `phone`, `cep`, `money`) via `frontend/src/utils/masks.js`. `money` guarda o decimal da API (`"1234.56"`),
  mostra `1.234,56` e preenche da direita (centavos primeiro); campo de valor em R$ usa ele, nunca `type="number"`.
- **Select** (`utils/Select.vue`) — dropdown próprio (não `<select>` nativo): `defineModel`, `label`,
  `options` = `[{ value, label, icon?, color? }]`, `placeholder` vira a opção vazia; teclado completo
  (↑↓, Enter, Esc, Home/End, digitar para pular) e `@change`. Opção com `color` aparece como
  `StatusBadge`. Opções de enum vêm da API (ex. `GET /customers/statuses`), nunca hardcoded.
  É a **lista base** do sistema: `searchable` (busca sem acento), `multiple` (v-model = array),
  `clearable`, `loading`, `disabled`. Seletor de entidade = wrapper fino que só carrega as opções e
  herda o resto por `$attrs` — ex. `components/finance/CategorySelect.vue`:
  `<CategorySelect v-model="ids" type="expense" multiple clearable />`. Crie `UserSelect`, `CustomerSelect`
  etc. do mesmo jeito; nunca reimplemente busca/múltipla seleção num componente específico.
  Listas longas: `limit` (máximo exibido, com aviso "Mostrando N de M") — ex.: `CompanySwitcher` usa `searchable :limit="7"` (ordena as opções A→Z antes).
  Slots opcionais: `value` ({ selected }) e `option` ({ option, selected }) para gatilho/opções próprios (opções
  podem levar campos extras, ex. `initials`), `footer` ({ close }) para uma ação no fim da lista.
- **StatusBadge** (`ui/StatusBadge.vue`) — pill de enum: `label`, `color` (tom: `info | success | warning |
  danger | neutral`, mapeado para `--color-*`/`--color-*-soft`, **ou** um hex escolhido pelo usuário, como a cor
  de uma categoria financeira) e `icon` (classe Font Awesome ou emoji). O enum no backend define
  `color()`/`icon()` (ex. `CustomerStatus`); vermelho (`danger`) só para estados de erro/destrutivos.
- **Campo booleano** (ativo/inativo, sim/não) sempre usa `Switch` — nunca checkbox nativo.
- **Listagens** — estrutura `.list-toolbar` (`__filters` + `__actions`) + `.grid-wrap` com o `AgGrid`; estilos
  globais em `styles/list.scss` (não copie para o `<style>` da página).
- **Endereço por CEP** — `useZipCodeLookup(form, numberInput)` de `services/zipCode.js` (busca ao 8º dígito ou
  no blur, preenche rua/bairro/cidade/UF/país, foca o número). Usado em Clientes e Empresas.
- **AgGrid** (`utils/AgGrid.vue`) — grid padrão para listagens, com paginação customizada embutida (não
  usar a paginação nativa do AG Grid). `columnTypes` prontos: `date`, `datetime`, `money`, `boolean` (ícone
  ✓/✕; rótulos via `cellRendererParams: { trueLabel, falseLabel }`) e `badge` (enum como `StatusBadge`,
  `cellRendererParams: { options: () => opcoes.value }`). Renderer novo com Vue → embrulhe com
  `cellComponent()` do próprio `AgGrid.vue` (função crua devolvendo VNode sai em branco).
- **Modal** (`utils/Modal.vue`) — `v-model`, slots `default`/`footer`, fecha com Esc ou clique fora. Prop
  `size` (`sm` 440px padrão / `md` 720px·80vh / `lg` 960px·85vh).
- **Tabs** (`ui/Tabs.vue`) — abas com `tabs = [{ value, label, icon?, count? }]` e `v-model`; setas/Home/End
  trocam. Painel: `id="tabpanel-<value>" role="tabpanel"`. Usado em Configurações e no UserForm.
- **CompanyFields** (`company/CompanyFields.vue`) — campos de cadastro + endereço com CEP de uma empresa
  (Restrita › Empresas e Configurações › Empresa); `markZipLoaded()` após carregar o registro.
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

- **Model**: estende **`App\Models\Model`** (nunca o `Model` do Eloquent direto — `ProtectedColumnsTest`
  falha), atributos `#[Fillable([...])]` / `#[Hidden([...])]`, casts via método `casts()`.
- **Colunas protegidas** — dado sensível (token, flag de privilégio, segredo de integração) vai em
  `#[ProtectedColumns(['token'], locked: ['master'])]`, não em `#[Hidden]`:
  - nunca sai em resposta (toArray/JSON); o backend só expõe de propósito com `makeVisible()` (ex.: token da
    empresa para master);
  - nunca é preenchida pelos dados da requisição — `fill/create/update` ignoram, mesmo se estiver no
    `#[Fillable]` ou na validação; o backend grava com `forceFill()` (ex.: token de sessão no login);
  - nunca entra no log de auditoria (`Auditable`);
  - `locked`: além disso, **nenhuma requisição HTTP altera** por caminho nenhum (nem `forceFill`) — só o banco
    ou o console (seeders, artisan, tinker). Hoje: `users.master`.
  Em uso: `User` (`token`; `master` locked), `Company` (`token`). `password` segue em `#[Hidden]` porque o
  cadastro de usuário precisa preenchê-lo.
- **Service** (`app/Service/`): uma classe por recurso, métodos `list/index/create/update/delete` recebendo
  arrays já validados. Ex.: `App\Service\UserService`.
- **Controller**: valida (`$request->validate`) e delega ao Service, devolve `response()->json(...)`.
- **Rotas** (`routes/api.php`): REST simples dentro do grupo `Route::middleware(['auth', 'company', 'permission'])`, com `->prefix('recurso')`: `GET /`,
  `GET /{id}`, `POST /`, `PUT /{id}`, `DELETE /{id}`. Rotas mais específicas (ex. `/summary`) sempre
  declaradas antes das genéricas com parâmetro (`/{id}`) no mesmo grupo.
- Novo recurso → Model **com `BelongsToCompany`** + migration **com `company_id`** (seção 14) + Service + Controller + bloco de rotas, seguindo exatamente o padrão de
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
- **Rotas públicas**: `Route::prefix('public/{token}')->middleware('throttle:public')` em `routes/api.php`; o
  token da empresa diz a qual empresa o registro pertence (token inválido/revogado/expirado = 404). Mesmas
  regras de validação/domínio da rota autenticada, sem aceitar campos internos (ex. status) e devolvendo só
  uma confirmação. Ações públicas entram na lista explícita do `AuthorizationTest`.

## 6. Arquitetura frontend (Vue)

- `pages/<recurso>/` — telas roteadas. `components/ui/` — componentes de composição de página.
  `components/utils/` — inputs e primitivos de formulário/grid. `components/layout/` — casca do app
  (sidebar, layout). `services/api.js` — único ponto de fetch autenticado (`apiFetch`, injeta Bearer token,
  trata 401 deslogando). Chame sempre **sem** o prefixo: `apiFetch('/users')` — o `API_URL`
  (vindo de `VITE_API_URL`) é prefixado lá dentro, então trocar o prefixo da API é só mudar o `.env`.
- Nunca `fetch()` direto numa página — sempre `apiFetch`.
- **Menu lateral** (`AppLayout` + `AppSidebar` + `SidebarMenuItem`): abre/fecha **só** pelo botão do topo
  (sem hover). Aberto = fixo ao lado do conteúdo; recolhido = trilho de ícones. A escolha fica no
  localStorage com o prefixo `PREFERENCE_PREFIX` (`stores/auth.js`), que a troca de empresa preserva — use
  o mesmo prefixo para qualquer preferência de tela. Até 900px o menu aberto flutua sobre o conteúdo com
  fundo escurecido e fecha ao navegar.
  - Grupos (`meta.menu.group` nas rotas) começam abertos: título + subitens num **único bloco** (contorno e
    fundo do tom do grupo; neutro sem `tone`), subitens recuados numa linha-guia de 1px; página atual = pílula
    branca no subitem (o título do grupo nunca leva o fundo de ativo). A seta recolhe.
  - No trilho: item simples mostra o nome ao passar o mouse/focar; grupo abre um painel flutuante com os
    subitens (hover, clique ou Enter — o foco entra no 1º subitem; Esc fecha e devolve o foco). Os painéis
    são teleportados para o `body` (o painel do menu tem `overflow: hidden`).
  - Foco por teclado sempre com anel `--color-primary`, nunca o contorno padrão do navegador.
- **Topo** (`AppLayout`): à esquerda o botão do menu e a busca global; à direita `CompanySwitcher` e
  `UserMenu`. O perfil abre um cartão (popover, **sem** fundo/desfoque — só sombra e o botão realçado com anel):
  avatar, nome, e-mail, selo do papel (`Master` em âmbar / `Usuário` neutro) e "Sair da conta" em vermelho.
  Esc ou clique fora fecha e devolve o foco. Até 900px só os avatares aparecem; até 480px os popovers ocupam a
  largura ao lado do trilho do menu (o topo fica numa camada abaixo do menu lateral). Item novo no cartão só
  com função real (ex.: "Preferências" quando existir a tela).

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
  (cache por usuário, invalidado em toda alteração).
- **Master** é conta da plataforma, criada e alterada **só no banco** (`UPDATE users SET master = true ...`):
  nenhuma rota cria, promove, edita, exclui ou lista master — nem outro master. Masters ficam na empresa 1.
  `master` é coluna protegida **locked** (seção 5): não aparece em nenhuma resposta de usuário (só o `/auth/me`
  informa o do próprio usuário), não existe nas telas de usuário e nenhuma requisição consegue alterá-la.
- **Frontend**: rota com `meta.permission` (guard bloqueia) e `meta.menu = { label, icon, description,
  keywords, module, order, sidebar? }` (sidebar + busca global, já filtrados). Botões: `v-can="'leads.create'"`
  ou `$can(...)` / `auth.can(...)`. As permissões vêm de `/auth/me` (no load e no poll de 60s).
- **Novo módulo**: `permission create` para cada ação → `#[Permission]` nos métodos → `meta` nas rotas →
  `v-can` nos botões. A aba Permissões do usuário aparece sozinha com o módulo novo.
- Um não-master só concede/remove permissões que ele próprio tem.
- **Área restrita** (masters, sem chave de permissão — não dá para conceder): rota da API dentro de
  `Route::middleware('master')`; rota do front com `meta.master: true`. No menu entra como filho do pai
  **Restrita** (`meta.menu.group = RESTRICTED` em `router/index.js`: ícone de alerta, tom âmbar
  `--color-warning-text`).

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
  - `GET transactions` devolve, além da página, `totals` (receitas, despesas, saldo e nº de pendentes de
    **todas** as linhas do filtro, qualquer status) — reusa `FinanceDashboardService::totalsByType()`.
  - `POST transactions` aceita `repeat` (`installments` | `monthly`) + `repeat_count` (2–120), só na web:
    `TransactionService::createSeries()` cria a série inteira numa transação de banco, mês a mês sem estourar
    (31/01 → 28/02). Parcelado divide o total em centavos (a sobra vai na 1ª) e numera "Desc (1/N)"; mensal
    repete o valor. Lançamentos com data futura nascem `pending`. Não há vínculo entre as parcelas: depois de
    criadas, cada uma se edita sozinha.
  - `PATCH transactions/status` (`ids[]` até 100 + `status`, permissão `finance.transaction.update`): troca de
    status em lote, model a model (cada mudança entra no histórico). Ação em lote = um endpoint, nunca um laço
    de requisições no front (o rate limit `default` é 120/min).
- **Filtros**: um vocabulário só, `Transaction::scopeFiltered()` (from, to, type, category_id, category_ids[],
  source, status, min/max_amount, search) — usado pela listagem, pelo dashboard e pela exportação. No dashboard,
  `status` = `paid` (padrão) | `pending` | `all`, e os filtros valem também para o saldo anterior.
- **Exportação Excel**: `GET /api/finance/dashboard/export` (permissão `finance.export`, mesmos filtros do
  dashboard) → `FinanceExportService` (OpenSpout): abas Resumo (balancete 100% em fórmulas SOMASES sobre
  Transações), Mensal (receitas/despesas/resultado/saldo acumulado por mês, fórmulas), Transações (dados, cabeçalho
  fixo, autofiltro) e Categorias. Texto do usuário entra sempre como `StringCell` — nunca vira fórmula.
- **Telas**: `pages/finance/` — `FinanceDashboard` (filtros de período/categorias/origem/status + exportar;
  gráficos em `dashboardCharts.js`, uma pergunta por gráfico: novo gráfico = nova entrada no array), `CategoryList` (cards) / `CategoryForm`, `TransactionList` / `TransactionForm`.
  Presets de período em `utils/period.js` (inclui "Mês passado", o único intervalo fechado).
- **TransactionList**: chips de período (`.filter-chip`, global em `main.scss`) + "Tudo"; editar uma data à
  mão vira personalizado. Período, filtros e página ficam na URL (`router.replace`): voltar do formulário,
  recarregar ou compartilhar o link mostra a mesma visão — o form volta com `router.back()` quando veio da
  lista. Barra de totais acima da grade (o card de lançamentos filtra as pendentes). Ações: Duplicar (abre o
  form com `?duplicate=<id>`, data de hoje) e Efetivar (pendentes selecionadas → `PATCH status`).
- **TransactionForm**: "Salvar e lançar outra" mantém tipo, data, categoria e status e limpa valor/descrição
  (foco volta no valor). Na inclusão, "Repetição" (não repete / parcelado / todo mês) com prévia do valor
  das parcelas.

## 12. Bot do Telegram (`bot/`)

Projeto Node + TypeScript próprio (grammY, long polling), container `bot` no compose. **Nunca acessa o
banco**: só `/api/bot/*`.

- **Autenticação em duas camadas** (`App\Http\Middleware\BotMiddleware`, alias `bot`): (1) o container prova
  que é o bot com `Authorization: Bearer BOT_API_TOKEN` (comparação constante, falha fechada se vazio);
  (2) o usuário do Telegram (`X-Telegram-User-Id`) precisa ter ativado o bot e não estar revogado
  (`telegram_accounts.revoked_at`). A `TelegramAccount` vira o *causer* do activity log.
- **Empresa**: cada `TelegramAccount` pertence a uma empresa; o middleware escopa a requisição a ela
  (`Company::as`) e bloqueia com 403 `COMPANY_INACTIVE` se a licença estiver inativa (o bot mostra a mensagem).
  O fuso horário da empresa (Configurações › Financeiro) chega ao bot em `/bot/me` e define o "hoje".
- **Vínculo**: cada empresa tem seu código (`companies.telegram_code`, coluna protegida, `XXXX-XXXX-XXXX` sem
  0/O/1/I/L) gerado em Configurações › Telegram. O link `t.me/<TELEGRAM_BOT_USERNAME>?start=<código>` chega ao bot
  como `/start <código>` e vincula com um toque; digitar o código também vale (sem diferença de caixa/espaços/traços).
  Novo código invalida o anterior só para novos vínculos; revogar o código fecha novos vínculos. Um Telegram
  pertence a uma empresa por vez: para mudar, a empresa atual **remove** o vínculo (revogar só bloqueia) ou o
  próprio usuário usa **Desconectar** no bot (só pelo menu de comandos do Telegram, `/desconectar` → confirma →
  `POST /bot/disconnect` → o bot volta a pedir o código). Sem vínculo, qualquer mensagem recebe as boas-vindas
  (`codePrompt`) numa mensagem nova com `force_reply` + placeholder (`CODE_INPUT`): a caixa de texto já abre pronta
  para o código; código errado repete o pedido com o erro no topo. Conta revogada não chega a desconectar (o middleware barra), então não dá para
  driblar a revogação. Tudo em `BotActivationService`.
- **Limites** (`config/rate-limit.php`, chaveados por usuário do Telegram): `bot`, `bot-activation`
  (tentativas de código), `bot-transactions` (teto de lançamentos/hora).
- **Idempotência**: o bot envia `idempotency_key` = `chat:mensagem de confirmação`; o backend grava
  `external_id = "<telegram_user_id>:<key>"` — retry/clique duplo não duplica.
- **Conversa em cartão único**: cada tela é um `Card` (texto + botões) renderizado em `bot/src/card.ts`
  (funções puras, testadas) e desenhado por `showCard`, que **edita** a mensagem viva em vez de mandar outra;
  respostas digitadas são apagadas. Telas finais (comprovante, balancete, cancelado) ficam no histórico e o
  próximo fluxo abre abaixo delas. Botão de mensagem antiga → "esta tela expirou". Tom cordial, trata pelo nome.
  Qualquer mensagem fora de um fluxo abre o menu (`flows/menu.ts`: Novo lançamento / Consultar).
- **Idempotência no bot**: cada rascunho tem um UUID (`draft.id`) enviado como `idempotency_key`. Consultar usa `GET /api/bot/finance/summary` — os mesmos agregados do dashboard web
  (`FinanceDashboardService`), formatados como balancete em `bot/src/balance.ts`.
- **Novo fluxo no bot**: telas em `card.ts`, handlers num arquivo em `bot/src/flows/` com sua função
  `register*`, registrado em `index.ts` antes do `registerMenuFallback`, e um botão no menu principal. O bot só coleta e formata; regras e contas
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
- [ ] Tabela nova tem `company_id` NOT NULL e o model usa `BelongsToCompany` (o `TenancyTest` falha se não).
- [ ] Model novo estende `App\Models\Model`; dado sensível vai em `#[ProtectedColumns]`, nunca só em `#[Hidden]`.
- [ ] `unique`/`exists` de dados de empresa filtram pela empresa (`Rule::unique('t')->where('company_id', Company::current())`).
- [ ] Cache de dado de empresa leva o id da empresa na chave.

## 14. Multi-empresa (SaaS)

Todo registro pertence a uma empresa (`companies`). **Não existe acesso global**: a empresa 1
("Empresa Padrão", criada pela migration `000007`; os dados que já existiam foram para ela) sempre existe, não pode ser excluída e recebe os masters.

- **Isolamento** — trait `App\Models\Concerns\BelongsToCompany` em todo model de empresa:
  - escopo global filtra por `Company::current()`; **fail-closed**: sem empresa atual a consulta não traz
    nada (nunca "todas");
  - `company_id` nunca é `fillable`: ao criar vale a empresa atual (o que vier na requisição é ignorado) e
    depois não muda;
  - registro de outra empresa responde **404, igual a um id inexistente** — nada indica que ele existe.
  - O banco garante o resto: `company_id` NOT NULL + FK. `TenancyTest` falha se uma tabela nova não tiver
    `company_id` (ou não estiver na lista de tabelas globais) ou se um model não usar a trait.
- **Empresa atual** — middleware `company` (`ResolveCompany`): vem sempre do usuário logado. Só um master
  troca, mandando `X-Company-Id` (id desconhecido → volta para a empresa dele). Console/seeders/jobs:
  `Company::as($id, fn () => ...)` (restaura a anterior no fim). Antes de existir empresa (login, busca do
  token): `User::withoutGlobalScope('company')`. **Atenção:** seeder com `WithoutModelEvents` não roda o
  `creating` da trait — informe a empresa (`->for($company)`).
- **Licença** — empresa ativa = tem `token` **e** hoje ≤ `expires_at` (vazio = não expira) e não excluída.
  Inativa: os usuários dela recebem 403 `COMPANY_INACTIVE` em toda requisição (e no login) na hora; o front
  encerra a sessão com aviso. Revogar = apagar o token; gerar novo token reativa e invalida o anterior —
  e, se a empresa tem data de expiração, renova para **hoje + 30 dias** (sem data continua sem expiração). No
  cadastro a data digitada é mantida (a renovação é só a ação "Gerar token").
  Masters acessam qualquer empresa, mesmo inativa.
- **Unicidade** — dados de negócio são únicos **por empresa** (o mesmo cliente pode existir em duas). O
  e-mail de usuário é o login, então é único no sistema; a mensagem de duplicado não diz que existe (não
  revela outra empresa).
- **Troca de empresa** (`CompanySwitcher`, no topo, à esquerda do perfil): pílula com as iniciais e o nome da
  empresa atual (âmbar + "Licença inativa" quando bloqueada). Só master abre a lista (`Select` com `searchable
  :limit="7"`, opções com iniciais, rodapé "Gerenciar empresas"). Trocar grava o id, limpa localStorage (menos
  a sessão e as preferências de tela) e sessionStorage e recarrega o app — nenhum estado da empresa anterior
  sobra. Usuário comum vê a pílula sem ação.
- **Telas** — Restrita › Empresas (`pages/company/`): cadastro (CPF/CNPJ com dígito verificador, endereço
  com CEP), licença (expira em, token com copiar/gerar/revogar) e histórico. Exclusão é soft delete.
- **Financeiro** — categorias, transações e contas do Telegram são da empresa: nome de categoria é único
  por empresa, `external_id` (idempotência) também (`company_id, source, external_id`). Categorias iniciais
  do seeder entram por empresa; empresa nova começa sem categorias (cadastro em Financeiro › Categorias).
- **Segurança extra** — token de sessão salvo como hash sha256 (vazamento da tabela `users` não entrega
  sessões); `/api/status` só para master; tokens e `master` são colunas protegidas (seção 5).

## 15. Configurações

Página `/settings` (menu Configurações, aba na URL: `?tab=telegram`). Abas da **empresa atual**, cada uma com sua
permissão (`settings.company | appearance | finance | telegram`), e **Preferências**, do próprio usuário (sem
permissão). Backend: `SettingsController` + `SettingsService` (dono das chaves, padrões e regras).

- **Armazenamento** — `companies.settings` e `users.preferences` (JSON). `SettingsService::COMPANY_DEFAULTS` /
  `PREFERENCE_DEFAULTS` definem as chaves; ao ler, os padrões completam o que falta e chaves desconhecidas somem.
  Configuração nova = chave nos defaults + regra em `sectionRules()` + campo na aba. Nada vem do id da requisição:
  tudo vale para `Company::current()` (master trocando de empresa configura a empresa escolhida).
- **Na sessão** — `/auth/me` traz `company.settings` e `preferences`; o front lê de `auth.settings` /
  `auth.preferences` (nunca busca de novo por tela). Depois de salvar uma aba da empresa: `auth.refreshUser()`.
- **Empresa** — a própria empresa edita cadastro e endereço; licença (`expires_at`, token) continua só do master.
- **Aparência** — cor principal (prévia ao vivo no app inteiro; sair sem salvar restaura) e nome exibido (menu e aba
  do navegador). Sem logo por enquanto.
- **Financeiro** — status inicial do formulário de transação, período inicial da lista e do dashboard financeiro,
  fuso horário do bot.
- **Telegram** — código/link de vínculo (gerar, gerar novo, revogar) e contas vinculadas (revogar, reativar, remover).
- **Preferências** — menu aberto ou só ícones (o botão do topo grava a mesma preferência), densidade das tabelas
  (`AgGrid` usa linha de 34px na compacta) e página inicial (só telas que o usuário acessa; senão o 1º item do menu).
