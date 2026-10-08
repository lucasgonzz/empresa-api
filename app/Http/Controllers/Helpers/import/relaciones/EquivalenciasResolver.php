<?php

namespace App\Http\Controllers\Helpers\import\relaciones;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportEquivalencia;
use App\Models\Location;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\Provincia;
use App\Models\Seller;
use App\Models\SubCategory;
use Illuminate\Support\Facades\Log;

/**
 * Las decisiones del usuario sobre nombres parecidos (`equivalencias` del POST de importación) y
 * las equivalencias recordadas del comercio, aplicadas al resolver una relación de la importación
 * (misión importacion-relaciones-parecidas, 8/10/2026).
 *
 * El orden de resolución de un valor `v`, el mismo para las cuatro relaciones de artículos (y,
 * con el camino de hoy al final, para las de clientes y proveedores):
 *   1. Decisión del usuario para (tipo, v [, padre]): `usar` → ese id; `crear` → el modelo con
 *      ese nombre EXACTO o uno nuevo; `unir` → se resuelve `con` en su lugar (un solo salto).
 *   2. Equivalencia recordada (tipo, contexto, clave de v) hacia un modelo vivo.
 *   3. Nombre exacto (comparación binaria).
 *   4. Clave de cache: la MÁS VIEJA gana.
 *   5. Crear.
 *
 * Los índices (exacto / clave / ids vivos) se cargan en memoria una vez por lote: el worker
 * resuelve miles de filas y no puede hacer una consulta por fila.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class EquivalenciasResolver
{
    const TIPOS_ARTICULOS   = ['proveedor', 'categoria', 'sub_categoria', 'marca'];
    const TIPOS_CLIENTES    = ['provincia', 'localidad', 'vendedor', 'tipo_de_precio'];
    const TIPOS_PROVEEDORES = ['localidad'];

    /** Tipos que se comparan dentro de un padre (categoría, provincia). */
    const TIPOS_CON_PADRE = ['sub_categoria', 'localidad'];

    /** Largo de `import_equivalencias.valor_clave` (ver la migración: 191 por el índice único). */
    const LARGO_CLAVE = 191;

    /** Decisiones que se aceptan por importación, como máximo (ver acotar()). */
    const TOPE_DECISIONES = 3000;

    /** @var int */
    protected $user_id;

    /**
     * Decisiones saneadas, indexadas por tipo, padre ('' = sin padre) y valor exacto.
     *
     * @var array [tipo => [padre => [valor => decision]]]
     */
    protected $decisiones = [];

    /**
     * @var array [tipo => [contexto => ['exacto' => [nombre => id], 'clave' => [clave => id], 'ids' => [id => true]]]]
     */
    protected $indices = [];

    /**
     * Equivalencias recordadas del comercio, cargadas la primera vez que se piden.
     *
     * @var array|null [tipo => [contexto => [valor_clave => model_id]]]
     */
    protected $recordadas = null;

    /**
     * Escrituras ya hechas sobre import_equivalencias en esta instancia ("tipo|contexto|clave"),
     * para escribir una vez por valor y por lote y no una por fila.
     *
     * @var array
     */
    protected $escritas = [];

    /**
     * Claves de cache de los valores con decisión `crear`, por tipo y padre (como las decisiones):
     * [tipo => [padre => [clave => true]]].
     * Ver resolver_sin_decision(): un valor SIN decisión que comparte clave con uno que el usuario
     * pidió crear aparte no puede caer por clave en el que se creó.
     *
     * @var array
     */
    protected $claves_con_crear = [];

    /**
     * Lo mismo, con la clave sin tildes (clientes y proveedores: el camino de siempre busca con un
     * WHERE de MySQL, que ignora mayúsculas Y tildes).
     *
     * @var array
     */
    protected $claves_con_crear_sin_tildes = [];

    /**
     * @param int   $user_id        Dueño del comercio.
     * @param array $equivalencias  Las decisiones tal como vinieron del request (se sanean acá).
     * @param array $tipos_validos  Los tipos que admite esta importación.
     */
    public function __construct($user_id, $equivalencias = [], array $tipos_validos = [])
    {
        $this->user_id = (int) $user_id;

        if (count($tipos_validos) === 0) {
            $tipos_validos = array_merge(self::TIPOS_ARTICULOS, self::TIPOS_CLIENTES);
        }

        foreach (self::sanear($this->user_id, $equivalencias, $tipos_validos) as $decision) {
            $padre = is_null($decision['padre']) ? '' : $decision['padre'];

            /* Si el request trae dos decisiones para el mismo valor, gana la última. */
            $this->decisiones[$decision['tipo']][$padre][$decision['valor']] = $decision;
        }

        foreach ($this->decisiones as $tipo => $por_padre) {
            foreach ($por_padre as $por_valor) {
                foreach ($por_valor as $decision) {
                    if ($decision['accion'] === 'crear') {
                        $padre = is_null($decision['padre']) ? '' : $decision['padre'];

                        $this->claves_con_crear[$tipo][$padre][RelacionesParecidasDetector::clave_cache($decision['valor'])] = true;
                        $this->claves_con_crear_sin_tildes[$tipo][$padre][RelacionesParecidasDetector::clave_sin_tildes($decision['valor'])] = true;
                    }
                }
            }
        }
    }

    /* =====================================================================
     * Saneado
     * ================================================================== */

    /**
     * Las decisiones tal como vinieron del request, normalizadas a una lista de arrays y con TOPE:
     * más de TOPE_DECISIONES entradas se cortan (con un warning). Viajan serializadas en cada lote
     * de la cadena de importación, y el paso 3 nunca muestra tantas: un request con decenas de
     * miles es un error o un abuso, no un usuario.
     *
     * @param  mixed $equivalencias  array, o el mismo array como JSON
     * @return array
     */
    public static function acotar($equivalencias)
    {
        if (is_string($equivalencias)) {
            $decodificado = json_decode($equivalencias, true);
            $equivalencias = is_array($decodificado) ? $decodificado : [];
        }

        if (!is_array($equivalencias)) {
            return [];
        }

        $equivalencias = array_values(array_filter($equivalencias, 'is_array'));

        if (count($equivalencias) > self::TOPE_DECISIONES) {
            Log::warning('EquivalenciasResolver: vinieron más decisiones que el tope; se ignoran las de más', [
                'recibidas' => count($equivalencias),
                'tope'      => self::TOPE_DECISIONES,
            ]);

            $equivalencias = array_slice($equivalencias, 0, self::TOPE_DECISIONES);
        }

        return $equivalencias;
    }

    /**
     * Las decisiones válidas del request. Lo que no sirve se descarta en silencio y esa fila sigue
     * el camino normal:
     *   - `tipo` fuera de la lista de esta importación;
     *   - `valor` vacío, o `accion` que no es usar / crear / unir;
     *   - `usar` con un id que no existe, está borrado o es de otro comercio;
     *   - `unir` con `con` vacío o igual al valor.
     *
     * A cada `usar` le agrega el contexto del modelo elegido (categoría de la subcategoría,
     * provincia de la localidad): el worker lo necesita para aplicar la decisión solo cuando la
     * fila resolvió ese mismo padre.
     *
     * @param  int   $user_id
     * @param  mixed $equivalencias
     * @param  array $tipos_validos
     * @return array lista de ['tipo', 'valor', 'padre', 'accion', 'id', 'con', 'contexto_modelo']
     */
    public static function sanear($user_id, $equivalencias, array $tipos_validos)
    {
        $equivalencias = self::acotar($equivalencias);

        $candidatas = [];
        $ids_por_tipo = [];

        foreach ($equivalencias as $cruda) {
            if (!is_array($cruda)) {
                continue;
            }

            $tipo   = isset($cruda['tipo']) && is_string($cruda['tipo']) ? $cruda['tipo'] : '';
            $accion = isset($cruda['accion']) && is_string($cruda['accion']) ? $cruda['accion'] : '';
            $valor  = isset($cruda['valor']) && is_scalar($cruda['valor']) ? trim((string) $cruda['valor']) : '';

            if (!in_array($tipo, $tipos_validos, true) || $valor === '') {
                continue;
            }

            if (!in_array($accion, ['usar', 'crear', 'unir'], true)) {
                continue;
            }

            $padre = null;

            if (in_array($tipo, self::TIPOS_CON_PADRE, true) && isset($cruda['padre']) && is_scalar($cruda['padre'])) {
                $padre = trim((string) $cruda['padre']);
                $padre = $padre === '' ? null : $padre;
            }

            $decision = [
                'tipo'            => $tipo,
                'valor'           => $valor,
                'padre'           => $padre,
                'accion'          => $accion,
                'id'              => null,
                'con'             => null,
                'contexto_modelo' => 0,
            ];

            if ($accion === 'usar') {
                $id = isset($cruda['id']) && is_numeric($cruda['id']) ? (int) $cruda['id'] : 0;

                if ($id <= 0) {
                    continue;
                }

                $decision['id'] = $id;
                $ids_por_tipo[$tipo][$id] = true;
            }

            if ($accion === 'unir') {
                $con = isset($cruda['con']) && is_scalar($cruda['con']) ? trim((string) $cruda['con']) : '';

                if ($con === '' || $con === $valor) {
                    continue;
                }

                $decision['con'] = $con;
            }

            $candidatas[] = $decision;
        }

        /* Una consulta por tipo para validar todos los `usar` juntos. */
        $vivos = [];

        foreach ($ids_por_tipo as $tipo => $ids) {
            $vivos[$tipo] = self::modelos_vivos($tipo, $user_id, array_keys($ids));
        }

        $saneadas = [];

        foreach ($candidatas as $decision) {
            if ($decision['accion'] === 'usar') {
                if (!isset($vivos[$decision['tipo']][$decision['id']])) {
                    continue;
                }

                $decision['contexto_modelo'] = (int) $vivos[$decision['tipo']][$decision['id']];
            }

            $saneadas[] = $decision;
        }

        return $saneadas;
    }

    /**
     * Ids vivos (no borrados, del comercio) de un tipo, con su contexto: [id => contexto].
     *
     * @param  string $tipo
     * @param  int    $user_id
     * @param  array  $ids
     * @return array
     */
    protected static function modelos_vivos($tipo, $user_id, array $ids)
    {
        $consulta = self::consulta_del_tipo($tipo, $user_id);

        if (is_null($consulta) || count($ids) === 0) {
            return [];
        }

        $vivos = [];

        foreach ($consulta->whereIn('id', $ids)->get() as $modelo) {
            $vivos[(int) $modelo->id] = self::contexto_del_modelo($tipo, $modelo);
        }

        return $vivos;
    }

    /**
     * La consulta base de los modelos de un tipo: del comercio y sin borrados (los modelos con
     * SoftDeletes ya los excluyen solos).
     *
     * @param  string $tipo
     * @param  int    $user_id
     * @return \Illuminate\Database\Eloquent\Builder|null
     */
    public static function consulta_del_tipo($tipo, $user_id)
    {
        $modelos = [
            'proveedor'      => Provider::class,
            'categoria'      => Category::class,
            'sub_categoria'  => SubCategory::class,
            'marca'          => Brand::class,
            'provincia'      => Provincia::class,
            'localidad'      => Location::class,
            'vendedor'       => Seller::class,
            'tipo_de_precio' => PriceType::class,
        ];

        if (!isset($modelos[$tipo])) {
            return null;
        }

        $clase = $modelos[$tipo];

        $columnas = ['id', 'name'];

        if ($tipo === 'sub_categoria') {
            $columnas[] = 'category_id';
        }

        if ($tipo === 'localidad') {
            $columnas[] = 'provincia_id';
        }

        return $clase::where('user_id', (int) $user_id)->select($columnas)->orderBy('id');
    }

    /**
     * Contexto de un modelo para las equivalencias: categoría de la subcategoría, provincia de la
     * localidad (0 si no tiene), 0 para el resto.
     *
     * @param  string $tipo
     * @param  mixed  $modelo
     * @return int
     */
    public static function contexto_del_modelo($tipo, $modelo)
    {
        if ($tipo === 'sub_categoria') {
            return (int) $modelo->category_id;
        }

        if ($tipo === 'localidad') {
            return (int) $modelo->provincia_id;
        }

        return 0;
    }

    /**
     * La clave con la que se guarda y se busca una equivalencia: el valor con trim y los espacios
     * internos colapsados, recortado al largo de la columna. Se recorta en los dos lados con la
     * misma función: si solo se recortara al guardar, un valor largo no se encontraría nunca.
     *
     * 🔴 RESPETA mayúsculas y tildes (y la columna es utf8mb4_bin). Una equivalencia es la
     * decisión que el usuario tomó sobre ESE texto: con una clave más gruesa (minúsculas, como la
     * clave de cache), "DEL NORTE"→usar y "Del norte"→crear del mismo Excel caían en la misma fila
     * y la segunda borraba a la primera, y "Pintureria"→usar se aplicaba sola a "PINTURERIA" sin
     * que nadie la hubiera elegido. Lo parecido lo vuelve a preguntar el paso 3.
     *
     * @param  mixed $valor
     * @return string
     */
    public static function clave_guardable($valor)
    {
        $texto = trim((string) $valor);

        $colapsado = preg_replace('/\s+/u', ' ', $texto);

        if (is_string($colapsado)) {
            $texto = $colapsado;
        }

        return mb_substr($texto, 0, self::LARGO_CLAVE, 'UTF-8');
    }

    /* =====================================================================
     * Decisiones
     * ================================================================== */

    /**
     * ¿Vino alguna decisión en el request?
     *
     * @return bool
     */
    public function hay_decisiones()
    {
        return count($this->decisiones) > 0;
    }

    /**
     * El padre como clave de los índices de decisiones: '' = sin padre.
     *
     * @param  string|null $padre
     * @return string
     */
    protected static function clave_de_padre($padre)
    {
        return is_null($padre) ? '' : trim((string) $padre);
    }

    /**
     * La decisión del usuario para un valor, o null.
     *
     * 🔴 Para los tipos con padre, la decisión tiene que ser la de ESE padre: si la fila trae padre
     * solo vale una decisión con el mismo padre, y si no trae, solo una sin padre. No se cae de una
     * a la otra: "Rosario" sin provincia (contra todas las localidades) y "Rosario" bajo "Santa
     * Fe" son dos preguntas distintas del paso 3, y la respuesta a una no es la de la otra.
     *
     * @param  string      $tipo
     * @param  mixed       $valor
     * @param  string|null $padre
     * @return array|null
     */
    public function decision($tipo, $valor, $padre = null)
    {
        $valor = trim((string) $valor);

        if ($valor === '' || !isset($this->decisiones[$tipo])) {
            return null;
        }

        $padre = is_null($padre) ? '' : trim((string) $padre);

        if (isset($this->decisiones[$tipo][$padre][$valor])) {
            return $this->decisiones[$tipo][$padre][$valor];
        }

        return null;
    }

    /**
     * Guarda (o borra) las equivalencias recordadas que salen de las decisiones, al arrancar la
     * importación: cada `usar` se recuerda; cada `crear` borra la que hubiera (el usuario cambió de
     * idea). Los `crear` de tipos con padre los borra el worker, que es el que sabe en qué
     * categoría (o provincia) cayó la fila. Los `unir` los guarda el worker cuando conoce el id.
     *
     * @return void
     */
    public function recordar_decisiones()
    {
        foreach ($this->decisiones as $tipo => $por_padre) {
            foreach ($por_padre as $padre => $por_valor) {
                foreach ($por_valor as $valor => $decision) {
                    try {
                        if ($decision['accion'] === 'usar') {
                            /*
                             * Localidad SIN padre se busca en el contexto 0 (todas las localidades),
                             * aunque la elegida tenga provincia: el contexto es el de la búsqueda.
                             */
                            $contexto = $this->contexto_de_decision($decision);

                            $this->recordar($tipo, $contexto, $decision['valor'], $decision['id']);
                        } elseif ($decision['accion'] === 'crear' && !$this->depende_del_padre($decision)) {
                            $this->olvidar($tipo, 0, $decision['valor']);
                        }
                    } catch (\Throwable $e) {
                        /* Recordar es una comodidad: la importación sale igual. */
                        Log::warning('EquivalenciasResolver: no se pudo recordar una decisión', [
                            'tipo'    => $tipo,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * @param  array $decision
     * @return bool  true si el contexto de la decisión es el padre de la fila
     */
    protected function depende_del_padre(array $decision)
    {
        if ($decision['tipo'] === 'sub_categoria') {
            return true;
        }

        return $decision['tipo'] === 'localidad' && !is_null($decision['padre']);
    }

    /**
     * @param  array $decision
     * @return int
     */
    protected function contexto_de_decision(array $decision)
    {
        return $this->depende_del_padre($decision) ? (int) $decision['contexto_modelo'] : 0;
    }

    /* =====================================================================
     * Índices en memoria
     * ================================================================== */

    /**
     * Carga el índice de un tipo con los modelos dados (id, name y, si corresponde, el contexto).
     * Los modelos tienen que llegar ordenados por id: la más vieja gana.
     *
     * @param  string   $tipo
     * @param  iterable $modelos
     * @param  callable|null $contexto_de  fn($modelo): int|int[] — contexto(s) donde va cada modelo
     * @return void
     */
    public function cargar_indice($tipo, $modelos, $contexto_de = null)
    {
        $this->indices[$tipo] = [];

        foreach ($modelos as $modelo) {
            $contextos = is_null($contexto_de) ? [0] : (array) call_user_func($contexto_de, $modelo);

            foreach ($contextos as $contexto) {
                $this->agregar_al_indice($tipo, (int) $contexto, (int) $modelo->id, (string) $modelo->name);
            }
        }
    }

    /**
     * ¿Ya se cargó el índice de este tipo?
     *
     * @param  string $tipo
     * @return bool
     */
    public function tiene_indice($tipo)
    {
        return isset($this->indices[$tipo]);
    }

    /**
     * Suma un modelo al índice (los que crea la importación misma, por ejemplo). Si ya hay otro con
     * el mismo nombre o la misma clave, NO lo pisa: la más vieja gana.
     *
     * @param  string $tipo
     * @param  int    $contexto
     * @param  int    $id
     * @param  string $nombre
     * @return void
     */
    public function agregar_al_indice($tipo, $contexto, $id, $nombre)
    {
        $contexto = (int) $contexto;

        if (!isset($this->indices[$tipo][$contexto])) {
            $this->indices[$tipo][$contexto] = ['exacto' => [], 'clave' => [], 'ids' => []];
        }

        $indice = &$this->indices[$tipo][$contexto];

        $indice['ids'][(int) $id] = true;

        if (!array_key_exists($nombre, $indice['exacto'])) {
            $indice['exacto'][$nombre] = (int) $id;
        }

        $clave = RelacionesParecidasDetector::clave_cache($nombre);

        /*
         * 🔴 La más vieja gana, no la última. Antes esto era un mapWithKeys() que se quedaba con
         * la ÚLTIMA: con "DEL NORTE" (id 5) y "Del norte" (id 9) cargadas, "del norte" se iba a la
         * 9, que es justo la que el usuario creó aparte a propósito.
         */
        if (!array_key_exists($clave, $indice['clave'])) {
            $indice['clave'][$clave] = (int) $id;
        }

        unset($indice);
    }

    /**
     * Id del modelo con ese nombre EXACTO (comparación binaria: es un array de PHP, no MySQL).
     *
     * @param  string $tipo
     * @param  int    $contexto
     * @param  mixed  $valor
     * @return int|null
     */
    public function buscar_exacto($tipo, $contexto, $valor)
    {
        $valor = trim((string) $valor);

        if (isset($this->indices[$tipo][(int) $contexto]['exacto'][$valor])) {
            return (int) $this->indices[$tipo][(int) $contexto]['exacto'][$valor];
        }

        return null;
    }

    /**
     * Id del modelo MÁS VIEJO con la misma clave de cache.
     *
     * @param  string $tipo
     * @param  int    $contexto
     * @param  mixed  $valor
     * @return int|null
     */
    public function buscar_por_clave($tipo, $contexto, $valor)
    {
        $clave = RelacionesParecidasDetector::clave_cache($valor);

        if (isset($this->indices[$tipo][(int) $contexto]['clave'][$clave])) {
            return (int) $this->indices[$tipo][(int) $contexto]['clave'][$clave];
        }

        return null;
    }

    /**
     * ¿El id está vivo en ese contexto? (Para validar recordadas y `usar` contra el padre.)
     *
     * @param  string $tipo
     * @param  int    $contexto
     * @param  int    $id
     * @return bool
     */
    public function esta_en_el_indice($tipo, $contexto, $id)
    {
        return isset($this->indices[$tipo][(int) $contexto]['ids'][(int) $id]);
    }

    /* =====================================================================
     * Equivalencias recordadas
     * ================================================================== */

    /**
     * La consulta sobre import_equivalencias. Un solo punto para que un test pueda simular la tabla
     * ausente.
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    protected static function consulta_equivalencias()
    {
        return ImportEquivalencia::query();
    }

    /**
     * 🔴 Todo lo que toca import_equivalencias (cargar, recordar, olvidar) tolera que la tabla no
     * esté: un cliente al que todavía no le corrió la migración tiene que poder seguir importando
     * —por el modal, por AdminSync o por el import clásico—, solo que sin equivalencias
     * recordadas. Recordar es una comodidad, la importación no.
     *
     * @return void
     */
    protected function cargar_recordadas()
    {
        if (!is_null($this->recordadas)) {
            return;
        }

        $this->recordadas = [];

        try {
            foreach (static::consulta_equivalencias()->where('user_id', $this->user_id)->get() as $fila) {
                $this->recordadas[$fila->tipo][(int) $fila->contexto_id][(string) $fila->valor_clave] = (int) $fila->model_id;
            }
        } catch (\Throwable $e) {
            $this->recordadas = [];

            Log::warning('EquivalenciasResolver: no se pudieron leer las equivalencias recordadas; se sigue sin ellas', [
                'user_id' => $this->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Todas las recordadas de un tipo y contexto: [valor_clave => model_id]. Para el chequeo.
     * Tolera la tabla ausente, igual que cargar_recordadas().
     *
     * @param  int    $user_id
     * @param  string $tipo
     * @return array [contexto => [valor_clave => model_id]]
     */
    public static function recordadas_del_tipo($user_id, $tipo)
    {
        $recordadas = [];

        try {
            foreach (static::consulta_equivalencias()->where('user_id', (int) $user_id)->where('tipo', $tipo)->get() as $fila) {
                $recordadas[(int) $fila->contexto_id][(string) $fila->valor_clave] = (int) $fila->model_id;
            }
        } catch (\Throwable $e) {
            Log::warning('EquivalenciasResolver: no se pudieron leer las equivalencias recordadas para el chequeo', [
                'user_id' => (int) $user_id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return $recordadas;
    }

    /**
     * El id recordado para este valor, si el modelo sigue vivo en ese contexto (o si no hay índice
     * cargado para validarlo, el id tal cual).
     *
     * @param  string $tipo
     * @param  int    $contexto
     * @param  mixed  $valor
     * @return int|null
     */
    public function recordada($tipo, $contexto, $valor)
    {
        $this->cargar_recordadas();

        $clave = self::clave_guardable($valor);

        if (!isset($this->recordadas[$tipo][(int) $contexto][$clave])) {
            return null;
        }

        $id = (int) $this->recordadas[$tipo][(int) $contexto][$clave];

        if (isset($this->indices[$tipo]) && !$this->esta_en_el_indice($tipo, $contexto, $id)) {
            return null;
        }

        return $id;
    }

    /**
     * Recuerda (upsert) que este valor es este modelo. Una vez por valor y por instancia.
     *
     * @param  string $tipo
     * @param  int    $contexto
     * @param  mixed  $valor
     * @param  int    $model_id
     * @return void
     */
    public function recordar($tipo, $contexto, $valor, $model_id)
    {
        $clave = self::clave_guardable($valor);
        $marca = $tipo . '|' . (int) $contexto . '|' . $clave;

        if (isset($this->escritas[$marca])) {
            return;
        }

        $this->escritas[$marca] = true;

        $this->cargar_recordadas();
        $this->recordadas[$tipo][(int) $contexto][$clave] = (int) $model_id;

        try {
            static::consulta_equivalencias()->updateOrCreate(
                [
                    'user_id'     => $this->user_id,
                    'tipo'        => $tipo,
                    'contexto_id' => (int) $contexto,
                    'valor_clave' => $clave,
                ],
                [
                    'valor'    => mb_substr(trim((string) $valor), 0, 255, 'UTF-8'),
                    'model_id' => (int) $model_id,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('EquivalenciasResolver: no se pudo recordar una equivalencia; la importación sigue', [
                'tipo'    => $tipo,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Borra la equivalencia recordada de este valor, si había. Una vez por valor y por instancia.
     *
     * @param  string $tipo
     * @param  int    $contexto
     * @param  mixed  $valor
     * @return void
     */
    public function olvidar($tipo, $contexto, $valor)
    {
        $clave = self::clave_guardable($valor);
        $marca = $tipo . '|' . (int) $contexto . '|' . $clave;

        if (isset($this->escritas[$marca])) {
            return;
        }

        $this->escritas[$marca] = true;

        $this->cargar_recordadas();
        unset($this->recordadas[$tipo][(int) $contexto][$clave]);

        try {
            static::consulta_equivalencias()
                ->where('user_id', $this->user_id)
                ->where('tipo', $tipo)
                ->where('contexto_id', (int) $contexto)
                ->where('valor_clave', $clave)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('EquivalenciasResolver: no se pudo borrar una equivalencia; la importación sigue', [
                'tipo'    => $tipo,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ¿Se aplica un `usar` en este contexto?
     *
     * 🔴 Una subcategoría (o una localidad) elegida bajo OTRO padre no se aplica: la fila resolvió
     * una categoría (o provincia) distinta de la que el usuario tenía en pantalla —cambió la
     * decisión del padre, o la equivalencia del padre era otra—, y meter el artículo en una
     * subcategoría de otra categoría lo deja inconsistente. Contexto 0 = la fila no tiene padre
     * (localidad sin provincia): ahí no hay contra qué validar.
     *
     * @param  array $decision
     * @param  int   $contexto
     * @return bool
     */
    protected function aplica_usar(array $decision, $contexto)
    {
        if (!in_array($decision['tipo'], self::TIPOS_CON_PADRE, true) || (int) $contexto === 0) {
            return true;
        }

        return (int) $decision['contexto_modelo'] === (int) $contexto;
    }

    /* =====================================================================
     * Resolución (clientes y proveedores)
     * ================================================================== */

    /**
     * Carga el índice de un tipo desde la base si todavía no se cargó (clientes y proveedores lo
     * piden la primera vez que una fila trae ese tipo). La localidad va en el contexto de su
     * provincia Y en el contexto 0 (todas), que es contra lo que se busca una fila sin provincia.
     *
     * @param  string $tipo
     * @return void
     */
    public function asegurar_indice($tipo)
    {
        if ($this->tiene_indice($tipo)) {
            return;
        }

        $consulta = self::consulta_del_tipo($tipo, $this->user_id);

        if (is_null($consulta)) {
            $this->indices[$tipo] = [];
            return;
        }

        $this->cargar_indice($tipo, $consulta->get(), function ($modelo) use ($tipo) {
            $contexto = self::contexto_del_modelo($tipo, $modelo);

            if ($tipo === 'localidad' && $contexto !== 0) {
                return [$contexto, 0];
            }

            return [$contexto];
        });
    }

    /**
     * Resuelve un valor de la importación de clientes o proveedores. Mismo orden que los
     * artículos, pero en lugar de "clave de cache → crear" el final es el CAMINO DE HOY
     * (LocalImportHelper), que no cambia:
     *   1. decisión: `usar` → el id; `crear` → el nombre exacto o uno nuevo; `unir` → el valor se
     *      reemplaza por el canónico y sigue por acá (y después se recuerda);
     *   2. equivalencia recordada;
     *   3. nombre exacto (binario), para que "JUAN PEREZ" vaya al viejo aunque exista un
     *      "Juan Perez" que el usuario creó aparte (el WHERE de MySQL no los distingue);
     *   4. el camino de hoy.
     *
     * @param  string      $tipo
     * @param  mixed       $valor
     * @param  int         $contexto       provincia_id para localidad con provincia, 0 el resto
     * @param  string|null $padre          provincia tal como vino en el Excel
     * @param  callable    $camino_de_hoy  fn(string): int|null — el LocalImportHelper de siempre
     * @param  callable    $crear_forzado  fn(string): int — crea con ese nombre sí o sí
     * @return int|null
     */
    public function resolver_con_camino_de_hoy($tipo, $valor, $contexto, $padre, $camino_de_hoy, $crear_forzado)
    {
        $this->asegurar_indice($tipo);

        $valor = trim((string) $valor);
        $contexto = (int) $contexto;

        $decision = $this->decision($tipo, $valor, $padre);
        $original = null;

        if (!is_null($decision)) {
            if ($decision['accion'] === 'usar' && $this->aplica_usar($decision, $contexto)) {
                return (int) $decision['id'];
            }

            if ($decision['accion'] === 'crear') {
                $this->olvidar($tipo, $contexto, $valor);

                return $this->exacto_o_crear($tipo, $contexto, $valor, $crear_forzado);
            }

            if ($decision['accion'] === 'unir') {
                $original = $valor;
                $valor = $decision['con'];
            }
        }

        $id = $this->recordada($tipo, $contexto, $valor);

        if (is_null($id)) {
            $id = $this->buscar_exacto($tipo, $contexto, $valor);
        }

        /*
         * Mismo motivo que en resolver_sin_decision(): si el usuario pidió crear aparte otra
         * escritura que el WHERE de MySQL no distingue de ésta (mayúsculas o tildes), el camino de
         * siempre caería en la que se creó. Va por nombre exacto, sin importar el orden de las filas.
         */
        if (is_null($id) && isset($this->claves_con_crear_sin_tildes[$tipo][self::clave_de_padre($padre)][RelacionesParecidasDetector::clave_sin_tildes($valor)])) {
            $id = $this->exacto_o_crear($tipo, $contexto, $valor, $crear_forzado);
        }

        if (is_null($id)) {
            $id = call_user_func($camino_de_hoy, $valor);

            if (!is_null($id)) {
                $id = (int) $id;
                $this->agregar_al_indice($tipo, $contexto, $id, $valor);
            }
        }

        if (!is_null($original) && !is_null($id)) {
            try {
                $this->recordar($tipo, $contexto, $original, $id);
            } catch (\Throwable $e) {
                Log::warning('EquivalenciasResolver: no se pudo recordar un unir', [
                    'tipo'    => $tipo,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $id;
    }

    /* =====================================================================
     * Resolución (artículos)
     * ================================================================== */

    /**
     * Resuelve un valor con el orden completo (decisión → recordada → exacto → clave → crear).
     *
     * @param  string      $tipo
     * @param  mixed       $valor     ya con trim y no vacío
     * @param  int         $contexto  categoría para sub_categoria, 0 el resto
     * @param  string|null $padre     categoría tal como vino en el Excel (sub_categoria)
     * @param  callable    $crear     fn(string $nombre): int — crea el modelo y devuelve su id
     * @return int
     */
    public function resolver($tipo, $valor, $contexto, $padre, $crear)
    {
        $valor = trim((string) $valor);
        $contexto = (int) $contexto;

        $decision = $this->decision($tipo, $valor, $padre);

        if (!is_null($decision)) {
            if ($decision['accion'] === 'usar') {
                if ($this->aplica_usar($decision, $contexto)) {
                    return (int) $decision['id'];
                }
            } elseif ($decision['accion'] === 'crear') {
                $this->olvidar($tipo, $contexto, $valor);

                return $this->exacto_o_crear($tipo, $contexto, $valor, $crear);
            } elseif ($decision['accion'] === 'unir') {
                $id = $this->resolver_destino_de_unir($tipo, $decision['con'], $contexto, $padre, $crear);

                try {
                    $this->recordar($tipo, $contexto, $valor, $id);
                } catch (\Throwable $e) {
                    Log::warning('EquivalenciasResolver: no se pudo recordar un unir', [
                        'tipo'    => $tipo,
                        'message' => $e->getMessage(),
                    ]);
                }

                return $id;
            }
        }

        return $this->resolver_sin_decision($tipo, $valor, $contexto, $crear, $padre);
    }

    /**
     * El destino de un `unir`: un solo salto. Si el destino tiene su propia decisión `usar` o
     * `crear` se respeta; un `unir` del destino se ignora (sin recursión: dos decisiones que se
     * apuntan entre sí no pueden colgar la importación).
     *
     * @param  string      $tipo
     * @param  string      $con
     * @param  int         $contexto
     * @param  string|null $padre
     * @param  callable    $crear
     * @return int
     */
    protected function resolver_destino_de_unir($tipo, $con, $contexto, $padre, $crear)
    {
        $decision = $this->decision($tipo, $con, $padre);

        if (!is_null($decision)) {
            if ($decision['accion'] === 'usar' && $this->aplica_usar($decision, $contexto)) {
                return (int) $decision['id'];
            }

            if ($decision['accion'] === 'crear') {
                return $this->exacto_o_crear($tipo, $contexto, $con, $crear);
            }
        }

        return $this->resolver_sin_decision($tipo, $con, $contexto, $crear, $padre);
    }

    /**
     * Pasos 2 a 5: recordada → exacto → clave (la más vieja) → crear.
     *
     * @param  string   $tipo
     * @param  string   $valor
     * @param  int      $contexto
     * @param  callable $crear
     * @return int
     */
    protected function resolver_sin_decision($tipo, $valor, $contexto, $crear, $padre = null)
    {
        $id = $this->recordada($tipo, $contexto, $valor);

        if (!is_null($id)) {
            return $id;
        }

        $id = $this->buscar_exacto($tipo, $contexto, $valor);

        if (!is_null($id)) {
            return $id;
        }

        /*
         * 🔴 Un valor sin decisión que comparte la clave de cache con uno que el usuario pidió
         * CREAR aparte no puede resolverse por clave: caería en el que se creó (o, si la fila sin
         * decisión venía primero, el "crear" de la otra encontraría a ésta por nombre... y al
         * revés no). El resultado dependía del orden de las filas: con "DEL NORTE"→crear antes que
         * "Del norte" (sin decisión), "Del norte" caía en "DEL NORTE" y nunca se creaba. Si el
         * usuario separó una escritura, las demás de esa clave van por su nombre exacto.
         */
        if (isset($this->claves_con_crear[$tipo][self::clave_de_padre($padre)][RelacionesParecidasDetector::clave_cache($valor)])) {
            return $this->exacto_o_crear($tipo, $contexto, $valor, $crear);
        }

        $id = $this->buscar_por_clave($tipo, $contexto, $valor);

        if (!is_null($id)) {
            return $id;
        }

        $id = (int) call_user_func($crear, $valor);
        $this->agregar_al_indice($tipo, $contexto, $id, $valor);

        return $id;
    }

    /**
     * `crear`: el modelo con ese nombre EXACTO si ya existe (otra fila del mismo lote, o un lote
     * anterior, ya lo creó), y si no uno nuevo con ese nombre.
     *
     * 🔴 Exacto y binario, no por clave: el usuario eligió "crear uno nuevo" para "Del norte"
     * sabiendo que existe "DEL NORTE". Si esto buscara por clave de cache (o con un WHERE de
     * MySQL, cuya collation ignora mayúsculas y tildes) caería de nuevo en "DEL NORTE" y la
     * decisión no haría nada.
     *
     * @param  string   $tipo
     * @param  int      $contexto
     * @param  string   $valor
     * @param  callable $crear
     * @return int
     */
    protected function exacto_o_crear($tipo, $contexto, $valor, $crear)
    {
        $id = $this->buscar_exacto($tipo, $contexto, $valor);

        if (!is_null($id)) {
            return $id;
        }

        $id = (int) call_user_func($crear, $valor);
        $this->agregar_al_indice($tipo, $contexto, $id, $valor);

        return $id;
    }
}
