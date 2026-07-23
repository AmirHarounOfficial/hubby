'use client';

import React, { useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import {
  User,
  Building2,
  Lock,
  Bell,
  Save,
  ShieldCheck,
  Box,
  ShoppingBag,
  Zap,
  Truck,
  ChevronRight,
} from 'lucide-react';
import Link from 'next/link';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useAuthStore } from '@/store/auth';
import { useT } from '@/i18n';

const tabs = [
  { id: 'profile', icon: User },
  { id: 'organization', icon: Building2 },
  { id: 'shipping', icon: Truck },
  { id: 'security', icon: Lock },
  { id: 'notifications', icon: Bell },
];

function Banner({ message, kind }: { message: string; kind: 'success' | 'error' }) {
  if (!message) return null;
  return (
    <div
      className={cn(
        'rounded-xl p-3 text-sm border',
        kind === 'success'
          ? 'bg-secondary/10 border-secondary/30 text-secondary'
          : 'bg-destructive/10 border-destructive/30 text-destructive'
      )}
    >
      {message}
    </div>
  );
}

export default function SettingsPage() {
  const t = useT();
  const [activeTab, setActiveTab] = useState('profile');

  const user = useAuthStore((s) => s.user);
  const organizations = useAuthStore((s) => s.organizations);
  const activeOrgId = useAuthStore((s) => s.activeOrgId);
  const setUser = useAuthStore((s) => s.setUser);
  const updateOrganizationName = useAuthStore((s) => s.updateOrganizationName);

  const activeOrg = organizations.find((o) => o.id === activeOrgId) || organizations[0];

  // Profile
  const [name, setName] = useState(user?.name || '');
  const [email, setEmail] = useState(user?.email || '');
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileMsg, setProfileMsg] = useState<{ k: 'success' | 'error'; m: string } | null>(null);

  // Organization
  const [orgName, setOrgName] = useState(activeOrg?.name || '');
  const [savingOrg, setSavingOrg] = useState(false);
  const [orgMsg, setOrgMsg] = useState<{ k: 'success' | 'error'; m: string } | null>(null);

  // Security
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [savingPw, setSavingPw] = useState(false);
  const [pwMsg, setPwMsg] = useState<{ k: 'success' | 'error'; m: string } | null>(null);

  // Notification preferences (persisted via /notification-preferences)
  const [prefs, setPrefs] = useState<Record<string, boolean>>({});
  useEffect(() => {
    api
      .get('/notification-preferences')
      .then((r) => setPrefs(r.data))
      .catch(() => {});
  }, []);

  const savePref = async (key: string, value: boolean) => {
    setPrefs((p) => ({ ...p, [key]: value })); // optimistic
    try {
      const r = await api.put('/notification-preferences', { [key]: value });
      setPrefs(r.data);
    } catch {
      setPrefs((p) => ({ ...p, [key]: !value })); // revert on failure
    }
  };

  const saveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingProfile(true);
    setProfileMsg(null);
    try {
      const res = await api.put('/profile', { name, email });
      setUser({ name: res.data.name, email: res.data.email });
      setProfileMsg({ k: 'success', m: t('settings.profile.updated') });
    } catch (err: any) {
      setProfileMsg({ k: 'error', m: err.response?.data?.message || t('settings.profile.error') });
    } finally {
      setSavingProfile(false);
    }
  };

  const saveOrg = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeOrg) return;
    setSavingOrg(true);
    setOrgMsg(null);
    try {
      const res = await api.put('/organization', { name: orgName });
      updateOrganizationName(activeOrg.id, res.data.name);
      setOrgMsg({ k: 'success', m: t('settings.organization.updated') });
    } catch (err: any) {
      setOrgMsg({ k: 'error', m: err.response?.data?.message || t('settings.organization.error') });
    } finally {
      setSavingOrg(false);
    }
  };

  const savePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingPw(true);
    setPwMsg(null);
    try {
      await api.put('/password', {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      });
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      setPwMsg({ k: 'success', m: t('settings.security.updated') });
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])[0] : null;
      setPwMsg({ k: 'error', m: first || err.response?.data?.message || t('settings.security.error') });
    } finally {
      setSavingPw(false);
    }
  };

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold">{t('settings.title')}</h1>
        <p className="text-muted-foreground text-sm">{t('settings.subtitle')}</p>
      </div>

      <div className="flex flex-col lg:flex-row gap-8">
        <aside className="lg:w-64 flex flex-col gap-2">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={cn(
                'flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all',
                activeTab === tab.id
                  ? 'bg-primary text-white shadow-lg shadow-primary/20'
                  : 'text-muted-foreground hover:bg-accent hover:text-foreground'
              )}
            >
              <tab.icon size={18} />
              {t(`settings.tabs.${tab.id}`)}
            </button>
          ))}
        </aside>

        <div className="flex-1 max-w-3xl">
          {activeTab === 'profile' && (
            <Card className="p-8">
              <form onSubmit={saveProfile} className="space-y-8">
                <div className="flex items-center gap-6 pb-8 border-b border-border">
                  <div className="w-20 h-20 rounded-full bg-primary/20 flex items-center justify-center text-primary border border-primary/30 text-2xl font-bold uppercase">
                    {(name || user?.name || '?').charAt(0)}
                  </div>
                  <div>
                    <h3 className="text-lg font-bold">{t('settings.profile.title')}</h3>
                    <p className="text-sm text-muted-foreground">{t('settings.profile.desc')}</p>
                  </div>
                </div>

                {profileMsg && <Banner message={profileMsg.m} kind={profileMsg.k} />}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <Input label={t('settings.profile.fullName')} value={name} onChange={(e) => setName(e.target.value)} required />
                  <Input label={t('settings.profile.email')} type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
                </div>

                <div className="flex justify-end">
                  <Button type="submit" isLoading={savingProfile}>
                    <Save size={16} className="mr-2" />
                    {t('settings.profile.save')}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {activeTab === 'organization' && (
            <Card className="p-8">
              <form onSubmit={saveOrg} className="space-y-8">
                <div>
                  <h3 className="text-lg font-bold">{t('settings.organization.title')}</h3>
                  <p className="text-sm text-muted-foreground">{t('settings.organization.desc')}</p>
                </div>

                {orgMsg && <Banner message={orgMsg.m} kind={orgMsg.k} />}

                <div className="space-y-6">
                  <Input label={t('settings.organization.name')} value={orgName} onChange={(e) => setOrgName(e.target.value)} required />
                </div>

                <div className="flex justify-end">
                  <Button type="submit" isLoading={savingOrg} disabled={!activeOrg}>
                    {t('settings.organization.update')}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {activeTab === 'shipping' && (
            <Card className="p-8 space-y-6">
              <div>
                <h3 className="text-lg font-bold">{t('settings.shipping.title')}</h3>
                <p className="text-sm text-muted-foreground">{t('settings.shipping.desc')}</p>
              </div>
              <Link
                href="/shipments/carriers"
                className="flex items-center justify-between rounded-xl border border-border px-5 py-4 hover:bg-accent transition-colors"
              >
                <div className="flex items-center gap-3">
                  <Truck size={20} className="text-primary" />
                  <div>
                    <p className="font-medium">{t('settings.shipping.carriersTitle')}</p>
                    <p className="text-xs text-muted-foreground">{t('settings.shipping.carriersDesc')}</p>
                  </div>
                </div>
                <ChevronRight size={18} className="text-muted-foreground" />
              </Link>
            </Card>
          )}

          {activeTab === 'security' && (
            <Card className="p-8">
              <form onSubmit={savePassword} className="space-y-8">
                <div>
                  <h3 className="text-base font-bold">{t('settings.security.title')}</h3>
                  <p className="text-sm text-muted-foreground">{t('settings.security.desc')}</p>
                </div>

                {pwMsg && <Banner message={pwMsg.m} kind={pwMsg.k} />}

                <div className="grid grid-cols-1 gap-4">
                  <Input label={t('settings.security.currentPassword')} type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required />
                  <Input label={t('settings.security.newPassword')} type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} required />
                  <Input label={t('settings.security.confirmPassword')} type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} required />
                </div>

                <div className="flex justify-end">
                  <Button type="submit" isLoading={savingPw}>
                    <ShieldCheck size={16} className="mr-2" />
                    {t('settings.security.update')}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {activeTab === 'notifications' && (
            <Card className="p-8 space-y-8">
              <div>
                <h3 className="text-lg font-bold">{t('settings.notifications.title')}</h3>
                <p className="text-sm text-muted-foreground">{t('settings.notifications.desc')}</p>
              </div>

              <div className="space-y-4">
                {[
                  { key: 'new_orders', label: t('settings.notifications.items.newOrders.label'), desc: t('settings.notifications.items.newOrders.desc'), icon: ShoppingBag },
                  { key: 'inventory_alerts', label: t('settings.notifications.items.inventoryAlerts.label'), desc: t('settings.notifications.items.inventoryAlerts.desc'), icon: Box },
                  { key: 'security_updates', label: t('settings.notifications.items.securityUpdates.label'), desc: t('settings.notifications.items.securityUpdates.desc'), icon: Lock },
                  { key: 'marketing', label: t('settings.notifications.items.marketing.label'), desc: t('settings.notifications.items.marketing.desc'), icon: Zap },
                ].map((item) => (
                  <NotificationToggle
                    key={item.key}
                    label={item.label}
                    desc={item.desc}
                    icon={item.icon}
                    on={!!prefs[item.key]}
                    onToggle={(v) => savePref(item.key, v)}
                  />
                ))}
              </div>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}

function NotificationToggle({
  label,
  desc,
  icon: Icon,
  on,
  onToggle,
}: {
  label: string;
  desc: string;
  icon: React.ComponentType<{ size?: number; className?: string }>;
  on: boolean;
  onToggle: (value: boolean) => void;
}) {
  return (
    <div className="flex items-center justify-between p-4 rounded-xl hover:bg-accent/30 transition-all border border-border/50">
      <div className="flex items-center gap-4">
        <div className="p-2 bg-background rounded-lg border border-border">
          <Icon size={18} className="text-primary" />
        </div>
        <div>
          <p className="text-sm font-bold">{label}</p>
          <p className="text-[11px] text-muted-foreground">{desc}</p>
        </div>
      </div>
      <button
        type="button"
        onClick={() => onToggle(!on)}
        aria-pressed={on}
        className={cn(
          'w-12 h-6 rounded-full relative p-1 transition-colors',
          on ? 'bg-primary' : 'bg-accent'
        )}
      >
        <div
          className={cn(
            'absolute top-1 w-4 h-4 bg-white rounded-full transition-all',
            on ? 'right-1' : 'left-1'
          )}
        />
      </button>
    </div>
  );
}
