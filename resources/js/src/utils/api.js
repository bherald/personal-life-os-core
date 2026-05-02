import axios from 'axios';
import router from '../router';

const api = axios.create({
  baseURL: '/api',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true, // Enable session cookies
});

// Handle 401 errors - redirect to login
api.interceptors.response.use(
  (response) => response.data,
  (error) => {
    if (error.response?.status === 401) {
      // Clear authentication state
      localStorage.removeItem('is_authenticated');
      router.push('/login');
    }
    return Promise.reject(error);
  }
);

export default api;
