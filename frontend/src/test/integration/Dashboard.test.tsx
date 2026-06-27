import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import DashboardPage from '@/app/(dashboard)/dashboard/page';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: false,
    },
  },
});

describe('Dashboard Integration', () => {
  it('renders dashboard with mocked analytics data', async () => {
    render(
      <QueryClientProvider client={queryClient}>
        <DashboardPage />
      </QueryClientProvider>
    );

    // Check for the presence of summary stats
    await waitFor(() => {
      expect(screen.getByText('$24,560.00')).toBeInTheDocument();
      expect(screen.getByText('1,284')).toBeInTheDocument();
    });

    // Check for mocked order
    expect(screen.getByText('Order #1001')).toBeInTheDocument();
  });
});
