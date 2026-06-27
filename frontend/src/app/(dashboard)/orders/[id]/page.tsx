'use client';

import React, { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { 
  ChevronLeft, 
  Package, 
  Truck, 
  CreditCard, 
  User, 
  Mail, 
  MapPin, 
  Calendar,
  ExternalLink,
  ShoppingBag,
  Globe,
  Zap,
  Store as StoreIcon,
  CheckCircle2,
  Clock,
  AlertCircle
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';

const platformIcons: Record<string, any> = {
  shopify: { icon: ShoppingBag, color: 'text-green-500', label: 'Shopify' },
  salla: { icon: Globe, color: 'text-primary', label: 'Salla' },
  woocommerce: { icon: Zap, color: 'text-purple-600', label: 'WooCommerce' },
  zid: { icon: StoreIcon, color: 'text-orange-500', label: 'Zid' },
};

const statusConfig: Record<string, any> = {
  paid: { color: 'text-secondary bg-secondary/10', icon: CheckCircle2, label: 'Paid' },
  processing: { color: 'text-primary bg-primary/10', icon: Clock, label: 'Processing' },
  shipped: { color: 'text-blue-500 bg-blue-500/10', icon: Truck, label: 'Shipped' },
  delivered: { color: 'text-green-600 bg-green-600/10', icon: CheckCircle2, label: 'Delivered' },
  pending: { color: 'text-warning bg-warning/10', icon: Clock, label: 'Pending' },
  cancelled: { color: 'text-destructive bg-destructive/10', icon: AlertCircle, label: 'Cancelled' },
};

export default function OrderDetailsPage() {
  const { id } = useParams();
  const router = useRouter();
  const [order, setOrder] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isUpdating, setIsUpdating] = useState(false);

  const fetchOrder = async () => {
    setIsLoading(true);
    try {
      const response = await api.get(`/orders/${id}`);
      setOrder(response.data);
    } catch (err) {
      console.error('Failed to fetch order', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchOrder();
  }, [id]);

  const updateStatus = async (newStatus: string) => {
    setIsUpdating(true);
    try {
      await api.put(`/orders/${id}`, { status: newStatus });
      await fetchOrder();
      // Optional: Show success toast
    } catch (err) {
      console.error('Failed to update status', err);
    } finally {
      setIsUpdating(false);
    }
  };

  const handlePrint = () => {
    window.print();
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!order) {
    return (
      <div className="text-center py-20">
        <h2 className="text-xl font-bold">Order not found</h2>
        <Button onClick={() => router.push('/orders')} className="mt-4">
          Back to Orders
        </Button>
      </div>
    );
  }

  const StatusInfo = statusConfig[order.status.toLowerCase()] || statusConfig.pending;
  const PlatformInfo = platformIcons[order.store?.platform] || { icon: StoreIcon, color: 'text-muted-foreground', label: 'Other' };

  return (
    <div className="space-y-6 pb-20 print:pb-0">
      <div className="flex items-center justify-between print:hidden">
        <div className="flex items-center gap-4">
          <button 
            onClick={() => router.back()}
            className="p-2 hover:bg-accent rounded-full transition-all"
          >
            <ChevronLeft size={20} />
          </button>
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-3">
              Order #{order.external_id.slice(-6).toUpperCase()}
              <span className={cn(
                "px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1.5",
                StatusInfo.color
              )}>
                <StatusInfo.icon size={12} />
                {StatusInfo.label}
              </span>
            </h1>
            <p className="text-xs text-muted-foreground mt-1">Placed on {new Date(order.created_at).toLocaleString()}</p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          {order.status.toLowerCase() === 'pending' && (
            <Button 
              variant="outline" 
              size="sm" 
              className="text-secondary hover:bg-secondary/10"
              onClick={() => updateStatus('Paid')}
              disabled={isUpdating}
            >
              Mark as Paid
            </Button>
          )}
          {order.status.toLowerCase() !== 'cancelled' && order.status.toLowerCase() !== 'shipped' && (
            <Button 
              variant="outline" 
              size="sm" 
              className="text-destructive hover:bg-destructive/10"
              onClick={() => updateStatus('Cancelled')}
              disabled={isUpdating}
            >
              Cancel Order
            </Button>
          )}
          <Button variant="outline" size="sm" onClick={handlePrint}>
            Print Order
          </Button>
          {['paid', 'processing'].includes(order.status.toLowerCase()) && (
            <Button 
              variant="primary" 
              size="sm"
              onClick={() => updateStatus('Shipped')}
              disabled={isUpdating}
            >
              Fulfill Items
            </Button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          {/* Items Card */}
          <Card className="p-0 overflow-hidden">
            <div className="p-4 border-b border-border bg-card/30 flex items-center gap-2">
              <Package size={18} className="text-primary" />
              <h3 className="font-bold text-sm">Line Items</h3>
              <span className="ml-auto text-[10px] font-bold text-muted-foreground uppercase bg-accent px-2 py-0.5 rounded">
                {order.items?.length || 0} Products
              </span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="bg-accent/30 text-muted-foreground text-[10px] uppercase font-bold tracking-wider">
                    <th className="px-6 py-3">Product</th>
                    <th className="px-6 py-3">SKU</th>
                    <th className="px-6 py-3">Price</th>
                    <th className="px-6 py-3 text-center">Qty</th>
                    <th className="px-6 py-3 text-right">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {order.items?.map((item: any) => (
                    <tr key={item.id} className="text-sm">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-10 rounded-lg bg-accent flex items-center justify-center text-muted-foreground">
                            <Package size={20} />
                          </div>
                          <span className="font-medium">{item.product_name || item.name}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 font-mono text-xs text-muted-foreground">{item.sku || 'N/A'}</td>
                      <td className="px-6 py-4">{formatCurrency(item.price, order.currency)}</td>
                      <td className="px-6 py-4 text-center">{item.quantity}</td>
                      <td className="px-6 py-4 text-right font-bold">{formatCurrency(item.price * item.quantity, order.currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="p-6 bg-accent/10 flex flex-col items-end gap-2 border-t border-border">
              <div className="flex justify-between w-full max-w-[240px] text-sm">
                <span className="text-muted-foreground">Subtotal</span>
                <span>{formatCurrency(order.total, order.currency)}</span>
              </div>
              <div className="h-px bg-border w-full max-w-[240px] my-1"></div>
              <div className="flex justify-between w-full max-w-[240px] text-lg font-bold">
                <span>Total</span>
                <span className="text-primary">{formatCurrency(order.total, order.currency)}</span>
              </div>
            </div>
          </Card>

          {/* Timeline Card */}
          <Card className="p-6">
            <h3 className="font-bold text-sm mb-6 flex items-center gap-2">
              <Clock size={18} className="text-primary" />
              Order Activity
            </h3>
            {(() => {
              const status = (order.status || '').toLowerCase();
              if (status === 'cancelled') {
                return (
                  <div className="flex gap-4 relative">
                    <div className="w-6 h-6 rounded-full bg-destructive/20 border-4 border-card flex items-center justify-center z-10">
                      <div className="w-2 h-2 rounded-full bg-destructive"></div>
                    </div>
                    <div>
                      <p className="text-sm font-bold">Order Cancelled</p>
                      <p className="text-[10px] text-muted-foreground/60 mt-2">{new Date(order.updated_at).toLocaleString()}</p>
                    </div>
                  </div>
                );
              }
              const steps = [
                { key: 'paid', label: 'Paid', desc: `Payment recorded on ${order.store?.platform || 'the store'}.` },
                { key: 'processing', label: 'Processing', desc: 'Order is being prepared for fulfilment.' },
                { key: 'shipped', label: 'Shipped', desc: 'Handed over to the carrier.' },
                { key: 'delivered', label: 'Delivered', desc: 'Delivered to the customer.' },
              ];
              const currentIdx = steps.findIndex((s) => s.key === status);
              return (
                <div className="space-y-6 relative before:absolute before:left-[11px] before:top-2 before:bottom-2 before:w-px before:bg-border">
                  {steps.map((s, i) => {
                    const done = currentIdx >= 0 && i < currentIdx;
                    const current = i === currentIdx;
                    return (
                      <div key={s.key} className="flex gap-4 relative">
                        <div className={cn(
                          'w-6 h-6 rounded-full border-4 border-card flex items-center justify-center z-10',
                          done ? 'bg-secondary/20' : current ? 'bg-primary/20' : 'bg-accent'
                        )}>
                          <div className={cn(
                            'w-2 h-2 rounded-full',
                            done ? 'bg-secondary' : current ? 'bg-primary animate-pulse' : 'bg-muted-foreground/40'
                          )} />
                        </div>
                        <div className={cn(!done && !current && 'opacity-50')}>
                          <p className="text-sm font-bold">{s.label}</p>
                          <p className="text-xs text-muted-foreground mt-1">{s.desc}</p>
                          {current && (
                            <p className="text-[10px] text-muted-foreground/60 mt-2">{new Date(order.updated_at).toLocaleString()}</p>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              );
            })()}
          </Card>
        </div>

        <div className="space-y-6">
          {/* Customer Card */}
          <Card className="p-6">
            <h3 className="font-bold text-sm mb-4 flex items-center gap-2">
              <User size={18} className="text-primary" />
              Customer Information
            </h3>
            <div className="flex items-center gap-3 mb-6">
              <div className="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center text-primary text-lg font-bold border border-primary/20">
                {order.customer_name?.charAt(0) || 'G'}
              </div>
              <div>
                <p className="text-sm font-bold">{order.customer_name || 'Guest Customer'}</p>
                <p className="text-xs text-muted-foreground">Customer</p>
              </div>
            </div>
            <div className="space-y-4">
              <div className="flex items-center gap-3">
                <Mail size={14} className="text-muted-foreground" />
                <span className="text-xs font-medium truncate">{order.customer_email || 'No email provided'}</span>
              </div>
              <div className="flex items-center gap-3">
                <Calendar size={14} className="text-muted-foreground" />
                <span className="text-xs font-medium">Order placed {new Date(order.created_at).toLocaleDateString()}</span>
              </div>
            </div>
            <Button 
              variant="outline" 
              size="sm" 
              className="w-full mt-6"
              onClick={() => router.push(`/customers/${encodeURIComponent(order.customer_email)}`)}
            >
              View Customer Profile
            </Button>
          </Card>

          {/* Store Info Card */}
          <Card className="p-6">
            <h3 className="font-bold text-sm mb-4 flex items-center gap-2">
              <StoreIcon size={18} className="text-primary" />
              Store Details
            </h3>
            <div className="p-4 rounded-2xl bg-accent/20 border border-border flex items-center gap-3">
              <div className={cn("w-10 h-10 rounded-xl bg-card flex items-center justify-center shadow-sm", PlatformInfo.color)}>
                <PlatformInfo.icon size={24} />
              </div>
              <div>
                <p className="text-xs font-bold">{order.store?.name}</p>
                <p className="text-[10px] text-muted-foreground mt-0.5 uppercase tracking-wider">{order.store?.platform}</p>
              </div>
              <button 
                className="ml-auto p-2 hover:bg-accent rounded-lg text-muted-foreground hover:text-primary transition-all"
                onClick={() => {
                  // For now, just link to store domain or dashboard
                  const url = order.store?.domain ? `https://${order.store.domain}` : '#';
                  window.open(url, '_blank');
                }}
              >
                <ExternalLink size={14} />
              </button>
            </div>
            <div className="mt-4 space-y-3">
              <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">Original Order ID</span>
                <span className="font-mono font-medium">#{order.external_id}</span>
              </div>
              <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">Currency</span>
                <span className="font-medium">{order.currency}</span>
              </div>
            </div>
          </Card>

          {/* Payment Card */}
          <Card className="p-6">
            <h3 className="font-bold text-sm mb-4 flex items-center gap-2">
              <CreditCard size={18} className="text-primary" />
              Payment Details
            </h3>
            <div className="space-y-4">
              <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">Status</span>
                <span className="font-medium capitalize">{order.status}</span>
              </div>
              <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">Total</span>
                <span className="font-bold">{formatCurrency(order.total, order.currency)}</span>
              </div>
              {['paid', 'processing', 'shipped', 'delivered'].includes((order.status || '').toLowerCase()) && (
                <div className="p-3 rounded-xl bg-secondary/5 border border-secondary/10 flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-secondary/20 flex items-center justify-center text-secondary">
                    <CheckCircle2 size={16} />
                  </div>
                  <div>
                    <p className="text-[10px] font-bold text-secondary">Payment recorded</p>
                    <p className="text-[9px] text-muted-foreground">Synced from {order.store?.platform || 'the store'}</p>
                  </div>
                </div>
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
