<?php

namespace App\Enums;

enum IntegrationEventType: string
{
    case OrderAssigned = 'order.assigned';
    case OrderUpdated = 'order.updated';
    case OrderReassigned = 'order.reassigned';
    case OrderCancelled = 'order.cancelled';
}
