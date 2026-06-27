'use client';

import React, { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

type RevealProps = {
  children: React.ReactNode;
  className?: string;
  /** Seconds of stagger delay applied to the element. */
  delay?: number;
  /** Vertical travel distance in px. */
  y?: number;
  as?: React.ElementType;
};

/**
 * Fades + lifts its children into view once they cross ~85% of the viewport.
 * Uses a scoped gsap.context so triggers are cleaned up on unmount.
 */
export default function Reveal({
  children,
  className,
  delay = 0,
  y = 48,
  as: Tag = 'div',
}: RevealProps) {
  const ref = useRef<HTMLElement>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const ctx = gsap.context(() => {
      gsap.fromTo(
        el,
        { opacity: 0, y, filter: 'blur(8px)' },
        {
          opacity: 1,
          y: 0,
          filter: 'blur(0px)',
          duration: 1,
          delay,
          ease: 'power3.out',
          scrollTrigger: { trigger: el, start: 'top 85%', once: true },
        },
      );
    }, el);
    return () => ctx.revert();
  }, [delay, y]);

  return React.createElement(Tag, { ref, className }, children);
}
