<?php

namespace App\GraphQL\Enums;

class OrderStatus
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const PROCESSING = 'processing';
    public const SHIPPING = 'shipping';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';
    const DELIVERY_FAILED = 'delivery_failed';

    public static function getAllStatuses(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
            self::PROCESSING,
            self::SHIPPING,
            self::COMPLETED,
            self::CANCELLED,
            self::FAILED,
            self::DELIVERY_FAILED
        ];
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::getAllStatuses());
    }
}
