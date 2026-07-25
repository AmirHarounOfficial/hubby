'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { Banknote, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Money } from '@/components/ui/Money';
import api from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { useT } from '@/i18n';

type Summary = {
  currency: string;
  in_transit: number;
  awaiting_remittance: number;
  overdue: number;
  remitted_30d: number;
  rto_amount: number;
  rto_count: number;
  aging: Record<string, number>;
};

export default function CodPage() {
  const t = useT();
  const { toast } = useToast();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [rows, setRows] = useState<any[]>([]);
  const [filter, setFilter] = useState<'all' | 'overdue'>('all');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [s, list] = await Promise.all([
        api.get('/cod/summary'),
        api.get('/cod/transactions', { params: { overdue: filter === 'overdue' ? 1 : undefined } }),
      ]);
      setSummary(s.data);
      setRows(list.data.data ?? []);
    } catch (err) {
      console.error('Failed to load COD', err);
    } finally {
      setLoading(false);
    }
  }, [filter]);

  useEffect(() => { void load(); }, [load]);

  const markRemitted = async (id: number) => {
    setBusy(id);
    try {
      await api.post(`/cod/transactions/${id}/remitted`, {});
      toast(t('cod.remittedToast'), 'success');
      await load();
    } catch (e: any) {
      toast(e?.response?.data?.message || t('cod.actionError'), 'error');
    } finally {
      setBusy(null);
    }
  };

  const tiles = summary ? [
    { key: 'inTransit', value: summary.in_transit, hint: 'inTransitHint', tone: 'text-blue-600 dark:text-blue-400' },
    { key: 'awaitingRemittance', value: summary.awaiting_remittance, hint: 'awaitingHint', tone: 'text-amber-600 dark:text-amber-500' },
    { key: 'overdue', value: summary.overdue, hint: 'overdueHint', tone: 'text-red-600 dark:text-red-400' },
    { key: 'remitted30d', value: summary.remitted_30d, hint: 'remittedHint', tone: 'text-emerald-600 dark:text-emerald-400' },
    { key: 'rtoAmount', value: summary.rto_amount, hint: 'rtoHint', tone: 'text-red-600 dark:text-red-400' },
  ] : [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-3"><Banknote className="text-primary" />{t('cod.title')}</h1>
        <p className="text-muted-foreground text-sm">{t('cod.subtitle')}</p>
      </div>

      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          {tiles.map((tile) => (
            <Card key={tile.key} className="p-4">
              <p className="text-[11px] uppercase tracking-wider text-muted-foreground font-bold">{t(`cod.${tile.key}`)}</p>
              <p className={cn('mt-1 text-xl font-bold tabular-nums', tile.tone)}>
                <Money amount={tile.value} currency={summary.currency} />
              </p>
              <p className="text-[10px] text-muted-foreground/70 mt-1">{t(`cod.${tile.hint}`)}</p>
            </Card>
          ))}
        </div>
      )}

      <Card className="p-0 overflow-hidden">
        <div className="p-4 border-b border-border flex items-center justify-between">
          <h3 className="font-bold text-sm">{t('cod.outstanding')}</h3>
          <div className="flex gap-2">
            {(['all', 'overdue'] as const).map((f) => (
              <button
                key={f}
                onClick={() => setFilter(f)}
                className={cn('px-3 py-1.5 text-xs rounded-lg border border-border transition-all',
                  f === filter ? 'bg-primary text-white border-primary' : 'bg-background hover:bg-accent')}
              >
                {t(f === 'all' ? 'cod.filterAll' : 'cod.filterOverdue')}
              </button>
            ))}
          </div>
        </div>

        <div className="overflow-x-auto min-h-[240px]">
          {loading ? (
            <div className="flex items-center justify-center py-20"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" /></div>
          ) : rows.length === 0 ? (
            <div className="p-12 text-center space-y-1">
              <p className="font-medium">{t('cod.empty')}</p>
              <p className="text-sm text-muted-foreground">{t('cod.emptyHint')}</p>
            </div>
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-5 py-3">{t('cod.colOrder')}</th>
                  <th className="px-5 py-3">{t('cod.colCarrier')}</th>
                  <th className="px-5 py-3">{t('cod.colAwb')}</th>
                  <th className="px-5 py-3 text-right">{t('cod.colExpected')}</th>
                  <th className="px-5 py-3">{t('cod.colStatus')}</th>
                  <th className="px-5 py-3 text-center">{t('cod.colAge')}</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-accent/30">
                    <td className="px-5 py-3 font-mono text-xs">{r.order?.external_id || r.order_id}</td>
                    <td className="px-5 py-3 capitalize">{r.carrier_code || '—'}</td>
                    <td className="px-5 py-3 font-mono text-xs">{r.awb_number || '—'}</td>
                    <td className="px-5 py-3 text-right tabular-nums"><Money amount={r.expected_amount} currency={r.currency} /></td>
                    <td className="px-5 py-3">
                      <span className="text-[10px] font-bold uppercase">{t(`cod.statuses.${r.status}`)}</span>
                      {r.is_overdue && (
                        <span className="ml-2 inline-flex items-center gap-1 text-[10px] font-bold text-red-600">
                          <AlertTriangle size={11} /> {t('cod.overdueBadge')}
                        </span>
                      )}
                    </td>
                    <td className="px-5 py-3 text-center text-xs text-muted-foreground">{r.aging_bucket || '—'}</td>
                    <td className="px-5 py-3 text-right">
                      {r.status === 'collected' && (
                        <Button variant="outline" onClick={() => markRemitted(r.id)} disabled={busy === r.id}>
                          {t('cod.markRemitted')}
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </Card>
    </div>
  );
}
