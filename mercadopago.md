# Mercado Pago — planejamento do módulo de cobranças

Status: **planejamento inicial** (nada disto está implementado). Este documento define o que vamos construir, em que
ordem e com quais decisões ainda abertas. Detalhes de endpoints marcados com _(confirmar)_ devem ser verificados na
documentação oficial (`/developers/llms.txt` e a busca do MCP) na Fase 0, antes de escrever código.

## 1. Objetivo

Hoje a integração **monitora** a conta Mercado Pago (importa recebimentos, compras, tarifas, estornos e, opcionalmente,
Pix enviados). O próximo passo é **usar a API de pagamentos**: emitir cobranças (Pix, boleto, link de pagamento,
parcelamento no cartão, carnê) e assinaturas, vinculadas aos clientes do sistema, com uma área própria no menu e telas
dedicadas.

Princípios:

1. **Separação clara de origens.** O menu **Financeiro** continua geral (transações, dashboard, metas) e não muda.
   O que for exclusivo do Mercado Pago vive no menu **Mercado Pago**. Cada lançamento continua com sua origem
   (`manual`, `telegram`, `import`, `mercado_pago`), então dá para filtrar exatamente o que veio de cada lugar.
2. **O dinheiro cai na conta de cada empresa** (decidido): as cobranças são criadas com o Access Token da própria
   empresa. Sem comissão de plataforma por enquanto.
3. **Só aparece se estiver ativo.** Sem integração conectada **e** ativa, o menu, as rotas e as permissões do módulo
   não existem para ninguém (nem master).
4. **Nenhum dado de cartão passa pelo nosso sistema.** Cartão só em páginas do próprio Mercado Pago (Checkout Pro e
   autorização de assinatura). Pix e boleto são gerados por API, sem dado sensível.
5. **UX primeiro.** Cada fluxo tem estados de carregando, vazio e erro; ações destrutivas pedem confirmação; o que o
   cliente paga atualiza a tela sozinho.

## 2. Escopo

| Capacidade | Como | Observação |
|---|---|---|
| **Pix** | Orders API (ou Payments API) _(confirmar)_ | Devolve QR e código "copia e cola"; expira (padrão do Mercado Pago, configurável). |
| **Boleto** | Orders/Payments API _(confirmar)_ | Exige CPF/CNPJ e endereço do pagador; compensa em até 3 dias úteis. |
| **Link de pagamento** | Checkout Pro (`/checkout/preferences`) | Página do Mercado Pago com Pix, boleto e cartão; é o único caminho para cartão. |
| **Parcelamento no cartão** | Opções da preferência (máximo de parcelas) _(confirmar o que dá para controlar por API; juros/sem juros também depende da conta)_ | Vive dentro do link de pagamento. |
| **Carnê** (parcelamento próprio) | N cobranças Pix/boleto com vencimentos mensais, geradas de uma vez | Feature nossa; o Mercado Pago só vê N cobranças. |
| **Assinaturas** | `preapproval_plan` + `preapproval` _(confirmar modelo)_ | Autorização do cartão no Mercado Pago (sem cartão no nosso sistema). |
| **Cancelar / reembolsar** | Cancelamento de cobrança pendente; reembolso total ou parcial _(confirmar)_ | Com confirmação e registro de auditoria. |
| **Conciliação** | Webhook + sync existente | Ver seção 6. |

Fora de escopo (por ora): comissão de plataforma (Marketplace/OAuth), Point/QR presencial, Wallet Connect, SmartApps,
Payouts, saques, antecipação, Open Finance.

## 3. Menu, rotas e permissões

### 3.1 Menu

Novo grupo na sidebar, ao lado de "Financeiro" (mesmo mecanismo `MENU_GROUPS` e `meta.menu`):

```
Mercado Pago                      (grupo; só existe com a integração ativa)
├─ Visão geral                    dashboard do Mercado Pago
├─ Cobranças                      lista + nova cobrança
├─ Assinaturas                    assinaturas e planos
├─ Pagamentos                     tudo que passou pela conta (o que o sync já espelha)
└─ Ajustes                        padrões de cobrança, webhook, saúde da conexão
```

