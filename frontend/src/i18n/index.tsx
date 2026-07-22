'use client';

import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { dictionaries } from './dictionary';

export type Locale = 'en' | 'ar';
const STORAGE_KEY = 'hubby_locale';

type I18nCtx = {
  locale: Locale;
  dir: 'ltr' | 'rtl';
  setLocale: (l: Locale) => void;
  /** Translate a dot-path key (e.g. `t('orders.title')`). Falls back to
   *  English, then to the key itself, so a missing translation never crashes.
   *  Optional `vars` replace `{{name}}` placeholders in the string. */
  t: (key: string, vars?: Record<string, string | number>) => string;
};

const I18nContext = createContext<I18nCtx | null>(null);

function lookup(obj: unknown, path: string): string | undefined {
  const v = path.split('.').reduce<unknown>((o, k) => (o == null ? undefined : (o as Record<string, unknown>)[k]), obj);
  return typeof v === 'string' ? v : undefined;
}

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>('en');

  // Restore the saved locale after hydration (SSR renders English).
  useEffect(() => {
    const saved = (typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null) as Locale | null;
    if (saved === 'en' || saved === 'ar') setLocaleState(saved);
  }, []);

  const setLocale = useCallback((l: Locale) => {
    setLocaleState(l);
    try {
      localStorage.setItem(STORAGE_KEY, l);
    } catch {
      /* ignore storage errors (private mode) */
    }
  }, []);

  const dir: 'ltr' | 'rtl' = locale === 'ar' ? 'rtl' : 'ltr';

  const t = useCallback(
    (key: string, vars?: Record<string, string | number>) => {
      const raw = lookup(dictionaries[locale], key) ?? lookup(dictionaries.en, key) ?? key;
      if (!vars) return raw;
      return raw.replace(/\{\{(\w+)\}\}/g, (m, name) => (name in vars ? String(vars[name]) : m));
    },
    [locale],
  );

  return <I18nContext.Provider value={{ locale, dir, setLocale, t }}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nCtx {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error('useI18n must be used within <I18nProvider>');
  return ctx;
}

/** Convenience: just the translate function. */
export function useT(): (key: string, vars?: Record<string, string | number>) => string {
  return useI18n().t;
}
