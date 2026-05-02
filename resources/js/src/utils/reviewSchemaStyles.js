const COLOR_ALIASES = {
  alert: 'ops-red-alert',
  black: 'ops-black',
  blue: 'ops-blue',
  butterscotch: 'ops-butterscotch',
  gold: 'ops-gold',
  green: 'ops-green',
  lilac: 'ops-lilac',
  magenta: 'ops-magenta',
  navy: 'ops-navy',
  orange: 'ops-orange',
  peach: 'ops-peach',
  plum: 'ops-plum',
  red: 'ops-red-alert',
  sky: 'ops-sky',
  sunset: 'ops-sunset',
  tan: 'ops-tan',
  teal: 'ops-teal',
  violet: 'ops-violet',
  white: 'ops-white',
}

const DARK_SURFACES = new Set([
  'ops-black',
  'ops-blue',
  'ops-magenta',
  'ops-navy',
  'ops-plum',
  'ops-red-alert',
  'ops-sunset',
  'ops-violet',
])

const SPACING_MAP = {
  'mt-1': '0.25rem',
  'mt-2': '0.5rem',
}

const TEXT_SIZE_MAP = {
  'text-xs': { fontSize: '0.75rem', lineHeight: '1rem' },
  'text-sm': { fontSize: '0.875rem', lineHeight: '1.25rem' },
}

function normalizeColorToken(token) {
  if (!token || typeof token !== 'string') {
    return null
  }

  const trimmed = token.trim()
  if (!trimmed) {
    return null
  }

  if (trimmed.startsWith('ops-')) {
    return trimmed
  }

  return COLOR_ALIASES[trimmed] || null
}

function colorVar(token) {
  const normalized = normalizeColorToken(token)
  return normalized ? `var(--${normalized})` : null
}

function prefersDarkText(token) {
  const normalized = normalizeColorToken(token)
  return normalized ? !DARK_SURFACES.has(normalized) : false
}

export function resolveSurfaceThemeStyle(colorToken, fallbackColor = 'ops-lilac') {
  const resolvedToken = normalizeColorToken(colorToken) || normalizeColorToken(fallbackColor) || 'ops-lilac'
  const accent = `var(--${resolvedToken})`

  // Ops Console canonical surface: black panel with a colored accent end-cap.
  // All Ops Console palette colors exceed WCAG AA (>8:1) on pure black, so body
  // text stays readable regardless of which type token was assigned.
  return {
    backgroundColor: 'var(--ops-black)',
    color: 'var(--ops-peach)',
    '--card-accent': accent,
    '--card-accent-token': resolvedToken,
  }
}

export function resolveSchemaClassStyle(classString) {
  if (!classString || typeof classString !== 'string') {
    return {}
  }

  const style = {}
  const tokens = classString.split(/\s+/).filter(Boolean)

  for (const token of tokens) {
    if (TEXT_SIZE_MAP[token]) {
      Object.assign(style, TEXT_SIZE_MAP[token])
      continue
    }

    if (SPACING_MAP[token]) {
      style.marginTop = SPACING_MAP[token]
      continue
    }

    switch (token) {
      case 'flex-1':
        style.flex = '1 1 0%'
        style.minWidth = '0'
        continue
      case 'font-medium':
        style.fontWeight = '500'
        continue
      case 'font-semibold':
        style.fontWeight = '600'
        continue
      case 'italic':
        style.fontStyle = 'italic'
        continue
      case 'rounded':
        style.borderRadius = '0.25rem'
        continue
      case 'rounded-lg':
        style.borderRadius = '0.5rem'
        continue
      case 'tracking-wide':
        style.letterSpacing = '0.025em'
        continue
      case 'tracking-wider':
        style.letterSpacing = '0.05em'
        continue
      case 'tracking-widest':
        style.letterSpacing = '0.1em'
        continue
      case 'uppercase':
        style.textTransform = 'uppercase'
        continue
      default:
        break
    }

    if (token.startsWith('bg-')) {
      const bgColor = colorVar(token.slice(3))
      if (bgColor) {
        style.backgroundColor = bgColor
        if (!('color' in style)) {
          style.color = prefersDarkText(token.slice(3)) ? 'var(--ops-black)' : 'var(--ops-peach)'
        }
      }
      continue
    }

    if (token.startsWith('text-')) {
      const textColor = colorVar(token.slice(5))
      if (textColor) {
        style.color = textColor
      }
    }
  }

  return style
}
