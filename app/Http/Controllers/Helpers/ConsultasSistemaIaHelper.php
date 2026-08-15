<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de LECTURA sobre los datos del negocio, compartidas entre el
 * endpoint AdminSync (SistemaQueryController, canal "sistema:" de WhatsApp)
 * y las tools del asistente de IA (AsistenteIaService).
 *
 * Extraídas del controller (misión chat-ia-y-modulo-ia) para no duplicar las
 * queries: acá vive la consulta, y cada consumidor le pone su cáscara. El
 * shape de salida de cada método es EXACTAMENTE el que el endpoint devolvía
 * (mismas claves, mismos tipos), con una sola excepción declarada: clientes()
 * suma la clave `id`, que la tool de movimientos del chat necesita para
 * encadenar consultas; el controller la quita antes de responder para
 * conservar su contrato byte a byte con admin-api.
 *
 * Todos los métodos filtran por el user_id del DUEÑO y respetan MAX_RESULTS:
 * el que llama es responsable de resolver el owner (nunca se lee Auth acá,
 * porque el chat consulta desde un job sin sesión).
 */
class ConsultasSistemaIaHelper
{
    /**
     * Cantidad máxima de registros que se devuelven por consulta. El mismo
     * tope que siempre tuvo SistemaQueryController: acota el JSON que viaja
     * al prompt de Claude, no el negocio.
     *
     * @var int
     */
    const MAX_RESULTS = 20;

    /**
     * Artículos activos del dueño con precio y stock, filtrados por nombre,
     * código de barras o código de proveedor.
     *
     * @param  int     $owner_id  Id del dueño (articles.user_id).
     * @param  string  $busqueda  Texto ya depurado a buscar; vacío trae los primeros sin filtrar.
     * @return array<int, array<string, mixed>>
     */
    public static function stock_de_articulos(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $articles_query = Article::query()
            ->where('user_id', $owner_id)
            ->where('status', 'active');

        // Si hay una palabra clave útil, se filtra por nombre / código de barras / código de proveedor.
        if ($busqueda !== '') {
            $articles_query->where(function ($sub) use ($busqueda) {
                $sub->where('name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $busqueda . '%');
            });
        }

        $articles = $articles_query
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'bar_code', 'provider_code', 'price', 'final_price', 'stock']);

        // Aplanamos a un array simple y legible para Claude.
        $result = [];
        foreach ($articles as $article) {
            $result[] = [
                'id'            => (int) $article->id,
                'nombre'        => (string) $article->name,
                'codigo'        => (string) ($article->bar_code ?? $article->provider_code ?? ''),
                'precio'        => $article->final_price !== null ? (float) $article->final_price : (float) $article->price,
                'stock'         => $article->stock !== null ? (float) $article->stock : 0,
            ];
        }

