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
import ConnectStoreModal from '@/components/stores/ConnectStoreModal';

/** Visual treatment for each real store status the backend reports. */
const STATUS_META: Record<string, { label: string; dot: string; text: string }> = {
  connected: { label: 'Connected', dot: 'bg-secondary animate-pulse', text: 'text-secondary' },
  syncing: { label: 'Syncing…', dot: 'bg-warning animate-pulse', text: 'text-warning' },
  error: { label: 'Sync error', dot: 'bg-destructive', text: 'text-destructive' },
  disconnected: { label: 'Disconnected', dot: 'bg-muted-foreground', text: 'text-muted-foreground' },
};

const timeAgo = (iso?: string | null) => {
  if (!iso) return 'Never';
  const secs = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 60) return 'just now';
  const mins = Math.floor(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
};

export default function StoresPage() {
  const [stores, setStores] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [connectPlatform, setConnectPlatform] = useState<PlatformId | null>(null);
  const [oauthEnabled, setOauthEnabled] = useState<Record<string, boolean>>({});

  const fetchStores = async () => {
    try {
      const response = await api.get('/stores');
      setStores(response.data);
    } catch (err) {
      console.error('Failed to fetch stores', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchStores();
    // Which platforms offer one-click OAuth (operator has configured app keys).
    api.get('/stores/connect-options')
      .then((res) => setOauthEnabled(res.data?.oauth_enabled || {}))
      .catch(() => setOauthEnabled({}));
  }, []);

  const toggleMaster = async (id: number) => {
    try {
      await api.post(`/stores/${id}/set-master`);
      fetchStores();
    } catch (err) {
      console.error('Failed to set master store', err);
    }
  };

  const syncStore = async (id: number) => {
    setBusyId(id);
    try {
      await api.post(`/stores/${id}/sync`);
      await fetchStores();
      // Sync runs in the background; re-poll shortly to reflect the result.
      setTimeout(fetchStores, 5000);
    } catch (err) {
      console.error('Failed to sync store', err);
    } finally {
      setBusyId(null);
    }
  };

  const deleteStore = async (id: number) => {
    if (!window.confirm('Disconnect this store? This removes it from your dashboard.')) return;
    try {
      await api.delete(`/stores/${id}`);
      fetchStores();
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
    return <div className="flex items-center justify-center h-full">Loading...</div>;
  }

  return (
    <div className="space-y-8">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Connected Stores</h1>
          <p className="text-muted-foreground text-sm">Manage your multi-channel connections and master sync settings.</p>
        </div>
        <Button variant="primary" size="sm" onClick={() => setConnectPlatform(PLATFORMS[0].id)}>
          <Plus size={16} className="mr-2" />
          Connect a store
        </Button>
      </div>

      {/* Network summary */}
      {summary.total > 0 && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {[
            { label: 'Stores', value: summary.total, tone: 'text-foreground' },
            { label: 'Connected', value: summary.connected, tone: 'text-secondary' },
            { label: 'Syncing', value: summary.syncing, tone: 'text-warning' },
            { label: 'Errors', value: summary.error, tone: 'text-destructive' },
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
          <h3 className="text-lg font-bold">Master Store</h3>
          <p className="text-sm text-muted-foreground mt-1 max-w-2xl">
            Your master store is the central inventory source. When stock changes there, HubbyGlobal automatically
            pushes the update to every other connected store.
          </p>
        </div>
        <div className="shrink-0 text-center md:text-right">
          <p className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider">Last network sync</p>
          <p className="text-sm font-bold flex items-center gap-1.5 mt-1 justify-center md:justify-end">
            <Clock size={14} className="text-primary" />
            {timeAgo(lastSync)}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {stores.map((store) => {
          const PlatformInfo = getPlatform(store.platform);
          const status = store.status || 'disconnected';
          const meta = STATUS_META[status] || STATUS_META.disconnected;
          const reconnectable = status === 'disconnected' || status === 'error';

          return (
            <Card key={store.id} className={cn(
              "p-6 flex flex-col gap-6 relative transition-all",
              store.is_master ? "ring-2 ring-primary border-transparent shadow-2xl shadow-primary/10" : "hover:border-primary/30"
            )}>
              {store.is_master && (
                <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-primary text-white text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-widest shadow-xl flex items-center gap-2">
                  <Crown size={12} />
                  Master Store
                </div>
              )}

              <div className="flex items-start justify-between">
                <div className="flex items-center gap-4">
                  <div className={cn("p-3 rounded-2xl bg-background border border-border shadow-inner", PlatformInfo.color)}>
                    <PlatformInfo.icon size={28} />
                  </div>
                  <div>
                    <h3 className="font-bold text-lg">{store.name}</h3>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className={cn("w-2 h-2 rounded-full", meta.dot)}></span>
                      <span className={cn("text-xs font-medium", meta.text)}>{meta.label}</span>
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
                  <span className="text-muted-foreground flex items-center gap-1.5"><Clock size={12} /> Last synced</span>
                  <span className="font-medium">{timeAgo(store.last_synced_at)}</span>
                </div>
                {status === 'error' && (
                  <div className="flex items-start gap-2 p-3 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive">
                    <AlertCircle size={16} className="shrink-0 mt-0.5" />
                    <p className="text-[10px] font-medium leading-relaxed">
                      Last sync failed — your credentials may have expired. Reconnect to fix it.
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
                    Reconnect
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
                        Set as Master
                      </Button>
                    )}
                    <Button
                      variant="ghost"
                      size="sm"
                      className="w-9 h-9 p-0 rounded-lg"
                      title="Sync now"
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
                  title="Disconnect"
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
            <p.icon size={32} className={cn('group-hover:scale-110 transition-transform', p.color)} />
            <div className="text-center">
              <h4 className="font-bold text-foreground">Add {p.name}</h4>
            </div>
          </button>
        ))}
      </div>

      <ConnectStoreModal
        platformId={connectPlatform}
        oauthEnabled={connectPlatform ? !!oauthEnabled[connectPlatform] : false}
        onClose={() => setConnectPlatform(null)}
        onConnected={fetchStores}
      />
    </div>
  );
}
