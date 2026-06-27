import { http, HttpResponse } from 'msw';

export const handlers = [
  http.get('*/api/analytics/dashboard', () => {
    return HttpResponse.json({
      summary: {
        total_revenue: 24560,
        total_orders: 1284,
        active_products: 452,
      },
      revenue_over_time: [],
      orders_by_platform: [],
    });
  }),
  
  http.get('*/api/orders', () => {
    return HttpResponse.json({
      data: [
        { id: '1001', total: 45.00, platform: 'shopify', created_at: new Date().toISOString() }
      ],
    });
  }),
];
