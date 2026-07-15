'use client';

import React, { useState, useEffect } from 'react';
import { useRouter, useParams } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { ChevronLeft, Save } from 'lucide-react';
import api from '@/lib/api';
import { useT } from '@/i18n';

export default function EditCategoryPage() {
  const t = useT();
  const router = useRouter();
  const { categoryId } = useParams();
  const [isSaving, setIsSaving] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [categories, setCategories] = useState<any[]>([]);
  
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    parent_id: ''
  });

  useEffect(() => {
    const fetchData = async () => {
      setIsLoading(true);
      try {
        const [catRes, allCatsRes] = await Promise.all([
          api.get(`/categories/${categoryId}`),
          api.get('/categories')
        ]);
        
        const cat = catRes.data;
        setFormData({
          name: cat.name,
          description: cat.description || '',
          parent_id: cat.parent_id ? cat.parent_id.toString() : ''
        });
        
        setCategories(allCatsRes.data);
      } catch (err) {
        console.error('Failed to fetch data', err);
        alert(t('categories.loadFailed'));
      } finally {
        setIsLoading(false);
      }
    };
    fetchData();
  }, [categoryId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    try {
      await api.put(`/categories/${categoryId}`, {
        ...formData,
        parent_id: formData.parent_id ? parseInt(formData.parent_id) : null
      });
      router.push('/categories');
    } catch (err: any) {
      console.error('Save failed', err);
      if (err.response?.data?.message) {
        alert(err.response.data.message);
      } else {
        alert(t('categories.updateFailed'));
      }
    } finally {
      setIsSaving(false);
    }
  };

  // Helper to render options with indentation, excluding the current category and its descendants
  const renderCategoryOptions = (cats: any[], parentId: number | null = null, depth = 0): React.ReactNode[] => {
    const children = cats.filter(c => c.parent_id === parentId);
    let options: React.ReactNode[] = [];
    
    children.forEach(child => {
      // Disable selecting itself as a parent
      const isSelf = child.id.toString() === categoryId;
      
      options.push(
        <option key={child.id} value={child.id} disabled={isSelf}>
          {'\u00A0\u00A0'.repeat(depth)}{depth > 0 ? '↳ ' : ''}{child.name}
        </option>
      );
      
      // If it's self, we still render children but maybe disable them all? 
      // It's easier just to render them and disable them on the server, but for UX let's render them.
      // Actually, if it's disabled, the user shouldn't select it. But to prevent infinite loops cleanly:
      options = options.concat(renderCategoryOptions(cats, child.id, depth + 1));
    });
    
    return options;
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

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
          <h1 className="text-2xl font-bold tracking-tight">{t('categories.editTitle')}</h1>
          <p className="text-sm text-muted-foreground">{t('categories.editSubtitle')}</p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <Card className="p-6 space-y-6">
          <div className="space-y-1.5">
            <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground">{t('categories.nameLabel')}</label>
            <Input
              placeholder={t('categories.namePlaceholder')}
              value={formData.name}
              onChange={(e) => setFormData({...formData, name: e.target.value})}
              required
            />
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex justify-between">
              {t('categories.parentLabel')}
              <span className="text-[10px] text-muted-foreground/50 lowercase tracking-normal font-medium">{t('categories.optional')}</span>
            </label>
            <select
              className="w-full bg-background border border-border rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-primary/20 transition-all appearance-none cursor-pointer"
              value={formData.parent_id}
              onChange={(e) => setFormData({...formData, parent_id: e.target.value})}
            >
              <option value="">{t('categories.parentNone')}</option>
              {renderCategoryOptions(categories)}
            </select>
            <p className="text-[10px] text-muted-foreground mt-1">{t('categories.selfParentHint')}</p>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex justify-between">
              {t('categories.descriptionLabel')}
              <span className="text-[10px] text-muted-foreground/50 lowercase tracking-normal font-medium">{t('categories.optional')}</span>
            </label>
            <textarea
              className="w-full min-h-[120px] bg-background border border-border rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-primary/20 transition-all"
              placeholder={t('categories.descriptionPlaceholder')}
              value={formData.description}
              onChange={(e) => setFormData({...formData, description: e.target.value})}
            />
          </div>

          <div className="pt-4 border-t border-border flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => router.back()}>{t('categories.cancel')}</Button>
            <Button type="submit" isLoading={isSaving} className="shadow-lg shadow-primary/20">
              <Save size={16} className="mr-2" />
              {t('categories.saveChanges')}
            </Button>
          </div>
        </Card>
      </form>
    </div>
  );
}
