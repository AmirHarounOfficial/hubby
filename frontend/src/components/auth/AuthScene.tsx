'use client';

import React, { Suspense, useRef } from 'react';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import {
  Environment,
  Float,
  Icosahedron,
  Lightformer,
  MeshDistortMaterial,
} from '@react-three/drei';
import * as THREE from 'three';
import { bindPointer, lerp, pointer } from '../landing/state';
import GradientBackground from '../landing/scene/GradientBackground';
import Particles from '../landing/scene/Particles';

const COLOR_A = new THREE.Color('#6366F1');
const COLOR_B = new THREE.Color('#10B981');

/** Small gold coin accent. */
function Coin({
  position,
  scale = 1,
  color = '#FBBF24',
  speed = 1,
}: {
  position: [number, number, number];
  scale?: number;
  color?: string;
  speed?: number;
}) {
  return (
    <Float speed={speed * 1.3} rotationIntensity={1} floatIntensity={1.6}>
      <mesh position={position} scale={scale} rotation={[Math.PI / 2.2, 0, 0]}>
        <cylinderGeometry args={[1, 1, 0.22, 48]} />
        <meshStandardMaterial
          color={color}
          metalness={0.75}
          roughness={0.22}
          emissive={color}
          emissiveIntensity={0.3}
        />
      </mesh>
    </Float>
  );
}

function Content({ accent }: { accent: THREE.Color }) {
  const blob = useRef<THREE.Mesh>(null);
  const distort = useRef<{ distort: number; color: THREE.Color }>(null);
  const { camera } = useThree();

  useFrame((state) => {
    const t = state.clock.elapsedTime;
    pointer.sx = lerp(pointer.sx, pointer.x, 0.05);
    pointer.sy = lerp(pointer.sy, pointer.y, 0.05);

    camera.position.x = lerp(camera.position.x, pointer.sx * 0.8, 0.05);
    camera.position.y = lerp(camera.position.y, pointer.sy * 0.6, 0.05);
    camera.lookAt(0, 0, 0);

    if (blob.current) {
      blob.current.rotation.y = t * 0.08;
      blob.current.rotation.z = t * 0.05;
      blob.current.scale.setScalar(0.9 + Math.sin(t * 0.8) * 0.03);
    }
    if (distort.current) {
      distort.current.color.lerp(accent, 0.02);
    }
  });

  return (
    <>
      <GradientBackground />

      <ambientLight intensity={0.5} />
      <hemisphereLight intensity={0.6} color="#cdd6ff" groundColor="#0a0f1f" />
      <directionalLight position={[5, 6, 4]} intensity={1.6} color="#ffffff" />
      <pointLight position={[-6, -2, 2]} intensity={50} distance={20} color="#4F46E5" />
      <pointLight position={[6, 3, -2]} intensity={45} distance={20} color="#10B981" />

      <Environment resolution={256} frames={1}>
        <Lightformer intensity={2} position={[0, 2, 5]} scale={[8, 8, 1]} color="#ffffff" />
        <Lightformer intensity={1.3} position={[-5, 1, 2]} scale={[5, 5, 1]} color="#818CF8" />
        <Lightformer intensity={1.3} position={[5, -1, 2]} scale={[5, 5, 1]} color="#34D399" />
      </Environment>

      {/* Visual cluster weighted to the left so it sits behind the brand panel
          and leaves the form side clean/readable. */}
      <group position={[-1.9, 0.1, 0]}>
        <Icosahedron ref={blob} args={[1, 12]}>
          <MeshDistortMaterial
            ref={distort as never}
            color={COLOR_A}
            roughness={0.18}
            metalness={0.45}
            distort={0.35}
            speed={1.8}
            emissive="#1E1B4B"
            emissiveIntensity={0.3}
          />
        </Icosahedron>

        <Icosahedron args={[1.35, 1]}>
          <meshBasicMaterial color="#3B3F82" wireframe transparent opacity={0.16} />
        </Icosahedron>

        <Coin position={[1.7, 1.5, -0.4]} scale={0.3} color="#FBBF24" speed={1.1} />
        <Coin position={[-1.4, -1.5, -0.3]} scale={0.26} color="#FFD66B" speed={0.9} />
      </group>

      <Particles count={900} />
    </>
  );
}

export default function AuthScene({ accent = COLOR_A }: { accent?: THREE.Color }) {
  React.useEffect(() => {
    bindPointer();
  }, []);

  return (
    <Canvas
      className="!absolute inset-0 h-full w-full"
      style={{ pointerEvents: 'none' }}
      dpr={[1, 1.8]}
      gl={{ antialias: true, alpha: false, powerPreference: 'high-performance' }}
      camera={{ position: [0, 0, 6], fov: 42 }}
    >
      <Suspense fallback={null}>
        <Content accent={accent} />
      </Suspense>
    </Canvas>
  );
}

export { COLOR_A, COLOR_B };
