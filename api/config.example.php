<?php
// SBC Subscription Box — configuration template.
// Copy this file to config.local.php and fill in your values.
// config.local.php is NOT committed / deployed to the web root examples.

return [
    // Database (MySQL)
    'db_host'      => 'localhost',
    'db_name'      => 'sbc_subscriptionbox',
    'db_user'      => 'root',
    'db_pass'      => '',

    // App
    'app_url'      => 'https://your-domain.com',
    'demo_mode'    => true,                 // allow subscriber/merchant/ops role pick at register
    'timezone'     => 'Asia/Kuala_Lumpur',

    // Auth
    'auth_token_ttl' => 2592000,            // 30 days

    // Shipping cutoff: subscribers can skip/swap/add-ons until this day of month
    'cutoff_day'   => 15,

    // Churn AI — free Colab/Ollama OpenAI-compatible endpoint (trycloudflare tunnel).
    'ollama_enabled' => true,
    'ollama_url'      => 'https://CHANGE-ME.trycloudflare.com/v1',
    'ollama_model'    => 'qwen2.5-coder:7b',
    'ollama_timeout'  => 15,                // seconds; falls back to rules when unreachable

    // Notifications (email/SMS). 'log' writes to storage/logs so demo works with zero keys.
    'mail_mode' => 'log',                   // 'log' | 'smtp'
    'sms_mode'  => 'log',                   // 'log' | 'twilio'
    'twilio_sid'    => '',
    'twilio_token'  => '',
    'twilio_from'   => '',

    // Carriers. Default 'local_csv' needs no keys and exports print-ready labels.
    'default_carrier'     => 'local_csv',   // 'local_csv' | 'dhl' | 'fedex'
    'dhl_api_key'         => '',
    'dhl_secret'          => '',
    'dhl_api_url'         => 'https://api-eu.dhl.com',
    'fedex_api_key'       => '',
    'fedex_secret'        => '',
    'fedex_api_url'       => 'https://apis.fedex.com',

    // Address validation. 'demo' is a mock that catches common typos; 'loqate'/'smartystreets' plug real keys.
    'address_provider' => 'demo',
    'loqate_api_key'   => '',
    'smartystreets_key'=> '',

    // Security
    'install_key' => 'CHANGE-ME-IN-PRODUCTION',

    // Storage (writable)
    'storage_dir' => __DIR__ . '/../storage',
];