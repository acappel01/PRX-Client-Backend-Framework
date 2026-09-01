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
 *   - `ProductClass` — one level broader again: the clinician may pick any
 *     product of any type within the class. The loosest instruction we can
 *     send, and the one that reveals the widest set of conditional steps.
 *
 * This is per-product because it is a clinical property of the item, not a
 * deployment-wide setting. It drives BOTH the API path (`products[].product_id`
 * vs `product_type_id` vs `product_class_id`) and the embed (`selectProducts()`
 * vs `selectProductTypes()` vs `selectProductClasses()`), which is why it lives
 * on the model rather than in either caller.
 *
 * IT ALSO DECIDES WHICH CONDITIONAL STEPS THE EMBED RENDERS. A wizard step
 * gated on `for_product_type_ids` or `for_product_class_ids` appears when a
 * matching type or class is nominated — either directly, or walked up from a
 * concrete product. Choosing a broader mode therefore reveals MORE clinical
 * questions, not fewer.
 */
enum IntakeSelectionMode: string
{
    case Product = 'product';
    case ProductType = 'product_type';
    case ProductClass = 'product_class';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Exact product',
            self::ProductType => 'Product type (clinician chooses dose)',
            self::ProductClass => 'Product class (clinician chooses product)',
        };
    }

    public function helperText(): string
    {
        return match ($this) {
            self::Product => 'Sends this exact product. The patient gets what they selected.',
            self::ProductType => 'Sends the product type instead, so the prescribing clinician picks the variant and dose.',
            self::ProductClass => 'Sends only the class, so the clinician may pick any product of any type within it. The broadest instruction — use it when the specific compound is genuinely the prescriber\'s call.',
        };
    }
}
