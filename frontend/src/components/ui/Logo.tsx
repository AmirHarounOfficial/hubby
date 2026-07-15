/* eslint-disable @next/next/no-img-element */

type LogoVariant = 'color' | 'white' | 'dark' | 'slogan' | 'icon';

const SRC: Record<LogoVariant, string> = {
  color: '/brand/logo.svg',        // green wordmark + teal mark — for light backgrounds
  white: '/brand/logo-white.svg',  // fully white — for photos / strong colour
  dark: '/brand/logo-dark.svg',    // white wordmark + green mark — for dark backgrounds
  slogan: '/brand/logo-slogan.svg',// wordmark with tagline
  icon: '/brand/icon.svg',         // square app icon
};

/**
 * The Hubby brand mark. Pick the variant that suits the background:
 * `color` on light, `dark`/`white` on dark. Size it with a height class
 * (e.g. `className="h-8 w-auto"`).
 */
export function Logo({
  variant = 'color',
  className,
  alt = 'Hubby',
}: {
  variant?: LogoVariant;
  className?: string;
  alt?: string;
}) {
  return <img src={SRC[variant]} alt={alt} className={className} draggable={false} />;
}
