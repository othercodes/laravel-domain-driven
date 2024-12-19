<?php

namespace App\Shared\Infrastructure\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Arr;

abstract class Controller extends BaseController
{
    public static function localizedMarkdownPath($name)
    {
        $localName = preg_replace('#(\.md)$#i', '.'.app()->getLocale().'$1', $name);

        return Arr::first([
            resource_path('markdown/'.$localName),
            resource_path('markdown/'.$name),
        ], function ($path) {
            return file_exists($path);
        });
    }
}
