import { defineStore } from 'pinia';

export const useThemeStore = defineStore('theme', {
  state: () => ({ isDark: false }),
  getters: { currentTheme: () => 'light' },
  actions: {
    init() {
      this.isDark = false;
      localStorage.setItem('theme-mode', 'light');
      this.applyTheme();
    },
    toggle() {
      this.setTheme(false);
    },
    setTheme() {
      this.isDark = false;
      localStorage.setItem('theme-mode', 'light');
      this.applyTheme();
    },
    applyTheme() {
      document.documentElement.classList.remove('dark');
      document.documentElement.classList.add('light');
    },
  },
});
