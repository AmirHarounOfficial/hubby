import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "HubbyGlobal — Command every store from one universe",
  description:
    "Unify orders, inventory and products across Shopify, Salla, Zid and WooCommerce in a single synchronised command center.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className="h-full antialiased">
      <head>
        {/* Fonts are loaded at runtime (browser-side), NOT at build time, so the
            production `next build` never fails on a Google Fonts network fetch.
            The CSS variables they back are defined in globals.css. */}
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&family=Geist+Mono:wght@400;500&family=Geist:wght@400;500;600;700&display=swap"
          rel="stylesheet"
        />
      </head>
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
