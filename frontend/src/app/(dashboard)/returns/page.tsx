'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Input from '@/components/ui/Input';
import { Undo2, Search, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Money } from '@/components/ui/Money';
import api from '@/lib/api';
import { useT } from '@/i18n';
import { statusColor } from '@/components/returns/statusColor';

type Rma = {
  id: number;
  rma_number: string;
  status: string;
  type: string;
  customer_name: string | null;
  items_count: number;
  total_refund: string;
  currency: string;
  order_id: number;
  created_at: string;
};

const FILTERS = ['', 'requested', 'approved', 'in_transit', 'received', 'inspected', 'closed'];

type Analytics = {
  total_returns: number;
  return_rate: number | null;
  rto_rate: number | null;
  restock_ratio: number | null;
  refund_value: string;
};

const pct = (v: number | null) => (v === null ? null : `${(v * 100).toFixed(1)}%`);

export default function ReturnsPage() {
  const t = useT();
  const router = useRouter();
  const [rows, setRows] = useState<Rma[]>([]);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState<Analytics | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/returns', { params: { status: status || undefined, search: search || undefined } });
      setRows(res.data.data ?? []);
    } catch (err) {
      console.error('Failed to load returns', err);
    } finally {
      setLoading(false);
    }
  }, [status, search]);

  useEffect(() => {
    const timer = setTimeout(() => void load(), 300);
    return () => clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    api.get('/returns/analytics')
      .then((res) => setStats(res.data))
      .catch((err) => console.error('Failed to load returns analytics', err));
  }, []);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-3">
          <Undo2 className="text-primary" />
          {t('returns.title')}
        </h1>
        <p className="text-muted-foreground text-sm">{t('returns.subtitle')}</p>
      </div>

      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          {[
            { label: t('returns.statTotal'), value: String(stats.total_returns) },
            { label: t('returns.statReturnRate'), value: pct(stats.return_rate) },
            { label: t('returns.statRtoRate'), value: pct(stats.rto_rate) },
            { label: t('returns.statRestock'), value: pct(stats.restock_ratio) },
            {
              label: t('returns.statRefundValue'),
              value: <Money amount={stats.refund_value} />,
            },
          ].map((s, i) => (
            <Card key={i} className="p-4">
              <p className="text-[11px] uppercase tracking-wider text-muted-foreground font-bold">{s.label}</p>
              <p className="mt-1 text-xl font-bold tabular-nums">{s.value ?? t('returns.statNone')}</p>
            </Card>
          ))}
        </div>
      )}

      <Card className="p-0 overflow-hidden">
        <div className="p-4 border-b border-border space-y-3">
          <div className="relative w-full md:w-96">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
            <Input placeholder={t('returns.searchPlaceholder')} className="pl-10" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <div className="flex flex-wrap gap-2">
            {FILTERS.map((f) => (
              <button
                key={f || 'all'}
                onClick={() => setStatus(f)}
                className={cn('px-3 py-1.5 text-xs rounded-lg border border-border transition-all',
                  f === status ? 'bg-primary text-white border-primary' : 'bg-background hover:bg-accent')}
              >
                {f === '' ? t('returns.all') : t(`returns.statuses.${f}`)}
              </button>
            ))}
          </div>
        </div>

        <div className="overflow-x-auto min-h-[300px]">
          {loading ? (
            <div className="flex items-center justify-center py-20">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
            </div>
          ) : rows.length === 0 ? (
            <div className="p-12 text-center space-y-2">
              <p className="font-medium">{t('returns.empty')}</p>
              <p className="text-sm text-muted-foreground">{t('returns.emptyHint')}</p>
            </div>
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-5 py-3">{t('returns.colRma')}</th>
                  <th className="px-5 py-3">{t('returns.colCustomer')}</th>
                  <th className="px-5 py-3 text-center">{t('returns.colItems')}</th>
                  <th className="px-5 py-3">{t('returns.colRefund')}</th>
                  <th className="px-5 py-3">{t('returns.colStatus')}</th>
                  <th className="px-5 py-3">{t('returns.colCreated')}</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-accent/30 transition-colors cursor-pointer group" onClick={() => router.push(`/returns/${r.id}`)}>
                    <td className="px-5 py-3 font-medium font-mono text-xs">{r.rma_number}</td>
                    <td className="px-5 py-3">{r.customer_name || '—'}</td>
                    <td className="px-5 py-3 text-center tabular-nums">{r.items_count}</td>
                    <td className="px-5 py-3 tabular-nums"><Money amount={r.total_refund} currency={r.currency} /></td>
                    <td className="px-5 py-3">
                      <span className={cn('px-2 py-0.5 rounded text-[10px] font-bold uppercase', statusColor(r.status))}>
                        {t(`returns.statuses.${r.status}`)}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-xs text-muted-foreground">{new Date(r.created_at).toLocaleDateString()}</td>
                    <td className="px-5 py-3 text-right">
                      <ChevronRight size={16} className="text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
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
