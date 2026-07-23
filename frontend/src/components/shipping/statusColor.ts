/** Maps a normalized shipment/tracking status to a Tailwind badge palette (spec 04 §4.2). */
export function shipmentStatusColor(status: string): string {
  switch (status) {
    case 'delivered':
      return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400';
    case 'out_for_delivery':
    case 'in_transit':
    case 'picked_up':
    case 'at_origin_hub':
    case 'at_destination_hub':
    case 'customs_clearance':
      return 'bg-blue-500/15 text-blue-700 dark:text-blue-400';
    case 'label_purchased':
    case 'awaiting_pickup':
    case 'rated':
      return 'bg-violet-500/15 text-violet-700 dark:text-violet-400';
    case 'draft':
      return 'bg-muted text-muted-foreground';
    case 'delivery_attempted':
    case 'held':
    case 'exception':
      return 'bg-amber-500/15 text-amber-700 dark:text-amber-500';
    case 'returned_to_origin':
    case 'rto_in_transit':
    case 'rto_delivered':
    case 'lost':
    case 'damaged':
      return 'bg-red-500/15 text-red-700 dark:text-red-400';
    case 'cancelled':
      return 'bg-muted text-muted-foreground line-through';
    default:
      return 'bg-muted text-muted-foreground';
  }
}
