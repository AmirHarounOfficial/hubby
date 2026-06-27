'use client';

import React, { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { 
  ChevronLeft, 
  User, 
  Mail, 
  Package, 
  DollarSign, 
  Calendar,
  ShoppingBag,
  ExternalLink,
  Clock,
  CheckCircle2,
  AlertCircle,
  Truck,
  Store
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';

const statusColors: Record<string, string> = {
  paid: 'text-secondary bg-secondary/10',
  processing: 'text-primary bg-primary/10',
  shipped: 'text-blue-500 bg-blue-500/10',
  pending: 'text-warning bg-warning/10',
  cancelled: 'text-destructive bg-destructive/10',
};

export default function CustomerProfilePage() {
  const { email } = useParams();
  const router = useRouter();
  const [customer, setCustomer] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const fetchCustomer = async () => {
      try {
        const decodedEmail = decodeURIComponent(email as string);
        const response = await api.get(`/customers/${decodedEmail}`);
        setCustomer(response.data);
      } catch (err) {
        console.error('Failed to fetch customer', err);
      } finally {
        setIsLoading(false);
      }
    };
    fetchCustomer();
  }, [email]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!customer) {
    return (
      <div className="text-center py-20">
        <h2 className="text-xl font-bold">Customer not found</h2>
        <Button onClick={() => router.push('/orders')} className="mt-4">
          Back to Orders
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-20">
      <div className="flex items-center gap-4">
        <button 
          onClick={() => router.back()}
          className="p-2 hover:bg-accent rounded-full transition-all"
        >
          <ChevronLeft size={20} />
        </button>
        <div>
          <h1 className="text-2xl font-bold">Customer Profile</h1>
          <p className="text-xs text-muted-foreground mt-1 tracking-wide">{customer.email}</p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="space-y-6">
          {/* Customer Overview */}
          <Card className="p-6">
            <div className="flex flex-col items-center text-center mb-8">
              <div className="w-20 h-20 rounded-full bg-primary/10 flex items-center justify-center text-primary text-3xl font-bold border border-primary/20 mb-4 shadow-sm">
                {customer.name?.charAt(0) || 'C'}
              </div>
              <h2 className="text-lg font-bold">{customer.name || 'Anonymous Customer'}</h2>
              <div className="flex items-center gap-2 text-muted-foreground text-sm mt-1">
                <Mail size={14} />
                <span>{customer.email}</span>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="p-4 rounded-2xl bg-accent/20 border border-border text-center">
                <p className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider mb-1">Total Orders</p>
                <p className="text-xl font-bold text-primary">{customer.total_orders}</p>
              </div>
              <div className="p-4 rounded-2xl bg-accent/20 border border-border text-center">
                <p className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider mb-1">Total Spend</p>
                <p className="text-xl font-bold text-secondary">{formatCurrency(customer.total_spend, customer.currency)}</p>
              </div>
            </div>

            <div className="mt-8 space-y-4">
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground flex items-center gap-2"><Calendar size={14} /> Last Order</span>
                <span className="font-medium">{new Date(customer.orders[0]?.created_at).toLocaleDateString()}</span>
              </div>
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground flex items-center gap-2"><Package size={14} /> Products Bought</span>
                <span className="font-medium">{customer.orders.reduce((acc: number, o: any) => acc + (o.items?.length || 0), 0)}</span>
              </div>
            </div>
          </Card>

          {/* Contact Details */}
          <Card className="p-6">
            <h3 className="font-bold text-sm mb-4">Contact Details</h3>
            <div className="space-y-4">
              <div className="flex flex-col gap-1">
                <span className="text-[10px] text-muted-foreground uppercase font-bold tracking-widest">Email</span>
                <span className="text-sm font-medium break-all">{customer.email}</span>
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-[10px] text-muted-foreground uppercase font-bold tracking-widest">First Order</span>
                <span className="text-sm font-medium">
                  {new Date(Math.min(...customer.orders.map((o: any) => new Date(o.created_at).getTime()))).toLocaleDateString()}
                </span>
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-[10px] text-muted-foreground uppercase font-bold tracking-widest">Latest Order</span>
                <span className="text-sm font-medium">
                  {new Date(Math.max(...customer.orders.map((o: any) => new Date(o.created_at).getTime()))).toLocaleDateString()}
                </span>
              </div>
            </div>
          </Card>
        </div>

        <div className="lg:col-span-2 space-y-6">
          {/* Order History */}
          <Card className="p-0 overflow-hidden">
            <div className="p-4 border-b border-border bg-card/30 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <ShoppingBag size={18} className="text-primary" />
                <h3 className="font-bold text-sm">Order History</h3>
              </div>
              <span className="text-[10px] font-bold text-muted-foreground uppercase bg-accent px-2 py-0.5 rounded">
                Real-time Data
              </span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="bg-accent/30 text-muted-foreground text-[10px] uppercase font-bold tracking-wider">
                    <th className="px-6 py-3">Order ID</th>
                    <th className="px-6 py-3">Store</th>
                    <th className="px-6 py-3">Date</th>
                    <th className="px-6 py-3">Total</th>
                    <th className="px-6 py-3">Status</th>
                    <th className="px-6 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {customer.orders.map((order: any) => (
                    <tr key={order.id} className="text-sm hover:bg-accent/10 transition-colors group">
                      <td className="px-6 py-4 font-mono text-xs text-primary font-bold">#{order.external_id.slice(-6).toUpperCase()}</td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <Store size={14} className="text-muted-foreground" />
                          <span className="text-xs">{order.store?.name || 'Unknown Store'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-xs text-muted-foreground">
                        {new Date(order.created_at).toLocaleDateString()}
                      </td>
                      <td className="px-6 py-4 font-medium">{formatCurrency(order.total, order.currency)}</td>
                      <td className="px-6 py-4">
                        <span className={cn(
                          "px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-tight",
                          statusColors[order.status.toLowerCase()] || 'bg-accent text-muted-foreground'
                        )}>
                          {order.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button 
                          onClick={() => router.push(`/orders/${order.id}`)}
                          className="p-1.5 hover:bg-primary/10 rounded-lg text-primary opacity-0 group-hover:opacity-100 transition-all"
                        >
                          <ExternalLink size={14} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          {/* Activity Insights */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Card className="p-6 bg-gradient-to-br from-primary/5 to-transparent border-primary/10">
              <h4 className="text-xs font-bold uppercase tracking-widest text-primary mb-4">Buying Patterns</h4>
              <p className="text-sm text-muted-foreground leading-relaxed">
                {(() => {
                  const counts: Record<string, number> = {};
                  customer.orders.forEach((o: any) => {
                    const p = o.store?.platform || 'other';
                    counts[p] = (counts[p] || 0) + 1;
                  });
                  const top = Object.entries(counts).sort((a, b) => b[1] - a[1])[0];
                  const aov = customer.total_orders ? customer.total_spend / customer.total_orders : 0;
                  const platform = top ? top[0].charAt(0).toUpperCase() + top[0].slice(1) : 'their store';
                  return `${customer.total_orders} order${customer.total_orders === 1 ? '' : 's'} placed, most often on ${platform}. Average order value is ${formatCurrency(aov, customer.currency)}.`;
                })()}
              </p>
            </Card>
            <Card className="p-6 bg-gradient-to-br from-secondary/5 to-transparent border-secondary/10">
              <h4 className="text-xs font-bold uppercase tracking-widest text-secondary mb-4">Loyalty Status</h4>
              {(() => {
                const spend = Number(customer.total_spend) || 0;
                const tier = spend >= 6000 ? 'Platinum' : spend >= 3000 ? 'Gold' : spend >= 1000 ? 'Silver' : 'Bronze';
                return (
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-secondary/20 flex items-center justify-center text-secondary">
                      <CheckCircle2 size={24} />
                    </div>
                    <div>
                      <p className="text-sm font-bold">{tier} Tier</p>
                      <p className="text-[10px] text-muted-foreground">
                        Based on {formatCurrency(spend, customer.currency)} lifetime spend
                      </p>
                    </div>
                  </div>
                );
              })()}
            </Card>
          </div>
        </div>
      </div>
    </div>
  );
}
