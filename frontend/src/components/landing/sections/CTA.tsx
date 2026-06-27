'use client';

import React from 'react';
import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import Magnetic from '../Magnetic';
import Reveal from '../Reveal';
import RevealText from '../RevealText';
import { useI18n } from '../i18n';

export default function CTA() {
  const { t, locale } = useI18n();
  return (
    <section className="relative px-6 py-40">
      <div className="relative mx-auto max-w-5xl overflow-hidden rounded-[2.5rem] border border-white/10 bg-gradient-to-br from-primary/20 via-white/[0.03] to-secondary/20 px-8 py-24 text-center backdrop-blur-xl sm:px-16">
        <div className="pointer-events-none absolute -top-1/2 left-1/2 h-[120%] w-[60%] -translate-x-1/2 rounded-full bg-primary/20 blur-[120px]" />

        <p className="relative mb-6 text-sm font-medium uppercase tracking-[0.3em] text-secondary">
          {t.cta.eyebrow}
        </p>
        <RevealText
          key={locale}
          text={t.cta.heading}
          className="relative justify-center text-balance text-4xl font-semibold tracking-tight sm:text-6xl"
        />
        <Reveal>
          <p className="relative mx-auto mt-6 max-w-xl text-balance text-lg text-white/60">
            {t.cta.body}
          </p>
        </Reveal>

        <Reveal delay={0.1}>
          <div className="relative mt-12 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Magnetic strength={0.4}>
              <Link
                href="/register"
                data-cursor
                data-cursor-label="Launch"
                className="group inline-flex items-center gap-2 rounded-full bg-white px-9 py-4 text-base font-semibold text-[#070A16] transition-transform hover:scale-[1.03]"
              >
                {t.cta.cta1}
                <ArrowRight size={18} className="transition-transform group-hover:translate-x-1 rtl:-scale-x-100" />
              </Link>
            </Magnetic>
            <Magnetic strength={0.3}>
              <Link
                href="/login"
                data-cursor
                className="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-9 py-4 text-base font-medium text-white/80 transition-colors hover:bg-white/10"
              >
                {t.cta.cta2}
              </Link>
            </Magnetic>
          </div>
        </Reveal>
      </div>
    </section>
  );
}
