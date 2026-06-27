'use client';

import React, { useMemo, useRef } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import { pointer, scroll } from '../state';

/**
 * A drifting star/dust field surrounding the centerpiece. Slowly rotates on its
 * own, parallaxes with the pointer, and pushes deeper as you scroll — selling
 * the sense of travelling through space.
 */
export default function Particles({ count = 1400 }: { count?: number }) {
  const ref = useRef<THREE.Points>(null);

  const positions = useMemo(() => {
    const arr = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
      // Distribute in a hollow-ish sphere shell.
      const r = 6 + Math.pow(Math.random(), 0.5) * 9;
      const theta = Math.random() * Math.PI * 2;
      const phi = Math.acos(2 * Math.random() - 1);
      arr[i * 3] = r * Math.sin(phi) * Math.cos(theta);
      arr[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
      arr[i * 3 + 2] = r * Math.cos(phi);
    }
    return arr;
  }, [count]);

  useFrame((_, delta) => {
    const p = ref.current;
    if (!p) return;
    p.rotation.y += delta * 0.02;
    p.rotation.x += delta * 0.008;
    p.position.x += (pointer.sx * 0.6 - p.position.x) * 0.04;
    p.position.y += (pointer.sy * 0.6 - p.position.y) * 0.04;
    p.position.z = scroll.progress * 4;
  });

  return (
    <points ref={ref} frustumCulled={false}>
      <bufferGeometry>
        <bufferAttribute attach="attributes-position" args={[positions, 3]} />
      </bufferGeometry>
      <pointsMaterial
        size={0.025}
        sizeAttenuation
        color="#A5B4FC"
        transparent
        opacity={0.7}
        depthWrite={false}
        blending={THREE.AdditiveBlending}
      />
    </points>
  );
}
