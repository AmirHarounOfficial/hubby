'use client';

import React, { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Reveal from '../Reveal';
import { useI18n } from '../i18n';

gsap.registerPlugin(ScrollTrigger);

const STAT_VALUES = [
  { value: 500, suffix: '+' },
  { value: 4, suffix: '' },
  { value: 99.9, suffix: '%', decimals: 1 },
  { value: 30, suffix: 's' },
];

export default function Showcase() {
  const { t } = useI18n();
  const section = useRef<HTMLDivElement>(null);
  const STATS = STAT_VALUES.map((s, i) => ({ ...s, label: t.stats[i] }));

  useLayoutEffect(() => {
    const el = section.current;
    if (!el) return;
    const ctx = gsap.context(() => {
      el.querySelectorAll<HTMLElement>('[data-counter]').forEach((node) => {
        const end = parseFloat(node.dataset.counter || '0');
        const decimals = parseInt(node.dataset.decimals || '0', 10);
        const obj = { v: 0 };
        gsap.to(obj, {
          v: end,
          duration: 2,
          ease: 'power2.out',
          scrollTrigger: { trigger: node, start: 'top 85%', once: true },
          onUpdate: () => {
            node.firstChild!.textContent = obj.v.toFixed(decimals);
          },
        });
      });
    }, el);
    return () => ctx.revert();
  }, []);

  return (
    <section ref={section} className="relative px-6 py-32">
      <div className="mx-auto grid max-w-6xl grid-cols-2 gap-px overflow-hidden rounded-3xl border border-white/10 bg-white/10 lg:grid-cols-4">
        {STATS.map((s) => (
          <Reveal
            key={s.label}
            className="flex flex-col items-center justify-center gap-3 bg-[#070A16] p-10 text-center"
          >
            <p className="text-5xl font-semibold tracking-tight sm:text-6xl">
              <span data-counter={s.value} data-decimals={s.decimals ?? 0}>
                0
              </span>
              <span className="gradient-text">{s.suffix}</span>
            </p>
            <p className="text-sm text-white/50">{s.label}</p>
          </Reveal>
        ))}
      </div>
    </section>
  );
}
