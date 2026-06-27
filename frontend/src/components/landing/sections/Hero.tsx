'use client';

import React, { useLayoutEffect, useRef } from 'react';
import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Magnetic from '../Magnetic';
import RevealText from '../RevealText';
import { useI18n } from '../i18n';

gsap.registerPlugin(ScrollTrigger);

export default function Hero() {
  const { t, locale } = useI18n();
  const root = useRef<HTMLDivElement>(null);
  const fade = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const ctx = gsap.context(() => {
      // Staggered entrance for the supporting elements.
      gsap.from('[data-hero-fade]', {
        opacity: 0,
        y: 24,
        duration: 1,
        ease: 'power3.out',
        stagger: 0.12,
        delay: 0.35,
      });

      // Hero parallaxes up and dissolves as you scroll into the manifesto.
      gsap.to(fade.current, {
        yPercent: -18,
        opacity: 0,
        ease: 'none',
        scrollTrigger: {
          trigger: root.current,
          start: 'top top',
          end: 'bottom top',
          scrub: true,
        },
      });
    }, root);
    return () => ctx.revert();
  }, []);

  return (
    <section
      id="top"
      ref={root}
      className="relative flex min-h-[100svh] items-center justify-center px-6"
    >
      <div ref={fade} className="mx-auto max-w-5xl text-center">
        <div
          data-hero-fade
          className="mb-8 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs font-medium uppercase tracking-[0.2em] text-white/70 backdrop-blur-sm"
        >
          <span className="relative flex h-2 w-2">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-secondary opacity-75" />
            <span className="relative inline-flex h-2 w-2 rounded-full bg-secondary" />
          </span>
          {t.hero.badge}
        </div>

        <h1 className="flex flex-col items-center text-balance text-5xl font-semibold leading-[1.04] tracking-tight sm:text-7xl md:text-8xl">
          <RevealText key={`t1-${locale}`} as="span" text={t.hero.title1} stagger={0.06} className="justify-center" />
          <RevealText
            key={`t2-${locale}`}
            as="span"
            text={t.hero.title2}
            stagger={0.06}
            className="justify-center"
            wordClassName="gradient-text"
          />
        </h1>

        <p
          data-hero-fade
          className="mx-auto mt-8 max-w-2xl text-balance text-lg leading-relaxed text-white/60 sm:text-xl"
        >
          {t.hero.subtitle}
        </p>

        <div
          data-hero-fade
          className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row"
        >
          <Magnetic strength={0.4}>
            <Link
              href="/register"
              data-cursor
              data-cursor-label="Launch"
              className="group inline-flex items-center gap-2 rounded-full bg-white px-8 py-4 text-base font-semibold text-[#070A16] transition-transform hover:scale-[1.03]"
            >
              {t.hero.cta1}
              <ArrowRight size={18} className="transition-transform group-hover:translate-x-1 rtl:-scale-x-100" />
            </Link>
          </Magnetic>
          <Magnetic strength={0.3}>
            <a
              href="#manifesto"
              data-cursor
              className="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-8 py-4 text-base font-medium text-white/80 backdrop-blur-sm transition-colors hover:bg-white/10"
            >
              {t.hero.cta2}
            </a>
          </Magnetic>
        </div>
      </div>

      {/* Scroll cue */}
      <div
        data-hero-fade
        className="absolute bottom-8 left-1/2 flex -translate-x-1/2 flex-col items-center gap-2 text-[10px] uppercase tracking-[0.3em] text-white/40"
      >
        {t.hero.scroll}
        <span className="flex h-10 w-6 justify-center rounded-full border border-white/20 p-1.5">
          <span className="h-2 w-1 animate-bounce rounded-full bg-white/60" />
        </span>
      </div>
    </section>
  );
}
