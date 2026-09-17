<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->user() && auth()->user()->is_admin == 1) {
            return $next($request);
        }

        // 403 មានន័យថា Forbidden - អ្នកមិនមានសិទ្ធិចូលប្រើ។
        return apiResponse(null, 403, 'Unauthorized..!');

    }
}
