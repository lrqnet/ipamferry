<?php

return [
    'version' => env('IPAMFERRY_VERSION', 'dev'),
    'source_url' => env('IPAMFERRY_SOURCE_URL', 'https://github.com/lrqnet/ipamferry'),
    'updates_enabled' => (bool) env('IPAMFERRY_UPDATES_ENABLED', false),
    'release_api_url' => env('IPAMFERRY_RELEASE_API_URL', 'https://api.github.com/repos/lrqnet/ipamferry/releases/latest'),
    'dump_max_bytes' => (int) env('IPAMFERRY_DUMP_MAX_BYTES', 1_073_741_824),
    'dump_retention_hours' => (int) env('IPAMFERRY_DUMP_RETENTION_HOURS', 24),
    'dump_max_rows' => (int) env('IPAMFERRY_DUMP_MAX_ROWS', 5_000_000),
    'allow_insecure_http' => (bool) env('IPAMFERRY_ALLOW_INSECURE_HTTP', false),
    'extra_ca_bundle' => env('IPAMFERRY_EXTRA_CA_BUNDLE'),
    'netbox_page_size' => (int) env('IPAMFERRY_NETBOX_PAGE_SIZE', 200),
    'netbox_max_objects_per_type' => (int) env('IPAMFERRY_NETBOX_MAX_OBJECTS_PER_TYPE', 1_000_000),
    'phpipam_max_objects_per_type' => (int) env('IPAMFERRY_PHPIPAM_MAX_OBJECTS_PER_TYPE', 1_000_000),
    'api_max_response_bytes' => (int) env('IPAMFERRY_API_MAX_RESPONSE_BYTES', 268_435_456),
    'apply_batch_size' => (int) env('IPAMFERRY_APPLY_BATCH_SIZE', 25),
    'operation_lock_seconds' => (int) env('IPAMFERRY_OPERATION_LOCK_SECONDS', 14_400),
    'mapping_preview_minutes' => (int) env('IPAMFERRY_MAPPING_PREVIEW_MINUTES', 60),
    'sandbox_url' => env('IPAMFERRY_SANDBOX_URL', 'http://sandbox-netbox:8080'),
    'sandbox_probe_timeout_seconds' => (int) env('IPAMFERRY_SANDBOX_PROBE_TIMEOUT_SECONDS', 15),
    'sandbox_api_key_file' => env('IPAMFERRY_SANDBOX_API_KEY_FILE', '/run/ipamferry-secrets/superuser_api_key'),
    'sandbox_api_token_file' => env('IPAMFERRY_SANDBOX_API_TOKEN_FILE', '/run/ipamferry-secrets/superuser_api_token'),
    // NetBox 4.4 uses the legacy single-token Authorization scheme. Newer
    // supported releases use a v2 `nbt_<key>.<token>` bearer token.
    'sandbox_token_format' => env('IPAMFERRY_SANDBOX_TOKEN_FORMAT', 'v2'),
];
