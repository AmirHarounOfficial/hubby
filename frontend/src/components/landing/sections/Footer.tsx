'use client';

import React from 'react';
import { useI18n } from '../i18n';
import { Logo } from '@/components/ui/Logo';

export default function Footer() {
  const { t } = useI18n();
  return (
    <footer className="relative border-t border-white/10 bg-[#051E1D] px-6 py-20 text-white">
      <div className="mx-auto grid max-w-7xl grid-cols-2 gap-12 md:grid-cols-5">
        <div className="col-span-2 space-y-5">
          <div className="flex items-center">
            <Logo variant="dark" className="h-8 w-auto" />
          </div>
          <p className="max-w-xs text-sm leading-relaxed text-white/50">
            {t.footer.tagline}
          </p>
        </div>

        {t.footer.columns.map((col) => (
          <div key={col.title} className="space-y-5">
            <h4 className="text-xs font-semibold uppercase tracking-[0.2em] text-white/40">
              {col.title}
            </h4>
            <ul className="space-y-3 text-sm text-white/60">
              {col.links.map((link) => (
                <li key={link}>
                  <a href="#" data-cursor className="transition-colors hover:text-white">
                    {link}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>

      <div className="mx-auto mt-16 flex max-w-7xl flex-col items-center justify-between gap-4 border-t border-white/10 pt-8 text-xs text-white/40 md:flex-row">
        <p>{t.footer.copyright}</p>
        <div className="flex items-center gap-6">
          {t.footer.social.map((s) => (
            <a key={s} href="#" data-cursor className="transition-colors hover:text-white">
              {s}
            </a>
          ))}
        </div>
      </div>
    </footer>
  );
}
