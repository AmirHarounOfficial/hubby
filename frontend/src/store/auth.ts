import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface User {
  id: number;
  name: string;
  email: string;
}

interface Organization {
  id: number;
  name: string;
}

interface AuthState {
  user: User | null;
  token: string | null;
  activeOrgId: number | null;
  organizations: Organization[];
  setAuth: (user: User, token: string) => void;
  setUser: (user: Partial<User>) => void;
  setOrganizations: (orgs: Organization[]) => void;
  updateOrganizationName: (id: number, name: string) => void;
  setActiveOrgId: (id: number) => void;
  logout: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      activeOrgId: null,
      organizations: [],
      setAuth: (user, token) => set({ user, token }),
      setUser: (partial) =>
        set((state) => ({ user: state.user ? { ...state.user, ...partial } : null })),
      setOrganizations: (organizations) => set({ organizations }),
      updateOrganizationName: (id, name) =>
        set((state) => ({
          organizations: state.organizations.map((o) => (o.id === id ? { ...o, name } : o)),
        })),
      setActiveOrgId: (activeOrgId) => set({ activeOrgId }),
      logout: () => set({ user: null, token: null, activeOrgId: null, organizations: [] }),
    }),
    {
      name: 'auth-storage',
    }
  )
);
