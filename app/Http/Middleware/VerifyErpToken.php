<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentification machine-à-machine de l'API ERP par jeton partagé (en-tête X-Erp-Token).
 */
class VerifyErpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('synchub.erp.api_token');
        if ($expected === '') {
            return response()->json(['message' => 'API ERP non configurée (ERP_API_TOKEN).'], 503);
        }

        $given = (string) $request->header('X-Erp-Token', '');
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Jeton ERP invalide.'], 401);
        }

        return $next($request);
    }
}
