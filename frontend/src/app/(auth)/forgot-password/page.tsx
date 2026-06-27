'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { ArrowRight, ArrowLeft, MailCheck } from 'lucide-react';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import AuthShell from '@/components/auth/AuthShell';
import { I18nProvider, useI18n } from '@/components/landing/i18n';

const inputClass =
  'bg-white/5 border-white/10 text-white placeholder:text-white/30 focus:ring-primary/40';

export default function ForgotPasswordPage() {
  return (
    <I18nProvider>
      <ForgotInner />
    </I18nProvider>
  );
}

function ForgotInner() {
  const { t } = useI18n();
  const c = t.auth.passwords;

  const [email, setEmail] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setError('');

    try {
      await api.post('/password/forgot', { email });
      setSent(true);
    } catch (err: any) {
      setError(err.response?.data?.message || c.generalError);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthShell accent="primary" screen="login">
      <div className="mb-8">
        <h1 className="text-2xl font-semibold tracking-tight">{c.forgotTitle}</h1>
        <p className="mt-2 text-sm text-white/50">{c.forgotSubtitle}</p>
      </div>

      {sent ? (
        <div className="space-y-6">
          <div className="flex items-start gap-3 rounded-xl border border-secondary/30 bg-secondary/10 p-4 text-sm text-secondary">
            <MailCheck size={18} className="mt-0.5 shrink-0" />
            <span>{c.sent}</span>
          </div>
          <Link
            href="/login"
            data-cursor
            className="flex items-center justify-center gap-2 text-sm text-white/60 transition-colors hover:text-white"
          >
            <ArrowLeft size={16} className="rtl:-scale-x-100" />
            {c.backToLogin}
          </Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && (
            <div className="rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
              {error}
            </div>
          )}

          <Input
            label={c.email}
            type="email"
            placeholder="name@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className={inputClass}
            required
          />

          <Button
            type="submit"
            isLoading={isLoading}
            data-cursor
            className="group h-12 w-full rounded-xl border-0 bg-gradient-to-r from-primary to-secondary text-base font-semibold shadow-lg shadow-primary/20"
          >
            {c.sendButton}
            <ArrowRight size={18} className="ms-2 transition-transform group-hover:translate-x-1 rtl:-scale-x-100" />
          </Button>

          <Link
            href="/login"
            data-cursor
            className="flex items-center justify-center gap-2 text-sm text-white/50 transition-colors hover:text-white"
          >
            <ArrowLeft size={16} className="rtl:-scale-x-100" />
            {c.backToLogin}
          </Link>
        </form>
      )}
    </AuthShell>
  );
}
