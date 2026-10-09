<?php

namespace App\Enums;

/**
 * What a catalog row represents operationally.
 *
 * The source file mixes two unlike things in one list of "cities": 94 places a
 * parcel is taken to, and «إستلام مكتب» — collection from the office, which is a
 * way of handing the parcel over rather than somewhere to take it. It arrives
 * with a city id, a region of the same name and a price of `0.00`, and it is
 * neither a destination nor a mistake.
 *
 * Marking it with a kind, instead of simply deactivating it, keeps the two
 * statements separate: `is_active` records that an operator withdrew a
 * destination, and this records that the row was never a destination. The order
 * form asks for `Delivery` and so does not offer it, while the pickup flow —
 * when it is designed — has a real row to attach to (PLAN §9).
 */
enum FulfilmentKind: string
{
    case Delivery = 'delivery';
    case OfficePickup = 'office_pickup';
}
