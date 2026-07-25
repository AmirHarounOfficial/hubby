'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Input from '@/components/ui/Input';
import { FileText, Search, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Money } from '@/components/ui/Money';
import api from '@/lib/api';
import { useT } from '@/i18n';

const FILTERS = ['', 'draft', 'issued', 'void'];

const statusColor = (s: string) =>
  s === 'issued' || s === 'cleared' || s === 'reported' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
    : s === 'draft' ? 'bg-slate-500/10 text-slate-600 dark:text-slate-400'
    : s === 'void' || s === 'rejected' || s === 'failed' ? 'bg-red-500/10 text-red-600 dark:text-red-400'
    : 'bg-amber-500/10 text-amber-600 dark:text-amber-500';

export default function InvoicesPage() {
  const t = useT();
  const router = useRouter();
  const [rows, setRows] = useState<any[]>([]);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/invoices', { params: { status: status || undefined, search: search || undefined } });
      setRows(res.data.data ?? []);
    } catch (err) {
      console.error('Failed to load invoices', err);
    } finally {
      setLoading(false);
    }
  }, [status, search]);

  useEffect(() => {
    const timer = setTimeout(() => void load(), 300);
    return () => clearTimeout(timer);
  }, [load]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-3"><FileText className="text-primary" />{t('invoices.title')}</h1>
        <p className="text-muted-foreground text-sm">{t('invoices.subtitle')}</p>
      </div>

      <Card className="p-0 overflow-hidden">
        <div className="p-4 border-b border-border space-y-3">
          <div className="relative w-full md:w-96">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
            <Input placeholder={t('invoices.searchPlaceholder')} className="pl-10" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <div className="flex flex-wrap gap-2">
            {FILTERS.map((f) => (
              <button
                key={f || 'all'}
                onClick={() => setStatus(f)}
                className={cn('px-3 py-1.5 text-xs rounded-lg border border-border transition-all',
                  f === status ? 'bg-primary text-white border-primary' : 'bg-background hover:bg-accent')}
              >
                {f === '' ? t('invoices.all') : t(`invoices.statuses.${f}`)}
              </button>
            ))}
          </div>
        </div>

        <div className="overflow-x-auto min-h-[300px]">
          {loading ? (
            <div className="flex items-center justify-center py-20"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" /></div>
          ) : rows.length === 0 ? (
            <div className="p-12 text-center space-y-2">
              <p className="font-medium">{t('invoices.empty')}</p>
              <p className="text-sm text-muted-foreground">{t('invoices.emptyHint')}</p>
            </div>
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-5 py-3">{t('invoices.colNumber')}</th>
                  <th className="px-5 py-3">{t('invoices.colType')}</th>
                  <th className="px-5 py-3">{t('invoices.colBuyer')}</th>
                  <th className="px-5 py-3 text-right">{t('invoices.colTotal')}</th>
                  <th className="px-5 py-3">{t('invoices.colStatus')}</th>
                  <th className="px-5 py-3">{t('invoices.colDate')}</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-accent/30 cursor-pointer group" onClick={() => router.push(`/invoices/${r.id}`)}>
                    <td className="px-5 py-3 font-mono text-xs">{r.invoice_number}</td>
                    <td className="px-5 py-3 text-xs">{t(`invoices.types.${r.document_type}`)}</td>
                    <td className="px-5 py-3">{r.buyer_name || '—'}</td>
                    <td className="px-5 py-3 text-right tabular-nums"><Money amount={r.tax_inclusive_amount} currency={r.currency_code} /></td>
                    <td className="px-5 py-3">
                      <span className={cn('px-2 py-0.5 rounded text-[10px] font-bold uppercase', statusColor(r.status))}>
                        {t(`invoices.statuses.${r.status}`)}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-xs text-muted-foreground">{r.issue_date?.slice(0, 10)}</td>
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
