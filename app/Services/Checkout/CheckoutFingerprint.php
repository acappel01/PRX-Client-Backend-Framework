<?php

namespace App\Services\Checkout;

use App\Models\Commerce\Cart;
use App\Models\Commerce\CartItem;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderItem;
use Illuminate\Database\Eloquent\Collection;

class CheckoutFingerprint
{
    public static function cart(Cart $cart, Collection $items): string
    {
        return self::make([
            'coupon_code' => $cart->coupon_code,
            'items' => $items->map(fn (CartItem $item) => $item->only([
                'id', 'itemable_type', 'itemable_id', 'plan_id', 'quantity', 'unit_price_snapshot',
            ]))->all(),
        ]);
    }

    public static function order(Order $order, Collection $items): string
    {
        return self::make([
            'amounts' => $order->only(['subtotal', 'tax_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'currency']),
            'items' => $items->map(fn (OrderItem $item) => $item->only([
                'id', 'name', 'sku', 'quantity', 'unit_price', 'line_total',
                'billing_period', 'prescribe_rx_product_id', 'prescribe_rx_product_number',
            ]))->all(),
        ]);
    }

    /** Keyed hashes protect low-entropy answers and snapshot values. */
    public static function make(array $value): string
    {
        $normalize = function (array $data) use (&$normalize): array {
            if (! array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$entry) {
                if (is_array($entry)) {
                    $entry = $normalize($entry);
                }
            }

            return $data;
        };

        return hash_hmac('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
