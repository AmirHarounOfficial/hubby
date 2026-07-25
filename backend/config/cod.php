<?php

/**
 * COD reconciliation configuration (spec 06). Per-carrier remittance cycles live in cod_carrier_
 * profiles in a later slice; this is the org-wide default used to compute due_at.
 */
return [
    // Days from collection to when the carrier is expected to remit the cash.
    'remittance_cycle_days' => 14,

    // Amount tolerance (currency units) within which a collected/remitted amount counts as matched.
    'match_tolerance' => 1.00,
];
