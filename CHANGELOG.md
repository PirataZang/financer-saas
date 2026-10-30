# Changelog

Todas as mudanças notáveis deste projeto ficam neste arquivo.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o projeto usa [Versionamento Semântico](https://semver.org/lang/pt-BR/).

<!--
INSTRUÇÕES PARA IA (Claude, Copilot, Cursor, ChatGPT…) — leia antes de editar.

Este arquivo é lido pelo DevScope (módulo Releases) na hora de lançar:
  - a seção "## [X.Y.Z] - AAAA-MM-DD" da versão vira a MENSAGEM DA TAG e as
    notas da Release no GitHub — escreva para quem vai ler a página da release;
  - se existe seção para a próxima patch, minor ou major, essa é a versão que
    o DevScope sugere. Então a versão escolhida aqui é a que vai ser lançada.

Como escrever:
  1. Levante o que mudou desde a última release, sem inventar nada:
       git log $(git describe --tags --abbrev=0)..HEAD --oneline
     e o diff quando a mensagem do commit não disser o efeito.
  2. Novidades entram em "## [Unreleased]". Na hora de lançar, renomeie para
     "## [X.Y.Z] - AAAA-MM-DD" e deixe um "## [Unreleased]" vazio acima dela.
  3. Agrupe em ### Added, ### Changed, ### Deprecated, ### Removed, ### Fixed e
     ### Security — nesta ordem, só os que tiverem item.
  4. Um item por mudança que o USUÁRIO percebe, não um por commit. Comece pelo
     efeito ("Login lembra o último e-mail"), não pela implementação
     ("refatora AuthService"). Nome da funcionalidade em negrito.
  5. Escolha a versão pelo maior impacto: algo removido ou incompatível →
     major; funcionalidade nova → minor; só correção ou ajuste pequeno → patch.
  6. Escreva no idioma deste arquivo. Não apague nem reescreva versões já
     lançadas.
-->

## [Unreleased]

### Added

- **Integração com o Mercado Pago** (Configurações › Integrações): cada empresa conecta a própria conta com o Access Token (guardado criptografado) e o
  financeiro passa a receber, a cada 30 minutos, vendas e recebimentos por Pix, cartão e boleto, tarifas e
  estornos, compras e, se ligado em Editar, Pix/transferências enviados (via relatório Liberações; cada pedido gera um relatório na conta, por isso é opcional), em categorias da integração. Somente leitura: o sistema não cobra, não estorna e não transfere.
  Card com ativar/desativar, editar, sincronizar agora e remover (apaga tudo o que a integração trouxe).
- **Fila de jobs**: container `queue` e jobs por empresa para sincronizar e pedir o relatório de Pix enviados (o scheduler
  só enfileira); o relatório pedido é lido sozinho ~90 s depois, sem clicar de novo.
- **Lançamentos do Mercado Pago na tela de editar**: seção bloqueada "Dados do Mercado Pago" (ID, status, meio de
  pagamento, parcelas, valor, tarifa, líquido, estorno e datas). Tipo, valor, data e status ficam travados (também na API e
  no "Efetivar" em lote, porque o próximo sync os sobrescreveria); descrição, categoria e cliente continuam editáveis.
- **Painel do Mercado Pago no Dashboard Financeiro**: recebido, tarifas, estornos e líquido, e gráficos de rosca
  por meio de pagamento, status, destino do valor recebido e pagamentos feitos pela conta. Receitas mostram quanto
  veio do Mercado Pago, e um gráfico compara as origens.
- **Personalizar o dashboard**: cada usuário escolhe quais gráficos aparecem.
- **Dicas nos campos**: ícone ⓘ ao lado do rótulo explica o campo ao passar o mouse ou tocar.

- **Metas**: nova tela em Financeiro › Metas para acompanhar metas de **investimento** (juntar um valor até um prazo)
  e **tetos de gastos** (não passar de um valor no período). Cada meta cria a própria categoria: o que for lançado
  nela é o dinheiro separado da meta, com progresso, quanto falta, prazo, quanto guardar por mês e se a meta foi
  atingida. Botões para aportar, resgatar ou lançar um gasto direto na meta.
- **Dashboard financeiro**: novo card com o progresso das metas; os gráficos agora ficam lado a lado.

- **Portal do cliente**: novo acesso para os clientes da empresa, pensado para o celular. O cliente entra com o
  e-mail do cadastro e um código enviado por e-mail e acompanha seus projetos (etapas e progresso), suas contas
  (em aberto, vencidas e pagas, com a chave PIX para copiar) e os avisos da empresa. Só leitura.
- **Projetos** (menu Portal do cliente): projetos por cliente com status, datas e etapas ordenáveis; o progresso
  sai das etapas concluídas. Com histórico de alterações.
- **Avisos** (menu Portal do cliente): comunicados para todos os clientes ou para o cliente de um projeto.
- **Cliente na receita**: vincular uma receita a um cliente faz ela aparecer no portal dele como conta.
- **Configurações › Portal do cliente**: link do portal para enviar aos clientes, chave PIX e instruções de pagamento.
- **Link do portal em Configurações › Empresa**: copiar, enviar por WhatsApp ou e-mail com uma mensagem pronta, ou abrir.

### Fixed

- **Chave do app**: o `docker-compose` passava um `APP_KEY` vazio que anulava o do `backend/.env`.
- **E-mails**: o backend cria as pastas de cache/views do Laravel na subida do container; sem elas, nenhum
  e-mail era renderizado.

### Security

- **PHP 8.3.36** na imagem do backend (correções de memória CVE-2026-109618 e CVE-2026-109619).
- **Banco e Redis** só escutam no loopback do servidor (acesso remoto por túnel SSH).
- **Cabeçalhos de segurança** em toda resposta da API (sem cache, sem iframe, sem MIME sniffing, HSTS em HTTPS).
