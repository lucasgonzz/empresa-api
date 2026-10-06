<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\category\SetPriceTypesHelper;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Las categorías y subcategorías REALES de un comercio, para la categorización con IA (misión
 * categorizacion-tres-modelos, 5/10/2026). Plan §4.2.1.
 *
 * Es lo único que crea filas en `categories` y `sub_categories` en este flujo (al elegir un sistema y
 * al aprobar un dudoso). Resuelve un nombre propuesto a una fila real: BUSCA por nombre normalizado y
 * la reutiliza, o la CREA "como el sistema" la crearía.
 *
 * Es una clase con estado (no estática) a propósito: el índice de las categorías del dueño se carga UNA
 * vez y se va actualizando con lo que se crea. Con un índice nuevo por llamada, un sistema de 18
 * categorías y 96 subcategorías haría 114 lecturas del catálogo, y dos nodos con el mismo nombre
 * normalizado dentro del mismo aplicar crearían la categoría dos veces.
 *
 * Reglas (R1 §3.6):
 *  - La clave de comparación es `CategoryProposalNombreHelper::clave_de` (minúsculas, sin acentos,
 *    espacios colapsados): al menos tan permisiva como la collation `utf8mb4_unicode_ci`, así nunca se
 *    duplica algo que un `where('name', ...)` del sistema ya vería igual. NO se usa `strtolower`
 *    pelado (el importador lo usa y en PHP 7.4 sobre Windows corrompe los acentos).
 *  - Con nombres repetidos en el catálogo del dueño gana la categoría MÁS VIEJA (menor id).
 *  - Una subcategoría se identifica por `[category_id][clave]`: el mismo nombre bajo otra categoría es
 *    otra subcategoría.
 *  - Nunca se crea ni se reutiliza la categoría `La de siempre` (tienda-api la esconde por nombre) ni
 *    un nombre que queda vacío al normalizar.
 *  - Se crea con Eloquent `create` (observers y auditoría), con el `num` correlativo del dueño
 *    (`Controller::num` con el dueño EXPLÍCITO: sirve fuera de una sesión) y después se le arman las
 *    listas de precio como hace el alta del sistema (`SetPriceTypesHelper`) PASANDO el dueño: con
 *    `$user = null` esos métodos toman el usuario de la sesión y no hacen nada, sin avisar, donde no
 *    hay sesión. Mismo patrón que `ProcessRow::get_category_id`.
 *  - Toda categoría o subcategoría que va a terminar escrita en un artículo se verifica contra el
 *    dueño (`categoria_es_del_dueno`, `subcategoria_pertenece_a`): un id suelto jamás se confía.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CategoriaRealHelper
{
    /**
     * El dueño del comercio (modelo: `SetPriceTypesHelper` lee sus extensiones).
     *
     * @var \App\Models\User
     */
    protected $dueno;

    /**
     * Instancia del controlador base, solo para `num()` (el correlativo por dueño con candado). Es el
     * mismo patrón que usan los importadores (`new Controller()`).
     *
     * @var \App\Http\Controllers\Controller
     */
    protected $controlador;

    /**
     * Las categorías vivas del dueño por clave de nombre: `[clave => id]`. Gana la más vieja. No
     * incluye las que no se pueden reutilizar (nombre vacío o `La de siempre`). `null` = todavía no se
     * cargó el índice.
     *
     * @var array|null
     */
    protected $categorias = null;

    /**
     * TODAS las categorías vivas del dueño: `[id => true]`. Es la definición de "esta categoría existe
     * y es de este dueño" (y, a la inversa, de "este artículo tiene categoría viva").
     *
     * @var array
     */
    protected $categorias_vivas = [];

    /**
     * Las subcategorías vivas del dueño por categoría y clave: `[category_id => [clave => id]]`. Gana
     * la más vieja.
     *
     * @var array
     */
    protected $subcategorias = [];

    /**
     * TODAS las subcategorías vivas del dueño: `[id => category_id]`.
     *
     * @var array
     */
    protected $subcategorias_vivas = [];

    /**
     * @param  \App\Models\User $dueno  El dueño del comercio (no un empleado).
     */
    public function __construct(User $dueno)
    {
        $this->dueno       = $dueno;
        $this->controlador = new Controller();
    }

    /**
     * El nombre tal como se guarda al CREAR: sin espacios en los bordes y acotado al largo máximo del
     * contrato (128; `categories.name` es varchar(128) y, con el modo estricto de MySQL, un nombre más
     * largo corta el INSERT). Es público y estático porque `puede_cambiar` lo usa para comprobar que
     * nadie renombró una categoría creada: tiene que calcular el nombre igual que al crearla.
     *
     * @param  string|null $nombre
     * @return string
     */
    public static function nombre_para_crear($nombre)
    {
        return mb_substr(trim((string) $nombre), 0, (int) config('catalogo_ia.largo_maximo_de_nombre'));
    }

    /**
     * El índice de las categorías y subcategorías vivas del dueño, cargado UNA vez (las siguientes
     * llamadas devuelven lo mismo, con lo creado hasta ahora). Mismo criterio que el cache del
     * importador (`ProcessRow`), pero con la más vieja ganando y con TODO acotado por `user_id`: la
     * base compartida del shared hosting tiene decenas de comercios.
     *
     * @return array  ['categorias' => [clave => id], 'vivas' => [id => true],
     *                 'subcategorias' => [category_id => [clave => id]], 'subcategorias_vivas' => [id => category_id]]
     */
    public function indice_de_categorias()
    {
        if (is_null($this->categorias)) {
            $this->cargar_indice();
        }

        return [
            'categorias'          => $this->categorias,
            'vivas'               => $this->categorias_vivas,
            'subcategorias'       => $this->subcategorias,
            'subcategorias_vivas' => $this->subcategorias_vivas,
        ];
    }

    /**
     * ¿Esta categoría existe (viva) y es de este dueño? Es la verificación que va ANTES de escribir un
     * `category_id` en un artículo, venga de donde venga (una categoría `existing_*` de una propuesta
     * "mantener", la que acaba de crearse o una ya atada a un nodo).
     *
     * @param  int|null $category_id
     * @return bool
     */
    public function categoria_es_del_dueno($category_id)
    {
        $this->asegurar_indice();

        return !is_null($category_id) && isset($this->categorias_vivas[(int) $category_id]);
    }

    /**
     * ¿Esta subcategoría existe (viva), es de este dueño y cuelga de esa categoría? Un artículo con una
     * subcategoría de OTRA categoría es un dato inconsistente que el sistema ya sufre (re-parentar una
     * subcategoría no arrastra artículos): por eso la subcategoría se verifica contra su categoría.
     *
     * @param  int|null $sub_category_id
     * @param  int|null $category_id
     * @return bool
     */
    public function subcategoria_pertenece_a($sub_category_id, $category_id)
    {
        $this->asegurar_indice();

        return !is_null($sub_category_id)
            && !is_null($category_id)
            && isset($this->subcategorias_vivas[(int) $sub_category_id])
            && $this->subcategorias_vivas[(int) $sub_category_id] === (int) $category_id
            && isset($this->categorias_vivas[(int) $category_id]);
    }

    /**
     * ¿El artículo con esta `category_id` tiene una categoría VIVA del dueño? "Sin categoría" en el
     * sistema es NULL, 0 o una categoría borrada (`CategoryController::destroy` deja 0; un artículo
     * puede seguir apuntando a una categoría que ya está en la papelera): la definición robusta es
     * "no apunta a una categoría viva".
     *
     * @param  int|null $category_id
     * @return bool
     */
    public function tiene_categoria_viva($category_id)
    {
        return $this->categoria_es_del_dueno($category_id);
    }

    /**
     * Busca una categoría del dueño por nombre normalizado y la reutiliza, o la crea.
     *
     * @param  string $nombre  El nombre propuesto (se compara por su clave normalizada).
     * @return array|null  ['id' => int, 'creada' => bool] o null si el nombre no se puede usar (queda
     *                     vacío al normalizar o es `La de siempre`).
     */
    public function buscar_o_crear_categoria($nombre)
    {
        if (!CategoryProposalNombreHelper::es_usable($nombre)) {
            return null;
        }

        $this->asegurar_indice();

        // La clave de comparación del nombre: lo que tiene que coincidir con una categoría ya existente.
        $clave = CategoryProposalNombreHelper::clave_de($nombre);

        if (isset($this->categorias[$clave])) {
            return ['id' => $this->categorias[$clave], 'creada' => false];
        }

        // Crear "como el sistema": Eloquent (observers y auditoría), `num` del dueño con candado y las
        // listas de precio por categoría armadas con el dueño explícito. La categoría recién creada.
        $categoria = Category::create([
            'num'     => $this->controlador->num('categories', $this->dueno->id, 'user_id', $this->dueno->id),
            'name'    => self::nombre_para_crear($nombre),
            'user_id' => $this->dueno->id,
        ]);

        SetPriceTypesHelper::set_price_types($categoria, $this->dueno);
        SetPriceTypesHelper::set_rangos($categoria, $this->dueno);

        // El índice se actualiza con lo recién creado: si otro nodo trae el mismo nombre normalizado,
        // se reutiliza esta y no se crea otra.
        $id = (int) $categoria->id;

        $this->categorias[$clave]     = $id;
        $this->categorias_vivas[$id]  = true;
        $this->subcategorias[$id]     = [];

        return ['id' => $id, 'creada' => true];
    }

    /**
     * Busca una subcategoría por nombre normalizado DENTRO de una categoría y la reutiliza, o la crea.
     *
     * @param  int    $category_id  Categoría real del dueño (se verifica: si no es del dueño, null).
     * @param  string $nombre
     * @return array|null  ['id' => int, 'creada' => bool] o null si la categoría no es del dueño o el
     *                     nombre no se puede usar.
     */
    public function buscar_o_crear_subcategoria($category_id, $nombre)
    {
        if (!CategoryProposalNombreHelper::es_usable($nombre)) {
            return null;
        }

        // Nunca se cuelga una subcategoría de una categoría ajena o borrada.
        if (!$this->categoria_es_del_dueno($category_id)) {
            return null;
        }

        // La categoría como entero (ya se comprobó que es del dueño y está viva).
        $category_id = (int) $category_id;

        // La clave de comparación del nombre, dentro de esa categoría.
        $clave = CategoryProposalNombreHelper::clave_de($nombre);

        if (isset($this->subcategorias[$category_id][$clave])) {
            return ['id' => $this->subcategorias[$category_id][$clave], 'creada' => false];
        }

        // La subcategoría recién creada, colgada de la categoría del dueño.
        $sub = SubCategory::create([
            'num'         => $this->controlador->num('sub_categories', $this->dueno->id, 'user_id', $this->dueno->id),
            'name'        => self::nombre_para_crear($nombre),
            'category_id' => $category_id,
            'user_id'     => $this->dueno->id,
        ]);

        // El alta del sistema (`SubCategoryController::store`) arma las listas por categoría de la
        // subcategoría; el helper solo actúa si el dueño tiene esa extensión.
        SetPriceTypesHelper::set_price_types($sub, $this->dueno);

        // El id de la subcategoría creada, para dejarla en el índice.
        $id = (int) $sub->id;

        $this->subcategorias[$category_id][$clave] = $id;
        $this->subcategorias_vivas[$id]            = $category_id;

        return ['id' => $id, 'creada' => true];
    }

    /**
     * Carga el índice si todavía no se cargó.
     *
     * @return void
     */
    protected function asegurar_indice()
    {
        if (is_null($this->categorias)) {
            $this->cargar_indice();
        }
    }

    /**
     * Lee las categorías y subcategorías vivas del dueño (SoftDeletes ya deja afuera las de la
     * papelera), de la más vieja a la más nueva, y arma los cuatro mapas. Solo trae las columnas que
     * hacen falta: un comercio puede tener cientos de categorías y miles de subcategorías.
     *
     * @return void
     */
    protected function cargar_indice()
    {
        $this->categorias          = [];
        $this->categorias_vivas    = [];
        $this->subcategorias       = [];
        $this->subcategorias_vivas = [];

        // Cuántas categorías repetidas por nombre se encontraron (gana la más vieja).
        $duplicadas = 0;

        // Las categorías vivas del dueño, de la más vieja a la más nueva (solo `id` y `name`).
        $categorias = Category::where('user_id', $this->dueno->id)->orderBy('id')->get(['id', 'name']);

        foreach ($categorias as $categoria) {
            // El id de esta categoría como entero.
            $id = (int) $categoria->id;

            // Existe y es del dueño, aunque no se pueda reutilizar por nombre.
            $this->categorias_vivas[$id] = true;
            $this->subcategorias[$id]    = [];

            // `La de siempre` y los nombres vacíos no se reutilizan nunca.
            if (!CategoryProposalNombreHelper::es_usable($categoria->name)) {
                continue;
            }

            // La clave de comparación de su nombre.
            $clave = CategoryProposalNombreHelper::clave_de($categoria->name);

            if (isset($this->categorias[$clave])) {
                $duplicadas++;
                continue;
            }

            $this->categorias[$clave] = $id;
        }

        // Las subcategorías vivas del dueño, de la más vieja a la más nueva (solo lo que hace falta).
        $subcategorias = SubCategory::where('user_id', $this->dueno->id)->orderBy('id')->get(['id', 'name', 'category_id']);

        foreach ($subcategorias as $sub) {
            // El id de esta subcategoría como entero.
            $id = (int) $sub->id;

            // La categoría de la que cuelga, como entero.
            $category_id = (int) $sub->category_id;

            // Una subcategoría colgada de una categoría que ya no existe (o de otro dueño) no cuenta.
            if (!isset($this->categorias_vivas[$category_id])) {
                continue;
            }

            $this->subcategorias_vivas[$id] = $category_id;

            // La clave de comparación de su nombre, dentro de su categoría.
            $clave = CategoryProposalNombreHelper::clave_de($sub->name);

            if ($clave === '' || isset($this->subcategorias[$category_id][$clave])) {
                continue;
            }

            $this->subcategorias[$category_id][$clave] = $id;
        }

        if ($duplicadas > 0) {
            Log::info('[CategoriaReal] El dueño '.$this->dueno->id.' tiene '.$duplicadas.' categorías repetidas por nombre: se usa la más vieja de cada una.');
        }
    }
}
