<?php

namespace App\Services\PrescribeRx\Embed;

use App\Enums\Catalog\IntakeSelectionMode;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Lead;
use App\Settings\BillingSettings;
use App\Settings\IntegrationSettings;

/**
 * Builds the JSON payload our Blade component hands to the prescribe-rx
 * embed SDK. Two responsibilities:
 *
 *   1) Translate a Lead's locally-collected fields into the snake_case keys
 *      the embed's prefill() method expects (so the user skips name / email /
 *      phone / DOB / address steps inside the embed).
 *   2) Translate the Lead's cart_items snapshot into the selectPackages /
 *      selectProducts / selectPlan calls that PRX uses to drive its dynamic
 *      step + question rendering. PRX's catalog is the source of truth for
 *      which questions appear; we just nominate the products.
 *
 * No PHI is read or stored here — this is purely demographic + cart data.
 */
class PrxEmbedPayloadBuilder
{
    public function __construct(
        protected IntegrationSettings $settings,
        protected BillingSettings $billing,
    ) {}

    /**
     * Build the full payload object for a Lead.
     *
     * @return array{
     *   embedCode: ?string,
     *   prefill: array<string, mixed>,
     *   packages: array<int, string>,
     *   products: array<int, string>,
     *   productTypes: array<int, array{product_type_id?: string, product_type_slug?: string}>,
     *   productClasses: array<int, array{product_class_id?: string, product_class_slug?: string}>,
     *   planIds: array<int, string>,
     *   skipSteps: array<int, string>,
     *   metadata: array<string, mixed>,
     * }
     */
    public function forLead(Lead $lead): array
    {
        return [
            'embedCode' => $this->settings->prescribe_rx_embed_code,
            'prefill' => $this->buildPrefill($lead),
            'packages' => $this->packageNumbersFromCart($lead),
            'products' => $this->productNumbersFromCart($lead),
            'productTypes' => $this->productTypesFromCart($lead),
            'productClasses' => $this->productClassesFromCart($lead),
            'planIds' => $this->planIdsFromCart($lead),
            // Step slugs are encounter-type-specific. The list comes from
            // config/prescribe-rx.php → embed.skip_steps and contains the
            // common variants for personal-info + product-selection. The
            // SDK silently ignores unknown slugs, so listing multiple
            // variants is safe — the embed picks the ones that match its
            // current schema. Adjust the config when you find the exact
            // slugs in PRX admin.
            'skipSteps' => $this->skipStepsForLead($lead),
            'metadata' => [
                'lead_uuid' => $lead->uuid,
                'lead_email' => $lead->email,
                'cart_subtotal' => $lead->cart_subtotal !== null ? (float) $lead->cart_subtotal : null,
                'utm_source' => $lead->utm_source,
                'utm_medium' => $lead->utm_medium,
                'utm_campaign' => $lead->utm_campaign,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPrefill(Lead $lead): array
    {
        // THE KEY NAMES ARE THEIRS, NOT OURS, and ours were wrong. Their
        // prefill vocabulary is `address` / `city` / `state` / `zip`; we were
        // sending `address_line1` / `postal_code` / `country`, none of which
        // they document, so the whole address silently failed to prefill.
        //
        // `gender` is passed through rather than mapped: their prefill accepts
        // male / female / other, and this lead column is already constrained
        // to that vocabulary at capture.
        return array_filter([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'date_of_birth' => $lead->date_of_birth?->toDateString(),
            'gender' => $lead->gender,
            'address' => self::streetLine($lead),
            'city' => $lead->city,
            'state' => $lead->state,
            'zip' => $lead->postal_code,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * One street line, because their flat prefill vocabulary has exactly one
     * key for it and no `street2`.
     *
     * A second line is joined with a comma rather than dropped: this prefills a
     * field the patient can see and correct, and a missing apartment number is
     * a mis-delivered prescription. That is the opposite of the intake API,
     * where `street2` is its own field and concatenating produced an
     * unparseable shipping label — different surface, different rule.
     */
    private static function streetLine(Lead $lead): ?string
    {
        $line1 = trim((string) $lead->address_line1);

        if ($line1 === '') {
            return null;
        }

        $line2 = trim((string) $lead->address_line2);

        return $line2 === '' ? $line1 : $line1.', '.$line2;
    }

    /**
     * Resolve package numbers (PKG-XXXXX) for every cart line of type=package.
     * Falls back to UUIDs if no number is mapped.
     *
     * Reads `provider_package_sku` / `provider_package_id` — the real columns.
     * These were once written as `prescribe_rx_package_*`, which do not exist;
     * Eloquent returns null for a missing attribute rather than raising, so the
     * embed silently opened with nothing selected. Assert POPULATED output when
     * touching this, never merely that the key is present.
     *
     * @return array<int, string>
     */
    protected function packageNumbersFromCart(Lead $lead): array
    {
        $items = collect($lead->cart_items ?? [])->where('resource_type', 'package');
        if ($items->isEmpty()) {
            return [];
        }

        $packages = Package::query()->whereIn('id', $items->pluck('resource_id'))->get();

        return $packages
            ->map(fn (Package $p) => trim((string) ($p->provider_package_sku ?: $p->provider_package_id)) ?: null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve identifiers for every cart line of type=product.
     *
     * THE UUID IS WHAT THE EMBED RESOLVES. Measured against the live embed,
     * not inferred — and it contradicts their SDK's own docblock, which
     * describes `selectProducts(productNumbers)` with `['PROD-001']` examples.
     * Trust the measurement:
     *
     *     ?products=<their product UUID>  -> initialProductIds: ["<uuid>"]
     *     ?products=<their product SKU>   -> initialProductIds: []
     *
     * Verified both ways against a product taken from their own production
     * catalogue, so it is not a property of our data. `init()` serialises
     * `options.products` into that query param, and it is what seeds the
     * wizard's initial state — which is what decides the conditional steps.
     *
     * AN ID THAT IS NOT IN THEIR CATALOGUE ALSO RESOLVES TO NOTHING, and the
     * embed reports "no products found" while still skipping every step. That
     * looks identical to a format problem and is not one: two of this
     * install's three mapped products pointed at ids absent from the 265-item
     * production catalogue, almost certainly sandbox ids captured while the
     * environment setting was sandbox. Check the id EXISTS before concluding
     * the format is wrong.
     *
     * Values are trimmed: at least one mapped identifier here carries a
     * leading space, which would defeat an exact match.
     * Plan items also implicitly drag in their parent package's products via
     * the embed (PRX dereferences plan→package→items server-side); we don't
     * unroll that here.
     *
     * @return array<int, string>
     */
    protected function productNumbersFromCart(Lead $lead): array
    {
        $items = collect($lead->cart_items ?? [])->where('resource_type', 'product');
        if ($items->isEmpty()) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $items->pluck('resource_id'))
            ->where('intake_selection_mode', IntakeSelectionMode::Product)
            ->get()
            ->map(fn (Product $p) => trim((string) ($p->provider_product_id ?: $p->provider_product_sku)) ?: null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Products whose dose is provider-determined nominate their TYPE instead,
     * so the clinician picks the variant inside the embed. Feeds the SDK's
     * `selectProductTypes([{product_type_id}])`, which is a different call
     * from `selectProducts()` — hence a separate payload key rather than a
     * flag on the products array.
     *
     * A product in type mode whose type is unmapped is DROPPED, never demoted
     * to its exact product: demoting would pin a specific dose on an item that
     * exists to have one chosen.
     *
     * Their SDK takes exactly one of `product_type_id` or `product_type_slug`
     * per entry. The id wins when present; the slug is the fallback, and the
     * more durable identifier — a UUID is environment-specific and resolves to
     * nothing after a sandbox → production switch, silently.
     *
     * @return array<int, array{product_type_id?: string, product_type_slug?: string}>
     */
    protected function productTypesFromCart(Lead $lead): array
    {
        $items = collect($lead->cart_items ?? [])->where('resource_type', 'product');

        if ($items->isEmpty()) {
            return [];
        }

        return Product::query()
            ->with('productType')
            ->whereIn('id', $items->pluck('resource_id'))
            ->where('intake_selection_mode', IntakeSelectionMode::ProductType)
            ->get()
            ->map(function (Product $p): ?array {
                $id = trim((string) $p->productType?->provider_product_type_id) ?: null;

                if ($id !== null) {
                    return ['product_type_id' => $id];
                }

                $slug = trim((string) $p->productType?->provider_product_type_slug) ?: null;

                return $slug !== null ? ['product_type_slug' => $slug] : null;
            })
            ->filter()
            ->unique(fn (array $entry) => implode(':', $entry))
            ->values()
            ->all();
    }

    /**
     * Products whose compound is the prescriber's call entirely nominate their
     * CLASS — one level broader than a type. Feeds the SDK's
     * `selectProductClasses([{product_class_id}])`, a separate call again, so a
     * separate payload key.
     *
     * Broader is not merely looser: a wizard step gated on
     * `for_product_class_ids` renders when a class is nominated, so this
     * reveals the WIDEST set of conditional clinical questions.
     *
     * An unmapped class is DROPPED, never demoted, for the same reason a type
     * is: demoting would pin a specific product on an item that exists to have
     * one chosen.
     *
     * @return array<int, array{product_class_id?: string, product_class_slug?: string}>
     */
    protected function productClassesFromCart(Lead $lead): array
    {
        $items = collect($lead->cart_items ?? [])->where('resource_type', 'product');

        if ($items->isEmpty()) {
            return [];
        }

        return Product::query()
            ->with('productType.productClass')
            ->whereIn('id', $items->pluck('resource_id'))
            ->where('intake_selection_mode', IntakeSelectionMode::ProductClass)
            ->get()
            ->map(function (Product $p): ?array {
                $class = $p->productType?->productClass;

                $id = trim((string) $class?->provider_product_class_id) ?: null;

                if ($id !== null) {
                    return ['product_class_id' => $id];
                }

                $slug = trim((string) $class?->provider_product_class_slug) ?: null;

                return $slug !== null ? ['product_class_slug' => $slug] : null;
            })
            ->filter()
            ->unique(fn (array $entry) => implode(':', $entry))
            ->values()
            ->all();
    }

    /**
     * Step slugs to skip in the embed. Pulled from config so admin can
     * tune them per-encounter-type without redeploying. Could later be
     * conditioned on lead state (e.g. don't skip personal-info if a
     * required field is missing) — for now it's a static list.
     *
     * @return array<int, string>
     */
    protected function skipStepsForLead(Lead $lead): array
    {
        $slugs = (array) config('prescribe-rx.embed.skip_steps', []);

        // WHO TAKES THE MONEY DECIDES WHICH STEPS RENDER, and it is one
        // setting so the two sides cannot both believe they are collecting —
        // the failure mode that produces a double charge, or none.
        if ($this->billing->collectsPaymentOnSite()) {
            $slugs = array_merge($slugs, (array) config('prescribe-rx.embed.payment_step_slugs', []));
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Resolve plan IDs (UUIDs) for every cart line of type=plan.
     * `selectPlan(planId)` in the embed SDK takes an id, not a number, so the
     * id is preferred here and the sku is only a fallback.
     *
     * @return array<int, string>
     */
    protected function planIdsFromCart(Lead $lead): array
    {
        $items = collect($lead->cart_items ?? [])->where('resource_type', 'plan');
        if ($items->isEmpty()) {
            return [];
        }

        $plans = Plan::query()->whereIn('id', $items->pluck('resource_id'))->get();

        return $plans
            ->map(fn (Plan $p) => trim((string) ($p->provider_plan_id ?: $p->provider_plan_sku)) ?: null)
            ->filter()
            ->values()
            ->all();
    }
}
