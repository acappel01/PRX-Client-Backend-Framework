<?php

namespace App\Actions\Checkout;

use App\Data\Checkout\CheckoutResultData;
use App\Data\PrescribeRx\AddressData;
use App\Data\PrescribeRx\IntakePackageSelectionData;
use App\Data\PrescribeRx\IntakeProductSelectionData;
use App\Data\PrescribeRx\PatientData;
use App\Data\PrescribeRx\UnifiedIntakeRequestData;
use App\Enums\Catalog\IntakeSelectionMode;
use App\Enums\EncounterStatus;
use App\Enums\LeadStatus;
use App\Enums\OrderStatus;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Commerce\Cart;
use App\Models\Commerce\CartItem;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Lead;
use App\Services\PrescribeRx\Client;
use App\Services\PrescribeRx\Exceptions\PrescribeRxException;
use App\Settings\IntegrationSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SubmitPrescribeRxCheckoutAction
{
    public function __construct(
        private readonly Client $prx,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * Submit the cart to Prescribe-Rx as a unified intake, then
     * create the local Encounter + Order shell and mark the lead handed off.
     *
     * @param  array<string, mixed>  $intakeAnswers
     *
     * @throws RuntimeException|PrescribeRxException
     */
    public function execute(Cart $cart, Lead $lead, array $intakeAnswers = []): CheckoutResultData
    {
        // `itemable.products` is deliberately gone: we no longer flatten a
        // package into its members, so loading them was work whose result was
        // thrown away. A plan line reaches its parent package/product lazily —
        // a checkout carries a handful of lines, and morphWith constraints for
        // two relations on one of three morph types costs more than it saves.
        // `productType` is loaded through morphWith rather than a plain
        // `itemable.productType`: a nested load on a morphTo is applied to
        // EVERY morph type, and Package has no such relation, so the plain
        // form throws "Call to undefined relationship". The old
        // `itemable.products` had the mirror-image bug for product lines.
        $items = $cart->items()->with([
            'itemable' => fn ($morphTo) => $morphTo->morphWith([Product::class => ['productType']]),
            'plan',
        ])->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('Cart is empty.');
        }

        $selections = $this->resolveSelections($items);

        // NOTE: this fires only when the WHOLE cart is unmapped. A cart of
        // [mapped product, unmapped package] still submits, naming only the
        // product — each skipped line is logged by the resolver above.
        if ($selections['products'] === [] && $selections['packages'] === []) {
            throw new RuntimeException('No Prescribe-Rx selections found on cart items. Map the catalog first: packages need provider_package_id / provider_package_sku, products need provider_product_id / provider_product_sku.');
        }

        $isSandbox = $this->settings->prescribe_rx_environment === 'sandbox';

        $request = UnifiedIntakeRequestData::from([
            'patient' => $this->buildPatient($lead),
            'encounter_type_id' => $this->settings->prescribe_rx_encounter_type_id,
            'sales_org_id' => $this->settings->prescribe_rx_sales_org_id,
            'client_id' => $this->settings->prescribe_rx_client_id,
            'products' => $selections['products'],
            'packages' => $selections['packages'],
            'answers' => $intakeAnswers,
            // Only ever ASSERTED, never denied. Their server auto-flags
            // test-looking names as sandbox; sending an explicit `false` in
            // production could override that heuristic and let a test-named
            // submission reach real fulfilment and billing. Absent is what
            // production sent before this change, so absent is what it sends
            // now — this is deliberately not `(bool) $isSandbox`.
            'is_sandbox' => $isSandbox ?: null,
            'metadata' => array_filter([
                'lead_uuid' => $lead->uuid,
                'cart_ulid' => $cart->ulid,
                'utm_source' => $lead->utm_source,
                'utm_medium' => $lead->utm_medium,
                'utm_campaign' => $lead->utm_campaign,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        // PRX API call is intentionally outside the DB transaction.
        //
        // The idempotency key is derived from the cart and lead rather than
        // generated, so a retry of the SAME submission replays their stored
        // response (24h window) instead of minting a second encounter for one
        // patient. It must stay stable across retries — do not add a timestamp.
        //
        // KNOWN EDGE, accepted: if their call succeeds but the local
        // transaction below fails, and the visitor then EDITS the cart and
        // resubmits under the same lead inside 24h, their stored response for
        // the old selection replays while we snapshot the new items. Hashing
        // the selection into the key would close it at the cost of making a
        // genuine retry of an unchanged cart look new.
        $prxResponse = $this->prx->submitUnifiedIntake(
            $request,
            $this->idempotencyKey($cart, $lead),
        );

        $order = DB::transaction(function () use ($cart, $lead, $items, $prxResponse, $isSandbox): Order {
            $encounter = Encounter::create([
                'lead_id' => $lead->id,
                'prescribe_rx_encounter_id' => $prxResponse->encounter_id,
                'prescribe_rx_patient_id' => $prxResponse->patient_chart_id,
                'prescribe_rx_encounter_type_id' => $this->settings->prescribe_rx_encounter_type_id,
                'status' => EncounterStatus::Submitted,
                'submitted_at' => now(),
                'is_sandbox' => $isSandbox,
                'total_amount' => $cart->subtotal(),
            ]);

            $order = Order::create([
                'encounter_id' => $encounter->id,
                'status' => OrderStatus::Pending,
                'subtotal' => $cart->subtotal(),
                'tax_amount' => 0,
                'shipping_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $cart->subtotal(),
                'currency' => 'USD',
                'placed_at' => now(),
            ]);

            // Snapshot order items from cart
            foreach ($items as $item) {
                $name = $item->itemable?->name ?? 'Unknown item';
                $price = (float) ($item->unit_price_snapshot ?? 0);

                $order->items()->create([
                    'name' => $name,
                    'sku' => $item->itemable?->provider_product_sku ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => $price,
                    'line_total' => $price * $item->quantity,
                ]);
            }

            $lead->update([
                'status' => LeadStatus::HandedOff->value,
                'prescribe_rx_encounter_id' => $prxResponse->encounter_id,
                'prescribe_rx_patient_id' => $prxResponse->patient_chart_id,
                'prescribe_rx_response' => $prxResponse->toArray(),
                'handed_off_at' => now(),
            ]);

            // Clear cart items but keep the cart record for analytics
            $cart->items()->delete();

            return $order;
        });

        return CheckoutResultData::from([
            'order_uuid' => $order->uuid,
            'checkout_path' => 'prx',
            'prescribe_rx' => [
                'encounter_id' => $prxResponse->encounter_id,
                'encounter_number' => $prxResponse->encounter_number,
                'patient_id' => $prxResponse->patient_chart_id,
                'status' => $prxResponse->status,
            ],
        ]);
    }

    /**
     * Namespaced per INSTALL, never per brand — this backend ships as a
     * generic product and several deployments submit to the same
     * prescribe-rx tenant, so an unprefixed key could collide across them.
     * Uses the app name the same way the Redis/Horizon prefixes do; nothing
     * client-specific may be hardcoded here.
     */
    private function idempotencyKey(Cart $cart, Lead $lead): string
    {
        return Str::slug((string) config('app.name', 'app')).'-'.$cart->ulid.'-'.$lead->uuid;
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
     * SKU alone. An unmapped item contributes nothing and is skipped — the
     * caller raises if that leaves the whole selection empty.
     *
     * @param  Collection<int, CartItem>  $items
     * @return array{products: list<IntakeProductSelectionData>, packages: list<IntakePackageSelectionData>}
     */
    private function resolveSelections(Collection $items): array
    {
        $products = [];
        $packages = [];

        foreach ($items as $item) {
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
     * Their `packages[]` carries no quantity, so a package bought more than
     * once is nominated once on the encounter; the local order rows keep the
     * real quantity and the money.
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

        if ($product->intake_selection_mode === IntakeSelectionMode::ProductType) {
            $typeId = $product->productType?->provider_product_type_id;

            if ($typeId === null) {
                Log::warning('Prescribe-Rx: product omitted from intake — set to product-type mode but its type has no provider mapping.', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_type_id' => $product->product_type_id,
                ]);

                return null;
            }

            return new IntakeProductSelectionData(
                product_type_id: $typeId,
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

    private function buildPatient(Lead $lead): PatientData
    {
        $address = null;

        if ($lead->address_line1 && $lead->city && $lead->state && $lead->postal_code) {
            // street2 is its own field on their side; concatenating it into
            // street produced a single unparseable line on the shipping label.
            $address = AddressData::from([
                'street' => $lead->address_line1,
                'street2' => $lead->address_line2 ?: null,
                'city' => $lead->city,
                'state' => $lead->state,
                'zip' => $lead->postal_code,
                'country' => $lead->country ?? 'US',
            ]);
        }

        return PatientData::from([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'date_of_birth' => $lead->date_of_birth?->toDateString(),
            'phone' => $lead->phone,
            'gender' => self::mapGender($lead->gender),
            'address' => $address,
        ]);
    }
}
