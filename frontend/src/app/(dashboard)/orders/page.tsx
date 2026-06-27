'use client';

import React, { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { 
  Search, 
  Filter, 
  Download, 
  ExternalLink, 
  MoreHorizontal,
  ChevronLeft,
  ChevronRight,
  ShoppingBag,
  Globe,
  Zap,
  Store
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';

const platformIcons: Record<string, any> = {
  shopify: { icon: ShoppingBag, color: 'text-green-500', label: 'Shopify' },
  salla: { icon: Globe, color: 'text-primary', label: 'Salla' },
  woocommerce: { icon: Zap, color: 'text-purple-600', label: 'WooCommerce' },
  zid: { icon: Store, color: 'text-orange-500', label: 'Zid' },
};

const statusColors: Record<string, string> = {
  paid: 'bg-secondary/10 text-secondary',
  processing: 'bg-primary/10 text-primary',
  shipped: 'bg-blue-500/10 text-blue-500',
  pending: 'bg-warning/10 text-warning',
  cancelled: 'bg-destructive/10 text-destructive',
  authorized: 'bg-blue-500/10 text-blue-500', // Shopify status
};

export default function OrdersPage() {
  const router = useRouter();
  const [orders, setOrders] = useState<any[]>([]);
  const [meta, setMeta] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [platform, setPlatform] = useState('All');
  const [status, setStatus] = useState('All');
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [activeMenuId, setActiveMenuId] = useState<number | null>(null);
  const [page, setPage] = useState(1);

  // Close menu on click outside
  useEffect(() => {
    const handleClickOutside = () => setActiveMenuId(null);
    window.addEventListener('click', handleClickOutside);
    return () => window.removeEventListener('click', handleClickOutside);
  }, []);

  const fetchOrders = async () => {
    setIsLoading(true);
    try {
      const params: any = { page, per_page: 15 };
      if (search) params.search = search;
      if (platform !== 'All') params.platform = platform.toLowerCase();
      if (status !== 'All') params.status = status.toLowerCase();
      
      const response = await api.get('/orders', { params });
      setOrders(response.data.data);
      setMeta(response.data);
    } catch (err) {
      console.error('Failed to fetch orders', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => {
      fetchOrders();
    }, 500);
    return () => clearTimeout(timer);
  }, [search, platform, status, page]);

  const handleExport = async () => {
    try {
      const params: any = {};
      if (search) params.search = search;
      if (platform !== 'All') params.platform = platform.toLowerCase();
      if (status !== 'All') params.status = status.toLowerCase();

      const response = await api.get('/orders/export', { 
        params,
        responseType: 'blob' 
      });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `orders-export-${new Date().toISOString().split('T')[0]}.csv`);
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch (err) {
      console.error('Export failed', err);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Orders</h1>
          <p className="text-muted-foreground text-sm">Manage and track orders from all your stores.</p>
        </div>
        <div className="flex items-center gap-3">
          <Button 
            variant="outline" 
            size="sm"
            onClick={handleExport}
          >
            <Download size={16} className="mr-2" />
            Export CSV
          </Button>
          <Button 
            variant={showAdvanced ? "primary" : "outline"} 
            size="sm"
            onClick={() => setShowAdvanced(!showAdvanced)}
          >
            <Filter size={16} className="mr-2" />
            {showAdvanced ? "Hide Filters" : "Advanced Filters"}
          </Button>
        </div>
      </div>

      <Card className="p-0 overflow-hidden">
        <div className="p-4 border-b border-border space-y-4 bg-card/30">
          <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
            <div className="relative w-full md:w-96">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
              <Input 
                placeholder="Search by ID or customer..." 
                className="pl-10" 
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground mr-2">Platform:</span>
              {['All', 'Shopify', 'Salla', 'WooCommerce', 'Zid'].map((p) => (
                <button 
                  key={p} 
                  onClick={() => { setPlatform(p); setPage(1); }}
                  className={cn(
                    "px-3 py-1.5 text-xs rounded-lg border border-border hover:bg-accent transition-all",
                    p === platform ? "bg-primary text-white border-primary" : "bg-background"
                  )}
                >
                  {p}
                </button>
              ))}
            </div>
          </div>

          {showAdvanced && (
            <div className="flex flex-wrap items-center gap-4 pt-4 border-t border-border/50 animate-in fade-in slide-in-from-top-2 duration-300">
              <div className="flex items-center gap-2">
                <span className="text-xs text-muted-foreground mr-2">Status:</span>
                {['All', 'Pending', 'Processing', 'Paid', 'Shipped', 'Cancelled'].map((s) => (
                  <button 
                    key={s} 
                    onClick={() => { setStatus(s); setPage(1); }}
                    className={cn(
                      "px-3 py-1.5 text-xs rounded-lg border border-border hover:bg-accent transition-all",
                      s === status ? "bg-secondary text-white border-secondary" : "bg-background"
                    )}
                  >
                    {s}
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="overflow-x-auto min-h-[400px]">
          {isLoading ? (
            <div className="flex items-center justify-center h-full py-20">Loading...</div>
          ) : (
            <table className="w-full text-left">
              <thead>
                <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-6 py-4">Order ID</th>
                  <th className="px-6 py-4">Customer</th>
                  <th className="px-6 py-4">Platform</th>
                  <th className="px-6 py-4">Date</th>
                  <th className="px-6 py-4">Total</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {orders.map((order) => {
                  const PlatformInfo = platformIcons[order.store?.platform] || { icon: Store, color: 'text-muted-foreground', label: 'Other' };
                  const isMenuOpen = activeMenuId === order.id;

                  return (
                    <tr 
                      key={order.id} 
                      onClick={() => router.push(`/orders/${order.id}`)}
                      className="hover:bg-accent/30 transition-all cursor-pointer group"
                    >
                      <td className="px-6 py-4 font-mono text-sm text-primary font-bold"
                      onClick={() => router.push(`/orders/${order.id}`)}>#{order.external_id.slice(-6).toUpperCase()}</td>
                      <td className="px-6 py-4">
                        <div className="flex flex-col">
                          <span className="text-sm font-medium">{order.customer_name || 'Guest Customer'}</span>
                          <span className="text-[10px] text-muted-foreground">{order.customer_email || 'no-email@provided.com'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <PlatformInfo.icon size={16} className={PlatformInfo.color} />
                          <span className="text-xs">{PlatformInfo.label}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-xs text-muted-foreground">
                        {new Date(order.created_at).toLocaleString()}
                      </td>
                      <td className="px-6 py-4 text-sm font-bold">{formatCurrency(order.total, order.currency)}</td>
                      <td className="px-6 py-4">
                        <span className={cn(
                          "px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-tight",
                          statusColors[order.status.toLowerCase()] || 'bg-accent text-muted-foreground'
                        )}>
                          {order.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button 
                            className="p-2 hover:bg-primary/10 rounded-lg text-primary transition-all opacity-40 group-hover:opacity-100"
                            title="View in Store"
                            onClick={(e) => {
                              e.stopPropagation();
                              const url = order.store?.domain ? `https://${order.store.domain}` : `/orders/${order.id}`;
                              window.open(url, '_blank');
                            }}
                          >
                            <ExternalLink size={16} />
                          </button>
                          <div className="relative">
                            <button 
                              className={cn(
                                "p-2 rounded-lg transition-all",
                                isMenuOpen ? "bg-primary text-white" : "hover:bg-accent opacity-40 group-hover:opacity-100"
                              )}
                              onClick={(e) => {
                                e.stopPropagation();
                                setActiveMenuId(isMenuOpen ? null : order.id);
                              }}
                            >
                              <MoreHorizontal size={16} />
                            </button>
                            {isMenuOpen && (
                              <div 
                                className="absolute right-0 bottom-full mb-2 w-40 bg-card border border-border rounded-xl shadow-xl z-50 overflow-hidden animate-in fade-in zoom-in-95 duration-200"
                                onClick={(e) => e.stopPropagation()}
                              >
                                {order.status.toLowerCase() !== 'cancelled' && order.status.toLowerCase() !== 'shipped' && (
                                  <button 
                                    className="w-full text-left px-4 py-2.5 text-xs hover:bg-destructive/10 text-destructive flex items-center gap-2"
                                    onClick={async (e) => {
                                      e.stopPropagation();
                                      if (confirm('Are you sure you want to cancel this order?')) {
                                        try {
                                          await api.put(`/orders/${order.id}`, { status: 'Cancelled' });
                                          fetchOrders();
                                          setActiveMenuId(null);
                                        } catch (err) {
                                          console.error('Cancel failed', err);
                                        }
                                      }
                                    }}
                                  >
                                    Cancel Order
                                  </button>
                                )}
                                <button 
                                  className="w-full text-left px-4 py-2.5 text-xs hover:bg-accent flex items-center gap-2"
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    router.push(`/orders/${order.id}`);
                                    setActiveMenuId(null);
                                  }}
                                >
                                  View Details
                                </button>
                              </div>
                            )}
                          </div>
                        </div>
                      </td>
                    </tr>
                  );
                })}
                {orders.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-6 py-20 text-center text-muted-foreground">
                      No orders found matching your criteria.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>

        {meta && meta.last_page > 1 && (
          <div className="p-4 border-t border-border flex items-center justify-between bg-card/30">
            <p className="text-xs text-muted-foreground">
              Showing {orders.length} of {meta.total} orders
            </p>
            <div className="flex items-center gap-2">
              <button 
                onClick={() => setPage(p => Math.max(1, p - 1))}
                disabled={page === 1}
                className="p-2 hover:bg-accent rounded-lg disabled:opacity-30"
              >
                <ChevronLeft size={18} />
              </button>
              <div className="flex items-center gap-1">
                <span className="text-xs font-medium px-3">Page {page} of {meta.last_page}</span>
              </div>
              <button 
                onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
                disabled={page === meta.last_page}
                className="p-2 hover:bg-accent rounded-lg disabled:opacity-30"
              >
                <ChevronRight size={18} />
              </button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
}

