'use client';
import { useRouter } from 'next/navigation';
import React, { useEffect, useState } from 'react';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { 
  Search, 
  Plus, 
  MoreVertical,
  Layers,
  Image as ImageIcon,
  Tag,
  Box,
  RefreshCw,
  Edit2,
  Trash2
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';

import Modal from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { useStores } from '@/components/providers/StoresProvider';
import ConnectPrompt from '@/components/ui/ConnectPrompt';

export default function ProductsPage() {
  const router = useRouter();
  const { toast } = useToast();
  const { hasConnectedStore } = useStores();
  const [products, setProducts] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [isSyncing, setIsSyncing] = useState(false);
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [categories, setCategories] = useState<any[]>([]);
  const [isCategoryModalOpen, setIsCategoryModalOpen] = useState(false);
  const [newCategoryName, setNewCategoryName] = useState('');
  const [isCreatingCategory, setIsCreatingCategory] = useState(false);

  const fetchProducts = async () => {
    setIsLoading(true);
    try {
      const params: any = { per_page: 50 };
      if (search) params.search = search;
      
      const [productsRes, categoriesRes] = await Promise.all([
        api.get('/products', { params }),
        api.get('/categories')
      ]);
      setProducts(productsRes.data.data);
      setCategories(categoriesRes.data);
    } catch (err) {
      console.error('Failed to fetch data', err);
    } finally {
      setIsLoading(false);
    }
  };

  const handleSync = async () => {
    if (!hasConnectedStore) {
      toast('Connect a store first — there’s nowhere to sync products to yet.', 'info');
      return;
    }
    setIsSyncing(true);
    try {
      // Pass selected ids when any are checked (otherwise syncs all).
      await api.post('/products/sync', selectedIds.length ? { product_ids: selectedIds } : {});
      toast('Sync started — products will update in the background.', 'success');
    } catch (err) {
      console.error('Sync failed', err);
      toast('Sync failed. Please try again.', 'error');
    } finally {
      setIsSyncing(false);
    }
  };

  const handleDelete = async () => {
    if (!deleteId) return;
    try {
      await api.delete(`/products/${deleteId}`);
      setProducts(products.filter(p => p.id !== deleteId));
      setSelectedIds(selectedIds.filter(id => id !== deleteId));
      setDeleteId(null);
    } catch (err) {
      console.error('Delete failed', err);
      toast('Failed to delete product.', 'error');
    }
  };

  const handleBulkDelete = async () => {
    if (!confirm(`Are you sure you want to delete ${selectedIds.length} products?`)) return;
    try {
      // For now, we delete one by one if backend doesn't support bulk delete
      await Promise.all(selectedIds.map(id => api.delete(`/products/${id}`)));
      setProducts(products.filter(p => !selectedIds.includes(p.id)));
      setSelectedIds([]);
      toast('Selected products deleted.', 'success');
    } catch (err) {
      console.error('Bulk delete failed', err);
      toast('Bulk delete failed.', 'error');
    }
  };

  const toggleSelectAll = () => {
    if (selectedIds.length === products.length) {
      setSelectedIds([]);
    } else {
      setSelectedIds(products.map(p => p.id));
    }
  };

  const toggleSelect = (id: number) => {
    if (selectedIds.includes(id)) {
      setSelectedIds(selectedIds.filter(sid => sid !== id));
    } else {
      setSelectedIds([...selectedIds, id]);
    }
  };

  const handleCreateCategory = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newCategoryName) return;
    setIsCreatingCategory(true);
    try {
      const response = await api.post('/categories', { name: newCategoryName });
      setCategories([...categories, response.data]);
      setNewCategoryName('');
    } catch (err) {
      console.error('Failed to create category', err);
    } finally {
      setIsCreatingCategory(false);
    }
  };

  const handleDeleteCategory = async (id: number) => {
    try {
      await api.delete(`/categories/${id}`);
      setCategories(categories.filter(c => c.id !== id));
    } catch (err) {
      console.error('Failed to delete category', err);
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => {
      fetchProducts();
    }, 500);
    return () => clearTimeout(timer);
  }, [search]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Products</h1>
          <p className="text-muted-foreground text-sm">Centralized product and inventory management.</p>
        </div>
        <div className="flex items-center gap-3">
          <Button 
            variant="outline" 
            size="sm" 
            onClick={handleSync}
            isLoading={isSyncing}
          >
            <RefreshCw size={16} className={cn("mr-2", isSyncing && "animate-spin")} />
            Sync Products
          </Button>
          <Button 
            variant="primary" 
            size="sm"
            onClick={() => router.push('/products/new')}
          >
            <Plus size={16} className="mr-2" />
            Add Global Product
          </Button>
        </div>
      </div>

      <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
        <div className="relative w-full md:w-96">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={18} />
          <Input 
            placeholder="Search products by name or SKU..." 
            className="pl-10" 
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="sm" className="text-xs" onClick={() => {router.push('/categories')}}>
            <Tag size={14} className="mr-2" />
            Categories ({categories.length})
          </Button>
          <div className="relative group">
            <Button variant="ghost" size="sm" className={cn("text-xs", selectedIds.length > 0 && "bg-primary/10 text-primary")}>
              <Layers size={14} className="mr-2" />
              {selectedIds.length > 0 ? `${selectedIds.length} Selected` : 'Bulk Actions'}
            </Button>
            {selectedIds.length > 0 && (
              <div className="absolute top-full right-0 mt-2 w-48 bg-card border border-border rounded-xl shadow-xl z-20 overflow-hidden">
                <button 
                  onClick={handleSync}
                  className="w-full px-4 py-3 text-xs text-left hover:bg-accent flex items-center gap-2"
                >
                  <RefreshCw size={12} /> Sync Selected
                </button>
                <button 
                  onClick={handleBulkDelete}
                  className="w-full px-4 py-3 text-xs text-left hover:bg-destructive/10 text-destructive flex items-center gap-2"
                >
                  <Trash2 size={12} /> Delete Selected
                </button>
                <button 
                  onClick={() => setSelectedIds([])}
                  className="w-full px-4 py-3 text-xs text-left border-t border-border hover:bg-accent"
                >
                  Clear Selection
                </button>
              </div>
            )}
          </div>
        </div>
      </div>

      {selectedIds.length > 0 && (
        <div className="bg-primary/10 border border-primary/20 p-3 rounded-xl flex items-center justify-between">
          <p className="text-xs font-bold text-primary">{selectedIds.length} products selected for bulk operation.</p>
          <div className="flex items-center gap-2">
            <Button size="xs" variant="outline" onClick={toggleSelectAll}>
              {selectedIds.length === products.length ? 'Deselect All' : 'Select All'}
            </Button>
            <Button size="xs" variant="primary" onClick={handleBulkDelete}>Delete All</Button>
          </div>
        </div>
      )}

      {!isLoading && !hasConnectedStore && products.length === 0 ? (
        <ConnectPrompt description="Connect a store to sync your products, or add a global product manually." />
      ) : (
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        {isLoading ? (
          <div className="col-span-full py-20 text-center">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto mb-4"></div>
            <p className="text-muted-foreground">Loading products...</p>
          </div>
        ) : (
          <>
            {products.map((product) => (
              <Card 
                key={product.id} 
                className="p-0 overflow-hidden flex flex-col group hover:border-primary/30 transition-all cursor-pointer"
                onClick={() => router.push(`/products/${product.id}`)}
              >
                <div className="aspect-square bg-accent/30 flex items-center justify-center relative overflow-hidden">
                  <div 
                    className="absolute top-3 left-3 z-10"
                    onClick={(e) => {
                      e.stopPropagation();
                      toggleSelect(product.id);
                    }}
                  >
                    <div className={cn(
                      "w-5 h-5 rounded border-2 flex items-center justify-center transition-all",
                      selectedIds.includes(product.id) ? "bg-primary border-primary" : "bg-card border-muted-foreground/40"
                    )}>
                      {selectedIds.includes(product.id) && <Plus size={12} className="text-white rotate-45" />}
                    </div>
                  </div>
                  {product.image_url ? (
                    <img src={product.image_url} alt={product.name} className="object-cover w-full h-full transition-transform duration-500 group-hover:scale-110" />
                  ) : (
                    <ImageIcon size={48} className="text-muted-foreground/20" />
                  )}
                  {product.stock === 0 && (
                    <div className="absolute top-3 left-4 bg-destructive text-white text-[10px] font-bold px-2 py-1 rounded-full uppercase tracking-wider shadow-lg">
                      Out of Stock
                    </div>
                  )}
                  <div className="absolute top-3 right-3 flex flex-col gap-2 opacity-0 group-hover:opacity-100 transition-all translate-x-4 group-hover:translate-x-0">
                    <button 
                      className="p-2 bg-background rounded-full border border-border hover:bg-primary hover:text-white hover:border-primary transition-all shadow-xl"
                      onClick={(e) => {
                        e.stopPropagation();
                        router.push(`/products/${product.id}/edit`);
                      }}
                    >
                      <Edit2 size={14} />
                    </button>
                    <button 
                      className="p-2 bg-background rounded-full border border-border hover:bg-destructive hover:text-white hover:border-destructive transition-all shadow-xl"
                      onClick={(e) => {
                        e.stopPropagation();
                        setDeleteId(product.id);
                      }}
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                </div>
                
                <div className="p-4 flex-1 flex flex-col gap-3">
                  <div>
                    <p className="text-[10px] font-mono font-bold text-primary uppercase tracking-widest mb-1">{product.sku}</p>
                    <h3 className="font-semibold text-sm leading-tight line-clamp-2">{product.name}</h3>
                  </div>

                  <div className="flex items-center justify-between mt-auto pt-2 border-t border-border/50">
                    <div className="flex flex-col">
                      <span className="text-[10px] text-muted-foreground font-medium uppercase">Price</span>
                      <span className="font-bold">{formatCurrency(product.price)}</span>
                    </div>
                    <div className="flex flex-col text-right">
                      <span className="text-[10px] text-muted-foreground font-medium uppercase">Stock</span>
                      <span className={cn(
                        "font-bold",
                        product.stock <= 15 ? "text-warning" : "text-foreground"
                      )}>{product.stock}</span>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 mt-1">
                    <div className="flex -space-x-2">
                      {product.stores?.map((store: any, i: number) => (
                        <div key={i} className="w-5 h-5 rounded-full bg-accent border-2 border-card flex items-center justify-center overflow-hidden" title={store.name}>
                          <Box size={10} className="text-primary" />
                        </div>
                      ))}
                    </div>
                    <span className="text-[10px] text-muted-foreground font-medium">
                      {product.stores?.length || 0} Connected Stores
                    </span>
                  </div>
                </div>
              </Card>
            ))}

            <button 
              onClick={() => router.push('/products/new')}
              className="aspect-square rounded-2xl border-2 border-dashed border-border flex flex-col items-center justify-center gap-3 text-muted-foreground hover:border-primary hover:text-primary transition-all bg-card/10 group"
            >
              <div className="w-12 h-12 rounded-full bg-accent flex items-center justify-center group-hover:scale-110 transition-transform">
                <Plus size={24} />
              </div>
              <span className="text-sm font-medium">Add New Product</span>
            </button>
          </>
        )}
      </div>
      )}
      <Modal
        isOpen={isCategoryModalOpen}
        onClose={() => setIsCategoryModalOpen(false)}
        title="Manage Categories"
      >
        <div className="space-y-6">
          <form onSubmit={handleCreateCategory} className="flex gap-2">
            <Input 
              placeholder="Category name..." 
              value={newCategoryName}
              onChange={(e) => setNewCategoryName(e.target.value)}
              className="flex-1"
            />
            <Button type="submit" isLoading={isCreatingCategory}>Add</Button>
          </form>

          <div className="space-y-2">
            {categories.map((category) => (
              <div key={category.id} className="flex items-center justify-between p-3 rounded-xl bg-accent/20 border border-border">
                <div className="flex items-center gap-3">
                  <Tag size={14} className="text-primary" />
                  <span className="text-sm font-medium">{category.name}</span>
                </div>
                <button 
                  onClick={() => handleDeleteCategory(category.id)}
                  className="p-1.5 text-muted-foreground hover:text-destructive hover:bg-destructive/10 rounded-lg transition-all"
                >
                  <Trash2 size={14} />
                </button>
              </div>
            ))}
            {categories.length === 0 && (
              <p className="text-center py-6 text-sm text-muted-foreground">No categories defined yet.</p>
            )}
          </div>
          
          <div className="flex justify-end pt-4 border-t border-border">
            <Button variant="outline" onClick={() => setIsCategoryModalOpen(false)}>Done</Button>
          </div>
        </div>
      </Modal>

      <Modal 
        isOpen={!!deleteId} 
        onClose={() => setDeleteId(null)}
        title="Delete Product"
      >
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Are you sure you want to delete this product? This will remove the global mapping, but platform-specific products will remain on their respective stores.
          </p>
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setDeleteId(null)}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete}>Delete Product</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

