<?php
// Razorpay configuration (scaffold)
// IMPORTANT: Do not commit real secrets. For local dev, set env vars or fill placeholders temporarily.

// Feature flag to enable/disable Razorpay end-to-end
if (!defined('RAZORPAY_ENABLED')) {
    define('RAZORPAY_ENABLED', false); // set to true after configuring keys
}

// API credentials
if (!defined('RAZORPAY_KEY_ID')) {
    define('RAZORPAY_KEY_ID', getenv('RAZORPAY_KEY_ID') ?: 'rzp_test_xxxxxxxxxxxxx');
}
if (!defined('RAZORPAY_KEY_SECRET')) {
    define('RAZORPAY_KEY_SECRET', getenv('RAZORPAY_KEY_SECRET') ?: 'xxxxxxxxxxxxxxxxxxxx');
}

// Webhook secret (set this in Razorpay dashboard and as env var locally). Used to verify webhook signatures.
if (!defined('RAZORPAY_WEBHOOK_SECRET')) {
    define('RAZORPAY_WEBHOOK_SECRET', getenv('RAZORPAY_WEBHOOK_SECRET') ?: 'whsec_xxxxxxxxxxxxx');
}

// Defaults
if (!defined('RAZORPAY_CURRENCY')) {
    define('RAZORPAY_CURRENCY', 'INR');
}

// Platform commission rate (10%)
if (!defined('PLATFORM_COMMISSION_RATE')) {
    define('PLATFORM_COMMISSION_RATE', 0.10);
}