Rotas: prefixo `/mercado-pago/...`. Conectar/desconectar o token continua em **Configurações › Integrações**.

### 3.2 Permissões (módulo `mercadopago`)

Novo módulo em `config/permissions.php`:

| Chave | Para quê |
|---|---|
| `mercadopago.view` | Ver visão geral, cobranças, assinaturas e pagamentos |
| `mercadopago.charge.create` | Criar cobranças e carnês |
| `mercadopago.charge.cancel` | Cancelar cobranças pendentes |
| `mercadopago.charge.refund` | Reembolsar (total/parcial) |
| `mercadopago.subscription.create` | Criar planos e assinaturas |
| `mercadopago.subscription.update` | Pausar, retomar, alterar valor e cancelar |
| `mercadopago.settings` | Editar os padrões do módulo |

### 3.3 Regra "só se estiver integrado e ativo" — um ponto único

A decisão fica em **um lugar** no backend e todo o resto herda:

- `User::permissionKeys()` remove as chaves `mercadopago.*` quando a empresa atual não tem integração Mercado Pago
  **conectada e ativa** (o cache de permissões passa a considerar esse estado e é invalidado ao conectar, ativar,
  desativar e remover a integração).
- Consequências automáticas: `/auth/me` não envia as chaves → o front esconde o menu (`menuItems` já filtra por
  permissão), bloqueia as rotas (guard) e some com o módulo da tela de permissões de usuários; a API responde 403 em
  qualquer endpoint do módulo (`CheckPermission`).
- Desativar a integração esconde o módulo na hora, **sem apagar** cobranças e assinaturas (voltam quando reativar).
- Webhooks continuam sendo recebidos e processados mesmo com o módulo escondido, para não perder pagamentos.

## 4. Telas e UX

Reaproveita o que já existe: `PageHeader`, `StatusBadge`, `Tabs`, `EChart`, `InfoTip`, `FormActions`, `Modal`,
`CustomerSelect`, `CategorySelect`, a seção bloqueada do `TransactionForm` (dados do Mercado Pago) e o padrão de
lista/filtros por URL de `TransactionList`.

### 4.1 Visão geral (dashboard do Mercado Pago)

- Filtro de período igual ao do Dashboard Financeiro.
- **Cards**: Recebido, A receber (pendentes), Vencidas, Taxa de aprovação, Tarifas, Líquido.
- **Gráficos**: recebido por dia, por meio de pagamento (Pix/boleto/cartão), funil de cobranças (criadas → pagas) e,
  quando houver assinaturas, receita recorrente mensal e cancelamentos.
- **Listas curtas**: próximas a vencer, vencidas (com "Cobrar de novo"), últimos eventos recebidos.
- O painel do Mercado Pago que hoje está no Dashboard Financeiro passa a viver aqui; no Financeiro fica um cartão
  resumo com link "Ver no Mercado Pago" e o gráfico de origens (decisão aberta, seção 9).

### 4.2 Cobranças

Lista com abas por status (**Todas · Pendentes · Pagas · Vencidas · Canceladas**), busca, filtros (cliente, tipo,
período) guardados na URL.

```
┌ Cobranças ──────────────────────────────────────── [ + Nova cobrança ] ┐
│ [Todas 42] [Pendentes 8] [Pagas 30] [Vencidas 3] [Canceladas 1]        │
│ 🔎 Buscar cliente ou descrição      Tipo ▾   Período ▾                 │
├────────────────────────────────────────────────────────────────────────┤
│ ◉ Ana Souza        Plano mensal         R$ 150,00  [Pix]   ● Pendente  │
│                    vence 30/10 · expira em 2h              ⧉ Copiar  ⋯ │
│ ◉ Loja Aurora      Identidade visual    R$ 515,02  [Boleto] ● Paga     │
│                    paga em 25/10                           ↗ Ver     ⋯ │
└────────────────────────────────────────────────────────────────────────┘
```

