'use client';

import React, { Suspense } from 'react';
import { Canvas } from '@react-three/fiber';
import Scene from './Scene';

/**
 * The fixed full-viewport WebGL layer. Lives behind all page content
 * (pointer-events-none) so the DOM scrolls over the 3D world. Dynamically
 * imported with `ssr: false` from the page so Three never runs on the server.
 */
export default function Experience() {
  return (
    <Canvas
      className="!fixed inset-0 h-full w-full"
      style={{ pointerEvents: 'none' }}
      dpr={[1, 1.8]}
      gl={{ antialias: true, alpha: false, powerPreference: 'high-performance' }}
      camera={{ position: [0, 0, 8], fov: 42 }}
    >
      <Suspense fallback={null}>
        <Scene />
      </Suspense>
    </Canvas>
  );
}
