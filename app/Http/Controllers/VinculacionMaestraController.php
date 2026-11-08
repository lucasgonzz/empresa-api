<?php

namespace App\Http\Controllers;

use App\Services\VinculacionMaestra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VinculacionMaestraController extends Controller
{
    public function iniciar(Request $request): JsonResponse
    {
        $this->verificar_contexto($request);

        $codigo = VinculacionMaestra::iniciar($request);

        return $codigo
            ? $this->respuesta(['codigo' => $codigo], 200)
            : $this->respuesta(['message' => 'No pudimos generar el código. Volvé a intentar.'], 503);
    }

    public function canjear(Request $request): JsonResponse
    {
        $this->verificar_contexto($request);

        [$estado, $cookie] = VinculacionMaestra::canjear($request);
        if (!$cookie) {
            $mensaje = $estado === 409 ? 'El navegador todavía espera aprobación.' : 'El código venció o ya se usó.';

            return $this->respuesta(['message' => $mensaje], $estado);
        }

        return $this->respuesta(['vinculado' => true], 200)->withCookie($cookie);
    }

    private function verificar_contexto(Request $request): void
    {
        abort_unless(VinculacionMaestra::disponible() && VinculacionMaestra::origen_valido($request), 404);
    }

    private function respuesta(array $datos, int $estado): JsonResponse
    {
        return response()->json($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}
