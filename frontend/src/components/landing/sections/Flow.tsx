'use client';

import React, { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Reveal from '../Reveal';
import RevealText from '../RevealText';
import { useI18n } from '../i18n';

gsap.registerPlugin(ScrollTrigger);

export default function Flow() {
  const { t, locale } = useI18n();
  const section = useRef<HTMLDivElement>(null);
  const line = useRef<HTMLDivElement>(null);
  const steps = t.flow.steps;

  useLayoutEffect(() => {
    const el = section.current;
    if (!el) return;
    const ctx = gsap.context(() => {
      gsap.fromTo(
        line.current,
        { scaleY: 0 },
        {
          scaleY: 1,
          ease: 'none',
          scrollTrigger: {
            trigger: el,
            start: 'top 60%',
            end: 'bottom 75%',
            scrub: true,
          },
        },
      );
    }, el);
    return () => ctx.revert();
  }, []);

  return (
    <section id="flow" ref={section} className="relative px-6 py-40">
      <div className="mx-auto max-w-5xl">
        <p className="mb-4 text-sm font-medium uppercase tracking-[0.3em] text-secondary">
          {t.flow.eyebrow}
        </p>
        <RevealText
          key={locale}
          text={t.flow.heading}
          className="text-4xl font-semibold tracking-tight sm:text-6xl"
        />

        <div className="relative mt-24 ps-12 sm:ps-20">
          {/* Track + animated progress line. */}
          <div className="absolute start-[1.15rem] top-2 h-[calc(100%-1rem)] w-px bg-white/10 sm:start-[1.65rem]" />
          <div
            ref={line}
            className="absolute start-[1.15rem] top-2 h-[calc(100%-1rem)] w-px origin-top bg-gradient-to-b from-primary to-secondary sm:start-[1.65rem]"
          />

          <div className="space-y-24">
            {steps.map((s, i) => (
              <Reveal key={i} className="relative">
                <span className="absolute -start-12 flex h-9 w-9 -translate-x-1/2 items-center justify-center rounded-full border border-white/20 bg-[#070A16] font-mono text-xs text-secondary sm:-start-20 rtl:translate-x-1/2">
                  {String(i + 1).padStart(2, '0')}
                </span>
                <h3 className="text-2xl font-semibold tracking-tight sm:text-4xl">{s.title}</h3>
                <p className="mt-4 max-w-xl text-lg leading-relaxed text-white/55">{s.desc}</p>
              </Reveal>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
