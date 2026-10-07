import { Bot, session } from 'grammy'
import { config } from './config.js'
import { registerActivation, replyError } from './flows/activation.js'
import { registerConsult } from './flows/consult.js'
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
registerActivation(bot)
registerTransaction(bot)
registerConsult(bot)
registerMenuFallback(bot)
// Only .message: the underlying fetch error embeds the request URL, which contains the bot token.
bot.catch((error) => log('error', { message: error.message }))
process.on('unhandledRejection', (error) => {
  log('fatal', { message: error instanceof Error ? error.message : String(error) })
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
await bot.api.setMyCommands([
  { command: 'start', description: 'Menu: lançamento ou consulta' },
  { command: 'cancelar', description: 'Cancelar a operação atual' },
])

for (const signal of ['SIGINT', 'SIGTERM'] as const) process.once(signal, () => void bot.stop().catch(() => {}))

log('bot.started', { username: bot.botInfo.username })
await bot.start().catch((error: Error) => {
  log('fatal', { message: error.message })
  process.exit(1)
})
