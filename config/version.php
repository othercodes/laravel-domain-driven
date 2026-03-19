<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Version Source
    |--------------------------------------------------------------------------
    |
    | The source from which the application version is resolved.
    | Supported: "git", "env", "file"
    |
    */

    'source' => env('VERSION_SOURCE', 'git'),

    /*
    |--------------------------------------------------------------------------
    | Version Value (for env source)
    |--------------------------------------------------------------------------
    |
    | The version string used when the source is set to "env".
    |
    */

    'value' => env('APP_VERSION', '0.0.0-dev'),

    /*
    |--------------------------------------------------------------------------
    | Application Version Name
    |--------------------------------------------------------------------------
    |
    | A human-readable name for the current version or release.
    |
    */

    'name' => '',

    /*
    |--------------------------------------------------------------------------
    | Application Version Code
    |--------------------------------------------------------------------------
    |
    | A short code identifier for the current version or release.
    |
    */

    'code' => '',

];
