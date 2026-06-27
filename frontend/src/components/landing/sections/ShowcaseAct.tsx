'use client';

import React, { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { cn } from '@/lib/utils';
import RevealText from '../RevealText';
import Reveal from '../Reveal';
import { beatEls } from '../state';
import { useI18n } from '../i18n';

gsap.registerPlugin(ScrollTrigger);

type Beat = {
  id: string;
  /** Which side of the screen the 3D model occupies (text goes opposite). */
  side: 'left' | 'right';
  eyebrow: string;
  title: string;
  body: string;
};

// Structural layout (ids match the 3D beats; sides alternate). Copy is merged
// in from the active locale's dictionary at render time.
const LAYOUT: { id: string; side: 'left' | 'right' }[] = [
  { id: 'shop', side: 'left' },
  { id: 'cart', side: 'right' },
  { id: 'bag', side: 'left' },
  { id: 'vsbag', side: 'right' },
];

function ShowcaseSection({ beat, locale }: { beat: Beat; locale: string }) {
  const ref = useRef<HTMLDivElement>(null);

  // Register the element; the 3D model reads its live on-screen position.
  useLayoutEffect(() => {
    beatEls[beat.id] = ref.current;
    return () => {
      beatEls[beat.id] = null;
    };
  }, [beat.id]);

  const text = (
    <div className="max-w-md">
      <Reveal>
        <p className="mb-4 text-sm font-medium uppercase tracking-[0.3em] text-secondary">
          {beat.eyebrow}
        </p>
      </Reveal>
      <RevealText
        key={`${beat.id}-${locale}`}
        text={beat.title}
        className="text-4xl font-semibold leading-[1.08] tracking-tight sm:text-5xl"
      />
      <Reveal delay={0.1}>
        <p className="mt-6 text-lg leading-relaxed text-white/60">{beat.body}</p>
      </Reveal>
    </div>
  );

  return (
    <section ref={ref} className="relative flex min-h-screen items-center px-6">
      <div className="mx-auto grid w-full max-w-7xl items-center gap-8 lg:grid-cols-2">
        {beat.side === 'left' ? (
          <>
            <div aria-hidden className="hidden lg:block" />
            <div className={cn('flex justify-start lg:justify-end')}>{text}</div>
          </>
        ) : (
          <>
            <div className="flex justify-start">{text}</div>
            <div aria-hidden className="hidden lg:block" />
          </>
        )}
      </div>
    </section>
  );
}

/**
 * The scroll-choreographed act: a run of full-height sections, each pairing one
 * 3D shape (driven in the WebGL layer by `beats[id]`) with copy on the opposite
 * side. A wrapper trigger also drives `beats.recede` so the hero centerpiece
 * shrinks aside while these shapes own the stage.
 */
export default function ShowcaseAct() {
  const { t, locale } = useI18n();
  const wrap = useRef<HTMLDivElement>(null);

  // Register the whole act so the centerpiece can recede while it's in view.
  useLayoutEffect(() => {
    beatEls.__showcase = wrap.current;
    return () => {
      beatEls.__showcase = null;
    };
  }, []);

  return (
    <div id="showcase" ref={wrap}>
      {LAYOUT.map((item, i) => {
        const copy = t.showcase.beats[i];
        const beat: Beat = { ...item, ...copy };
        return <ShowcaseSection key={beat.id} beat={beat} locale={locale} />;
      })}
    </div>
  );
}
