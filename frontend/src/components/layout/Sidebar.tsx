'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/auth';
import { 
  LayoutDashboard, 
  ShoppingBag, 
  Users,
  Package, 
  Database, 
  Store as StoreIcon, 
  BarChart3, 
  CreditCard, 
  Settings,
  ChevronLeft,
  ChevronRight,
  Building2
} from 'lucide-react';
import { cn } from '@/lib/utils';

const menuItems = [
  { icon: LayoutDashboard, label: 'Dashboard', href: '/dashboard' },
  { icon: ShoppingBag, label: 'Orders', href: '/orders' },
  { icon: Users, label: 'Customers', href: '/customers' },
  { icon: Package, label: 'Products', href: '/products' },
  { icon: Database, label: 'Inventory', href: '/inventory' },
  { icon: StoreIcon, label: 'Stores', href: '/stores' },
  { icon: BarChart3, label: 'Analytics', href: '/analytics' },
  { icon: CreditCard, label: 'Billing', href: '/billing' },
  { icon: Settings, label: 'Settings', href: '/settings' },
];

export default function Sidebar({ isOpen, setIsOpen }: { isOpen: boolean; setIsOpen: (v: boolean) => void }) {
  const pathname = usePathname();
  const { organizations, activeOrgId, setActiveOrgId } = useAuthStore();
  const activeOrg = organizations.find(o => o.id === activeOrgId);

  return (
    <aside className={cn(
      "bg-card border-r border-border transition-all duration-300 flex flex-col glass",
      isOpen ? "w-64" : "w-20"
    )}>
      <div className="p-4 flex flex-col gap-4">
        <div className="flex items-center justify-between">
          {isOpen && <h1 className="text-xl font-bold gradient-text">HubbyGlobal</h1>}
          <button 
            onClick={() => setIsOpen(!isOpen)}
            className="p-2 hover:bg-accent rounded-lg"
          >
            {isOpen ? <ChevronLeft size={20} /> : <ChevronRight size={20} />}
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
            {isOpen && <span className="ml-4 font-medium">{item.label}</span>}
          </Link>
        ))}
      </nav>
    </aside>
  );
}
