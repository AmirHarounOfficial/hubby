'use client';

import React, { useEffect, useState } from 'react';
import { Coins } from 'lucide-react';
import Card from '@/components/ui/Card';
import { Money } from '@/components/ui/Money';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useT } from '@/i18n';

type Line = {
  order_item_id: number | null;
  sku: string | null;
  quantity: number;
  net_revenue: string;
  cogs: string;
  direct_fees: string;
  allocated_fees: string;
  net_profit: string;
  margin_pct: number | null;
  is_estimated: boolean;
};

type ProfitPayload = {
  order: {
    net_revenue_base: string;
    cogs_base: string;
    total_fees_base: string;
    net_profit_base: string;
    margin_pct: number | null;
    is_estimated: boolean;
  };
  lines: Line[];
};

const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);

/**
 * Per-order P&L, shown on the order detail page. Reads /orders/{id}/profit, which is gated by
 * `cost.access` — so a 403 (viewer-level teammate) renders nothing at all, and a 404 (not yet
 * calculated) shows a quiet note rather than an error.
 */
export function OrderProfitCard({ orderId }: { orderId: string | number }) {
  const t = useT();
  const [data, setData] = useState<ProfitPayload | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'not_calculated' | 'hidden'>('loading');

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const res = await api.get(`/orders/${orderId}/profit`);
        if (!active) return;
        setData(res.data);
        setState('ready');
      } catch (err: any) {
        if (!active) return;
        const status = err?.response?.status;
        if (status === 403) setState('hidden'); // no cost access — don't reveal the section
        else if (status === 404) setState('not_calculated');
        else setState('hidden');
      }
    })();
    return () => {
      active = false;
    };
  }, [orderId]);

  if (state === 'loading' || state === 'hidden') return null;

  const header = (
    <div className="p-4 border-b border-border bg-card/30 flex items-center gap-2">
      <Coins size={18} className="text-primary" />
      <h3 className="font-bold text-sm">{t('orders.profit.title')}</h3>
      {data?.order.is_estimated && (
        <span className="ms-auto px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide bg-orange-100 text-orange-700">
          {t('orders.profit.estimated')}
        </span>
      )}
    </div>
  );

  if (state === 'not_calculated' || !data) {
    return (
      <Card className="p-0 overflow-hidden">
        {header}
        <p className="p-6 text-sm text-muted-foreground">{t('orders.profit.notCalculated')}</p>
      </Card>
    );
  }

  const o = data.order;
  const netProfit = Number(o.net_profit_base);

  const kpis = [
    { label: t('orders.profit.netRevenue'), value: <Money amount={o.net_revenue_base} />, tone: 'text-foreground' },
    { label: t('orders.profit.cogs'), value: <Money amount={o.cogs_base} />, tone: 'text-muted-foreground' },
    { label: t('orders.profit.fees'), value: <Money amount={o.total_fees_base} />, tone: 'text-muted-foreground' },
    {
      label: t('orders.profit.netProfit'),
      value: <Money amount={o.net_profit_base} />,
      tone: netProfit >= 0 ? 'text-secondary' : 'text-destructive',
    },
  ];

  return (
    <Card className="p-0 overflow-hidden">
      {header}

      <div className="grid grid-cols-2 md:grid-cols-4 divide-x divide-border border-b border-border">
        {kpis.map((k) => (
          <div key={k.label} className="p-4">
            <p className="text-[10px] uppercase font-bold text-muted-foreground tracking-widest">{k.label}</p>
            <div className={cn('mt-1 text-base font-bold', k.tone)}>{k.value}</div>
          </div>
        ))}
      </div>

      <div className="px-4 py-2 flex items-center justify-end gap-2 text-xs border-b border-border bg-accent/20">
        <span className="text-muted-foreground">{t('orders.profit.margin')}</span>
        <span className={cn('font-bold tabular-nums', netProfit >= 0 ? 'text-secondary' : 'text-destructive')}>
          {pct(o.margin_pct)}
        </span>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="bg-accent/40 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
              <th className="px-4 py-2">{t('orders.profit.colSku')}</th>
              <th className="px-4 py-2 text-center">{t('orders.profit.colQty')}</th>
              <th className="px-4 py-2">{t('orders.profit.colRevenue')}</th>
              <th className="px-4 py-2">{t('orders.profit.colCogs')}</th>
              <th className="px-4 py-2">{t('orders.profit.colFees')}</th>
              <th className="px-4 py-2 text-right">{t('orders.profit.colProfit')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {data.lines.map((line, i) => {
              const lineProfit = Number(line.net_profit);
              const fees = Number(line.direct_fees) + Number(line.allocated_fees);
              return (
                <tr key={line.order_item_id ?? i}>
                  <td className="px-4 py-2">
                    <div className="flex items-center gap-2">
                      <span className="font-medium">{line.sku ?? '—'}</span>
                      {line.is_estimated && (
                        <span className="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide bg-orange-100 text-orange-700">
                          {t('orders.profit.lineEstimated')}
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-2 text-center tabular-nums">{line.quantity}</td>
                  <td className="px-4 py-2 tabular-nums"><Money amount={line.net_revenue} /></td>
                  <td className="px-4 py-2 tabular-nums text-muted-foreground"><Money amount={line.cogs} /></td>
                  <td className="px-4 py-2 tabular-nums text-muted-foreground"><Money amount={fees} /></td>
                  <td className={cn('px-4 py-2 text-right font-bold tabular-nums', lineProfit >= 0 ? 'text-secondary' : 'text-destructive')}>
                    <Money amount={line.net_profit} />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Card>
  );
}
