'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { Zap } from 'lucide-react';
import { cn } from '@/lib/utils';
import Magnetic from '../Magnetic';
import LanguageSwitcher from '../LanguageSwitcher';
import { useI18n } from '../i18n';

export default function Nav() {
  const { t } = useI18n();
  const [scrolled, setScrolled] = useState(false);

  const LINKS = [
    { label: t.nav.links.manifesto, href: '#manifesto' },
    { label: t.nav.links.capabilities, href: '#capabilities' },
    { label: t.nav.links.flow, href: '#flow' },
  ];

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 40);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <header
      className={cn(
        'fixed inset-x-0 top-0 z-50 transition-all duration-500',
        scrolled
          ? 'border-b border-white/10 bg-[#070A16]/60 backdrop-blur-xl'
          : 'border-b border-transparent',
      )}
    >
      <nav className="mx-auto flex h-20 max-w-7xl items-center justify-between px-6">
        <Magnetic strength={0.3}>
          <Link href="#top" className="flex items-center gap-2.5" data-cursor>
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-secondary shadow-lg shadow-primary/30">
              <Zap size={18} className="text-white" />
            </span>
            <span className="text-lg font-semibold tracking-tight">HubbyGlobal</span>
          </Link>
        </Magnetic>

        <div className="hidden items-center gap-9 text-sm text-white/60 md:flex">
          {LINKS.map((l) => (
            <a
              key={l.href}
              href={l.href}
              data-cursor
              className="group relative transition-colors hover:text-white"
            >
              {l.label}
              <span className="absolute -bottom-1 left-0 h-px w-0 bg-gradient-to-r from-primary to-secondary transition-all duration-300 group-hover:w-full" />
            </a>
          ))}
        </div>

        <div className="flex items-center gap-3 sm:gap-5">
          <LanguageSwitcher className="hidden sm:inline-flex" />
          <Link
            href="/login"
            data-cursor
            className="hidden text-sm text-white/70 transition-colors hover:text-white sm:block"
          >
            {t.nav.login}
          </Link>
          <Magnetic strength={0.5}>
            <Link
              href="/register"
              data-cursor
              data-cursor-label="Go"
              className="inline-flex items-center rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-[#070A16] transition-transform hover:scale-[1.03]"
            >
              {t.nav.start}
            </Link>
          </Magnetic>
        </div>
      </nav>
    </header>
  );
}
