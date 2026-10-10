<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class RequireCsrfToken extends PreventRequestForgery
{
    /** Require the session CSRF token even when Fetch Metadata reports same-origin. */
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
