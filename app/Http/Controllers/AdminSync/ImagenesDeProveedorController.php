<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\AdminSync\Concerns\ClaveEstrictaDeAdmin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Services\ImageAssignment\ImagenesDeProveedorWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST admin-sync/catalogo/imagenes/proveedor — las fotos que la tarea de soporte trajo de la web de un
 * proveedor (misión importaciones-de-martin, 11/11/2026). Multipart:
 *
 *   user_id, support_ticket_id, fuente (dominio del sitio), ultima_tanda? (1 = dejar el aviso por WhatsApp),
 *   tipo_fuente? (web | pdf, default web: con pdf, `fuente` es `adjunto-<id>.pdf` y la asignación va con origen
 *   `proveedor_pdf` en lugar de `proveedor_web`),
 *   items[i][article_id], items[i][tipo] (exacto|dudosa), items[i][imagen] (archivo, hasta 4 MiB),
 *   items[i][url_origen]?, items[i][codigo_web]?   — hasta 25 items por pedido.
 *
 *   → 200 {run_id, run_uuid, asignadas, a_revisar, salteadas: [{article_id, motivo}], exactas_a_revisar: [article_id],
 *          totales: {asignadas, a_revisar, no_asignadas, pendientes}, aviso_dejado}
 *   → 401 {error: unauthorized} · 409 {message, motivo: dueno_no_resoluble|dueno_distinto} · 422 {message, motivo|errors}
 *
 * Motivos de `salteadas`: articulo_no_encontrado (no existe o es de otro dueño), articulo_inactivo, ya_procesado
 * (ya está en esta asignación), ya_tiene_imagen, ya_tiene_a_revisar, imagen_no_recibida (el ítem llegó sin archivo:
 * PHP corta en `max_file_uploads`, 20 por defecto; hay que reintentarlo), imagen_invalida, imagen_muy_pesada,
 * imagen_chica, imagen_muy_grande, error_interno. Un 404 sin `motivo` es un empresa viejo: `version_vieja`.
 *
 * La clave X-Admin-Api-Key se exige SIEMPRE (trait ClaveEstrictaDeAdmin): esta ruta escribe en el catálogo.
 */
class ImagenesDeProveedorController extends Controller
{
    use ClaveEstrictaDeAdmin;

    public function store(Request $request, ImagenesDeProveedorWeb $servicio): JsonResponse
    {
        $rechazo = $this->rechazo_de_acceso($request);
        if (!is_null($rechazo)) {
            return $rechazo;
        }

        $datos = $request->validate([
            'user_id' => 'required|integer|min:1',
            'support_ticket_id' => 'required|integer|min:1',
            'fuente' => 'required|string|max:190',
            'ultima_tanda' => 'nullable|in:0,1,true,false',
            'tipo_fuente' => 'nullable|in:' . implode(',', ImagenesDeProveedorWeb::TIPOS_DE_FUENTE),
            'items' => 'required|array|min:1',
            'items.*.article_id' => 'required|integer|min:1',
            'items.*.tipo' => 'required|in:' . implode(',', ImagenesDeProveedorWeb::TIPOS),
            'items.*.url_origen' => 'nullable|string|max:500',
            'items.*.codigo_web' => 'nullable|string|max:100',
        ]);

        $dueno = AsistenteCanalHelper::dueno();
        if ((int) $dueno->id !== (int) $datos['user_id']) {
            return response()->json([
                'message' => 'El negocio indicado no es el dueño de esta instancia.',
                'motivo' => 'dueno_distinto',
            ], 409);
        }
        if (count($datos['items']) > ImagenesDeProveedorWeb::MAXIMO_DE_ITEMS) {
            return response()->json([
                'message' => 'Hasta ' . ImagenesDeProveedorWeb::MAXIMO_DE_ITEMS . ' imágenes por pedido.',
                'motivo' => 'demasiados_items',
            ], 422);
        }
        $tipo_fuente = isset($datos['tipo_fuente']) ? (string) $datos['tipo_fuente'] : 'web';
        $fuente = $tipo_fuente === 'pdf' ? $this->fuente_pdf((string) $datos['fuente']) : $this->fuente((string) $datos['fuente']);
        if ($fuente === '') {
            return response()->json(['message' => $tipo_fuente === 'pdf'
                ? 'Con tipo_fuente pdf, la fuente tiene que ser «adjunto-<id>.pdf».'
                : 'La fuente tiene que ser el dominio del sitio del proveedor.', 'motivo' => 'fuente_invalida'], 422);
        }

        $archivos = $request->file('items');
        $items = [];
        foreach ($datos['items'] as $clave => $item) {
            $item['imagen'] = is_array($archivos) && isset($archivos[$clave]['imagen']) ? $archivos[$clave]['imagen'] : null;
            $items[] = $item;
        }

        $ultima = isset($datos['ultima_tanda']) && in_array((string) $datos['ultima_tanda'], ['1', 'true'], true);

        return response()->json($servicio->procesar((int) $dueno->id, (int) $datos['support_ticket_id'], $fuente, $items, $ultima, $tipo_fuente), 200);
    }

    /**
     * La fuente de un catálogo en PDF: el adjunto del ticket, `adjunto-<id>.pdf` (en minúsculas).
     */
    private function fuente_pdf(string $valor): string
    {
        $valor = strtolower(trim($valor));

        return preg_match('/^adjunto-[1-9][0-9]{0,18}\.pdf$/', $valor) ? $valor : '';
    }

    /**
     * El dominio del sitio, sin esquema, sin ruta y en minúsculas («https://www.Prov.com.ar/x» → «www.prov.com.ar»).
     */
    private function fuente(string $valor): string
    {
        $valor = strtolower(trim($valor));
        if (preg_match('#^[a-z][a-z0-9+.-]*://#', $valor)) {
            $host = parse_url($valor, PHP_URL_HOST);
            $valor = is_string($host) ? $host : '';
        }
        $valor = preg_replace('#[/?\#].*$#', '', $valor);
        // Un `adjunto-<id>.pdf` no es un sitio: es la fuente de un catálogo en PDF y va con tipo_fuente=pdf.
        if (preg_match('/^adjunto-[0-9]+\.pdf$/', (string) $valor)) {
            return '';
        }

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', (string) $valor) ? (string) $valor : '';
    }
}
