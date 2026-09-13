<?php

namespace Tests\Feature\Mail;

use App\Mail\PlanReadyMail;
use App\Models\Lead;
use App\Settings\BrandSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * Mail names the BRAND, never the backend it runs on.
 *
 * Laravel's stock mail layout and notification template print APP_NAME and link
 * to APP_URL. On this backend those are the admin product ("PRX Backend") and
 * the admin hostname, so the first live plan email greeted a patient as
 * "PRX Backend" in its header, sign-off and footer, and linked them to the admin
 * login. The Brand name in the admin replaced none of it.
 *
 * Rendered for real, HTML and text: the overrides resolve through
 * `mail.markdown.paths`, and an app-level `markdown` block without that key
 * would shadow the framework's and drop them without a word.
 */
class MailBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Backend Product',
            'app.url' => 'https://admin.backend.example.test',
        ]);
    }

    private function brand(?string $siteUrl): void
    {
        $brand = app(BrandSettings::class);
        $brand->name = 'Brand Co';
        $brand->site_url = $siteUrl;
        $brand->save();
    }

    private function plan(): PlanReadyMail
    {
        $lead = new Lead(['first_name' => 'Ada', 'email' => 'a@example.test']);

        return new PlanReadyMail($lead, 'https://shop.example.test/plan/abc');
    }

    private function assertBranded(string $rendered): void
    {
        $this->assertStringContainsString('Brand Co', $rendered);
        $this->assertStringNotContainsString('Backend Product', $rendered);
        $this->assertStringNotContainsString('admin.backend.example.test', $rendered);
    }

    public function test_the_plan_email_html_names_and_links_the_brand(): void
    {
        $this->brand('https://shop.example.test');

        $html = $this->plan()->render();

        $this->assertBranded($html);
        $this->assertStringContainsString('href="https://shop.example.test"', $html);
        $this->assertStringContainsString('&copy; '.date('Y').' Brand Co', htmlentities(html_entity_decode($html)));
    }

    public function test_the_plan_email_text_part_names_the_brand(): void
    {
        $this->brand('https://shop.example.test');

        $mail = $this->plan();
        $text = app(Markdown::class)->renderText($mail->content()->markdown, $mail->content()->with)->toHtml();

        $this->assertBranded($text);
        $this->assertStringContainsString('Brand Co: https://shop.example.test', $text);
    }

    /** No site configured: an unlinked header, never an empty href or the admin. */
    public function test_without_a_site_url_the_header_is_not_a_link(): void
    {
        config(['cms.frontend.url' => null]);
        $this->brand(null);

        $html = $this->plan()->render();

        $this->assertBranded($html);
        $this->assertStringNotContainsString('href=""', $html);
        $this->assertMatchesRegularExpression('/<td class="header"[^>]*>\s*Brand Co\s*<\/td>/', $html);

        $mail = $this->plan();
        $text = app(Markdown::class)->renderText($mail->content()->markdown, $mail->content()->with)->toHtml();
        $this->assertBranded($text);
        $this->assertStringStartsWith('Brand Co', ltrim($text));
        $this->assertStringNotContainsString('Brand Co:', $text);
    }

    public function test_the_frontend_url_stands_in_for_a_missing_site_url(): void
    {
        config(['cms.frontend.url' => 'https://front.example.test/']);
        $this->brand(null);

        $this->assertStringContainsString('href="https://front.example.test"', $this->plan()->render());
    }

    /** Filament's password-reset mail (both panels) goes through this template. */
    public function test_notification_emails_sign_off_as_the_brand(): void
    {
        $this->brand('https://shop.example.test');

        $html = (string) (new MailMessage)->line('Reset your password.')->render();

        $this->assertBranded($html);
    }
}
