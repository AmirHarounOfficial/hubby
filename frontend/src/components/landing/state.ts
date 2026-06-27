/**
 * Hot-path shared state for the immersive landing page.
 *
 * Deliberately NOT a React store: these values change every frame (scroll +
 * pointer) and are read inside requestAnimationFrame / r3f's useFrame. Routing
 * them through React state would trigger re-renders on every tick. Instead we
 * mutate these plain singletons and let the animation loops read them directly.
 */

export const pointer = {
  /** Normalised pointer position in [-1, 1], y up. */
  x: 0,
  y: 0,
  /** Smoothed (lerped) values the scene actually follows. */
  sx: 0,
  sy: 0,
};

export const scroll = {
  /** Whole-page scroll progress in [0, 1]. */
  progress: 0,
  /** Lenis scroll velocity (px/frame-ish), useful for stretch/skew effects. */
  velocity: 0,
};

/**
 * Non-reactive layout flags the WebGL loops read. `rtl` mirrors the scene's
 * left/right choreography for the Arabic (right-to-left) layout.
 */
export const layout = {
  rtl: false,
};

/**
 * Live registry of the showcase section elements, keyed by beat id
 * ('shop' | 'cart' | 'bag' | 'vsbag', plus '__showcase' for the whole act).
 * The 3D models read these elements' real on-screen position each frame, so the
 * choreography is always perfectly in sync with the visible text — and immune
 * to ScrollTrigger pin-spacer offsets from the pinned sections above.
 */
export const beatEls: Record<string, HTMLElement | null> = {};

/**
 * 0→1 progress of a section as it travels up through the viewport: 0 when its
 * center sits at the bottom edge, 0.5 when perfectly centered, 1 at the top.
 */
export function sectionProgress(el: HTMLElement | null | undefined): number {
  if (!el || typeof window === 'undefined') return 0;
  const vh = window.innerHeight || 1;
  const rect = el.getBoundingClientRect();
  const center = rect.top + rect.height / 2;
  return Math.min(1, Math.max(0, 1 - center / vh));
}

/** 0→1 "in view" factor for a tall block (1 while it fills the viewport). */
export function coverage(el: HTMLElement | null | undefined): number {
  if (!el || typeof window === 'undefined') return 0;
  const vh = window.innerHeight || 1;
  const rect = el.getBoundingClientRect();
  const enter = Math.min(1, Math.max(0, 1 - rect.top / vh));
  const exit = Math.min(1, Math.max(0, rect.bottom / vh));
  return Math.min(enter, exit);
}

/** Linear interpolation helper shared by the animation loops. */
export const lerp = (a: number, b: number, t: number) => a + (b - a) * t;

/** Smoothstep easing (0→1) with clamped edges. */
export const smoothstep = (edge0: number, edge1: number, x: number) => {
  const t = Math.min(1, Math.max(0, (x - edge0) / (edge1 - edge0)));
  return t * t * (3 - 2 * t);
};

let pointerBound = false;

/** Attach a single global pointer listener (idempotent). */
export function bindPointer() {
  if (pointerBound || typeof window === 'undefined') return;
  pointerBound = true;
  window.addEventListener(
    'pointermove',
    (e) => {
      pointer.x = (e.clientX / window.innerWidth) * 2 - 1;
      pointer.y = -((e.clientY / window.innerHeight) * 2 - 1);
    },
    { passive: true },
  );
}
