'use client';

import React, { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { useI18n } from '../i18n';

gsap.registerPlugin(ScrollTrigger);

/**
 * A pinned manifesto where each word lights up as it passes through the center
 * of the screen — the scroll itself reads the sentence to you.
 */
export default function Manifesto() {
  const { t } = useI18n();
  const section = useRef<HTMLDivElement>(null);
  const words = t.manifesto.text.split(' ');

  useLayoutEffect(() => {
    const el = section.current;
    if (!el) return;
    const ctx = gsap.context(() => {
      gsap.fromTo(
        '[data-mword]',
        { opacity: 0.12 },
        {
          opacity: 1,
          ease: 'none',
          stagger: 0.5,
          scrollTrigger: {
            trigger: el,
            start: 'top top',
            end: '+=180%',
            scrub: 0.6,
            pin: true,
          },
        },
      );
    }, el);
    return () => ctx.revert();
  }, [t.manifesto.text]);

  return (
    <section id="manifesto" ref={section} className="relative flex min-h-[100svh] items-center px-6">
      <div className="mx-auto max-w-5xl">
        <p className="mb-10 text-sm font-medium uppercase tracking-[0.3em] text-secondary">
          {t.manifesto.eyebrow}
        </p>
        <p className="flex flex-wrap text-3xl font-medium leading-snug tracking-tight sm:text-5xl sm:leading-[1.25]">
          {words.map((w, i) => (
            <span key={i} data-mword className="me-[0.28em] inline-block">
              {w}
            </span>
          ))}
        </p>
      </div>
    </section>
  );
}
