<?php

namespace App\Actions\Checkout;

use App\Actions\Customers\LinkCustomerToClaimedLeadAction;
use App\Actions\Exceptions\ActionException;
use App\Data\Checkout\CheckoutResultData;
use App\Data\PrescribeRx\AddressData;
use App\Data\PrescribeRx\IntakePackageSelectionData;
use App\Data\PrescribeRx\IntakeProductSelectionData;
use App\Data\PrescribeRx\PatientData;
use App\Data\PrescribeRx\UnifiedIntakeRequestData;
use App\Enums\Catalog\IntakeSelectionMode;
use App\Enums\OrderStatus;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Commerce\Cart;
use App\Models\Commerce\CartItem;
use App\Models\Commerce\CheckoutAttempt;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\ProviderInstance;
use App\Services\Checkout\CheckoutFingerprint;
use App\Services\Checkout\CheckoutProviderBinding;
use App\Services\PrescribeRx\Client;
use App\Settings\IntegrationSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubmitPrescribeRxCheckoutAction
{
    public function __construct(
        private readonly Client $prx,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * Freeze the local purchase before the provider call. Uncertain attempts are
     * reconciled by an operator; they are never automatically sent a second time.
     * No raw medical answers are persisted in the attempt ledger.
     *
     * @param  array<string, mixed>  $intakeAnswers
     */
    public function execute(Cart $cart, Lead $lead, array $intakeAnswers = []): CheckoutResultData
    {
        [$attempt, $request] = DB::transaction(function () use ($cart, $lead, $intakeAnswers): array {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            if (! filled($lead->cart_ulid) || ! hash_equals($lead->cart_ulid, $cart->ulid)) {
                throw ActionException::failed('Cart and lead do not belong to the same session.', 403);
            }

            $attempt = CheckoutAttempt::where('cart_id', $cart->id)->first();
            if ($attempt !== null && $attempt->lead_id !== $lead->id) {
                throw ActionException::failed('This cart already has a submitted checkout. Please contact support or start a new cart.', 409);
            }
            $items = $this->loadItems($cart);
            $answerFingerprint = $this->fingerprint($intakeAnswers);
            if ($attempt) {
                if (! hash_equals($attempt->answers_fingerprint, $answerFingerprint)) {
                    throw ActionException::failed('This checkout has already been submitted with different details. Please contact support.', 409);
                }
                if ($attempt->status === 'completed' && $items->isEmpty()) {
                    return [$attempt, null];
                }
                if (! hash_equals($attempt->cart_fingerprint, $this->cartFingerprint($cart, $items))) {
                    throw ActionException::failed('Your cart changed after checkout was submitted. Please contact support.', 409);
                }
            }
            if ($items->isEmpty()) {
                throw ActionException::failed('Your cart is empty.');
            }
            $selections = $this->resolveSelections($items);
            if (count($selections['products']) + count($selections['packages']) !== $items->count()) {
                throw ActionException::failed('We cannot take this order right now. Please contact support.', 503);
            }
            $contextUuid = $attempt?->uuid ?? (string) Str::uuid();
            $request = UnifiedIntakeRequestData::from([
                'patient' => $this->buildPatient($lead),
                'encounter_type_id' => $this->settings->prescribe_rx_encounter_type_id,
                'sales_org_id' => $this->settings->prescribe_rx_sales_org_id,
                'client_id' => $this->settings->prescribe_rx_client_id,
                'products' => $selections['products'],
                'packages' => $selections['packages'],
                'answers' => $intakeAnswers,
                'is_sandbox' => $this->settings->prescribe_rx_environment === 'sandbox' ? true : null,
                'metadata' => array_filter([
                    'checkout_context_uuid' => $contextUuid,
                    'lead_uuid' => $lead->uuid,
                    'cart_ulid' => $cart->ulid,
                    'utm_source' => $lead->utm_source,
                    'utm_medium' => $lead->utm_medium,
                    'utm_campaign' => $lead->utm_campaign,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
            $fingerprint = $this->fingerprint([
                'environment' => $this->settings->prescribe_rx_environment,
                'request' => $request->toArray(),
            ]);
            if ($attempt) {
                if (! hash_equals($attempt->request_fingerprint, $fingerprint)) {
                    throw ActionException::failed('This checkout has already been submitted with different details. Please contact support.', 409);
                }
                if ($attempt->status === 'completed') {
                    return [$attempt, null];
                }
                throw ActionException::failed('This order is awaiting confirmation. Please contact support before trying again.', 409);
            }

            $providerInstance = app(CheckoutProviderBinding::class)->configured($this->settings);
            $customerId = null;
            if ($lead->patient_id !== null) {
                $account = Patient::findOrFail($lead->patient_id);
                $customerId = app(LinkCustomerToClaimedLeadAction::class)->execute($account, $lead)->getKey();
            } elseif ($lead->customer_id !== null) {
                throw ActionException::failed('We could not verify the owner of this checkout. Please contact support.', 409);
            }
            // Calculate from precisely the rows frozen below, never a second cart query.
            $subtotal = $items->sum(fn (CartItem $item) => $item->lineTotal());
            $order = Order::create([
                'customer_id' => $customerId,
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'shipping_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $subtotal,
                'currency' => 'USD',
                'placed_at' => now(),
            ]);
            foreach ($items as $item) {
                $order->items()->create([
                    'name' => $item->itemable->name,
                    'sku' => $item->itemable->provider_product_sku ?? $item->itemable->provider_package_sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price_snapshot,
                    'line_total' => $item->lineTotal(),
                ]);
            }
            $attempt = CheckoutAttempt::create([
                'uuid' => $contextUuid,
                'provider_instance_id' => $providerInstance->id,
                'provider_tenant_kind' => filled($this->settings->prescribe_rx_client_id) ? 'client' : 'sales_organization',
                'provider_client_id' => $this->settings->prescribe_rx_client_id,
                'provider_sales_org_id' => $this->settings->prescribe_rx_sales_org_id,
                'order_fingerprint' => CheckoutFingerprint::order($order, $order->items()->orderBy('id')->get()),
                'cart_id' => $cart->id,
                'lead_id' => $lead->id,
                'order_id' => $order->id,
                'status' => 'submitting',
                'provider_idempotency_key' => 'checkout-'.$contextUuid,
                'request_fingerprint' => $fingerprint,
                'answers_fingerprint' => $answerFingerprint,
                'cart_fingerprint' => $this->cartFingerprint($cart, $items),
                'provider_environment' => $this->settings->prescribe_rx_environment,
                'provider_encounter_type_id' => $this->settings->prescribe_rx_encounter_type_id,
                'submitted_at' => now(),
            ]);

            return [$attempt, $request];
        });

        if ($request === null) {
            return CheckoutResultData::from($attempt->result);
        }
        try {
            // A committed attempt already owns this cart/lead pair. No database
            // locks cross this network boundary, and no retry calls the provider.
            $response = $this->prx->submitUnifiedIntake($request, $attempt->provider_idempotency_key);

            if (trim($response->encounter_id) === '' || trim($response->patient_chart_id) === '') {
                throw ActionException::failed('The order is awaiting provider confirmation. Please contact support.', 502);
            }
            // Commit the minimal receipt independently of finalization. A later
            // ownership/write failure must not discard known provider references.
            DB::transaction(function () use ($attempt, $response): void {
                $stored = CheckoutAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($stored->status !== 'submitting' || $stored->provider_receipt !== null) {
                    throw ActionException::failed('This order requires confirmation before it can be updated. Please contact support.', 409);
                }
                $stored->update(['receipt_received_at' => now(), 'provider_receipt' => [
                    'encounter_id' => $response->encounter_id,
                    'encounter_number' => $response->encounter_number,
                    'patient_id' => $response->patient_chart_id,
                    'status' => $response->status,
                ]]);
            });

            $instance = ProviderInstance::findOrFail($attempt->provider_instance_id);

            return app(FinalizePrescribeRxCheckoutAction::class)->execute($attempt, $instance->key);
        } catch (\Throwable $exception) {
            CheckoutAttempt::whereKey($attempt->id)->where('status', 'submitting')->update([
                'status' => 'unknown', 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function loadItems(Cart $cart): Collection
    {
        return $cart->items()->with([
            'itemable' => fn ($morphTo) => $morphTo->morphWith([Product::class => ['productType.productClass']]),
            'plan',
        ])->orderBy('id')->lockForUpdate()->get();
    }

    private function cartFingerprint(Cart $cart, Collection $items): string
    {
        return CheckoutFingerprint::cart($cart, $items);
    }

    private function fingerprint(array $value): string
    {
        return CheckoutFingerprint::make($value);
    }

    /**
     * Build the modern `products[]` / `packages[]` selection arrays.
     *
     * WHY THIS IS NOT A FLATTEN. The legacy shape sent member product ids for
     * a package, which discarded the package itself — and prescribe-rx keys
     * real behaviour off the package row (a labs hold before dispensing, a $0
     * shipping quote, consult-included pricing). Naming the package delegates
     * every one of those facts back to the side that owns it, and their side
     * already knows the contents, so nothing is lost by not enumerating them.
     *
     * Each line carries EXACTLY ONE identifier, per their contract. The UUID
     * is preferred over the human-readable number because it is stable across
     * a rename on their side; the number is the fallback for items mapped by
     * SKU alone. An unmapped item contributes nothing; the caller rejects the entire
     * checkout whenever any line cannot be represented.
     *
     * @param  Collection<int, CartItem>  $items
     * @return array{products: list<IntakeProductSelectionData>, packages: list<IntakePackageSelectionData>}
     */
    private function resolveSelections(Collection $items): array
    {
        $products = [];
        $packages = [];

        foreach ($items as $item) {
            if ($item->quantity < 1 || $item->unit_price_snapshot === null || (float) $item->unit_price_snapshot < 0) {
                throw ActionException::failed('We cannot take this order right now. Please contact support.', 503);
            }
            $itemable = $item->itemable;

            if (! $itemable) {
                continue;
            }

            // There is deliberately no `$itemable instanceof Plan` branch.
            // A plan cannot BE a cart line — `CartController::addItem` accepts
            // `type` in product|package only, and production holds just those
            // two itemable types. A chosen term arrives as `plan_id` ON the
            // line, handled below. The old resolver carried such a branch and
            // it was unreachable; it is absent rather than left as decoration
            // a later reader would trust.
            if ($itemable instanceof Package) {
                if ($item->quantity !== 1) {
                    throw ActionException::failed('Please order one of each package at a time.', 422);
                }
                $packages[] = $this->packageSelection($itemable, $item->plan);

                continue;
            }

            if ($itemable instanceof Product) {
                $products[] = $this->productSelection($itemable, $item);
            }
        }

        return [
            'products' => array_values(array_filter($products)),
            'packages' => array_values(array_filter($packages)),
        ];
    }

    /**
     * Their `packages[]` carries no quantity. The resolver rejects package
     * quantity greater than one before this method to avoid a partial order.
     */
    private function packageSelection(Package $package, ?Plan $plan): ?IntakePackageSelectionData
    {
        $id = $package->provider_package_id;
        $number = $package->provider_package_sku;

        if ($id === null && $number === null) {
            Log::warning('Prescribe-Rx: package omitted from intake — no provider mapping.', [
                'package_id' => $package->id,
                'package_name' => $package->name,
            ]);

            return null;
        }

        return new IntakePackageSelectionData(
            package_id: $id,
            package_number: $id === null ? $number : null,
            plan_id: $plan?->provider_plan_id,
        );
    }

    /**
     * Translate our lead vocabulary into theirs.
     *
     * Our lead form offers `prefer_not_to_say` (LeadController) and older rows
     * may carry `unspecified`; prescribe-rx accepts only male / female / other
     * and 422s on anything else. Gender is optional there, so an unmappable
     * value is DROPPED rather than guessed — declining to answer is not the
     * same as being "other", and inventing a value would put a wrong answer on
     * a clinical chart.
     */
    private static function mapGender(?string $gender): ?string
    {
        return in_array($gender, ['male', 'female', 'other'], true) ? $gender : null;
    }

    /**
     * A product nominates itself by EXACT product or by product TYPE, per its
     * own `intake_selection_mode`. Type mode sends a placeholder line so the
     * prescribing clinician chooses the variant and dose — required wherever
     * strength is provider-determined.
     *
     * Type mode deliberately does NOT fall back to the exact product when the
     * type is unmapped: falling back would silently dispense a specific dose
     * on an item whose whole point is that a clinician picks it. The line is
     * dropped and logged instead.
     */
    private function productSelection(Product $product, CartItem $item): ?IntakeProductSelectionData
    {
        $quantity = $item->quantity;
        $snapshot = $item->unit_price_snapshot !== null ? (float) $item->unit_price_snapshot : null;

        // Exactly one identifier per line throughout, so each slug is a
        // FALLBACK rather than a companion. The id wins when present because it
        // is unambiguous; the slug matters because it is far likelier to survive
        // a sandbox → production switch, which is how two of this install's
        // mappings came to point at nothing.
        if ($product->intake_selection_mode === IntakeSelectionMode::ProductType) {
            $type = $product->productType;
            $typeId = $type?->provider_product_type_id;
            $typeSlug = $type?->provider_product_type_slug;

            if ($typeId === null && $typeSlug === null) {
                Log::warning('Prescribe-Rx: product omitted from intake — set to product-type mode but its type has no provider mapping.', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_type_id' => $product->product_type_id,
                ]);

                return null;
            }

            return new IntakeProductSelectionData(
                product_type_id: $typeId,
                product_type_slug: $typeId === null ? $typeSlug : null,
                quantity: $quantity,
                snapshot_price: $snapshot,
            );
        }

        if ($product->intake_selection_mode === IntakeSelectionMode::ProductClass) {
            $class = $product->productType?->productClass;
            $classId = $class?->provider_product_class_id;
            $classSlug = $class?->provider_product_class_slug;

            if ($classId === null && $classSlug === null) {
                Log::warning('Prescribe-Rx: product omitted from intake — set to product-class mode but its class has no provider mapping.', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_type_id' => $product->product_type_id,
                ]);

                return null;
            }

            return new IntakeProductSelectionData(
                product_class_id: $classId,
                product_class_slug: $classId === null ? $classSlug : null,
                quantity: $quantity,
                snapshot_price: $snapshot,
            );
        }

        $id = $product->provider_product_id;
        $number = $product->provider_product_sku;

        if ($id === null && $number === null) {
            Log::warning('Prescribe-Rx: product omitted from intake — no provider mapping.', [
                'product_id' => $product->id,
                'product_name' => $product->name,
            ]);

            return null;
        }

        return new IntakeProductSelectionData(
            product_id: $id,
            product_number: $id === null ? $number : null,
            quantity: $quantity,
            snapshot_price: $snapshot,
        );
    }

    /**
     * THE SHIPPING ADDRESS IS THE CLINICALLY LOAD-BEARING ONE. Its state
     * decides which licensed clinician can be assigned to the encounter, so it
     * is sent as its own structured field rather than folded into the legacy
     * single-address shape. The lead's unprefixed address columns ARE that
     * shipping address (see the billing-address migration for why they are not
     * renamed).
     *
     * One address SHAPE is sent, never both: the explicit shipping/billing
     * pair whenever a shipping address exists, and the legacy `address` only
     * for a lead captured before shipping was collected, so their controller
     * still has something to normalise.
     */
    private function buildPatient(Lead $lead): PatientData
    {
        $shipping = $this->addressFrom(
            $lead->address_line1,
            $lead->address_line2,
            $lead->city,
            $lead->state,
            $lead->postal_code,
            $lead->country,
        );

        $billing = ($lead->billing_same_as_shipping ?? true)
            ? null
            : $this->addressFrom(
                $lead->billing_address_line1,
                $lead->billing_address_line2,
                $lead->billing_city,
                $lead->billing_state,
                $lead->billing_postal_code,
                $lead->billing_country,
            );

        return PatientData::from([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'date_of_birth' => $lead->date_of_birth?->toDateString(),
            'phone' => $lead->phone,
            'gender' => self::mapGender($lead->gender),
            'shipping_address' => $shipping,
            'billing_address' => $billing,
            'billing_same_as_shipping' => $shipping === null ? null : ($billing === null),
        ]);
    }

    /**
     * A PARTIAL address is worse than none: their validator rejects an
     * incomplete one and the failure surfaces as a 422 on the whole intake,
     * so anything missing a required part resolves to null here.
     */
    private function addressFrom(
        ?string $line1,
        ?string $line2,
        ?string $city,
        ?string $state,
        ?string $postalCode,
        ?string $country,
    ): ?AddressData {
        if (! $line1 || ! $city || ! $state || ! $postalCode) {
            return null;
        }

        // street2 is its own field on their side; concatenating it into street
        // produced a single unparseable line on the shipping label.
        return AddressData::from([
            'street' => $line1,
            'street2' => $line2 ?: null,
            'city' => $city,
            'state' => $state,
            'zip' => $postalCode,
            'country' => $country ?: 'US',
        ]);
    }
}
