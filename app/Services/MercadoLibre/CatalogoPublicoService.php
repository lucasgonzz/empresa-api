<?php

namespace App\Services\MercadoLibre;

use App\Models\PlatformConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Llamadas al catálogo público de Mercado Libre (predictor, categorías, atributos) que el
 * formulario del artículo necesita aunque el comercio todavía NO haya conectado su cuenta (misión
 * mercado-libre-integracion, 27/10/2026).
 *
 * NO extiende `MercadoLibreService` a propósito: ése exige un conector conectado (y si no lo hay
 * notifica "Mercado Libre no conectado" y tira). Acá el token es lo mejor que haya:
 * 1. el del vendedor, si el comercio tiene ML conectado y el access_token no venció;
 * 2. si no, el token de APLICACIÓN (`client_credentials`, cacheado);
 * 3. si no hay ninguno, sin token (hoy ML todavía responde estas rutas así, pero está empezando a
 *    exigir token en todo: la investigación del 27/10 vio 403 `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`).
 * Ante un 403 con el token del vendedor se reintenta una vez con el de la app.
 *
 * Categorías y atributos se cachean un día: cambian poco y el formulario los pide seguido.
 */
class CatalogoPublicoService
{
    /** Host de la API. */
    const BASE_URL = 'https://api.mercadolibre.com/';

    /** Sitio de Mercado Libre Argentina. */
    const SITE_ID = 'MLA';

    /** Segundos de caché de una categoría y de sus atributos (1 día). */
    const SEGUNDOS_CACHE = 86400;

    /** @var int|null Comercio (owner) para buscar su conector; null = solo token de app */
    protected $user_id;

    /**
     * @param int|null $user_id Comercio (owner) cuyo token de vendedor se usa si lo tiene.
     */
    public function __construct($user_id = null)
    {
        $this->user_id = is_null($user_id) ? null : (int) $user_id;
    }

    /**
     * Predictor de categorías (`domain_discovery`) para un título.
     *
     * Cada sugerencia sale con la ruta legible de la categoría ("Herramientas > ... > De Mano"),
     * que se arma desde `categories/{id}` (cacheado).
     *
     * @param string $q Título o texto libre.
     * @param int $limit Cantidad de sugerencias (1 a 8; ML recomienda pocas).
     * @return array<int, array<string, string|null>> `[{category_id, category_name, domain_name, path}]`
     */
    public function predecir($q, $limit = 5)
    {
        $q = trim((string) $q);

        if ($q === '') {
            return [];
        }

        $limit = max(1, min(8, (int) $limit));

        $respuesta = $this->get('sites/'.self::SITE_ID.'/domain_discovery/search', [
            'q'     => $q,
            'limit' => $limit,
        ]);

        if (!is_array($respuesta)) {
            return [];
        }

        $sugerencias = [];
        $vistas = [];

        foreach ($respuesta as $fila) {
            if (!is_array($fila) || empty($fila['category_id'])) {
                continue;
            }

            $category_id = (string) $fila['category_id'];

            // ML puede sugerir la misma categoría desde dos dominios: se muestra una vez.
            if (isset($vistas[$category_id])) {
                continue;
            }
            $vistas[$category_id] = true;

            $sugerencias[] = [
                'category_id'   => $category_id,
                'category_name' => isset($fila['category_name']) ? (string) $fila['category_name'] : null,
                'domain_name'   => isset($fila['domain_name']) ? (string) $fila['domain_name'] : null,
                'path'          => $this->ruta_legible($category_id),
            ];

            if (count($sugerencias) >= $limit) {
                break;
            }
        }

        return $sugerencias;
    }

    /**
     * `GET categories/{id}` cacheado un día. null si ML no tiene esa categoría.
     *
     * @param string $meli_category_id Ej.: `MLA5232`.
     * @return array<string, mixed>|null
     */
    public function categoria($meli_category_id)
    {
        $meli_category_id = (string) $meli_category_id;

        return $this->cacheado('meli_categoria_'.$meli_category_id, function () use ($meli_category_id) {
            return $this->get('categories/'.rawurlencode($meli_category_id));
        });
    }

    /**
     * `GET categories/{id}/attributes` cacheado un día.
     *
     * @param string $meli_category_id
     * @return array<int, array<string, mixed>>
     */
    public function atributos($meli_category_id)
    {
        $meli_category_id = (string) $meli_category_id;

        $atributos = $this->cacheado('meli_categoria_atributos_'.$meli_category_id, function () use ($meli_category_id) {
            return $this->get('categories/'.rawurlencode($meli_category_id).'/attributes');
        });

        return is_array($atributos) ? $atributos : [];
    }

    /**
     * Hijas de una categoría para navegar el árbol, con la ruta hasta ella y si es hoja.
     * Sin id, las categorías raíz del sitio (`sites/MLA/categories`).
     *
     * @param string|null $meli_category_id
     * @return array{categories: array<int, array<string, string>>, path: array<int, array<string, string>>, hoja: bool}|null
     *         null si ML no tiene esa categoría.
     */
    public function hijas($meli_category_id = null)
    {
        if (empty($meli_category_id)) {
            $raiz = $this->cacheado('meli_categorias_raiz_'.self::SITE_ID, function () {
                return $this->get('sites/'.self::SITE_ID.'/categories');
            });

            return [
                'categories' => self::id_y_nombre(is_array($raiz) ? $raiz : []),
                'path'       => [],
                'hoja'       => false,
            ];
        }

        $categoria = $this->categoria($meli_category_id);

        if (!is_array($categoria)) {
            return null;
        }

        $hijas = isset($categoria['children_categories']) && is_array($categoria['children_categories'])
            ? $categoria['children_categories']
            : [];

        return [
            'categories' => self::id_y_nombre($hijas),
            'path'       => self::id_y_nombre(isset($categoria['path_from_root']) && is_array($categoria['path_from_root']) ? $categoria['path_from_root'] : []),
            'hoja'       => self::es_hoja($categoria),
        ];
    }

