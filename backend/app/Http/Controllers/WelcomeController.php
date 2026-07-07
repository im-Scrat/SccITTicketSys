<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves the framework welcome page for `GET /`.
 *
 * Extracted verbatim from the former web route closure so the HTTP route table
 * contains no closures and can be serialized by `route:cache` (a production
 * boot-time optimization). In production the SPA is served by Nginx and this
 * route is never reached through the browser; behaviour is otherwise unchanged.
 */
class WelcomeController extends Controller
{
    public function __invoke(): View
    {
        return view('welcome');
    }
}
