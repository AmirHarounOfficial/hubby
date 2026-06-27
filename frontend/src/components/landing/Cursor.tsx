'use client';

import React, { useEffect, useRef, useState } from 'react';
import { lerp } from './state';

/**
 * A blend-mode cursor follower with inertia. Grows when hovering interactive
 * elements (anything carrying `data-cursor` or native links/buttons) and shows
 * an optional label via `data-cursor-label`. Auto-disabled on touch devices so
 * we never hide the native cursor where there isn't one.
 */
export default function Cursor() {
  const dotRef = useRef<HTMLDivElement>(null);
  const ringRef = useRef<HTMLDivElement>(null);
  const labelRef = useRef<HTMLSpanElement>(null);
  const [enabled, setEnabled] = useState(false);

  useEffect(() => {
    const fine = window.matchMedia('(pointer: fine)').matches;
    if (!fine) return;
    setEnabled(true);

    const target = { x: window.innerWidth / 2, y: window.innerHeight / 2 };
    const ring = { x: target.x, y: target.y };
    let hover = false;
    let raf = 0;

    const onMove = (e: PointerEvent) => {
      target.x = e.clientX;
      target.y = e.clientY;
      const el = (e.target as HTMLElement)?.closest(
        '[data-cursor], a, button, input, textarea, select, [role="button"]',
      ) as HTMLElement | null;
      const nextHover = !!el;
      if (nextHover !== hover) {
        hover = nextHover;
        ringRef.current?.classList.toggle('is-hover', hover);
      }
      const label = el?.getAttribute('data-cursor-label') ?? '';
      if (labelRef.current && labelRef.current.textContent !== label) {
        labelRef.current.textContent = label;
      }
    };

    const onDown = () => ringRef.current?.classList.add('is-down');
    const onUp = () => ringRef.current?.classList.remove('is-down');

    const render = () => {
      ring.x = lerp(ring.x, target.x, 0.18);
      ring.y = lerp(ring.y, target.y, 0.18);
      if (dotRef.current) {
        dotRef.current.style.transform = `translate3d(${target.x}px, ${target.y}px, 0)`;
      }
      if (ringRef.current) {
        ringRef.current.style.transform = `translate3d(${ring.x}px, ${ring.y}px, 0)`;
      }
      raf = requestAnimationFrame(render);
    };

    window.addEventListener('pointermove', onMove, { passive: true });
    window.addEventListener('pointerdown', onDown);
    window.addEventListener('pointerup', onUp);
    raf = requestAnimationFrame(render);

    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerdown', onDown);
      window.removeEventListener('pointerup', onUp);
    };
  }, []);

  if (!enabled) return null;

  return (
    <div className="cursor-root" aria-hidden>
      <div ref={dotRef} className="cursor-dot" />
      <div ref={ringRef} className="cursor-ring">
        <span ref={labelRef} className="cursor-label" />
      </div>
    </div>
  );
}
