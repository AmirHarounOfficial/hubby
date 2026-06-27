'use client';

import React, { createContext, useCallback, useContext, useState } from 'react';
import { CheckCircle2, AlertCircle, Info, X } from 'lucide-react';
import { cn } from '@/lib/utils';

type ToastType = 'success' | 'error' | 'info';
interface ToastItem {
  id: number;
  message: string;
  type: ToastType;
}

interface ToastApi {
  toast: (message: string, type?: ToastType) => void;
}

const ToastContext = createContext<ToastApi | null>(null);
let counter = 0;

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);

  const remove = useCallback((id: number) => {
    setItems((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const toast = useCallback(
    (message: string, type: ToastType = 'info') => {
      const id = ++counter;
      setItems((prev) => [...prev, { id, message, type }]);
      setTimeout(() => remove(id), 4500);
    },
    [remove],
  );

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}
      {/* `theme-light` keeps toasts on the dashboard palette even though the
          provider sits outside the AppShell's themed wrapper. */}
      <div className="theme-light pointer-events-none fixed bottom-6 end-6 z-[100] flex w-full max-w-sm flex-col gap-2">
        {items.map((t) => (
          <ToastCard key={t.id} item={t} onClose={() => remove(t.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext);
  // Fail soft if a component using toasts is rendered outside the provider.
  return ctx ?? { toast: (m: string) => console.log('[toast]', m) };
}

const ICONS = { success: CheckCircle2, error: AlertCircle, info: Info } as const;
const TONES = { success: 'text-secondary', error: 'text-destructive', info: 'text-primary' } as const;

function ToastCard({ item, onClose }: { item: ToastItem; onClose: () => void }) {
  const Icon = ICONS[item.type];
  return (
    <div className="pointer-events-auto flex items-start gap-3 rounded-xl border border-border bg-card p-4 text-foreground shadow-2xl animate-in fade-in slide-in-from-bottom-2">
      <Icon size={18} className={cn('mt-0.5 shrink-0', TONES[item.type])} />
      <p className="flex-1 text-sm">{item.message}</p>
      <button onClick={onClose} className="text-muted-foreground transition-colors hover:text-foreground">
        <X size={16} />
      </button>
    </div>
  );
}
