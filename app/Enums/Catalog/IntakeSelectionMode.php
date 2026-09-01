<?php

namespace App\Enums\Catalog;

/**
 * How a product nominates itself on a prescribe-rx intake.
 *
 * Their selection line accepts EXACTLY ONE identifier, and which one we send
 * changes who picks the final SKU:
 *
 *   - `Product` — we name the exact product. What the patient chose is what
 *     gets dispensed.
 *   - `ProductType` — we name the product TYPE and prescribe-rx creates a
 *     placeholder line, letting the prescribing clinician choose the specific
 *     variant and dose. Required for products whose strength or presentation is
 *     provider-determined, which is a good number of this catalog.
 *
 * This is per-product because it is a clinical property of the item, not a
 * deployment-wide setting. It drives BOTH the API path
 * (`products[].product_id` vs `products[].product_type_id`) and the embed
 * (`selectProducts()` vs `selectProductTypes()`), which is why it lives on the
 * model rather than in either caller.
 */
enum IntakeSelectionMode: string
{
    case Product = 'product';
    case ProductType = 'product_type';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Exact product',
            self::ProductType => 'Product type (clinician chooses dose)',
        };
    }

    public function helperText(): string
    {
        return match ($this) {
            self::Product => 'Sends this exact product. The patient gets what they selected.',
            self::ProductType => 'Sends the product type instead, so the prescribing clinician picks the variant and dose.',
        };
    }
}
