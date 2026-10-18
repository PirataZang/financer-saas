// Company appearance (Configurações › Aparência): the primary color is a CSS variable on <html>;
// main.scss derives hover/soft/muted from it. Brand name = menu + browser tab.

export const APP_NAME = 'Financial Bot'

export function applyAppearance(appearance = {}) {
  const root = document.documentElement
  if (appearance.primary_color) root.style.setProperty('--color-primary', appearance.primary_color)
  else root.style.removeProperty('--color-primary')
  document.title = appearance.brand_name || APP_NAME
}

export function resetAppearance() {
  applyAppearance()
}

// WCAG contrast of white text on `hex` (buttons, avatars and active items use white on primary).
export function contrastWithWhite(hex) {
  const channel = (i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }
  const luminance = 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)
  return 1.05 / (luminance + 0.05)
}
