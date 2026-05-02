import { defineStore } from 'pinia';
import api from '../utils/api';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: !!localStorage.getItem('is_authenticated'),
  }),

  actions: {
    async login(password) {
      try {
        const response = await api.post('/auth/login', { password });
        if (response.success) {
          this.user = response.data.user;
          this.isAuthenticated = true;
          localStorage.setItem('is_authenticated', 'true');
          return { success: true };
        }
        return { success: false, error: 'Login failed' };
      } catch (error) {
        return {
          success: false,
          error: error.response?.data?.error?.message || 'Login failed'
        };
      }
    },

    async logout() {
      try {
        await api.post('/auth/logout');
      } catch (error) {
        console.error('Logout error:', error);
      } finally {
        this.user = null;
        this.isAuthenticated = false;
        localStorage.removeItem('is_authenticated');
      }
    },

    // Check authentication status with backend
    async checkAuth() {
      try {
        const response = await api.get('/auth/me');
        if (response.success) {
          this.user = response.data.user;
          this.isAuthenticated = true;
          localStorage.setItem('is_authenticated', 'true');
          return true;
        }
        return false;
      } catch (error) {
        this.isAuthenticated = false;
        localStorage.removeItem('is_authenticated');
        return false;
      }
    },
  },
});
