'use client';

import React, { useLayoutEffect, useRef } from 'react';
import {
  Layers,
  RefreshCw,
  BarChart3,
  Globe,
  ShieldCheck,
  Smartphone,
} from 'lucide-react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { useI18n } from '../i18n';

gsap.registerPlugin(ScrollTrigger);

const ICONS = [Layers, RefreshCw, BarChart3, Globe, ShieldCheck, Smartphone];
const ACCENTS = [
  'from-indigo-500/30',
  'from-emerald-500/30',
  'from-purple-500/30',
  'from-sky-500/30',
  'from-orange-500/30',
  'from-rose-500/30',
];

/**
 * Horizontal cinematic scroll: the section pins and the card track slides
 * sideways as the user scrolls vertically — the classic gallery interaction.
 * Under RTL the track slides the opposite way.
 */
export default function Capabilities() {
  const { t, dir } = useI18n();
  const section = useRef<HTMLDivElement>(null);
  const track = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const sectionEl = section.current;
    const trackEl = track.current;
    if (!sectionEl || !trackEl) return;

    const rtl = dir === 'rtl';
    const ctx = gsap.context(() => {
      const getScrollDistance = () => trackEl.scrollWidth - window.innerWidth;

      gsap.to(trackEl, {
        x: () => (rtl ? getScrollDistance() : -getScrollDistance()),
        ease: 'none',
        scrollTrigger: {
          trigger: sectionEl,
          start: 'top top',
          end: () => `+=${getScrollDistance()}`,
          scrub: 0.8,
          pin: true,
          invalidateOnRefresh: true,
          anticipatePin: 1,
        },
      });
    }, sectionEl);
    return () => ctx.revert();
  }, [dir]);

  const cards = t.capabilities.cards.map((c, i) => ({
    ...c,
    icon: ICONS[i],
    accent: ACCENTS[i],
  }));

  return (
    <section id="capabilities" ref={section} className="relative h-[100svh] overflow-hidden">
      <div className="pointer-events-none absolute start-6 top-24 z-10 sm:start-12">
        <p className="text-sm font-medium uppercase tracking-[0.3em] text-secondary">
          {t.capabilities.eyebrow}
        </p>
        <h2 className="mt-3 max-w-md text-3xl font-semibold tracking-tight sm:text-5xl">
          {t.capabilities.heading}
        </h2>
      </div>

      <div ref={track} className="flex h-full items-center gap-8 px-6 sm:px-12 will-change-transform">
        {/* Leading spacer so the first card clears the heading. */}
        <div className="h-1 w-[34vw] shrink-0" aria-hidden />
        {cards.map((card, i) => (
          <article
            key={i}
            data-cursor
            className="group relative flex h-[60vh] w-[78vw] shrink-0 flex-col justify-between overflow-hidden rounded-3xl border border-white/10 bg-white/[0.03] p-10 backdrop-blur-md transition-colors hover:border-white/25 sm:w-[44vw] lg:w-[32vw]"
          >
            <div
              className={`pointer-events-none absolute inset-0 bg-gradient-to-br ${card.accent} to-transparent opacity-0 transition-opacity duration-500 group-hover:opacity-100`}
            />
            <div className="relative flex items-center justify-between">
              <span className="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-white">
                <card.icon size={26} />
              </span>
              <span className="font-mono text-sm text-white/30">
                {String(i + 1).padStart(2, '0')}
              </span>
            </div>
            <div className="relative">
              <h3 className="text-2xl font-semibold tracking-tight sm:text-3xl">{card.title}</h3>
              <p className="mt-4 text-base leading-relaxed text-white/55">{card.desc}</p>
            </div>
          </article>
        ))}
        <div className="h-1 w-[10vw] shrink-0" aria-hidden />
      </div>
    </section>
  );
}
