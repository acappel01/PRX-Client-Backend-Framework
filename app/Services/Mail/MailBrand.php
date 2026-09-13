<?php

namespace App\Services\Mail;

use App\Settings\BrandSettings;

/**
 * Who a recipient is told the email is from, in the body — and where its header
 * links to.
 *
 * Laravel's stock markdown layout prints `config('app.name')` and links to
 * `config('app.url')`. On this backend those name the ADMIN: the product's
 * APP_NAME ("PRX Backend") and the admin hostname. So the first live plan email
 * greeted a patient as "PRX Backend", signed off as it, and linked its header
 * to the admin panel. APP_NAME cannot simply be changed per brand — the Filament
 * panels use it — so mail reads the brand instead.
 *
 * `rescue` because mail can render during install, before settings exist; the
 * From name is the fallback because MailConfigurator has already resolved it to
 * the operator's choice or the brand.
 */
class MailBrand
{
    public function name(): string
    {
        $brand = rescue(fn (): ?string => app(BrandSettings::class)->name, null, false);

        return filled($brand) ? $brand : (string) config('mail.from.name');
    }

    /**
     * The public site, never the admin. Null when neither is configured: a
     * header with no link is better than one pointing a patient at a login page.
     *
     * Deliberately NOT the same chain as SendPlanEmail::planUrl (frontend URL,
     * then APP_URL): that link must reach a route that exists, this one only
     * names the brand, so it prefers the brand's canonical site and never falls
     * back to the admin.
     */
    public function url(): ?string
    {
        $site = rescue(fn (): ?string => app(BrandSettings::class)->site_url, null, false);
        $url = filled($site) ? $site : config('cms.frontend.url');

        return filled($url) ? rtrim((string) $url, '/') : null;
    }
}
