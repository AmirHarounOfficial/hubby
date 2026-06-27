'use client';

import React, { Suspense, useRef } from 'react';
import { useFrame, useThree } from '@react-three/fiber';
import {
  Environment,
  Float,
  Icosahedron,
  Lightformer,
  MeshDistortMaterial,
  Octahedron,
} from '@react-three/drei';
import * as THREE from 'three';
import { beatEls, coverage, lerp, pointer, scroll } from '../state';
import GradientBackground from './GradientBackground';
import Particles from './Particles';
import ChoreographedModel from './ChoreographedModel';

const COLOR_A = new THREE.Color('#6366F1'); // indigo
const COLOR_B = new THREE.Color('#10B981'); // emerald
const tmpColor = new THREE.Color();

type ShapeKind = 'coin' | 'box' | 'ring' | 'shard';

/**
 * A drifting commerce object orbiting the centerpiece. Coins read as money/
 * selling, boxes as packages/orders, rings + shards add variety. Each floats
 * and tumbles on its own via drei's <Float>.
 */
function CommerceShape({
  kind,
  position,
  scale = 1,
  color,
  speed = 1,
}: {
  kind: ShapeKind;
  position: [number, number, number];
  scale?: number;
  color: string;
  speed?: number;
}) {
  return (
    <Float speed={speed * 1.4} rotationIntensity={1.1} floatIntensity={1.6}>
      <group position={position} scale={scale}>
        {kind === 'coin' && (
          // A thick disc tilted toward the camera — catches the key light like a coin.
          <mesh rotation={[Math.PI / 2.2, 0, 0]}>
            <cylinderGeometry args={[1, 1, 0.22, 48]} />
            <meshStandardMaterial
              color={color}
              metalness={0.75}
              roughness={0.22}
              emissive={color}
              emissiveIntensity={0.3}
            />
          </mesh>
        )}
        {kind === 'box' && (
          <mesh>
            <boxGeometry args={[1.4, 1.4, 1.4]} />
            <meshStandardMaterial
              color={color}
              metalness={0.3}
              roughness={0.4}
              emissive={color}
              emissiveIntensity={0.22}
              flatShading
            />
          </mesh>
        )}
        {kind === 'ring' && (
          <mesh>
            <torusGeometry args={[0.85, 0.3, 22, 48]} />
            <meshStandardMaterial
              color={color}
              metalness={0.6}
              roughness={0.3}
              emissive={color}
              emissiveIntensity={0.22}
            />
          </mesh>
        )}
        {kind === 'shard' && (
          <Octahedron args={[1, 0]}>
            <meshStandardMaterial
              color={color}
              roughness={0.15}
              metalness={0.35}
              emissive={color}
              emissiveIntensity={0.25}
              flatShading
            />
          </Octahedron>
        )}
      </group>
    </Float>
  );
}

