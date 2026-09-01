/**
 * Tailwind configuration for the admin apps.
 *
 * Two deliberate departures from a standalone app:
 *
 * 1. Preflight is off. Tailwind's reset is global, and this CSS is loaded into
 *    wp-admin — it would restyle every screen the plugin touches. A narrow
 *    reset scoped to our own root lives in style.css instead.
 *
 * 2. `important` is a selector, not a boolean. That prefixes every utility with
 *    `.schemapress`, which both scopes them to our UI and gives them enough
 *    specificity to win against wp-admin's own rules without !important.
 */

module.exports = {
  content: ['./src/**/*.{js,jsx}'],
  important: '.schemapress',
  corePlugins: {
    preflight: false
  },
  theme: {
    extend: {
      colors: {
        border: 'hsl(var(--sp-border))',
        input: 'hsl(var(--sp-input))',
        ring: 'hsl(var(--sp-ring))',
        background: 'hsl(var(--sp-background))',
        foreground: 'hsl(var(--sp-foreground))',
        primary: {
          DEFAULT: 'hsl(var(--sp-primary))',
          foreground: 'hsl(var(--sp-primary-foreground))'
        },
        secondary: {
          DEFAULT: 'hsl(var(--sp-secondary))',
          foreground: 'hsl(var(--sp-secondary-foreground))'
        },
        muted: {
          DEFAULT: 'hsl(var(--sp-muted))',
          foreground: 'hsl(var(--sp-muted-foreground))'
        },
        accent: {
          DEFAULT: 'hsl(var(--sp-accent))',
          foreground: 'hsl(var(--sp-accent-foreground))'
        },
        destructive: {
          DEFAULT: 'hsl(var(--sp-destructive))',
          foreground: 'hsl(var(--sp-destructive-foreground))'
        },
        card: {
          DEFAULT: 'hsl(var(--sp-card))',
          foreground: 'hsl(var(--sp-card-foreground))'
        },
        popover: {
          DEFAULT: 'hsl(var(--sp-popover))',
          foreground: 'hsl(var(--sp-popover-foreground))'
        },
        appbar: {
          DEFAULT: 'hsl(var(--sp-appbar))',
          foreground: 'hsl(var(--sp-appbar-foreground))',
          muted: 'hsl(var(--sp-appbar-muted))',
          active: 'hsl(var(--sp-appbar-active))'
        }
      },
      borderRadius: {
        lg: 'var(--sp-radius)',
        md: 'calc(var(--sp-radius) - 2px)',
        sm: 'calc(var(--sp-radius) - 4px)'
      },
      fontFamily: {
        sans: [
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          '"Helvetica Neue"',
          'sans-serif'
        ],
        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace']
      },
      keyframes: {
        'sp-in': {
          from: { opacity: '0', transform: 'translateY(-4px) scale(.98)' },
          to: { opacity: '1', transform: 'translateY(0) scale(1)' }
        },
        'sp-collapse-down': {
          from: { height: '0' },
          to: { height: 'var(--radix-collapsible-content-height)' }
        },
        'sp-collapse-up': {
          from: { height: 'var(--radix-collapsible-content-height)' },
          to: { height: '0' }
        }
      },
      animation: {
        'sp-in': 'sp-in .14s ease-out',
        'sp-collapse-down': 'sp-collapse-down .18s ease-out',
        'sp-collapse-up': 'sp-collapse-up .18s ease-out'
      }
    }
  },
  plugins: []
}
