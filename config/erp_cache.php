<?php

/**
 * ERP performance-cache TTLs and stampede settings.
 *
 * MySQL remains the source of truth. Redis (when CACHE_STORE=redis) is a temporary
 * cache-aside layer only. Do not use one global TTL for every domain.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Domain TTLs (seconds)
    |--------------------------------------------------------------------------
    */
    'ttls' => [
        'dashboard' => (int) env('ERP_CACHE_TTL_DASHBOARD', 60),
        'project_cost_control' => (int) env('ERP_CACHE_TTL_COST_CONTROL', 60),
        'material_control' => (int) env('ERP_CACHE_TTL_MATERIAL_CONTROL', 60),
        'approval_count' => (int) env('ERP_CACHE_TTL_APPROVAL_COUNT', 20),
        'company_settings' => (int) env('ERP_CACHE_TTL_COMPANY_SETTINGS', 3600),
        'masters' => (int) env('ERP_CACHE_TTL_MASTERS', 3600),
        'reports' => (int) env('ERP_CACHE_TTL_REPORTS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale-while-revalidate grace (seconds beyond fresh TTL)
    |--------------------------------------------------------------------------
    |
    | Used with Cache::flexible() for expensive dashboards when enabled.
    |
    */
    'stale_grace' => (int) env('ERP_CACHE_STALE_GRACE', 30),

    /*
    |--------------------------------------------------------------------------
    | Stampede protection
    |--------------------------------------------------------------------------
    */
    'stampede' => [
        'enabled' => (bool) env('ERP_CACHE_STAMPEDE', true),
        'lock_seconds' => (int) env('ERP_CACHE_LOCK_SECONDS', 10),
        'block_seconds' => (int) env('ERP_CACHE_LOCK_BLOCK', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domains that use flexible (fresh, fresh+grace) caching
    |--------------------------------------------------------------------------
    */
    'flexible_domains' => [
        'dashboard',
        'project_cost_control',
        'reports',
    ],

];
