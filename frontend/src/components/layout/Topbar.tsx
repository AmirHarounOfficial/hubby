'use client';

import React, { useEffect, useState, useRef } from 'react';
import { 
  Bell, 
  User, 
  Search, 
  Globe, 
  Check, 
  AlertCircle, 
  Info, 
  CheckCircle2, 
  X,
  LogOut,
  Settings,
  ChevronDown
} from 'lucide-react';
import { useAuthStore } from '@/store/auth';
import api from '@/lib/api';
import { cn } from '@/lib/utils';
import { useRouter } from 'next/navigation';

export default function Topbar() {
  const router = useRouter();
  const { user, organizations, activeOrgId, setActiveOrgId, logout } = useAuthStore();
  const [notifications, setNotifications] = useState<any[]>([]);
  const [showNotifications, setShowNotifications] = useState(false);
  const [showProfile, setShowProfile] = useState(false);
  const notificationRef = useRef<HTMLDivElement>(null);
  const profileRef = useRef<HTMLDivElement>(null);
  
  const activeOrg = organizations.find(o => o.id === activeOrgId);
  const unreadCount = notifications.filter(n => !n.read_at).length;

  useEffect(() => {
    const fetchNotifications = async () => {
      if (!activeOrgId) return;
      try {
        const res = await api.get('/notifications');
        setNotifications(res.data);
      } catch (err) {
        console.error('Failed to fetch notifications', err);
      }
    };

    fetchNotifications();
    const interval = setInterval(fetchNotifications, 60000); // Refresh every minute
    return () => clearInterval(interval);
  }, [activeOrgId]);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (notificationRef.current && !notificationRef.current.contains(event.target as Node)) {
        setShowNotifications(false);
      }
      if (profileRef.current && !profileRef.current.contains(event.target as Node)) {
        setShowProfile(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const markAsRead = async (id: number) => {
    try {
      await api.post(`/notifications/${id}/read`);
      setNotifications(notifications.map(n => n.id === id ? { ...n, read_at: new Date() } : n));
    } catch (err) {
      console.error('Failed to mark notification as read', err);
    }
  };

  const handleLogout = () => {
    logout();
    router.push('/login');
  };

  const getIcon = (type: string) => {
    switch (type) {
      case 'success': return <CheckCircle2 className="text-secondary" size={16} />;
      case 'warning': return <AlertCircle className="text-warning" size={16} />;
      case 'error': return <X className="text-destructive" size={16} />;
      default: return <Info className="text-primary" size={16} />;
    }
  };

  return (
    <header className="h-16 border-b border-border bg-card/50 backdrop-blur-md px-6 flex items-center justify-between sticky top-0 z-20">
      <div className="flex items-center gap-4">
        <div className="relative group">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground group-focus-within:text-primary transition-colors" size={18} />
          <input 
            type="text" 
            placeholder="Search anything..." 
            className="bg-background/50 border border-border rounded-full pl-10 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/50 w-64 transition-all"
          />
        </div>
      </div>

      <div className="flex items-center gap-4">
        {organizations.length > 0 && (
          <select 
            value={activeOrgId || ''} 
            onChange={(e) => setActiveOrgId(Number(e.target.value))}
            className="bg-background border border-border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/50 cursor-pointer hover:border-primary/50 transition-all"
          >
            {organizations.map(org => (
              <option key={org.id} value={org.id}>{org.name}</option>
            ))}
          </select>
        )}

        <div className="relative" ref={notificationRef}>
          <button 
            onClick={() => setShowNotifications(!showNotifications)}
            className="p-2 hover:bg-accent rounded-full relative transition-colors"
          >
            <Bell size={20} className={cn(unreadCount > 0 && "text-primary")} />
            {unreadCount > 0 && (
              <span className="absolute top-2 right-2 w-2 h-2 bg-primary rounded-full border-2 border-card animate-pulse"></span>
            )}
          </button>

          {showNotifications && (
            <div className="absolute right-0 mt-2 w-80 bg-card border border-border rounded-2xl shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
              <div className="p-4 border-b border-border flex items-center justify-between bg-accent/20">
                <h3 className="font-bold text-sm">Notifications</h3>
                {unreadCount > 0 && <span className="text-[10px] bg-primary/20 text-primary px-2 py-0.5 rounded-full font-bold">{unreadCount} New</span>}
              </div>
              <div className="max-h-[400px] overflow-y-auto custom-scrollbar">
                {notifications.length > 0 ? (
                  notifications.map((notif) => (
                    <div 
                      key={notif.id} 
                      onClick={() => !notif.read_at && markAsRead(notif.id)}
                      className={cn(
                        "p-4 border-b border-border/50 hover:bg-accent/30 transition-all cursor-pointer flex gap-3",
                        !notif.read_at && "bg-primary/5"
                      )}
                    >
                      <div className="mt-0.5">{getIcon(notif.type)}</div>
                      <div className="flex-1">
                        <p className={cn("text-xs font-bold", !notif.read_at ? "text-foreground" : "text-muted-foreground")}>{notif.title}</p>
                        <p className="text-[10px] text-muted-foreground mt-1 line-clamp-2">{notif.message}</p>
                        <p className="text-[9px] text-muted-foreground/60 mt-2 font-medium">
                          {new Date(notif.created_at).toLocaleString()}
                        </p>
                      </div>
                      {!notif.read_at && (
                        <div className="w-2 h-2 bg-primary rounded-full self-center"></div>
                      )}
                    </div>
                  ))
                ) : (
                  <div className="p-10 text-center text-muted-foreground flex flex-col items-center gap-2">
                    <Bell size={32} className="opacity-20" />
                    <p className="text-xs">No notifications yet</p>
                  </div>
                )}
              </div>
              {notifications.length > 0 && (
                <button className="w-full py-3 text-[10px] font-bold text-primary hover:bg-accent/50 border-t border-border transition-all">
                  View All Activity
                </button>
              )}
            </div>
          )}
        </div>

        <div className="h-8 w-px bg-border mx-2"></div>

        <div className="relative" ref={profileRef}>
          <button 
            onClick={() => setShowProfile(!showProfile)}
            className="flex items-center gap-3 p-1 pr-3 hover:bg-accent rounded-full transition-all group"
          >
            <div className="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary border border-primary/30 group-hover:border-primary/50 transition-all">
              <User size={18} />
            </div>
            <div className="text-left hidden sm:block">
              <p className="text-xs font-bold leading-none">{user?.name || 'Guest User'}</p>
              <p className="text-[10px] text-muted-foreground mt-1">{activeOrg?.name || 'Organization'}</p>
            </div>
            <ChevronDown size={14} className={cn("text-muted-foreground transition-transform", showProfile && "rotate-180")} />
          </button>

          {showProfile && (
            <div className="absolute right-0 mt-2 w-56 bg-card border border-border rounded-2xl shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
              <div className="p-4 border-b border-border bg-accent/10">
                <p className="text-xs font-bold">{user?.name}</p>
                <p className="text-[10px] text-muted-foreground mt-0.5">{user?.email}</p>
              </div>
              <div className="p-2">
                <button
                  onClick={() => { router.push('/settings'); setShowProfile(false); }}
                  className="w-full flex items-center gap-3 px-3 py-2 text-xs font-medium hover:bg-accent rounded-lg transition-all"
                >
                  <User size={14} className="text-primary" />
                  My Profile
                </button>
                <button 
                  onClick={() => { router.push('/settings'); setShowProfile(false); }}
                  className="w-full flex items-center gap-3 px-3 py-2 text-xs font-medium hover:bg-accent rounded-lg transition-all"
                >
                  <Settings size={14} className="text-secondary" />
                  Settings
                </button>
                <div className="h-px bg-border my-2 mx-2"></div>
                <button 
                  onClick={handleLogout}
                  className="w-full flex items-center gap-3 px-3 py-2 text-xs font-bold text-destructive hover:bg-destructive/10 rounded-lg transition-all"
                >
                  <LogOut size={14} />
                  Log Out
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
