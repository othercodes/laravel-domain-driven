<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Class InertiaController
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
abstract class InertiaController extends Controller
{
    protected array $renderingCallbacks = [];

    public function render(Request $request, string $page, array $data = []): Response
    {
        if (isset($this->renderingCallbacks[$page])) {
            foreach ($this->renderingCallbacks[$page] as $callback) {
                $data = $callback($request, $data);
            }
        }

        return Inertia::render($page, $data);
    }

    public function whenRendering(string $page, callable $callback): static
    {
        $this->renderingCallbacks[$page][] = $callback;

        return $this;
    }
}