    /**
     * True si en la categoría se puede publicar: ML solo publica en la última del árbol.
     *
     * @param array<string, mixed> $categoria Respuesta de `categories/{id}`.
     * @return bool
     */
    public static function es_hoja(array $categoria)
    {
        $hijas = isset($categoria['children_categories']) && is_array($categoria['children_categories'])
            ? $categoria['children_categories']
            : [];

        if (count($hijas) > 0) {
            return false;
        }

        if (isset($categoria['settings']) && is_array($categoria['settings']) && array_key_exists('listing_allowed', $categoria['settings'])) {
            return (bool) $categoria['settings']['listing_allowed'];
        }

        return true;
    }

    /**
     * Ruta legible de una categoría ("Herramientas > Herramientas Manuales > Martillos"), o el
     * nombre solo si ML no devolvió `path_from_root`. null si no se pudo consultar.
     *
     * @param string $meli_category_id
     * @return string|null
     */
    public function ruta_legible($meli_category_id)
    {
        try {
            $categoria = $this->categoria($meli_category_id);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($categoria)) {
            return null;
        }

        $nombres = [];
        if (isset($categoria['path_from_root']) && is_array($categoria['path_from_root'])) {
            foreach ($categoria['path_from_root'] as $nodo) {
                if (is_array($nodo) && isset($nodo['name'])) {
                    $nombres[] = (string) $nodo['name'];
                }
            }
        }

        if (count($nombres) === 0 && isset($categoria['name'])) {
            $nombres[] = (string) $categoria['name'];
        }

        return count($nombres) > 0 ? implode(' > ', $nombres) : null;
    }

    /**
     * `[{id, name}]` a partir de una lista de nodos de ML.
     *
     * @param array<int, mixed> $nodos
     * @return array<int, array<string, string>>
     */
    protected static function id_y_nombre(array $nodos)
    {
        $lista = [];

        foreach ($nodos as $nodo) {
            if (is_array($nodo) && isset($nodo['id'])) {
                $lista[] = [
                    'id'   => (string) $nodo['id'],
                    'name' => isset($nodo['name']) ? (string) $nodo['name'] : '',
                ];
            }
        }

        return $lista;
    }

    /**
     * Guarda en caché el resultado de `$consultar` (solo si es un array: un null por 404 o un
     * fallo no se cachea, así una categoría nueva o un corte de ML no quedan pegados un día).
     *
     * @param string $clave
     * @param \Closure $consultar
     * @return mixed
     */
    protected function cacheado($clave, \Closure $consultar)
    {
        try {
            $cacheado = Cache::get($clave);
            if (is_array($cacheado)) {
                return $cacheado;
            }
        } catch (\Throwable $e) {
            // Sin caché se consulta igual.
        }

        $valor = $consultar();

        if (is_array($valor)) {
            try {
                Cache::put($clave, $valor, self::SEGUNDOS_CACHE);
            } catch (\Throwable $e) {
                // Nada.
            }
        }

        return $valor;
    }

    /**
     * GET contra la API pública con el mejor token disponible.
     *
     * @param string $path Path relativo (sin la base).
     * @param array<string, mixed> $query
     * @return array<mixed>|null JSON decodificado; null en 404.
     * @throws \RuntimeException Si ML respondió otro error.
     */
    public function get($path, array $query = [])
    {
        $url = self::BASE_URL.ltrim($path, '/');

        $token_vendedor = $this->token_del_vendedor();
        $token = $token_vendedor ?: MercadoLibreAppCredentials::token_de_app();

        $response = $this->pedir($url, $query, $token);

        // Un 403 con el token del vendedor (permisos funcionales de la app, IP, etc.): se reintenta
        // una vez con el de la app.
        if ($response->status() === 403 && $token_vendedor) {
            $token_app = MercadoLibreAppCredentials::token_de_app();
            if ($token_app && $token_app !== $token_vendedor) {
                $response = $this->pedir($url, $query, $token_app);
            }
        }

        if ($response->status() === 404) {
            return null;
        }

        if (!$response->successful()) {
            Log::warning('CatalogoPublicoService: Mercado Libre respondió '.$response->status().' para '.$path);

            throw new \RuntimeException('Mercado Libre no respondió la consulta de categorías (HTTP '.$response->status().'). Probá de nuevo en unos minutos.');
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Una llamada HTTP con o sin token.
     *
     * @param string $url
     * @param array<string, mixed> $query
     * @param string|null $token
     * @return \Illuminate\Http\Client\Response
     */
    protected function pedir($url, array $query, $token)
    {
        $http = Http::withOptions(MercadoLibreService::http_client_options());

        if (!empty($token)) {
            $http = $http->withToken($token);
        }

        return $http->get($url, $query);
    }

    /**
     * access_token del vendedor si el comercio tiene ML conectado y el token no venció (o vence
     * en más de un minuto). No refresca: para el catálogo público alcanza con el token de app.
     *
     * @return string|null
     */
    protected function token_del_vendedor()
    {
        if (is_null($this->user_id)) {
            return null;
        }

        $connector = PlatformConnector::find_connected_mercado_libre_for_user($this->user_id);

        if (!$connector) {
            return null;
        }

        if ($connector->expires_at && $connector->expires_at->lte(now()->addMinute())) {
            return null;
        }

        try {
            $token = $connector->access_token;
        } catch (\Throwable $e) {
            return null;
        }

        return empty($token) ? null : (string) $token;
    }
}
