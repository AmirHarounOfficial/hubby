'use client';

import React, { useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Card from '@/components/ui/Card';
import Input from '@/components/ui/Input';
import Button from '@/components/ui/Button';
import {
  Search,
  User, 
  Mail, 
  ShoppingBag, 
  DollarSign, 
  ChevronRight,
  Filter,
  Users
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';
import { getPlatform } from '@/lib/platforms';
import { useStores } from '@/components/providers/StoresProvider';
import ConnectPrompt from '@/components/ui/ConnectPrompt';

export default function CustomersPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { connectedPlatforms, hasConnectedStore, loading: storesLoading } = useStores();
  const [customers, setCustomers] = useState<any[]>([]);
  const [meta, setMeta] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [search, setSearch] = useState(searchParams.get('search') || '');
  const [platform, setPlatform] = useState('All');
  const [page, setPage] = useState(1);

  const fetchCustomers = async () => {
    setIsLoading(true);
    try {
      const response = await api.get('/customers', {
        params: {
          search,
          platform: platform !== 'All' ? platform.toLowerCase() : undefined,
          per_page: 15,
          page,
        }
      });
      setCustomers(response.data.data);
      setMeta(response.data);
    } catch (err) {
      console.error('Failed to fetch customers', err);
    } finally {
      setIsLoading(false);
    }
  };

  // Reset to the first page whenever the filters change.
  useEffect(() => {
    setPage(1);
  }, [search, platform]);

  useEffect(() => {
    const timer = setTimeout(() => {
      fetchCustomers();
    }, 400);
    return () => clearTimeout(timer);
  }, [search, platform, page]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-3">
            <Users className="text-primary" />
            Customers
          </h1>
          <p className="text-muted-foreground text-sm">View and manage your cross-platform customer base.</p>
        </div>
      </div>

      {!storesLoading && !hasConnectedStore ? (
        <ConnectPrompt description="Connect a store to see customers from your orders here." />
      ) : (
      <Card className="p-0 overflow-hidden">
        <div className="p-4 border-b border-border bg-card/30 space-y-4">
          <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
            <div className="relative w-full md:w-96">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
              <Input 
                placeholder="Search by name or email..." 
                className="pl-10" 
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground mr-2">Platform:</span>
              {['All', ...connectedPlatforms.map((p) => getPlatform(p).name)].map((p) => (
                <button 
                  key={p} 
                  onClick={() => setPlatform(p)}
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
        </div>

        <div className="overflow-x-auto min-h-[400px]">
          {isLoading ? (
            <div className="flex items-center justify-center h-full py-20">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            </div>
          ) : (
            <table className="w-full text-left">
              <thead>
                <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase tracking-wider font-bold">
                  <th className="px-6 py-4">Customer</th>
                  <th className="px-6 py-4">Email</th>
                  <th className="px-6 py-4">Sources</th>
                  <th className="px-6 py-4 text-center">Total Orders</th>
                  <th className="px-6 py-4">Total Spend</th>
                  <th className="px-6 py-4">Last Order</th>
                  <th className="px-6 py-4 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {customers.map((customer) => {
                  const customerPlatforms = (customer.platforms || '').split(',').filter(Boolean);
                  return (
                    <tr 
                      key={customer.customer_email} 
                      className="hover:bg-accent/30 transition-all cursor-pointer group"
                      onClick={() => router.push(`/customers/${encodeURIComponent(customer.customer_email)}`)}
                    >
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs border border-primary/20">
                            {customer.name?.charAt(0) || 'C'}
                          </div>
                          <span className="text-sm font-medium">{customer.name || 'Anonymous'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-xs text-muted-foreground">{customer.customer_email}</td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-1.5">
                          {customerPlatforms.map((p: string) => {
                            const info = getPlatform(p);
                            const Icon = info.icon;
                            return (
                              <div key={p} className={cn("p-1.5 rounded-lg bg-accent/50 border border-border/50", info.color)} title={info.name}>
                                <Icon size={14} />
                              </div>
                            );
                          })}
                        </div>
                      </td>
                      <td className="px-6 py-4 text-center">
                        <span className="px-2 py-1 rounded-lg bg-accent text-xs font-bold">{customer.total_orders}</span>
                      </td>
                      <td className="px-6 py-4 text-sm font-bold text-secondary">
                        {formatCurrency(customer.total_spend, 'SAR')}
                      </td>
                      <td className="px-6 py-4 text-xs text-muted-foreground">
                        {new Date(customer.last_order_date).toLocaleDateString()}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button className="p-2 hover:bg-primary/10 rounded-lg text-primary opacity-0 group-hover:opacity-100 transition-all">
                          <ChevronRight size={18} />
                        </button>
                      </td>
                    </tr>
                  );
                })}
                {customers.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-6 py-20 text-center text-muted-foreground">
                      No customers found.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between px-6 py-4 border-t border-border text-sm">
            <span className="text-muted-foreground text-xs">
              Page {meta.current_page} of {meta.last_page} · {meta.total} customers
            </span>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Previous
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        )}
      </Card>
      )}
    </div>
  );
}
