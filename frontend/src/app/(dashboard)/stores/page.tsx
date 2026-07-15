'use client';

import React, { useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import {
  RefreshCw,
  Trash2,
  AlertCircle,
  Crown,
  Clock,
  Plus
} from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import { PLATFORMS, getPlatform, type PlatformId } from '@/lib/platforms';
import { PlatformLogo } from '@/components/ui/PlatformLogo';
import ConnectStoreModal from '@/components/stores/ConnectStoreModal';
import { useStores } from '@/components/providers/StoresProvider';
import { useT } from '@/i18n';

/** Visual treatment for each real store status the backend reports. Labels are
 *  looked up at render time via `t('stores.status.<status>')`. */
const STATUS_META: Record<string, { dot: string; text: string }> = {
  connected: { dot: 'bg-secondary animate-pulse', text: 'text-secondary' },
  syncing: { dot: 'bg-warning animate-pulse', text: 'text-warning' },
  error: { dot: 'bg-destructive', text: 'text-destructive' },
  disconnected: { dot: 'bg-muted-foreground', text: 'text-muted-foreground' },
};

const timeAgo = (t: (key: string) => string, iso?: string | null) => {
  if (!iso) return t('stores.time.never');
  const secs = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 60) return t('stores.time.justNow');
  const mins = Math.floor(secs / 60);
  if (mins < 60) return t('stores.time.minutesAgo').replace('{n}', String(mins));
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return t('stores.time.hoursAgo').replace('{n}', String(hrs));
  return t('stores.time.daysAgo').replace('{n}', String(Math.floor(hrs / 24)));
};

