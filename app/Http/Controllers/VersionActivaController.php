<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\VersionActivaHelper;

/**
 * VersionActivaController
 *
 * Endpoint PÚBLICO (sin sesión, sin token, sin cookies) que le dice al SPA, en la pantalla de login,
 * cuál es la dirección del sistema activo de esta instancia (misión
 * redireccion-version-antes-del-login, 24/9/2026). Con eso el SPA del frente en desuso puede mandar
 * al negocio a la versión actual ANTES de que inicie sesión, en vez de esperar al login.
 *
 * El controlador es delgado a propósito: toda la lógica (cuándo se contesta y cuándo no) vive en
 * VersionActivaHelper. Ahí también está el porqué de que la respuesta dependa de que la base tenga
 * un único dueño.
 *
 * Contrato con empresa-spa: GET {API}/api/version-activa responde 200 con
 * `{"default_version": "<url del SPA activo>" | null}` y NADA más. Cualquier otra respuesta
 * (404 de una API vieja, 500, timeout) el SPA la trata como "sin información" y sigue al login.
 * Ver la ruta en routes/api.php.
 */
class VersionActivaController extends Controller
{
    /**
     * Devuelve la dirección del sistema activo, o null si no se puede decir con seguridad.
     *
     * 🔴 El JSON tiene EXACTAMENTE una clave. No se agrega nada del negocio (ni nombre, ni id, ni
     * email): lo que sale es una dirección que el usuario ya ve en la barra de su navegador, y la
     * ruta la puede llamar cualquier visitante anónimo.
     *
     * `Cache-Control: no-store` porque la respuesta cambia con cada upgrade (el admin reescribe
     * `default_version`) y el SPA necesita el valor de ahora, no el de una caché intermedia.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show()
    {
        return response()->json([
            'default_version' => VersionActivaHelper::default_version_del_unico_dueno(),
        ], 200)->header('Cache-Control', 'no-store, max-age=0');
    }
}
