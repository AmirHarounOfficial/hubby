<?php

namespace Tests\Unit;

use App\Exceptions\InvalidShipmentTransition;
use App\Services\Shipping\ShipmentStateMachine;
use PHPUnit\Framework\TestCase;

/** The merchant-triggered pre-transit transitions (spec 04 §4.1). */
class ShipmentStateMachineTest extends TestCase
{
    public function test_legal_pre_transit_transitions_are_allowed(): void
    {
        $this->assertTrue(ShipmentStateMachine::canTransition('draft', 'rated'));
        $this->assertTrue(ShipmentStateMachine::canTransition('draft', 'label_purchased'));
        $this->assertTrue(ShipmentStateMachine::canTransition('rated', 'label_purchased'));
        $this->assertTrue(ShipmentStateMachine::canTransition('label_purchased', 'awaiting_pickup'));
        $this->assertTrue(ShipmentStateMachine::canTransition('label_purchased', 'cancelled'));
    }

    public function test_carrier_driven_states_are_never_merchant_transitions(): void
    {
        // You cannot jump a shipment straight into a carrier-owned state.
        $this->assertFalse(ShipmentStateMachine::canTransition('label_purchased', 'delivered'));
        $this->assertFalse(ShipmentStateMachine::canTransition('draft', 'in_transit'));
        // Nor cancel one that's already moving.
        $this->assertFalse(ShipmentStateMachine::canTransition('in_transit', 'cancelled'));
    }

    public function test_assert_throws_on_an_illegal_transition(): void
    {
        $this->expectException(InvalidShipmentTransition::class);
        ShipmentStateMachine::assert('delivered', 'draft');
    }
}
