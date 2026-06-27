'use client';

import React from 'react';
import { Globe } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useI18n } from './i18n';

/** Toggles between English and Arabic (label shows the other language). */
export default function LanguageSwitcher({ className }: { className?: string }) {
  const { toggle, t } = useI18n();
  return (
    <button
      type="button"
      onClick={toggle}
      data-cursor
      aria-label="Switch language"
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3.5 py-2 text-xs font-medium text-white/80 backdrop-blur-sm transition-colors hover:bg-white/10',
        className,
      )}
    >
      <Globe size={14} />
      {t.langName}
    </button>
  );
}
