import { cn, formatCurrency } from '@/lib/utils';

/**
 * The official new Saudi Riyal symbol, drawn from the same SVG the mobile app
 * uses (no font ships the U+20C0 glyph). Rendered as a CSS mask so it inherits
 * the surrounding text colour (currentColor), exactly like the app tints it.
 */
export function RiyalGlyph({ className }: { className?: string }) {
  return (
    <span
      aria-hidden
      className={cn('inline-block shrink-0', className)}
      style={{
        width: '0.9em',
        height: '0.9em',
        backgroundColor: 'currentColor',
        WebkitMaskImage: 'url(/saudi_riyal.svg)',
        maskImage: 'url(/saudi_riyal.svg)',
        WebkitMaskRepeat: 'no-repeat',
        maskRepeat: 'no-repeat',
        WebkitMaskPosition: 'center',
        maskPosition: 'center',
        WebkitMaskSize: 'contain',
        maskSize: 'contain',
        transform: 'translateY(0.05em)',
      }}
    />
  );
}

/**
 * Renders a currency amount with the official riyal glyph for SAR (matching the
 * mobile app), or the plain formatted string for any other currency.
 * Drop-in replacement for `{formatCurrency(x, code)}` in JSX.
 */
export function Money({
  amount,
  currency = 'SAR',
  className,
}: {
  amount: number | string | null | undefined;
  currency?: string | null;
  className?: string;
}) {
  const value = typeof amount === 'string' ? parseFloat(amount) : amount ?? 0;
  const safe = Number.isFinite(value as number) ? (value as number) : 0;

  if (currency && currency.toUpperCase() !== 'SAR') {
    return <span className={className}>{formatCurrency(safe, currency)}</span>;
  }

  const number = safe.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  return (
    <span
      className={cn('inline-flex items-baseline gap-[0.18em] whitespace-nowrap tabular-nums', className)}
      aria-label={`SAR ${number}`}
    >
      <RiyalGlyph />
      <span>{number}</span>
    </span>
  );
}
