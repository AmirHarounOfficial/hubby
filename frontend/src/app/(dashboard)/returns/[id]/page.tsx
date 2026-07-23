'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { ChevronLeft, CheckCircle2, XCircle, Truck, PackageCheck, ClipboardCheck, Banknote } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Money } from '@/components/ui/Money';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';
import { statusColor } from '@/components/returns/statusColor';
import { InspectModal } from '@/components/returns/InspectModal';

const platformLabel = (p?: string) =>
  p ? p.charAt(0).toUpperCase() + p.slice(1) : '';

export default function ReturnDetailPage() {
  const t = useT();
  const { id } = useParams();
  const router = useRouter();
  const { toast } = useToast();
  const [rma, setRma] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [inspecting, setInspecting] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setRma((await api.get(`/returns/${id}`)).data);
    } catch {
      router.push('/returns');
    } finally {
      setLoading(false);
    }
  }, [id, router]);

  useEffect(() => {
    void load();
  }, [load]);

  const act = async (path: string, body: any, okKey: string) => {
    setBusy(true);
    try {
      await api.post(`/returns/${id}/${path}`, body);
      toast(t(`returns.${okKey}`), 'success');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('returns.actionError'), 'error');
    } finally {
      setBusy(false);
    }
  };

  if (loading || !rma) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }

  const status: string = rma.status;
  const actions: React.ReactNode[] = [];
  if (status === 'requested') {
    actions.push(
      <Button key="a" onClick={() => act('approve', {}, 'approved')} disabled={busy}><CheckCircle2 size={16} className="mr-1" />{t('returns.approve')}</Button>,
      <Button key="r" variant="outline" onClick={() => {
        const reason = window.prompt(t('returns.rejectReason'));
        if (reason) void act('reject', { reason }, 'rejected');
      }} disabled={busy}><XCircle size={16} className="mr-1" />{t('returns.reject')}</Button>,
    );
  } else if (status === 'approved' || status === 'awaiting_shipment') {
    actions.push(<Button key="s" onClick={() => act('ship', {}, 'shipped')} disabled={busy}><Truck size={16} className="mr-1" />{t('returns.markShipped')}</Button>);
  } else if (status === 'in_transit') {
    actions.push(<Button key="rc" onClick={() => act('receive', {}, 'received')} disabled={busy}><PackageCheck size={16} className="mr-1" />{t('returns.receive')}</Button>);
  } else if (status === 'received' || status === 'inspecting') {
    actions.push(<Button key="i" onClick={() => setInspecting(true)} disabled={busy}><ClipboardCheck size={16} className="mr-1" />{t('returns.inspect')}</Button>);
  } else if (status === 'inspected' || status === 'refund_pending') {
    const failed = rma.refund?.status === 'failed';
    actions.push(
      <Button key="rf" onClick={() => act('refund', {}, rma.can_push_refund ? 'refundQueued' : 'refunded')} disabled={busy}>
        <Banknote size={16} className="mr-1" />
        {rma.can_push_refund
          ? (failed ? t('returns.retryRefund') : t('returns.refundOnPlatform', { platform: platformLabel(rma.platform) }))
          : t('returns.issueRefund')}
      </Button>,
    );
  }

  return (
    <div className="space-y-6">
      <button onClick={() => router.push('/returns')} className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft size={16} /> {t('returns.back')}
      </button>

      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-3">
            {t('returns.detailTitle')} <span className="font-mono text-lg">{rma.rma_number}</span>
            <span className={cn('px-3 py-1 rounded-full text-xs font-bold uppercase', statusColor(status))}>
              {t(`returns.statuses.${status}`)}
            </span>
          </h1>
          <p className="text-sm text-muted-foreground mt-1">{rma.customer_name} · {rma.customer_email}</p>
        </div>
        <div className="flex items-center gap-2">{actions}</div>
      </div>

      {rma.refund && rma.can_push_refund && (
        <div className={cn('rounded-lg border px-4 py-3 text-sm flex items-center gap-2',
          rma.refund.status === 'succeeded' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
            : rma.refund.status === 'failed' ? 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-400'
            : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-500')}>
          <Banknote size={16} className="shrink-0" />
          {rma.refund.status === 'succeeded'
            ? t('returns.refundPushed', { platform: platformLabel(rma.platform) }) + (rma.refund.external_id ? ` · #${rma.refund.external_id}` : '')
            : rma.refund.status === 'failed'
              ? t('returns.refundPushFailed', { platform: platformLabel(rma.platform) })
              : t('returns.refundPushPending', { platform: platformLabel(rma.platform) })}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2 p-0 overflow-hidden">
          <div className="p-4 border-b border-border font-bold text-sm">{t('returns.lines')}</div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="bg-accent/40 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-4 py-2">SKU</th>
                  <th className="px-4 py-2 text-center">{t('returns.qtyRequested')}</th>
                  <th className="px-4 py-2 text-center">{t('returns.qtyApproved')}</th>
                  <th className="px-4 py-2 text-center">{t('returns.qtyReceived')}</th>
                  <th className="px-4 py-2">{t('returns.disposition')}</th>
                  <th className="px-4 py-2 text-right">{t('returns.colRefund')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rma.items.map((it: any) => (
                  <tr key={it.id}>
                    <td className="px-4 py-2"><div className="font-medium">{it.name}</div><div className="text-[11px] text-muted-foreground">{it.sku}</div></td>
                    <td className="px-4 py-2 text-center tabular-nums">{it.quantity_requested}</td>
                    <td className="px-4 py-2 text-center tabular-nums">{it.quantity_approved}</td>
                    <td className="px-4 py-2 text-center tabular-nums">{it.quantity_received}</td>
                    <td className="px-4 py-2">
                      <span className="text-xs">{t(`returns.dispositions.${it.disposition}`)}</span>
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums"><Money amount={it.refund_amount} currency={rma.currency} /></td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t border-border font-bold">
                  <td className="px-4 py-2" colSpan={5}>{t('returns.colRefund')}</td>
                  <td className="px-4 py-2 text-right"><Money amount={rma.total_refund} currency={rma.currency} /></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </Card>

        <Card className="p-4">
          <h3 className="font-bold text-sm mb-4">{t('returns.timeline')}</h3>
          <ul className="space-y-3">
            {(rma.events ?? []).map((e: any) => (
              <li key={e.id} className="flex items-start gap-3 text-sm">
                <span className={cn('mt-1 w-2 h-2 rounded-full shrink-0', statusColor(e.to_status).replace('/10', '').replace('/15', ''))} />
                <div>
                  <p className="font-medium">{t(`returns.statuses.${e.to_status}`)}</p>
                  {e.note && <p className="text-xs text-muted-foreground">{e.note}</p>}
                  <p className="text-[10px] text-muted-foreground/70">{new Date(e.created_at).toLocaleString()}</p>
                </div>
              </li>
            ))}
          </ul>
        </Card>
      </div>

      {inspecting && (
        <InspectModal
          rma={rma}
          onClose={() => setInspecting(false)}
          onDone={async () => { setInspecting(false); toast(t('returns.inspected'), 'success'); await load(); }}
        />
      )}
    </div>
  );
}
