'use client';

import React, { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { ChevronLeft, Save } from 'lucide-react';
import api from '@/lib/api';

export default function NewCategoryPage() {
  const router = useRouter();
  const [isSaving, setIsSaving] = useState(false);
  const [categories, setCategories] = useState<any[]>([]);
  
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    parent_id: ''
  });

  useEffect(() => {
    const fetchCategories = async () => {
      try {
        const response = await api.get('/categories');
        setCategories(response.data);
      } catch (err) {
        console.error('Failed to fetch categories', err);
      }
    };
    fetchCategories();
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    try {
      await api.post('/categories', {
        ...formData,
        parent_id: formData.parent_id ? parseInt(formData.parent_id) : null
      });
      router.push('/categories');
    } catch (err) {
      console.error('Save failed', err);
      alert('Failed to create category.');
    } finally {
      setIsSaving(false);
    }
  };

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

  return (
    <div className="max-w-2xl mx-auto space-y-6 pb-20">
      <div className="flex items-center gap-4">
        <button 
          onClick={() => router.back()}
          className="p-2 hover:bg-accent rounded-full transition-all"
        >
          <ChevronLeft size={20} />
        </button>
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Create Category</h1>
          <p className="text-sm text-muted-foreground">Add a new category or subcategory.</p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <Card className="p-6 space-y-6">
          <div className="space-y-1.5">
            <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Category Name</label>
            <Input 
              placeholder="e.g. T-Shirts" 
              value={formData.name}
              onChange={(e) => setFormData({...formData, name: e.target.value})}
              required
            />
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex justify-between">
              Parent Category
              <span className="text-[10px] text-muted-foreground/50 lowercase tracking-normal font-medium">(Optional)</span>
            </label>
            <select 
              className="w-full bg-background border border-border rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-primary/20 transition-all appearance-none cursor-pointer"
              value={formData.parent_id}
              onChange={(e) => setFormData({...formData, parent_id: e.target.value})}
            >
              <option value="">None (Top Level Category)</option>
              {renderCategoryOptions(categories)}
            </select>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex justify-between">
              Description
              <span className="text-[10px] text-muted-foreground/50 lowercase tracking-normal font-medium">(Optional)</span>
            </label>
            <textarea 
              className="w-full min-h-[120px] bg-background border border-border rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-primary/20 transition-all"
              placeholder="Describe this category..."
              value={formData.description}
              onChange={(e) => setFormData({...formData, description: e.target.value})}
            />
          </div>

          <div className="pt-4 border-t border-border flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => router.back()}>Cancel</Button>
            <Button type="submit" isLoading={isSaving} className="shadow-lg shadow-primary/20">
              <Save size={16} className="mr-2" />
              Save Category
            </Button>
          </div>
        </Card>
      </form>
    </div>
  );
}
