<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [

    'api_path'    => 'api',
    'api_domain'  => null,
    'export_path' => 'api.json',

    'info' => [
        'version'     => env('API_VERSION', '1.0.0'),
        'description' => 'FEEPAY.ID API Documentation',
    ],

    'ui' => [
        'title'                    => 'FEEPAY.ID API Docs',
        'theme'                    => 'light',
        'hide_try_it'              => true,  // Sembunyikan "Try It" di production
        'hide_schemas'             => false,
        'logo'                     => '',
        'try_it_credentials_policy'=> 'include',
        'layout'                   => 'responsive',
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',
    'enum_cases_names_strategy'        => false,
    'flatten_deep_query_parameters'    => true,

    /*
     * PENTING: Di production, docs API HARUS dibatasi aksesnya.
     * Tambahkan middleware auth atau batasi IP.
     *
     * Opsi 1 — Nonaktifkan total di production (REKOMENDASI):
     *   Tambahkan di AppServiceProvider::boot():
     *   if (app()->isProduction()) { \Dedoc\Scramble\Scramble::disableDefaultRoutes(); }
     *
     * Opsi 2 — Pakai RestrictedDocsAccess (hanya authenticated user):
     */
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],
];