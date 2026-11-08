<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Http\Request;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'login',
        'api/vender/*',
        'api/*',
    ];

    /** /login conserva la excepción histórica. Elevarlo por cookie exige el token de la sesión. */
    public function token_de_sesion_valido(Request $request): bool
    {
        return $request->hasSession() && $this->tokensMatch($request);
    }
}
