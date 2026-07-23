'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Input from '@/components/ui/Input';
import Link from 'next/link';
import { Truck, Search, ChevronRight, Settings2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useT } from '@/i18n';
import { shipmentStatusColor } from '@/components/shipping/statusColor';

type Shipment = {
  id: number;
  reference: string;
  status: string;
  carrier_code: string | null;
  tracking_number: string | null;
  items_count: number;
  created_at: string;
};

const FILTERS = ['', 'draft', 'label_purchased', 'in_transit', 'out_for_delivery', 'delivered', 'returned_to_origin', 'cancelled'];

export default function ShipmentsPage() {
  const t = useT();
  const router = useRouter();
  const [rows, setRows] = useState<Shipment[]>([]);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/shipments', { params: { status: status || undefined, search: search || undefined } });
      setRows(res.data.data ?? []);
    } catch (err) {
      console.error('Failed to load shipments', err);
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
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-3">
            <Truck className="text-primary" />
            {t('shipping.title')}
          </h1>
          <p className="text-muted-foreground text-sm">{t('shipping.subtitle')}</p>
        </div>
        <Link
          href="/shipments/carriers"
          className="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-border hover:bg-accent transition-colors"
        >
          <Settings2 size={16} /> {t('shipping.accountsTitle')}
        </Link>
      </div>

      <Card className="p-0 overflow-hidden">
        <div className="p-4 border-b border-border space-y-3">
          <div className="relative w-full md:w-96">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
            <Input placeholder={t('shipping.searchPlaceholder')} className="pl-10" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <div className="flex flex-wrap gap-2">
            {FILTERS.map((f) => (
              <button
                key={f || 'all'}
                onClick={() => setStatus(f)}
                className={cn('px-3 py-1.5 text-xs rounded-lg border border-border transition-all',
                  f === status ? 'bg-primary text-white border-primary' : 'bg-background hover:bg-accent')}
              >
                {f === '' ? t('shipping.all') : t(`shipping.statuses.${f}`)}
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
            <div className="p-12 text-center space-y-3">
              <p className="font-medium">{t('shipping.empty')}</p>
              <p className="text-sm text-muted-foreground">{t('shipping.emptyHint')}</p>
              <Link
                href="/shipments/carriers"
                className="inline-flex items-center gap-2 px-4 py-2 text-sm rounded-lg bg-primary text-white hover:opacity-90 transition-opacity"
              >
                <Settings2 size={16} /> {t('shipping.setupCarriers')}
              </Link>
            </div>
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-5 py-3">{t('shipping.colRef')}</th>
                  <th className="px-5 py-3">{t('shipping.colCarrier')}</th>
                  <th className="px-5 py-3">{t('shipping.colTracking')}</th>
                  <th className="px-5 py-3 text-center">{t('shipping.colItems')}</th>
                  <th className="px-5 py-3">{t('shipping.colStatus')}</th>
                  <th className="px-5 py-3">{t('shipping.colCreated')}</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-accent/30 transition-colors cursor-pointer group" onClick={() => router.push(`/shipments/${r.id}`)}>
                    <td className="px-5 py-3 font-medium font-mono text-xs">{r.reference}</td>
                    <td className="px-5 py-3 capitalize">{r.carrier_code || '—'}</td>
                    <td className="px-5 py-3 font-mono text-xs">{r.tracking_number || '—'}</td>
                    <td className="px-5 py-3 text-center tabular-nums">{r.items_count}</td>
                    <td className="px-5 py-3">
                      <span className={cn('px-2 py-0.5 rounded text-[10px] font-bold uppercase', shipmentStatusColor(r.status))}>
                        {t(`shipping.statuses.${r.status}`)}
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
