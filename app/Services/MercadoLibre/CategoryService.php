<?php

namespace App\Services\MercadoLibre;

use App\Models\Article;
use App\Models\MeliAttribute;
use App\Models\MeliAttributeTag;
use App\Models\MeliAttributeValue;
use App\Models\MeliCategory;
use Illuminate\Support\Facades\Log;

/**
 * Categorías y atributos de Mercado Libre del lado del ERP: la categoría local (`meli_categories`)
 * con sus atributos, valores y tags, y lo que el formulario del artículo necesita para elegirla.
 *
 * Misión mercado-libre-integracion (27/10/2026): ya NO extiende `MercadoLibreService`. Antes
 * exigía un conector conectado (y si no, notificaba y tiraba), así que el predictor y el alta de
 * una categoría no andaban hasta conectar la cuenta, justo cuando el comercio está armando sus
 * artículos. Ahora todo pasa por `CatalogoPublicoService`, que usa el token del vendedor si lo hay
 * y si no el de la app.
 */
class CategoryService
{
    /** Tags de ML que sacan un atributo del formulario (los completa ML o no se pueden cargar). */
    const TAGS_QUE_OCULTAN = ['read_only', 'fixed', 'inferred', 'hidden'];

    /** Atributos que se muestran aunque tengan uno de esos tags (identificadores del producto). */
    const ATRIBUTOS_SIEMPRE_VISIBLES = ['GTIN', 'EMPTY_GTIN_REASON'];

    /** @var int|null Comercio (owner) */
    protected $user_id;

    /** @var CatalogoPublicoService */
    protected $catalogo;

    /**
     * @param int|null $user_id Comercio (owner); se usa su token de vendedor si tiene ML conectado.
     */
    public function __construct($user_id = null)
    {
        $this->user_id = is_numeric($user_id) ? (int) $user_id : null;
        $this->catalogo = new CatalogoPublicoService($this->user_id);
    }

    /**
     * Servicio de catálogo público que usa esta instancia.
     *
     * @return CatalogoPublicoService
     */
    public function catalogo()
    {
        return $this->catalogo;
    }

    /**
     * Determina la categoría ML del artículo.
     * Prioriza:
     * - meli_category_id del artículo
     * - predictor automático si no está seteado
     *
     * @param Article $article
     * @return Article
     */
    public function resolve_meli_category_for_article(Article $article)
    {
        $mercado_libre_category_id = null;

        if ($article->meli_category) {
            $mercado_libre_category_id = $article->meli_category->meli_category_id;
        }

        if (!$mercado_libre_category_id) {
            $suggestions = $this->fetch_meli_categories((string) $article->name);

            if (count($suggestions) > 0) {
                $mercado_libre_category_id = $suggestions[0]['category_id'];
            }
        }

        if ($mercado_libre_category_id) {
            $article = $this->assign_to_article($article, $mercado_libre_category_id);
        }

        return $article;
    }

    /**
     * Predictor de categorías para un texto. Forma de cada fila:
     * `{category_id, category_name, domain_name, path}` (la ruta vieja
     * `meli-category-predictor/{article_name}` y la nueva `?q=` devuelven lo mismo).
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array<string, string|null>>
     */
    public function fetch_meli_categories(string $query, $limit = 5)
    {
        return $this->catalogo->predecir($query, $limit);
    }

    /**
     * Asigna una categoría de Mercado Libre al artículo (creando la categoría local si falta).
     *
     * @param Article $article
     * @param string $mercado_libre_category_id Ej.: `MLA5232`.
     * @return Article
     */
    public function assign_to_article(Article $article, string $mercado_libre_category_id)
    {
        $meli_category = $this->resolver_categoria_local($mercado_libre_category_id);

        if (!$meli_category) {
            throw new \RuntimeException('Mercado Libre no tiene la categoría '.$mercado_libre_category_id.'.');
        }

        $article->meli_category_id = $meli_category->id;
        $article->save();

        return $article;
    }

    /**
     * Categoría local para un id de ML: la existente, o la crea con sus atributos. Si existe pero
     * quedó sin atributos (una carga anterior que falló a la mitad), se los carga.
     *
     * @param string $meli_category_id Ej.: `MLA5232`.
     * @return MeliCategory|null null si ML no tiene esa categoría.
     */
    public function resolver_categoria_local($meli_category_id)
    {
        $meli_category = MeliCategory::where('meli_category_id', $meli_category_id)->first();

        if (!$meli_category) {
            return $this->create_meli_category_y_asignar_attributes($meli_category_id);
        }

        if (!MeliAttribute::where('meli_category_id', $meli_category->id)->exists()) {
            $this->asignar_attributes($meli_category);
        }

        return $meli_category;
    }

