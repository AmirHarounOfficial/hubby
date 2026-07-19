'use client';

import React, { useMemo } from 'react';
import { useFrame, useThree } from '@react-three/fiber';
import * as THREE from 'three';
import { lerp, pointer, scroll } from '../state';
import GradientBackground from './GradientBackground';

/**
 * The commerce constellation: a luminous Hubby core with the merchant's sales
 * channels orbiting it in 3D, linked by connection lines with data streaming
 * inward — the product's promise ("every store, one orbit") rendered as the hero.
 */

// Soft radial disc used for the core, channel nodes and particles.
function makeGlow() {
  const s = 128;
  const cv = document.createElement('canvas');
  cv.width = cv.height = s;
  const ctx = cv.getContext('2d')!;
  const g = ctx.createRadialGradient(s / 2, s / 2, 0, s / 2, s / 2, s / 2);
  g.addColorStop(0, 'rgba(255,255,255,1)');
  g.addColorStop(0.35, 'rgba(255,255,255,0.85)');
  g.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, s, s);
  return new THREE.CanvasTexture(cv);
}

// Channel brand hues (Shopify, Salla, Amazon, Noon, WooCommerce, Zid, Trendyol).
const CHANNELS = ['#5AC85A', '#0EA5A5', '#F6A623', '#E6B800', '#8B5CF6', '#F97316', '#F2712A'];
const CORE_TEAL = '#0B5A5C';
const CORE_GREEN = '#4FD34A';

export default function Scene() {
  const { camera } = useThree();
  const glow = useMemo(makeGlow, []);

  const rig = useMemo(() => {
    const group = new THREE.Group();
    const N = CHANNELS.length;

    const sprite = (color: string, scale: number, opacity = 1) => {
      const s = new THREE.Sprite(
        new THREE.SpriteMaterial({
          map: glow,
          color: new THREE.Color(color),
          transparent: true,
          opacity,
          depthWrite: false,
        }),
      );
      s.scale.setScalar(scale);
      group.add(s);
      return s;
    };

    // Orbiting channel nodes.
    const nodes = CHANNELS.map((c, i) => {
      const node = {
        sp: sprite(c, 0.62),
        color: new THREE.Color(c),
        r: 2.95 + ((i * 13) % 7) / 7 * 1.7,
        inc: (i / N) * Math.PI * 0.95 - 0.48,
        ph: (i / N) * Math.PI * 2,
        spd: 0.09 + ((i * 7) % 5) / 5 * 0.09,
      };
      return node;
    });

    // Luminous Hubby core (layered soft discs).
    sprite(CORE_GREEN, 3.6, 0.1);
    sprite(CORE_TEAL, 1.6, 0.98);
    sprite(CORE_GREEN, 0.78, 0.95);

    // Connection lines: each channel → core.
    const lgeo = new THREE.BufferGeometry();
    const lpos = new Float32Array(N * 2 * 3);
    const lcol = new Float32Array(N * 2 * 3);
    const teal = new THREE.Color(CORE_TEAL);
    nodes.forEach((n, i) => {
      n.color.toArray(lcol, i * 6);
      teal.toArray(lcol, i * 6 + 3);
    });
    lgeo.setAttribute('position', new THREE.BufferAttribute(lpos, 3));
    lgeo.setAttribute('color', new THREE.BufferAttribute(lcol, 3));
    const lines = new THREE.LineSegments(
      lgeo,
      new THREE.LineBasicMaterial({ vertexColors: true, transparent: true, opacity: 0.18, depthWrite: false }),
    );
    group.add(lines);

    // Particles streaming inward along the lines.
    const P = 90;
    const pgeo = new THREE.BufferGeometry();
    const ppos = new Float32Array(P * 3);
    const pcol = new Float32Array(P * 3);
    const parts = Array.from({ length: P }, (_, k) => {
      const n = k % N;
      nodes[n].color.toArray(pcol, k * 3);
      return { n, t: Math.random(), spd: 0.15 + Math.random() * 0.22 };
    });
    pgeo.setAttribute('position', new THREE.BufferAttribute(ppos, 3));
    pgeo.setAttribute('color', new THREE.BufferAttribute(pcol, 3));
    const points = new THREE.Points(
      pgeo,
      new THREE.PointsMaterial({
        map: glow,
        size: 0.16,
        vertexColors: true,
        transparent: true,
        depthWrite: false,
        sizeAttenuation: true,
      }),
    );
    group.add(points);

    return {
      group,
      nodes,
      parts,
      lattr: lgeo.attributes.position as THREE.BufferAttribute,
      pattr: pgeo.attributes.position as THREE.BufferAttribute,
      pcolAttr: pgeo.attributes.color as THREE.BufferAttribute,
    };
  }, [glow]);

  const orbit = (nd: (typeof rig.nodes)[number], t: number) => {
    const a = nd.ph + t * nd.spd;
    return new THREE.Vector3(
      Math.cos(a) * nd.r,
      Math.sin(a) * nd.r * 0.5 * Math.sin(nd.inc * 2.0) + Math.sin(nd.inc) * 1.05,
      Math.sin(a) * nd.r * Math.cos(nd.inc),
    );
  };

  useFrame((state, delta) => {
    const t = state.clock.elapsedTime;

    // Pointer parallax + gentle scroll dolly.
    pointer.sx = lerp(pointer.sx, pointer.x, 0.05);
    pointer.sy = lerp(pointer.sy, pointer.y, 0.05);
    camera.position.x = lerp(camera.position.x, pointer.sx * 1.0, 0.05);
    camera.position.y = lerp(camera.position.y, pointer.sy * 0.6, 0.05);
    camera.position.z = lerp(camera.position.z, 8 - scroll.progress * 1.4, 0.05);
    camera.lookAt(0, 0, 0);

    rig.group.rotation.y = t * 0.08;

    const N = rig.nodes.length;
    for (let i = 0; i < N; i++) {
      const p = orbit(rig.nodes[i], t);
      rig.nodes[i].sp.position.copy(p);
      rig.lattr.array[i * 6] = p.x;
      rig.lattr.array[i * 6 + 1] = p.y;
      rig.lattr.array[i * 6 + 2] = p.z;
      rig.lattr.array[i * 6 + 3] = 0;
      rig.lattr.array[i * 6 + 4] = 0;
      rig.lattr.array[i * 6 + 5] = 0;
    }
    rig.lattr.needsUpdate = true;

    for (let k = 0; k < rig.parts.length; k++) {
      const pt = rig.parts[k];
      pt.t -= pt.spd * delta;
      if (pt.t < 0) {
        pt.t = 1;
        pt.n = (pt.n + 3) % N;
        rig.nodes[pt.n].color.toArray(rig.pcolAttr.array as unknown as number[], k * 3);
        rig.pcolAttr.needsUpdate = true;
      }
      const p = orbit(rig.nodes[pt.n], t).multiplyScalar(pt.t);
      rig.pattr.array[k * 3] = p.x;
      rig.pattr.array[k * 3 + 1] = p.y;
      rig.pattr.array[k * 3 + 2] = p.z;
    }
    rig.pattr.needsUpdate = true;
  });

  return (
    <>
      <GradientBackground />
      <ambientLight intensity={0.9} />
      <primitive object={rig.group} />
    </>
  );
}
