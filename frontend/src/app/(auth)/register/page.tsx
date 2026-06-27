'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowRight } from 'lucide-react';
import { useAuthStore } from '@/store/auth';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import AuthShell from '@/components/auth/AuthShell';
import { I18nProvider, useI18n } from '@/components/landing/i18n';

const inputClass =
  'bg-white/5 border-white/10 text-white placeholder:text-white/30 focus:ring-secondary/40';

export default function RegisterPage() {
  return (
    <I18nProvider>
      <RegisterInner />
    </I18nProvider>
  );
}

function RegisterInner() {
  const { t } = useI18n();
  const c = t.auth.register;

  const [formData, setFormData] = useState({
    name: '',
    email: '',
    organization_name: '',
    password: '',
    password_confirmation: '',
  });
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const router = useRouter();
  const setAuth = useAuthStore((state) => state.setAuth);
  const setOrganizations = useAuthStore((state) => state.setOrganizations);
  const setActiveOrgId = useAuthStore((state) => state.setActiveOrgId);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrors({});

    try {
      const response = await api.post('/register', formData);
      const { access_token, user } = response.data;

      setAuth(user, access_token);

      if (user.organizations && user.organizations.length > 0) {
        setOrganizations(user.organizations);
        setActiveOrgId(user.organizations[0].id);
      }

      router.push('/onboarding');
    } catch (err: any) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors);
      } else {
        setErrors({
          general: [err.response?.data?.message || err.message || c.generalError],
        });
        console.error('Registration error:', err);
      }
    } finally {
      setIsLoading(false);
    }
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  return (
    <AuthShell accent="secondary" screen="register">
      <div className="mb-8">
        <h1 className="text-2xl font-semibold tracking-tight">{c.title}</h1>
        <p className="mt-2 text-sm text-white/50">{c.subtitle}</p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        {errors.general && (
          <div className="rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
            {errors.general[0]}
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={c.name}
            name="name"
            placeholder={c.namePlaceholder}
            value={formData.name}
            onChange={handleChange}
            error={errors.name?.[0]}
            className={inputClass}
            required
          />
          <Input
            label={c.org}
            name="organization_name"
            placeholder={c.orgPlaceholder}
            value={formData.organization_name}
            onChange={handleChange}
            error={errors.organization_name?.[0]}
            className={inputClass}
            required
          />
        </div>

        <Input
          label={c.email}
          name="email"
          type="email"
          placeholder="name@example.com"
          value={formData.email}
          onChange={handleChange}
          error={errors.email?.[0]}
          className={inputClass}
          required
        />

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={c.password}
            name="password"
            type="password"
            placeholder="••••••••"
            value={formData.password}
            onChange={handleChange}
            error={errors.password?.[0]}
            className={inputClass}
            required
          />
          <Input
            label={c.confirm}
            name="password_confirmation"
            type="password"
            placeholder="••••••••"
            value={formData.password_confirmation}
            onChange={handleChange}
            className={inputClass}
            required
          />
        </div>

        <Button
          type="submit"
          isLoading={isLoading}
          data-cursor
          className="group mt-2 h-12 w-full rounded-xl border-0 bg-gradient-to-r from-secondary to-primary text-base font-semibold shadow-lg shadow-secondary/20"
        >
          {c.submit}
          <ArrowRight size={18} className="ms-2 transition-transform group-hover:translate-x-1 rtl:-scale-x-100" />
        </Button>

        <p className="text-center text-sm text-white/50">
          {c.haveAccount}{' '}
          <Link
            href="/login"
            data-cursor
            className="font-medium text-white transition-colors hover:text-secondary"
          >
            {c.signin}
          </Link>
        </p>
      </form>

      <p className="mt-6 text-center text-xs text-white/40">
        {c.termsPre}{' '}
        <Link href="/terms" data-cursor className="underline hover:text-white">
          {c.terms}
        </Link>{' '}
        {c.and}{' '}
        <Link href="/privacy" data-cursor className="underline hover:text-white">
          {c.privacy}
        </Link>
        .
      </p>
    </AuthShell>
  );
}
