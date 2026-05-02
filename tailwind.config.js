/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          500: '#3b82f6',
          600: '#2563eb',
          700: '#1d4ed8',
        },
        // Brand-neutral operations palette.
        ops: {
          // Warm / oranges
          orange: '#ff9900',
          peach: '#ff9966',
          gold: '#ffcc99',
          yellow: '#ffff99',
          tan: '#cc9966',
          sunset: '#cc6633',
          butterscotch: '#ff9933',
          // Purples
          lilac: '#cc99cc',
          violet: '#9977aa',
          plum: '#774477',
          magenta: '#cc6699',
          grape: '#9966cc',
          lavender: '#cc99ff',
          // Blues
          sky: '#99ccff',
          'sky-light': '#bbddff',
          blue: '#6688cc',
          azure: '#3366cc',
          navy: '#336699',
          ice: '#aaccff',
          teal: '#66cccc',
          // Status / utility
          alert: '#cc0000',
          red: '#cc0000',
          green: '#99cc66',
          'green-bright': '#66ff66',
          white: '#ffffff',
          black: '#000000',
          gray: '#999999',
          frame: '#774477',
          // Text
          text: '#ff9966',
          'text-muted': '#999999',
          // Semantic role tokens
          accent: '#ff9900',
          'accent-soft': '#ff9966',
          'accent-muted': '#ffcc99',
          info: '#99ccff',
          success: '#99cc66',
          warning: '#ff9900',
          danger: '#cc0000',
          surface: '#0a0a0a',
          void: '#000000',
          mute: '#999999',
        },
        // Accent colors (commonly used throughout the app)
        accent: {
          DEFAULT: '#3498db',
          light: '#5dade2',
          dark: '#2980b9',
        },
        // Dark theme colors
        dark: {
          bg: {
            primary: '#1a1a1a',
            secondary: '#2d2d2d',
            tertiary: '#34495e',
          },
          text: {
            primary: '#e0e0e0',
            secondary: '#95a5a6',
          },
          border: '#444444',
          accent: '#3498db',
        },
        // Light theme colors
        light: {
          bg: {
            primary: '#ffffff',
            secondary: '#f3f4f6',
            tertiary: '#e5e7eb',
          },
          text: {
            primary: '#111827',
            secondary: '#6b7280',
          },
          border: '#d1d5db',
          accent: '#2563eb',
        },
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}
