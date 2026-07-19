'use client';

import React, { useMemo, useRef } from 'react';
import { useFrame, useThree } from '@react-three/fiber';
import * as THREE from 'three';
import { scroll } from '../state';

/**
 * A full-screen animated gradient (flowing value-noise between the two brand
 * colors). Rendered on a plane locked to the camera so it always fills the
 * frame and sits behind every other object. Colour mix drifts with page scroll.
 */
const vertex = /* glsl */ `
  varying vec2 vUv;
  void main() {
    vUv = uv;
    gl_Position = vec4(position, 1.0);
  }
`;

const fragment = /* glsl */ `
  precision highp float;
  varying vec2 vUv;
  uniform float uTime;
  uniform float uScroll;
  uniform vec2 uRes;
  uniform vec3 uColorA;
  uniform vec3 uColorB;
  uniform vec3 uBase;

  // Cheap hash-based value noise.
  float hash(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
  float noise(vec2 p){
    vec2 i = floor(p); vec2 f = fract(p);
    vec2 u = f*f*(3.0-2.0*f);
    return mix(mix(hash(i+vec2(0,0)), hash(i+vec2(1,0)), u.x),
               mix(hash(i+vec2(0,1)), hash(i+vec2(1,1)), u.x), u.y);
  }
  float fbm(vec2 p){
    float v = 0.0; float a = 0.5;
    for(int i=0;i<5;i++){ v += a*noise(p); p *= 2.0; a *= 0.5; }
    return v;
  }

  const vec3 CYAN = vec3(0.30, 0.78, 0.86);

  void main(){
    vec2 uv = vUv;
    float aspect = uRes.x / uRes.y;
    vec2 p = vec2(uv.x * aspect, uv.y);
    float t = uTime * 0.03 + uScroll * 0.6;

    // Soft brand blooms drifting over a near-white ground — luminous, not dark.
    float f1 = fbm(p * 1.7 + vec2(t, t * 0.6));
    float f2 = fbm(p * 2.3 - vec2(t * 0.8, t));
    float cy = fbm(p * 1.3 + vec2(-t * 0.5, t * 0.4));

    vec3 col = uBase;
    col = mix(col, mix(uBase, uColorA, 0.55), smoothstep(0.45, 0.95, f1) * 0.42);
    col = mix(col, mix(uBase, uColorB, 0.70), smoothstep(0.50, 1.00, f2) * 0.50);
    col = mix(col, mix(uBase, CYAN,    0.60), smoothstep(0.55, 0.95, cy) * 0.28);

    // Keep it airy: lift back toward the ground + a gentle top light.
    col = mix(col, uBase, 0.28);
    col += (1.0 - uv.y) * 0.015;

    float grain = (hash(uv * uRes + t) - 0.5) * 0.015;
    col += grain;

    gl_FragColor = vec4(col, 1.0);
  }
`;

export default function GradientBackground() {
  const matRef = useRef<THREE.ShaderMaterial>(null);
  const { size, viewport } = useThree();

  const uniforms = useMemo(
    () => ({
      uTime: { value: 0 },
      uScroll: { value: 0 },
      uRes: { value: new THREE.Vector2(size.width, size.height) },
      uColorA: { value: new THREE.Color('#0B5A5C') },
      uColorB: { value: new THREE.Color('#4FD34A') },
      uBase: { value: new THREE.Color('#F4F8F7') },
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  useFrame((_, delta) => {
    const m = matRef.current;
    if (!m) return;
    m.uniforms.uTime.value += delta;
    m.uniforms.uScroll.value += (scroll.progress - m.uniforms.uScroll.value) * 0.08;
    m.uniforms.uRes.value.set(size.width * viewport.dpr, size.height * viewport.dpr);
  });

  return (
    // Plane drawn in clip space (vertex shader ignores camera), so render it
    // first and never let it write/read depth.
    <mesh frustumCulled={false} renderOrder={-1}>
      <planeGeometry args={[2, 2]} />
      <shaderMaterial
        ref={matRef}
        vertexShader={vertex}
        fragmentShader={fragment}
        uniforms={uniforms}
        depthTest={false}
        depthWrite={false}
      />
    </mesh>
  );
}
