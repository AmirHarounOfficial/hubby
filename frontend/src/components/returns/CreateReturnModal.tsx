'use client';

import React, { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Modal from '@/components/ui/Modal';
import Button from '@/components/ui/Button';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT, useI18n } from '@/i18n';

type Reason = { code: string; group: string; label_en: string; label_ar: string };

/** Pick order items + quantities the customer is returning, choose a reason, create the RMA. */
export function CreateReturnModal({ order, onClose }: { order: any; onClose: () => void }) {
  const t = useT();
  const { locale } = useI18n();
  const router = useRouter();
  const { toast } = useToast();
  const items: any[] = order.items ?? [];

  const [reasons, setReasons] = useState<Reason[]>([]);
  const [reasonCode, setReasonCode] = useState('');
  const [qty, setQty] = useState<Record<number, number>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get('/return-reasons').then((r) => {
      setReasons(r.data);
      if (r.data[0]) setReasonCode(r.data[0].code);
    }).catch(() => {});
  }, []);

  const lines = items
    .map((it) => ({ order_item_id: it.id, quantity: qty[it.id] ?? 0, reason_code: reasonCode }))
    .filter((l) => l.quantity > 0);

  const submit = async () => {
    if (lines.length === 0) return;
    setBusy(true);
    try {
      const res = await api.post('/returns', { order_id: order.id, reason_code: reasonCode, lines });
      toast(t('returns.created'), 'success');
      router.push(`/returns/${res.data.id}`);
    } catch (e: any) {
      toast(e?.response?.data?.message || t('returns.actionError'), 'error');
      setBusy(false);
    }
  };

  return (
    <Modal isOpen onClose={onClose} title={t('returns.createTitle')} size="lg">
      <div className="p-6 space-y-4">
        <p className="text-sm text-muted-foreground">{t('returns.createHint')}</p>

        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('returns.noReturnableItems')}</p>
        ) : (
          <>
            <div className="space-y-2">
              {items.map((it) => (
                <div key={it.id} className="flex items-center gap-3 rounded-lg border border-border p-2">
                  <div className="flex-1">
                    <p className="text-sm font-medium">{it.name}</p>
                    <p className="text-[11px] text-muted-foreground">{it.sku} · ×{it.quantity}</p>
                  </div>
                  <input
                    type="number"
                    min={0}
                    max={it.quantity}
                    value={qty[it.id] ?? 0}
                    onChange={(e) => setQty((s) => ({ ...s, [it.id]: Math.max(0, Math.min(it.quantity, Number(e.target.value))) }))}
                    className="w-20 h-9 rounded-lg border border-border bg-background px-2 text-sm"
                  />
                </div>
              ))}
            </div>

            <label className="block">
              <span className="text-xs font-medium text-muted-foreground">{t('returns.reason')}</span>
              <select value={reasonCode} onChange={(e) => setReasonCode(e.target.value)} className="mt-1 w-full h-10 rounded-lg border border-border bg-background px-3 text-sm">
                {reasons.map((r) => (
                  <option key={r.code} value={r.code}>{locale === 'ar' ? r.label_ar : r.label_en}</option>
                ))}
              </select>
            </label>

            <div className="flex items-center gap-3 pt-1">
              <Button onClick={submit} disabled={busy || lines.length === 0}>{t('returns.create')}</Button>
              <Button variant="outline" onClick={onClose}>{t('returns.cancel')}</Button>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}
