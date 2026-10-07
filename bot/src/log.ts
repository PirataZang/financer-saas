// One JSON line per event (docker logs friendly). Never log message text: it may hold
// activation codes or financial details.
export function log(event: string, data: Record<string, unknown> = {}): void {
  console.log(JSON.stringify({ at: new Date().toISOString(), event, ...data }))
}
