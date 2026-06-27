import AppShell from '@/components/layout/AppShell';
import { ToastProvider } from '@/components/ui/Toast';
import { StoresProvider } from '@/components/providers/StoresProvider';

// The dashboard is auth-gated and entirely data-driven, so there's nothing to
// statically prerender. Forcing dynamic rendering also avoids CSR-bailout build
// errors from client hooks like useSearchParams in nested pages.
export const dynamic = 'force-dynamic';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <ToastProvider>
      <StoresProvider>
        <AppShell>{children}</AppShell>
      </StoresProvider>
    </ToastProvider>
  );
}
