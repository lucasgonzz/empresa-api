<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint de consulta de datos para el canal "sistema:" de WhatsApp de soporte.
 *
 * Autenticado con X-Admin-Api-Key (middleware admin.api.key), igual que el resto de
 * los endpoints AdminSync. Lo consume admin-api (SistemaQueryService) cuando un cliente
 * escribe "sistema: ..." por WhatsApp. Devuelve datos reales del sistema del owner para
 * que Claude (en admin-api) los redacte como respuesta en lenguaje natural.
 */
class SistemaQueryController extends Controller
{
    /**
     * Cantidad máxima de registros que se devuelven por consulta.
     *
     * @var int
     */
    protected const MAX_RESULTS = 20;

    /**
     * Recibe una consulta en lenguaje natural, detecta su tipo por palabras clave
     * y devuelve los datos correspondientes del sistema del owner.
     *
     * Body esperado:
     *  - query   (string, obligatorio): texto de la consulta (ej. "cuánto stock tengo de Coca Cola").
     *  - context (string, opcional):    contexto adicional, no usado en la query pero logueado.
     *  - user_id (int, opcional):       fuerza el owner; por defecto se usa el primer owner del sistema.
     *
     * Respuesta:
     *  { "data": [...], "query_type": "stock|ventas|facturas|clientes" }
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function query_data(Request $request): JsonResponse
    {
        // Texto de la consulta del cliente; sin él no se puede resolver nada.
        $query = trim((string) $request->input('query', ''));
        if ($query === '') {
            return response()->json([
                'message' => 'El campo "query" es obligatorio.',
            ], 422);
        }

        // Owner cuyos datos se consultan. Por defecto, el primer owner del sistema (owner_id null).
        $owner = $this->resolve_owner($request);
        if ($owner === null) {
            return response()->json([
                'data'       => [],
                'query_type' => 'desconocido',
                'note'       => 'No se encontró un owner en el sistema para resolver la consulta.',
            ], 200);
        }

        // Tipo de consulta inferido por palabras clave en el texto.
        $query_type = $this->detect_query_type($query);

        try {
            // Cada rama devuelve un array plano listo para serializar a JSON.
            switch ($query_type) {
                case 'ventas':
                    $data = $this->query_top_sold_articles($owner->id);
                    break;

                case 'facturas':
                    $data = $this->query_pending_collections($owner->id);
                    break;

                case 'clientes':
                    $data = $this->query_clients($owner->id, $query);
                    break;

                case 'stock':
                default:
                    // Por defecto y para stock: artículos con su stock filtrados por nombre similar.
                    $query_type = 'stock';
                    $data = $this->query_articles_stock($owner->id, $query);
                    break;
            }
        } catch (\Throwable $exception) {
            Log::error('AdminSync\\SistemaQueryController::query_data - error al consultar', [
                'query'      => $query,
                'query_type' => $query_type,
                'owner_id'   => $owner->id,
                'error'      => $exception->getMessage(),
            ]);

            return response()->json([
                'data'       => [],
                'query_type' => $query_type,
                'note'       => 'Error al ejecutar la consulta: ' . $exception->getMessage(),
            ], 200);
        }

        return response()->json([
            'data'       => $data,
            'query_type' => $query_type,
        ], 200);
    }

    /**
     * Resuelve el owner cuyos datos se consultarán.
     *
     * Prioridad: user_id explícito del request → primer owner del sistema (owner_id null).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Models\User|null
     */
    protected function resolve_owner(Request $request): ?User
    {
        // Si admin-api manda un user_id explícito, se usa ese owner.
        $explicit_user_id = $request->input('user_id');
        if ($explicit_user_id !== null && is_numeric($explicit_user_id) && (int) $explicit_user_id > 0) {
            $owner = User::find((int) $explicit_user_id);
            if ($owner !== null) {
                return $owner;
            }
        }

        // Por defecto: primer owner del sistema (cuenta principal de la instalación).
        return User::whereNull('owner_id')->orderBy('id')->first();
    }

