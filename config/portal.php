<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Patient portal
    |--------------------------------------------------------------------------
    |
    | The portal's PUBLIC origin, for links this backend puts in an email —
    | today the single-use claim link. An environment value rather than an
    | admin setting because a deployed app's origin is topology, fixed by
    | whoever deployed it, and a settings field could be edited to point at a
    | host nothing is listening on with no way to validate it from here.
    |
    | This is the same compromise `cms.frontend.url` makes for the storefront:
    | the frontend owns its routes, so the one path this backend composes is
    | configurable rather than assumed. A different portal overrides the
    | `*_path` keys; `{token}` is replaced with the plain token. The create-
    | account and reset links are sent to anonymous visitors, so those pages
    | must not require a session.
    |
    | Unset means claim links cannot be built, and requesting one answers 503
    | rather than emailing somebody a link to the admin.
    |
    */

    'url' => env('PATIENT_PORTAL_URL'),

    'claim_path' => env('PATIENT_PORTAL_CLAIM_PATH', '/claim/{token}'),

    'create_account_path' => env('PATIENT_PORTAL_CREATE_ACCOUNT_PATH', '/create-account/{token}'),

    'reset_path' => env('PATIENT_PORTAL_RESET_PATH', '/reset/{token}'),

    // Where the "your password was changed" notice sends someone who did not
    // change it. No token in it.
    'forgot_path' => env('PATIENT_PORTAL_FORGOT_PATH', '/forgot'),

];