- Badge de **tipo** (Pix, Boleto, Link, Carnê 2/6) e de **status** com cor e ícone (não só cor).
- Ação principal por linha conforme o estado: pendente → **Copiar** (Pix: código; boleto/link: URL); paga → **Ver**;
  vencida → **Cobrar de novo**. Menu `⋯`: cancelar, reembolsar, ver cliente, ver lançamento.
- Estado vazio com chamada "Crie sua primeira cobrança" e atalhos (Pix, Boleto, Link).

### 4.3 Nova cobrança (assistente de 3 passos, em tela cheia no mobile)

1. **Cliente** — `CustomerSelect` com "cadastrar novo" inline. Mostra o que falta para a forma escolhida (boleto exige
   CPF/CNPJ e endereço; Pix e link, só o e-mail) e deixa completar ali mesmo.
2. **Valor e forma** — controle segmentado **Pix · Boleto · Link de pagamento · Carnê**; os campos mudam conforme a
   forma: valor, descrição, vencimento/expiração, categoria financeira, parcelas máximas (link), quantidade e
   periodicidade (carnê). Aviso claro do que cada forma cobra de tarifa _(a confirmar se a API informa)_.
3. **Revisão** — resumo e uma **prévia de como o cliente verá** (valor, descrição, vencimento). Botão **Criar cobrança**.

Resultado imediato (mesma tela, sem navegar):
- **Pix**: QR grande, código "copia e cola" com botão Copiar, contagem regressiva da expiração.
- **Boleto**: linha digitável com Copiar, botão "Abrir/baixar boleto".
- **Link**: URL com Copiar e atalhos de compartilhar (WhatsApp, e-mail).
- Em todos: "Ver cobrança" e "Criar outra".

### 4.4 Detalhe da cobrança

- Cabeçalho com status e valor; **linha do tempo** (criada → enviada/copiada? → paga/vencida/cancelada).
- Enquanto pendente, a tela **atualiza sozinha** (assinatura de eventos por polling curto + webhook), virando "Paga" na
  hora, com toast.
- Seção bloqueada "Dados do Mercado Pago" (mesmo padrão do lançamento): ID, status, meio, parcelas, valor, tarifa,
  líquido, datas.
- Vínculos: cliente, lançamento financeiro gerado, e (se houver) o carnê/assinatura de origem.
- Ações: copiar, reenviar, cancelar, reembolsar (com valor parcial), todas com confirmação (`swal.confirmDelete`).

### 4.5 Assinaturas

- **Lista**: cliente, plano/valor, periodicidade, próxima cobrança, status (Aguardando autorização · Ativa · Pausada ·
  Cancelada). Aba **Planos** para planos reutilizáveis.
- **Nova assinatura**: cliente, valor, periodicidade (mensal/anual), início e fim opcional → gera o **link de
  autorização** (o cliente autoriza o cartão no Mercado Pago). A assinatura fica "Aguardando autorização" até chegar o
  evento.
- **Detalhe**: cobranças geradas, próxima data, pausar/retomar/cancelar, alterar valor.

### 4.6 Pagamentos

Tudo que o sync espelha da conta (inclusive o que **não** nasceu no sistema): filtros por direção (entrada/saída), meio e
status, com o mesmo painel bloqueado de dados. É o "extrato do Mercado Pago" dentro do módulo, separado do Financeiro.

### 4.7 Ajustes

- **Padrões**: dias até o vencimento, multa e juros do boleto _(se a API permitir)_, expiração do Pix, parcelas
  máximas, descrição na fatura do cartão, categoria padrão das receitas.
- **Webhook e saúde**: URL pública, último evento recebido, falhas recentes, botão "Testar", aviso "reconectar" se o
  token foi revogado.

### 4.8 Pontos de contato fora do módulo

- **Cliente**: aba/seção "Cobranças" (Mercado Pago) com o histórico e o botão **Cobrar**.
- **Portal do cliente** (`PortalBills`): a cobrança em aberto ganha **Pagar agora** (abre o link/Pix) no lugar das
  instruções manuais de Pix.
- **Financeiro**: continua igual. Lançamentos de cobrança aparecem lá como origem "Mercado Pago" e abrem a tela de
  edição com a seção bloqueada.