    /**
     * Detecta el tipo de consulta a partir de palabras clave en el texto.
     *
     * @param  string  $query  Texto de la consulta en lenguaje natural.
     * @return string  stock|ventas|facturas|clientes
     */
    protected function detect_query_type(string $query): string
    {
        // Normalizamos a minúsculas sin acentos para comparar palabras clave.
        $normalized = $this->normalize_text($query);

        // Ventas / artículos más vendidos.
        if ($this->contains_any($normalized, ['vendido', 'vendidos', 'venta', 'ventas', 'mas vendido'])) {
            return 'ventas';
        }

        // Facturas pendientes / deuda / cobranza.
        if ($this->contains_any($normalized, ['factura', 'facturas', 'pendiente', 'cobro', 'cobrar', 'deuda', 'debe', 'saldo', 'cuenta corriente'])) {
            return 'facturas';
        }

        // Listado de clientes (evita falsos positivos con "cliente" dentro de "facturas de cliente":
        // ya filtrado arriba si menciona factura/deuda).
        if ($this->contains_any($normalized, ['cliente', 'clientes', 'comprador', 'compradores'])) {
            return 'clientes';
        }

        // Stock / inventario.
        if ($this->contains_any($normalized, ['stock', 'inventario', 'cuanto tengo', 'cuanto hay', 'existencia'])) {
            return 'stock';
        }

        // Por defecto: stock.
        return 'stock';
    }

    /**
     * Consulta artículos del owner con su stock, filtrando por nombre similar al de la consulta.
     *
     * @param  int  $owner_id  Id del owner (articles.user_id).
     * @return array<int, array<string, mixed>>
     */
    protected function query_articles_stock(int $owner_id, string $query): array
    {
        // Palabra clave de producto extraída del texto (ej. "Coca Cola" desde "cuánto stock tengo de Coca Cola").
        $keyword = $this->extract_product_keyword($query);

        $articles_query = Article::query()
            ->where('user_id', $owner_id)
            ->where('status', 'active');

        // Si hay una palabra clave útil, se filtra por nombre / código de barras / código de proveedor.
        if ($keyword !== '') {
            $articles_query->where(function ($sub) use ($keyword) {
                $sub->where('name', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $keyword . '%');
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
     * Consulta los artículos más vendidos del owner en los últimos 30 días.
     *
     * Los ítems de venta viven en la tabla pivot article_purchases (article_id, sale_id, amount).
     * Se agrupan por artículo sumando las cantidades vendidas.
     *
     * @param  int  $owner_id  Id del owner (articles.user_id / sales.user_id).
     * @return array<int, array<string, mixed>>
     */
    protected function query_top_sold_articles(int $owner_id): array
    {
        $top = DB::table('article_purchases')
            ->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $owner_id)
            ->where('article_purchases.created_at', '>=', now()->subDays(30))
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
     * Consulta clientes del owner con saldo pendiente de cobro (deuda en cuenta corriente).
     *
     * @param  int  $owner_id  Id del owner (clients.user_id).
     * @return array<int, array<string, mixed>>
     */
    protected function query_pending_collections(int $owner_id): array
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
     * Consulta clientes del owner, filtrando por nombre si la consulta lo sugiere.
     *
     * @param  int     $owner_id  Id del owner (clients.user_id).
     * @param  string  $query     Texto de la consulta para extraer un posible nombre.
     * @return array<int, array<string, mixed>>
     */
    protected function query_clients(int $owner_id, string $query): array
    {
        $keyword = $this->extract_product_keyword($query);

        $clients_query = Client::query()->where('user_id', $owner_id);

        if ($keyword !== '') {
            $clients_query->where('name', 'LIKE', '%' . $keyword . '%');
        }

        $clients = $clients_query
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'phone', 'email', 'saldo']);

        $result = [];
        foreach ($clients as $client) {
            $result[] = [
                'cliente'   => (string) $client->name,
                'telefono'  => (string) ($client->phone ?? ''),
                'email'     => (string) ($client->email ?? ''),
                'saldo'     => $client->saldo !== null ? (float) $client->saldo : 0,
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
    protected function extract_product_keyword(string $query): string
    {
        // Palabras de relleno que no aportan al filtro de nombre.
        $stop_words = [
            'cuanto', 'cuanta', 'cuantos', 'cuantas', 'stock', 'inventario', 'tengo', 'hay', 'queda', 'quedan',
            'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'mi', 'mis', 'me', 'que', 'cual', 'cuales',
            'existencia', 'existencias', 'producto', 'productos', 'articulo', 'articulos', 'sistema',
            'cliente', 'clientes', 'factura', 'facturas', 'deuda', 'saldo', 'pendiente', 'pendientes',
        ];

        $normalized = $this->normalize_text($query);
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
    protected function normalize_text(string $text): string
    {
        $text = mb_strtolower(trim($text));

        // Reemplazo simple de vocales acentuadas y ñ.
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr($text, $replacements);
    }

    /**
     * Indica si el texto normalizado contiene alguna de las palabras/frases dadas.
     *
     * @param  string                $haystack  Texto ya normalizado.
     * @param  array<int, string>    $needles   Palabras/frases a buscar.
     * @return bool
     */
    protected function contains_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
