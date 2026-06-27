'use client';

import React, { useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { 
  Check, 
  CreditCard, 
  Zap, 
  ShieldCheck, 
  ArrowUpCircle,
  HelpCircle
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';

export default function BillingPage() {
  const [plans, setPlans] = useState<any[]>([]);
  const [subscription, setSubscription] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubscribing, setIsSubscribing] = useState<number | null>(null);
  const [isCancelling, setIsCancelling] = useState(false);

  const handleCancel = async () => {
    if (!window.confirm('Cancel your subscription? You will keep access until the period ends.')) return;
    setIsCancelling(true);
    try {
      await api.post('/billing/cancel');
      await fetchData();
    } catch (err) {
      console.error('Cancel failed', err);
    } finally {
      setIsCancelling(false);
    }
  };

  const fetchData = async () => {
    setIsLoading(true);
    try {
      const [plansRes, statusRes] = await Promise.all([
        api.get('/billing/plans'),
        api.get('/billing/status')
      ]);
      setPlans(plansRes.data);
      setSubscription(statusRes.data.subscription);
    } catch (err) {
      console.error('Failed to fetch billing data', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  const handleSubscribe = async (planId: number) => {
    setIsSubscribing(planId);
    try {
      const res = await api.post('/billing/subscribe', { plan_id: planId });
      // If a payment gateway is configured, hand off to its hosted checkout.
      if (res.data?.checkout_url) {
        window.location.href = res.data.checkout_url;
        return;
      }
      alert('Subscription updated successfully!');
      fetchData();
    } catch (err) {
      console.error('Subscription failed', err);
    } finally {
      setIsSubscribing(null);
    }
  };

  if (isLoading) {
    return <div className="flex items-center justify-center h-full">Loading...</div>;
  }

  return (
    <div className="space-y-10">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Billing & Subscription</h1>
          <p className="text-muted-foreground text-sm">Manage your plan, payment methods, and invoices.</p>
        </div>
        <div className="flex items-center gap-2 text-xs font-bold bg-secondary/10 text-secondary px-4 py-2 rounded-full">
          <ShieldCheck size={16} />
          SECURE PAYMENTS BY EDFAPAY
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <Card className="lg:col-span-2 p-8 flex flex-col md:flex-row gap-8 items-center bg-primary/5 border-primary/20">
          <div className="w-20 h-20 bg-primary/10 rounded-3xl flex items-center justify-center text-primary shrink-0 shadow-inner">
            <Zap size={40} />
          </div>
          <div className="flex-1 text-center md:text-left">
            <h3 className="text-xl font-bold">
              Current Plan: {subscription?.plan?.name || 'Trial'}
            </h3>
            <p className="text-sm text-muted-foreground mt-1">
              {subscription 
                ? `Your next billing date is ${new Date(subscription.ends_at).toLocaleDateString()} for ${formatCurrency(subscription.plan.price)}.`
                : 'You are currently on a free trial.'
              }
            </p>
          </div>
          <div className="shrink-0 space-y-3 w-full md:w-auto">
            {subscription && subscription.status === 'active' ? (
              <Button variant="outline" className="w-full bg-background" isLoading={isCancelling} onClick={handleCancel}>
                Cancel Subscription
              </Button>
            ) : (
              <Button variant="primary" className="w-full" onClick={() => document.getElementById('plans')?.scrollIntoView({ behavior: 'smooth' })}>
                Choose a Plan
              </Button>
            )}
          </div>
        </Card>

        <Card className="p-8 flex flex-col gap-6">
          <div className="flex items-center justify-between">
            <h3 className="font-bold">Payment Method</h3>
            <CreditCard size={20} className="text-muted-foreground" />
          </div>
          <div className="p-4 rounded-xl border border-dashed border-border bg-background flex items-center gap-4">
            <div className="w-10 h-10 bg-accent rounded-lg flex items-center justify-center text-muted-foreground">
              <CreditCard size={18} />
            </div>
            <div>
              <p className="text-sm font-bold">No payment method on file</p>
              <p className="text-[10px] text-muted-foreground">Subscriptions activate instantly during preview.</p>
            </div>
          </div>
          <p className="text-[10px] text-muted-foreground">
            Card payments (EdfaPay) will appear here once configured.
          </p>
        </Card>
      </div>

      <div id="plans" className="space-y-6">
        <div className="text-center">
          <h2 className="text-3xl font-bold">Choose the right plan for you</h2>
          <p className="text-muted-foreground mt-2">Scale your multi-store business with our flexible pricing.</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          {plans.map((plan) => {
            const isCurrent = subscription?.plan_id === plan.id;
            return (
              <Card key={plan.id} className={cn(
                "p-8 flex flex-col gap-8 relative",
                isCurrent ? "border-primary shadow-2xl shadow-primary/10" : "border-border"
              )}>
                {plan.name === 'Pro' && (
                  <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-primary text-white text-[10px] font-bold px-4 py-1 rounded-full uppercase tracking-widest shadow-xl">
                    Recommended
                  </div>
                )}
                
                <div>
                  <h3 className="text-xl font-bold">{plan.name}</h3>
                  <div className="flex items-baseline gap-1 mt-2">
                    <span className="text-4xl font-bold tracking-tight">{formatCurrency(plan.price)}</span>
                    <span className="text-muted-foreground text-sm">/mo</span>
                  </div>
                  <p className="text-xs text-muted-foreground mt-4 leading-relaxed">{plan.description}</p>
                </div>

                <div className="space-y-4">
                  {(Array.isArray(plan.features) && plan.features.length
                    ? plan.features
                    : [
                        `${plan.store_limit ?? 'Unlimited'} stores`,
                        `${plan.order_limit ? plan.order_limit.toLocaleString() : 'Unlimited'} orders / month`,
                      ]
                  ).map((feature: string) => (
                    <div key={feature} className="flex items-center gap-3">
                      <div className="w-5 h-5 rounded-full bg-secondary/10 flex items-center justify-center text-secondary">
                        <Check size={12} />
                      </div>
                      <span className="text-sm font-medium">{feature}</span>
                    </div>
                  ))}
                </div>

                <Button 
                  variant={isCurrent ? 'outline' : 'primary'} 
                  className={cn("mt-auto w-full", isCurrent && "bg-background")}
                  onClick={() => handleSubscribe(plan.id)}
                  isLoading={isSubscribing === plan.id}
                  disabled={isCurrent}
                >
                  {isCurrent ? 'Current Plan' : 'Switch to ' + plan.name}
                </Button>
              </Card>
            );
          })}
        </div>
      </div>

      <Card className="p-8 flex items-center justify-between bg-accent/30 border-dashed">
        <div className="flex items-center gap-4">
          <HelpCircle size={24} className="text-primary" />
          <div>
            <h4 className="font-bold text-sm">Have questions about our pricing?</h4>
            <p className="text-xs text-muted-foreground">Our team is here to help you find the best plan for your needs.</p>
          </div>
        </div>
        <Button variant="ghost" size="sm" className="font-bold">Contact Support</Button>
      </Card>
    </div>
  );
}

