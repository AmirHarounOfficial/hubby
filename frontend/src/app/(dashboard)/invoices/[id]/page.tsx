'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { ChevronLeft, FileCheck2, Undo2, Trash2, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Money } from '@/components/ui/Money';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';

const statusColor = (s: string) =>
  s === 'issued' || s === 'cleared' || s === 'reported' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
    : s === 'draft' ? 'bg-slate-500/10 text-slate-600 dark:text-slate-400'
    : s === 'void' || s === 'rejected' || s === 'failed' ? 'bg-red-500/10 text-red-600 dark:text-red-400'
    : 'bg-amber-500/10 text-amber-600 dark:text-amber-500';

export default function InvoiceDetailPage() {
  const t = useT();
  const { id } = useParams();
  const router = useRouter();
  const { toast } = useToast();
  const [inv, setInv] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setInv((await api.get(`/invoices/${id}`)).data);
    } catch {
      router.push('/invoices');
    } finally {
      setLoading(false);
    }
  }, [id, router]);

  useEffect(() => { void load(); }, [load]);

  const issue = async () => {
    if (!window.confirm(t('invoices.issueWarning'))) return;
    setBusy(true);
    try {
      await api.post(`/invoices/${id}/issue`, {});
      toast(t('invoices.issued'), 'success');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('invoices.actionError'), 'error');
    } finally { setBusy(false); }
  };

  const creditNote = async () => {
    const reason = window.prompt(t('invoices.creditReason'));
    if (!reason) return;
    setBusy(true);
    try {
      const res = await api.post(`/invoices/${id}/credit-note`, { reason });
      toast(t('invoices.creditCreated'), 'success');
      router.push(`/invoices/${res.data.id}`);
    } catch (e: any) {
      toast(e?.response?.data?.message || t('invoices.actionError'), 'error');
    } finally { setBusy(false); }
  };

  const discard = async () => {
    setBusy(true);
    try {
      await api.delete(`/invoices/${id}`);
      toast(t('invoices.discarded'), 'success');
      router.push('/invoices');
    } catch (e: any) {
      toast(e?.response?.data?.message || t('invoices.actionError'), 'error');
    } finally { setBusy(false); }
  };

  if (loading || !inv) {
    return <div className="flex items-center justify-center h-[60vh]"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" /></div>;
  }

  const isDraft = inv.status === 'draft';
  const reg = inv.tax_registration;

  return (
    <div className="space-y-6">
      <button onClick={() => router.push('/invoices')} className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft size={16} /> {t('invoices.back')}
      </button>

      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-3">
            {t(`invoices.types.${inv.document_type}`)} <span className="font-mono text-lg">{inv.invoice_number}</span>
            <span className={cn('px-3 py-1 rounded-full text-xs font-bold uppercase', statusColor(inv.status))}>
              {t(`invoices.statuses.${inv.status}`)}
            </span>
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            {t(`invoices.subtypes.${inv.subtype}`)} · {inv.issue_date?.slice(0, 10)}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {isDraft && <Button onClick={issue} disabled={busy}><FileCheck2 size={16} className="mr-1" />{t('invoices.issue')}</Button>}
          {isDraft && <Button variant="outline" onClick={discard} disabled={busy}><Trash2 size={16} className="mr-1" />{t('invoices.discard')}</Button>}
          {inv.status === 'issued' && inv.document_type === 'invoice' && (
            <Button variant="outline" onClick={creditNote} disabled={busy}><Undo2 size={16} className="mr-1" />{t('invoices.creditNote')}</Button>
          )}
        </div>
      </div>

      {/* Honest about fiscal status — never imply ZATCA clearance we haven't done. */}
      {!inv.is_fiscal && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 flex items-start gap-3">
          <ShieldAlert size={18} className="text-amber-600 dark:text-amber-500 shrink-0 mt-0.5" />
          <div className="text-sm">
            <p className="font-medium text-amber-700 dark:text-amber-500">{t('invoices.nonFiscalTitle')}</p>
            <p className="text-muted-foreground text-xs mt-0.5">{t('invoices.nonFiscalBody')}</p>
          </div>
        </div>
      )}

      {inv.parent && (
        <p className="text-sm text-muted-foreground">
          {t('invoices.parentInvoice')}{' '}
          <button onClick={() => router.push(`/invoices/${inv.parent.id}`)} className="text-primary hover:underline font-mono">
            {inv.parent.invoice_number}
          </button>
          {inv.note_reason && <> · {t('invoices.reason')}: {inv.note_reason}</>}
        </p>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2 p-0 overflow-hidden">
          <div className="p-4 border-b border-border font-bold text-sm">{t('invoices.lines')}</div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="bg-accent/40 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-4 py-2">{t('invoices.colItem')}</th>
                  <th className="px-4 py-2 text-center">{t('invoices.colQty')}</th>
                  <th className="px-4 py-2 text-right">{t('invoices.colUnitPrice')}</th>
                  <th className="px-4 py-2 text-right">{t('invoices.colLineNet')}</th>
                  <th className="px-4 py-2 text-right">{t('invoices.colLineVat')}</th>
                  <th className="px-4 py-2 text-right">{t('invoices.colLineTotal')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {(inv.lines ?? []).map((l: any) => (
                  <tr key={l.id}>
                    <td className="px-4 py-2"><div className="font-medium">{l.name}</div><div className="text-[11px] text-muted-foreground">{l.sku}</div></td>
                    <td className="px-4 py-2 text-center tabular-nums">{Number(l.quantity)}</td>
                    <td className="px-4 py-2 text-right tabular-nums"><Money amount={l.unit_price} currency={inv.currency_code} /></td>
                    <td className="px-4 py-2 text-right tabular-nums"><Money amount={l.line_extension_amount} currency={inv.currency_code} /></td>
                    <td className="px-4 py-2 text-right tabular-nums text-muted-foreground">{Number(l.tax_percent)}% · <Money amount={l.tax_amount} currency={inv.currency_code} /></td>
                    <td className="px-4 py-2 text-right tabular-nums font-medium"><Money amount={l.line_amount_with_tax} currency={inv.currency_code} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        <div className="space-y-6">
          <Card className="p-4 space-y-3">
            <h3 className="font-bold text-sm">{t('invoices.totals')}</h3>
            <div className="flex justify-between text-sm"><span className="text-muted-foreground">{t('invoices.netTotal')}</span><span className="tabular-nums"><Money amount={inv.tax_exclusive_amount} currency={inv.currency_code} /></span></div>
            <div className="flex justify-between text-sm"><span className="text-muted-foreground">{t('invoices.vatTotal')}</span><span className="tabular-nums"><Money amount={inv.tax_amount} currency={inv.currency_code} /></span></div>
            <div className="flex justify-between text-base font-bold border-t border-border pt-3"><span>{t('invoices.grandTotal')}</span><span className="tabular-nums"><Money amount={inv.payable_amount} currency={inv.currency_code} /></span></div>
          </Card>

          {reg && (
            <Card className="p-4 space-y-1 text-sm">
              <h3 className="font-bold text-sm mb-2">{t('invoices.seller')}</h3>
              <p className="font-medium">{reg.legal_name}</p>
              {reg.vat_number && <p className="text-muted-foreground text-xs">{t('invoices.vatNumber')}: <span className="font-mono">{reg.vat_number}</span></p>}
              {reg.city && <p className="text-muted-foreground text-xs">{[reg.street, reg.city].filter(Boolean).join(', ')}</p>}
            </Card>
          )}

          <Card className="p-4 space-y-1 text-sm">
            <h3 className="font-bold text-sm mb-2">{t('invoices.buyer')}</h3>
            <p className="font-medium">{inv.buyer_name || '—'}</p>
            {inv.buyer_vat_number && <p className="text-muted-foreground text-xs">{t('invoices.vatNumber')}: <span className="font-mono">{inv.buyer_vat_number}</span></p>}
          </Card>

          {(inv.credit_notes ?? []).length > 0 && (
            <Card className="p-4">
              <h3 className="font-bold text-sm mb-2">{t('invoices.creditNotes')}</h3>
              <ul className="space-y-2">
                {inv.credit_notes.map((n: any) => (
                  <li key={n.id}>
                    <button onClick={() => router.push(`/invoices/${n.id}`)} className="text-primary hover:underline font-mono text-xs">{n.invoice_number}</button>
                    <span className="text-muted-foreground text-xs"> · <Money amount={n.tax_inclusive_amount} currency={inv.currency_code} /></span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
