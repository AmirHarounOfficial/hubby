import { getPlatform, PLATFORM_LOGO_IDS } from '@/lib/platforms';
import { cn } from '@/lib/utils';

/**
 * Renders a platform's real brand logo (/platforms/{id}.svg) when available,
 * else the platform's Lucide icon. Height-constrained; wide wordmarks keep their
 * aspect ratio but are capped so they can't blow out a row.
 */
export function PlatformLogo({
  platform,
  size = 22,
  className,
}: {
  platform: string | null | undefined;
  size?: number;
  className?: string;
}) {
  const id = (platform ?? '').toLowerCase();

  if (PLATFORM_LOGO_IDS.has(id)) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={`/platforms/${id}.svg`}
        alt={getPlatform(id).name}
        style={{ height: size, width: 'auto', maxWidth: size * 3 }}
        className={cn('object-contain shrink-0', className)}
      />
    );
  }

  const meta = getPlatform(id);
  const Icon = meta.icon;
  return <Icon size={size} className={cn(meta.color, className)} />;
}
