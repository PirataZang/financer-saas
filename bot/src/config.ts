// Every setting comes from the environment (root .env via docker-compose). Fails fast on boot.
function required(name: string): string {
  const value = process.env[name]?.trim()
  if (!value) throw new Error(`Variável de ambiente obrigatória ausente: ${name}`)
  return value
}

export const config = {
  telegramToken: required('TELEGRAM_BOT_TOKEN'),
  // Optional sanity check: the token must belong to this bot.
  telegramBotId: process.env.TELEGRAM_BOT_ID?.trim() || null,
  backendUrl: required('BACKEND_URL').replace(/\/+$/, ''),
  // Shared secret with the backend (BotMiddleware). Never the Telegram token.
  backendToken: required('BOT_API_TOKEN'),
  // "Today" for new entries is the user's day, not the server's UTC day.
  timezone: process.env.BOT_TIMEZONE?.trim() || 'America/Sao_Paulo',
}

/** "Today" for this chat: the company's timezone (session, from /bot/me), else BOT_TIMEZONE. */
export const timezoneOf = (ctx: { session: { timezone?: string } }): string => ctx.session.timezone ?? config.timezone
