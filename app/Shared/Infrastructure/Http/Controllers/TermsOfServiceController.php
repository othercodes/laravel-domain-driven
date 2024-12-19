<?php

namespace App\Shared\Infrastructure\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TermsOfServiceController extends Controller
{
    /**
     * Show the terms of service for the application.
     */
    public function show(Request $request): Response
    {
        $termsFile = $this->localizedMarkdownPath('terms.md');

        return Inertia::render('TermsOfService', [
            'terms' => Str::markdown(file_get_contents($termsFile)),
        ]);
    }
}
