'use client';

import React, { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { 
  ChevronLeft, 
  Save, 
  Image as ImageIcon,
  Package,
  DollarSign,
  Box,
  Layout,
  Plus,
  Trash2,
  Store,
  Check
} from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';

export default function NewProductPage() {
  const router = useRouter();
  const [isLoading, setIsLoading] = useState(false);
  const [formData, setFormData] = useState<any>({
    name: '',
    sku: '',
    price: '',
    stock: '0',
    description: '',
    image_url: '',
    category_id: '',
    variants: []
  });

  const [stores, setStores] = useState<any[]>([]);
  const [categories, setCategories] = useState<any[]>([]);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [storesRes, categoriesRes] = await Promise.all([
          api.get('/stores'),
          api.get('/categories')
        ]);
        setStores(storesRes.data);
        setCategories(categoriesRes.data);
      } catch (err) {
        console.error('Failed to fetch data', err);
      }
    };
    fetchData();
  }, []);

  // Helper to render options with indentation
  const renderCategoryOptions = (cats: any[], parentId: number | null = null, depth = 0): React.ReactNode[] => {
    const children = cats.filter(c => c.parent_id === parentId);
    let options: React.ReactNode[] = [];
    
    children.forEach(child => {
      options.push(
        <option key={child.id} value={child.id}>
          {'\u00A0\u00A0'.repeat(depth)}{depth > 0 ? '↳ ' : ''}{child.name}
        </option>
      );
      options = options.concat(renderCategoryOptions(cats, child.id, depth + 1));
    });
    
    return options;
  };

  const addVariant = () => {
    setFormData({
      ...formData,
      variants: [
        ...formData.variants,
        { name: '', sku: '', price: formData.price || '', stock: '0' }
      ]
    });
  };

  const updateVariant = (index: number, field: string, value: string) => {
    const newVariants = [...formData.variants];
    newVariants[index] = { ...newVariants[index], [field]: value };
    setFormData({ ...formData, variants: newVariants });
  };

  const removeVariant = (index: number) => {
    setFormData({
      ...formData,
      variants: formData.variants.filter((_: any, i: number) => i !== index)
    });
  };

  const [isUploadingImage, setIsUploadingImage] = useState(false);
  const fileInputRef = React.useRef<HTMLInputElement>(null);

  const handleImageUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setIsUploadingImage(true);
    const formDataUpload = new FormData();
    formDataUpload.append('image', file);

    try {
      const response = await api.post('/products/upload', formDataUpload, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
      setFormData({ ...formData, image_url: response.data.url });
    } catch (err) {
      console.error('Upload failed', err);
      alert('Failed to upload image.');
    } finally {
      setIsUploadingImage(false);
    }
  };

  const [selectedStores, setSelectedStores] = useState<number[]>([]);

  const toggleStore = (storeId: number) => {
    setSelectedStores(prev => 
      prev.includes(storeId) ? prev.filter(id => id !== storeId) : [...prev, storeId]
    );
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    try {
      const payload = {
        ...formData,
        price: parseFloat(formData.price),
        stock: parseInt(formData.stock),
        store_ids: selectedStores,
        variants: formData.variants.map((v: any) => ({
          ...v,
          price: parseFloat(v.price),
          stock: parseInt(v.stock)
        }))
      };

      await api.post('/products', payload);
      router.push('/products');
    } catch (err) {
      console.error('Failed to create product', err);
      alert('Failed to create product. Please check the data and try again.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="max-w-4xl mx-auto space-y-6 pb-20">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button 
            onClick={() => router.push('/products')}
            className="p-2 hover:bg-accent rounded-full transition-all"
          >
            <ChevronLeft size={20} />
          </button>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Add Global Product</h1>
            <p className="text-sm text-muted-foreground">Create a new centralized product to map across your stores.</p>
          </div>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="md:col-span-2 space-y-6">
          <Card className="p-6 space-y-4">
            <h3 className="font-bold text-sm border-b border-border pb-2">Basic Information</h3>
            <div className="space-y-4">
              <div className="space-y-1.5">
                <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Product Name</label>
                <Input 
                  placeholder="e.g. Premium Cotton T-Shirt" 
                  value={formData.name}
                  onChange={(e) => setFormData({...formData, name: e.target.value})}
                  required
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Global SKU</label>
                  <Input 
                    placeholder="e.g. TS-PRM-001" 
                    value={formData.sku}
                    onChange={(e) => setFormData({...formData, sku: e.target.value})}
                    required
                  />
                </div>
                <div className="space-y-1.5">
                  <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Global Price</label>
                  <div className="relative">
                    <DollarSign className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={16} />
                    <Input 
                      type="number"
                      step="0.01"
                      className="pl-10"
                      placeholder="0.00" 
                      value={formData.price}
                      onChange={(e) => setFormData({...formData, price: e.target.value})}
                      required
                    />
                  </div>
                </div>
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Description</label>
                <textarea 
                  className="w-full min-h-[120px] bg-background border border-border rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-primary/20 transition-all"
                  placeholder="Describe your product..."
                  value={formData.description}
                  onChange={(e) => setFormData({...formData, description: e.target.value})}
                />
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Category</label>
                <select 
                  className="w-full bg-background border border-border rounded-xl px-4 py-2 text-sm outline-none focus:ring-2 focus:ring-primary/20 transition-all appearance-none cursor-pointer"
                  value={formData.category_id}
                  onChange={(e) => setFormData({...formData, category_id: e.target.value})}
                >
                  <option value="">Select a category...</option>
                  {renderCategoryOptions(categories)}
                </select>
              </div>
            </div>
          </Card>

          <Card className="p-6 space-y-4">
            <div className="flex items-center justify-between border-b border-border pb-2">
              <h3 className="font-bold text-sm flex items-center gap-2">
                <Store size={18} className="text-primary" />
                Link to Stores
              </h3>
              <span className="text-[10px] text-muted-foreground font-bold uppercase">Select Channels</span>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {stores.map((store) => (
                <div 
                  key={store.id} 
                  onClick={() => toggleStore(store.id)}
                  className={cn(
                    "p-3 rounded-xl border transition-all cursor-pointer flex items-center justify-between",
                    selectedStores.includes(store.id) ? "bg-primary/5 border-primary shadow-sm" : "bg-accent/10 border-border hover:border-primary/30"
                  )}
                >
                  <div className="flex items-center gap-3">
                    <div className={cn(
                      "w-4 h-4 rounded border flex items-center justify-center transition-all",
                      selectedStores.includes(store.id) ? "bg-primary border-primary" : "bg-card border-border"
                    )}>
                      {selectedStores.includes(store.id) && <Check size={10} className="text-white" />}
                    </div>
                    <div>
                      <p className="text-xs font-bold">{store.name}</p>
                      <p className="text-[10px] text-muted-foreground uppercase">{store.platform}</p>
                    </div>
                  </div>
                  {selectedStores.includes(store.id) && (
                    <span className="text-[8px] font-bold text-primary bg-primary/10 px-1.5 py-0.5 rounded-full uppercase">Syncing</span>
                  )}
                </div>
              ))}
              {stores.length === 0 && (
                <div className="col-span-full py-6 text-center text-xs text-muted-foreground">
                  No stores found. Go to Stores to connect your first channel.
                </div>
              )}
            </div>
          </Card>

          <Card className="p-6 space-y-4">
            <div className="flex items-center justify-between border-b border-border pb-2">
              <h3 className="font-bold text-sm">Product Variants</h3>
              <Button type="button" variant="ghost" size="sm" className="h-8 text-primary" onClick={addVariant}>
                <Plus size={14} className="mr-1" /> Add Variant
              </Button>
            </div>
            
            <div className="space-y-4">
              {formData.variants.map((variant: any, index: number) => (
                <div key={index} className="p-4 rounded-2xl bg-accent/20 border border-border space-y-4 relative group">
                  <button 
                    type="button"
                    onClick={() => removeVariant(index)}
                    className="absolute top-4 right-4 p-2 text-muted-foreground hover:text-destructive transition-colors"
                  >
                    <Trash2 size={16} />
                  </button>
                  
                  <div className="grid grid-cols-2 gap-4 mr-8">
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Variant Name</label>
                      <Input 
                        placeholder="e.g. Blue / XL" 
                        value={variant.name}
                        onChange={(e) => updateVariant(index, 'name', e.target.value)}
                        required
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Variant SKU</label>
                      <Input 
                        placeholder="e.g. TS-PRM-001-BL-XL" 
                        value={variant.sku}
                        onChange={(e) => updateVariant(index, 'sku', e.target.value)}
                        required
                      />
                    </div>
                  </div>
                  
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Price</label>
                      <Input 
                        type="number"
                        step="0.01"
                        placeholder="0.00" 
                        value={variant.price}
                        onChange={(e) => updateVariant(index, 'price', e.target.value)}
                        required
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Stock</label>
                      <Input 
                        type="number"
                        placeholder="0" 
                        value={variant.stock}
                        onChange={(e) => updateVariant(index, 'stock', e.target.value)}
                        required
                      />
                    </div>
                  </div>
                </div>
              ))}

              {formData.variants.length === 0 && (
                <div className="text-center py-8 bg-accent/10 rounded-2xl border border-dashed border-border">
                  <Layout size={32} className="text-muted-foreground/30 mx-auto mb-2" />
                  <p className="text-xs text-muted-foreground">This product currently has no variants.</p>
                  <p className="text-[10px] text-muted-foreground/60">Add variants for different sizes, colors, or materials.</p>
                </div>
              )}
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card className="p-6 space-y-4">
            <h3 className="font-bold text-sm border-b border-border pb-2">Product Image</h3>
            <input 
              type="file" 
              className="hidden" 
              ref={fileInputRef} 
              onChange={handleImageUpload}
              accept="image/*"
            />
            <div 
              className={cn(
                "aspect-square rounded-2xl bg-accent/30 border-2 border-dashed border-border flex flex-col items-center justify-center gap-3 group hover:border-primary/50 transition-all cursor-pointer overflow-hidden relative",
                isUploadingImage && "opacity-50 cursor-not-allowed"
              )}
              onClick={() => !isUploadingImage && fileInputRef.current?.click()}
            >
              {isUploadingImage ? (
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
              ) : formData.image_url ? (
                <>
                  <img src={formData.image_url} alt="Preview" className="object-cover w-full h-full" />
                  <div className="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                    <span className="text-white text-xs font-bold uppercase tracking-widest">Change Image</span>
                  </div>
                </>
              ) : (
                <>
                  <ImageIcon size={32} className="text-muted-foreground group-hover:text-primary transition-colors" />
                  <span className="text-xs font-medium text-muted-foreground">Upload Image</span>
                </>
              )}
            </div>
            <p className="text-[10px] text-muted-foreground text-center">Max size: 2MB. Format: JPG, PNG, WEBP.</p>
          </Card>

          <Card className="p-6 space-y-4">
            <h3 className="font-bold text-sm border-b border-border pb-2">Inventory</h3>
            <div className="space-y-1.5">
              <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Total Opening Stock</label>
              <div className="relative">
                <Package className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={16} />
                <Input 
                  type="number"
                  className="pl-10"
                  placeholder="0" 
                  value={formData.stock}
                  onChange={(e) => setFormData({...formData, stock: e.target.value})}
                  required
                />
              </div>
              <p className="text-[10px] text-muted-foreground mt-1">
                Note: Stock will be distributed among variants if they exist.
              </p>
            </div>
          </Card>

          <div className="space-y-3">
            <Button 
              type="submit" 
              className="w-full shadow-lg shadow-primary/20" 
              isLoading={isLoading}
            >
              <Save size={18} className="mr-2" />
              Create Product
            </Button>
            <Button 
              type="button" 
              variant="outline" 
              className="w-full"
              onClick={() => router.push('/products')}
            >
              Discard Changes
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
}