    /**
     * Crea la categoría local desde `categories/{id}` y le carga los atributos.
     *
     * @param string $meli_category_id
     * @return MeliCategory|null null si ML no tiene esa categoría.
     */
    public function create_meli_category_y_asignar_attributes($meli_category_id)
    {
        $response = $this->catalogo->categoria($meli_category_id);

        if (!is_array($response) || empty($response['id'])) {
            return null;
        }

        $meli_category = MeliCategory::create([
            'meli_category_id'   => $response['id'],
            'meli_category_name' => isset($response['name']) ? $response['name'] : null,
        ]);

        $this->asignar_attributes($meli_category);

        return $meli_category;
    }

    /**
     * Carga los atributos de la categoría (con sus valores y tags) desde
     * `categories/{id}/attributes`.
     *
     * @param MeliCategory $meli_category
     * @return void
     */
    public function asignar_attributes($meli_category)
    {
        $attributes = $this->catalogo->atributos($meli_category->meli_category_id);

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $meli_attribute = MeliAttribute::create([
                'meli_id'              => $attribute['id'] ?? null,
                'name'                 => $attribute['name'] ?? null,
                'relevance'            => $attribute['relevance'] ?? null,
                'value_type'           => $attribute['value_type'] ?? null,
                'value_max_length'     => $attribute['value_max_length'] ?? null,
                'default_unit'         => $attribute['default_unit'] ?? null,
                'tooltip'              => $attribute['tooltip'] ?? null,
                'hint'                 => $attribute['hint'] ?? null,
                'hierarchy'            => $attribute['hierarchy'] ?? null,
                'example'              => isset($attribute['example']) ? mb_substr((string) $attribute['example'], 0, 191) : null,
                'attribute_group_id'   => $attribute['attribute_group_id'] ?? null,
                'attribute_group_name' => $attribute['attribute_group_name'] ?? null,

                'meli_category_id'     => $meli_category->id,
            ]);

            if (isset($attribute['values']) && is_array($attribute['values'])) {
                foreach ($attribute['values'] as $value) {
                    if (!is_array($value) || !isset($value['id'])) {
                        continue;
                    }

                    MeliAttributeValue::create([
                        'meli_id'           => $value['id'],
                        'meli_name'         => isset($value['name']) ? mb_substr((string) $value['name'], 0, 191) : null,
                        'meli_attribute_id' => $meli_attribute->id,
                    ]);
                }
            }

            // ML manda los tags como objeto `{required: true, ...}`; se guarda un renglón por clave.
            if (isset($attribute['tags']) && is_array($attribute['tags'])) {
                foreach ($attribute['tags'] as $key => $value) {
                    // Por si algún día vienen como lista (`["required"]`): la clave es el tag.
                    if (is_int($key)) {
                        $key = (string) $value;
                        $value = true;
                    }

                    MeliAttributeTag::create([
                        'key'               => $key,
                        'value'             => is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : json_encode($value)),
                        'meli_attribute_id' => $meli_attribute->id,
                    ]);
                }
            }
        }

        Log::info('CategoryService: atributos cargados para la categoría '.$meli_category->meli_category_id.': '.count($attributes));
    }

    /**
     * Tags activos de un atributo local (los que tienen valor verdadero).
     *
     * @param MeliAttribute $meli_attribute Con `meli_attributes_tags` cargado (o se consulta).
     * @return array<int, string>
     */
    public static function tags_activos(MeliAttribute $meli_attribute)
    {
        $tags = [];

        foreach ($meli_attribute->meli_attributes_tags as $tag) {
            $valor = strtolower(trim((string) $tag->value));
            if ($valor !== '' && $valor !== '0' && $valor !== 'false') {
                $tags[] = (string) $tag->key;
            }
        }

        return $tags;
    }

    /**
     * True si el atributo es obligatorio para publicar (`required`, o `new_required`: el módulo
     * publica nuevo por defecto).
     *
     * También `catalog_required`. Prueba real del 28/10/2026 con un vendedor del modelo nuevo
     * (User Products): `items/validate` rechazó el alta por `item.attribute.missing_catalog_required`
     * («Tipo de producto», que en Destornilladores solo trae `catalog_required`), y el formulario no
     * lo pedía. Hoy las cuentas nuevas de MLA son User Products.
     *
     * @param array<int, string> $tags
     * @return bool
     */
    public static function es_requerido(array $tags)
    {
        return in_array('required', $tags, true)
            || in_array('new_required', $tags, true)
            || in_array('catalog_required', $tags, true);
    }

    /**
     * True si el atributo va en el formulario (no lo completa ML ni es de solo lectura).
     *
     * @param string|null $meli_id
     * @param array<int, string> $tags
     * @return bool
     */
    public static function va_en_el_formulario($meli_id, array $tags)
    {
        if (in_array((string) $meli_id, self::ATRIBUTOS_SIEMPRE_VISIBLES, true)) {
            return true;
        }

        foreach (self::TAGS_QUE_OCULTAN as $tag) {
            if (in_array($tag, $tags, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Atributos de la categoría para el formulario del artículo, requeridos primero.
     *
     * Cada uno: `{id, meli_id, name, value_type, required, conditional_required, auto, values,
     * hint, tooltip, value_max_length}`. `auto` dice con qué dato del artículo se completa solo
     * (`brand`, `bar_code`, `sku`) si el usuario no lo carga.
     *
     * @param MeliCategory $meli_category
     * @return array<int, array<string, mixed>>
     */
    public function atributos_para_formulario(MeliCategory $meli_category)
    {
        $atributos = MeliAttribute::where('meli_category_id', $meli_category->id)
            ->with('meli_attributes_values', 'meli_attributes_tags')
            ->orderBy('id')
            ->get();

        $filas = [];

        foreach ($atributos as $atributo) {
            $tags = self::tags_activos($atributo);

            if (!self::va_en_el_formulario($atributo->meli_id, $tags)) {
                continue;
            }

            $valores = [];
            foreach ($atributo->meli_attributes_values as $valor) {
                $valores[] = [
                    'id'   => (string) $valor->meli_id,
                    'name' => (string) $valor->meli_name,
                ];
            }

            $filas[] = [
                'id'                   => (int) $atributo->id,
                'meli_id'              => (string) $atributo->meli_id,
                'name'                 => (string) $atributo->name,
                'value_type'           => $atributo->value_type,
                'required'             => self::es_requerido($tags),
                'conditional_required' => in_array('conditional_required', $tags, true),
                'auto'                 => MercadoLibreArticuloService::auto_de_atributo($atributo->meli_id),
                'values'               => $valores,
                'hint'                 => $atributo->hint,
                'tooltip'              => $atributo->tooltip,
                'value_max_length'     => is_null($atributo->value_max_length) ? null : (int) $atributo->value_max_length,
                // Para ordenar; se saca antes de devolver.
                '_relevance'           => is_null($atributo->relevance) ? 99 : (int) $atributo->relevance,
            ];
        }

        usort($filas, function ($a, $b) {
            if ($a['required'] !== $b['required']) {
                return $a['required'] ? -1 : 1;
            }
            if ($a['conditional_required'] !== $b['conditional_required']) {
                return $a['conditional_required'] ? -1 : 1;
            }
            if ($a['_relevance'] !== $b['_relevance']) {
                return $a['_relevance'] < $b['_relevance'] ? -1 : 1;
            }

            return $a['id'] < $b['id'] ? -1 : ($a['id'] > $b['id'] ? 1 : 0);
        });

        foreach ($filas as $i => $fila) {
            unset($filas[$i]['_relevance']);
        }

        return array_values($filas);
    }

    /**
     * Resuelve una categoría de ML elegida en el formulario: valida que sea hoja, la crea local
     * si falta y devuelve lo que la SPA necesita para mostrar sus atributos.
     *
     * @param string $meli_category_id Ej.: `MLA5232`.
     * @return array<string, mixed> `{id, meli_category_id, meli_category_name, path, listing_allowed, max_title_length, atributos}`
     * @throws MercadoLibreCategoriaInvalidaException Si no existe o no es hoja.
     */
    public function resolver_para_formulario($meli_category_id)
    {
        $meli_category_id = strtoupper(trim((string) $meli_category_id));

        if (!preg_match('/^[A-Z]{3}\d+$/', $meli_category_id)) {
            throw new MercadoLibreCategoriaInvalidaException('La categoría de Mercado Libre no es válida.');
        }

        $categoria = $this->catalogo->categoria($meli_category_id);

        if (!is_array($categoria)) {
            throw new MercadoLibreCategoriaInvalidaException('Mercado Libre no tiene esa categoría.');
        }

        if (!CatalogoPublicoService::es_hoja($categoria)) {
            throw new MercadoLibreCategoriaInvalidaException('Elegí una categoría más específica: en Mercado Libre solo se publica en la última categoría del árbol.');
        }

        $meli_category = $this->resolver_categoria_local($meli_category_id);

        if (!$meli_category) {
            throw new MercadoLibreCategoriaInvalidaException('Mercado Libre no tiene esa categoría.');
        }

        $settings = isset($categoria['settings']) && is_array($categoria['settings']) ? $categoria['settings'] : [];

        return [
            'id'                 => (int) $meli_category->id,
            'meli_category_id'   => (string) $meli_category->meli_category_id,
            'meli_category_name' => (string) $meli_category->meli_category_name,
            'path'               => $this->catalogo->ruta_legible($meli_category_id),
            'listing_allowed'    => true,
            'max_title_length'   => isset($settings['max_title_length']) ? (int) $settings['max_title_length'] : null,
            'atributos'          => $this->atributos_para_formulario($meli_category),
        ];
    }
}
