import { Bot, session } from 'grammy'
import { config } from './config.js'
import { registerActivation, replyError } from './flows/activation.js'
import { registerConsult } from './flows/consult.js'
import { registerDisconnect } from './flows/disconnect.js'
import { registerMenu, registerMenuFallback } from './flows/menu.js'
import { registerTransaction } from './flows/transaction.js'
import { log } from './log.js'
import { type BotContext, initialSession } from './session.js'

const bot = new Bot<BotContext>(config.telegramToken)

// Private chats only: in a group, anyone could drive another user's activated session.
bot.use(async (ctx, next) => {
  if (ctx.chat?.type === 'private' && ctx.from) await next()
})
bot.use(session({ initial: initialSession }))
bot.use(async (ctx, next) => {
  try {
    await next()
  } catch (error) {
    await replyError(ctx, error)
  }
})

// Order matters: commands first (so "/start" is never read as an amount), then each flow's step
// handlers, then the fallback that greets on any other message. A new flow = a new file in
// flows/ with its own register function + a button in the main menu (flows/menu.ts).
registerMenu(bot)
// Before activation: "/desconectar" typed while waiting for a code must not be read as the code.
registerDisconnect(bot)
registerActivation(bot)
registerTransaction(bot)
registerConsult(bot)
registerMenuFallback(bot)
// Only .message: the underlying fetch error embeds the request URL, which contains the bot token.
bot.catch((error) => log('error', { message: error.message }))
// A stray rejection (e.g. a network blip in a fire-and-forget call) must not take the bot down.
process.on('unhandledRejection', (error) => log('unhandled', { message: error instanceof Error ? error.message : String(error) }))
process.on('uncaughtException', (error) => {
  log('fatal', { message: error.message })
  process.exit(1)
})

try {
  await bot.init()
} catch (error) {
  log('fatal', { message: error instanceof Error ? error.message : String(error) })
  process.exit(1)
}
if (config.telegramBotId && String(bot.botInfo.id) !== config.telegramBotId) {
  throw new Error(`TELEGRAM_BOT_TOKEN pertence ao bot ${bot.botInfo.id}, não a TELEGRAM_BOT_ID=${config.telegramBotId}.`)
}
// Bot profile: what people see in the empty chat before the first message, and the command list.
await Promise.all([
  bot.api.setMyCommands([
    { command: 'start', description: 'Abrir o menu' },
    { command: 'cancelar', description: 'Descartar o lançamento atual' },
    { command: 'desconectar', description: 'Desvincular da empresa para usar outro código' },
  ]),
  bot.api.setMyShortDescription('Registre receitas e despesas e consulte seu balancete, direto pelo Telegram.'),
  bot.api.setMyDescription(
    'Seu assistente financeiro.\n\n'
      + '• Registre receitas e despesas em poucos toques\n'
      + '• Consulte o balancete do mês, do mês passado ou do ano\n\n'
      + 'Os lançamentos vão direto para o sistema financeiro. Envie qualquer mensagem para começar.',
  ),
]).catch((error: Error) => log('profile.failed', { message: error.message }))

log('bot.started', { username: bot.botInfo.username })

// Long polling dies on transient network errors (Telegram/VPS hiccups, 409 during a redeploy).
// Restart it with backoff instead of exiting; a run that lasted a while resets the backoff.
let stopping = false
for (const signal of ['SIGINT', 'SIGTERM'] as const) {
  process.once(signal, () => {
    stopping = true
    void bot.stop().catch(() => {})
  })
}
let delay = 1_000
while (!stopping) {
  const startedAt = Date.now()
  try {
    await bot.start({ drop_pending_updates: false })
  } catch (error) {
    log('polling.failed', { message: error instanceof Error ? error.message : String(error), retryInMs: delay })
  }
  if (stopping) break
  delay = Date.now() - startedAt > 60_000 ? 1_000 : Math.min(delay * 2, 30_000)
  await new Promise((resolve) => setTimeout(resolve, delay))
}
