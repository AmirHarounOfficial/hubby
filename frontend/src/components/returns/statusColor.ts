/** Maps an RMA status to a badge colour class. Shared by the list and detail views. */
export function statusColor(status: string): string {
  switch (status) {
    case 'requested':
      return 'bg-warning/15 text-warning';
    case 'approved':
    case 'awaiting_shipment':
    case 'in_transit':
    case 'received':
    case 'inspecting':
      return 'bg-primary/10 text-primary';
    case 'inspected':
    case 'refunded':
    case 'exchanged':
      return 'bg-secondary/15 text-secondary';
    case 'rejected':
    case 'cancelled':
    case 'failed':
      return 'bg-destructive/10 text-destructive';
    default:
      return 'bg-accent text-muted-foreground';
  }
}
