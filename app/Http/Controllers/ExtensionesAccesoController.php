<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RutaDeMantenimiento;
use Illuminate\Http\JsonResponse;

/**
 * Emite el acceso de un solo uso al configurador de extensiones desde el admin (misión
 * extensiones-desde-el-admin, 15/10/2026).
 *
 * POR QUÉ EXISTE: el configurador (`/extensiones`) está cerrado en producción por la clave maestra
 * (`RutaDeMantenimiento`). El admin no la tiene, pero sí tiene la clave de admin de cada cliente
 * (`X-Admin-Api-Key`). Con ella pide acá un token; el navegador lo canjea en `/extensiones?acceso=<token>`
 * y `RutaDeMantenimiento` lo consume y abre la sesión SOLO para el configurador.
 *
 * La ruta (`POST api/admin-sync/extensiones/acceso`) va con `admin.api.key:siempre`: exige la clave aunque
 * la instancia no tenga ninguna cargada, porque esta ruta emite un acceso. Acá no se vuelve a validar nada.
 */
class ExtensionesAccesoController extends Controller
{
    /** Segundos que vive el token antes de canjearse. */
    const SEGUNDOS_DE_VIDA = 120;

    /** Ruta del configurador a la que el admin manda al navegador. */
    const RUTA_DEL_CONFIGURADOR = '/extensiones';

    /**
     * Emite un token de 64 hex aleatorios. En cache queda SOLO su sha256 (si alguien lee la cache no se
     * lleva un acceso canjeable) y vence a los 120 s. El token viaja una vez, en esta respuesta, y no se loguea.
     *
     * @return JsonResponse `{ok, acceso, expira_en, ruta}`
     */
    public function store()
    {
        // 32 bytes del generador criptográfico del sistema = 64 caracteres hexadecimales.
        $token = bin2hex(random_bytes(32));

        cache()->put(RutaDeMantenimiento::clave_de_cache_del_acceso($token), 1, self::SEGUNDOS_DE_VIDA);

        return response()->json([
            'ok'        => true,
            'acceso'    => $token,
            'expira_en' => self::SEGUNDOS_DE_VIDA,
            'ruta'      => self::RUTA_DEL_CONFIGURADOR,
        ]);
    }
}
