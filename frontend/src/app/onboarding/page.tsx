'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { CheckCircle2, ArrowRight, Loader2, Zap } from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { PLATFORMS, getPlatform } from '@/lib/platforms';

export default function OnboardingPage() {
  const [step, setStep] = useState(1);
  const [selectedPlatform, setSelectedPlatform] = useState('');
  const [isConnecting, setIsConnecting] = useState(false);
  const router = useRouter();

  const handlePlatformSelect = (id: string) => {
    setSelectedPlatform(id);
    setStep(2);
  };

  const handleConnect = async () => {
    setIsConnecting(true);
    try {
      // Mocking the OAuth redirect for now since we don't have the full backend URL/secrets
      const response = await api.get(`/oauth/${selectedPlatform}/redirect`);
      
      // In a real scenario, we'd redirect to the platform's OAuth page
      // window.location.href = response.data.url;
      
      // For this demo, let's simulate the flow
      setTimeout(() => {
        setIsConnecting(false);
        setStep(3);
        
        setTimeout(() => {
          setStep(4);
        }, 3000);
      }, 2000);
    } catch (err) {
      console.error(err);
      setIsConnecting(false);
      // Fallback for demo
      setStep(3);
      setTimeout(() => setStep(4), 3000);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-6 bg-[radial-gradient(circle_at_top_right,_var(--tw-gradient-stops))] from-primary/10 via-background to-background">
      <div className="w-full max-w-2xl">
        <div className="flex items-center justify-center gap-4 mb-12">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="flex items-center">
              <div className={cn(
                "w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all",
                step === i ? "bg-primary text-white ring-4 ring-primary/20" : 
                step > i ? "bg-secondary text-white" : "bg-accent text-muted-foreground"
              )}>
                {step > i ? <CheckCircle2 size={16} /> : i}
              </div>
              {i < 4 && <div className={cn("w-12 h-px mx-2", step > i ? "bg-secondary" : "bg-border")} />}
            </div>
          ))}
        </div>

        {step === 1 && (
          <div className="space-y-8 text-center">
            <div>
              <h2 className="text-3xl font-bold mb-2">Connect your first store</h2>
              <p className="text-muted-foreground text-sm">Select your e-commerce platform to get started.</p>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
              {PLATFORMS.map((p) => (
                <button
                  key={p.id}
                  onClick={() => handlePlatformSelect(p.id)}
                  className="bg-card border border-border p-6 rounded-2xl flex flex-col items-center gap-3 hover:border-primary hover:shadow-xl hover:shadow-primary/10 transition-all group"
                >
                  <p.icon size={32} className={cn("transition-transform group-hover:scale-110", p.color)} />
                  <span className="text-sm font-semibold">{p.name}</span>
                </button>
              ))}
            </div>
          </div>
        )}

        {step === 2 && (() => {
          const platform = getPlatform(selectedPlatform);
          const PlatformIcon = platform?.icon;
          
          return (
            <Card className="p-8 text-center space-y-6">
              <div className="mx-auto w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center text-primary mb-2">
                {PlatformIcon && <PlatformIcon size={32} />}
              </div>
              <div>
                <h2 className="text-2xl font-bold mb-1">Connect {platform?.name}</h2>
                <p className="text-muted-foreground text-sm">We'll need your permission to manage your orders and products.</p>
              </div>
              <div className="bg-background border border-border p-4 rounded-xl text-left flex items-start gap-3">
                <Zap size={20} className="text-warning mt-0.5 shrink-0" />
                <p className="text-xs text-muted-foreground">
                  By clicking connect, you'll be redirected to {platform?.name} to approve HubbyGlobal.
                </p>
              </div>
              <div className="flex gap-4">
                <Button onClick={() => setStep(1)} variant="outline" className="flex-1">Back</Button>
                <Button onClick={handleConnect} isLoading={isConnecting} className="flex-1">Connect Store</Button>
              </div>
            </Card>
          );
        })()}

        {step === 3 && (
          <Card className="p-12 text-center space-y-6">
            <Loader2 className="animate-spin text-primary mx-auto" size={48} />
            <div>
              <h2 className="text-2xl font-bold mb-2">Synchronizing Data</h2>
              <p className="text-muted-foreground text-sm">We're importing your products and orders. This may take a moment.</p>
            </div>
            <div className="w-full bg-accent h-1.5 rounded-full overflow-hidden">
              <div className="bg-primary h-full animate-progress-bar w-2/3" />
            </div>
          </Card>
        )}

        {step === 4 && (
          <Card className="p-12 text-center space-y-6">
            <div className="w-20 h-20 bg-secondary/10 rounded-full flex items-center justify-center text-secondary mx-auto mb-2 animate-bounce">
              <CheckCircle2 size={48} />
            </div>
            <div>
              <h2 className="text-3xl font-bold mb-2">You're all set!</h2>
              <p className="text-muted-foreground text-sm">Your {getPlatform(selectedPlatform).name} store is successfully connected.</p>
            </div>
            <Button onClick={() => router.push('/dashboard')} className="w-full group">
              Go to Dashboard
              <ArrowRight size={18} className="ml-2 transition-transform group-hover:translate-x-1" />
            </Button>
          </Card>
        )}
      </div>
    </div>
  );
}
