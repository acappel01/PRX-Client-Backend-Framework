<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Blog\BlogCategoryController;
use App\Http\Controllers\Api\V1\Blog\BlogTagController;
use App\Http\Controllers\Api\V1\Blog\PostController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\FacetController;
use App\Http\Controllers\Api\V1\Catalog\PackageController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\TagController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Cms\LayoutController;
use App\Http\Controllers\Api\V1\Cms\MenuController;
use App\Http\Controllers\Api\V1\Content\SlugRedirectController;
use App\Http\Controllers\Api\V1\Cms\PageController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\Content\FaqController;
use App\Http\Controllers\Api\V1\Content\ProfileController;
use App\Http\Controllers\Api\V1\Intake\IntakeSchemaController;
use App\Http\Controllers\Api\V1\Kb\CompoundController;
use App\Http\Controllers\Api\V1\Kb\HealthGoalController;
use App\Http\Controllers\Api\V1\Leads\LeadController;
use App\Http\Controllers\Api\V1\Leads\LeadIntakeController;
use App\Http\Controllers\Api\V1\Leads\LeadPlanController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Patient\AuthController as PatientAuthController;
use App\Http\Controllers\Api\V1\Patient\PortalController;
use App\Http\Controllers\Api\V1\Quiz\QuizController;
use App\Http\Controllers\Api\V1\Recommendations\ProtocolPreviewController;
use App\Http\Controllers\Api\V1\Webhooks\PrescribeRxWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  —  /api/v1/
|--------------------------------------------------------------------------
|
| Token auth: Laravel Sanctum (Bearer tokens).
|
| Ability scopes:
|   frontend:*        Issued to the React/Next.js frontend for a logged-in user.
|   patient:*         Issued to patient self-service portal sessions.
|   integration:*     Issued to 3rd-party integrations (webhooks, CRM, etc.).
|   admin:*           Issued to server-to-server admin tooling.
|
| Public (no auth required):
|   GET  /api/v1/config                      Bootstrap call — brand, theme, contact, provider caps.
|   GET  /api/v1/pages                       All published pages (list, no sections).
|   GET  /api/v1/pages/{slug}               Single published page with typed sections.
|   GET  /api/v1/catalog/categories          Category tree or flat list.
|   GET  /api/v1/catalog/categories/{slug}   Category detail.
|   GET  /api/v1/catalog/products            Paginated product list (filter: category, tag, featured, search).
|   GET  /api/v1/catalog/products/{slug}     Product detail with categories + tags.
|   GET  /api/v1/catalog/packages            Paginated package list with plans.
|   GET  /api/v1/catalog/packages/{slug}     Package detail with products + plans.
|   GET  /api/v1/catalog/tags                All visible tags.
|   GET  /api/v1/blog/posts                  Paginated blog posts.
|   GET  /api/v1/blog/posts/{slug}           Single blog post.
|   GET  /api/v1/blog/categories             Blog categories.
|   GET  /api/v1/blog/tags                   Blog tags.
|   GET  /api/v1/health-goals               Intake quiz choices (active + offered by default).
|   GET  /api/v1/kb/compounds                Published compound monographs (peptides by default).
|   GET  /api/v1/kb/compounds/{slug}         Single monograph with its full prose sections.
|   GET  /api/v1/cart                        View cart (X-Cart-Token header).
|   POST /api/v1/cart/items                  Add item to cart.
|   POST /api/v1/leads                       Create lead at checkout start.
|   GET  /api/v1/leads/{uuid}                Retrieve lead by UUID.
|   GET  /api/v1/intake/schema               Resolve intake question schema for cart products.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    // ── Public ──────────────────────────────────────────────────────────
    // No auth required. Safe to cache at CDN edge.

    Route::get('config', ConfigController::class)->name('config');

    // ── CMS ──────────────────────────────────────────────────────────────
    // Published pages with typed section blueprints.

    Route::prefix('pages')->name('pages.')->middleware('throttle:api')->group(function (): void {
        Route::get('/', [PageController::class, 'index'])->name('index');
        Route::get('{slug}', [PageController::class, 'show'])->name('show');
    });

    Route::prefix('menus')->name('menus.')->middleware('throttle:api')->group(function (): void {
        Route::get('/', [MenuController::class, 'index'])->name('index');
        Route::get('{slug}', [MenuController::class, 'show'])->name('show');
    });

    Route::get('layout', LayoutController::class)->name('layout')->middleware('throttle:api');

    // ── Catalog ──────────────────────────────────────────────────────────
    // Fully public — storefront browse does not require login.

    Route::prefix('catalog')->name('catalog.')->middleware('throttle:api')->group(function (): void {
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('categories/{category:slug}', [CategoryController::class, 'show'])->name('categories.show');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product:slug}', [ProductController::class, 'show'])->name('products.show');

        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('packages/{package:slug}', [PackageController::class, 'show'])->name('packages.show');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');

        Route::get('facets', [FacetController::class, 'index'])->name('facets.index');
    });

    // ── Slug redirects ───────────────────────────────────────────────────
    // Where a renamed record went. Called by the frontend ONLY after its own
    // lookup 404'd, so a renamed URL can redirect (308) instead of dead-ending. Public
    // for the same reason the catalog is: it answers about published content
    // and reveals nothing a visitor could not already see by browsing.
    Route::get('slug-redirect', SlugRedirectController::class)
        ->name('slug-redirect')
        ->middleware('throttle:api');

    // ── Blog ─────────────────────────────────────────────────────────────
    // Fully public — no auth required to read published posts.

    Route::prefix('blog')->name('blog.')->middleware('throttle:api')->group(function (): void {
        Route::get('posts', [PostController::class, 'index'])->name('posts.index');
        Route::get('posts/{post:slug}', [PostController::class, 'show'])->name('posts.show');
        Route::get('categories', [BlogCategoryController::class, 'index'])->name('categories.index');
        Route::get('categories/{blogCategory:slug}', [BlogCategoryController::class, 'show'])->name('categories.show');
        Route::get('tags', [BlogTagController::class, 'index'])->name('tags.index');
    });

    // ── Knowledge base ───────────────────────────────────────────────────
    // Fully public, and narrower than it looks: Compound::published() also
    // requires a regulatory status, so a monograph without one 404s here even
    // with its published flag set. A clinician reviewer is NOT required — see
    // the model for why requiring one would manufacture bylines.

    // Health goals sit OUTSIDE the kb prefix: they are the intake quiz's
    // vocabulary and drive commerce recommendations, not knowledge-base
    // content. Nesting them under /kb would say the opposite.
    Route::get('health-goals', [HealthGoalController::class, 'index'])
        ->name('health-goals.index')
        ->middleware('throttle:api');

    // The quiz's own questions — public, identical for everyone, cacheable.
    // The opposite of protocol/preview below, which is per-visitor and must
    // never be cached.
    Route::get('quiz/{slug?}', [QuizController::class, 'show'])
        ->name('quiz.show')
        ->middleware('throttle:api');

    // What the quiz may offer a visitor, given the goals they picked and what
    // they told us about themselves. POST because a GET would log the pairing
    // of a health goal with a sex and an age against an IP; see the controller.
    // Stores nothing — a preview must not create a record about someone who
    // then closes the tab.
    Route::post('protocol/preview', [ProtocolPreviewController::class, 'store'])
        ->name('protocol.preview')
        ->middleware('throttle:api');

    Route::prefix('kb')->name('kb.')->middleware('throttle:api')->group(function (): void {
        Route::get('compounds', [CompoundController::class, 'index'])->name('compounds.index');
        Route::get('compounds/{compound:slug}', [CompoundController::class, 'show'])->name('compounds.show');
    });

    // ── Leads ────────────────────────────────────────────────────────────
    // Lead creation is public (called before login). Retrieval by UUID is
    // also public — the UUID is opaque and only known from the creation response.

    Route::prefix('leads')->name('leads.')->middleware('throttle:api')->group(function (): void {
        Route::post('/', [LeadController::class, 'store'])->name('store');
        Route::get('{lead:uuid}', [LeadController::class, 'show'])->name('show');

        // The matched protocol for a lead. Separate from `show` because it
        // resolves the catalogue live and `show` must stay a cheap read of the
        // row — the plan page fetches both and the quiz submit response wants
        // only the second.
        Route::get('{lead:uuid}/plan', [LeadPlanController::class, 'show'])->name('plan');

        // Everything the storefront needs to host the clinical intake embed
        // itself: embed config, cart summary, and who is collecting payment.
        // The embed used to live on a server-rendered page in this domain,
        // which put the clinical step outside the storefront's branding,
        // compliance copy and cart, with no way back.
        Route::get('{lead:uuid}/intake', [LeadIntakeController::class, 'show'])->name('intake');

        // Advisory completion ping from the embed. The web route of the same
        // name is CSRF/session bound and unreachable cross-origin, which is
        // what the storefront now is.
        Route::post('{lead:uuid}/intake/complete', [LeadIntakeController::class, 'complete'])->name('intake.complete');
    });

    // ── Intake ────────────────────────────────────────────────────────────
    // Resolves dynamic intake question schema from the configured provider.

    Route::get('intake/schema', IntakeSchemaController::class)
        ->middleware('throttle:api')
        ->name('intake.schema');

    // ── Cart ─────────────────────────────────────────────────────────────
    // Token-identified via X-Cart-Token header. No auth required.
    // A new cart is created automatically when the token is absent or expired.

    Route::prefix('cart')->name('cart.')->middleware('throttle:api')->group(function (): void {
        Route::get('/', [CartController::class, 'show'])->name('show');
        Route::get('suggestions', [CartController::class, 'suggestions'])->name('suggestions');
        Route::post('items', [CartController::class, 'addItem'])->name('items.add');
        Route::patch('items/{cartItem}', [CartController::class, 'updateItem'])->name('items.update');
        Route::delete('items/{cartItem}', [CartController::class, 'removeItem'])->name('items.remove');
        Route::delete('/', [CartController::class, 'clear'])->name('clear');
    });

    // ── Auth ────────────────────────────────────────────────────────────
    // Token issue / revoke endpoints. Rate-limited to prevent brute-force.

    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function (): void {
        Route::post('login', LoginController::class)->name('login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', LogoutController::class)->name('logout');
            Route::get('me', MeController::class)->name('me');
        });
    });

    // ── Orders ───────────────────────────────────────────────────────────
    // Retrieve an order by UUID. The UUID is opaque — it is only returned
    // to the client at checkout completion and treated as a bearer credential.

    Route::prefix('orders')->name('orders.')->middleware('throttle:api')->group(function (): void {
        Route::get('{uuid}', [OrderController::class, 'show'])->name('show');
    });

    // ── Webhooks ─────────────────────────────────────────────────────────
    // Inbound events from external systems. CSRF exempt (no session).
    // Signature verification is handled inside each controller.

    Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:60,1')->group(function (): void {
        Route::post('prescribe-rx', PrescribeRxWebhookController::class)->name('prescribe-rx');
    });

    // ── Profiles ─────────────────────────────────────────────────────────
    // Published team / doctor / expert profiles.

    Route::prefix('profiles')->name('profiles.')->middleware('throttle:api')->group(function (): void {
        Route::get('/', [ProfileController::class, 'index'])->name('index');
        Route::get('{slug}', [ProfileController::class, 'show'])->name('show');
    });

    // ── FAQ ──────────────────────────────────────────────────────────────
    // Published FAQ items and categories.

    Route::prefix('faq')->name('faq.')->middleware('throttle:api')->group(function (): void {
        Route::get('/', [FaqController::class, 'index'])->name('index');
        Route::get('categories', [FaqController::class, 'categories'])->name('categories');
        Route::get('categories/{slug}', [FaqController::class, 'category'])->name('categories.show');
    });

    // ── Checkout ─────────────────────────────────────────────────────────
    // Submits a cart to the configured checkout provider (PRX or local).
    // Uses a Sanctum token issued at cart creation — does not require a
    // logged-in user; the token scopes the cart to an anonymous session.

    Route::prefix('checkout')->name('checkout.')->middleware('throttle:20,1')->group(function (): void {
        Route::get('gateway-config', [CheckoutController::class, 'gatewayConfig'])->name('gateway-config');
        Route::post('/', [CheckoutController::class, 'store'])->name('store');
    });

    // ── Patient Auth ─────────────────────────────────────────────────────
    // Separate patient guard — tokens carry patient:* abilities.
    // Rate-limited aggressively to prevent credential stuffing.

    Route::prefix('patient/auth')->name('patient.auth.')->middleware('throttle:auth')->group(function (): void {
        Route::post('register', [PatientAuthController::class, 'register'])->name('register');
        Route::post('login', [PatientAuthController::class, 'login'])->name('login');

        Route::middleware(['auth:sanctum', 'patient'])->group(function (): void {
            Route::post('logout', [PatientAuthController::class, 'logout'])->name('logout');
            Route::get('me', [PatientAuthController::class, 'me'])->name('me');
        });
    });

    // ── Patient Portal ────────────────────────────────────────────────────
    // Proxies PRX /me/patient/* endpoints using a patient-scoped PRX token.
    // All routes require a valid patient Sanctum token.

    Route::prefix('patient')->name('patient.portal.')->middleware(['auth:sanctum', 'patient', 'throttle:api'])->group(function (): void {
        Route::get('dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('encounters', [PortalController::class, 'encounters'])->name('encounters.index');
        Route::get('encounters/{encounterId}/video-token', [PortalController::class, 'videoToken'])->name('encounters.video-token');
        Route::get('vitals', [PortalController::class, 'vitals'])->name('vitals.index');
        Route::post('vitals', [PortalController::class, 'storeVital'])->name('vitals.store');
        Route::get('orders', [PortalController::class, 'orders'])->name('orders.index');
        Route::get('prescriptions', [PortalController::class, 'prescriptions'])->name('prescriptions.index');
        Route::get('conversations', [PortalController::class, 'conversations'])->name('conversations.index');
        Route::get('conversations/{conversationId}/messages', [PortalController::class, 'conversationMessages'])->name('conversations.messages');
        Route::post('conversations/{conversationId}/messages', [PortalController::class, 'sendMessage'])->name('conversations.messages.store');
        Route::get('scheduling/slots', [PortalController::class, 'availabilitySlots'])->name('scheduling.slots');
        Route::post('scheduling/appointments', [PortalController::class, 'bookAppointment'])->name('scheduling.appointments.store');
    });
});
