'use client';

import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import api from '@/lib/api';
import type { PlatformId } from '@/lib/platforms';

interface StoresState {
  stores: any[];
  /** Distinct platforms the org has at least one store on. */
  connectedPlatforms: PlatformId[];
  /** Whether the org has connected any store at all. */
  hasConnectedStore: boolean;
  loading: boolean;
  refresh: () => Promise<void>;
}

const StoresContext = createContext<StoresState | null>(null);

const EMPTY: StoresState = {
  stores: [],
  connectedPlatforms: [],
  hasConnectedStore: false,
  loading: false,
  refresh: async () => {},
};

export function StoresProvider({ children }: { children: React.ReactNode }) {
  const [stores, setStores] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const res = await api.get('/stores');
      setStores(Array.isArray(res.data) ? res.data : []);
    } catch {
      setStores([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const connectedPlatforms = Array.from(
    new Set(stores.map((s) => s.platform).filter(Boolean)),
  ) as PlatformId[];

  return (
    <StoresContext.Provider
      value={{
        stores,
        connectedPlatforms,
        hasConnectedStore: stores.length > 0,
        loading,
        refresh,
      }}
    >
      {children}
    </StoresContext.Provider>
  );
}

export function useStores(): StoresState {
  return useContext(StoresContext) ?? EMPTY;
}
