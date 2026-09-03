<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Which reverse proxies this app believes when it reads X-Forwarded-*.
    | Laravel's TrustProxies middleware reads this key natively, so no
    | middleware or provider wiring is needed — set the env var and restart.
    |
    | This is NOT cosmetic. $request->ip() is written to leads.ip_address,
    | carts.ip_address and lead_consents.ip_address — the last being the record
    | of WHO agreed to be contacted, i.e. the evidence behind a TCPA or
    | CAN-SPAM question. The `api` rate limiter keys on the same value. Put any
    | proxy in front with nothing trusted here and all of it silently describes
    | the proxy instead of the visitor: nothing errors, the rows just become
    | worthless and every visitor shares one rate-limit bucket.
    |
    | Accepts a comma-separated list of addresses and/or CIDR ranges, the
    | literal REMOTE_ADDR, or "*". Empty trusts nothing, which is correct for a
    | directly-exposed app.
    |
    | CHOOSING A VALUE
    |
    |   Directly exposed, no proxy
    |       TRUSTED_PROXIES=
    |
    |   App behind Apache/nginx on the same host
    |       TRUSTED_PROXIES=127.0.0.1
    |       Add the host's own public IP if sibling apps reach it by public
    |       DNS and hairpin back in.
    |
    |   Behind a load balancer with no fixed address (AWS ALB, GCP, Azure)
    |       TRUSTED_PROXIES=REMOTE_ADDR
    |       This trusts exactly the peer that connected, whoever it turns out
    |       to be — which is what makes it correct when the balancer's ENIs
    |       take private addresses that churn and cannot be enumerated. It is
    |       safe because the target's security group admits ONLY the load
    |       balancer's security group, so nothing else can ever be the peer.
    |       Treat that ingress rule and this setting as ONE decision: widen the
    |       SG and this stops being safe the same day. A shared SG widens it too.
    |
    |   Tighter still, when the balancer's subnets are known
    |       TRUSTED_PROXIES=10.0.1.0/24,10.0.2.0/24
    |
    | DO NOT USE "*" HERE. It reads as "trust any proxy", but it means
    | setTrustedProxies(['0.0.0.0/0', '::/0']) — EVERY address is a trusted
    | proxy, so Symfony strips the entire X-Forwarded-For chain and falls back
    | to its LEFTMOST entry, which is the part the client writes. Measured
    | against the real middleware:
    |
    |     peer=10.0.1.20 (ALB)  XFF="9.9.9.9, 203.0.113.55"
    |       *             -> 9.9.9.9        forged by the visitor
    |       REMOTE_ADDR   -> 203.0.113.55   correct
    |       10.0.1.0/24   -> 203.0.113.55   correct
    |
    | With "*" an anonymous visitor can put any address they like into a
    | consent record, and rotate it to defeat the rate limiter — the exact
    | failures this setting exists to prevent.
    |
    | A LAYER-4 BALANCER IS THE "TRUST NOTHING" CASE. An AWS NLB adds no
    | X-Forwarded-For, and with client-IP preservation the visitor is already
    | the peer. Trusting anything there means believing a header the visitor
    | wrote.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
