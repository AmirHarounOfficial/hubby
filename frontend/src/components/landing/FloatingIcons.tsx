'use client';

import React, { useLayoutEffect, useRef } from 'react';
import {
  ShoppingBag,
  ShoppingCart,
  Store,
  Package,
  Tag,
  CreditCard,
  Coins,
  Receipt,
  Truck,
  BadgePercent,
  Barcode,
  Wallet,
} from 'lucide-react';
import gsap from 'gsap';
import { pointer, scroll } from './state';

/**
 * An ambient field of store/selling icons drifting behind the content. Two
 * nested layers per icon keep concerns separate: the OUTER element is moved by
 * a rAF loop for scroll + pointer parallax, while the INNER element is animated
 * by GSAP for an endless float + slow spin. Deliberately low-opacity and
 * pointer-events-none so it never competes with the copy.
 *
 * Config is a fixed list (no Math.random in render) to stay hydration-safe.
 */
type IconItem = {
  Icon: React.ComponentType<{ size?: number; strokeWidth?: number }>;
  top: string;
  left: string;
  size: number;
  /** Parallax strength — higher feels "closer". */
  depth: number;
  /** Float animation duration (seconds). */
  dur: number;
  rotate: number;
  color: string;
  opacity: number;
};

const ITEMS: IconItem[] = [
  { Icon: ShoppingBag, top: '14%', left: '8%', size: 46, depth: 1.4, dur: 7, rotate: 12, color: '#818CF8', opacity: 0.22 },
  { Icon: ShoppingCart, top: '70%', left: '12%', size: 52, depth: 1.8, dur: 9, rotate: -14, color: '#34D399', opacity: 0.2 },
  { Icon: Store, top: '24%', left: '84%', size: 50, depth: 1.6, dur: 8, rotate: -8, color: '#A5B4FC', opacity: 0.22 },
  { Icon: Package, top: '78%', left: '80%', size: 44, depth: 2.0, dur: 10, rotate: 16, color: '#6EE7B7', opacity: 0.18 },
  { Icon: Tag, top: '46%', left: '4%', size: 38, depth: 1.2, dur: 6.5, rotate: 20, color: '#FBBF24', opacity: 0.2 },
  { Icon: CreditCard, top: '8%', left: '52%', size: 40, depth: 1.0, dur: 7.5, rotate: -10, color: '#C7D2FE', opacity: 0.16 },
  { Icon: Coins, top: '60%', left: '46%', size: 42, depth: 1.3, dur: 8.5, rotate: 10, color: '#FCD34D', opacity: 0.18 },
  { Icon: Receipt, top: '34%', left: '70%', size: 36, depth: 1.5, dur: 9.5, rotate: -16, color: '#93C5FD', opacity: 0.16 },
  { Icon: Truck, top: '88%', left: '34%', size: 48, depth: 1.7, dur: 8, rotate: 8, color: '#A7F3D0', opacity: 0.17 },
  { Icon: BadgePercent, top: '18%', left: '30%', size: 40, depth: 1.1, dur: 7, rotate: -18, color: '#FBBF24', opacity: 0.18 },
  { Icon: Barcode, top: '52%', left: '90%', size: 44, depth: 2.1, dur: 11, rotate: 6, color: '#C4B5FD', opacity: 0.15 },
  { Icon: Wallet, top: '40%', left: '40%', size: 34, depth: 0.9, dur: 6, rotate: 14, color: '#6EE7B7', opacity: 0.14 },
];

export default function FloatingIcons() {
  const outerRefs = useRef<(HTMLDivElement | null)[]>([]);

  useLayoutEffect(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const inners = outerRefs.current
      .map((el) => el?.firstElementChild as HTMLElement | undefined)
      .filter(Boolean) as HTMLElement[];

    let ctx: gsap.Context | undefined;
    if (!reduce) {
      ctx = gsap.context(() => {
        inners.forEach((inner, i) => {
          const item = ITEMS[i];
          gsap.to(inner, {
            y: i % 2 === 0 ? '+=26' : '-=26',
            rotate: item.rotate,
            duration: item.dur,
            ease: 'sine.inOut',
            repeat: -1,
            yoyo: true,
          });
        });
      });
    }

    // Parallax loop: drift with scroll, lean toward the pointer.
    let raf = 0;
    const render = () => {
      for (let i = 0; i < outerRefs.current.length; i++) {
        const el = outerRefs.current[i];
        if (!el) continue;
        const d = ITEMS[i].depth;
        const px = pointer.sx * d * 14;
        const py = scroll.progress * d * -160 + pointer.sy * d * 14;
        el.style.transform = `translate3d(${px}px, ${py}px, 0)`;
      }
      raf = requestAnimationFrame(render);
    };
    if (!reduce) raf = requestAnimationFrame(render);

    return () => {
      cancelAnimationFrame(raf);
      ctx?.revert();
    };
  }, []);

  return (
    <div className="pointer-events-none fixed inset-0 z-[1] overflow-hidden" aria-hidden>
      {ITEMS.map((item, i) => (
        <div
          key={i}
          ref={(el) => {
            outerRefs.current[i] = el;
          }}
          className="absolute will-change-transform"
          style={{ top: item.top, left: item.left }}
        >
          <div
            className="will-change-transform"
            style={{ color: item.color, opacity: item.opacity, filter: 'drop-shadow(0 0 14px currentColor)' }}
          >
            <item.Icon size={item.size} strokeWidth={1.4} />
          </div>
        </div>
      ))}
    </div>
  );
}
