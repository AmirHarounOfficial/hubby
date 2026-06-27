'use client';

import React, { useEffect, useMemo, useState } from 'react';
import Card from '@/components/ui/Card';
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  AreaChart,
  Area,
  PieChart,
  Pie,
  Cell,
} from 'recharts';
import { TrendingUp, ShoppingBag, DollarSign, Package, Download } from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';

const PLATFORM_COLORS: Record<string, string> = {
  shopify: '#10B981',
  salla: '#4F46E5',
  woocommerce: '#9333EA',
  zid: '#F97316',
};

type Summary = {
  total_revenue: number;
  total_orders: number;
  avg_order_value: number;
  active_products: number;
  revenue_change: number | null;
  orders_change: number | null;
};

export default function AnalyticsPage() {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [timeline, setTimeline] = useState<any[]>([]);
  const [platforms, setPlatforms] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        const [s, t, p] = await Promise.all([
          api.get('/analytics/dashboard'),
          api.get('/analytics/orders-timeline'),
          api.get('/analytics/by-platform'),
        ]);
        setSummary(s.data);
        setTimeline(
          t.data.map((d: any) => ({
            date: new Date(d.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
            revenue: Number(d.revenue),
            orders: Number(d.orders),
          }))
        );
        setPlatforms(p.data);
      } catch (err) {
        console.error('Failed to load analytics', err);
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  const platformShare = useMemo(() => {
    const total = platforms.reduce((acc, p) => acc + Number(p.count), 0) || 1;
    return platforms.map((p) => ({
      name: (p.platform || 'other').charAt(0).toUpperCase() + (p.platform || 'other').slice(1),
      value: Math.round((Number(p.count) / total) * 100),
      color: PLATFORM_COLORS[p.platform] || '#64748B',
    }));
  }, [platforms]);

  const downloadReport = () => {
    const header = 'Date,Orders,Revenue\n';
    const rows = timeline.map((d) => `${d.date},${d.orders},${d.revenue}`).join('\n');
    const blob = new Blob([header + rows], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `analytics-report-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  if (isLoading) {
    return <div className="flex items-center justify-center h-full">Loading...</div>;
  }

  const kpis = [
    {
      label: 'Total Revenue',
      value: formatCurrency(summary?.total_revenue ?? 0),
      trend: summary?.revenue_change,
      icon: DollarSign,
      color: 'text-secondary',
    },
    {
      label: 'Total Orders',
      value: (summary?.total_orders ?? 0).toLocaleString(),
      trend: summary?.orders_change,
      icon: ShoppingBag,
      color: 'text-primary',
    },
    {
      label: 'Avg Order Value',
      value: formatCurrency(summary?.avg_order_value ?? 0),
      trend: null,
      icon: TrendingUp,
      color: 'text-purple-500',
    },
    {
      label: 'Active Products',
      value: (summary?.active_products ?? 0).toLocaleString(),
      trend: null,
      icon: Package,
      color: 'text-orange-500',
    },
  ];

  return (
    <div className="space-y-8">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Analytics</h1>
          <p className="text-muted-foreground text-sm">Deep dive into your store performance over the last 30 days.</p>
        </div>
        <button
          onClick={downloadReport}
          className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium shadow-lg shadow-primary/20 hover:bg-primary/90 transition-colors"
        >
          <Download size={16} />
          Download Report
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {kpis.map((stat) => (
          <Card key={stat.label} className="p-5 flex items-center gap-4">
            <div className={cn('p-3 rounded-2xl bg-background border border-border', stat.color)}>
              <stat.icon size={24} />
            </div>
            <div>
              <p className="text-[10px] uppercase font-bold text-muted-foreground tracking-widest">{stat.label}</p>
              <div className="flex items-center gap-2">
                <h3 className="text-xl font-bold">{stat.value}</h3>
                {typeof stat.trend === 'number' && (
                  <span className={cn('text-[10px] font-bold', stat.trend >= 0 ? 'text-secondary' : 'text-destructive')}>
                    {stat.trend >= 0 ? '+' : ''}
                    {stat.trend}%
                  </span>
                )}
              </div>
            </div>
          </Card>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <Card className="p-6 flex flex-col gap-6">
          <div className="flex items-center justify-between">
            <h3 className="font-bold text-lg">Revenue Overview</h3>
            <div className="flex items-center gap-2 text-xs">
              <div className="w-3 h-3 rounded-full bg-primary"></div>
              <span>Last 30 days</span>
            </div>
          </div>
          <div className="h-[300px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={timeline}>
                <defs>
                  <linearGradient id="colorRevenue" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#4F46E5" stopOpacity={0.3} />
                    <stop offset="95%" stopColor="#4F46E5" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
                <XAxis dataKey="date" stroke="#94a3b8" fontSize={11} tickLine={false} axisLine={false} minTickGap={24} />
                <YAxis stroke="#94a3b8" fontSize={11} tickLine={false} axisLine={false} width={48} />
                <Tooltip contentStyle={{ borderRadius: 12, border: '1px solid #e2e8f0' }} />
                <Area type="monotone" dataKey="revenue" stroke="#4F46E5" strokeWidth={3} fillOpacity={1} fill="url(#colorRevenue)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card className="p-6 flex flex-col gap-6">
          <div className="flex items-center justify-between">
            <h3 className="font-bold text-lg">Platform Share</h3>
            <span className="text-xs text-muted-foreground">Order volume by platform</span>
          </div>
          {platformShare.length === 0 ? (
            <div className="flex-1 flex items-center justify-center text-sm text-muted-foreground">No order data yet.</div>
          ) : (
            <div className="flex-1 flex flex-col md:flex-row items-center justify-center gap-8">
              <div className="h-[250px] w-[250px]">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={platformShare} cx="50%" cy="50%" innerRadius={60} outerRadius={80} paddingAngle={8} dataKey="value">
                      {platformShare.map((entry, index) => (
                        <Cell key={index} fill={entry.color} stroke="none" />
                      ))}
                    </Pie>
                    <Tooltip />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <div className="space-y-3 w-full max-w-[200px]">
                {platformShare.map((p) => (
                  <div key={p.name} className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <div className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: p.color }}></div>
                      <span className="text-xs font-medium">{p.name}</span>
                    </div>
                    <span className="text-xs font-bold">{p.value}%</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </Card>
      </div>

      <Card className="p-6 flex flex-col gap-6">
        <h3 className="font-bold text-lg">Daily Orders</h3>
        <div className="h-[300px] w-full">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={timeline}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
              <XAxis dataKey="date" stroke="#94a3b8" fontSize={11} tickLine={false} axisLine={false} minTickGap={24} />
              <YAxis stroke="#94a3b8" fontSize={11} tickLine={false} axisLine={false} width={36} allowDecimals={false} />
              <Tooltip cursor={{ fill: 'rgba(0,0,0,0.04)' }} contentStyle={{ borderRadius: 12, border: '1px solid #e2e8f0' }} />
              <Bar dataKey="orders" fill="#10B981" radius={[6, 6, 0, 0]} maxBarSize={40} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Card>
    </div>
  );
}
