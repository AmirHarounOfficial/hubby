'use client';

import React, { useState } from 'react';
import Modal from '@/components/ui/Modal';
import Button from '@/components/ui/Button';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';

const CONDITIONS = ['new', 'opened', 'used', 'damaged', 'defective', 'wrong_item', 'missing_parts', 'unknown'];
const DISPOSITIONS = ['restock', 'scrap', 'quarantine', 'return_to_vendor', 'repair'];

type Line = { condition: string; disposition: string; quantity_restock: number; quantity_scrap: number };

/** Grade each received line: pick a condition + disposition, and split the received qty into restock/scrap. */
export function InspectModal({ rma, onClose, onDone }: { rma: any; onClose: () => void; onDone: () => void }) {
  const t = useT();
  const { toast } = useToast();
  const [busy, setBusy] = useState(false);
  const [state, setState] = useState<Record<number, Line>>(() =>
    Object.fromEntries(
      rma.items.map((it: any) => [
        it.id,
        { condition: 'new', disposition: 'restock', quantity_restock: it.quantity_received, quantity_scrap: 0 },
      ]),
    ),
  );

  const set = (id: number, patch: Partial<Line>) => setState((s) => ({ ...s, [id]: { ...s[id], ...patch } }));

  const submit = async () => {
    setBusy(true);
    try {
      await api.post(`/returns/${rma.id}/inspect`, {
        items: rma.items.map((it: any) => ({ return_item_id: it.id, ...state[it.id] })),
      });
      onDone();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('returns.actionError'), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal isOpen onClose={onClose} title={t('returns.inspect')} size="xl">
      <div className="p-6 space-y-4">
        <p className="text-sm text-muted-foreground">{t('returns.inspectHint')}</p>
        <div className="space-y-3 max-h-[50vh] overflow-y-auto">
          {rma.items.map((it: any) => {
            const line = state[it.id];
            return (
              <div key={it.id} className="rounded-xl border border-border p-3 space-y-3">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="font-medium text-sm">{it.name}</p>
                    <p className="text-[11px] text-muted-foreground">{it.sku} · {t('returns.qtyReceived')}: {it.quantity_received}</p>
                  </div>
                </div>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
                  <Select label={t('returns.condition')} value={line.condition} onChange={(v) => set(it.id, { condition: v })} options={CONDITIONS} tPrefix="returns.conditions" t={t} />
                  <Select label={t('returns.disposition')} value={line.disposition} onChange={(v) => set(it.id, { disposition: v })} options={DISPOSITIONS} tPrefix="returns.dispositions" t={t} />
                  <Num label={t('returns.restockQty')} value={line.quantity_restock} max={it.quantity_received} onChange={(n) => set(it.id, { quantity_restock: n })} />
                  <Num label={t('returns.scrapQty')} value={line.quantity_scrap} max={it.quantity_received} onChange={(n) => set(it.id, { quantity_scrap: n })} />
                </div>
              </div>
            );
          })}
        </div>
        <div className="flex items-center gap-3 pt-2">
          <Button onClick={submit} disabled={busy}>{t('returns.save')}</Button>
          <Button variant="outline" onClick={onClose}>{t('returns.cancel')}</Button>
        </div>
      </div>
    </Modal>
  );
}

function Select({ label, value, onChange, options, tPrefix, t }: {
  label: string; value: string; onChange: (v: string) => void; options: string[]; tPrefix: string; t: (k: string) => string;
}) {
  return (
    <label className="block">
      <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wide">{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)} className="mt-1 w-full h-9 rounded-lg border border-border bg-background px-2 text-sm">
        {options.map((o) => <option key={o} value={o}>{t(`${tPrefix}.${o}`)}</option>)}
      </select>
    </label>
  );
}

function Num({ label, value, max, onChange }: { label: string; value: number; max: number; onChange: (n: number) => void }) {
  return (
    <label className="block">
      <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wide">{label}</span>
      <input
        type="number"
        min={0}
        max={max}
        value={value}
        onChange={(e) => onChange(Math.max(0, Math.min(max, Number(e.target.value))))}
        className="mt-1 w-full h-9 rounded-lg border border-border bg-background px-2 text-sm"
      />
    </label>
  );
}
