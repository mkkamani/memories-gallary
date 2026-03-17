<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     * Aborts with 403 if the authenticated user does not have the 'admin' role.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->user()?->role !== 'admin') {
            abort(403, 'Only administrators can access this area.');
        }

        return $next($request);
    }
}
