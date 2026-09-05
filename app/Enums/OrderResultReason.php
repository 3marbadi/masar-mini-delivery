<?php

namespace App\Enums;

/**
 * The result reasons Masar may announce (CONTRACT §3.14.1, §3.21.5).
 *
 * Masar owns this vocabulary — the codes come from the reasons its courier
 * interface offers — and §3.21.5 is explicit that no second dictionary is
 * created for the wire. This is that same closed list of ten, held here so the
 * receiver can enforce the pairing rather than accept whatever arrives: «القسمةُ
 * ملزمةٌ في الطرفين لا في المرسِل وحده».
 *
 * The database column stays a plain string, as the iteration-three data-layer
 * migration made it. That is deliberate and §3.21.5 permits it: the contract
 * binds the endpoint, not the storage, and the list is versioned with the
 * endpoint rather than with the schema.
 *
 * Nothing in Mini Delivery produces these codes. They arrive, they are checked,
 * they are stored.
 */
enum OrderResultReason: string
{
    case CustomerRequestedDeferral = 'customer_requested_deferral';
    case CustomerCurrentlyUnavailable = 'customer_currently_unavailable';
    case CustomerUnreachable = 'customer_unreachable';
    case CustomerAbsent = 'customer_absent';
    case LocationOrAddressIssue = 'location_or_address_issue';
    case CustomerRefused = 'customer_refused';
    case CustomerCancelled = 'customer_cancelled';
    case OrderIssue = 'order_issue';
    case PriceMismatch = 'price_mismatch';
    case IncorrectCustomerOrOrderData = 'incorrect_customer_or_order_data';

    /** The result this reason belongs to — postponement or return, never both. */
    public function result(): DeliveryStatus
    {
        return match ($this) {
            self::CustomerRequestedDeferral,
            self::CustomerCurrentlyUnavailable,
            self::CustomerUnreachable,
            self::CustomerAbsent,
            self::LocationOrAddressIssue => DeliveryStatus::Postponed,
            default => DeliveryStatus::Returned,
        };
    }

    /**
     * The reasons one result accepts.
     *
     * `delivered` accepts none at all, which is why it is absent here rather
     * than mapped to an empty list by accident. So is `with_rep`, which never
     * travels on this wire (§3.21.3).
     *
     * @return list<string>
     */
    public static function codesFor(DeliveryStatus $result): array
    {
        return array_values(array_map(
            static fn (self $reason) => $reason->value,
            array_filter(self::cases(), static fn (self $reason) => $reason->result() === $result),
        ));
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::cases(), 'value');
    }
}
