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
} from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useAuthStore } from '@/store/auth';

const tabs = [
  { id: 'profile', label: 'Profile', icon: User },
  { id: 'organization', label: 'Organization', icon: Building2 },
  { id: 'security', label: 'Security', icon: Lock },
  { id: 'notifications', label: 'Notifications', icon: Bell },
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
      setProfileMsg({ k: 'success', m: 'Profile updated.' });
    } catch (err: any) {
      setProfileMsg({ k: 'error', m: err.response?.data?.message || 'Could not update profile.' });
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
      setOrgMsg({ k: 'success', m: 'Organization updated.' });
    } catch (err: any) {
      setOrgMsg({ k: 'error', m: err.response?.data?.message || 'Could not update organization.' });
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
      setPwMsg({ k: 'success', m: 'Password updated.' });
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])[0] : null;
      setPwMsg({ k: 'error', m: first || err.response?.data?.message || 'Could not update password.' });
    } finally {
      setSavingPw(false);
    }
  };

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold">Settings</h1>
        <p className="text-muted-foreground text-sm">Manage your personal preferences and organization settings.</p>
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
              {tab.label}
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
                    <h3 className="text-lg font-bold">Your Profile</h3>
                    <p className="text-sm text-muted-foreground">This information is displayed across the platform.</p>
                  </div>
                </div>

                {profileMsg && <Banner message={profileMsg.m} kind={profileMsg.k} />}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <Input label="Full Name" value={name} onChange={(e) => setName(e.target.value)} required />
                  <Input label="Email Address" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
                </div>

                <div className="flex justify-end">
                  <Button type="submit" isLoading={savingProfile}>
                    <Save size={16} className="mr-2" />
                    Save Changes
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {activeTab === 'organization' && (
            <Card className="p-8">
              <form onSubmit={saveOrg} className="space-y-8">
                <div>
                  <h3 className="text-lg font-bold">Organization Settings</h3>
                  <p className="text-sm text-muted-foreground">Manage your company details.</p>
                </div>

                {orgMsg && <Banner message={orgMsg.m} kind={orgMsg.k} />}

                <div className="space-y-6">
                  <Input label="Organization Name" value={orgName} onChange={(e) => setOrgName(e.target.value)} required />
                </div>

                <div className="flex justify-end">
                  <Button type="submit" isLoading={savingOrg} disabled={!activeOrg}>
                    Update Organization
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {activeTab === 'security' && (
            <Card className="p-8">
              <form onSubmit={savePassword} className="space-y-8">
                <div>
                  <h3 className="text-base font-bold">Change Password</h3>
                  <p className="text-sm text-muted-foreground">Use at least 8 characters.</p>
                </div>

                {pwMsg && <Banner message={pwMsg.m} kind={pwMsg.k} />}

                <div className="grid grid-cols-1 gap-4">
                  <Input label="Current Password" type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required />
                  <Input label="New Password" type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} required />
                  <Input label="Confirm New Password" type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} required />
                </div>

                <div className="flex justify-end">
                  <Button type="submit" isLoading={savingPw}>
                    <ShieldCheck size={16} className="mr-2" />
                    Update Password
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {activeTab === 'notifications' && (
            <Card className="p-8 space-y-8">
              <div>
                <h3 className="text-lg font-bold">Email Notifications</h3>
                <p className="text-sm text-muted-foreground">Choose what notifications you want to receive.</p>
              </div>

              <div className="space-y-4">
                {[
                  { key: 'new_orders', label: 'New Orders', desc: 'Receive an email for every new order.', icon: ShoppingBag },
                  { key: 'inventory_alerts', label: 'Inventory Alerts', desc: 'Get notified when stock levels are low.', icon: Box },
                  { key: 'security_updates', label: 'Security Updates', desc: 'Important account security notifications.', icon: Lock },
                  { key: 'marketing', label: 'Marketing', desc: 'Tips, features, and platform updates.', icon: Zap },
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
