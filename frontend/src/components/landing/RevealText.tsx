'use client';

import React, { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { cn } from '@/lib/utils';

gsap.registerPlugin(ScrollTrigger);

/**
 * Word-by-word masked reveal for headlines. Each word sits in an
 * overflow-hidden box so it rises up from behind a mask as you scroll in.
 * Pass the headline as a plain string; per-word markup is generated for you.
 */
export default function RevealText({
  text,
  className,
  wordClassName,
  start = 'top 80%',
  stagger = 0.08,
  as: Tag = 'h2',
}: {
  text: string;
  className?: string;
  wordClassName?: string;
  start?: string;
  stagger?: number;
  as?: React.ElementType;
}) {
  const ref = useRef<HTMLElement>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const words = el.querySelectorAll('[data-word]');
    const ctx = gsap.context(() => {
      gsap.fromTo(
        words,
        { yPercent: 120, opacity: 0 },
        {
          yPercent: 0,
          opacity: 1,
          duration: 1.1,
          ease: 'power4.out',
          stagger,
          scrollTrigger: { trigger: el, start, once: true },
        },
      );
    }, el);
    return () => ctx.revert();
  }, [start, stagger]);

  return React.createElement(
    Tag,
    { ref, className: cn('flex flex-wrap', className), 'aria-label': text },
    text.split(' ').map((word, i) => (
        <span
          key={i}
          className="relative me-[0.28em] inline-block overflow-hidden pb-[0.14em]"
          aria-hidden
        >
          <span data-word className={cn('inline-block will-change-transform', wordClassName)}>
            {word}
          </span>
        </span>
      )),
  );
}
