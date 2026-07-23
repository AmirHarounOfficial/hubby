'use client';

import React, { useState } from 'react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';

const MANUAL_STATUSES = [
  'picked_up', 'in_transit', 'at_origin_hub', 'at_destination_hub', 'customs_clearance',
  'out_for_delivery', 'delivery_attempted', 'held', 'delivered', 'returned_to_origin',
  'rto_in_transit', 'rto_delivered', 'lost', 'damaged', 'exception',
];

export function AddTrackingModal({ shipmentId, onClose, onDone }: { shipmentId: number; onClose: () => void; onDone: () => void }) {
  const t = useT();
  const { toast } = useToast();
  const [status, setStatus] = useState('in_transit');
  const [eventAt, setEventAt] = useState(() => new Date().toISOString().slice(0, 16));
  const [city, setCity] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      await api.post(`/shipments/${shipmentId}/tracking-events`, {
        status,
        event_at: new Date(eventAt).toISOString(),
        city: city || undefined,
        description: note || undefined,
      });
      toast(t('shipping.eventAdded'), 'success');
      onDone();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('shipping.actionError'), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <Card className="w-full max-w-md p-5 space-y-4" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between">
          <h3 className="font-bold">{t('shipping.eventTitle')}</h3>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground"><X size={18} /></button>
        </div>
        <p className="text-xs text-muted-foreground">{t('shipping.eventHint')}</p>

        <div className="space-y-3">
          <div>
            <label className="text-xs font-medium text-muted-foreground">{t('shipping.eventStatus')}</label>
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
            >
              {MANUAL_STATUSES.map((s) => (
                <option key={s} value={s}>{t(`shipping.statuses.${s}`)}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">{t('shipping.eventAt')}</label>
            <Input type="datetime-local" value={eventAt} onChange={(e) => setEventAt(e.target.value)} className="mt-1" />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">{t('shipping.eventCity')}</label>
            <Input value={city} onChange={(e) => setCity(e.target.value)} className="mt-1" />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">{t('shipping.eventNote')}</label>
            <Input value={note} onChange={(e) => setNote(e.target.value)} className="mt-1" />
          </div>
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button variant="outline" onClick={onClose} disabled={busy}>{t('shipping.cancel')}</Button>
          <Button onClick={submit} disabled={busy}>{t('shipping.save')}</Button>
        </div>
      </Card>
    </div>
  );
}
