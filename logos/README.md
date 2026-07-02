# Platform brand logos — drop them here

Place each platform's logo as an **SVG** in this folder, named by the platform id
(lowercase). Full-color brand SVGs are preferred — they render as-is; monochrome
also works.

Expected files (add whichever you have):

```
logos/shopify.svg
logos/salla.svg
logos/amazon.svg
logos/noon.svg
logos/woocommerce.svg
logos/zid.svg
logos/trendyol.svg
```

Once these are here, I copy them into the app + dashboard asset folders and wire a
single `PlatformLogo` component/widget so the real logo shows **everywhere a store
appears** — stores page, store cards, orders, customers, products/channels,
analytics, and the dashboard — automatically. Any platform without an SVG falls
back to its current icon.

Tips:
- SVG only (scalable, theme-safe). If you only have PNGs, drop them as e.g.
  `shopify.png` and I'll wire raster fallback, but SVG looks best.
- Keep the artwork's own `viewBox`; don't worry about size — it's sized in code.
