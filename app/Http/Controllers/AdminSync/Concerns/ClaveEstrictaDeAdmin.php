<?php

namespace App\Http\Controllers\AdminSync\Concerns;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use Illuminate\Http\Request;

/**
 * La clave de `admin-sync` exigida SIEMPRE y la resolución del dueño, para los controladores
 * `AdminSync\Catalogo*` de la categorización con IA (misión categorizacion-tres-modelos, 5/10/2026).
 *
 * Es el patrón estricto de `AdminSync\AsistenteController::rechazo_de_acceso()` / `clave_valida()`,
 * SIN la comprobación de la extensión (la categorización no se gatea por extensión), puesto en un
 * trait porque los controladores nuevos son más de uno. NO se refactorizaron los cinco controladores
 * que ya tienen su copia: este trait es solo para los nuevos.
 *
 * 🔴 Por qué la clave se valida ACÁ ADENTRO y no se confía en el middleware `admin.api.key`:
 *   - Ese middleware, con `services.admin_api.require_api_key` apagado (hoy lo está en toda la flota),
 *     deja pasar el pedido SIN mirar el header.
 *   - Estas rutas le dan a quien llame el inventario completo de un comercio (nombres, códigos,
 *     proveedores) y le dejan escribir propuestas que el dueño después aplica sobre todo su
 *     catálogo. Un canal así no puede quedar abierto porque una variable de entorno esté apagada.
 *   - NO se copia el patrón condicional de PlanIa / ModelosIa / ConsumoIa / Imagenes, que pasa
 *     ABIERTO cuando el cliente no tiene clave cargada: acá "no hay clave" nunca es un pase libre.
 *
 * Respuestas de rechazo (las del precedente, para que el admin y la skill las lean igual):
 *   - Clave ausente, equivocada o no configurada de este lado → 401 `{"error":"unauthorized"}`. Los
 *     tres casos devuelven EXACTAMENTE lo mismo: así no se le dice a quien prueba claves si este
 *     cliente tiene una cargada.
 *   - Dueño no resoluble → 409 `{"message": ...}`. Es 409 y no 404 a propósito: para la skill y el
 *     admin un 404 en estas rutas significa "este cliente todavía no tiene el endpoint" (versión
 *     vieja), y un cliente ya actualizado pero sin `USER_ID` en su `.env` no puede quedar diciendo
 *     "actualizá" cuando lo que falta es una variable.
 *
 * El orden es fijo: primero la clave (sin ella no se le contesta nada a nadie, ni siquiera si existe
 * el dueño) y recién después el dueño.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
trait ClaveEstrictaDeAdmin
{
    /**
     * La clave y el dueño, en un solo lugar: devuelve la respuesta de rechazo, o null si el pedido
     * puede pasar. Cada acción lo llama antes de hacer nada:
     *
     *     $rechazo = $this->rechazo_de_acceso($request);
     *     if (!is_null($rechazo)) { return $rechazo; }
     *     $dueno = AsistenteCanalHelper::dueno();
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function rechazo_de_acceso(Request $request)
    {
        // 1) La CLAVE primero.
        if (!$this->clave_valida($request)) {

            return response()->json(['error' => 'unauthorized'], 401);
        }

        // 2) El dueño. Con varios comercios en la base y sin USER_ID no hay forma de saber a cuál
        // le están hablando: se corta, no se adivina (el costo de equivocarse es escribirle las
        // propuestas de un negocio a otro).
        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        return null;
    }

    /**
     * Compara el header `X-Admin-Api-Key` contra `services.admin_api.api_key`, SIN mirar
     * `require_api_key`. Ver el 🔴 del docblock del trait.
     *
     * Una clave no configurada de este lado es un rechazo, no un pase libre. La comparación es
     * `hash_equals` (tiempo constante) para no filtrar la clave por el tiempo de respuesta.
     *
     * @param  \Illuminate\Http\Request $request
     * @return bool
     */
    protected function clave_valida(Request $request)
    {
        // Lo que mandó la skill y lo que este cliente espera (ambos como texto: un header ausente
        // es null y una variable sin cargar también).
        $recibida = (string) $request->header('X-Admin-Api-Key');
        $esperada = (string) config('services.admin_api.api_key');

        // "No hay clave" NUNCA es pase libre: ni la nuestra vacía, ni la recibida vacía.
        if ($esperada === '' || $recibida === '') {

            return false;
        }

        return hash_equals($esperada, $recibida);
    }
}
