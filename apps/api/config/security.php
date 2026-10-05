<?php

return [
    // Leave this empty unless the deployment's reverse-proxy addresses are known.
    // Trusting arbitrary forwarded headers would let a client spoof HTTPS, host, or IP.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // Entries are regular expressions accepted by Laravel's TrustHosts middleware.
    // APP_URL and its subdomains remain trusted independently.
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),

    // Enable only behind an HTTPS-terminating deployment. The middleware also checks
    // that the current request is secure before emitting HSTS.
    'hsts_enabled' => env('SECURITY_HSTS_ENABLED', false),
    'hsts_max_age' => min(63072000, max(0, (int) env('SECURITY_HSTS_MAX_AGE', 31536000))),
];
