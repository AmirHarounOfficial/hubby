import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Hubby — Command every store from one place",
  description:
    "Unify orders, inventory and products across Shopify, Salla, Amazon, Noon, Zid, WooCommerce and Trendyol in a single synchronised command center.",
  // Favicons come from the app/ file conventions (icon.svg, apple-icon.png, favicon.ico).
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className="h-full antialiased">
      {/* Brand fonts (Satoshi / Alexandria) are self-hosted via @font-face in
          globals.css — no build-time or runtime network fetch. */}
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
