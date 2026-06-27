'use client';

import React, { useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import { 
  TrendingUp, 
  ShoppingCart, 
  Package, 
  ArrowUpRight, 
  ArrowDownRight,
  RefreshCw,
  BarChart3,
  Users,
  Activity
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';
import { useRouter } from 'next/navigation';
import { 
  BarChart, 
  Bar, 
  XAxis, 
  YAxis, 
  CartesianGrid, 
  Tooltip, 
  ResponsiveContainer,
  AreaChart,
  Area
} from 'recharts';

export default function DashboardPage() {
  const router = useRouter();
  const [summary, setSummary] = useState<any>(null);
  const [timeline, setTimeline] = useState<any[]>([]);
  const [recentOrders, setRecentOrders] = useState<any[]>([]);
  const [topCustomers, setTopCustomers] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSyncing, setIsSyncing] = useState(false);
  const [revenueRange, setRevenueRange] = useState('30'); // Days

  const fetchDashboardData = async () => {
    setIsLoading(true);
    try {
      const [summaryRes, timelineRes, ordersRes, customersRes] = await Promise.all([
        api.get('/analytics/dashboard', { params: { days: revenueRange } }),
        api.get('/analytics/orders-timeline', { params: { days: revenueRange } }),
        api.get('/orders', { params: { per_page: 6 } }),
        api.get('/analytics/top-customers')
      ]);

      setSummary(summaryRes.data);
      setTimeline(timelineRes.data);
      setRecentOrders(ordersRes.data.data);
      setTopCustomers(customersRes.data);
    } catch (err) {
      console.error('Failed to fetch dashboard data', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchDashboardData();
  }, [revenueRange]);

  const handleSyncAll = async () => {
    setIsSyncing(true);
    try {
      await api.post('/stores/sync-all');
      alert('Syncing started for all stores! Data will update shortly.');
      // Refresh after a delay to show new data
      setTimeout(fetchDashboardData, 5000);
    } catch (err) {
      console.error('Sync failed', err);
    } finally {
      setIsSyncing(false);
    }
  };

  const stats = [
    { label: 'Total Revenue', value: formatCurrency(summary?.total_revenue || 0), change: summary?.revenue_change ?? null, icon: TrendingUp, color: 'text-primary' },
    { label: 'Orders', value: summary?.total_orders || 0, change: summary?.orders_change ?? null, icon: ShoppingCart, color: 'text-secondary' },
    { label: 'Avg Order Value', value: formatCurrency(summary?.avg_order_value || 0), change: null, icon: ArrowUpRight, color: 'text-warning' },
    { label: 'Active Products', value: summary?.active_products || 0, change: null, icon: Package, color: 'text-primary' },
  ];

  if (isLoading && !timeline.length) {
    return <div className="flex items-center justify-center h-full">Loading...</div>;
  }

  return (
    <div className="space-y-8">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Dashboard</h1>
          <p className="text-muted-foreground text-sm">Welcome back to HubbyGlobal dashboard.</p>
        </div>
        <button 
          onClick={handleSyncAll}
          disabled={isSyncing}
          className={cn(
            "flex items-center gap-2 text-xs font-medium px-4 py-2 bg-accent rounded-lg hover:bg-accent/80 transition-all border border-border disabled:opacity-50",
            isSyncing && "cursor-not-allowed"
          )}
        >
          <RefreshCw size={14} className={cn(isSyncing && "animate-spin")} />
          {isSyncing ? 'Syncing...' : 'Sync All Stores'}
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {stats.map((stat) => (
          <Card key={stat.label} className="p-5 flex flex-col gap-4 group hover:border-primary/30 transition-all cursor-default">
            <div className="flex items-center justify-between">
              <div className={cn("p-2 rounded-xl bg-background border border-border group-hover:border-primary/50 transition-all", stat.color)}>
                <stat.icon size={24} />
              </div>
              {typeof stat.change === 'number' && (
                <div className={cn(
                  "flex items-center text-xs font-bold px-2 py-1 rounded-full",
                  stat.change >= 0 ? "bg-secondary/10 text-secondary" : "bg-destructive/10 text-destructive"
                )}>
                  {stat.change >= 0 ? <ArrowUpRight size={14} className="mr-1" /> : <ArrowDownRight size={14} className="mr-1" />}
                  {stat.change >= 0 ? '+' : ''}{stat.change}%
                </div>
              )}
            </div>
            <div>
              <p className="text-muted-foreground text-xs font-medium uppercase tracking-wider">{stat.label}</p>
              <h3 className="text-2xl font-bold mt-1">{stat.value}</h3>
            </div>
          </Card>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <Card className="lg:col-span-2 p-6 flex flex-col gap-6">
          <div className="flex items-center justify-between">
            <h3 className="font-bold flex items-center gap-2">
              <Activity size={18} className="text-primary" />
              Revenue Growth
            </h3>
            <select 
              value={revenueRange}
              onChange={(e) => setRevenueRange(e.target.value)}
              className="bg-background border border-border rounded-lg px-2 py-1 text-xs outline-none cursor-pointer hover:border-primary/50 transition-all"
            >
              <option value="7">Last 7 Days</option>
              <option value="30">Last 30 Days</option>
              <option value="90">Last 90 Days</option>
            </select>
          </div>
          <div className="h-[350px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={timeline}>
                <defs>
                  <linearGradient id="colorRev" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#4F46E5" stopOpacity={0.3}/>
                    <stop offset="95%" stopColor="#4F46E5" stopOpacity={0}/>
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#1e293b" />
                <XAxis dataKey="date" stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} />
                <YAxis stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} />
                <Tooltip 
                  contentStyle={{ backgroundColor: '#1e293b', border: '1px solid #334155', borderRadius: '12px' }}
                />
                <Area type="monotone" dataKey="revenue" stroke="#4F46E5" strokeWidth={3} fillOpacity={1} fill="url(#colorRev)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card className="p-6 flex flex-col gap-6">
          <div className="flex items-center justify-between">
            <h3 className="font-bold">Recent Orders</h3>
            <button 
              onClick={() => router.push('/orders')}
              className="text-xs text-primary hover:underline font-bold"
            >
              View All
            </button>
          </div>
          
          <div className="flex flex-col gap-4 overflow-y-auto max-h-[350px] pr-2 custom-scrollbar">
            {recentOrders.map((order) => (
              <div 
                key={order.id} 
                onClick={() => router.push(`/orders/${order.id}`)}
                className="flex items-center justify-between p-3 rounded-xl bg-background/50 border border-border/50 hover:border-primary/30 transition-all cursor-pointer group"
              >
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-lg bg-accent flex items-center justify-center font-bold text-[10px] text-primary border border-border group-hover:border-primary/50 transition-all">
                    #{order.external_id?.slice(-4).toUpperCase() || 'ORD'}
                  </div>
                  <div>
                    <p className="text-sm font-medium">{order.customer_name || 'Guest Customer'}</p>
                    <p className="text-[10px] text-muted-foreground">{order.store?.platform || 'Direct'} • {new Date(order.created_at).toLocaleDateString()}</p>
                  </div>
                </div>
                <div className="text-right">
                  <p className="text-sm font-bold">{formatCurrency(order.total)}</p>
                  <div className="flex items-center gap-1 text-[8px] font-bold uppercase tracking-tighter text-primary mt-0.5">
                    <ArrowUpRight size={8} />
                    Details
                  </div>
                </div>
              </div>
            ))}
            {recentOrders.length === 0 && (
              <div className="text-center py-10 text-muted-foreground text-sm">
                No orders yet.
              </div>
            )}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        <Card className="p-6 space-y-6">
          <h4 className="font-bold text-sm flex items-center gap-2">
            <BarChart3 size={16} className="text-secondary" />
            Orders by Day
          </h4>
          <div className="h-[200px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={timeline}>
                <XAxis dataKey="date" hide />
                <Tooltip 
                  contentStyle={{ backgroundColor: '#1e293b', border: '1px solid #334155', borderRadius: '12px' }}
                />
                <Bar dataKey="orders" fill="#10B981" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card className="p-6 space-y-6">
          <h4 className="font-bold text-sm flex items-center gap-2">
            <Users size={16} className="text-purple-500" />
            Top Customers
          </h4>
          <div className="space-y-4">
            {topCustomers.map((customer, index) => (
              <div key={index} className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-full bg-purple-500/10 flex items-center justify-center text-purple-500 text-xs font-bold">
                    {customer.customer_name?.charAt(0) || 'G'}
                  </div>
                  <div>
                    <p className="text-xs font-bold">{customer.customer_name || 'Guest'}</p>
                    <p className="text-[10px] text-muted-foreground">{customer.orders_count} Orders</p>
                  </div>
                </div>
                <span className="text-xs font-bold">{formatCurrency(customer.total_spent)}</span>
              </div>
            ))}
            {topCustomers.length === 0 && (
              <p className="text-xs text-muted-foreground">Recent customer insights will appear here as orders flow in.</p>
            )}
          </div>
        </Card>

        <Card className="p-6 bg-primary/5 border-primary/20 flex flex-col justify-center items-center text-center gap-4">
          <div className="w-12 h-12 rounded-2xl bg-primary/10 flex items-center justify-center text-primary shadow-inner">
            <RefreshCw size={24} className="animate-spin-slow" />
          </div>
          <div>
            <h4 className="font-bold text-sm">System Health</h4>
            <p className="text-xs text-muted-foreground mt-1">Backend services are connected. Analytics updated in real-time.</p>
          </div>
          <button 
            onClick={() => router.push('/settings')}
            className="text-xs font-bold text-primary hover:underline"
          >
            View System Status
          </button>
        </Card>
      </div>
    </div>
  );
}