export default function StoresPage() {
  // Stores come from the shared provider so connect/disconnect updates the whole
  // dashboard (Orders filter, connect banner, etc.) at once — not just this page.
  const t = useT();
  const { stores, loading: isLoading, refresh } = useStores();
  const [busyId, setBusyId] = useState<number | null>(null);
  const [connectPlatform, setConnectPlatform] = useState<PlatformId | null>(null);
  const [oauthEnabled, setOauthEnabled] = useState<Record<string, boolean>>({});

  useEffect(() => {
    // Re-sync on landing here in case stores changed elsewhere.
    refresh();
    // Which platforms offer one-click OAuth (operator has configured app keys).
    api.get('/stores/connect-options')
      .then((res) => setOauthEnabled(res.data?.oauth_enabled || {}))
      .catch(() => setOauthEnabled({}));
  }, [refresh]);

  const toggleMaster = async (id: number) => {
    try {
      await api.post(`/stores/${id}/set-master`);
      refresh();
    } catch (err) {
      console.error('Failed to set master store', err);
    }
  };

  const syncStore = async (id: number) => {
    setBusyId(id);
    try {
      await api.post(`/stores/${id}/sync`);
      await refresh();
      // Sync runs in the background; re-poll shortly to reflect the result.
      setTimeout(refresh, 5000);
    } catch (err) {
      console.error('Failed to sync store', err);
    } finally {
      setBusyId(null);
    }
  };

  const deleteStore = async (id: number) => {
    if (!window.confirm(t('stores.confirmDisconnect'))) return;
    try {
      await api.delete(`/stores/${id}`);
      refresh();
    } catch (err) {
      console.error('Failed to delete store', err);
    }
  };

  // Network-level summary so the page reads as an operations console, not just a list.
  const summary = {
    total: stores.length,
    connected: stores.filter((s) => s.status === 'connected').length,
    syncing: stores.filter((s) => s.status === 'syncing').length,
    error: stores.filter((s) => s.status === 'error').length,
  };
  const lastSync = stores
    .map((s) => s.last_synced_at)
    .filter(Boolean)
    .sort()
    .pop();

  if (isLoading) {
    return <div className="flex items-center justify-center h-full">{t('stores.loading')}</div>;
  }

  return (
    <div className="space-y-8">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{t('stores.title')}</h1>
          <p className="text-muted-foreground text-sm">{t('stores.subtitle')}</p>
        </div>
        <Button variant="primary" size="sm" onClick={() => setConnectPlatform(PLATFORMS[0].id)}>
          <Plus size={16} className="mr-2" />
          {t('stores.connect')}
        </Button>
      </div>

      {/* Network summary */}
      {summary.total > 0 && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {[
            { label: t('stores.summary.stores'), value: summary.total, tone: 'text-foreground' },
            { label: t('stores.summary.connected'), value: summary.connected, tone: 'text-secondary' },
            { label: t('stores.summary.syncing'), value: summary.syncing, tone: 'text-warning' },
            { label: t('stores.summary.errors'), value: summary.error, tone: 'text-destructive' },
          ].map((s) => (
            <Card key={s.label} className="p-4">
              <p className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider">{s.label}</p>
              <p className={cn('text-2xl font-bold mt-1', s.tone)}>{s.value}</p>
            </Card>
          ))}
        </div>
      )}

      <div className="bg-primary/5 border border-primary/20 rounded-2xl p-6 flex flex-col md:flex-row items-center gap-6 glass">
        <div className="w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center text-primary shrink-0 shadow-inner">
          <Crown size={32} />
        </div>
        <div className="flex-1 text-center md:text-left">
          <h3 className="text-lg font-bold">{t('stores.master.title')}</h3>
          <p className="text-sm text-muted-foreground mt-1 max-w-2xl">
            {t('stores.master.description')}
          </p>
        </div>
        <div className="shrink-0 text-center md:text-right">
          <p className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider">{t('stores.master.lastNetworkSync')}</p>
          <p className="text-sm font-bold flex items-center gap-1.5 mt-1 justify-center md:justify-end">
            <Clock size={14} className="text-primary" />
            {timeAgo(t, lastSync)}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {stores.map((store) => {
          const PlatformInfo = getPlatform(store.platform);
          const status = store.status || 'disconnected';
          const meta = STATUS_META[status] || STATUS_META.disconnected;
          const statusKey = STATUS_META[status] ? status : 'disconnected';
          const reconnectable = status === 'disconnected' || status === 'error';

          return (
            <Card key={store.id} className={cn(
              "p-6 flex flex-col gap-6 relative transition-all",
              store.is_master ? "ring-2 ring-primary border-transparent shadow-2xl shadow-primary/10" : "hover:border-primary/30"
            )}>
              {store.is_master && (
                <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-primary text-white text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-widest shadow-xl flex items-center gap-2">
                  <Crown size={12} />
                  {t('stores.master.badge')}
                </div>
              )}

              <div className="flex items-start justify-between">
                <div className="flex items-center gap-4">
                  <div className="p-3 rounded-2xl bg-background border border-border shadow-inner flex items-center justify-center">
                    <PlatformLogo platform={store.platform} size={28} />
                  </div>
                  <div>
                    <h3 className="font-bold text-lg">{store.name}</h3>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className={cn("w-2 h-2 rounded-full", meta.dot)}></span>
                      <span className={cn("text-xs font-medium", meta.text)}>{t(`stores.status.${statusKey}`)}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div className="space-y-3">
                <div className="flex items-center justify-between text-xs p-3 rounded-xl bg-background/50 border border-border/50">
                  <span className="text-muted-foreground">{PlatformInfo.domainLabel}</span>
                  <span className="font-medium truncate max-w-[60%] text-right">{store.domain || '—'}</span>
                </div>
                <div className="flex items-center justify-between text-xs p-3 rounded-xl bg-background/50 border border-border/50">
                  <span className="text-muted-foreground flex items-center gap-1.5"><Clock size={12} /> {t('stores.card.lastSynced')}</span>
                  <span className="font-medium">{timeAgo(t, store.last_synced_at)}</span>
                </div>
                {status === 'error' && (
                  <div className="flex items-start gap-2 p-3 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive">
                    <AlertCircle size={16} className="shrink-0 mt-0.5" />
                    <p className="text-[10px] font-medium leading-relaxed">
                      {t('stores.card.syncError')}
                    </p>
                  </div>
                )}
              </div>

              <div className="flex items-center gap-2 mt-auto">
                {reconnectable ? (
                  <Button
                    variant="primary"
                    size="sm"
                    className="flex-1 h-9 text-[10px]"
                    onClick={() => setConnectPlatform(store.platform as PlatformId)}
                  >
                    {t('stores.card.reconnect')}
                  </Button>
                ) : (
                  <>
                    {!store.is_master && (
                      <Button
                        variant="outline"
                        size="sm"
                        className="flex-1 text-[10px] h-9"
                        onClick={() => toggleMaster(store.id)}
                      >
                        {t('stores.card.setMaster')}
                      </Button>
                    )}
                    <Button
                      variant="ghost"
                      size="sm"
                      className="w-9 h-9 p-0 rounded-lg"
                      title={t('stores.card.syncNow')}
                      onClick={() => syncStore(store.id)}
                    >
                      <RefreshCw size={14} className={cn(busyId === store.id && 'animate-spin')} />
                    </Button>
                  </>
                )}
                <Button
                  variant="ghost"
                  size="sm"
                  className="w-9 h-9 p-0 rounded-lg hover:text-destructive"
                  title={t('stores.card.disconnect')}
                  onClick={() => deleteStore(store.id)}
                >
                  <Trash2 size={14} />
                </Button>
              </div>
            </Card>
          );
        })}

        {PLATFORMS.map((p) => (
          <button
            key={p.id}
            onClick={() => setConnectPlatform(p.id)}
            className="min-h-[140px] rounded-2xl border-2 border-dashed border-border flex flex-col items-center justify-center gap-4 text-muted-foreground hover:border-primary hover:text-primary transition-all bg-card/10 group"
          >
            <PlatformLogo platform={p.id} size={32} className="group-hover:scale-110 transition-transform" />
            <div className="text-center">
              <h4 className="font-bold text-foreground">{t('stores.add')} {p.name}</h4>
            </div>
          </button>
        ))}
      </div>

      <ConnectStoreModal
        platformId={connectPlatform}
        oauthEnabled={connectPlatform ? !!oauthEnabled[connectPlatform] : false}
        onClose={() => setConnectPlatform(null)}
        onConnected={refresh}
      />
    </div>
  );
}
