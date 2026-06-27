'use client';

import React, { useEffect, useRef } from 'react';
import 'lenis/dist/lenis.css';
import { ReactLenis, useLenis, type LenisRef } from 'lenis/react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { scroll, bindPointer } from './state';

gsap.registerPlugin(ScrollTrigger);

/**
 * Bridges Lenis' inertia scrolling into GSAP's ScrollTrigger and publishes the
 * scroll progress/velocity into the shared `scroll` singleton consumed by the
 * WebGL scene. We drive Lenis off the GSAP ticker (autoRaf disabled) so both
 * systems advance on the same clock — the canonical Lenis + ScrollTrigger setup.
 */
function LenisSync() {
  useLenis((lenis) => {
    scroll.progress = lenis.progress;
    scroll.velocity = lenis.velocity;
    ScrollTrigger.update();
  });
  return null;
}

export default function SmoothScroll({ children }: { children: React.ReactNode }) {
  const lenisRef = useRef<LenisRef>(null);

  useEffect(() => {
    bindPointer();

    function raf(time: number) {
      lenisRef.current?.lenis?.raf(time * 1000);
    }

    gsap.ticker.add(raf);
    gsap.ticker.lagSmoothing(0);
    return () => {
      gsap.ticker.remove(raf);
    };
  }, []);

  return (
    <ReactLenis
      root
      ref={lenisRef}
      options={{ autoRaf: false, lerp: 0.1, smoothWheel: true, wheelMultiplier: 1 }}
    >
      <LenisSync />
      {children}
    </ReactLenis>
  );
}
