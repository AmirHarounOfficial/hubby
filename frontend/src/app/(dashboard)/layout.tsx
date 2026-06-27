import AppShell from '@/components/layout/AppShell';

// The dashboard is auth-gated and entirely data-driven, so there's nothing to
// statically prerender. Forcing dynamic rendering also avoids CSR-bailout build
// errors from client hooks like useSearchParams in nested pages.
export const dynamic = 'force-dynamic';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return <AppShell>{children}</AppShell>;
}