        return $result;
    }

    /**
     * Clientes del dueño con teléfono, email y saldo de cuenta corriente
     * (saldo positivo = el cliente debe), filtrados por nombre.
     *
     * A diferencia del resto, incluye `id`: la tool de movimientos del chat
     * lo necesita para encadenar. El endpoint AdminSync lo quita al responder.
     *
     * @param  int     $owner_id  Id del dueño (clients.user_id).
     * @param  string  $busqueda  Nombre o parte del nombre; vacío trae los primeros.
     * @return array<int, array<string, mixed>>
     */
    public static function clientes(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $clients_query = Client::query()->where('user_id', $owner_id);

        if ($busqueda !== '') {
            $clients_query->where('name', 'LIKE', '%' . $busqueda . '%');
        }

        $clients = $clients_query
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'phone', 'email', 'saldo']);

        $result = [];
        foreach ($clients as $client) {
            $result[] = [
                'id'        => (int) $client->id,
                'cliente'   => (string) $client->name,
                'telefono'  => (string) ($client->phone ?? ''),
                'email'     => (string) ($client->email ?? ''),
                'saldo'     => $client->saldo !== null ? (float) $client->saldo : 0,
            ];
        }

        return $result;
    }

    /**
     * Últimos movimientos de cuenta corriente de UN cliente del dueño:
     * fecha, detalle, debe, haber y saldo, del más nuevo al más viejo.
     *
     * Query nueva de la misión chat-ia-y-modulo-ia (el endpoint AdminSync no
     * la tenía): current_acounts no maneja soft deletes, así que no hay
     * filtro de borrados que aplicar.
     *
     * @param  int  $owner_id   Id del dueño (current_acounts.user_id).
     * @param  int  $client_id  Id del cliente (current_acounts.client_id).
     * @return array<int, array<string, mixed>>
     */
    public static function movimientos_de_cuenta_corriente(int $owner_id, int $client_id): array
    {
        $movimientos = CurrentAcount::query()
            ->where('user_id', $owner_id)
            ->where('client_id', $client_id)
            ->orderBy('created_at', 'DESC')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'detalle', 'description', 'debe', 'haber', 'saldo', 'created_at']);

        $result = [];
        foreach ($movimientos as $movimiento) {
            // detalle es el campo principal; description queda de respaldo para filas viejas.
            $detalle = trim((string) ($movimiento->detalle ?? ''));
            if ($detalle === '') {
                $detalle = trim((string) ($movimiento->description ?? ''));
            }

            $result[] = [
                'fecha'   => $movimiento->created_at ? $movimiento->created_at->format('d/m/Y H:i') : '',
                'detalle' => $detalle,
                'debe'    => $movimiento->debe !== null ? (float) $movimiento->debe : 0,
                'haber'   => $movimiento->haber !== null ? (float) $movimiento->haber : 0,
                'saldo'   => $movimiento->saldo !== null ? (float) $movimiento->saldo : 0,
            ];
        }

        return $result;
    }

    /**
     * Artículos más vendidos del dueño en los últimos N días, con las
     * unidades vendidas. Excluye ventas borradas (soft delete de sales).
     *
     * Los ítems de venta viven en la tabla pivot article_purchases
     * (article_id, sale_id, amount); se agrupan por artículo sumando cantidades.
     *
     * @param  int  $owner_id  Id del dueño (articles.user_id / sales.user_id).
     * @param  int  $dias      Ventana de días hacia atrás.
     * @return array<int, array<string, mixed>>
     */
    public static function mas_vendidos(int $owner_id, int $dias = 30): array
    {
        $top = DB::table('article_purchases')
            ->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $owner_id)
            ->where('article_purchases.created_at', '>=', now()->subDays($dias))
            ->whereNull('sales.deleted_at')
            ->select('articles.name as nombre', DB::raw('SUM(article_purchases.amount) as total_vendido'))
            ->groupBy('articles.id', 'articles.name')
            ->orderByDesc('total_vendido')
            ->limit(self::MAX_RESULTS)
            ->get();

        $result = [];
        foreach ($top as $row) {
            $result[] = [
                'nombre'        => (string) $row->nombre,
                'total_vendido' => (float) $row->total_vendido,
            ];
        }

        return $result;
    }

    /**
     * Clientes del dueño con saldo pendiente de cobro (deuda en cuenta
     * corriente), ordenados por deuda descendente.
     *
     * @param  int  $owner_id  Id del dueño (clients.user_id).
     * @return array<int, array<string, mixed>>
     */
    public static function clientes_con_saldo_pendiente(int $owner_id): array
    {
        // clients.saldo positivo = el cliente debe dinero (pendiente de cobro).
        $clients = Client::query()
            ->where('user_id', $owner_id)
            ->where('saldo', '>', 0)
            ->orderByDesc('saldo')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'phone', 'saldo']);

        $result = [];
        foreach ($clients as $client) {
            $result[] = [
                'cliente'           => (string) $client->name,
                'telefono'          => (string) ($client->phone ?? ''),
                'saldo_pendiente'   => (float) $client->saldo,
            ];
        }

        return $result;
    }

    /**
     * Extrae una palabra clave de producto/entidad desde el texto de la consulta.
     *
     * Quita los disparadores conocidos (stock, cuánto tengo, de, etc.) y devuelve el resto.
     *
     * @param  string  $query
     * @return string  Palabra clave depurada (puede quedar vacía).
     */
    public static function extract_product_keyword(string $query): string
    {
        // Palabras de relleno que no aportan al filtro de nombre.
        $stop_words = [
            'cuanto', 'cuanta', 'cuantos', 'cuantas', 'stock', 'inventario', 'tengo', 'hay', 'queda', 'quedan',
            'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'mi', 'mis', 'me', 'que', 'cual', 'cuales',
            'existencia', 'existencias', 'producto', 'productos', 'articulo', 'articulos', 'sistema',
            'cliente', 'clientes', 'factura', 'facturas', 'deuda', 'saldo', 'pendiente', 'pendientes',
        ];

        $normalized = self::normalize_text($query);
        // Separamos en palabras y descartamos las de relleno.
        $words = preg_split('/\s+/', $normalized) ?: [];

        $kept = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || in_array($word, $stop_words, true)) {
                continue;
            }
            $kept[] = $word;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * Normaliza texto a minúsculas sin acentos para comparaciones de palabras clave.
     *
     * @param  string  $text
     * @return string
     */
    public static function normalize_text(string $text): string
    {
        $text = mb_strtolower(trim($text));

        // Reemplazo simple de vocales acentuadas y ñ.
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr($text, $replacements);
    }
}