export default function Scene() {
  const coreGroup = useRef<THREE.Group>(null);
  const blob = useRef<THREE.Mesh>(null);
  const distort = useRef<{ distort: number; color: THREE.Color }>(null);
  const { camera } = useThree();

  useFrame((state, delta) => {
    const t = state.clock.elapsedTime;

    // Smooth the raw pointer into the shared singleton everything else reads.
    pointer.sx = lerp(pointer.sx, pointer.x, 0.05);
    pointer.sy = lerp(pointer.sy, pointer.y, 0.05);

    // Camera gently follows the pointer for a parallax "looking around" feel,
    // and dollies in slightly as the page scrolls.
    camera.position.x = lerp(camera.position.x, pointer.sx * 1.1, 0.05);
    camera.position.y = lerp(camera.position.y, pointer.sy * 0.8, 0.05);
    camera.position.z = lerp(camera.position.z, 8 - scroll.progress * 1.8, 0.05);
    camera.lookAt(0, 0, 0);

    // Centerpiece: spins idly, then recedes up + back while the showcase act
    // runs so the choreographed shapes own the stage.
    const recede = coverage(beatEls.__showcase);
    const c = coreGroup.current;
    if (c) {
      c.rotation.y = scroll.progress * Math.PI * 1.2 + t * 0.05;
      c.rotation.x = Math.sin(t * 0.2) * 0.1;
      c.scale.setScalar(lerp(1, 0.34, recede));
      // Recede to top-centre and far back so it clears the side shapes.
      c.position.set(
        0,
        lerp(0, 2.8, recede) + Math.sin(t * 0.4) * 0.1,
        lerp(0, -4, recede),
      );
    }

    const b = blob.current;
    if (b) {
      const s = 1.2 + Math.sin(t * 0.8) * 0.04 + scroll.velocity * 0.0006;
      b.scale.setScalar(s);
      b.rotation.z = t * 0.1;
    }

    // Drift the blob colour between the two brand hues across the scroll.
    if (distort.current) {
      tmpColor.copy(COLOR_A).lerp(COLOR_B, (Math.sin(scroll.progress * Math.PI * 2) + 1) / 2);
      distort.current.color.lerp(tmpColor, 0.04);
      distort.current.distort = 0.35 + Math.min(scroll.velocity * 0.0008, 0.25);
    }
  });

  return (
    <>
      <GradientBackground />

      {/* Cinematic lighting: ambient + hemisphere fill + key + coloured rims,
          plus a front fill so models facing the camera never read as silhouettes. */}
      <ambientLight intensity={0.5} />
      <hemisphereLight intensity={0.6} color="#cdd6ff" groundColor="#0a0f1f" />
      <directionalLight position={[5, 6, 4]} intensity={1.8} color="#ffffff" />
      <directionalLight position={[0, 1, 6]} intensity={1.1} color="#dfe6ff" />
      <pointLight position={[-6, -2, 2]} intensity={60} distance={20} color="#4F46E5" />
      <pointLight position={[6, 3, -2]} intensity={50} distance={20} color="#10B981" />

      {/* Local image-based lighting (rendered offline — no network/HDR fetch)
          so the glb PBR materials get real reflections + soft fill. */}
      <Environment resolution={256} frames={1}>
        <Lightformer intensity={2.2} position={[0, 2, 5]} scale={[8, 8, 1]} color="#ffffff" />
        <Lightformer intensity={1.4} position={[-5, 1, 2]} scale={[5, 5, 1]} color="#818CF8" />
        <Lightformer intensity={1.4} position={[5, -1, 2]} scale={[5, 5, 1]} color="#34D399" />
        <Lightformer intensity={1.0} position={[0, -4, 3]} scale={[6, 3, 1]} color="#a78bfa" />
      </Environment>

      <group ref={coreGroup}>
        {/* Central morphing blob. */}
        <Icosahedron ref={blob} args={[1, 12]}>
          <MeshDistortMaterial
            ref={distort as never}
            color={COLOR_A}
            roughness={0.18}
            metalness={0.45}
            distort={0.35}
            speed={1.6}
            emissive="#1E1B4B"
            emissiveIntensity={0.4}
          />
        </Icosahedron>

        {/* Wireframe shell wrapping it for a "tech" read. */}
        <Icosahedron args={[1.9, 1]}>
          <meshBasicMaterial color="#3B3F82" wireframe transparent opacity={0.18} />
        </Icosahedron>

        {/* Gold coins ride with the centerpiece (recede together). */}
        <CommerceShape kind="coin" position={[2.0, 1.7, 0.2]} scale={0.32} color="#FBBF24" speed={1.2} />
        <CommerceShape kind="coin" position={[-2.2, -1.6, 0.2]} scale={0.26} color="#FFD66B" speed={0.95} />
      </group>

      {/* Scroll-choreographed commerce shapes — each driven by its section's
          beat, sweeping up its side of the screen as you scroll. */}
      <Suspense fallback={null}>
        <ChoreographedModel
          id="shop"
          url="/models/2nd__low_poly_shop.glb"
          side="left"
          targetSize={1.8}
          rotation={[0, 0.5, 0]}
        />
        <ChoreographedModel
          id="cart"
          url="/models/shopping_cart.glb"
          side="right"
          targetSize={2.0}
        />
        <ChoreographedModel
          id="bag"
          url="/models/designer_shopping_bag.glb"
          side="left"
          targetSize={1.7}
        />
        <ChoreographedModel
          id="vsbag"
          url="/models/victoria_secret_shopping_bag.glb"
          side="right"
          targetSize={1.6}
        />
      </Suspense>

      <Particles />
    </>
  );
}
