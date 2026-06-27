'use client';

import React, { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Card from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { 
  FolderTree, 
  Plus, 
  Edit2, 
  Trash2, 
  Search,
  ChevronRight,
  ChevronDown,
  FolderOpen
} from 'lucide-react';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import Modal from '@/components/ui/Modal';

// Helper to build a tree from flat categories
const buildTree = (categories: any[], parentId: number | null = null): any[] => {
  return categories
    .filter(c => c.parent_id === parentId)
    .map(c => ({
      ...c,
      children: buildTree(categories, c.id)
    }));
};

const CategoryNode = ({ 
  category, 
  depth = 0, 
  onEdit, 
  onDelete 
}: { 
  category: any, 
  depth?: number,
  onEdit: (id: number) => void,
  onDelete: (category: any) => void
}) => {
  const [isExpanded, setIsExpanded] = useState(true);
  const hasChildren = category.children && category.children.length > 0;

  return (
    <div className="select-none">
      <div 
        className={cn(
          "flex items-center justify-between p-3 rounded-xl hover:bg-accent/30 transition-all border border-transparent group",
          depth === 0 ? "bg-accent/10 border-border/50 mb-2" : "mb-1"
        )}
        style={{ marginLeft: `${depth * 24}px` }}
      >
        <div className="flex items-center gap-3 flex-1 cursor-pointer" onClick={() => setIsExpanded(!isExpanded)}>
          <div className={cn("w-6 flex justify-center text-muted-foreground transition-transform duration-200", hasChildren ? "opacity-100" : "opacity-0")}>
            {isExpanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
          </div>
          <div className="w-8 h-8 rounded-lg bg-card border border-border flex items-center justify-center shadow-sm">
            {depth === 0 ? <FolderTree size={16} className="text-primary" /> : <FolderOpen size={16} className="text-muted-foreground" />}
          </div>
          <div>
            <p className={cn("text-sm transition-colors group-hover:text-primary", depth === 0 ? "font-bold" : "font-medium")}>
              {category.name}
            </p>
            {category.description && (
              <p className="text-[10px] text-muted-foreground mt-0.5 line-clamp-1">{category.description}</p>
            )}
          </div>
        </div>
        
        <div className="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
          <button 
            className="p-1.5 bg-background rounded-md border border-border hover:bg-primary hover:text-white hover:border-primary transition-all shadow-sm"
            onClick={(e) => { e.stopPropagation(); onEdit(category.id); }}
            title="Edit Category"
          >
            <Edit2 size={14} />
          </button>
          <button 
            className="p-1.5 bg-background rounded-md border border-border hover:bg-destructive hover:text-white hover:border-destructive transition-all shadow-sm"
            onClick={(e) => { e.stopPropagation(); onDelete(category); }}
            title="Delete Category"
          >
            <Trash2 size={14} />
          </button>
        </div>
      </div>
      
      {isExpanded && hasChildren && (
        <div className="mt-1 relative before:absolute before:left-[35px] before:top-0 before:bottom-0 before:w-px before:bg-border/50">
          {category.children.map((child: any) => (
            <CategoryNode 
              key={child.id} 
              category={child} 
              depth={depth + 1} 
              onEdit={onEdit} 
              onDelete={onDelete} 
            />
          ))}
        </div>
      )}
    </div>
  );
};

export default function CategoriesPage() {
  const router = useRouter();
  const [categories, setCategories] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  
  // Deletion state
  const [deletingCategory, setDeletingCategory] = useState<any>(null);
  const [deleteStrategy, setDeleteStrategy] = useState<'move_to_root' | 'cascade'>('move_to_root');
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchCategories = async () => {
    setIsLoading(true);
    try {
      const response = await api.get('/categories');
      setCategories(response.data);
    } catch (err) {
      console.error('Failed to fetch categories', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchCategories();
  }, []);

  const handleDelete = async () => {
    if (!deletingCategory) return;
    setIsDeleting(true);
    try {
      await api.delete(`/categories/${deletingCategory.id}?strategy=${deleteStrategy}`);
      await fetchCategories();
      setDeletingCategory(null);
    } catch (err) {
      console.error('Failed to delete category', err);
      alert('Failed to delete category.');
    } finally {
      setIsDeleting(false);
    }
  };

  const filteredCategories = categories.filter(c => 
    c.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
    c.description?.toLowerCase().includes(searchQuery.toLowerCase())
  );

  // If searching, show flat list, otherwise build tree
  const displayTree = searchQuery 
    ? filteredCategories.map(c => ({ ...c, children: [] })) 
    : buildTree(categories);

  return (
    <div className="space-y-6 pb-20">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight text-foreground">Categories</h1>
          <p className="text-sm text-muted-foreground mt-1">Manage your product hierarchy and collections.</p>
        </div>
        <Button onClick={() => router.push('/categories/new')} className="shadow-lg shadow-primary/20">
          <Plus size={18} className="mr-2" />
          Add Category
        </Button>
      </div>

      <Card className="p-0 overflow-hidden border-border/50">
        <div className="p-4 border-b border-border/50 bg-card/50 flex flex-col md:flex-row gap-4 justify-between items-center">
          <div className="relative w-full max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" size={16} />
            <Input 
              placeholder="Search categories..." 
              className="pl-9 bg-background/50 border-border/50"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-muted-foreground bg-accent/30 px-3 py-1.5 rounded-lg border border-border/50">
            <FolderTree size={14} className="text-primary" />
            <span>{categories.length} Total</span>
          </div>
        </div>

        <div className="p-4 sm:p-6 min-h-[400px]">
          {isLoading ? (
            <div className="flex flex-col items-center justify-center h-64 text-muted-foreground gap-4">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
              <p className="text-sm font-medium">Loading hierarchy...</p>
            </div>
          ) : displayTree.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-64 text-center">
              <div className="w-16 h-16 rounded-2xl bg-accent/30 flex items-center justify-center mb-4 border border-border shadow-sm">
                <FolderTree size={24} className="text-muted-foreground" />
              </div>
              <p className="text-lg font-bold">No categories found</p>
              <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                {searchQuery ? "Try adjusting your search terms." : "Create your first category to start organizing products."}
              </p>
              {!searchQuery && (
                <Button onClick={() => router.push('/categories/new')} variant="outline" className="mt-6">
                  Create Category
                </Button>
              )}
            </div>
          ) : (
            <div className="max-w-4xl">
              {displayTree.map((category) => (
                <CategoryNode 
                  key={category.id} 
                  category={category} 
                  onEdit={(id) => router.push(`/categories/${id}/edit`)}
                  onDelete={(cat) => setDeletingCategory(cat)}
                />
              ))}
            </div>
          )}
        </div>
      </Card>

      <Modal 
        isOpen={!!deletingCategory} 
        onClose={() => setDeletingCategory(null)}
        title="Delete Category"
      >
        <div className="space-y-6">
          <div className="p-4 rounded-xl bg-destructive/10 border border-destructive/20 flex gap-3">
            <Trash2 size={20} className="text-destructive shrink-0 mt-0.5" />
            <div>
              <h4 className="text-sm font-bold text-destructive">Warning</h4>
              <p className="text-xs text-destructive/80 mt-1 leading-relaxed">
                You are about to delete <strong>{deletingCategory?.name}</strong>. 
                Products assigned to this category will lose their assignment unless they are reassigned.
              </p>
            </div>
          </div>

          {deletingCategory?.children && deletingCategory.children.length > 0 && (
            <div className="space-y-3">
              <p className="text-sm font-bold text-foreground">What should happen to its subcategories?</p>
              
              <label className={cn(
                "flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all",
                deleteStrategy === 'move_to_root' ? "bg-primary/5 border-primary shadow-sm" : "bg-card border-border hover:border-primary/50"
              )}>
                <div className="mt-1 flex items-center justify-center h-4 w-4 rounded-full border border-primary shrink-0 relative">
                  {deleteStrategy === 'move_to_root' && <div className="h-2 w-2 rounded-full bg-primary" />}
                </div>
                <input 
                  type="radio" 
                  className="hidden" 
                  checked={deleteStrategy === 'move_to_root'} 
                  onChange={() => setDeleteStrategy('move_to_root')} 
                />
                <div>
                  <p className="text-sm font-bold">Move to Root Level (Recommended)</p>
                  <p className="text-xs text-muted-foreground mt-0.5">Subcategories will be preserved and moved up to the top level.</p>
                </div>
              </label>

              <label className={cn(
                "flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all",
                deleteStrategy === 'cascade' ? "bg-destructive/5 border-destructive shadow-sm" : "bg-card border-border hover:border-destructive/50"
              )}>
                <div className="mt-1 flex items-center justify-center h-4 w-4 rounded-full border border-destructive shrink-0 relative">
                  {deleteStrategy === 'cascade' && <div className="h-2 w-2 rounded-full bg-destructive" />}
                </div>
                <input 
                  type="radio" 
                  className="hidden" 
                  checked={deleteStrategy === 'cascade'} 
                  onChange={() => setDeleteStrategy('cascade')} 
                />
                <div>
                  <p className="text-sm font-bold text-destructive">Delete All Subcategories</p>
                  <p className="text-xs text-destructive/70 mt-0.5">Permanently delete this category and every subcategory beneath it.</p>
                </div>
              </label>
            </div>
          )}

          <div className="flex justify-end gap-3 pt-4 border-t border-border">
            <Button variant="outline" onClick={() => setDeletingCategory(null)} disabled={isDeleting}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete} isLoading={isDeleting}>Delete Category</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
