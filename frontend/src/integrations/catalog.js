import mercadoPagoLogo from '@/assets/integrations/mercado-pago.svg'

// Configurações › Integrações: what each provider is, what it does and what its card edits.
// The backend (GET /integrations) says which ones exist and their state; this file only presents
// them. New integration = new entry here (key = backend IntegrationProvider value) + its logo.
//   fields: the card's "Editar" form, saved to the integration's settings
//     { key, type: 'date' | 'switch', label, tip, onText?, offText? }
export const INTEGRATIONS = {
  mercado_pago: {
    name: 'Mercado Pago',
    logo: mercadoPagoLogo,
    // What the "Conectar" form asks: the account's own credential, kept encrypted by the backend.
    credential: {
      label: 'Access Token',
      tip: 'No Mercado Pago Developers, abra sua aplicação › Credenciais de produção e copie o Access Token. Ele fica criptografado, não aparece de novo e só é usado para leituras.',
      placeholder: 'APP_USR-...',
      link: 'https://www.mercadopago.com.br/developers/panel/app',
    },
    summary: 'Acompanhe os recebimentos da sua conta Mercado Pago e veja tudo junto no financeiro.',
    capabilities: [
      { icon: 'fa-solid fa-arrows-rotate', text: 'Importa a cada 30 minutos vendas e recebimentos por Pix, cartão e boleto.' },
      { icon: 'fa-solid fa-money-bill-transfer', text: 'Opcional: lança como despesa os Pix e transferências enviados (via relatório da conta, com atraso).' },
      { icon: 'fa-solid fa-receipt', text: 'Lança tarifas, estornos e contestações como despesas, nas categorias da integração.' },
      { icon: 'fa-solid fa-chart-pie', text: 'Mostra meios de pagamento, status e líquido recebido no Dashboard Financeiro.' },
    ],
    // Said up front: what it never does (the integration is monitoring only).
    limits: 'Somente leitura: o financial-bot não cria cobranças, não estorna, não transfere e não emite boletos. Só pede o relatório de movimentações da conta.',
    fields: [
      {
        key: 'import_from',
        type: 'date',
        label: 'Importar desde',
        tip: 'Dia a partir do qual a primeira sincronização busca pagamentos (até 1 ano atrás). Em branco, busca os últimos 90 dias. Depois disso, só o que mudou é buscado.',
      },
      {
        key: 'fees',
        type: 'switch',
        label: 'Lançar tarifas',
        onText: 'Tarifas viram despesas',
        offText: 'Não lançar tarifas',
        tip: 'Lança a tarifa que o Mercado Pago cobra em cada venda como despesa em "Tarifas · Mercado Pago". Desligado, só o valor bruto da venda entra como receita.',
      },
      {
        key: 'transfers',
        type: 'switch',
        label: 'Pix e transferências enviados',
        onText: 'Importar enviados',
        offText: 'Não importar',
        tip: 'O Mercado Pago só entrega o que você enviou por um relatório da conta. Ligado, o sistema pede esse relatório às 8h, 15h e 20h (horário de Brasília) e quando você clica em Sincronizar agora, e cada pedido gera um aviso "foi gerado um relatório" no Mercado Pago, que também demora para listar movimentos recentes. Desligado, nenhum relatório é pedido.',
      },
      {
        key: 'pending',
        type: 'switch',
        label: 'Pagamentos aguardando',
        onText: 'Lançar como pendentes',
        offText: 'Só aprovados',
        tip: 'Boletos emitidos e Pix ainda não pagos entram como receitas pendentes e viram efetivadas quando o Mercado Pago aprovar. Desligado, só pagamentos aprovados são lançados.',
      },
    ],
  },
}
