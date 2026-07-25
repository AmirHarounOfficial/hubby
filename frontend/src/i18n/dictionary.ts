import { common } from './dicts/common';
import { dashboard } from './dicts/dashboard';
import { orders } from './dicts/orders';
import { returns } from './dicts/returns';
import { shipping } from './dicts/shipping';
import { cod } from './dicts/cod';
import { customers } from './dicts/customers';
import { products } from './dicts/products';
import { categories } from './dicts/categories';
import { inventory } from './dicts/inventory';
import { analytics } from './dicts/analytics';
import { profit } from './dicts/profit';
import { automation } from './dicts/automation';
import { stores } from './dicts/stores';
import { billing } from './dicts/billing';
import { settings } from './dicts/settings';

/**
 * The full dashboard dictionary, one tree per locale. `common` keys (nav,
 * topbar, connect, actions) live at the top level; every feature is namespaced
 * (e.g. `orders.title`). Add feature strings in `src/i18n/dicts/<feature>.ts`.
 */
export const dictionaries = {
  en: {
    ...common.en,
    dashboard: dashboard.en,
    orders: orders.en,
    returns: returns.en,
    shipping: shipping.en,
    cod: cod.en,
    customers: customers.en,
    products: products.en,
    categories: categories.en,
    inventory: inventory.en,
    analytics: analytics.en,
    profit: profit.en,
    automation: automation.en,
    stores: stores.en,
    billing: billing.en,
    settings: settings.en,
  },
  ar: {
    ...common.ar,
    dashboard: dashboard.ar,
    orders: orders.ar,
    returns: returns.ar,
    shipping: shipping.ar,
    cod: cod.ar,
    customers: customers.ar,
    products: products.ar,
    categories: categories.ar,
    inventory: inventory.ar,
    analytics: analytics.ar,
    profit: profit.ar,
    automation: automation.ar,
    stores: stores.ar,
    billing: billing.ar,
    settings: settings.ar,
  },
} as const;
