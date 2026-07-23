'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { ChevronLeft, Truck, Ban, MapPin } from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';
import { shipmentStatusColor } from '@/components/shipping/statusColor';
import { AddTrackingModal } from '@/components/shipping/AddTrackingModal';

type Account = { id: number; carrier_code: string; label: string; is_active: boolean };

export default function ShipmentDetailPage() {
  const t = useT();
  const { id } = useParams();
  const router = useRouter();
  const { toast } = useToast();
  const [shipment, setShipment] = useState<any>(null);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState<number | ''>('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [tracking, setTracking] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setShipment((await api.get(`/shipments/${id}`)).data);
    } catch {
      router.push('/shipments');
    } finally {
      setLoading(false);
    }
  }, [id, router]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    api.get('/shipping/accounts')
      .then((r) => {
        const active = (r.data ?? []).filter((a: Account) => a.is_active);
        setAccounts(active);
        if (active.length) setAccountId(active[0].id);
      })
      .catch(() => {});
  }, []);

  const act = async (fn: () => Promise<any>, okKey: string) => {
    setBusy(true);
    try {
      await fn();
      toast(t(`shipping.${okKey}`), 'success');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('shipping.actionError'), 'error');
    } finally {
      setBusy(false);
    }
  };

  if (loading || !shipment) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }

  const status: string = shipment.status;
  const isDraft = status === 'draft';
  const canCancel = ['draft', 'rated', 'label_purchased', 'awaiting_pickup'].includes(status);
  const isFinal = ['delivered', 'rto_delivered', 'cancelled', 'lost', 'damaged'].includes(status);

  return (
    <div className="space-y-6">
      <button onClick={() => router.push('/shipments')} className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft size={16} /> {t('shipping.back')}
      </button>

      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-3">
            <Truck className="text-primary" size={22} />
            {t('shipping.detailTitle')} <span className="font-mono text-lg">{shipment.reference}</span>
            <span className={cn('px-3 py-1 rounded-full text-xs font-bold uppercase', shipmentStatusColor(status))}>
              {t(`shipping.statuses.${status}`)}
            </span>
          </h1>
          {shipment.tracking_number && (
            <p className="text-sm text-muted-foreground mt-1 font-mono">{shipment.carrier_code} · {shipment.tracking_number}</p>
          )}
        </div>
        <div className="flex items-center gap-2">
          {isDraft && (
            <div className="flex items-center gap-2">
              {accounts.length === 0 ? (
                <span className="text-xs text-muted-foreground">{t('shipping.noCarrierAccounts')}</span>
              ) : (
                <>
                  <select
                    value={accountId}
                    onChange={(e) => setAccountId(Number(e.target.value))}
                    className="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                  >
                    {accounts.map((a) => (
                      <option key={a.id} value={a.id}>{a.label} ({a.carrier_code})</option>
                    ))}
                  </select>
                  <Button
                    onClick={() => act(() => api.post(`/shipments/${id}/label`, { carrier_account_id: accountId }), 'labelBought')}
                    disabled={busy || !accountId}
                  >
                    {t('shipping.purchaseLabel')}
                  </Button>
                </>
              )}
            </div>
          )}
          {!isDraft && !isFinal && (
            <Button variant="outline" onClick={() => setTracking(true)} disabled={busy}>
              <MapPin size={16} className="mr-1" />{t('shipping.addEvent')}
            </Button>
          )}
          {canCancel && (
            <Button variant="outline" onClick={() => act(() => api.post(`/shipments/${id}/cancel`, {}), 'cancelled')} disabled={busy}>
              <Ban size={16} className="mr-1" />{t('shipping.cancel')}
            </Button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2 p-0 overflow-hidden">
          <div className="p-4 border-b border-border font-bold text-sm">{t('shipping.packages')}</div>
          <div className="p-4 space-y-3">
            {(shipment.packages ?? []).map((p: any) => (
              <div key={p.id} className="flex items-center justify-between text-sm border border-border rounded-lg px-3 py-2">
                <span className="font-medium">{t('shipping.piece')} {p.sequence}</span>
                <span className="text-muted-foreground">{t('shipping.weight')}: {p.weight_kg} kg</span>
                <span className="font-mono text-xs">{p.tracking_number || '—'}</span>
              </div>
            ))}
          </div>
          <div className="p-4 border-t border-border">
            <div className="font-bold text-xs uppercase tracking-wider text-muted-foreground mb-2">{t('shipping.contents')}</div>
            <ul className="text-sm divide-y divide-border">
              {(shipment.items ?? []).map((it: any) => (
                <li key={it.id} className="flex items-center justify-between py-1.5">
                  <span>{it.name} <span className="text-[11px] text-muted-foreground">{it.sku}</span></span>
                  <span className="tabular-nums text-muted-foreground">×{it.quantity}</span>
                </li>
              ))}
            </ul>
          </div>
        </Card>

        <Card className="p-4">
          <h3 className="font-bold text-sm mb-4">{t('shipping.timeline')}</h3>
          {(shipment.tracking_events ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('shipping.noEvents')}</p>
          ) : (
            <ul className="space-y-3">
              {shipment.tracking_events.map((e: any) => (
                <li key={e.id} className="flex items-start gap-3 text-sm">
                  <span className={cn('mt-1 w-2 h-2 rounded-full shrink-0', shipmentStatusColor(e.status).split(' ')[0])} />
                  <div>
                    <p className="font-medium">{t(`shipping.statuses.${e.status}`)}</p>
                    {(e.city || e.location) && <p className="text-xs text-muted-foreground">{e.city || e.location}</p>}
                    {e.description_en && <p className="text-xs text-muted-foreground">{e.description_en}</p>}
                    <p className="text-[10px] text-muted-foreground/70">{new Date(e.event_at).toLocaleString()}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {tracking && (
        <AddTrackingModal
          shipmentId={Number(id)}
          onClose={() => setTracking(false)}
          onDone={async () => { setTracking(false); await load(); }}
        />
      )}
    </div>
  );
}