### 4.9 Qualidade de interface (vale para todas as telas)

Mobile-first (o portal é usado no celular), estados de carregando/vazio/erro, foco e navegação por teclado, status por
cor **e** ícone/texto, valores sempre em `formatMoney`, datas em `formatDate`, "Copiar" com confirmação visual e
`aria-live`, confirmação em ações destrutivas, mensagens de erro do Mercado Pago traduzidas para ações possíveis
("CPF inválido — corrija no cadastro do cliente").

## 5. Dados

Todas as tabelas com `company_id` e o escopo de empresa já usado no projeto, decimais para dinheiro, auditoria via
`Auditable`. Nomes provisórios.

| Tabela | Conteúdo |
|---|---|
| `mercado_pago_charges` | Cobrança única: cliente, tipo (`pix`/`boleto`/`link`/`carne_parcela`), valor, descrição, vencimento/expiração, `external_reference` (nosso UUID), ID no Mercado Pago, estado local, dados de pagamento (QR, linha digitável, URL), quem criou, `paid_at`, `canceled_at` |
| `mercado_pago_charge_plans` | Carnê: cliente, valor total, quantidade, periodicidade; cada parcela é uma linha em `mercado_pago_charges` |
| `mercado_pago_subscriptions` | Cliente, `preapproval_id`, plano opcional, valor, periodicidade, status, próxima cobrança, link de autorização |
| `mercado_pago_plans` | Planos reutilizáveis (`preapproval_plan`) |
| `mercado_pago_events` | Log de webhooks: tópico, recurso, recebido em, processado em, resultado (idempotência e diagnóstico) |

Ligações:
- Cobrança → `customer_id` (existente) e → lançamento financeiro (`finance_transactions`, `source = mercado_pago`,
  `external_id` = ID do pagamento). O lançamento nasce **pendente** na criação e é atualizado pelo sync/webhook (o
  mesmo `external_id` que o sync já usa, então não duplica).
- Pagamentos que o sync espelha (`mercado_pago_payments`) continuam sendo a fonte da aba **Pagamentos**; uma cobrança
  aponta para o pagamento correspondente quando ele existe.
- `integrations.settings` ganha os padrões do módulo; a URL/segredo do webhook ficam no `credentials` criptografado.

## 6. Arquitetura (segue os padrões do repo)

**Cliente da API.** `MercadoPagoClient` continua sendo a única porta, agora com uma lista **explícita de escritas**
permitidas (criar pagamento/preferência/assinatura, cancelar, reembolsar) além das leituras, sempre com
`X-Idempotency-Key` nas criações. A promessa "somente leitura" do card de Integrações é atualizada para refletir isso
(o monitoramento continua só lendo; escrever só acontece por ação explícita do usuário no módulo).

**Serviços.** `MercadoPagoChargeService`, `MercadoPagoSubscriptionService`, `MercadoPagoDashboardService`,
`MercadoPagoWebhookService`; controllers finos com `#[Permission('mercadopago.…')]`; validação no padrão de `rules()`.

**Conciliação — webhook como gatilho, API como verdade.**
1. Rota pública `POST /api/webhooks/mercado-pago/{token}` com `throttle`, token longo e único por empresa (na URL
   enviada em cada cobrança via `notification_url`).
2. O corpo só diz "o pagamento X mudou". O sistema **busca o pagamento no Mercado Pago com o token da empresa** (não
   confia no corpo), compara `date_last_updated` (ignora eventos fora de ordem) e atualiza cobrança, espelho e
   lançamento numa transação. Registro em `mercado_pago_events` (idempotente por recurso + versão).
3. A assinatura `x-signature` é validada quando a empresa informar o segredo do webhook do app dela (campo opcional na
   conexão); mesmo sem ela, o passo 2 impede que uma notificação falsa altere dados.
4. O sync de 30 minutos que já existe vira a **rede de segurança**: se um webhook falhar, o status é corrigido no
   próximo ciclo. Em desenvolvimento local (sem URL pública) só o sync atua, e a tela tem "Atualizar".

