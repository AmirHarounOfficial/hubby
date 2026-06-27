'use client';

import React, { useEffect, useRef, useState } from 'react';
import { useProgress } from '@react-three/drei';
import { ShoppingBag, ShoppingCart, Store, Tag, Coins, Package } from 'lucide-react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { lerp } from './state';

gsap.registerPlugin(ScrollTrigger);

/**
 * A fancy on-brand loading screen. A CSS-3D cube of store/selling icons spins
 * while the real glTF assets download — progress is read from three's loading
 * manager via drei's useProgress. When loading finishes (and a minimum display
 * time has elapsed) the overlay splits open like curtains to reveal the page.
 *
 * Notes:
 * - We avoid spinning up a second WebGL context here (the landing already owns
 *   one); the "3D" is pure CSS transforms, so it's cheap and reliable.
 * - A synthetic climb fills the bar before any asset registers, and a failsafe
 *   guarantees we never trap the user behind the loader.
 */
const FACES = [
  { Icon: ShoppingBag, color: '#818CF8' },
  { Icon: ShoppingCart, color: '#34D399' },
  { Icon: Store, color: '#A5B4FC' },
  { Icon: Tag, color: '#FBBF24' },
  { Icon: Coins, color: '#FCD34D' },
  { Icon: Package, color: '#6EE7B7' },
];

export default function Preloader() {
  const { progress, active, total } = useProgress();
  const [hidden, setHidden] = useState(false);

  // Keep the latest loader values in refs so the rAF loop reads fresh data
  // (the effect runs once with empty deps).
  const progressRef = useRef(0);
  const activeRef = useRef(false);
  const totalRef = useRef(0);
  progressRef.current = progress;
  activeRef.current = active;
  totalRef.current = total;

  const rootRef = useRef<HTMLDivElement>(null);
  const numRef = useRef<HTMLSpanElement>(null);
  const barRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    document.body.style.overflow = 'hidden';

    const start = performance.now();
    const MIN_MS = 2200;
    const FAILSAFE_MS = 14000;
    const displayed = { v: 0 };
    let exiting = false;
    let raf = 0;

    const finish = () => {
      if (exiting) return;
      exiting = true;
      const ctx = gsap.context(() => {
        gsap
          .timeline({
            onComplete: () => {
              document.body.style.overflow = '';
              setHidden(true);
              ScrollTrigger.refresh();
            },
          })
          .to('.preloader__content', { y: -28, opacity: 0, duration: 0.5, ease: 'power2.in' })
          .to(
            '.preloader__panel--top',
            { yPercent: -100, duration: 0.85, ease: 'power4.inOut' },
            '-=0.15',
          )
          .to(
            '.preloader__panel--bottom',
            { yPercent: 100, duration: 0.85, ease: 'power4.inOut' },
            '<',
          );
      }, rootRef);
      // ctx is reverted implicitly when the component unmounts via setHidden.
      void ctx;
    };

    const tick = () => {
      const elapsed = performance.now() - start;
      const synthetic = Math.min(92, (elapsed / 2600) * 92);
      const target =
        totalRef.current > 0 ? Math.max(progressRef.current, displayed.v) : synthetic;
      displayed.v = lerp(displayed.v, Math.max(target, displayed.v), 0.08);

      const realDone = totalRef.current > 0 && progressRef.current >= 100 && !activeRef.current;
      const canFinish = (realDone && elapsed > MIN_MS) || elapsed > FAILSAFE_MS;
      if (canFinish) displayed.v = 100;

      const v = Math.min(100, Math.round(displayed.v));
      if (numRef.current) numRef.current.textContent = String(v).padStart(2, '0');
      if (barRef.current) barRef.current.style.transform = `scaleX(${displayed.v / 100})`;

      if (canFinish) {
        finish();
        return;
      }
      raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);

    return () => {
      cancelAnimationFrame(raf);
      document.body.style.overflow = '';
    };
  }, []);

  if (hidden) return null;

  return (
    <div ref={rootRef} className="preloader" role="status" aria-live="polite" aria-label="Loading">
      <div className="preloader__panel preloader__panel--top" />
      <div className="preloader__panel preloader__panel--bottom" />

      <div className="preloader__content">
        <div className="preloader__stage">
          <div className="preloader__cube">
            {FACES.map(({ Icon, color }, i) => (
              <div key={i} className={`preloader__face preloader__face--${i}`} style={{ color }}>
                <Icon size={40} strokeWidth={1.4} />
              </div>
            ))}
          </div>
        </div>

        <div className="preloader__meta">
          <span className="preloader__brand">HubbyGlobal</span>
          <div className="preloader__bar">
            <div ref={barRef} className="preloader__bar-fill" />
          </div>
          <div className="preloader__pct">
            <span ref={numRef}>00</span>
            <span className="preloader__pct-sign">%</span>
          </div>
        </div>
      </div>
    </div>
  );
}
