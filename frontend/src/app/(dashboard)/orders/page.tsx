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
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Money } from '@/components/ui/Money';
import api from '@/lib/api';
import { getPlatform } from '@/lib/platforms';
import { PlatformLogo } from '@/components/ui/PlatformLogo';
import { useStores } from '@/components/providers/StoresProvider';
import { useToast } from '@/components/ui/Toast';
import ConnectPrompt from '@/components/ui/ConnectPrompt';
import { useT } from '@/i18n';

const statusColors: Record<string, string> = {
  paid: 'bg-secondary/10 text-secondary',
  processing: 'bg-primary/10 text-primary',
  shipped: 'bg-blue-500/10 text-blue-500',
  pending: 'bg-warning/10 text-warning',
  cancelled: 'bg-destructive/10 text-destructive',
  authorized: 'bg-blue-500/10 text-blue-500', // Shopify status
};

export default function OrdersPage() {
  const t = useT();
  const router = useRouter();
  const { connectedPlatforms, hasConnectedStore, loading: storesLoading } = useStores();
  const { toast } = useToast();
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
      toast(t('orders.toast.exportFailed'), 'error');
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{t('orders.title')}</h1>
          <p className="text-muted-foreground text-sm">{t('orders.subtitle')}</p>
        </div>
        <div className="flex items-center gap-3">
          <Button 
            variant="outline" 
            size="sm"
            onClick={handleExport}
          >
            <Download size={16} className="mr-2" />
            {t('orders.exportCsv')}
          </Button>
          <Button 
            variant={showAdvanced ? "primary" : "outline"} 
            size="sm"
            onClick={() => setShowAdvanced(!showAdvanced)}
          >
            <Filter size={16} className="mr-2" />
            {showAdvanced ? t('orders.hideFilters') : t('orders.advancedFilters')}
          </Button>
        </div>
      </div>

      {!storesLoading && !hasConnectedStore ? (
        <ConnectPrompt description={t('orders.connectDescription')} />
      ) : (
      <Card className="p-0 overflow-hidden">
        <div className="p-4 border-b border-border space-y-4 bg-card/30">
          <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
            <div className="relative w-full md:w-96">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
              <Input
                placeholder={t('orders.searchPlaceholder')}
                className="pl-10"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground mr-2">{t('orders.filters.platform')}</span>
              {['All', ...connectedPlatforms.map((p) => getPlatform(p).name)].map((p) => (
                <button
                  key={p}
                  onClick={() => { setPlatform(p); setPage(1); }}
                  className={cn(
                    "px-3 py-1.5 text-xs rounded-lg border border-border hover:bg-accent transition-all",
                    p === platform ? "bg-primary text-white border-primary" : "bg-background"
                  )}
                >
                  {p === 'All' ? t('orders.filters.all') : p}
                </button>
              ))}
            </div>
          </div>

          {showAdvanced && (
            <div className="flex flex-wrap items-center gap-4 pt-4 border-t border-border/50 animate-in fade-in slide-in-from-top-2 duration-300">
              <div className="flex items-center gap-2">
                <span className="text-xs text-muted-foreground mr-2">{t('orders.filters.status')}</span>
                {['All', 'Pending', 'Processing', 'Paid', 'Shipped', 'Cancelled'].map((s) => (
                  <button
                    key={s}
                    onClick={() => { setStatus(s); setPage(1); }}
                    className={cn(
                      "px-3 py-1.5 text-xs rounded-lg border border-border hover:bg-accent transition-all",
                      s === status ? "bg-secondary text-white border-secondary" : "bg-background"
                    )}
                  >
                    {s === 'All' ? t('orders.filters.all') : t(`orders.status.${s.toLowerCase()}`)}
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="overflow-x-auto min-h-[400px]">
          {isLoading ? (
            <div className="flex items-center justify-center h-full py-20">{t('orders.loading')}</div>
          ) : (
            <table className="w-full text-left">
              <thead>
                <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-6 py-4">{t('orders.columns.orderId')}</th>
                  <th className="px-6 py-4">{t('orders.columns.customer')}</th>
                  <th className="px-6 py-4">{t('orders.columns.platform')}</th>
                  <th className="px-6 py-4">{t('orders.columns.date')}</th>
                  <th className="px-6 py-4">{t('orders.columns.total')}</th>
                  <th className="px-6 py-4">{t('orders.columns.status')}</th>
                  <th className="px-6 py-4 text-right">{t('orders.columns.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {orders.map((order) => {
                  const PlatformInfo = getPlatform(order.store?.platform);
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
                          <span className="text-sm font-medium">{order.customer_name || t('orders.guestCustomer')}</span>
                          <span className="text-[10px] text-muted-foreground">{order.customer_email || 'no-email@provided.com'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <PlatformLogo platform={order.store?.platform} size={16} />
                          <span className="text-xs">{PlatformInfo.name}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-xs text-muted-foreground">
                        {new Date(order.created_at).toLocaleString()}
                      </td>
                      <td className="px-6 py-4 text-sm font-bold"><Money amount={order.total} currency={order.currency} /></td>
                      <td className="px-6 py-4">
                        <span className={cn(
                          "px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-tight",
                          statusColors[order.status.toLowerCase()] || 'bg-accent text-muted-foreground'
                        )}>
                          {t(`orders.status.${order.status.toLowerCase()}`)}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button 
                            className="p-2 hover:bg-primary/10 rounded-lg text-primary transition-all opacity-40 group-hover:opacity-100"
                            title={t('orders.actions.viewInStore')}
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
                                      if (confirm(t('orders.confirm.cancelOrder'))) {
                                        try {
                                          await api.put(`/orders/${order.id}`, { status: 'Cancelled' });
                                          toast(t('orders.toast.orderCancelled'), 'success');
                                          fetchOrders();
                                          setActiveMenuId(null);
                                        } catch (err) {
                                          console.error('Cancel failed', err);
                                          toast(t('orders.toast.cancelFailed'), 'error');
                                        }
                                      }
                                    }}
                                  >
                                    {t('orders.actions.cancelOrder')}
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
                                  {t('orders.actions.viewDetails')}
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
                      {t('orders.emptyState')}
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
              {t('orders.pagination.showing')} {orders.length} {t('orders.pagination.of')} {meta.total} {t('orders.pagination.orders')}
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
                <span className="text-xs font-medium px-3">{t('orders.pagination.page')} {page} {t('orders.pagination.pageOf')} {meta.last_page}</span>
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
      )}
    </div>
  );
}

