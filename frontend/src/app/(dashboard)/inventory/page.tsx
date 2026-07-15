'use client';

import React, { useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Modal from '@/components/ui/Modal';
import {
  Search, 
  ArrowRight, 
  History, 
  AlertTriangle, 
  CheckCircle2,
  Package,
  ArrowUpRight,
  ArrowDownRight,
  RefreshCw,
  MoreVertical
} from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { useStores } from '@/components/providers/StoresProvider';
import { useToast } from '@/components/ui/Toast';
import ConnectPrompt from '@/components/ui/ConnectPrompt';
import { useT } from '@/i18n';

export default function InventoryPage() {
  const t = useT();
  const { stores, hasConnectedStore, loading: storesLoading } = useStores();
  const { toast } = useToast();
  const [inventory, setInventory] = useState<any[]>([]);
  const [logs, setLogs] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [stockFilter, setStockFilter] = useState<'all' | 'low' | 'out'>('all');

  // Stock-adjustment modal state
  const [adjustItem, setAdjustItem] = useState<any | null>(null);
  const [variantId, setVariantId] = useState<string>('');
  const [change, setChange] = useState<string>('');
  const [reason, setReason] = useState<string>('');
  const [submitting, setSubmitting] = useState(false);
  const [adjustError, setAdjustError] = useState('');

  const openAdjust = (item: any) => {
    setAdjustItem(item);
    setVariantId(item.variants?.[0]?.id ? String(item.variants[0].id) : '');
    setChange('');
    setReason('');
    setAdjustError('');
  };

  const submitAdjust = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!adjustItem) return;
    const delta = parseInt(change, 10);
    if (Number.isNaN(delta) || delta === 0) {
      setAdjustError(t('inventory.nonZeroError'));
      return;
    }
    setSubmitting(true);
    setAdjustError('');
    try {
      await api.post('/inventory/adjust', {
        product_id: adjustItem.id,
        ...(variantId ? { variant_id: Number(variantId) } : {}),
        change: delta,
        reason: reason || 'Manual adjustment',
      });
      setAdjustItem(null);
      await fetchData();
    } catch (err: any) {
      setAdjustError(err.response?.data?.message || t('inventory.adjustFailed'));
    } finally {
      setSubmitting(false);
    }
  };

  const fetchData = async () => {
    setIsLoading(true);
    try {
      const [invRes, logsRes] = await Promise.all([
        api.get('/inventory'),
        api.get('/inventory/logs'),
      ]);
      setInventory(invRes.data);
      setLogs(logsRes.data.data);
    } catch (err) {
      console.error('Failed to fetch inventory data', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  const totalStockOf = (item: any) =>
    item.variants?.reduce((acc: number, v: any) => acc + (v.stock || 0), 0) ?? item.stock ?? 0;

  const filteredInventory = inventory.filter((item) => {
    const matchesSearch =
      item.name.toLowerCase().includes(search.toLowerCase()) ||
      item.sku?.toLowerCase().includes(search.toLowerCase());
    if (!matchesSearch) return false;
    const total = totalStockOf(item);
    if (stockFilter === 'low') return total > 0 && total < 10;
    if (stockFilter === 'out') return total === 0;
    return true;
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-full">{t('inventory.loading')}</div>;
  }

  return (
    <div className="space-y-8">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{t('inventory.title')}</h1>
          <p className="text-muted-foreground text-sm">{t('inventory.subtitle')}</p>
        </div>
        <div className="flex items-center gap-3">
          <Button variant="outline" size="sm">
            <History size={16} className="mr-2" />
            {t('inventory.stockHistory')}
          </Button>
          <Button
            variant="primary"
            size="sm"
            onClick={async () => {
              if (!hasConnectedStore) {
                toast(t('inventory.connectFirst'), 'info');
                return;
              }
              try {
                await api.post('/stores/sync-all');
                toast(t('inventory.syncStarted'), 'success');
              } catch {
                toast(t('inventory.syncFailed'), 'error');
              }
            }}
          >
            <RefreshCw size={16} className="mr-2" />
            {t('inventory.pushGlobalSync')}
          </Button>
        </div>
      </div>

      {!storesLoading && !hasConnectedStore ? (
        <ConnectPrompt description={t('inventory.connectDescription')} />
      ) : (
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2 space-y-6">
          <Card className="p-0 overflow-hidden">
            <div className="p-4 border-b border-border bg-card/30 flex items-center justify-between">
              <div className="relative w-full max-w-sm">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
                <Input
                  placeholder={t('inventory.searchPlaceholder')}
                  className="pl-10"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
              <div className="flex gap-2">
                {([
                  { k: 'all', label: t('inventory.filterAll') },
                  { k: 'low', label: t('inventory.filterLow') },
                  { k: 'out', label: t('inventory.filterOut') },
                ] as const).map((f) => (
                  <button
                    key={f.k}
                    onClick={() => setStockFilter(f.k)}
                    className={cn(
                      'px-3 py-1.5 text-xs rounded-lg border font-medium transition-all',
                      stockFilter === f.k
                        ? 'bg-primary text-white border-primary'
                        : 'bg-background border-border hover:bg-accent'
                    )}
                  >
                    {f.label}
                  </button>
                ))}
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="bg-accent/50 text-muted-foreground text-[10px] uppercase font-bold tracking-wider">
                    <th className="px-6 py-4">{t('inventory.colProduct')}</th>
                    <th className="px-6 py-4">{t('inventory.colStock')}</th>
                    <th className="px-6 py-4">{t('inventory.colStores')}</th>
                    <th className="px-6 py-4">{t('inventory.colStatus')}</th>
                    <th className="px-6 py-4 text-right">{t('inventory.colActions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {filteredInventory.map((item) => {
                    const totalStock = item.variants?.reduce((acc: number, v: any) => acc + (v.stock || 0), 0) || item.stock || 0;
                    const isLow = totalStock < 10;
                    const isOut = totalStock === 0;

                    return (
                      <tr key={item.id} className="hover:bg-accent/30 transition-all cursor-pointer">
                        <td className="px-6 py-4">
                          <div className="flex flex-col">
                            <span className="text-sm font-semibold">{item.name}</span>
                            <span className="text-[10px] font-mono text-primary font-bold">{item.sku || t('inventory.noSku')}</span>
                          </div>
                        </td>
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2">
                            <span className={cn(
                              "text-lg font-bold",
                              isOut ? "text-destructive" : isLow ? "text-warning" : "text-foreground"
                            )}>
                              {totalStock}
                            </span>
                            <span className="text-[10px] text-muted-foreground font-medium uppercase tracking-tight">{t('inventory.units')}</span>
                          </div>
                        </td>
                        <td className="px-6 py-4 text-xs font-medium">
                          {item.stores?.length || 0} {t('inventory.connected')}
                        </td>
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2">
                            {isOut ? (
                              <AlertTriangle size={14} className="text-destructive" />
                            ) : isLow ? (
                              <AlertTriangle size={14} className="text-warning" />
                            ) : (
                              <CheckCircle2 size={14} className="text-secondary" />
                            )}
                            <span className={cn(
                              "text-[10px] font-bold uppercase tracking-widest",
                              isOut ? "text-destructive" : isLow ? "text-warning" : "text-secondary"
                            )}>
                              {isOut ? t('inventory.statusOut') : isLow ? t('inventory.statusLow') : t('inventory.statusInSync')}
                            </span>
                          </div>
                        </td>
                        <td className="px-6 py-4 text-right">
                          <Button
                            variant="outline"
                            size="xs"
                            onClick={() => openAdjust(item)}
                          >
                            {t('inventory.adjust')}
                          </Button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card className="p-6 flex flex-col gap-6">
            <h3 className="font-bold text-lg flex items-center gap-2">
              <History size={20} className="text-primary" />
              {t('inventory.recentAdjustments')}
            </h3>
            <div className="space-y-4">
              {logs.map((log) => (
                <div key={log.id} className="flex items-center justify-between p-4 rounded-xl bg-background border border-border group hover:border-primary/30 transition-all">
                  <div className="flex items-center gap-4">
                    <div className={cn(
                      "w-10 h-10 rounded-full flex items-center justify-center border",
                      log.change > 0 ? "bg-secondary/10 border-secondary/30 text-secondary" : "bg-destructive/10 border-destructive/30 text-destructive"
                    )}>
                      {log.change > 0 ? <ArrowUpRight size={20} /> : <ArrowDownRight size={20} />}
                    </div>
                    <div>
                      <p className="text-sm font-bold">{log.product?.name || t('inventory.unknownProduct')}</p>
                      <p className="text-[10px] text-muted-foreground">{log.source} • {new Date(log.created_at).toLocaleDateString()}</p>
                    </div>
                  </div>
                  <span className={cn(
                    "text-sm font-bold",
                    log.change > 0 ? "text-secondary" : "text-destructive"
                  )}>
                    {log.change > 0 ? `+${log.change}` : log.change}
                  </span>
                </div>
              ))}
            </div>
            <Button variant="ghost" size="sm" className="w-full text-xs font-bold">{t('inventory.viewFullAuditLog')}</Button>
          </Card>

          <Card className="p-6 bg-primary/5 border-primary/20 space-y-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center text-primary">
                <RefreshCw size={20} />
              </div>
              <h4 className="font-bold text-sm">{t('inventory.automaticSyncing')}</h4>
            </div>
            <p className="text-xs text-muted-foreground leading-relaxed">
              {t('inventory.automaticSyncingDesc')}
            </p>
            {(() => {
              const connected = stores.filter((s) => s.status === 'connected').length;
              const pct = stores.length ? Math.round((connected / stores.length) * 100) : 0;
              return (
                <div className="pt-2">
                  <div className="flex items-center justify-between text-[10px] font-bold uppercase tracking-widest text-muted-foreground mb-2">
                    <span>{t('inventory.storesConnected')}</span>
                    <span className="text-secondary">{connected}/{stores.length}</span>
                  </div>
                  <div className="w-full bg-accent h-1.5 rounded-full overflow-hidden">
                    <div className="bg-secondary h-full transition-all" style={{ width: `${pct}%` }} />
                  </div>
                </div>
              );
            })()}
          </Card>
        </div>
      </div>
      )}

      <Modal
        isOpen={!!adjustItem}
        onClose={() => setAdjustItem(null)}
        title={`${t('inventory.adjustStock')} — ${adjustItem?.name ?? ''}`}
        size="sm"
      >
        <form onSubmit={submitAdjust} className="space-y-5">
          {adjustError && (
            <div className="rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
              {adjustError}
            </div>
          )}

          {adjustItem?.variants?.length > 0 && (
            <div className="space-y-1.5 w-full">
              <label className="text-xs font-medium text-muted-foreground ml-1">{t('inventory.variant')}</label>
              <select
                value={variantId}
                onChange={(e) => setVariantId(e.target.value)}
                className="w-full bg-background border border-border rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
              >
                {adjustItem.variants.map((v: any) => (
                  <option key={v.id} value={v.id}>
                    {(v.name || v.sku || `${t('inventory.variantFallback')} ${v.id}`)} — {v.stock ?? 0} {t('inventory.inStock')}
                  </option>
                ))}
              </select>
            </div>
          )}

          <Input
            label={t('inventory.changeLabel')}
            type="number"
            placeholder={t('inventory.changePlaceholder')}
            value={change}
            onChange={(e) => setChange(e.target.value)}
            required
          />

          <Input
            label={t('inventory.reasonLabel')}
            placeholder={t('inventory.reasonPlaceholder')}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />

          <div className="flex justify-end gap-3 pt-2">
            <Button type="button" variant="ghost" onClick={() => setAdjustItem(null)}>
              {t('inventory.cancel')}
            </Button>
            <Button type="submit" isLoading={submitting}>
              {t('inventory.applyAdjustment')}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

