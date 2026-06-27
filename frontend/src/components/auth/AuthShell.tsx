'use client';

import React, { useLayoutEffect, useRef } from 'react';
import Link from 'next/link';
import dynamic from 'next/dynamic';
import { Zap, Check } from 'lucide-react';
import * as THREE from 'three';
import gsap from 'gsap';
import Cursor from '../landing/Cursor';
import LanguageSwitcher from '../landing/LanguageSwitcher';
import { useI18n } from '../landing/i18n';

// Three.js stays client-only.
const AuthScene = dynamic(() => import('./AuthScene'), { ssr: false });

/**
 * Cinematic two-column auth layout that mirrors the landing page. Brand-panel
 * copy is pulled from the active locale (`screen` selects login vs register);
 * the form is passed as children. Direction (LTR/RTL) follows the i18n context.
 */
export default function AuthShell({
  accent = 'primary',
  screen,
  children,
}: {
  accent?: 'primary' | 'secondary';
  screen: 'login' | 'register';
  children: React.ReactNode;
}) {
  const { t, dir } = useI18n();
  const root = useRef<HTMLDivElement>(null);
  const accentColor = new THREE.Color(accent === 'secondary' ? '#10B981' : '#6366F1');
  const copy = t.auth[screen];

  useLayoutEffect(() => {
    const ctx = gsap.context(() => {
      gsap.from('[data-auth-rise]', {
        opacity: 0,
        y: 26,
        filter: 'blur(6px)',
        duration: 0.9,
        ease: 'power3.out',
        stagger: 0.08,
        delay: 0.15,
      });
    }, root);
    return () => ctx.revert();
  }, []);

  return (
    <div
      ref={root}
      dir={dir}
      className="auth-root relative min-h-screen overflow-hidden bg-[#070A16] text-white selection:bg-primary/40"
    >
      <Cursor />

      {/* Fixed WebGL backdrop. */}
      <div className="pointer-events-none absolute inset-0 z-0">
        <AuthScene accent={accentColor} />
      </div>

      {/* Language toggle, pinned to the top-trailing corner. */}
      <div className="absolute end-6 top-6 z-20">
        <LanguageSwitcher />
      </div>

      <div className="relative z-10 grid min-h-screen lg:grid-cols-2">
        {/* Brand panel (desktop only). */}
        <aside className="relative hidden flex-col justify-between p-12 lg:flex">
          <Link href="/" data-cursor className="flex w-fit items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-secondary shadow-lg shadow-primary/30">
              <Zap size={18} className="text-white" />
            </span>
            <span className="text-lg font-semibold tracking-tight">HubbyGlobal</span>
          </Link>

          <div className="max-w-md">
            <p
              data-auth-rise
              className="mb-5 text-xs font-medium uppercase tracking-[0.3em] text-secondary"
            >
              {copy.eyebrow}
            </p>
            <h2
              data-auth-rise
              className="text-balance text-4xl font-semibold leading-[1.1] tracking-tight xl:text-5xl"
            >
              {copy.heading}
            </h2>
            <p data-auth-rise className="mt-6 text-pretty text-lg leading-relaxed text-white/60">
              {copy.subheading}
            </p>
            <ul className="mt-10 space-y-4">
              {copy.highlights.map((h) => (
                <li key={h} data-auth-rise className="flex items-center gap-3 text-white/75">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full border border-white/15 bg-white/5">
                    <Check size={13} className="text-secondary" />
                  </span>
                  {h}
                </li>
              ))}
            </ul>
          </div>

          <p className="text-xs text-white/40">{t.footer.copyright}</p>
        </aside>

        {/* Form column. */}
        <main className="flex items-center justify-center p-6 sm:p-10">
          <div data-auth-rise className="w-full max-w-md">
            {/* Compact brand mark for mobile, where the panel is hidden. */}
            <Link href="/" data-cursor className="mb-8 flex w-fit items-center gap-2.5 lg:hidden">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-secondary">
                <Zap size={18} className="text-white" />
              </span>
              <span className="text-lg font-semibold">HubbyGlobal</span>
            </Link>

            <div className="rounded-3xl border border-white/10 bg-white/[0.04] p-8 shadow-2xl shadow-black/40 backdrop-blur-xl sm:p-10">
              {children}
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
