import { ShoppingBag, Globe, Zap, Store, Package, Sun, type LucideIcon } from 'lucide-react';

/**
 * Single source of truth for the e-commerce platforms HubbyGlobal connects to.
 * Add a platform here and it appears everywhere (onboarding, stores, badges).
 */
export type PlatformId =
  | 'shopify'
  | 'salla'
  | 'woocommerce'
  | 'zid'
  | 'amazon'
  | 'noon';

export interface PlatformMeta {
  id: PlatformId;
  name: string;
  icon: LucideIcon;
  /** Tailwind text-color class for the brand mark. */
  color: string;
  /**
   * How a merchant connects this store from the dashboard.
   * - `oauth`: redirect to the provider to authorise (also supports a token fallback).
   * - `manual`: the merchant pastes their own API credentials.
   */
  auth: 'oauth' | 'manual';
  /** Label + placeholder for the store-identifier field (domain / URL / seller id). */
  domainLabel: string;
  domainPlaceholder: string;
  /** Label for the primary credential field. */
  tokenLabel: string;
  /** Optional second credential (e.g. WooCommerce consumer secret). */
  secretLabel?: string;
  /** One-line hint on where the merchant finds these credentials. */
  help: string;
}

export const PLATFORMS: PlatformMeta[] = [
  {
    id: 'shopify',
    name: 'Shopify',
    icon: ShoppingBag,
    color: 'text-green-500',
    auth: 'oauth',
    domainLabel: 'Store domain',
    domainPlaceholder: 'your-store.myshopify.com',
    tokenLabel: 'Admin API access token',
    help: 'Shopify admin → Settings → Apps and sales channels → Develop apps → your app → API credentials.',
  },
  {
    id: 'salla',
    name: 'Salla',
    icon: Globe,
    color: 'text-primary',
    auth: 'oauth',
    domainLabel: 'Store URL',
    domainPlaceholder: 'your-store.salla.sa',
    tokenLabel: 'Access token',
    help: 'Salla Partners portal → your app → Tokens.',
  },
  {
    id: 'amazon',
    name: 'Amazon',
    icon: Package,
    color: 'text-amber-500',
    auth: 'manual',
    domainLabel: 'Seller ID',
    domainPlaceholder: 'A1B2C3D4E5',
    tokenLabel: 'SP-API refresh token',
    help: 'Seller Central → Apps & Services → Develop apps → your app credentials.',
  },
  {
    id: 'noon',
    name: 'Noon',
    icon: Sun,
    color: 'text-yellow-500',
    auth: 'manual',
    domainLabel: 'Store URL',
    domainPlaceholder: 'your-store.noon.partners',
    tokenLabel: 'API token',
    help: 'noon Seller portal → Settings → API access.',
  },
  {
    id: 'woocommerce',
    name: 'WooCommerce',
    icon: Zap,
    color: 'text-purple-600',
    auth: 'manual',
    domainLabel: 'Store URL',
    domainPlaceholder: 'https://yourstore.com',
    tokenLabel: 'Consumer key',
    secretLabel: 'Consumer secret',
    help: 'WooCommerce → Settings → Advanced → REST API → Add key (Read/Write).',
  },
  {
    id: 'zid',
    name: 'Zid',
    icon: Store,
    color: 'text-orange-500',
    auth: 'manual',
    domainLabel: 'Store URL',
    domainPlaceholder: 'your-store.zid.store',
    tokenLabel: 'Access token',
    help: 'Zid dashboard → Settings → API & integrations.',
  },
];

/** Lookup by id, with a sensible fallback for unknown platforms. */
export const platformMap: Record<string, PlatformMeta> = PLATFORMS.reduce(
  (acc, p) => ({ ...acc, [p.id]: p }),
  {} as Record<string, PlatformMeta>,
);

export const FALLBACK_PLATFORM: PlatformMeta = {
  id: 'shopify',
  name: 'Other',
  icon: Store,
  color: 'text-muted-foreground',
  auth: 'manual',
  domainLabel: 'Store URL',
  domainPlaceholder: 'your-store.example.com',
  tokenLabel: 'Access token',
  help: 'Use the API credentials from your store’s developer settings.',
};

export const getPlatform = (id: string): PlatformMeta => platformMap[id] ?? FALLBACK_PLATFORM;
