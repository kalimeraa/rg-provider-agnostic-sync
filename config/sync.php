<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supplier providers
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'dummyjson' => [
            'base_url' => env('SYNC_DUMMYJSON_BASE_URL', 'https://dummyjson.com'),
        ],
        'fakestore' => [
            'base_url' => env('SYNC_FAKESTORE_BASE_URL', 'https://fakestoreapi.com'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    */
    'interval_minutes' => (int) env('SYNC_INTERVAL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting & circuit breaker (ThrottledHttpClient)
    |--------------------------------------------------------------------------
    */
    'rate_limit_per_second' => (int) env('SYNC_RATE_LIMIT_PER_SECOND', 5),
    'max_consecutive_failures' => (int) env('SYNC_MAX_CONSECUTIVE_FAILURES', 5),

    /*
    |--------------------------------------------------------------------------
    | Job uniqueness (SyncProviderJob ShouldBeUnique ceiling)
    |--------------------------------------------------------------------------
    */
    'job_unique_for' => (int) env('SYNC_JOB_UNIQUE_FOR', 900),

    /*
    |--------------------------------------------------------------------------
    | Alerting thresholds (AlertService)
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'slack_webhook_url' => env('ALERT_SLACK_WEBHOOK_URL'),
        'consecutive_sync_failures' => (int) env('ALERT_CONSECUTIVE_SYNC_FAILURES', 3),
        'failed_job_threshold' => (int) env('ALERT_FAILED_JOB_THRESHOLD', 10),
        'consecutive_api_failures' => (int) env('ALERT_CONSECUTIVE_API_FAILURES', 5),
        'queue_backlog_threshold' => (int) env('ALERT_QUEUE_BACKLOG_THRESHOLD', 100),
        'throttle_minutes' => (int) env('ALERT_THROTTLE_MINUTES', 5),
    ],

];
