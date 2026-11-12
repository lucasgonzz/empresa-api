<?php

namespace App\Services\Importacion;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ArticleImportColumnsNormalizer;
use App\Http\Controllers\Helpers\import\article\IdentifierNormalizer;
use App\Http\Controllers\Helpers\import\excel\CsvDeHoja;
use App\Http\Controllers\Helpers\import\excel\EncabezadosRepetidos;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\ImportacionDePrueba;
use App\Models\PriceType;
use App\Models\Provider;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class PlanillaDePrueba
{
    private const CONFIANZA_MINIMA = 0.90;
    public const SEPARAR_CODIGO = '__prueba_separar_codigo';
    public const DESCUENTOS_LATERALES = '__prueba_descuentos_laterales';
    // Valor del mapeo previo que deja una columna afuera de la importación.
    public const CAMPO_IGNORAR = 'ignorar';

    /*
     * La pregunta del margen (misión prueba-e2e-campana-imperio, 11/11/2026; pedido de Lucas: «si importás
     * el costo también tenés que poner el margen de ganancia»). Sale cuando una hoja trae el costo y ninguna
     * columna dice a cuánto se vende. La clave es la del campo del importador: la respuesta viaja como una
     * columna `margen_de_ganancia` más de la planilla preparada.
     */
    public const CLAVE_MARGEN = 'margen_de_ganancia';
    // Respuesta que deja todo como hoy: se carga el costo y el dueño pone los precios después.
    public const MARGEN_DESPUES = 'despues';
    // Porcentajes que se ofrecen como opción cerrada. Además se acepta cualquier otro (`acepta_porcentaje`).
    public const MARGENES_SUGERIDOS = ['20', '30', '40', '50'];
    public const MARGEN_MAXIMO = 1000;

    // Por qué una fila no se importa: un título de sección o una nota suelta, o el total al pie.
    public const FILA_TITULO = 'titulo';
    public const FILA_TOTAL = 'total';
    // Cuántos textos de filas salteadas se guardan y se muestran, para que el dueño pueda decir «ese sí era un producto».
    public const EJEMPLOS_DE_SALTEADAS = 5;

    /*
     * Cómo se le nombra cada campo al dueño en las OPCIONES de una pregunta (misión
     * prueba-e2e-campana-imperio, ítem O). Solo cambia la `etiqueta`: el `valor` de la opción sigue siendo la
     * clave del importador, que es lo que usan el admin y el monitor. Un campo que no está acá cae a su
     * clave con espacios, como antes.
     */
    private const ETIQUETAS = [
        'numero' => 'número de artículo', 'nombre' => 'nombre del artículo', 'codigo_de_barras' => 'código de barras',
        'sku' => 'código propio (SKU)', 'codigo_de_proveedor' => 'código de proveedor', 'costo' => 'costo',
        'precio' => 'precio de venta', 'iva' => 'IVA', 'margen_de_ganancia' => 'margen de ganancia',
        'categoria' => 'categoría', 'sub_categoria' => 'subcategoría', 'marca' => 'marca', 'descripcion' => 'descripción',
        'stock_actual' => 'stock', 'stock_minimo' => 'stock mínimo', 'descuentos' => 'descuentos del proveedor (%)',
        'descuentos_montos' => 'descuentos del proveedor (en pesos)', 'recargos' => 'recargos (%)',
        'recargos_montos' => 'recargos (en pesos)', 'proveedor' => 'proveedor', 'moneda' => 'moneda del costo (pesos o dólares)',
        'aplicar_iva' => 'si lleva IVA', 'unidad_medida' => 'unidad de medida', 'u_individuales' => 'unidades por bulto',
        'medida' => 'medida', 'contenido' => 'contenido', 'in_offer' => 'en oferta', 'online' => 'visible en la tienda',
        'precio_pausado' => 'precio pausado', 'disponible_tienda_nube' => 'disponible en Tienda Nube',
    ];

    /*
     * Cómo se nombra un campo en el RESUMEN final de la prueba («También tomé de tu lista: …»), cuando no
     * alcanza la etiqueta de las opciones (misión prueba-e2e-campana-imperio, ronda 3, E1). «Número de
     * artículo» o «código propio (SKU)» no le dicen nada a un comerciante: lo que se guardó es SU código.
     */
    private const ETIQUETAS_DEL_RESUMEN = [
        'sku' => 'tu código de cada artículo',
        'numero' => 'el número de los artículos que ya tenías cargados',
    ];

    /*
     * Las respuestas más probables de una columna dudosa, por familia, y cómo se le dicen al dueño. La
     * familia sale de lo que dudó el analizador (su campo sugerido) o, si ese campo no es de ninguna, del título.
     */
    private const FAMILIAS = [
        'codigo' => [
            'campos' => ['codigo_de_proveedor', 'sku', 'codigo_de_barras'],
            'tambien' => ['numero'],
            'titulo' => '/^(?:cod|codigo|codigos|code|sku|ref|referencia|id|ean|barras|nro|numero)(?: |$)/',
        ],
        'importe' => [
            'campos' => ['costo', 'precio'],
            'tambien' => [],
            'titulo' => '/(?:^| )(?:precio|precios|costo|costos|importe|importes|valor|pvp|unitario|contado|final)(?: |$)/',
        ],
        'cantidad' => [
            'campos' => ['stock_actual', 'u_individuales'],
            'tambien' => ['stock_minimo'],
            'titulo' => '/(?:^| )(?:stock|cant|cantidad|cantidades|unid|unidades|bulto|bultos|caja|cajas|pack|existencia|existencias|hay|disponible)(?: |$)/',
        ],
    ];

    private const FRASES = [
        'codigo_de_proveedor' => 'el código que usa tu proveedor', 'sku' => 'un código tuyo',
        'codigo_de_barras' => 'el código de barras', 'costo' => 'lo que te cobra el proveedor (costo)',
        'precio' => 'a lo que vendés vos (precio de venta)', 'stock_actual' => 'el stock que tenés',
        'u_individuales' => 'cuántas unidades trae el bulto o la caja',
    ];

    // Cuántos ejemplos de una columna se muestran en su pregunta.
    private const EJEMPLOS_POR_COLUMNA = 2;

    private $run;
    private $propiedades;
    private $nombres;
    // Etiqueta legible de cada campo para las opciones de las preguntas (ver ETIQUETAS).
    private $etiquetas;
    private $proveedores;
    // Filas que `filas()` dejó afuera por no ser artículos: [índice de hoja => [motivo => cantidad, 'textos_<motivo>' => [...]]].
    private $salteadas = [];
    // Filas con costo y sin ganancia ni precio, en hojas que sí traen esas columnas: [índice de hoja => cantidad].
    private $sin_ganancia = [];
    // Filas a las que `filas()` les puso el margen que contestó el dueño porque no lo traían: [índice => cantidad].
    private $margen_agregado = [];

    public function __construct(ImportacionDePrueba $run)
    {
        $this->run = $run;
        $this->propiedades = [];
        $this->nombres = [];
        $this->etiquetas = [];
        $this->proveedores = Provider::where('user_id', $run->user_id)->pluck('name', 'id')->all();
        foreach (array_merge(AiExcelAnalyzer::propiedades_importables(), ['stock_minimo']) as $campo) {
            $canonico = ArticleImportColumnsNormalizer::normalize_property_key($campo);
            $this->propiedades[$canonico] = $canonico;
            $this->nombres[$canonico] = str_replace('_', ' ', $canonico);
            $this->etiquetas[$canonico] = self::ETIQUETAS[$canonico] ?? $this->nombres[$canonico];
        }

        foreach (Address::where('user_id', $run->user_id)->whereNull('buyer_id')->get(['id', 'street']) as $deposito) {
            $clave = str_replace(' ', '_', strtolower($deposito->street));
            foreach (['amount' => '', 'min' => 'min_', 'max' => 'max_'] as $subtipo => $prefijo) {
                $campo = 'address_' . $deposito->id . '_' . $subtipo;
                $this->propiedades[$campo] = $prefijo . $clave;
                $this->nombres[$campo] = 'Depósito ' . $deposito->street . ($prefijo === '' ? '' : ' (' . $subtipo . ')');
                $this->etiquetas[$campo] = 'Depósito ' . $deposito->street
                    . ['amount' => ': stock', 'min' => ': stock mínimo', 'max' => ': stock máximo'][$subtipo];
            }
        }

        foreach (PriceType::where('user_id', $run->user_id)->get(['id', 'name', 'catalogo_restringido_en_tienda']) as $lista) {
            $clave = str_replace(' ', '_', strtolower($lista->name));
            foreach ([
                'final_price' => '$_final_', 'percentage' => '%_', 'setear' => 'setear_precio_final_',
                'visible_en_tienda' => 'visible_en_tienda_',
            ] as $subtipo => $prefijo) {
                if ($subtipo === 'visible_en_tienda' && !$lista->catalogo_restringido_en_tienda) {
                    continue;
                }
                $campo = 'price_type_' . $lista->id . '_' . $subtipo;
                $this->propiedades[$campo] = $prefijo . $clave;
                $this->nombres[$campo] = 'Lista ' . $lista->name . ' (' . $subtipo . ')';
                $this->etiquetas[$campo] = 'Lista ' . $lista->name . [
                    'final_price' => ': precio de venta final', 'percentage' => ': margen de ganancia (%)',
                    'setear' => ': fijar el precio final (sí o no)', 'visible_en_tienda' => ': visible en la tienda (sí o no)',
                ][$subtipo];
            }
        }
    }

    private function ruta(): string
    {
        $original = storage_path('app/' . $this->run->archivo);
        if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'xls') {
            return $original;
        }

        $xlsx = $original . '.xlsx';
        if (!is_file($xlsx)) {
            $libro = IOFactory::createReader('Xls')->load($original);
            try {
                IOFactory::createWriter($libro, 'Xlsx')->setPreCalculateFormulas(false)->save($xlsx . '.tmp');
                if (!rename($xlsx . '.tmp', $xlsx)) {
                    throw new RuntimeException('No se pudo preparar el Excel.');
                }
            } finally {
                $libro->disconnectWorksheets();
            }
        }

        return $xlsx;
    }

    public function analizar(AiExcelAnalyzer $analizador): array
    {
        $ruta = $this->ruta();
        $hojas = [];
        foreach (ExcelWorkbookReader::listar_hojas($ruta) as $hoja) {
            $indice = (int) $hoja['indice'];
            $meta = ExcelWorkbookReader::asegurar_csv($ruta, $indice);
            if ((int) $meta['ultima_fila_con_contenido'] <= 1) {
                continue;
            }

            $resultado = $analizador->analyze($ruta, $this->run->nombre, [
                'hoja' => $indice,
                'hoja_nombre' => $hoja['nombre'],
                'provider_id' => $this->run->provider_id,
            ]);
            $encabezado = $resultado['encabezado_detectado'];
            $inicio = (int) ($encabezado['fila'] ?? 0) + 1;
            if ((int) $meta['ultima_fila_con_contenido'] < $inicio) {
                continue;
            }

            $titulos = $encabezado['columnas'];
            $sin_nombre = array_fill_keys($resultado['columnas_sin_nombre'] ?? [], true);
            $titulos = array_diff_key($titulos, $sin_nombre);
            // Una columna puede estar vacía en la muestra y recién tener datos más abajo.
            $datos_hoja = $this->leer_datos_de_hoja($ruta, $indice, $inicio);
            $muestras = array_diff_key($datos_hoja['muestras'], $datos_hoja['columnas_del_bloque']);

            $mapeo = [];
            foreach ($resultado['column_mapping'] as $columna) {
                $posicion = (int) $columna['excel_column_index'];
                if (!isset($muestras[$posicion])) {
                    continue;
                }
                if (isset($mapeo[$posicion])) {
                    $mapeo[$posicion]['confidence'] = 0;
                    continue;
                }
                $columna['system_property'] = ArticleImportColumnsNormalizer::normalize_property_key($columna['system_property'] ?? null);
                $mapeo[$posicion] = $columna;
            }

            foreach ($titulos as $posicion => $titulo) {
                if (isset($muestras[$posicion]) && !isset($mapeo[$posicion]) && trim((string) $titulo) !== '') {
                    $mapeo[$posicion] = ['excel_column' => $titulo, 'confidence' => 0];
                }
            }
            foreach (array_keys($muestras) as $posicion) {
                if (!isset($mapeo[$posicion])) {
                    $mapeo[$posicion] = ['excel_column' => '', 'confidence' => 0];
                }
            }
            ksort($mapeo);

            $descripcion_como_nombre = $this->descripcion_como_nombre($titulos, $mapeo, $muestras);
            if ($descripcion_como_nombre !== null) {
                $mapeo[$descripcion_como_nombre]['system_property'] = 'nombre';
                $mapeo[$descripcion_como_nombre]['confidence'] = 1;
            }
            $precio_sin_tipo = $this->precio_de_proveedor_sin_tipo($titulos, $mapeo, $muestras);
            if ($precio_sin_tipo !== null) {
                $mapeo[$precio_sin_tipo]['confidence'] = 0;
            }

            $propiedades = array_count_values(array_filter(array_column($mapeo, 'system_property'), 'is_string'));
            $columnas = [];
            foreach ($mapeo as $posicion => $columna) {
                $campo = $columna['system_property'] ?? null;
                $segura = (float) ($columna['confidence'] ?? 0) >= self::CONFIANZA_MINIMA
                    && ($campo === null || (isset($this->propiedades[$campo]) && $propiedades[$campo] === 1));
                $columnas[$posicion] = [
                    'campo' => $campo,
                    'segura' => $segura,
                    'titulo' => mb_substr(trim((string) ($titulos[$posicion] ?? $columna['excel_column'])), 0, 100),
                    // El ejemplo sale de filas que son artículos, nunca de un título de rubro ni del total.
                    'muestra' => $datos_hoja['ejemplos'][$posicion][0] ?? '',
                    'ejemplos' => $datos_hoja['ejemplos'][$posicion] ?? [],
                ];
            }

            foreach ($resultado['columnas_ambiguas'] ?? [] as $ambigua) {
                foreach ($ambigua['columnas'] as $posicion) {
                    if (isset($columnas[$posicion]) && $posicion !== $descripcion_como_nombre) {
                        $columnas[$posicion]['segura'] = false;
                    }
                }
            }

            // En la prueba un importe no se adivina: si el título no dice qué es, se le pregunta al dueño.
            if (!$this->run->modo_soporte) {
                $columnas = self::dudar_de_los_importes($columnas);
                // Una hoja con una sola columna que el analizador no reconoció es una hoja de notas: no se pregunta por ella.
                if (count($columnas) === 1 && (reset($columnas)['campo'] ?? null) === null) {
                    continue;
                }
            }

            $proveedor = $this->run->provider_id;
            $inferido = (int) ($resultado['provider_id'] ?? 0);
            if (!$proveedor && ($resultado['provider_confidence'] ?? '') === 'alto' && isset($this->proveedores[$inferido])) {
                $proveedor = $inferido;
            }

            $hojas[] = [
                'indice' => $indice, 'nombre' => $hoja['nombre'], 'inicio' => $inicio, 'columnas' => $columnas,
                'proveedor' => $this->proveedores[$proveedor] ?? null,
                'bloque_descuentos' => $datos_hoja['descuentos'],
            ];
        }

        /*
         * Una hoja de notas al lado de la lista («pedir cable a la distribuidora»): en la prueba, si el libro
         * tiene una hoja con varias columnas, las que traen una sola no se cargan ni se pregunta por ellas.
         * Un libro que es solo una lista de nombres, de una columna, se sigue leyendo.
         */
        $con_tabla = array_filter($hojas, function ($hoja) { return count($hoja['columnas']) > 1; });
        if (!$this->run->modo_soporte && $con_tabla !== [] && count($con_tabla) < count($hojas)) {
            $hojas = array_values($con_tabla);
        }

        return $hojas;
    }

    private function descripcion_como_nombre(array $titulos, array $mapeo, array $muestras): ?int
    {
        $descripciones = [];
        foreach (array_intersect_key($titulos, $muestras) as $posicion => $titulo) {
            $titulo = self::titulo_comparable($titulo);
            if (preg_match('/^(?:nombre(?: (?:del )?(?:articulo|producto|item))?|articulo|producto|item)$/', $titulo)) {
                return null;
            }
            if (preg_match('/^descripcion(?: (?:del )?(?:articulo|producto|item))?$/', $titulo)) {
                $descripciones[] = $posicion;
            }
        }
        if (count($descripciones) !== 1) {
            return null;
        }
        foreach ($mapeo as $posicion => $columna) {
            if ($posicion !== $descripciones[0] && ($columna['system_property'] ?? null) === 'nombre') {
                return null;
            }
        }

        return $descripciones[0];
    }

    public static function titulo_comparable($titulo): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii((string) $titulo))));
    }

    private function precio_de_proveedor_sin_tipo(array $titulos, array $mapeo, array $muestras): ?int
    {
        if (!in_array('proveedor', array_column($mapeo, 'system_property'), true)) {
            return null;
        }

        $precios = [];
        foreach (array_intersect_key($titulos, $muestras) as $posicion => $titulo) {
            $titulo = self::titulo_comparable($titulo);
            if (preg_match('/^(?:costo(?: .*|$)|precio (?:de )?(?:venta|costo|publico|compra)(?: .*|$))/', $titulo)) {
                return null;
            }
            if ($titulo === 'precio') {
                $precios[] = $posicion;
            }
        }

        // Con proveedores, «Precio» también puede ser el precio de compra de la lista recibida.
        return count($precios) === 1 ? $precios[0] : null;
    }

    private function leer_datos_de_hoja(string $ruta, int $indice, int $inicio): array
    {
        $muestras = [];
        $ejemplos = [];
        $bloques = [];
        $ultima_columna_del_encabezado = -1;
        foreach ($this->filas_crudas($ruta, $indice, max(1, $inicio - 1)) as $numero => $fila) {
            if ($numero < $inicio) {
                foreach ($fila as $posicion => $valor) {
                    if (trim((string) $valor) !== '') {
                        $ultima_columna_del_encabezado = $posicion;
                    }
                }
                continue;
            }

            /*
             * Los ejemplos que ve el dueño en las preguntas: hasta dos valores distintos por columna, y solo
             * de filas que parecen un artículo. Una fila con un único dato (un título de rubro, una nota) o
             * la del total no sirven de ejemplo: «--- TORNILLERIA ---» no es un código.
             */
            $con_dato = self::celdas_con_dato($fila);
            $es_un_total = count($con_dato) === 2 && array_filter($con_dato, function ($valor) { return self::empieza_como_total($valor); }) !== [];
            if (count($con_dato) >= 2 && !$es_un_total) {
                foreach ($con_dato as $posicion => $valor) {
                    $valor = mb_substr($valor, 0, 40);
                    if (count($ejemplos[$posicion] ?? []) < self::EJEMPLOS_POR_COLUMNA && !in_array($valor, $ejemplos[$posicion] ?? [], true)) {
                        $ejemplos[$posicion][] = $valor;
                    }
                }
            }

            // El encabezado del bloque está a la derecha de la tabla, con el importe al lado.
            foreach ($fila as $posicion => $valor) {
                $valor = trim((string) $valor);
                if ($valor === '') {
                    continue;
                }
                if ($ultima_columna_del_encabezado >= 0 && $posicion > $ultima_columna_del_encabezado
                    && !isset($muestras[$posicion]) && !isset($muestras[$posicion + 1])
                    && trim((string) ($fila[$posicion + 1] ?? '')) === ''
                    && in_array(self::titulo_comparable($valor), ['descuentos', 'descuentos por proveedor'], true)) {
                    $bloques[$posicion] = ['fila' => $numero, 'descuentos' => []];
                }
                $muestras[$posicion] = $muestras[$posicion] ?? mb_substr($valor, 0, 55);
            }

            foreach ($bloques as $posicion => &$bloque) {
                if ($bloque['fila'] === $numero) {
                    continue;
                }
                $proveedor = self::texto_comparable($fila[$posicion] ?? null);
                $valor = trim((string) ($fila[$posicion + 1] ?? ''));
                if ($proveedor === null && $valor === '') {
                    continue;
                }
                $descuento = self::porcentajes_del_bloque($valor);
                if ($proveedor === null || $descuento === null
                    || (($bloque['descuentos'][$proveedor] ?? $descuento) !== $descuento)) {
                    unset($bloques[$posicion]);
                    continue;
                }
                $bloque['descuentos'][$proveedor] = $descuento;
            }
            unset($bloque);
        }

        $descuentos = [];
        $columnas_del_bloque = [];
        if (count($bloques) === 1) {
            $posicion = array_key_first($bloques);
            $descuentos = $bloques[$posicion]['descuentos'];
            if ($descuentos !== []) {
                $columnas_del_bloque = [$posicion => true, $posicion + 1 => true];
            }
        }

        return compact('muestras', 'ejemplos', 'descuentos', 'columnas_del_bloque');
    }

    private static function porcentajes_del_bloque(string $valor): ?string
    {
        $partes = preg_split('/\s*(?:\by\b|[+_])\s*/iu', $valor);
        $porcentajes = [];
        foreach ($partes as $parte) {
            if (!preg_match('/^(\d{1,3}(?:[.,]\d+)?)\s*%?$/', $parte, $coincidencia)) {
                return null;
            }
            $porcentaje = (float) str_replace(',', '.', $coincidencia[1]);
            if ($porcentaje <= 0 || $porcentaje > 100) {
                return null;
            }
            $porcentajes[] = $porcentaje;
        }

        return implode('_', $porcentajes);
    }

    public function preguntas_columnas(array $hojas): array
    {
        $preguntas = [];
        foreach ($hojas as $hoja) {
            $opciones = ['ignorar' => 'Ignorar'];
            foreach ($hoja['columnas'] as $columna) {
                if ($columna['segura'] && $columna['campo'] !== null) {
                    $opciones[$columna['campo']] = null;
                }
            }
            $opciones += $this->etiquetas;
            $disponibles = array_filter($opciones, 'is_string');
            $grupo = array_flip(self::grupo_de_precios($hoja));
            foreach ($hoja['columnas'] as $posicion => $columna) {
                // Los precios de un grupo no se preguntan de a uno: van juntos en una sola pregunta, más abajo.
                if ($columna['segura'] || isset($grupo[$posicion])) {
                    continue;
                }
                $clave = self::clave_columna($hoja, (int) $posicion);
                $sugeridas = self::sugeridas_para($columna, $disponibles);
                // Los títulos de los importes de la hoja, para avisar cuando hay más de uno.
                $importes = [];
                foreach ($hoja['columnas'] as $otra) {
                    if (!empty($otra['importe'])) {
                        $importes[] = trim((string) ($otra['titulo'] ?? ''));
                    }
                }
                $preguntas[$clave] = self::pregunta(
                    $clave,
                    self::texto_de_columna($hoja, (int) $posicion, $columna, $sugeridas, $disponibles, count($hojas) > 1, $importes),
                    $disponibles
                );
                // Clave nueva y opcional: las respuestas más probables, por su `valor`, y «ignorar» siempre.
                $preguntas[$clave]['sugeridas'] = array_merge($sugeridas, ['ignorar']);
            }
            if ($grupo !== []) {
                $clave = self::clave_de_precios($hoja);
                $preguntas[$clave] = self::pregunta_de_precios($hoja, array_keys($grupo), count($hojas) > 1);
            }
        }

        return $preguntas;
    }

    /**
     * Las columnas de una hoja que son «un precio» sin que el título diga cuál (misión
     * prueba-e2e-campana-imperio): «Precio Lista» y «Precio Contado». Con dos o más se hace UNA sola
     * pregunta —con cuál se carga el precio de venta— en vez de una por columna: preguntadas de a una, las
     * dos se contestaban «precio de venta» y la carga no salía nunca de «dos columnas al mismo dato».
     *
     * @param  array $hoja
     * @return int[]  Posiciones; vacío si no llegan a dos (ahí cada una se pregunta sola)
     */
    public static function grupo_de_precios(array $hoja): array
    {
        $posiciones = [];
        foreach ($hoja['columnas'] ?? [] as $posicion => $columna) {
            if (!empty($columna['grupo_de_precios']) && empty($columna['segura'])) {
                $posiciones[] = (int) $posicion;
            }
        }

        return count($posiciones) >= 2 ? $posiciones : [];
    }

    public static function clave_de_precios(array $hoja): string
    {
        return 'precios_' . $hoja['indice'];
    }

    /**
     * La pregunta única de una hoja con dos o más precios: una opción por columna (su `valor` es la clave
     * de esa columna). La elegida se carga como precio de venta y las demás quedan afuera.
     *
     * @param  array $hoja
     * @param  int[] $posiciones
     * @param  bool  $varias_hojas
     * @return array  {clave, texto, opciones, sugeridas}
     */
    private static function pregunta_de_precios(array $hoja, array $posiciones, bool $varias_hojas): array
    {
        $opciones = [];
        $nombradas = [];
        foreach ($posiciones as $posicion) {
            $columna = $hoja['columnas'][$posicion];
            $titulo = trim((string) ($columna['titulo'] ?? ''));
            $titulo = $titulo !== '' ? $titulo : 'columna ' . Coordinate::stringFromColumnIndex($posicion + 1);
            $ejemplo = ($columna['ejemplos'] ?? [])[0] ?? ($columna['muestra'] ?? '');
            $opciones[] = ['valor' => self::clave_columna($hoja, $posicion), 'etiqueta' => $titulo];
            $nombradas[] = '«' . $titulo . '»' . ($ejemplo === '' ? '' : ' (por ejemplo ' . self::como_plata($ejemplo) . ')');
        }
        $cuantos = [2 => 'dos', 3 => 'tres', 4 => 'cuatro'][count($posiciones)] ?? (string) count($posiciones);
        $ultima = array_pop($nombradas);

        return [
            'clave' => self::clave_de_precios($hoja),
            'texto' => ($varias_hojas ? 'La hoja «' . $hoja['nombre'] . '» de tu lista' : 'Tu lista') . ' trae ' . $cuantos . ' precios: '
                . implode(', ', $nombradas) . ' y ' . $ultima . '. ¿Con cuál querés que cargue el precio de venta? '
                . (count($posiciones) === 2 ? 'El otro lo dejo afuera.' : 'Los otros los dejo afuera.'),
            'opciones' => $opciones,
            'sugeridas' => array_column($opciones, 'valor'),
        ];
    }

    /**
     * Un importe escrito como plata («9.800,00»); lo que no es un número queda como vino.
     *
     * @param  mixed $valor
     * @return string
     */
    private static function como_plata($valor): string
    {
        $numero = self::importe($valor);

        return $numero === null ? (string) $valor : number_format((float) $numero, 2, ',', '.');
    }

    /**
     * Las respuestas más probables para una columna dudosa, por el `valor` de sus opciones (misión
     * prueba-e2e-campana-imperio, ítem O). La familia —código, importe o cantidad— sale de lo que dudó el
     * analizador (su campo sugerido) y, si ese campo no es de ninguna, del título de la columna. Fuera de las tres
     * familias, la única sugerencia es el campo que propuso el analizador, si propuso alguno.
     *
     * @param  array $columna      Columna analizada: `campo`, `titulo`
     * @param  array $disponibles  Opciones que se pueden elegir: valor => etiqueta
     * @return string[]            Valores sugeridos, sin «ignorar»
     */
    public static function sugeridas_para(array $columna, array $disponibles): array
    {
        $campo = $columna['campo'] ?? null;
        $titulo = self::titulo_comparable($columna['titulo'] ?? '');
        $familia = !empty($columna['importe']) ? 'importe' : null;
        foreach ($familia === null ? self::FAMILIAS : [] as $nombre => $datos) {
            if ($campo !== null && in_array($campo, array_merge($datos['campos'], $datos['tambien']), true)) {
                $familia = $nombre;
                break;
            }
        }
        if ($familia === null) {
            foreach (self::FAMILIAS as $nombre => $datos) {
                if ($titulo !== '' && preg_match($datos['titulo'], $titulo)) {
                    $familia = $nombre;
                    break;
                }
            }
        }

        $candidatas = $familia !== null ? self::FAMILIAS[$familia]['campos'] : ($campo !== null ? [$campo] : []);

        return array_values(array_filter($candidatas, function ($valor) use ($disponibles) {
            return isset($disponibles[$valor]);
        }));
    }

    /**
     * El texto de la pregunta de una columna dudosa, en palabras de comerciante: cómo se llama la columna,
     * uno o dos ejemplos de artículos de verdad, y qué puede ser. No nombra la letra de la columna (salvo
     * que no tenga título) ni la hoja (salvo que el libro tenga más de una con datos).
     *
     * @param  array    $hoja
     * @param  int      $posicion
     * @param  array    $columna
     * @param  string[] $sugeridas    Valores de las respuestas más probables
     * @param  array    $disponibles  valor => etiqueta
     * @param  bool     $varias_hojas
     * @return string
     */
    private static function texto_de_columna(array $hoja, int $posicion, array $columna, array $sugeridas, array $disponibles, bool $varias_hojas, array $importes = []): string
    {
        $titulo = trim((string) ($columna['titulo'] ?? ''));
        // Una corrida analizada antes de este cambio trae solo `muestra`.
        $ejemplos = $columna['ejemplos'] ?? (($columna['muestra'] ?? '') !== '' ? [$columna['muestra']] : []);
        $cierre = ' Si es otra cosa, decime cuál; y si no la querés cargar, decime que no la cargue.';
        // ¿Costo o precio de venta? Es LA pregunta de un importe: se hace directa, con la plata escrita como plata.
        $costo_o_venta = in_array('costo', $sugeridas, true) && in_array('precio', $sugeridas, true);
        // Todo importe se muestra como plata, también el de una columna «Final» que solo puede ser el precio.
        if (!empty($columna['importe']) || array_intersect($sugeridas, ['costo', 'precio']) !== []) {
            $ejemplos = array_map(function ($ejemplo) { return self::como_plata($ejemplo); }, $ejemplos);
        }
        // Un ejemplo con coma adentro («2.054,90») no se puede separar del siguiente con otra coma.
        $separador = array_filter($ejemplos, function ($ejemplo) { return strpos($ejemplo, ',') !== false; }) === [] ? ', ' : ' y ';

        $texto = 'En ' . ($varias_hojas ? 'la hoja «' . $hoja['nombre'] . '» de tu lista' : 'tu lista') . ' hay una columna '
            . ($titulo !== '' ? 'que se llama «' . $titulo . '»' : 'sin título (la ' . Coordinate::stringFromColumnIndex($posicion + 1) . ')')
            . ($ejemplos === [] ? '' : ' (por ejemplo: ' . implode($separador, $ejemplos) . ')')
            . '.';

        if ($costo_o_venta) {
            $texto .= ' ¿Es lo que te cobra el proveedor o es a lo que vendés vos?';
            $otros = array_values(array_filter($importes, function ($otro) use ($titulo) { return $otro !== '' && $otro !== $titulo; }));
            if (count($importes) > 1) {
                $texto .= ' Tu lista trae más de un importe' . ($otros === [] ? '' : ' (también «' . implode('», «', $otros) . '»)')
                    . ': decime cuál es tu precio de venta de siempre.';
            }

            return $texto . $cierre;
        }
        $texto .= ' ¿Qué es?';

        $frases = [];
        foreach ($sugeridas as $valor) {
            $frases[] = self::FRASES[$valor] ?? '«' . $disponibles[$valor] . '»';
        }
        if ($frases !== []) {
            $ultima = array_pop($frases);
            $texto .= ' Puede ser ' . ($frases === [] ? '' : implode(', ', $frases) . ' o ') . $ultima . '.';
        }

        return $texto . $cierre;
    }

    /**
     * Qué dice el TÍTULO de una columna de importe, sin mirar nada más: `costo`, `precio` (de venta) o null
     * si no lo dice («precio», «importe», «valor», «$», «p. lista», «precio unitario», sin título…).
     *
     * Con más de un importe en la hoja, «final» y «precio final» dejan de ser claros: al lado de un costo
     * puede ser un precio calculado que no cierra, y hay que confirmarlo.
     *
     * @param  string $titulo
     * @param  bool   $varios  Si la hoja tiene más de una columna de importe
     * @return string|null
     */
    public static function importe_segun_titulo(string $titulo, bool $varios = false): ?string
    {
        $titulo = self::titulo_comparable($titulo);
        if (preg_match('/^(?:costos?|p costo|precio (?:de )?(?:costo|compra))(?: |$)/', $titulo)) {
            return 'costo';
        }
        if (preg_match('/^(?:precio (?:de |al )?(?:venta|publico)|pvp|p v p|venta|publico)(?: |$)/', $titulo)) {
            return 'precio';
        }
        if (!$varios && preg_match('/^(?:precio )?final(?: |$)/', $titulo)) {
            return 'precio';
        }

        return null;
    }

    /**
     * En la prueba, un importe no se adivina (misión prueba-e2e-campana-imperio, ítem P).
     *
     * El caso real: la lista de un proveedor con una sola columna «PRECIO», que es su costo. El analizador
     * la dio por precio de venta con confianza alta, no se preguntó nada y se cargaron 57 artículos con el
     * costo del proveedor como precio de venta y el costo vacío: el negocio iba a vender al costo.
     *
     * Son importes las columnas que el analizador asignó a costo o a precio de venta y las que dejó sin
     * asignar con título de importe. Cada una queda segura SOLO si su título dice sin vueltas qué es
     * (ver importe_segun_titulo()) y el analizador coincide; si no, deja de ser segura y se pregunta. El
     * mapeo previo (`columnas_previas`) se aplica después y la vuelve a dejar segura: lo que soporte ya
     * confirmó no se vuelve a preguntar.
     *
     * @param  array $columnas  Columnas analizadas de una hoja
     * @return array            Las mismas, con `importe` => true en las de importe
     */
    public static function dudar_de_los_importes(array $columnas): array
    {
        $importes = [];
        foreach ($columnas as $posicion => $columna) {
            $campo = $columna['campo'] ?? null;
            $titulo = self::titulo_comparable($columna['titulo'] ?? '');
            if (in_array($campo, ['costo', 'precio'], true)
                || ($campo === null && $titulo !== '' && preg_match(self::FAMILIAS['importe']['titulo'], $titulo))) {
                $importes[] = $posicion;
            }
        }

        $sin_tipo = [];
        foreach ($importes as $posicion) {
            $columnas[$posicion]['importe'] = true;
            $segun_titulo = self::importe_segun_titulo((string) ($columnas[$posicion]['titulo'] ?? ''), count($importes) > 1);
            if ($segun_titulo === null || $segun_titulo !== ($columnas[$posicion]['campo'] ?? null)) {
                $columnas[$posicion]['segura'] = false;
            }
            if ($segun_titulo === null) {
                $sin_tipo[] = $posicion;
            }
        }
        // Dos o más importes que el título no distingue («Precio Lista», «Precio Contado»): van en una sola pregunta.
        foreach (count($sin_tipo) >= 2 ? $sin_tipo : [] as $posicion) {
            $columnas[$posicion]['grupo_de_precios'] = true;
        }

        return $columnas;
    }

    /**
     * Los campos de la planilla dichos en palabras («código de proveedor, IVA y unidad de medida»), para el
     * resumen de la importación. Un campo propio del negocio (un depósito, una lista) va con su nombre.
     *
     * @param  string[] $campos  Claves del mapeo
     * @return string
     */
    public static function campos_en_palabras(array $campos): string
    {
        // Siempre en el mismo orden (el de ETIQUETAS): la columna JSON donde vive el mapeo no conserva el suyo.
        $orden = array_flip(array_keys(self::ETIQUETAS));
        usort($campos, function ($a, $b) use ($orden) {
            return [$orden[$a] ?? PHP_INT_MAX, (string) $a] <=> [$orden[$b] ?? PHP_INT_MAX, (string) $b];
        });
        $palabras = [];
        foreach ($campos as $campo) {
            $palabras[] = self::ETIQUETAS_DEL_RESUMEN[$campo] ?? self::ETIQUETAS[$campo] ?? trim(str_replace(['$_final_', '%_', '_'], ['precio final de la lista ', 'margen de la lista ', ' '], (string) $campo));
        }
        $palabras = array_values(array_unique($palabras));
        if (count($palabras) < 2) {
            return (string) ($palabras[0] ?? '');
        }
        $ultima = array_pop($palabras);

        return implode(', ', $palabras) . (preg_match('/^h?i/i', $ultima) ? ' e ' : ' y ') . $ultima;
    }

    /**
     * Resuelve un campo del mapeo previo (lo que soporte confirmó con el cliente) contra los campos
     * que este negocio puede importar: canónicos, depósitos y listas de precio.
     *
     * Acepta la clave exacta (`nombre`, `address_1_amount`), un alias del importador
     * (`codigo_proveedor`), la etiqueta que usan las preguntas (`Depósito Principal`, `precio final`)
     * o el nombre de columna del importador, comparados con `titulo_comparable`. Las listas de
     * precio no se comparan contra el nombre de columna del importador (ver abajo). Si el texto
     * coincide con más de un campo distinto, no se adivina.
     *
     * @param  string $campo  Texto recibido en el mapeo previo
     * @return string|null    Clave del campo, `self::CAMPO_IGNORAR` para ignorar la columna,
     *                        o null si no existe o es ambiguo
     */
    public function resolver_campo_previo(string $campo): ?string
    {
        $texto = trim($campo);
        if (self::titulo_comparable($texto) === self::CAMPO_IGNORAR) {
            return self::CAMPO_IGNORAR;
        }
        if (isset($this->propiedades[$texto])) {
            return $texto;
        }
        $alias = ArticleImportColumnsNormalizer::normalize_property_key($texto);
        if ($alias !== null && isset($this->propiedades[$alias])) {
            return $alias;
        }

        // Comparación tolerante: clave, etiqueta de la pregunta o nombre de columna del importador.
        $buscado = self::titulo_comparable($texto);
        if ($buscado === '') {
            return null;
        }
        $coincidencias = [];
        foreach ($this->propiedades as $clave => $propiedad) {
            // En las listas de precio el nombre de columna del importador (`%_mayorista`,
            // `$_final_mayorista`) pierde el símbolo al compararlo: «Mayorista» caería en silencio
            // en el porcentaje. Para las listas valen solo la clave y la etiqueta completa
            // («Lista Mayorista (percentage)»).
            $candidatos = strpos($clave, 'price_type_') === 0
                ? [$clave, $this->nombres[$clave] ?? '']
                : [$clave, $propiedad, $this->nombres[$clave] ?? ''];
            foreach ($candidatos as $candidato) {
                if (self::titulo_comparable($candidato) === $buscado) {
                    $coincidencias[$clave] = true;
                }
            }
        }

        return count($coincidencias) === 1 ? (string) array_key_first($coincidencias) : null;
    }

    /**
     * Nombres de las hojas de un libro recién recibido, antes de que exista la corrida.
     *
     * 🔴 Solo nombres, sin recorrer ni contar filas: corre dentro del pedido del admin, que en modo
     * soporte corta a los 25 s. `ExcelWorkbookReader::listar_hojas` cuenta las filas de cada hoja y
     * con un Excel pesado el admin contestaría «no responde» mientras acá la corrida se crea igual.
     * `listWorksheetNames` de PhpSpreadsheet lee solo el índice del libro (`xl/workbook.xml` en un
     * `.xlsx`, el stream de libro en un `.xls`). Son los mismos nombres que después ve `analizar()`:
     * un `.xls` se convierte en `ruta()` sin cambiarlos.
     *
     * @param  string $ruta       Ruta del archivo (puede ser el temporal de la subida)
     * @param  string $extension  Extensión original del archivo
     * @return string[]           Nombres de las hojas, en orden
     *
     * @throws RuntimeException   Si el archivo no se puede leer
     */
    public static function nombres_de_hojas(string $ruta, string $extension): array
    {
        try {
            $lector = IOFactory::createReader(strtolower($extension) === 'xls' ? 'Xls' : 'Xlsx');

            return array_values($lector->listWorksheetNames($ruta));
        } catch (\Throwable $error) {
            throw new RuntimeException('No se pudieron leer las hojas del archivo.', 0, $error);
        }
    }

    /**
     * Lista los campos posibles para el mensaje de error del mapeo previo, con la etiqueta cuando
     * aporta (depósitos y listas de precio).
     *
     * @return string[]  `ignorar`, y cada clave con su etiqueta entre paréntesis si es distinta
     */
    public function campos_posibles(): array
    {
        $campos = [self::CAMPO_IGNORAR];
        foreach (array_keys($this->propiedades) as $clave) {
            $etiqueta = $this->nombres[$clave] ?? '';
            $campos[] = $etiqueta !== '' && self::titulo_comparable($etiqueta) !== self::titulo_comparable($clave)
                ? $clave . ' (' . $etiqueta . ')' : $clave;
        }

        return $campos;
    }

    /**
     * Aplica a las hojas analizadas el mapeo de columnas que soporte ya confirmó con el cliente.
     *
     * Cada columna cubierta queda segura con el campo resuelto (o sin campo, si se ignora) y marcada
     * `previa`. Otra columna de la misma hoja sin mapeo previo que el análisis había asignado a un
     * campo ya tomado deja de ser segura, para que se pregunte en lugar de chocar en
     * `resolver_columnas` («Dos columnas … al mismo dato»).
     *
     * Reglas: la hoja se busca por nombre exacto y, si ninguna hoja se llama así, con
     * `titulo_comparable`; sin hoja, la previa vale solo si el análisis dejó una única hoja
     * (`iniciar` ya guarda la hoja normalizada al nombre real del libro, así que es un resguardo). Una letra que no está entre las columnas con datos se saltea.
     * Las previas con hoja se aplican antes que las sin hoja; si dos previas caen en la misma
     * columna o en el mismo campo, gana la primera. Un campo que el negocio ya no tiene (un
     * depósito borrado entre el pedido y el análisis) no se aplica y la columna se pregunta.
     *
     * 🔴 `ignorar` nunca queda como `campo = 'ignorar'`: `preguntas_columnas()` anula
     * `$opciones[$campo]` de cada columna segura y se llevaría la opción «Ignorar».
     *
     * @param  array $hojas    Hojas devueltas por `analizar()`
     * @param  array $previas  Lista normalizada de ['hoja' => ?string, 'columna' => string, 'campo' => ?string]
     * @return array           Las mismas hojas, con las columnas cubiertas ya resueltas
     */
    public function aplicar_columnas_previas(array $hojas, array $previas): array
    {
        // Primero las que nombran la hoja: son más específicas. Sin usort: en PHP 7.4 no es estable.
        $previas = array_merge(
            array_filter($previas, function ($previa) { return ($previa['hoja'] ?? null) !== null; }),
            array_filter($previas, function ($previa) { return ($previa['hoja'] ?? null) === null; })
        );
        $una_sola_hoja = count($hojas) === 1;
        // Nombres reales de las hojas: el nombre exacto gana sobre la comparación tolerante.
        $nombres_exactos = array_column($hojas, 'nombre');

        foreach ($hojas as &$hoja) {
            $nombre_hoja = self::titulo_comparable($hoja['nombre']);
            // Posiciones ya cubiertas por una previa en esta hoja.
            $cubiertas = [];
            // Propiedades del importador ya tomadas por una previa en esta hoja.
            $tomadas = [];
            foreach ($previas as $previa) {
                $hoja_previa = $previa['hoja'] ?? null;
                if ($hoja_previa === null) {
                    $corresponde = $una_sola_hoja;
                } elseif ($hoja_previa === $hoja['nombre']) {
                    $corresponde = true;
                } else {
                    $corresponde = !in_array($hoja_previa, $nombres_exactos, true)
                        && self::titulo_comparable($hoja_previa) === $nombre_hoja;
                }
                if (!$corresponde) {
                    continue;
                }
                $letra = strtoupper(trim((string) ($previa['columna'] ?? '')));
                if (!preg_match('/^[A-Z]{1,3}$/', $letra)) {
                    continue;
                }
                $posicion = Coordinate::columnIndexFromString($letra) - 1;
                if (!isset($hoja['columnas'][$posicion]) || isset($cubiertas[$posicion])) {
                    continue;
                }
                $campo = $previa['campo'] ?? null;
                if ($campo === self::CAMPO_IGNORAR) {
                    $campo = null;
                }
                if ($campo !== null && (!isset($this->propiedades[$campo]) || isset($tomadas[$this->propiedades[$campo]]))) {
                    continue;
                }

                $hoja['columnas'][$posicion]['campo'] = $campo;
                $hoja['columnas'][$posicion]['segura'] = true;
                $hoja['columnas'][$posicion]['previa'] = true;
                $cubiertas[$posicion] = true;
                if ($campo !== null) {
                    $tomadas[$this->propiedades[$campo]] = true;
                }
            }

            // El análisis pudo haberle dado a otra columna el mismo dato: esa se pregunta.
            foreach ($hoja['columnas'] as $posicion => &$columna) {
                $campo = $columna['campo'] ?? null;
                if (isset($cubiertas[$posicion]) || $campo === null || !isset($this->propiedades[$campo])) {
                    continue;
                }
                if (isset($tomadas[$this->propiedades[$campo]])) {
                    $columna['segura'] = false;
                }
            }
            unset($columna);
        }
        unset($hoja);

        return $hojas;
    }

    private static function clave_columna(array $hoja, int $posicion): string
    {
        return 'columna_' . $hoja['indice'] . '_' . $posicion;
    }

    public static function pregunta(string $clave, string $texto, array $opciones): array
    {
        $lista = [];
        foreach ($opciones as $valor => $etiqueta) {
            $lista[] = ['valor' => $valor, 'etiqueta' => $etiqueta];
        }

        return ['clave' => $clave, 'texto' => $texto, 'opciones' => $lista];
    }

    public function resolver_columnas(array $hojas, array $decisiones): array
    {
        foreach ($hojas as &$hoja) {
            $hoja['mapeo'] = ArticleImportColumnsNormalizer::normalize($this->mapeo_de_hoja($hoja, $decisiones));
            // Solo en la prueba: el código del dueño no se pierde en `numero` (ver el método).
            if (!$this->run->modo_soporte) {
                $hoja['mapeo'] = $this->codigo_del_dueno_como_sku($hoja);
            }
            $hoja['descuentos_por_proveedor'] = $this->resolver_descuentos($hoja);
        }
        unset($hoja);

        return array_values(array_filter($hojas, function (array $hoja) {
            return array_intersect(['nombre', 'numero', 'codigo_de_barras', 'sku', 'codigo_de_proveedor'], array_keys($hoja['mapeo'])) !== [];
        }));
    }

    /**
     * En la importación de la PRUEBA, la columna de códigos del dueño que quedó en `numero` pasa a `sku`
     * (misión prueba-e2e-campana-imperio, ronda 3, E1).
     *
     * Por qué: para el importador `numero` es el id de un artículo que YA existe; sirve para encontrarlo y
     * actualizarlo, nunca para dar de alta con ese número. En el ensayo del 12/11 la columna «Cod.» (1–32) de
     * una planilla de alta terminó en `numero` (la eligió el analizador, pero lo mismo pasa si la elige el
     * dueño en la pregunta o soporte con `--columnas`): los 32 artículos se crearon con su número correlativo
     * y el código del dueño no quedó en ningún campo. Buscar «15» no encontraba su artículo 15. Peor: si un
     * código coincidía con el id de un artículo que el dueño ya había cargado, lo pisaba.
     *
     * El SKU («código propio») es el campo natural del código del negocio, el importador lo usa también para
     * encontrar el artículo en una segunda carga, y al terminar la importación la prueba lo suma al buscador
     * del Listado y de Vender (`ImportacionDePruebaService::buscar_tambien_por_sku`).
     *
     * Se deja en `numero` solo cuando la planilla es, sin duda, una lista de artículos que el negocio ya tiene
     * por su número (`son_articulos_por_numero`). Si la hoja ya trae una columna de SKU, `numero` no se toca
     * (el código del dueño ya se guarda). Fuera de la prueba (modo soporte) no se llama: ahí `numero` sigue como siempre.
     *
     * @param  array $hoja  Hoja con su `mapeo` ya resuelto
     * @return array        El mapeo, con `numero` pasado a `sku` si corresponde
     */
    private function codigo_del_dueno_como_sku(array $hoja): array
    {
        $mapeo = $hoja['mapeo'];
        if (!isset($mapeo['numero']) || isset($mapeo['sku'])) {
            return $mapeo;
        }

        // Código => nombre de esa fila (null si la hoja no trae nombre o la fila no lo tiene).
        $filas = [];
        $ruta = $this->ruta();
        ExcelWorkbookReader::asegurar_csv($ruta, (int) $hoja['indice']);
        foreach ($this->filas_crudas($ruta, (int) $hoja['indice'], (int) $hoja['inicio']) as $fila) {
            $valor = IdentifierNormalizer::normalize(ImportHelper::getColumnValue($fila, 'numero', $mapeo));
            if ($valor !== null) {
                $nombre = isset($mapeo['nombre']) ? ImportHelper::getColumnValue($fila, 'nombre', $mapeo, true) : null;
                $filas[(string) $valor] = self::texto_comparable($nombre);
            }
        }

        // Una columna vacía tampoco sirve para buscar por número: pasa igual.
        if ($filas === [] || !$this->son_articulos_por_numero($filas)) {
            $mapeo['sku'] = $mapeo['numero'];
            unset($mapeo['numero']);
        }

        return $mapeo;
    }

    /**
     * ¿La hoja es una lista de artículos que el negocio ya tiene, por su número? Lo es solo si:
     *  - ningún código es el SKU de un artículo del dueño (entonces es su código propio: se busca por SKU);
     *  - TODOS los códigos son ids de artículos activos del dueño que no son de ejemplo;
     *  - y en cada fila que trae nombre, el nombre es el de ese artículo. Sin esto, la lista de un proveedor
     *    nuevo con códigos 40–45 pisaría los artículos 40–45 del negocio, que no tienen nada que ver.
     *
     * @param  array $filas  código => nombre comparable (o null)
     * @return bool
     */
    private function son_articulos_por_numero(array $filas): bool
    {
        $owner_id = (int) $this->run->user_id;
        $codigos = array_map('strval', array_keys($filas));

        if (Article::where('user_id', $owner_id)->whereIn('sku', $codigos)->exists()) {
            return false;
        }
        if (count(array_filter($codigos, 'ctype_digit')) !== count($codigos)) {
            return false;
        }

        $nombres = Article::where('user_id', $owner_id)->where('status', 'active')
            ->whereIn('id', array_map('intval', $codigos))
            ->whereNotIn('id', SemillaRegistroHelper::tabla_disponible() ? SemillaRegistroHelper::ids($owner_id, 'articles') : [])
            ->pluck('name', 'id')->all();

        foreach ($filas as $codigo => $nombre) {
            if (!array_key_exists((int) $codigo, $nombres)) {
                return false;
            }
            if ($nombre !== null && self::texto_comparable($nombres[(int) $codigo]) !== $nombre) {
                return false;
            }
        }

        return true;
    }

    /**
     * Propiedad del importador => posición de su columna, con lo seguro del análisis y lo que se contestó.
     * En un grupo de precios, la columna elegida es el precio de venta y las demás quedan afuera.
     *
     * @param  array $hoja
     * @param  array $decisiones
     * @return array
     *
     * @throws InvalidArgumentException  Si dos columnas quedan asignadas al mismo dato
     */
    private function mapeo_de_hoja(array $hoja, array $decisiones): array
    {
        $grupo = array_flip(self::grupo_de_precios($hoja));
        $elegida = $decisiones[self::clave_de_precios($hoja)] ?? null;
        $mapeo = [];
        foreach ($hoja['columnas'] as $posicion => $columna) {
            $clave = self::clave_columna($hoja, (int) $posicion);
            if (isset($grupo[$posicion])) {
                $campo = $elegida === $clave ? 'precio' : null;
            } else {
                $campo = $columna['segura'] ? $columna['campo'] : ($decisiones[$clave] ?? null);
            }
            if (!isset($this->propiedades[$campo])) {
                continue;
            }
            $propiedad = $this->propiedades[$campo];
            if (isset($mapeo[$propiedad])) {
                throw new InvalidArgumentException('Dos columnas de «' . $hoja['nombre'] . '» quedaron asignadas al mismo dato.');
            }
            $mapeo[$propiedad] = (int) $posicion;
        }

        return $mapeo;
    }

    /**
     * ¿Estas respuestas dejan dos columnas de una hoja asignadas al mismo dato? Lo pregunta `prueba-resolver`
     * antes de guardarlas: así se rechazan con un mensaje claro en vez de volver a preguntar lo mismo sin fin.
     *
     * @param  array $hojas
     * @param  array $decisiones
     * @return bool
     */
    public function decisiones_repiten_un_dato(array $hojas, array $decisiones): bool
    {
        try {
            foreach ($hojas as $hoja) {
                $this->mapeo_de_hoja($hoja, $decisiones);
            }
        } catch (InvalidArgumentException $error) {
            return true;
        }

        return false;
    }

    private function resolver_descuentos(array $hoja): array
    {
        $bloque = $hoja['bloque_descuentos'] ?? [];
        if ($bloque === [] || isset($hoja['mapeo']['descuentos']) || isset($hoja['mapeo']['descuentos_montos'])) {
            return [];
        }

        $proveedores = [];
        $aliases = [];
        $ruta = $this->ruta();
        ExcelWorkbookReader::asegurar_csv($ruta, $hoja['indice']);
        foreach ($this->filas_crudas($ruta, $hoja['indice'], $hoja['inicio']) as $fila) {
            $valor = ImportHelper::getColumnValue($fila, 'proveedor', $hoja['mapeo']);
            $proveedor = self::texto_comparable($valor ?: $hoja['proveedor']);
            if ($proveedor === null || isset($proveedores[$proveedor])) {
                continue;
            }
            $proveedores[$proveedor] = true;
            $base = preg_replace('/\s+\([^()]+\)$/u', '', $proveedor);
            $aliases[$base][] = $proveedor;
        }

        $descuentos = array_intersect_key($bloque, $proveedores);
        foreach (array_diff_key($bloque, $proveedores) as $alias => $valores) {
            if (count($aliases[$alias] ?? []) === 1) {
                $descuentos[$aliases[$alias][0]] = $descuentos[$aliases[$alias][0]] ?? $valores;
            }
        }

        return $descuentos;
    }

    private function filas_crudas(string $ruta, int $indice, int $inicio): \Generator
    {
        $csv = fopen(CsvDeHoja::ruta_csv($ruta, $indice), 'rb');
        if ($csv === false) {
            throw new RuntimeException('No se pudo abrir la hoja del Excel.');
        }

        // Los encabezados repetidos adentro de la planilla no son artículos ni códigos repetidos.
        $regla_de_encabezados = EncabezadosRepetidos::leer($ruta, $indice);

        try {
            if (fread($csv, 3) !== CsvDeHoja::BOM_UTF8) {
                rewind($csv);
            }
            $numero = 0;
            while (($fila = fgetcsv($csv, 0, ',', '"', '')) !== false) {
                if (++$numero >= $inicio && !EncabezadosRepetidos::es_encabezado($fila, $numero, $regla_de_encabezados)) {
                    yield $numero => $fila;
                }
            }
        } finally {
            fclose($csv);
        }
    }

    public function filas(array $hojas): \Generator
    {
        $ruta = $this->ruta();
        /*
         * El descarte de títulos y totales corre SOLO en la importación de la prueba. En modo soporte (un
         * negocio que ya opera) queda lo de siempre: se saltean nada más que las filas vacías y los
         * encabezados repetidos.
         */
        $descarta = !$this->run->modo_soporte;
        foreach ($hojas as $hoja) {
            ExcelWorkbookReader::asegurar_csv($ruta, $hoja['indice']);
            // La única fila que puede ser el total: la última con datos, sin contar las notas sueltas del pie.
            $cierre = $descarta ? $this->fila_de_cierre($ruta, $hoja) : null;
            $despues_del_total = false;
            foreach ($this->filas_crudas($ruta, $hoja['indice'], $hoja['inicio']) as $numero => $fila) {
                $datos = [];
                foreach ($hoja['mapeo'] as $campo => $posicion) {
                    $datos[$campo] = ImportHelper::getColumnValue($fila, $campo, $hoja['mapeo'], $campo === 'nombre');
                }
                if (array_filter($datos, function ($valor) { return $valor !== null && $valor !== ''; }) === []) {
                    continue;
                }
                // Un título de rubro o el total al pie no son artículos: no llegan ni a los repetidos ni al motor.
                $motivo = $descarta ? self::motivo_para_saltear($fila, $hoja['mapeo'], $numero === $cierre, $despues_del_total) : null;
                if ($motivo !== null) {
                    $despues_del_total = $despues_del_total || $motivo === self::FILA_TOTAL;
                    $this->anotar_salteada((int) $hoja['indice'], $motivo, $fila, $hoja['mapeo']);
                    continue;
                }
                // La ganancia que contestó el dueño, como si la planilla trajera la columna del margen.
                if (isset($hoja['margen_respondido'])) {
                    $datos[self::CLAVE_MARGEN] = $hoja['margen_respondido'];
                }
                // Una fila con costo y sin ganancia ni precio, en una hoja que sí trae esas columnas.
                if ($descarta && self::fila_sin_ganancia($datos, $hoja['mapeo'])) {
                    if (isset($hoja['margen_para_vacias'])) {
                        $datos[self::CLAVE_MARGEN] = $hoja['margen_para_vacias'];
                        $this->margen_agregado[$hoja['indice']] = ($this->margen_agregado[$hoja['indice']] ?? 0) + 1;
                    } else {
                        $this->sin_ganancia[$hoja['indice']] = ($this->sin_ganancia[$hoja['indice']] ?? 0) + 1;
                    }
                }
                if (empty($datos['proveedor']) && $hoja['proveedor'] !== null) {
                    $datos['proveedor'] = $hoja['proveedor'];
                }
                $proveedor = self::texto_comparable($datos['proveedor'] ?? null);
                if (isset($hoja['descuentos_por_proveedor'][$proveedor])) {
                    $datos[self::DESCUENTOS_LATERALES] = $hoja['descuentos_por_proveedor'][$proveedor];
                }
                yield $hoja['indice'] . ':' . $numero => $datos;
            }
        }
    }

    public function coincidencias(array $hojas, array $decisiones): array
    {
        $barras = [];
        $codigos = [];
        $proveedores_por_codigo = [];
        $descartar = [];
        $separar = [];
        $preguntas = [];
        foreach ($this->filas($hojas) as $clave => $datos) {
            $barra = IdentifierNormalizer::normalize($datos['codigo_de_barras'] ?? null);
            if ($barra !== null) {
                if (isset($barras[$barra])) {
                    $descartar[$barras[$barra]] = true;
                }
                $barras[$barra] = $clave;
            }
            $codigo = IdentifierNormalizer::normalize($datos['codigo_de_proveedor'] ?? null);
            if ($codigo !== null) {
                $proveedor = self::texto_comparable($datos['proveedor'] ?? null);
                $grupo = hash('sha256', json_encode([$proveedor, $codigo]));
                $proveedores_por_codigo[$codigo][$grupo] = true;
                $codigos[$grupo][$clave] = [
                    'codigo' => $codigo, 'proveedor' => $datos['proveedor'] ?? null,
                    'nombre' => self::texto_comparable($datos['nombre'] ?? null),
                    'precio' => self::importe($datos['precio'] ?? null),
                    'costo' => self::importe($datos['costo'] ?? null),
                    'moneda' => self::moneda($datos['moneda'] ?? null),
                ];
            }
        }

        foreach ($codigos as $grupo => $filas) {
            $filas = array_diff_key($filas, $descartar);
            if ($filas === []) {
                continue;
            }

            $primera = reset($filas);
            $decision = count($filas) < 2 ? 'mismo' : ($decisiones[$grupo] ?? self::decision_del_codigo($filas));
            if ($decision === null) {
                $ejemplos = array_values(array_filter(array_map(function ($fila) {
                    $valores = array_filter([$fila['nombre'], $fila['precio'], $fila['costo']], function ($valor) {
                        return $valor !== null;
                    });
                    return mb_substr(implode(' / ', $valores), 0, 70);
                }, array_slice($filas, 0, 2))));
                $preguntas[$grupo] = self::pregunta($grupo,
                    'El código de proveedor «' . mb_substr($primera['codigo'], 0, 80) . '»'
                    . ($primera['proveedor'] ? ' de ' . mb_substr($primera['proveedor'], 0, 70) : '')
                    . ' aparece en ' . count($filas) . ' filas.'
                    . ($ejemplos === [] ? '' : ' Ejemplos: «' . implode('» y «', $ejemplos) . '».')
                    . ' ¿Son artículos distintos o el mismo artículo?',
                    ['distintos' => 'Artículos distintos', 'mismo' => 'El mismo artículo; conservar la última fila']
                );
                continue;
            }
            if ($decision === 'mismo') {
                $descartar += array_fill_keys(array_slice(array_keys($filas), 0, -1), true);
            }
            if ($decision === 'distintos' || count($proveedores_por_codigo[$primera['codigo']]) > 1) {
                $separar += array_fill_keys(array_keys($filas), true);
            }
        }

        return compact('descartar', 'separar', 'preguntas');
    }

    private static function decision_del_codigo(array $filas): ?string
    {
        $comparable = false;
        $moneda_incompleta = false;
        foreach (['nombre', 'precio', 'costo', 'moneda'] as $campo) {
            $valores = array_filter(array_column($filas, $campo), function ($valor) { return $valor !== null; });
            if (count(array_unique($valores)) > 1) {
                return 'distintos';
            }
            if (in_array($campo, ['precio', 'costo'], true) && count($valores) === count($filas)) {
                $comparable = true;
            }
            if ($campo === 'moneda') {
                $moneda_incompleta = $valores !== [] && count($valores) < count($filas);
            }
        }

        return $comparable && !$moneda_incompleta && !in_array(null, array_column($filas, 'nombre'), true) ? 'mismo' : null;
    }

    public static function texto_comparable($valor): ?string
    {
        $texto = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $valor)));

        return $texto === '' ? null : $texto;
    }

    private static function moneda($valor): ?string
    {
        $texto = self::texto_comparable($valor);
        if (in_array($texto, ['usd', 'u$s', 'dólar', 'dolar', 'dólares', 'dolares', 'sí', 'si', '1'], true)) {
            return 'USD';
        }
        if (in_array($texto, ['ars', 'peso', 'pesos', 'no', '0'], true)) {
            return 'ARS';
        }

        return null;
    }

    private static function importe($valor): ?float
    {
        try {
            return ImportHelper::parseNumericValue($valor);
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    public function escribir(array $hojas, array $coincidencias): array
    {
        $campos = ['proveedor' => true, self::SEPARAR_CODIGO => true];
        foreach ($hojas as $hoja) {
            $campos += $hoja['mapeo'];
            if (!empty($hoja['descuentos_por_proveedor'])) {
                $campos[self::DESCUENTOS_LATERALES] = true;
            }
            if (isset($hoja['margen_respondido']) || isset($hoja['margen_para_vacias'])) {
                $campos[self::CLAVE_MARGEN] = true;
            }
        }
        // `filas()` ya corrió para buscar repetidos: la cuenta de las salteadas es la de ESTA pasada.
        $this->salteadas = [];
        $this->sin_ganancia = [];
        $this->margen_agregado = [];
        $columnas = array_keys($campos);
        $ruta = storage_path('app/imported_files/prueba_' . $this->run->uuid . '.preparado');
        $csv = fopen($ruta, 'wb');
        if ($csv === false) {
            throw new RuntimeException('No se pudo guardar la planilla preparada.');
        }

        $filas = 0;
        try {
            self::escribir_fila($csv, $columnas);
            foreach ($this->filas($hojas) as $clave => $datos) {
                if (isset($coincidencias['descartar'][$clave])) {
                    continue;
                }
                $datos[self::SEPARAR_CODIGO] = isset($coincidencias['separar'][$clave]) ? '1' : '';
                $ordenada = array_map(function ($campo) use ($datos) { return $datos[$campo] ?? ''; }, $columnas);
                self::escribir_fila($csv, $ordenada);
                $filas++;
            }
        } finally {
            fclose($csv);
        }

        return [
            'ruta' => $ruta, 'filas' => $filas, 'columnas' => array_flip($columnas), 'salteadas' => $this->salteadas,
            'sin_ganancia' => $this->sin_ganancia, 'margen_agregado' => $this->margen_agregado,
        ];
    }

    /**
     * ¿La hoja trae el costo y además alguna columna que dice a cuánto se vende (margen, precio o una lista)?
     * Es donde puede haber filas sueltas con costo y sin ganancia.
     *
     * @param  array $mapeo
     * @return string[]  Los campos de venta de la hoja; vacío si no trae costo o no trae ninguno
     */
    private static function campos_de_venta(array $mapeo): array
    {
        if (!isset($mapeo['costo'])) {
            return [];
        }

        return array_values(array_filter(array_keys($mapeo), function ($campo) {
            return in_array($campo, [self::CLAVE_MARGEN, 'precio'], true)
                || strpos((string) $campo, '%_') === 0 || strpos((string) $campo, '$_final_') === 0;
        }));
    }

    /**
     * ¿La fila trae el costo y deja vacías TODAS las columnas de venta de su hoja? Quedaría cargada al costo.
     *
     * @param  array $datos  Campo => valor de la fila
     * @param  array $mapeo
     * @return bool
     */
    private static function fila_sin_ganancia(array $datos, array $mapeo): bool
    {
        $de_venta = self::campos_de_venta($mapeo);
        if ($de_venta === [] || trim((string) ($datos['costo'] ?? '')) === '') {
            return false;
        }
        foreach ($de_venta as $campo) {
            if (trim((string) ($datos[$campo] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Cuántas filas traen el costo y ninguna ganancia ni precio, en las hojas que sí tienen esas columnas.
     * Recorre la planilla; si ninguna hoja puede tener el caso, no la abre.
     *
     * @param  array $hojas  Hojas con `mapeo`
     * @return int
     */
    public function contar_sin_ganancia(array $hojas): int
    {
        $candidatas = array_values(array_filter($hojas, function ($hoja) {
            return self::campos_de_venta($hoja['mapeo'] ?? []) !== [];
        }));
        if ($candidatas === [] || $this->run->modo_soporte) {
            return 0;
        }
        foreach ($candidatas as &$hoja) {
            unset($hoja['margen_para_vacias']);
        }
        unset($hoja);

        $this->sin_ganancia = [];
        foreach ($this->filas($candidatas) as $fila) {
            // Solo se cuenta: `filas()` va sumando en $this->sin_ganancia.
        }

        return (int) array_sum($this->sin_ganancia);
    }

    /**
     * Las celdas con dato de una fila cruda, por posición y sin espacios alrededor.
     *
     * @param  array $fila
     * @return array<int, string>
     */
    private static function celdas_con_dato(array $fila): array
    {
        $con_dato = [];
        foreach ($fila as $posicion => $valor) {
            // \s con /u para que un espacio duro cuente como vacío; con UTF-8 inválido se deja el valor crudo.
            $limpio = preg_replace('/^\s+|\s+$/u', '', (string) $valor);
            $limpio = $limpio === null ? trim((string) $valor) : $limpio;
            if ($limpio !== '') {
                $con_dato[(int) $posicion] = $limpio;
            }
        }

        return $con_dato;
    }

    /**
     * ¿El texto empieza como el nombre de una fila de total? («TOTAL LISTA», «Total general», «Subtotal»,
     * «Sub total», «Suma de importes»), sin acentos ni mayúsculas. «Stock total» no empieza así.
     *
     * @param  string $texto
     * @return bool
     */
    private static function empieza_como_total(string $texto): bool
    {
        // Sin números y corto: la palabra de total y, como mucho, dos más («total lista», «sub total sin iva»).
        // «Total Quartz 7000 5W40» y «Suma dental x 12» son artículos.
        return preg_match('/\d/', $texto) !== 1
            && preg_match('/^(?:sub ?total(?:es)?|total(?:es)?|sumas?)(?: [a-z]+){0,2}$/', self::titulo_comparable($texto)) === 1;
    }

    /**
     * ¿El texto es un título decorado? Abre y cierra con el mismo signo repetido («--- X ---», «*** X ***»,
     * «=== X ===», «## X ##») y adentro no trae números: «-- Martillo 500g --» es un artículo.
     *
     * @param  string $texto
     * @return bool
     */
    private static function es_titulo_decorado(string $texto): bool
    {
        return preg_match('/^([-=*#_~•·])\1+\s*(.*?)\s*\1{2,}$/u', $texto, $partes) === 1
            && preg_match('/\pL/u', $partes[2]) === 1 && preg_match('/\d/', $partes[2]) !== 1;
    }

    /**
     * El número de la última fila con datos de la hoja, sin contar las notas sueltas del pie (una fila con
     * un único texto, como «Consulte por descuentos por cantidad.»). Es la única fila que puede ser el total:
     * un «Total» en el medio de la planilla es un artículo.
     *
     * @param  string $ruta
     * @param  array  $hoja
     * @return int|null
     */
    private function fila_de_cierre(string $ruta, array $hoja): ?int
    {
        $cierre = null;
        foreach ($this->filas_crudas($ruta, $hoja['indice'], $hoja['inicio']) as $numero => $fila) {
            $con_dato = self::celdas_con_dato($fila);
            if ($con_dato === []) {
                continue;
            }
            $valor = (string) reset($con_dato);
            $es_nota = count($con_dato) === 1 && self::importe($valor) === null
                && preg_match('/\pL/u', $valor) === 1 && !self::empieza_como_total($valor);
            if (!$es_nota) {
                $cierre = (int) $numero;
            }
        }

        return $cierre;
    }

    /**
     * Cuenta una fila salteada y guarda su texto (los primeros EJEMPLOS_DE_SALTEADAS de cada motivo).
     *
     * @param  int    $indice  Índice de la hoja
     * @param  string $motivo  self::FILA_TITULO o self::FILA_TOTAL
     * @param  array  $fila
     * @param  array  $mapeo
     * @return void
     */
    private function anotar_salteada(int $indice, string $motivo, array $fila, array $mapeo): void
    {
        $this->salteadas[$indice][$motivo] = ($this->salteadas[$indice][$motivo] ?? 0) + 1;

        $con_dato = self::celdas_con_dato($fila);
        $texto = $motivo === self::FILA_TOTAL && isset($mapeo['nombre'], $con_dato[(int) $mapeo['nombre']])
            ? $con_dato[(int) $mapeo['nombre']] : (string) reset($con_dato);
        // Sin el punto final: la frase del resumen ya cierra con el suyo.
        $texto = rtrim($texto, '. ');
        $clave = 'textos_' . $motivo;
        if (count($this->salteadas[$indice][$clave] ?? []) < self::EJEMPLOS_DE_SALTEADAS) {
            $this->salteadas[$indice][$clave][] = mb_substr($texto, 0, 60);
        }
    }

    /**
     * Por qué una fila de la planilla no es un artículo, o null si hay que importarla (misión
     * prueba-e2e-campana-imperio, 11/11/2026).
     *
     * Las listas de proveedor traen títulos de rubro («--- TORNILLERIA ---» en la columna del código y el
     * resto vacío), notas sueltas al pie y una fila de total. Hasta hoy terminaban como artículos: el motor
     * solo descarta la fila que no tiene código NI nombre.
     *
     * 🔴 La regla es conservadora a propósito: descartar de más es perder un artículo sin que nadie lo vea.
     * Ante la duda la fila se importa, como siempre.
     *
     *  - FILA_TOTAL: solo la fila de cierre de la hoja (`$es_el_cierre`: la última con datos, sin contar las
     *    notas sueltas de abajo), sin código, con un nombre que EMPIEZA con total, subtotal o suma, y con un
     *    importe como único otro dato (o ninguno). «Total» o «Suma» en el medio de la planilla, «Stock total»
     *    y un total con dos datos al lado son artículos.
     *  - FILA_TITULO: la fila tiene UN solo valor en todo su ancho, es texto, y además:
     *      · está decorado de apertura y de cierre con el mismo signo y sin números adentro («--- X ---»,
     *        «*** X ***», «=== X ===», «## X ##»); o
     *      · está en una columna de código, la hoja tiene columna de nombre, y no parece un código (un código
     *        no tiene espacios y trae algún dígito): es un rubro suelto, como «--- TORNILLERIA ---».
     *    Un nombre suelto en la columna del NOMBRE («Martillo», «**Martillo carpintero», «-- Martillo 500g --»)
     *    no se descarta: es un artículo sin precio.
     *
     * @param  array $fila          Celdas crudas de la fila, por posición
     * @param  array $mapeo         Campo del importador => posición de su columna
     * @param  bool  $es_el_cierre  Si es la última fila con datos de la hoja (ver fila_de_cierre())
     * @return string|null          self::FILA_TITULO, self::FILA_TOTAL o null
     */
    public static function motivo_para_saltear(array $fila, array $mapeo, bool $es_el_cierre = false, bool $despues_del_total = false): ?string
    {
        $con_dato = self::celdas_con_dato($fila);
        if ($con_dato === [] || count($con_dato) > 2) {
            return null;
        }

        $columna_del_nombre = isset($mapeo['nombre']) ? (int) $mapeo['nombre'] : null;
        $columnas_de_codigo = [];
        foreach (['numero', 'codigo_de_barras', 'sku', 'codigo_de_proveedor'] as $campo) {
            if (isset($mapeo[$campo])) {
                $columnas_de_codigo[(int) $mapeo[$campo]] = true;
            }
        }

        if ($es_el_cierre) {
            /*
             * El rótulo del total puede estar en la columna del nombre o, con el nombre vacío, en la del
             * código («TOTAL LISTA» en la primera columna: así vino la lista del ensayo). Un rótulo no trae
             * números, así que no se confunde con un código.
             */
            $rotulo = null;
            if ($columna_del_nombre !== null && isset($con_dato[$columna_del_nombre])) {
                $rotulo = $columna_del_nombre;
            } else {
                foreach (array_keys($columnas_de_codigo) as $de_codigo) {
                    if (isset($con_dato[$de_codigo])) {
                        $rotulo = $de_codigo;
                        break;
                    }
                }
            }
            if ($rotulo !== null && self::empieza_como_total($con_dato[$rotulo])) {
                $resto = array_diff_key($con_dato, [$rotulo => true]);
                if ($resto === []) {
                    return self::FILA_TOTAL;
                }
                $otra = (int) array_key_first($resto);
                if (!isset($columnas_de_codigo[$otra]) && self::importe($resto[$otra]) !== null) {
                    return self::FILA_TOTAL;
                }
            }
        }

        if (count($con_dato) !== 1) {
            return null;
        }
        $posicion = (int) array_key_first($con_dato);
        $valor = $con_dato[$posicion];
        if (self::importe($valor) !== null || !preg_match('/\pL/u', $valor)) {
            return null;
        }
        // Debajo del total, un texto suelto es una nota al pie, esté en la columna que esté.
        if ($despues_del_total) {
            return self::FILA_TITULO;
        }
        if (self::es_titulo_decorado($valor)) {
            return self::FILA_TITULO;
        }
        $parece_un_codigo = !preg_match('/\s/u', $valor) && preg_match('/\d/', $valor);
        if (isset($columnas_de_codigo[$posicion]) && $columna_del_nombre !== null && !$parece_un_codigo) {
            return self::FILA_TITULO;
        }

        return null;
    }

    /**
     * La frase del resumen sobre las filas que no se importaron por no ser artículos, o '' si no hubo. Dice
     * cuántas fueron y CUÁLES (los primeros textos, con el total al final), para que el dueño pueda avisar
     * si alguna sí era un producto.
     *
     * @param  array $hojas  Hojas guardadas en la corrida, con `filas_salteadas`
     * @return string        Por ejemplo « Dejé afuera 3 filas que no son artículos: “--- TORNILLERIA ---”,
     *                       “--- PINTURERIA ---”, “TOTAL LISTA”. Si alguna era un producto, decímelo y la cargo.»
     */
    public static function texto_de_filas_salteadas(array $hojas): string
    {
        $filas = 0;
        $titulos = [];
        $totales = [];
        foreach ($hojas as $hoja) {
            $salteadas = $hoja['filas_salteadas'] ?? [];
            $filas += (int) ($salteadas[self::FILA_TITULO] ?? 0) + (int) ($salteadas[self::FILA_TOTAL] ?? 0);
            $titulos = array_merge($titulos, array_values($salteadas['textos_' . self::FILA_TITULO] ?? []));
            $totales = array_merge($totales, array_values($salteadas['textos_' . self::FILA_TOTAL] ?? []));
        }
        if ($filas === 0) {
            return '';
        }

        // El total va siempre a la vista, al final; el resto del cupo es para los títulos, en su orden.
        $totales = array_slice($totales, 0, 2);
        $titulos = array_slice($titulos, 0, self::EJEMPLOS_DE_SALTEADAS - count($totales));
        $entre_comillas = function ($texto) {
            return '“' . $texto . '”';
        };
        $partes = array_map($entre_comillas, $titulos);
        if ($partes !== [] && $filas > count($titulos) + count($totales)) {
            $partes[] = '…';
        }
        $partes = array_merge($partes, array_map($entre_comillas, $totales));

        return ' Dejé afuera ' . $filas . ($filas === 1 ? ' fila que no es un artículo' : ' filas que no son artículos')
            . ($partes === [] ? '' : ': ' . implode(', ', $partes)) . '.'
            . ($filas === 1 ? ' Si era un producto, decímelo y la cargo.' : ' Si alguna era un producto, decímelo y la cargo.');
    }

    /**
     * ¿Esta hoja, ya con su mapeo resuelto, trae el costo sin decir a cuánto se vende? Ni margen, ni precio
     * de venta, ni el margen o el precio final de una lista de precios.
     *
     * @param  array $hoja  Hoja con `mapeo`
     * @return bool
     */
    public static function hoja_necesita_margen(array $hoja): bool
    {
        $mapeo = $hoja['mapeo'] ?? [];
        if (!isset($mapeo['costo']) || isset($mapeo[self::CLAVE_MARGEN]) || isset($mapeo['precio'])) {
            return false;
        }
        foreach (array_keys($mapeo) as $campo) {
            if (strpos((string) $campo, '%_') === 0 || strpos((string) $campo, '$_final_') === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * La pregunta del margen, con la forma de todas las preguntas del circuito (`{clave, texto, opciones}`)
     * más `acepta_porcentaje`, que le avisa a quien responde que también vale un número libre. Vacío si
     * ninguna hoja la necesita.
     *
     * @param  array $hojas  Hojas con `mapeo`
     * @return array         [] o [self::CLAVE_MARGEN => pregunta]
     */
    public static function pregunta_del_margen(array $hojas, int $sin_ganancia = 0): array
    {
        $para_todos = array_filter($hojas, [self::class, 'hoja_necesita_margen']) !== [];
        if (!$para_todos && $sin_ganancia < 1) {
            return [];
        }

        // Las opciones se arman a mano: como clave de un array PHP, «20» se volvería el entero 20, y el
        // `valor` de una opción tiene que ser texto, como en todas las preguntas y como llega la respuesta.
        $opciones = [];
        foreach (self::MARGENES_SUGERIDOS as $porcentaje) {
            $opciones[] = ['valor' => (string) $porcentaje, 'etiqueta' => $porcentaje . ' %'];
        }
        $opciones[] = ['valor' => self::MARGEN_DESPUES, 'etiqueta' => 'Lo cargo yo después'];

        $pregunta = [
            'clave' => self::CLAVE_MARGEN,
            // Con columna de ganancia o de precio y filas que la dejan vacía, la pregunta es solo por esas filas.
            'texto' => ($para_todos
                    ? 'Tu lista trae el costo pero no dice a cuánto vendés. ¿Qué ganancia le sumamos a todos para calcular el precio de venta?'
                    : ($sin_ganancia === 1
                        ? 'En tu lista hay 1 artículo que trae el costo pero no la ganancia ni el precio de venta. ¿Qué ganancia le sumamos a ese, que no la trae?'
                        : 'En tu lista hay ' . $sin_ganancia . ' artículos que traen el costo pero no la ganancia ni el precio de venta.'
                            . ' ¿Qué ganancia les sumamos a esos, que no la traen?'))
                . ' Puede ser ' . implode(' %, ', self::MARGENES_SUGERIDOS) . ' % u otro porcentaje que me digas.'
                . ($para_todos ? ' Si preferís cargar los precios vos después, decímelo y dejo solo el costo.'
                    : ' Si preferís cargarlo vos después, decímelo y los dejo solo con el costo.'),
            'opciones' => $opciones,
            'acepta_porcentaje' => true,
        ];

        return [self::CLAVE_MARGEN => $pregunta];
    }

    /**
     * El porcentaje de una respuesta a la pregunta del margen, normalizado («35», «35 %», «35,5» → «35»,
     * «35», «35.5»), o null si no es un porcentaje válido (mayor a 0 y hasta MARGEN_MAXIMO). «despues» y
     * cualquier texto dan null: no se aplica ningún margen.
     *
     * @param  mixed $respuesta
     * @return string|null
     */
    public static function margen_de_respuesta($respuesta): ?string
    {
        if (!is_scalar($respuesta) || !preg_match('/^\s*(\d{1,4})(?:[.,](\d{1,2}))?\s*%?\s*$/', (string) $respuesta, $partes)) {
            return null;
        }
        $porcentaje = (float) ($partes[1] . '.' . ($partes[2] ?? '0'));
        if ($porcentaje <= 0 || $porcentaje > self::MARGEN_MAXIMO) {
            return null;
        }

        return rtrim(rtrim(number_format($porcentaje, 2, '.', ''), '0'), '.');
    }

    /**
     * Deja en las hojas que lo necesitan el margen que contestó el dueño (`margen_respondido`): `filas()` lo
     * escribe en cada fila y `escribir()` suma la columna, así el motor lo trata como una columna asignada a
     * `margen_de_ganancia`. Con «lo cargo yo después» no toca nada.
     *
     * @param  array $hojas       Hojas con `mapeo`
     * @param  array $decisiones  Respuestas guardadas de la corrida
     * @return array              Las mismas hojas
     */
    public static function aplicar_margen(array $hojas, array $decisiones): array
    {
        $margen = self::margen_de_respuesta($decisiones[self::CLAVE_MARGEN] ?? null);
        foreach ($hojas as &$hoja) {
            unset($hoja['margen_respondido'], $hoja['margen_para_vacias']);
            if ($margen !== null && self::hoja_necesita_margen($hoja)) {
                $hoja['margen_respondido'] = $margen;
            } elseif ($margen !== null && self::campos_de_venta($hoja['mapeo'] ?? []) !== []) {
                // La hoja trae ganancia o precio: el margen contestado va solo a las filas que los dejan vacíos.
                $hoja['margen_para_vacias'] = $margen;
            }
        }
        unset($hoja);

        return $hojas;
    }

    private static function escribir_fila($csv, array $fila): void
    {
        if (fputcsv($csv, $fila, ',', '"', '') === false) {
            throw new RuntimeException('No se pudo escribir una fila de la planilla preparada.');
        }
    }
}
