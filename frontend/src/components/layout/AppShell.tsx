'use client';

import React, { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { Plug, ArrowRight, X } from 'lucide-react';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { useStores } from '@/components/providers/StoresProvider';
import { useToast } from '@/components/ui/Toast';
import { useI18n } from '@/i18n';

export default function AppShell({ children }: { children: React.ReactNode }) {
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const { dir } = useI18n();

  return (
    <div dir={dir} className="theme-light min-h-screen bg-background text-foreground flex">
      <Sidebar isOpen={isSidebarOpen} setIsOpen={setIsSidebarOpen} />

      <div className="flex-1 flex flex-col min-w-0">
        <Topbar />

        <main className="p-6 flex-1 overflow-y-auto">
          <div className="max-w-7xl mx-auto space-y-6">
            <ConnectBanner />
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}

/**
 * Global nudge shown on every dashboard page until the org connects its first
 * store — most features have no data to show without a linked platform.
 */
function ConnectBanner() {
  const { hasConnectedStore, loading } = useStores();
  const { toast } = useToast();
  const { t, dir } = useI18n();
  const [dismissed, setDismissed] = useState(false);
  const toasted = useRef(false);

  // Fire a one-time toast the moment we know there are no stores.
  useEffect(() => {
    if (!loading && !hasConnectedStore && !toasted.current) {
      toasted.current = true;
      toast(t('connect.toast'), 'info');
    }
  }, [loading, hasConnectedStore, toast, t]);

  if (loading || hasConnectedStore || dismissed) return null;

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-primary/20 bg-primary/5 p-4 sm:flex-row sm:items-center">
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
        <Plug size={20} />
      </div>
      <div className="flex-1">
        <p className="text-sm font-bold">{t('connect.title')}</p>
        <p className="text-xs text-muted-foreground">{t('connect.body')}</p>
      </div>
      <div className="flex items-center gap-2">
        <Link
          href="/stores"
          className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:opacity-90"
        >
          {t('connect.cta')}
          <ArrowRight size={14} className={dir === 'rtl' ? 'rotate-180' : ''} />
        </Link>
        <button
          onClick={() => setDismissed(true)}
          className="rounded-lg p-2 text-muted-foreground transition hover:bg-accent"
          title={t('actions.cancel')}
        >
          <X size={16} />
        </button>
      </div>
    </div>
  );
}
