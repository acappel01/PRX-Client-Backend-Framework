<?php

namespace App\Models\Commerce;

use Database\Factories\Commerce\CartFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Cart extends Model
{
    use HasFactory, Prunable;

    protected $hidden = ['successor_cart_id'];

    protected $fillable = [
        'ulid',
        'user_id',
        'email',
        'coupon_code',
        'ip_address',
        'user_agent',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Cart $cart): void {
            $cart->ulid ??= (string) Str::ulid();
            $cart->expires_at ??= now()->addDays(30);
        });
    }

    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function isEmpty(): bool
    {
        return $this->items()->doesntExist();
    }

    public function itemCount(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    public function subtotal(): float
    {
        return (float) $this->items()
            ->whereNotNull('unit_price_snapshot')
            ->get()
            ->sum(fn ($i) => $i->unit_price_snapshot * $i->quantity);
    }

    /**
     * Abandoned carts old enough to reap.
     *
     * Every anonymous visitor mints a cart row, so this table grows with
     * traffic and nothing else removes it. `expires_at` already governs whether
     * a cart is USABLE — CartController treats an expired one as absent and
     * mints a fresh cart — so what was missing was only the reaping.
     *
     * 90 DAYS, measured from `updated_at` so a cart someone is still touching
     * is never in scope however old the row is.
     *
     * A CART REFERENCED BY A LEAD IS NEVER PRUNED. CheckoutController looks the
     * cart up by ulid with firstOrFail() and hash_equals() it against
     * `leads.cart_ulid` to prove cart and lead came from the same visitor. Reap
     * one of those and that checkout 404s — and the reference is held remotely
     * too, since prescribe-rx stores the lead uuid and returns it on its webhook.
     *
     * ATTRIBUTION IS NOT AT RISK, AND THE PREDICTED GUARD TURNED OUT TO BE
     * UNNECESSARY. This comment used to say "revisit when affiliate tracking
     * lands". It landed (2026-09-03), and it deliberately keeps nothing on the
     * cart: a referral lives in a first-party cookie, in the append-only
     * `referral_clicks` ledger, and on `leads`. None of those is pruned, so
     * reaping a cart destroys no attribution and no commission evidence.
     * Do not add referral columns here — that is what would create the problem.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('updated_at', '<', now()->subDays(90))
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('leads')
                ->whereColumn('leads.cart_ulid', 'carts.ulid'))
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('checkout_attempts')
                ->whereColumn('checkout_attempts.cart_id', 'carts.id'))
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('carts as predecessors')
                ->whereColumn('predecessors.successor_cart_id', 'carts.id'));
    }
}
