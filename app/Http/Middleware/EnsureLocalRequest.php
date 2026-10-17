<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('central.local_only', true)) {
            return $next($request);
        }

        if (! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            abort(403, 'A Central de Projetos Git aceita conexões apenas deste computador.');
        }

        return $next($request);
    }
}
