import { defineStore } from 'pinia';

export const useThemeStore = defineStore('theme', {
  state: () => ({
    // Default to dark mode (current app state)
    isDark: true,
  }),

  getters: {
    currentTheme: (state) => state.isDark ? 'dark' : 'light',
  },

  actions: {
    /**
     * Initialize theme from localStorage or system preference
     * Defaults to dark mode if no preference is saved
     */
    init() {
      const saved = localStorage.getItem('theme-mode');

      if (saved) {
        this.isDark = saved === 'dark';
      } else {
        // Default to dark mode (app's current design)
        this.isDark = true;
      }

      this.applyTheme();
    },

    /**
     * Toggle between dark and light themes
     */
    toggle() {
      this.isDark = !this.isDark;
      localStorage.setItem('theme-mode', this.isDark ? 'dark' : 'light');
      this.applyTheme();
    },

    /**
     * Set a specific theme
     * @param {boolean} dark - True for dark mode, false for light mode
     */
    setTheme(dark) {
      this.isDark = dark;
      localStorage.setItem('theme-mode', dark ? 'dark' : 'light');
      this.applyTheme();
    },

    /**
     * Apply the current theme to the document
     */
    applyTheme() {
      if (this.isDark) {
        document.documentElement.classList.add('dark');
        document.documentElement.classList.remove('light');
      } else {
        document.documentElement.classList.remove('dark');
        document.documentElement.classList.add('light');
      }
    },
  },
});
