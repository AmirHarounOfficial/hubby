'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { ChevronLeft, FileText, Truck, X } from 'lucide-react';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';

type Account = { id: number; carrier_code: string; label: string; is_active: boolean };

export default function ManifestsPage() {
  const t = useT();
  const router = useRouter();
  const { toast } = useToast();
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [manifests, setManifests] = useState<any[]>([]);
  const [pickups, setPickups] = useState<any[]>([]);
  const [mAccount, setMAccount] = useState<number | ''>('');
  const [pAccount, setPAccount] = useState<number | ''>('');
  const [pDate, setPDate] = useState('');
  const [ready, setReady] = useState('09:00');
  const [close, setClose] = useState('17:00');
  const [pieces, setPieces] = useState('1');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const [acc, man, pk] = await Promise.all([
      api.get('/shipping/accounts'),
      api.get('/manifests'),
      api.get('/pickups'),
    ]);
    const active = (acc.data ?? []).filter((a: Account) => a.is_active);
    setAccounts(active);
    if (active.length && !mAccount) setMAccount(active[0].id);
    if (active.length && !pAccount) setPAccount(active[0].id);
    setManifests(man.data.data ?? []);
    setPickups(pk.data.data ?? []);
  }, [mAccount, pAccount]);

  useEffect(() => { void load(); }, [load]);

  const openDoc = async (id: number) => {
    try {
      const res = await api.get(`/manifests/${id}/document`, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      window.open(url, '_blank');
      setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch { toast(t('shipping.actionError'), 'error'); }
  };

  const createManifest = async () => {
    if (!mAccount) return;
    setBusy(true);
    try {
      const carrier = accounts.find((a) => a.id === mAccount)?.carrier_code;
      const ships = await api.get('/shipments', { params: { status: 'label_purchased', carrier_code: carrier } });
      const ids = (ships.data.data ?? []).map((s: any) => s.id);
      if (ids.length === 0) { toast(t('shipping.noEligible'), 'error'); return; }
      await api.post('/manifests', { carrier_account_id: mAccount, shipment_ids: ids });
      toast(t('shipping.manifestCreated'), 'success');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('shipping.actionError'), 'error');
    } finally { setBusy(false); }
  };

  const bookPickup = async () => {
    if (!pAccount || !pDate) return;
    setBusy(true);
    try {
      await api.post('/pickups', {
        carrier_account_id: pAccount, pickup_date: pDate, ready_at: ready, close_at: close, pieces: Number(pieces),
      });
      toast(t('shipping.pickupBooked'), 'success');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('shipping.actionError'), 'error');
    } finally { setBusy(false); }
  };

  const cancelPickup = async (id: number) => {
    try { await api.delete(`/pickups/${id}`); toast(t('shipping.pickupCancelled'), 'success'); await load(); }
    catch { toast(t('shipping.actionError'), 'error'); }
  };

  const accountSelect = (value: number | '', set: (v: number) => void) => (
    <select value={value} onChange={(e) => set(Number(e.target.value))} className="rounded-lg border border-border bg-background px-3 py-2 text-sm">
      {accounts.map((a) => <option key={a.id} value={a.id}>{a.label} ({a.carrier_code})</option>)}
    </select>
  );

  return (
    <div className="space-y-6">
      <button onClick={() => router.push('/shipments')} className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft size={16} /> {t('shipping.back')}
      </button>
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-3"><Truck className="text-primary" />{t('shipping.manifestsTitle')}</h1>
        <p className="text-muted-foreground text-sm">{t('shipping.manifestsHint')}</p>
      </div>

      {/* Manifests */}
      <Card className="p-4 space-y-4">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="text-xs font-medium text-muted-foreground block mb-1">{t('shipping.manifestForCarrier')}</label>
            {accountSelect(mAccount, setMAccount)}
          </div>
          <Button onClick={createManifest} disabled={busy || !mAccount}>{t('shipping.newManifest')}</Button>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead><tr className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold">
              <th className="py-2">{t('shipping.colRef')}</th><th>{t('shipping.colCarrier')}</th>
              <th className="text-center">{t('shipping.colCount')}</th><th>{t('shipping.colDate')}</th><th>{t('shipping.colStatus')}</th><th />
            </tr></thead>
            <tbody className="divide-y divide-border">
              {manifests.length === 0 ? (
                <tr><td colSpan={6} className="py-6 text-center text-muted-foreground">{t('shipping.manifests')}: 0</td></tr>
              ) : manifests.map((m) => (
                <tr key={m.id}>
                  <td className="py-2 font-mono text-xs">{m.reference}</td>
                  <td className="capitalize">{m.carrier_code}</td>
                  <td className="text-center tabular-nums">{m.shipment_count}</td>
                  <td className="text-xs text-muted-foreground">{m.manifest_date}</td>
                  <td><span className="text-[10px] font-bold uppercase">{m.status}</span></td>
                  <td className="text-right">
                    <button onClick={() => openDoc(m.id)} className="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                      <FileText size={14} /> {t('shipping.document')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Pickups */}
      <Card className="p-4 space-y-4">
        <h3 className="font-bold text-sm">{t('shipping.pickups')}</h3>
        <div className="flex flex-wrap items-end gap-3">
          {accountSelect(pAccount, setPAccount)}
          <div><label className="text-xs text-muted-foreground block mb-1">{t('shipping.pickupDate')}</label><Input type="date" value={pDate} onChange={(e) => setPDate(e.target.value)} /></div>
          <div><label className="text-xs text-muted-foreground block mb-1">{t('shipping.readyAt')}</label><Input type="time" value={ready} onChange={(e) => setReady(e.target.value)} /></div>
          <div><label className="text-xs text-muted-foreground block mb-1">{t('shipping.closeAt')}</label><Input type="time" value={close} onChange={(e) => setClose(e.target.value)} /></div>
          <div className="w-20"><label className="text-xs text-muted-foreground block mb-1">{t('shipping.pieces')}</label><Input type="number" value={pieces} onChange={(e) => setPieces(e.target.value)} /></div>
          <Button onClick={bookPickup} disabled={busy || !pAccount || !pDate}>{t('shipping.bookPickup')}</Button>
        </div>
        <div className="divide-y divide-border">
          {pickups.map((p) => (
            <div key={p.id} className="flex items-center justify-between py-2 text-sm">
              <div><span className="font-mono text-xs">{p.reference}</span> <span className="text-muted-foreground">· {p.carrier_code} · {p.pickup_date}</span></div>
              <div className="flex items-center gap-3">
                <span className="text-[10px] font-bold uppercase">{p.status}</span>
                {p.status !== 'cancelled' && (
                  <button onClick={() => cancelPickup(p.id)} className="text-muted-foreground hover:text-red-600"><X size={14} /></button>
                )}
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
