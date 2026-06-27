'use client';

import React, { useEffect, useState } from 'react';
import { Info, ArrowRight, ShieldCheck } from 'lucide-react';
import Modal from '@/components/ui/Modal';
import Input from '@/components/ui/Input';
import Button from '@/components/ui/Button';
import api from '@/lib/api';
import { getPlatform, type PlatformId } from '@/lib/platforms';

interface ConnectStoreModalProps {
  platformId: PlatformId | null;
  /** Whether the operator has configured this platform's OAuth app (env keys). */
  oauthEnabled?: boolean;
  onClose: () => void;
  onConnected: () => void;
}

/**
 * Self-serve store connection for a tenant. The merchant connects their own
 * store with credentials they control — no operator env access required.
 * OAuth-capable platforms also offer a one-click redirect when the workspace
 * has that integration configured.
 */
export default function ConnectStoreModal({ platformId, oauthEnabled = false, onClose, onConnected }: ConnectStoreModalProps) {
  const platform = platformId ? getPlatform(platformId) : null;
  // One-click OAuth is only offered when the platform supports it AND the
  // operator has configured its app keys; otherwise it's token-only.
  const showOAuth = platform?.auth === 'oauth' && oauthEnabled;

  const [name, setName] = useState('');
  const [domain, setDomain] = useState('');
  const [token, setToken] = useState('');
  const [secret, setSecret] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [oauthBusy, setOauthBusy] = useState(false);
  const [error, setError] = useState('');

  // Reset the form whenever a different platform is opened.
  useEffect(() => {
    setName('');
    setDomain('');
    setToken('');
    setSecret('');
    setError('');
  }, [platformId]);

  if (!platform || !platformId) return null;

  const startOAuth = async () => {
    setError('');
    if (!domain.trim()) {
      setError(`Enter your ${platform.domainLabel.toLowerCase()} first.`);
      return;
    }
    setOauthBusy(true);
    try {
      const res = await api.get(`/oauth/${platformId}/redirect`, { params: { shop: domain.trim() } });
      if (res.data?.url) {
        window.location.href = res.data.url;
        return;
      }
      setError('Could not start the connection. Try connecting with a token below.');
    } catch (err: any) {
      // Operator hasn't enabled this OAuth app — fall back to manual token entry.
      setError(
        err.response?.data?.message ||
          'One-click connect isn’t enabled for this platform yet — connect with an access token below.',
      );
    } finally {
      setOauthBusy(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setIsSubmitting(true);
    try {
      await api.post('/stores/connect', {
        name: name.trim(),
        platform: platformId,
        domain: domain.trim(),
        access_token: token.trim(),
        api_secret: platform.secretLabel ? secret.trim() : undefined,
      });
      onConnected();
      onClose();
    } catch (err: any) {
      const resp = err.response?.data;
      const firstFieldError = (Object.values(resp?.errors ?? {})[0] as string[] | undefined)?.[0];
      setError(resp?.message || firstFieldError || 'Could not connect the store.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Modal isOpen={!!platformId} onClose={onClose} title={`Connect ${platform.name}`} size="lg">
      <div className="space-y-6">
        <div className="flex items-center gap-3">
          <div className={`p-3 rounded-2xl bg-background border border-border shadow-inner ${platform.color}`}>
            <platform.icon size={26} />
          </div>
          <div>
            <p className="font-bold">{platform.name}</p>
            <p className="text-xs text-muted-foreground">Connect your {platform.name} store to start syncing.</p>
          </div>
        </div>

        {/* Where to find the credentials. */}
        <div className="flex items-start gap-2 rounded-xl bg-primary/5 border border-primary/15 p-3 text-xs text-muted-foreground">
          <Info size={15} className="text-primary mt-0.5 shrink-0" />
          <span>{platform.help}</span>
        </div>

        {error && (
          <div className="rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <Input
            label="Store name"
            placeholder={`My ${platform.name} store`}
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />

          <Input
            label={platform.domainLabel}
            placeholder={platform.domainPlaceholder}
            value={domain}
            onChange={(e) => setDomain(e.target.value)}
            required
          />

          {/* One-click connect — only when the operator has configured this platform. */}
          {showOAuth && (
            <div className="space-y-3">
              <Button type="button" variant="primary" className="w-full group" isLoading={oauthBusy} onClick={startOAuth}>
                Continue with {platform.name}
                <ArrowRight size={16} className="ml-2 transition-transform group-hover:translate-x-1" />
              </Button>
              <div className="flex items-center gap-3 py-1">
                <span className="h-px flex-1 bg-border" />
                <span className="text-[10px] uppercase tracking-widest text-muted-foreground">or use a token</span>
                <span className="h-px flex-1 bg-border" />
              </div>
            </div>
          )}

          <Input
            label={platform.tokenLabel}
            placeholder="••••••••••••••••"
            value={token}
            onChange={(e) => setToken(e.target.value)}
            required
          />

          {platform.secretLabel && (
            <Input
              label={platform.secretLabel}
              placeholder="••••••••••••••••"
              value={secret}
              onChange={(e) => setSecret(e.target.value)}
              required
            />
          )}

          <div className="flex items-center gap-2 text-[10px] text-muted-foreground">
            <ShieldCheck size={13} className="text-secondary" />
            Credentials are stored encrypted and used only to sync your store.
          </div>

          <div className="flex gap-3 pt-2">
            <Button type="button" variant="outline" className="flex-1" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" variant="primary" className="flex-1" isLoading={isSubmitting}>
              Connect store
            </Button>
          </div>
        </form>
      </div>
    </Modal>
  );
}
