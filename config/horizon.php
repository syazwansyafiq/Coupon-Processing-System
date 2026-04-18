<?php

use Laravel\Horizon\Listeners\TrimFailedJobs;
use Laravel\Horizon\Listeners\TrimMonitoredJobs;
use Laravel\Horizon\Listeners\TrimRecentJobs;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain and Path
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    | Alert when queue wait time exceeds these thresholds (seconds).
    */

    'waits' => [
        'redis:high' => 5,
        'redis:default' => 30,
        'redis:low' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,          // minutes
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080, // 7 days
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Three supervisor pools:
    |   high    — coupon validation (fast, few workers, tight timeout)
    |   default — cart updates and consumption/release
    |   low     — analytics, event tracking (bulk, tolerant of delay)
    */

    'defaults' => [
        'supervisor-high' => [
            'connection' => 'redis',
            'queue' => ['high'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 10,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 30,
            'nice' => 0,
        ],

        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 5,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 5,
            'timeout' => 30,
            'nice' => 0,
        ],

        'supervisor-low' => [
            'connection' => 'redis',
            'queue' => ['low'],
            'balance' => 'simple',
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 10,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-high' => [
                'minProcesses' => 3,
                'maxProcesses' => 15,
                'balanceCooldown' => 3,
            ],
            'supervisor-default' => [
                'minProcesses' => 2,
                'maxProcesses' => 8,
                'balanceCooldown' => 3,
            ],
            'supervisor-low' => [
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'balanceCooldown' => 10,
            ],
        ],

        'local' => [
            'supervisor-high' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-low' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],
    ],
];
