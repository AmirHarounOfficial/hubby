'use client';

import React, { useMemo, useRef } from 'react';
import { useFrame } from '@react-three/fiber';
import { useGLTF } from '@react-three/drei';
import * as THREE from 'three';
import { beatEls, layout, sectionProgress } from '../state';

/**
 * A model whose transform is driven entirely by its section's scroll progress
 * (`beats[id]`, written by the matching DOM showcase section). As the section
 * travels through the viewport the model sweeps up its side of the screen,
 * peaks in scale + turns a full rotation mid-section, then shrinks away — so
 * each shape gets its own choreographed entrance/exit instead of sitting fixed.
 */
export default function ChoreographedModel({
  id,
  url,
  side,
  targetSize = 1.7,
  rotation = [0, 0, 0],
}: {
  id: string;
  url: string;
  side: 'left' | 'right';
  targetSize?: number;
  rotation?: [number, number, number];
}) {
  const { scene } = useGLTF(url);
  const outer = useRef<THREE.Group>(null);
  const spinRef = useRef<THREE.Group>(null);

  const { object, scale } = useMemo(() => {
    const clone = scene.clone(true);
    clone.traverse((child) => {
      const mesh = child as THREE.Mesh;
      if (mesh.isMesh) mesh.frustumCulled = false;
    });
    clone.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(clone);
    const size = new THREE.Vector3();
    const center = new THREE.Vector3();
    box.getSize(size);
    box.getCenter(center);
    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    clone.position.sub(center);
    return { object: clone, scale: targetSize / maxDim };
  }, [scene, targetSize]);

  useFrame((state) => {
    const g = outer.current;
    if (!g) return;
    const q = sectionProgress(beatEls[id]);

    // Only render (and animate) while the section is in play.
    const visible = q > 0.002 && q < 0.998;
    g.visible = visible;
    if (!visible) return;

    // Mirror the side for the Arabic (right-to-left) layout so the shape stays
    // opposite its text, which the DOM grid flips automatically under dir=rtl.
    const mirror = layout.rtl ? -1 : 1;
    // A perspective FOV is vertical, so on narrow/portrait viewports the
    // horizontal view shrinks and a fixed side offset (±2.4) falls off-screen.
    // Clamp the offset to a fraction of the actual visible half-width so the
    // shapes stay on-screen on phones while keeping the full spread on desktop.
    const mobile = state.size.width < 640;
    const halfW = state.viewport.width / 2;
    const sideMag = Math.min(2.4, halfW * 0.62);
    const sideX = (side === 'left' ? -sideMag : sideMag) * mirror;
    const sweep = (side === 'left' ? 0.9 : -0.9) * mirror;
    // Sweep up its side, drifting a touch toward centre at the midpoint.
    g.position.x = sideX + (q - 0.5) * sweep;
    // On phones the copy is top-aligned, so keep the shape in the lower portion
    // (and a little smaller) so it frames the text instead of covering it.
    g.position.y = mobile
      ? THREE.MathUtils.lerp(-3.2, 0.4, q)
      : THREE.MathUtils.lerp(-3.6, 3.6, q);
    g.position.z = THREE.MathUtils.lerp(-1.2, 0.4, Math.sin(Math.PI * q));

    // Grow to full size at the centre of the section, shrink at the edges.
    const env = Math.sin(Math.PI * q);
    g.scale.setScalar((0.45 + env * 0.75) * (mobile ? 0.6 : 1));

    if (spinRef.current) {
      spinRef.current.rotation.y = q * Math.PI * 2 + state.clock.elapsedTime * 0.15;
    }
  });

  return (
    <group ref={outer} visible={false}>
      <group ref={spinRef} rotation={rotation}>
        <primitive object={object} scale={scale} />
      </group>
    </group>
  );
}

useGLTF.preload('/models/2nd__low_poly_shop.glb');
useGLTF.preload('/models/designer_shopping_bag.glb');
useGLTF.preload('/models/shopping_cart.glb');
useGLTF.preload('/models/victoria_secret_shopping_bag.glb');