**Sem dado sensível de cartão.** Cartão e assinatura só por páginas do Mercado Pago.

## 7. Fases

| Fase | Entrega | Pronto quando |
|---|---|---|
| **0. Verificação** | Confirmar endpoints/campos na documentação (Pix e boleto via Orders vs Payments, preferência, `preapproval`, cancelamento, reembolso, parcelas); tentar criar usuário de teste de novo; definir a URL pública do webhook | Tabela "endpoint → payload → resposta" validada; decisão Orders vs Payments registrada |
| **1. Fundação** | Módulo de permissões + regra "integrado e ativo" no ponto único; grupo de menu e rotas vazias; tabelas; escritas no cliente; receptor de webhook + log de eventos | Menu some/aparece ao ativar/desativar; webhook de teste reconcilia um pagamento |
| **2. Pix e link** | Nova cobrança (Pix, Link), lista, detalhe com atualização automática, vínculo ao cliente e ao lançamento, "Pagar agora" no portal | Cliente paga um Pix de teste e a cobrança, o lançamento e o portal viram "Paga" sozinhos |
| **3. Boleto, carnê, cancelar, reembolsar** | Boleto com validação de dados do pagador, carnê, cancelamento e reembolso total/parcial | Carnê de 3 parcelas criado; cancelar e reembolsar com confirmação e auditoria |
| **4. Assinaturas** | Planos, assinaturas, link de autorização, pausar/retomar/cancelar, eventos de cobrança recorrente | Assinatura autorizada gera cobranças e lançamentos a cada ciclo |
| **5. Visão geral e Ajustes** | Dashboard do Mercado Pago, mover/encolher o painel do Financeiro, página de Ajustes e saúde do webhook | Números batem com o extrato do Mercado Pago do período |
| **6. Endurecimento** | Testes (feature + front), limites de taxa, mensagens de erro, docs (`design.md` seção 18, README, CHANGELOG), checklist de produção | Suíte verde; `mp-review` sem pendências |

Cada fase é entregável e útil sozinha; a 2 já resolve "cobrar um cliente por Pix ou link".

## 8. Testes e segurança

- **Testes automáticos**: `Http::fake` para todo o Mercado Pago (como em `IntegrationTest`), cobrindo criação, expiração,
  cancelamento, reembolso, webhook fora de ordem/duplicado, isolamento entre empresas, permissão negada e módulo
  oculto com a integração inativa.
- **Teste real**: o Mercado Pago não tem sandbox; usar o token de produção com valores mínimos (R$ 1) ou usuários de
  teste, se a criação voltar a funcionar. Sempre em conta que o dono controla.
- **Segurança**: token por empresa criptografado (já é); webhook com token na URL + verificação ativa na API;
  `X-Idempotency-Key`; limites de taxa nas criações; nunca confiar em valor/cliente vindos do navegador para cobrar
  (recalcular no servidor); dados do pagador mínimos e só quando exigidos (CPF/endereço para boleto); sem cartão.

## 9. Decisões em aberto

1. **Pix e boleto**: Orders API ou Payments API? (Fase 0 decide; preferência: Orders, a API atual.)
2. **Assinaturas**: com plano reutilizável, sem plano, ou os dois? (Sugestão: com plano para mensalidades iguais e sem
   plano para casos avulsos.)
3. **Painel do Mercado Pago no Dashboard Financeiro**: mover tudo para o módulo e deixar só um cartão-resumo, ou manter
   como está? (Sugestão: mover.)
4. **Notificar o cliente**: só copiar/compartilhar o link, ou enviar e-mail pelo sistema (já existe e-mail para o
   portal)? WhatsApp seria só atalho de compartilhar.
5. **Multa e juros do boleto, e "sem juros" no cartão**: dependem do que a API aceita; decidir na Fase 0.
6. **Comissão da plataforma** (Marketplace) fica para depois; o desenho atual não impede adicioná-la.
7. **Necessidade de um interruptor extra** "Habilitar cobranças" em Integrações, ou basta o módulo estar ativo +
   permissão `mercadopago.charge.create`? (Sugestão: basta a permissão.)
