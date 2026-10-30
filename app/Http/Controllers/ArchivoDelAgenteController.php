<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * La descarga de un archivo que armó el agente personal (misión agente-mejoras-uso-real, 29/10/2026,
 * §4 E3.1 del plan): el Excel de exportar_excel, por el link que el agente le pasa al dueño.
 *
 * `GET asistente/archivo/{uuid}?t=<token>` — PÚBLICA a propósito (routes/web.php, declarada con su
 * motivo en Seguridad/RutasPublicas/1_Ninguna_ruta_publica_sin_saberlo_Test): el dueño la abre desde
 * WhatsApp, en el teléfono, sin sesión. La protege el token del link; toda la regla (token con
 * hash_equals, vencimiento, carpeta del dueño) vive en ArchivosDelAgenteIaHelper::para_descargar().
 *
 * Inexistente, token equivocado, vencido o archivo borrado: 404, todos con la misma respuesta (no se
 * distingue "no existe" de "no es tuyo").
 */
class ArchivoDelAgenteController extends Controller
{
    /** Lo que ve quien abre un link que no vale (texto plano: se abre en el navegador del teléfono). */
    const TEXTO_NO_DISPONIBLE = 'Este archivo no existe o ya venció (los archivos del agente duran 7 días). Pedíselo de nuevo al agente.';

    /**
     * Baja el archivo, o 404.
     *
     * @param  Request  $request
     * @param  string  $uuid
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function descargar(Request $request, $uuid)
    {
        $archivo = ArchivosDelAgenteIaHelper::para_descargar($uuid, $request->query('t'));

        if (is_null($archivo)) {

            return response(self::TEXTO_NO_DISPONIBLE, 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        // El nombre y el tipo salen de la fila; la ruta en el disco, también (nunca del pedido).
        return response()->download(
            Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->path($archivo->path),
            $archivo->nombre,
            [
                'Content-Type'  => ArchivosDelAgenteIaHelper::MIME_XLSX,
                // Datos del negocio: que ningún intermediario lo guarde ni un buscador lo indexe.
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag'  => 'noindex, nofollow',
            ]
        );
    }
}
