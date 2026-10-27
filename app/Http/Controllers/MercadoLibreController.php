<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Models\Article;
use App\Services\MercadoLibre\MercadoLibreArticuloService;
use App\Services\MercadoLibre\OrderDownloaderService;
use App\Services\MercadoLibre\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Mercado Libre desde el formulario del artículo: estado de la publicación y "Sincronizar ahora"
 * (misión mercado-libre-integracion, 27/10/2026). La lógica vive en
 * `MercadoLibreArticuloService` / `ProductService`.
 */
class MercadoLibreController extends Controller
{
    /** Respuesta de tenencia (ajeno, inexistente y no-id contestan lo mismo). */
    const MENSAJE_AJENO = 'El artículo no existe o no es de tu cuenta.';

    /**
     * LEGACY: no está ruteado (las notificaciones entran por `api/meli/notifications`).
     *
     * @param Request $request
     * @return void
     */
    function webhook(Request $request) {

        Log::info('MercadoLibreController webhook request:');
        Log::info((array)$request);

        if ($request->topic == 'orders_v2') {

            Log::info('Entro con topic orders_v2');

            $service = new OrderDownloaderService();

            $service->obtener_order($request->resource);
        }
    }

    /**
     * Estado de la publicación del artículo: `GET mercado-libre/articulo/{id}`.
     *
     * Respuesta: `{estado: {me_li_id, permalink, meli_status, ultima_sync: {status, error_message,
     * synced_at, attempted_at}|null, faltantes: [texto...]}}`. 404 si el artículo no es del dueño
     * de la sesión.
     *
     * @param mixed $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function articulo($id)
    {
        $ajeno = TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(Article::class, $id, $this->userId(), self::MENSAJE_AJENO);
        if ($ajeno) {
            return $ajeno;
        }

        $article = Article::find((int) $id);

        return response()->json(['estado' => MercadoLibreArticuloService::estado($article)], 200);
    }

    /**
     * "Sincronizar ahora": `POST mercado-libre/articulo/{id}/sincronizar`. Deja una sincronización
     * pendiente (la toma el comando del scheduler en el próximo minuto) y devuelve el mismo
     * `estado` que `articulo()`. Encola aunque falten datos: el veredicto, con el motivo legible,
     * lo da la sincronización (que valida con ML antes de publicar). Sobre una publicación cerrada
     * en ML, la publica de nuevo (alta nueva).
     *
     * Con el módulo apagado (sin la extensión `usa_mercado_libre`), 422 `{message}`: nadie la
     * procesaría.
     *
     * @param mixed $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar($id)
    {
        $ajeno = TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(Article::class, $id, $this->userId(), self::MENSAJE_AJENO);
        if ($ajeno) {
            return $ajeno;
        }

        $article = Article::find((int) $id);

        if (!ProductService::modulo_habilitado($article->user_id)) {
            return response()->json(['message' => 'Mercado Libre no está activado en este sistema. Avisale a ComercioCity.'], 422);
        }

        if ((string) $article->meli_status === 'closed') {
            // Cerrada en ML: no se puede reactivar. "Sincronizar ahora" la publica de nuevo (le saca
            // el código de la publicación vieja y la encola como un alta).
            ProductService::republicar_cerrada($article);
        } else {
            ProductService::encolar($article);
        }

        return response()->json(['estado' => MercadoLibreArticuloService::estado($article)], 200);
    }
}
