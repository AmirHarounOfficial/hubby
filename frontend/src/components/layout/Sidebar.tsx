'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/auth';
import { 
  LayoutDashboard, 
  ShoppingBag,
  Undo2,
  Truck,
  Banknote,
  FileText,
  Users,
  Package, 
  Database, 
  Store as StoreIcon, 
  BarChart3,
  Coins,
  Workflow,
  CreditCard,
  Settings,
  ChevronLeft,
  ChevronRight,
  Building2
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Logo } from '@/components/ui/Logo';
import { useI18n } from '@/i18n';

const menuItems = [
  { icon: LayoutDashboard, key: 'dashboard', href: '/dashboard' },
  { icon: ShoppingBag, key: 'orders', href: '/orders' },
  { icon: Undo2, key: 'returns', href: '/returns' },
  { icon: Truck, key: 'shipping', href: '/shipments' },
  { icon: Banknote, key: 'cod', href: '/cod' },
  { icon: FileText, key: 'invoices', href: '/invoices' },
  { icon: Users, key: 'customers', href: '/customers' },
  { icon: Package, key: 'products', href: '/products' },
  { icon: Database, key: 'inventory', href: '/inventory' },
  { icon: StoreIcon, key: 'stores', href: '/stores' },
  { icon: BarChart3, key: 'analytics', href: '/analytics' },
  { icon: Coins, key: 'profit', href: '/profit' },
  { icon: Workflow, key: 'automation', href: '/automation' },
  { icon: CreditCard, key: 'billing', href: '/billing' },
  { icon: Settings, key: 'settings', href: '/settings' },
];

export default function Sidebar({ isOpen, setIsOpen }: { isOpen: boolean; setIsOpen: (v: boolean) => void }) {
  const pathname = usePathname();
  const { t, dir } = useI18n();
  const { organizations, activeOrgId, setActiveOrgId } = useAuthStore();
  const activeOrg = organizations.find(o => o.id === activeOrgId);

  return (
    <aside className={cn(
      "bg-card border-r border-border transition-all duration-300 flex flex-col glass",
      isOpen ? "w-64" : "w-20"
    )}>
      <div className="p-4 flex flex-col gap-4">
        <div className="flex items-center justify-between">
          {isOpen
            ? <Logo variant="color" className="h-7 w-auto" />
            : <Logo variant="icon" className="h-8 w-8" />}
          <button 
            onClick={() => setIsOpen(!isOpen)}
            className="p-2 hover:bg-accent rounded-lg"
          >
            {(isOpen ? dir === 'ltr' : dir === 'rtl') ? <ChevronLeft size={20} /> : <ChevronRight size={20} />}
          </button>
        </div>

        {isOpen && organizations.length > 0 && (
          <div className="px-2">
            <select 
              value={activeOrgId || ''} 
              onChange={(e) => setActiveOrgId(parseInt(e.target.value))}
              className="w-full bg-accent/50 border border-border rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary/20 transition-all appearance-none cursor-pointer"
            >
              {organizations.map(org => (
                <option key={org.id} value={org.id}>{org.name}</option>
              ))}
            </select>
          </div>
        )}
      </div>

      <nav className="flex-1 px-4 space-y-2 mt-4">
        {menuItems.map((item) => (
          <Link
            key={item.href}
            href={item.href}
            className={cn(
              "flex items-center p-3 rounded-xl transition-all hover:bg-primary/10 group",
              pathname.startsWith(item.href) ? "bg-primary text-white shadow-lg shadow-primary/20" : "text-muted-foreground"
            )}
          >
            <item.icon size={22} className={cn(
              "min-w-[22px]",
              pathname.startsWith(item.href) ? "text-white" : "group-hover:text-primary"
            )} />
            {isOpen && <span className="ms-4 font-medium">{t('nav.' + item.key)}</span>}
          </Link>
        ))}
      </nav>
    </aside>
  );
}
