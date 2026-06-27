'use client';

import React from 'react';
import Link from 'next/link';
import { Store, ArrowRight, Plug } from 'lucide-react';
import Card from './Card';

/**
 * Empty-state shown on data pages when the org hasn't connected any store yet —
 * the data here is sourced from connected platforms, so there's nothing to show
 * until one is linked. Guides the user straight to the Stores screen.
 */
export default function ConnectPrompt({
  title = 'No platforms connected yet',
  description = 'Connect a store to start syncing your data here. You can link Shopify, Salla, Amazon, Noon, WooCommerce or Zid.',
}: {
  title?: string;
  description?: string;
}) {
  return (
    <Card className="flex flex-col items-center gap-4 p-12 text-center">
      <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10 text-primary">
        <Plug size={30} />
      </div>
      <div>
        <h3 className="text-lg font-bold">{title}</h3>
        <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">{description}</p>
      </div>
      <Link
        href="/stores"
        className="inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90"
      >
        <Store size={16} />
        Connect a store
        <ArrowRight size={16} />
      </Link>
    </Card>
  );
}
