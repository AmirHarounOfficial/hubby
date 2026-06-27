import axios from 'axios';
import { useAuthStore } from '@/store/auth';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL || '/api',
});

console.log('API baseURL:', api.defaults.baseURL);

api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token;
  const activeOrgId = useAuthStore.getState().activeOrgId;

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  if (activeOrgId) {
    config.headers['X-Organization-Id'] = activeOrgId;
  }

  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      useAuthStore.getState().logout();
      if (typeof window !== 'undefined') {
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  }
);

export default api;
