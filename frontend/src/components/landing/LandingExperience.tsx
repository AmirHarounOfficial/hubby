'use client';

import React, { useEffect } from 'react';
import dynamic from 'next/dynamic';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import SmoothScroll from './SmoothScroll';
import Cursor from './Cursor';
import FloatingIcons from './FloatingIcons';
import Preloader from './Preloader';
import Nav from './sections/Nav';
import Hero from './sections/Hero';
import Manifesto from './sections/Manifesto';
import ShowcaseAct from './sections/ShowcaseAct';
import Capabilities from './sections/Capabilities';
import Flow from './sections/Flow';
import Showcase from './sections/Showcase';
import CTA from './sections/CTA';
import Footer from './sections/Footer';
import { I18nProvider, useI18n } from './i18n';

gsap.registerPlugin(ScrollTrigger);

// Keep Three.js entirely on the client — it can't render on the server.
const Experience = dynamic(() => import('./scene/Experience'), { ssr: false });

export default function LandingExperience() {
  return (
    <I18nProvider>
      <LandingInner />
    </I18nProvider>
  );
}

function LandingInner() {
  const { dir, locale } = useI18n();

  useEffect(() => {
    // Pinned/scrubbed triggers measure layout on creation; once webfonts and
    // the canvas settle (or the language/direction changes), re-measure so
    // start/end positions stay accurate.
    const refresh = () => ScrollTrigger.refresh();
    const id = window.setTimeout(refresh, 400);
    window.addEventListener('load', refresh);
    if (document.fonts?.ready) document.fonts.ready.then(refresh);
    return () => {
      window.clearTimeout(id);
      window.removeEventListener('load', refresh);
    };
  }, []);

  // Re-measure pinned/horizontal triggers after a direction switch.
  useEffect(() => {
    const id = window.setTimeout(() => ScrollTrigger.refresh(), 120);
    return () => window.clearTimeout(id);
  }, [dir]);

  return (
    <div
      dir={dir}
      lang={locale}
      // `overflow-x-clip` contains the vw-based animated tracks so phones never
      // get accidental horizontal scroll/zoom. `clip` (not `hidden`) avoids
      // creating a scroll container that would break the GSAP pins / Lenis.
      className="landing-root relative overflow-x-clip bg-[#F4F8F7] text-[#122E33] selection:bg-secondary/30"
    >
      <Preloader />
      <Cursor />

      {/* Fixed WebGL world behind the scrolling DOM. */}
      <div className="pointer-events-none fixed inset-0 z-0">
        <Experience />
      </div>

      {/* Ambient drifting store/selling icons (above the canvas, under content). */}
      <FloatingIcons />

      <SmoothScroll>
        <Nav />
        <main className="relative z-10">
          {/* The whole page is ONE continuous, luminous 3D world (the fixed
              scene shows through everywhere) — hero and story sit on the same
              living field, content floats on frosted glass. */}
          <Hero />
          <Manifesto />
          <ShowcaseAct />
          <Capabilities />
          <Flow />
          <Showcase />
          <CTA />
        </main>
        <Footer />
      </SmoothScroll>
    </div>
  );
}
