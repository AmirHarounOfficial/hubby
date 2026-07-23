'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { ChevronLeft, Plug, CheckCircle2, Power } from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';

type Catalog = { code: string; label: string; available: boolean; capabilities: string[]; credential_fields: string[] };
type Account = { id: number; carrier_code: string; label: string; is_active: boolean; last_validated_at: string | null };

export default function CarrierAccountsPage() {
  const t = useT();
  const router = useRouter();
  const { toast } = useToast();
  const [catalog, setCatalog] = useState<Catalog[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [carrier, setCarrier] = useState('');
  const [label, setLabel] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const [cat, acc] = await Promise.all([api.get('/shipping/carriers'), api.get('/shipping/accounts')]);
    const carriers: Catalog[] = cat.data.carriers ?? [];
    setCatalog(carriers);
    setAccounts(acc.data ?? []);
    if (!carrier) {
      const firstAvailable = carriers.find((c) => c.available);
      if (firstAvailable) setCarrier(firstAvailable.code);
    }
  }, [carrier]);

  useEffect(() => { void load(); }, [load]);

  const add = async () => {
    if (!carrier || !label.trim()) return;
    setBusy(true);
    try {
      await api.post('/shipping/accounts', { carrier_code: carrier, label: label.trim() });
      toast(t('shipping.accountSaved'), 'success');
      setLabel('');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('shipping.actionError'), 'error');
    } finally {
      setBusy(false);
    }
  };

  const validate = async (id: number) => {
    try {
      const res = await api.post(`/shipping/accounts/${id}/validate`, {});
      toast(res.data.valid ? t('shipping.validated') : t('shipping.actionError'), res.data.valid ? 'success' : 'error');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('shipping.actionError'), 'error');
    }
  };

  const deactivate = async (id: number) => {
    try {
      await api.delete(`/shipping/accounts/${id}`);
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('shipping.actionError'), 'error');
    }
  };

  return (
    <div className="space-y-6">
      <button onClick={() => router.push('/shipments')} className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft size={16} /> {t('shipping.back')}
      </button>

      <div>
        <h1 className="text-2xl font-bold flex items-center gap-3"><Plug className="text-primary" />{t('shipping.accountsTitle')}</h1>
        <p className="text-muted-foreground text-sm">{t('shipping.accountsHint')}</p>
      </div>

      <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-500">
        {t('shipping.manualOnlyHint')}
      </div>

      <Card className="p-4">
        <div className="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end">
          <div>
            <label className="text-xs font-medium text-muted-foreground">{t('shipping.carrier')}</label>
            <select value={carrier} onChange={(e) => setCarrier(e.target.value)} className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm">
              {catalog.map((c) => (
                <option key={c.code} value={c.code} disabled={!c.available}>
                  {c.label}{c.available ? '' : ` — ${t('shipping.comingSoon')}`}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">{t('shipping.label')}</label>
            <Input value={label} onChange={(e) => setLabel(e.target.value)} className="mt-1" placeholder="e.g. Riyadh warehouse" />
          </div>
          <Button onClick={add} disabled={busy || !label.trim()}>{t('shipping.addAccount')}</Button>
        </div>
      </Card>

      <Card className="p-0 overflow-hidden">
        <div className="divide-y divide-border">
          {accounts.length === 0 ? (
            <div className="p-8 text-center text-sm text-muted-foreground">{t('shipping.accountsHint')}</div>
          ) : accounts.map((a) => (
            <div key={a.id} className="flex items-center justify-between px-5 py-3">
              <div>
                <p className="font-medium">{a.label} <span className="text-xs text-muted-foreground capitalize">· {a.carrier_code}</span></p>
                {a.last_validated_at && (
                  <p className="text-[11px] text-emerald-600 flex items-center gap-1"><CheckCircle2 size={12} /> {new Date(a.last_validated_at).toLocaleDateString()}</p>
                )}
              </div>
              <div className="flex items-center gap-2">
                <span className={cn('px-2 py-0.5 rounded text-[10px] font-bold uppercase',
                  a.is_active ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400' : 'bg-muted text-muted-foreground')}>
                  {a.is_active ? 'active' : 'inactive'}
                </span>
                <Button variant="outline" onClick={() => validate(a.id)}>{t('shipping.validate')}</Button>
                {a.is_active && (
                  <Button variant="outline" onClick={() => deactivate(a.id)}><Power size={14} className="mr-1" />{t('shipping.deactivate')}</Button>
                )}
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
