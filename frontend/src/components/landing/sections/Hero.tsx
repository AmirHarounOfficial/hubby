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
      // Top-align on phones (content can be taller than the viewport, so centering
      // would push the badge up under the fixed navbar); center on larger screens.
      className="relative flex min-h-[100svh] items-start justify-center px-6 pt-28 pb-20 sm:items-center sm:pt-24"
    >
      {/* Soft light halo behind the copy — keeps text crisp and calms the
          busiest part of the constellation without a hard box. */}
      <div
        aria-hidden
        className="pointer-events-none absolute left-1/2 top-[46%] h-[62vh] w-[min(780px,94vw)] -translate-x-1/2 -translate-y-1/2"
        style={{
          background:
            'radial-gradient(50% 50% at 50% 50%, rgba(244,248,247,0.82), rgba(244,248,247,0.35) 46%, rgba(244,248,247,0) 74%)',
        }}
      />

      <div ref={fade} className="relative mx-auto max-w-5xl text-center">
        <div
          data-hero-fade
          className="mb-8 inline-flex items-center gap-2 rounded-full border border-white/70 bg-white/55 px-4 py-2 text-xs font-medium uppercase tracking-[0.2em] text-[#0B5A5C] shadow-sm backdrop-blur-md"
        >
          <span className="relative flex h-2 w-2">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-secondary opacity-75" />
            <span className="relative inline-flex h-2 w-2 rounded-full bg-secondary" />
          </span>
          {t.hero.badge}
        </div>

        <h1 className="flex flex-col items-center text-balance text-4xl font-semibold leading-[1.06] tracking-tight sm:text-7xl md:text-8xl">
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
          className="mx-auto mt-8 max-w-2xl text-balance text-lg leading-relaxed text-[#5C6E74] sm:text-xl"
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
              className="group inline-flex items-center gap-2 rounded-full bg-[#0B5A5C] px-8 py-4 text-base font-semibold text-white shadow-[0_16px_34px_-14px_rgba(11,90,92,.8)] transition-transform hover:scale-[1.03]"
            >
              {t.hero.cta1}
              <ArrowRight size={18} className="transition-transform group-hover:translate-x-1 rtl:-scale-x-100" />
            </Link>
          </Magnetic>
          <Magnetic strength={0.3}>
            <a
              href="#manifesto"
              data-cursor
              className="inline-flex items-center rounded-full border border-[rgba(11,90,92,.18)] bg-white/60 px-8 py-4 text-base font-medium text-[#122E33] shadow-sm backdrop-blur-md transition-colors hover:bg-white/85"
            >
              {t.hero.cta2}
            </a>
          </Magnetic>
        </div>
      </div>

      {/* Scroll cue */}
      <div
        data-hero-fade
        className="absolute bottom-8 left-1/2 flex -translate-x-1/2 flex-col items-center gap-2 text-[10px] uppercase tracking-[0.3em] text-[#5C6E74]"
      >
        {t.hero.scroll}
        <span className="flex h-10 w-6 justify-center rounded-full border border-[rgba(11,90,92,.25)] p-1.5">
          <span className="h-2 w-1 animate-bounce rounded-full bg-[#0B5A5C]/70" />
        </span>
      </div>
    </section>
  );
}
