'use client';

import React, { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { 
  ChevronLeft, 
  Edit2, 
  Trash2, 
  Package, 
  Tag, 
  Box, 
  Layers,
  ArrowUpRight,
  Store,
  CheckCircle2,
  AlertCircle,
  BarChart3,
  Image as ImageIcon
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Money } from '@/components/ui/Money';
import api from '@/lib/api';
import Modal from '@/components/ui/Modal';
import { useT } from '@/i18n';

export default function ProductDetailsPage() {
  const t = useT();
  const { productId } = useParams();
  const router = useRouter();
  const [product, setProduct] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchProduct = async () => {
    setIsLoading(true);
    try {
      const response = await api.get(`/products/${productId}`);
      setProduct(response.data);
    } catch (err) {
      console.error('Failed to fetch product', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchProduct();
  }, [productId]);

  const handleDelete = async () => {
    try {
      await api.delete(`/products/${productId}`);
      router.push('/products');
    } catch (err) {
      console.error('Delete failed', err);
      alert(t('products.detail.alertDeleteFailed'));
    }
  };

  const [isSyncingStore, setIsSyncingStore] = useState<number | null>(null);

  const handleToggleSync = async (platformProductId: number) => {
    setIsSyncingStore(platformProductId);
    try {
      const response = await api.post(`/platform-products/${platformProductId}/toggle-sync`);
      setProduct({
        ...product,
        platform_products: product.platform_products.map((pp: any) => 
          pp.id === platformProductId ? { ...pp, sync_enabled: response.data.sync_enabled } : pp
        )
      });
    } catch (err) {
      console.error('Toggle sync failed', err);
      alert(t('products.detail.alertSyncFailed'));
    } finally {
      setIsSyncingStore(null);
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!product) {
    return (
      <div className="text-center py-20">
        <h2 className="text-xl font-bold">{t('products.detail.notFound')}</h2>
        <Button onClick={() => router.push('/products')} className="mt-4">
          {t('products.detail.backToProducts')}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-20">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <button 
            onClick={() => router.push('/products')}
            className="p-2 hover:bg-accent rounded-full transition-all"
          >
            <ChevronLeft size={20} />
          </button>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-[10px] font-mono font-bold text-primary uppercase tracking-widest">{product.sku}</span>
              <span className="w-1.5 h-1.5 rounded-full bg-border"></span>
              <span className="text-[10px] text-muted-foreground font-bold uppercase tracking-widest">{t('products.detail.globalProduct')}</span>
            </div>
            <h1 className="text-2xl font-bold mt-1">{product.name}</h1>
            {product.category && (
              <div className="flex items-center gap-1.5 mt-1">
                <Tag size={12} className="text-primary" />
                <span className="text-xs font-medium text-muted-foreground">{product.category.name}</span>
              </div>
            )}
          </div>
        </div>
        <div className="flex items-center gap-3">
          <Button variant="outline" size="sm" onClick={() => router.push(`/products/${productId}/edit`)}>
            <Edit2 size={16} className="mr-2" />
            {t('products.detail.editProduct')}
          </Button>
          <Button variant="destructive" size="sm" onClick={() => setIsDeleting(true)}>
            <Trash2 size={16} className="mr-2" />
            {t('products.detail.delete')}
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          {/* Main Info */}
          <Card className="p-0 overflow-hidden">
            <div className="grid grid-cols-1 md:grid-cols-5">
              <div className="md:col-span-2 bg-accent/30 aspect-square flex items-center justify-center border-b md:border-b-0 md:border-r border-border">
                {product.image_url ? (
                  <img src={product.image_url} alt={product.name} className="object-contain w-full h-full p-4" />
                ) : (
                  <ImageIcon size={64} className="text-muted-foreground/20" />
                )}
              </div>
              <div className="md:col-span-3 p-6 space-y-6">
                <div>
                  <h3 className="font-bold text-sm mb-2">{t('products.detail.description')}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed whitespace-pre-wrap">
                    {product.description || t('products.detail.noDescription')}
                  </p>
                </div>

                <div className="grid grid-cols-2 gap-6">
                  <div>
                    <h3 className="font-bold text-sm mb-2">{t('products.detail.price')}</h3>
                    <p className="text-2xl font-bold text-secondary"><Money amount={product.price} /></p>
                  </div>
                  <div>
                    <h3 className="font-bold text-sm mb-2">{t('products.detail.totalStock')}</h3>
                    <div className="flex items-center gap-2">
                      <p className={cn(
                        "text-2xl font-bold",
                        product.stock <= 15 ? "text-warning" : "text-foreground"
                      )}>{product.stock}</p>
                      <span className="text-[10px] font-bold text-muted-foreground uppercase bg-accent px-2 py-0.5 rounded">
                        {product.stock > 15 ? t('products.detail.healthy') : t('products.detail.lowStock')}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </Card>

          {/* Connected Stores */}
          <Card className="p-6">
            <div className="flex items-center justify-between mb-6">
              <h3 className="font-bold text-sm flex items-center gap-2">
                <Store size={18} className="text-primary" />
                {t('products.detail.linkedPlatformProducts')}
              </h3>
              <span className="text-[10px] font-bold text-muted-foreground uppercase bg-accent px-2 py-0.5 rounded">
                {product.platform_products?.length || 0} {t('products.detail.storesSuffix')}
              </span>
            </div>
            <div className="space-y-4">
              {product.platform_products?.map((pp: any) => (
                <div key={pp.id} className="p-4 rounded-2xl bg-accent/20 border border-border flex items-center justify-between group hover:border-primary/30 transition-all">
                  <div className="flex items-center gap-4">
                    <div className="w-10 h-10 rounded-xl bg-card border border-border flex items-center justify-center shadow-sm">
                      <Box size={20} className="text-primary" />
                    </div>
                    <div>
                      <p className="text-xs font-bold">{pp.store?.name}</p>
                      <div className="flex items-center gap-2 mt-0.5">
                        <p className="text-[10px] text-muted-foreground uppercase tracking-wider">
                          {pp.store?.platform} • ID: {pp.external_id}
                        </p>
                        <span className={cn(
                          "text-[8px] font-bold px-1.5 py-0.5 rounded-full uppercase",
                          pp.sync_enabled ? "bg-green-500/10 text-green-500" : "bg-muted text-muted-foreground"
                        )}>
                          {pp.sync_enabled ? t('products.detail.syncOn') : t('products.detail.syncOff')}
                        </span>
                      </div>
                    </div>
                  </div>
                  <div className="flex items-center gap-4">
                    <div className="text-right">
                      <p className="text-xs font-bold"><Money amount={pp.price || product.price} /></p>
                      <p className="text-[10px] text-muted-foreground">{t('products.detail.platformPrice')}</p>
                    </div>
                    <div className="flex items-center gap-2">
                      <button 
                        onClick={() => handleToggleSync(pp.id)}
                        disabled={isSyncingStore === pp.id}
                        className={cn(
                          "w-10 h-5 rounded-full relative transition-all duration-300 outline-none",
                          pp.sync_enabled ? "bg-primary" : "bg-muted"
                        )}
                        title={pp.sync_enabled ? t('products.detail.disableSync') : t('products.detail.enableSync')}
                      >
                        <div className={cn(
                          "absolute top-1 left-1 w-3 h-3 bg-white rounded-full transition-all duration-300",
                          pp.sync_enabled && "left-6",
                          isSyncingStore === pp.id && "animate-pulse"
                        )} />
                      </button>
                      <button className="p-2 hover:bg-card rounded-lg text-muted-foreground hover:text-primary transition-all">
                        <ArrowUpRight size={16} />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
              {(!product.platform_products || product.platform_products.length === 0) && (
                <div className="text-center py-10 bg-accent/10 rounded-2xl border border-dashed border-border">
                  <p className="text-xs text-muted-foreground">{t('products.detail.notLinked')}</p>
                  <Button variant="ghost" size="sm" className="mt-2 text-primary">{t('products.detail.linkToStore')}</Button>
                </div>
              )}
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          {/* Inventory Insights */}
          <Card className="p-6">
            <h3 className="font-bold text-sm mb-4 flex items-center gap-2">
              <BarChart3 size={18} className="text-primary" />
              {t('products.detail.inventoryInsights')}
            </h3>
            {(() => {
              const totalStock = (product.variants || []).reduce((acc: number, v: any) => acc + (v.stock || 0), 0);
              const variantCount = product.variants?.length || 0;
              const storeCount = new Set((product.platform_products || []).map((pp: any) => pp.store_id)).size;
              const status = totalStock === 0 ? t('products.detail.statusOutOfStock') : totalStock < 10 ? t('products.detail.statusLowStock') : t('products.detail.statusInStock');
              const statusColor = totalStock === 0 ? 'text-destructive' : totalStock < 10 ? 'text-warning' : 'text-secondary';
              return (
                <div className="space-y-4">
                  <div className="p-4 rounded-2xl bg-accent/30 border border-border">
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-[10px] font-bold text-muted-foreground uppercase tracking-wider">{t('products.detail.totalStock')}</span>
                      <span className={cn('text-xs font-bold', statusColor)}>{status}</span>
                    </div>
                    <p className="text-2xl font-bold">{totalStock}<span className="text-xs font-medium text-muted-foreground ml-1">{t('products.detail.units')}</span></p>
                  </div>
                  <div className="space-y-3 pt-2">
                    <div className="flex items-center justify-between text-xs">
                      <span className="text-muted-foreground">{t('products.detail.variants')}</span>
                      <span className="font-bold">{variantCount}</span>
                    </div>
                    <div className="flex items-center justify-between text-xs">
                      <span className="text-muted-foreground">{t('products.detail.linkedStores')}</span>
                      <span className="font-bold">{storeCount}</span>
                    </div>
                  </div>
                </div>
              );
            })()}
          </Card>

          {/* Variants */}
          <Card className="p-6">
            <h3 className="font-bold text-sm mb-4 flex items-center gap-2">
              <Layers size={18} className="text-primary" />
              {t('products.detail.variants')}
            </h3>
            <div className="space-y-3">
              {product.variants?.map((variant: any) => (
                <div key={variant.id} className="flex items-center justify-between p-3 rounded-xl border border-border hover:bg-accent/30 transition-all">
                  <div>
                    <p className="text-xs font-bold">{variant.name}</p>
                    <p className="text-[10px] text-muted-foreground font-mono uppercase mt-0.5">{variant.sku}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-xs font-bold"><Money amount={variant.price} /></p>
                    <p className="text-[10px] text-muted-foreground">{variant.stock} {t('products.detail.inStockSuffix')}</p>
                  </div>
                </div>
              ))}
              {(!product.variants || product.variants.length === 0) && (
                <p className="text-xs text-muted-foreground text-center py-4">{t('products.detail.noVariants')}</p>
              )}
              <Button
                variant="outline"
                size="sm"
                className="w-full mt-2"
                onClick={() => router.push(`/products/${productId}/edit`)}
              >
                {t('products.detail.manageVariants')}
              </Button>
            </div>
          </Card>
        </div>
      </div>

      <Modal 
        isOpen={isDeleting} 
        onClose={() => setIsDeleting(false)}
        title={t('products.detail.deleteGlobalProduct')}
      >
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">
            {t('products.detail.deleteBodyPrefix')} <strong>{product.name}</strong>{t('products.detail.deleteBodySuffix')}
          </p>
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setIsDeleting(false)}>{t('products.detail.cancel')}</Button>
            <Button variant="destructive" onClick={handleDelete}>{t('products.detail.deletePermanently')}</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
