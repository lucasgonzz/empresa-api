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
use App\Models\User;
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
    // Un total parcial en el medio de la hoja («Subtotal plomería | | 39»), en una hoja cuyos artículos traen código.
    public const FILA_SUBTOTAL = 'subtotal';
    // Cuántos textos de filas salteadas se guardan y se muestran, para que el dueño pueda decir «ese sí era un producto».
    // Hasta este número se nombran TODAS; con más, «y K más» con el número (B2, ronda 8; antes 5 y un «…» mudo).
    public const EJEMPLOS_DE_SALTEADAS = 10;

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
    // Cómo se nombra el código del dueño en el resumen; si alguno no lo traía, se cambia por otra (ronda 8, B1).
    public const ETIQUETA_DEL_CODIGO_DEL_DUENO = 'tu código de cada artículo';

    private const ETIQUETAS_DEL_RESUMEN = [
        'sku' => self::ETIQUETA_DEL_CODIGO_DEL_DUENO,
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
            'titulo' => '/(?:^| )(?:precio|precios|pcio|costo|costos|cto|importe|importes|valor|pvp|unitario|contado|final|vta)(?: |$)/',
        ],
        'cantidad' => [
            'campos' => ['stock_actual', 'u_individuales'],
            'tambien' => ['stock_minimo'],
            'titulo' => '/(?:^| )(?:stock|cant|cantidad|cantidades|unid|unidades|bulto|bultos|caja|cajas|pack|existencia|existencias|hay|disponible)(?: |$)/',
        ],
        // «% UTIL», «Utilidad», «Ganancia %», «Margen», «Recargo» (E8.2): se sugiere el margen. Va última: «Precio con
        // recargo» es un importe.
        'margen' => [
            'campos' => ['margen_de_ganancia'],
            'tambien' => [],
            'titulo' => '/(?:^| )(?:util|utilidad|utilidades|ganancia|ganancias|margen|margenes|recargo|recargos|markup|rentabilidad)(?: |$)/',
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
    // Filas a las que `filas()` les puso el precio que dijo el dueño (`precio:N`, ronda 8, D2): [índice => cantidad].
    private $precio_agregado = [];
    // La primera fila con costo y sin ganancia de cada hoja: [índice => ['nombre' => ..., 'sku' => ...]].
    private $sin_ganancia_ejemplo = [];
    // La primera fila a la que `filas()` le puso el margen o el precio del dueño, por hoja (B3, ronda 8).
    private $margen_agregado_ejemplo = [];
    private $precio_agregado_ejemplo = [];
    // Filas sin el código del dueño en una hoja que lo trae como SKU (B1): [índice => ['cantidad' => n, 'nombres' => [...]]].
    private $sin_codigo = [];
    // Propiedad del importador de la columna de stock de cada sucursal => [id, nombre] (ver `stock_junto_a_la_sucursal`).
    private $stock_por_sucursal = [];
    // Solo en la prueba: la sucursal donde va el stock «junto» (una sola cantidad), o null. Ver `sucursal_del_stock_junto`.
    private $sucursal_del_stock_junto = null;

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
            $this->stock_por_sucursal[self::propiedad_normalizada($clave)] = ['id' => (int) $deposito->id, 'nombre' => (string) $deposito->street];
        }
        if (!$run->modo_soporte) {
            $this->sucursal_del_stock_junto = $this->sucursal_del_stock_junto();
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
                // Las columnas que el sistema no tiene dónde guardar no se preguntan (E8.6).
                $columnas = $this->sin_destino($columnas, $ruta, $indice, $inicio);
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

    /**
     * Las columnas que no son un dato del artículo en el sistema (misión prueba-e2e-campana-imperio, E8.6, solo en la
     * prueba): «Obs.», «Observaciones», «Notas», «Comentarios», «Ubic.», «Ubicación», «Estante», «Valorizado»,
     * «Importe total», «Subtotal», y «Nº», «#», «Ítem» cuando traen un contador (enteros consecutivos) y OTRA columna
     * ya es el código (E8.7: sin otra, el contador puede ser el código del dueño y se pregunta). Antes se le
     * preguntaba al dueño qué eran y la única sugerencia era «ignorar». El importador de artículos no tiene destino
     * para ninguna (ni observaciones ni ubicación: ver AiExcelAnalyzer::SYSTEM_PROPERTIES), así que se ignoran sin
     * preguntar (`sin_destino`) y el resumen lo dice (texto_de_columnas_sin_destino()). Solo las que el analizador
     * dejó sin asignar: si les dio un campo, se respeta.
     *
     * @param  array  $columnas
     * @param  string $ruta
     * @param  int    $indice
     * @param  int    $inicio
     * @return array
     */
    private function sin_destino(array $columnas, string $ruta, int $indice, int $inicio): array
    {
        // ¿Otra columna ya es el código? (código propio, de barras, de proveedor o número)
        $hay_otro_codigo = array_filter($columnas, function ($columna) {
            return in_array($columna['campo'] ?? null, ['numero', 'sku', 'codigo_de_barras', 'codigo_de_proveedor'], true);
        }) !== [];
        foreach ($columnas as $posicion => $columna) {
            if (($columna['campo'] ?? null) !== null) {
                continue;
            }
            $crudo = trim((string) ($columna['titulo'] ?? ''));
            $titulo = self::titulo_comparable($crudo);
            $sin_destino = preg_match('/^(?:obs|observacion|observaciones|nota|notas|comentario|comentarios|ubic|ubicacion|ubicaciones'
                . '|estante|estantes|valorizado|valorizacion|importe total|total importe|subtotal|subtotales)$/', $titulo) === 1;
            if (!$sin_destino && ($crudo === '#' || preg_match('/^(?:n|no|nro|num|numero|item|items|n item)$/', $titulo) === 1)
                && $this->es_un_contador($ruta, $indice, $inicio, (int) $posicion)) {
                /*
                 * E8.7: numerar 1..N es el código propio más común de un negocio chico. El contador se ignora solo si
                 * otra columna ya es el código; si no, se pregunta como cualquier columna dudosa, sugiriendo su código.
                 */
                if ($hay_otro_codigo) {
                    $sin_destino = true;
                } else {
                    $columnas[$posicion]['contador'] = true;
                }
            }
            if ($sin_destino) {
                $columnas[$posicion]['sin_destino'] = true;
                $columnas[$posicion]['segura'] = true;
            }
        }

        return $columnas;
    }

    /**
     * ¿La columna es un contador? Todos sus valores son enteros consecutivos (1, 2, 3…; puede arrancar en cualquiera)
     * y son al menos tres. Las filas sin valor (un rubro, el total) no cortan la cuenta.
     */
    private function es_un_contador(string $ruta, int $indice, int $inicio, int $posicion): bool
    {
        $anterior = null;
        $cuantos = 0;
        foreach ($this->filas_crudas($ruta, $indice, $inicio) as $fila) {
            $valor = trim((string) ($fila[$posicion] ?? ''));
            if ($valor === '') {
                continue;
            }
            if (!ctype_digit($valor) || ($anterior !== null && (int) $valor !== $anterior + 1)) {
                return false;
            }
            $anterior = (int) $valor;
            $cuantos++;
        }

        return $cuantos >= 3;
    }

    /**
     * La frase del resumen sobre las columnas que no se cargaron porque el sistema no tiene dónde guardarlas (ver
     * sin_destino()), o '' si no hubo.
     *
     * @param  array $hojas
     * @return string
     */
    public static function texto_de_columnas_sin_destino(array $hojas): string
    {
        /*
         * E8.7: «Obs.», «Notas», «Comentarios» sí tendrían dónde ir (la descripción), así que para esas no se dice que
         * el sistema no tiene dónde guardarlas. Tampoco se le ofrece cargarlas como descripción: con la carga ya
         * terminada no queda una pregunta abierta por donde contestarlo.
         */
        $de_notas = [];
        $sin_lugar = [];
        foreach ($hojas as $hoja) {
            foreach ((array) ($hoja['columnas'] ?? []) as $columna) {
                if (!empty($columna['sin_destino'])) {
                    $titulo = '«' . mb_substr(trim((string) ($columna['titulo'] ?? '')), 0, 40) . '»';
                    $es_nota = preg_match('/^(?:obs|observacion|observaciones|nota|notas|comentario|comentarios)$/',
                        self::titulo_comparable($columna['titulo'] ?? '')) === 1;
                    if ($es_nota) {
                        $de_notas[] = $titulo;
                    } else {
                        $sin_lugar[] = $titulo;
                    }
                }
            }
        }
        $lista = function (array $titulos) {
            $titulos = array_values(array_unique($titulos));
            $ultima = array_pop($titulos);

            return ($titulos === [] ? '' : implode(', ', $titulos) . ' y ') . $ultima;
        };
        $texto = '';
        if ($de_notas !== []) {
            $texto .= (count(array_unique($de_notas)) === 1 ? ' No cargué la columna ' : ' No cargué las columnas ') . $lista($de_notas) . '.';
        }
        if ($sin_lugar !== []) {
            $texto .= count(array_unique($sin_lugar)) === 1
                ? ' No cargué la columna ' . $lista($sin_lugar) . ': el sistema no tiene dónde guardarla. Si era otra cosa, decímelo.'
                : ' No cargué las columnas ' . $lista($sin_lugar) . ': el sistema no tiene dónde guardarlas. Si alguna era otra cosa, decímelo.';
        }

        return $texto;
    }

    private function descripcion_como_nombre(array $titulos, array $mapeo, array $muestras): ?int
    {
        /*
         * Solo en la prueba (misión prueba-e2e-campana-imperio, ronda 6, E8.2): una sola columna con título de «texto
         * del artículo» (detalle, descripción, artículo, producto, ítem, denominación, concepto, mercadería, nombre; con
         * o sin acentos, punto final o plural, y «… del artículo»), y ninguna OTRA columna mapeada a nombre: esa es el
         * nombre y no se le pregunta al dueño. AiExcelAnalyzer le baja la confianza a «DETALLE» (la toma por etiqueta
         * de descripción) y el dueño recibía «¿Qué es la columna DETALLE?». No se rescata si la IA la mapeó a otro dato
         * (un código, un precio): ahí el título miente. Con dos candidatas («Artículo» y «Descripción») sigue lo de antes.
         */
        if (!$this->run->modo_soporte) {
            $candidatas = [];
            foreach (array_intersect_key($titulos, $muestras) as $posicion => $titulo) {
                if (self::es_titulo_de_texto_del_articulo((string) $titulo)) {
                    $candidatas[] = $posicion;
                }
            }
            if (count($candidatas) === 1 && in_array($mapeo[$candidatas[0]]['system_property'] ?? null, [null, '', 'nombre', 'descripcion'], true)) {
                $otra_es_el_nombre = false;
                foreach ($mapeo as $posicion => $columna) {
                    $otra_es_el_nombre = $otra_es_el_nombre || ($posicion !== $candidatas[0] && ($columna['system_property'] ?? null) === 'nombre');
                }
                if (!$otra_es_el_nombre) {
                    return $candidatas[0];
                }
            }
        }

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

    /**
     * ¿El título de la columna dice que trae el texto del artículo? «DETALLE», «Descripción», «Artículos», «Ítem.»,
     * «Denominación», «Concepto», «Mercadería», «Nombre del producto» (ver descripcion_como_nombre()).
     *
     * @param  string $titulo
     * @return bool
     */
    private static function es_titulo_de_texto_del_articulo(string $titulo): bool
    {
        return preg_match('/^(?:nombres?|detalles?|descripcion(?:es)?|articulos?|productos?|items?|denominacion(?:es)?|conceptos?|mercaderias?)'
            . '(?: (?:de |del )?(?:articulo|producto|item)s?)?$/', self::titulo_comparable($titulo)) === 1;
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
            $es_un_total = count($con_dato) === 2 && array_filter($con_dato, function ($valor) { return self::es_rotulo_de_total($valor); }) !== [];
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
        // Un contador 1..N sin otra columna de código: puede ser el código del dueño (E8.7).
        if (!empty($columna['contador'])) {
            return isset($disponibles['sku']) ? ['sku'] : [];
        }
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
    public static function importe_segun_titulo(string $titulo, bool $varios = false, bool $abreviaturas = true): ?string
    {
        $titulo = self::titulo_comparable($titulo);
        if (preg_match('/^(?:costos?|p costo|precio (?:de )?(?:costo|compra))(?: |$)/', $titulo)) {
            return 'costo';
        }
        if (preg_match('/^(?:precio (?:de |al )?(?:venta|publico)|pvp|p v p|venta|publico)(?: |$)/', $titulo)) {
            return 'precio';
        }
        /*
         * Las abreviaturas (E8.6, solo en la prueba): «P. Venta», «P.Vta», «Pr. Venta», «Pcio. venta», «P. Público»,
         * «Vta.»; «Pr. Costo», «Pcio. costo», «P. Cto», «Cto.». «$ Venta» y «$ Costo» ya son «venta» y «costo» (el
         * signo no cuenta). «P. Lista» NO: la lista de un proveedor suele ser su costo, se sigue preguntando.
         */
        if ($abreviaturas) {
            if (preg_match('/^(?:cto|(?:p|pr|pcio) (?:de )?(?:costo|cto))(?: |$)/', $titulo)) {
                return 'costo';
            }
            if (preg_match('/^(?:vta|(?:p|pr|pcio|precio) (?:de |al )?(?:venta|vta|publico))(?: |$)/', $titulo)) {
                return 'precio';
            }
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
            if (!empty($columna['sin_destino'])) {
                continue;
            }
            $campo = $columna['campo'] ?? null;
            $titulo = self::titulo_comparable($columna['titulo'] ?? '');
            if (in_array($campo, ['costo', 'precio'], true)
                || ($campo === null && $titulo !== '' && preg_match(self::FAMILIAS['importe']['titulo'], $titulo))) {
                $importes[] = $posicion;
            }
        }

        /*
         * Las abreviaturas valen con UNA sola candidata a venta: con dos («P. Venta» y «P. Contado») se pregunta como
         * siempre, en una sola pregunta (E8.6).
         */
        $candidatas_a_venta = 0;
        foreach ($importes as $posicion) {
            $candidatas_a_venta += self::importe_segun_titulo((string) ($columnas[$posicion]['titulo'] ?? ''), count($importes) > 1) !== 'costo' ? 1 : 0;
        }
        $abreviaturas = $candidatas_a_venta <= 1;

        $sin_tipo = [];
        foreach ($importes as $posicion) {
            $columnas[$posicion]['importe'] = true;
            $segun_titulo = self::importe_segun_titulo((string) ($columnas[$posicion]['titulo'] ?? ''), count($importes) > 1, $abreviaturas);
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
    public static function campos_en_palabras(array $campos, array $etiquetas = []): string
    {
        // Siempre en el mismo orden (el de ETIQUETAS): la columna JSON donde vive el mapeo no conserva el suyo.
        $orden = array_flip(array_keys(self::ETIQUETAS));
        usort($campos, function ($a, $b) use ($orden) {
            return [$orden[$a] ?? PHP_INT_MAX, (string) $a] <=> [$orden[$b] ?? PHP_INT_MAX, (string) $b];
        });
        $palabras = [];
        foreach ($campos as $campo) {
            $palabras[] = $etiquetas[$campo] ?? self::ETIQUETAS_DEL_RESUMEN[$campo] ?? self::ETIQUETAS[$campo] ?? trim(str_replace(['$_final_', '%_', '_'], ['precio final de la lista ', 'margen de la lista ', ' '], (string) $campo));
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
                $hoja['mapeo'] = $this->stock_junto_a_la_sucursal($hoja['mapeo']);
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
     * En la importación de la PRUEBA, el stock «junto» (una sola columna de cantidad) va a la sucursal del
     * dueño cuando el negocio tiene sucursales (misión prueba-e2e-campana-imperio, ronda 4, E5).
     *
     * Por qué: con sucursales el stock vive en `address_article` (una fila por sucursal) y `articles.stock` es
     * su suma. Una columna «Cant» mapeada a `stock_actual` hace que `ProcessRow::obtener_stock` arme un
     * `stock_global` (no vino ninguna columna de sucursal) y el artículo queda con `articles.stock` y SIN filas
     * por sucursal: el «estado mixto» que `MigrarASucursalPrincipal` existe para eliminar. En la ronda 4 (14/11)
     * el Listado mostraba las columnas de las sucursales vacías y el stock solo en «Stock». Pasando la columna a
     * la de la sucursal (la misma propiedad que usa un mapeo `address_{id}_amount`), el motor la carga por el
     * camino de siempre de las columnas de sucursal.
     *
     * No cambia nada si la hoja ya trae alguna columna de sucursal (stock, mínimo o máximo: el dueño o soporte
     * ya dijeron dónde va), si no trae `stock_actual`, o si el negocio no tiene sucursales. Fuera de la prueba
     * (modo soporte) no se llama.
     *
     * @param  array $mapeo  Propiedad del importador => posición
     * @return array         El mapeo, con `stock_actual` pasado a la columna de la sucursal si corresponde
     */
    private function stock_junto_a_la_sucursal(array $mapeo): array
    {
        if (is_null($this->sucursal_del_stock_junto) || !isset($mapeo['stock_actual']) || $this->trae_columnas_de_sucursal($mapeo)) {
            return $mapeo;
        }

        $propiedad = self::propiedad_normalizada(str_replace(' ', '_', strtolower($this->sucursal_del_stock_junto->street)));

        // Se renombra en su lugar: el orden del mapeo es el de las columnas de la planilla preparada.
        $renombrado = [];
        foreach ($mapeo as $clave => $posicion) {
            $renombrado[$clave === 'stock_actual' ? $propiedad : $clave] = $posicion;
        }

        return $renombrado;
    }

    /**
     * ¿El mapeo trae alguna columna de sucursal (su stock, su mínimo o su máximo)?
     *
     * @param  array $mapeo
     * @return bool
     */
    private function trae_columnas_de_sucursal(array $mapeo): bool
    {
        foreach (array_keys($this->stock_por_sucursal) as $propiedad) {
            if (isset($mapeo[$propiedad]) || isset($mapeo['min_' . $propiedad]) || isset($mapeo['max_' . $propiedad])) {
                return true;
            }
        }

        return false;
    }

    /**
     * La sucursal donde va el stock «junto» de la prueba: la del dueño (`users.address_id`, la que fija
     * `PrimeraVentaDeLaPruebaHelper` y con la que vende, así una venta descuenta de esa misma fila) si sigue
     * existiendo; si no, la principal (`Address::principal_de`). Null si el negocio no tiene sucursales.
     *
     * @return \App\Models\Address|null
     */
    private function sucursal_del_stock_junto(): ?Address
    {
        $owner_id = (int) $this->run->user_id;
        $address_id = User::whereKey($owner_id)->value('address_id');
        if ($address_id) {
            $del_dueno = Address::sucursales_de($owner_id)->where('id', (int) $address_id)->first();
            if (!is_null($del_dueno)) {
                return $del_dueno;
            }
        }

        return Address::principal_de($owner_id);
    }

    /**
     * La propiedad del importador como queda después de `ArticleImportColumnsNormalizer::normalize`.
     *
     * @param  string $propiedad
     * @return string
     */
    private static function propiedad_normalizada(string $propiedad): string
    {
        return ArticleImportColumnsNormalizer::normalize_property_key($propiedad) ?? $propiedad;
    }

    /**
     * Cómo se nombra en el resumen final la columna de stock de cada sucursal: «stock en Santa Fe»
     * (ronda 4, E5). Sin esto el resumen decía el nombre de la columna del importador («santa fe»).
     *
     * @return array  Propiedad del importador => palabras
     */
    public function etiquetas_del_resumen(): array
    {
        $etiquetas = [];
        foreach ($this->stock_por_sucursal as $propiedad => $sucursal) {
            $etiquetas[$propiedad] = 'stock en ' . $sucursal['nombre'];
        }

        return $etiquetas;
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

    /**
     * Como filas_crudas(), pero también devuelve las filas que EncabezadosRepetidos descarta, marcadas: número de
     * fila => [celdas, es_encabezado_repetido]. En la prueba esas filas se cuentan entre las salteadas (E8.6:
     * ninguna fila que queda afuera desaparece en silencio).
     */
    private function filas_crudas_marcadas(string $ruta, int $indice, int $inicio): \Generator
    {
        $csv = fopen(CsvDeHoja::ruta_csv($ruta, $indice), 'rb');
        if ($csv === false) {
            throw new RuntimeException('No se pudo abrir la hoja del Excel.');
        }
        $regla_de_encabezados = EncabezadosRepetidos::leer($ruta, $indice);
        try {
            if (fread($csv, 3) !== CsvDeHoja::BOM_UTF8) {
                rewind($csv);
            }
            $numero = 0;
            while (($fila = fgetcsv($csv, 0, ',', '"', '')) !== false) {
                if (++$numero >= $inicio) {
                    yield $numero => [$fila, EncabezadosRepetidos::es_encabezado($fila, $numero, $regla_de_encabezados)];
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
            // Si los artículos traen código, dónde empieza el pie: una pasada sobre la hoja entera (ver resumir_la_hoja()).
            $resumen = $descarta ? self::resumir_la_hoja($this->filas_crudas($ruta, $hoja['indice'], $hoja['inicio']), $hoja['mapeo']) : [];
            // Los títulos de la cabecera, por posición: una fila que los repite no es un artículo.
            $titulos = $descarta ? self::titulos_de_la_cabecera($hoja) : [];
            $recorrido = $descarta ? $this->filas_crudas_marcadas($ruta, $hoja['indice'], $hoja['inicio'])
                : $this->filas_crudas($ruta, $hoja['indice'], $hoja['inicio']);
            foreach ($recorrido as $numero => $fila) {
                if ($descarta) {
                    [$fila, $es_encabezado] = $fila;
                    // La cabecera repetida que reconoce EncabezadosRepetidos también se cuenta (E8.6), sin nombrarla:
                    // «“COD”» no le dice nada al dueño.
                    if ($es_encabezado) {
                        $this->anotar_salteada((int) $hoja['indice'], self::FILA_TITULO, $fila, $hoja['mapeo'], false);
                        continue;
                    }
                }
                // Toda fila que queda afuera se nombra (E8.6); en soporte, la fila sin datos mapeados se saltea como siempre.
                $motivo = $descarta ? self::motivo_de_la_fila($fila, $hoja['mapeo'], $resumen, (int) $numero, $titulos) : null;
                if ($motivo === null && self::sin_datos_mapeados($fila, $hoja['mapeo'])) {
                    continue;
                }
                $datos = [];
                foreach ($hoja['mapeo'] as $campo => $posicion) {
                    $datos[$campo] = ImportHelper::getColumnValue($fila, $campo, $hoja['mapeo'], $campo === 'nombre');
                }
                // Un título de rubro, un subtotal o el pie no son artículos: no llegan ni a los repetidos ni al motor.
                if ($motivo !== null) {
                    $this->anotar_salteada((int) $hoja['indice'], $motivo, $fila, $hoja['mapeo']);
                    continue;
                }
                // La ganancia que contestó el dueño, como si la planilla trajera la columna del margen.
                if (isset($hoja['margen_respondido'])) {
                    $datos[self::CLAVE_MARGEN] = $hoja['margen_respondido'];
                }
                // Una fila con costo y sin ganancia ni precio, en una hoja que sí trae esas columnas.
                if ($descarta && self::fila_sin_ganancia($datos, $hoja['mapeo'])) {
                    if (isset($hoja['precio_para_vacia'])) {
                        // El precio de venta que dijo el dueño para el único artículo que no lo traía (ronda 8, D2).
                        $datos['precio'] = $hoja['precio_para_vacia'];
                        $this->precio_agregado[$hoja['indice']] = ($this->precio_agregado[$hoja['indice']] ?? 0) + 1;
                        if (!isset($this->precio_agregado_ejemplo[$hoja['indice']])) {
                            $this->precio_agregado_ejemplo[$hoja['indice']] = self::ejemplo_de_la_fila($datos);
                        }
                    } elseif (isset($hoja['margen_para_vacias'])) {
                        $datos[self::CLAVE_MARGEN] = $hoja['margen_para_vacias'];
                        $this->margen_agregado[$hoja['indice']] = ($this->margen_agregado[$hoja['indice']] ?? 0) + 1;
                        if (!isset($this->margen_agregado_ejemplo[$hoja['indice']])) {
                            $this->margen_agregado_ejemplo[$hoja['indice']] = self::ejemplo_de_la_fila($datos);
                        }
                    } else {
                        $this->sin_ganancia[$hoja['indice']] = ($this->sin_ganancia[$hoja['indice']] ?? 0) + 1;
                        if (!isset($this->sin_ganancia_ejemplo[$hoja['indice']])) {
                            $this->sin_ganancia_ejemplo[$hoja['indice']] = self::ejemplo_de_la_fila($datos);
                        }
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
            if (isset($hoja['precio_para_vacia'])) {
                $campos['precio'] = true;
            }
        }
        // `filas()` ya corrió para buscar repetidos: la cuenta de las salteadas es la de ESTA pasada.
        $this->salteadas = [];
        $this->sin_ganancia = [];
        $this->margen_agregado = [];
        $this->precio_agregado = [];
        $this->sin_ganancia_ejemplo = [];
        $this->margen_agregado_ejemplo = [];
        $this->precio_agregado_ejemplo = [];
        $this->sin_codigo = [];
        // Las hojas que traen el código del dueño como SKU (B1): ahí una fila sin código se busca por su nombre.
        $con_sku = [];
        foreach ($hojas as $hoja) {
            $con_sku[(string) $hoja['indice']] = isset($hoja['mapeo']['sku']) && !$this->run->modo_soporte;
        }
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
                $indice = (string) strstr((string) $clave, ':', true);
                if (!empty($con_sku[$indice]) && trim((string) ($datos['sku'] ?? '')) === '') {
                    $sin = $this->sin_codigo[$indice] ?? ['cantidad' => 0, 'nombres' => []];
                    $sin['cantidad']++;
                    $nombre = trim((string) ($datos['nombre'] ?? ''));
                    if ($nombre !== '' && count($sin['nombres']) < self::NOMBRES_SIN_CODIGO) {
                        $sin['nombres'][] = mb_substr($nombre, 0, 60);
                    }
                    $this->sin_codigo[$indice] = $sin;
                }
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
            'precio_agregado' => $this->precio_agregado, 'sin_ganancia_ejemplo' => $this->sin_ganancia_ejemplo,
            'margen_agregado_ejemplo' => $this->margen_agregado_ejemplo, 'precio_agregado_ejemplo' => $this->precio_agregado_ejemplo,
            'sin_codigo' => $this->sin_codigo,
        ];
    }

    /** Cuántos nombres de artículos sin código se guardan para el resumen (B1). */
    public const NOMBRES_SIN_CODIGO = 3;

    /**
     * El nombre y el SKU de una fila, para buscar después el artículo que cargó (B3).
     *
     * @param  array $datos
     * @return array ['nombre' => ?string, 'sku' => ?string]
     */
    private static function ejemplo_de_la_fila(array $datos): array
    {
        return [
            'nombre' => isset($datos['nombre']) ? (string) $datos['nombre'] : null,
            'sku' => isset($datos['sku']) && trim((string) $datos['sku']) !== '' ? (string) $datos['sku'] : null,
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
            unset($hoja['margen_para_vacias'], $hoja['precio_para_vacia']);
        }
        unset($hoja);

        $this->sin_ganancia = [];
        $this->sin_ganancia_ejemplo = [];
        foreach ($this->filas($candidatas) as $fila) {
            // Solo se cuenta: `filas()` va sumando en $this->sin_ganancia.
        }

        return (int) array_sum($this->sin_ganancia);
    }

    /**
     * El nombre del primer artículo con costo y sin ganancia que vio la última pasada de `filas()` (la de
     * contar_sin_ganancia(), por ejemplo), o null.
     *
     * @return string|null
     */
    public function nombre_del_primero_sin_ganancia(): ?string
    {
        foreach ($this->sin_ganancia_ejemplo as $ejemplo) {
            $nombre = trim((string) ($ejemplo['nombre'] ?? ''));
            if ($nombre !== '') {
                return $nombre;
            }
        }

        return null;
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
     * La palabra tiene que estar COMPLETA al principio: «Totales:», «SUBTOTAL - Plomería» y «Sub-total» sí;
     * «Totalgaz garrafa» o «Sumatoria» no (después de la palabra viene un espacio o nada, los signos cuentan
     * como espacio). Lo usa también ClientImport para la fila de total de una lista de clientes (E8.1).
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
     * ¿El rótulo es el pie que CUENTA las filas (misión prueba-e2e-campana-imperio, ronda 6, E8.2)? «Total
     * artículos: 21», «Total: 21 artículos», «Total de la hoja 1», «TOTAL 21», «Cantidad de artículos: 21»,
     * «Son 21 artículos». Empieza con la palabra completa total/totales/subtotal o con «cantidad de», o es «son»
     * seguido de un número; trae algún número, y TODOS sus números son números sueltos (sin letras pegadas);
     * seis palabras como mucho y ninguna de razón social (sa, srl, sas, sh, hnos).
     *
     * Por qué así: «Total Quartz 5W40», «Total 10W40 aceite» o «500g» traen el número pegado a letras, que es
     * como se escribe un producto, y no entran; «Suma dental x 12» no empieza con una palabra de conteo; «Total
     * 2000 SA» es una razón social. Igual, quien llama exige además que la fila no tenga código ni precio.
     *
     * @param  string $texto
     * @return bool
     */
    public static function cuenta_en_el_rotulo(string $texto): bool
    {
        $comparable = self::titulo_comparable($texto);
        // «TOTAL-2024» (sin espacios) es un código, no un rótulo.
        if (preg_match('/\d/', $comparable) !== 1 || preg_match('/\s/u', trim($texto)) !== 1) {
            return false;
        }
        $palabras = explode(' ', $comparable);
        if (count($palabras) > 6) {
            return false;
        }
        foreach ($palabras as $palabra) {
            if ((preg_match('/\d/', $palabra) === 1 && !ctype_digit($palabra)) || in_array($palabra, ['sa', 'srl', 'sas', 'sh', 'hnos'], true)) {
                return false;
            }
        }

        return preg_match('/^(?:(?:sub ?)?total(?:es)?|cantidad de) /', $comparable) === 1
            || preg_match('/^son \d+(?: |$)/', $comparable) === 1;
    }

    /**
     * ¿El rótulo es de una fila de total? empieza_como_total() o cuenta_en_el_rotulo().
     *
     * @param  string $texto
     * @return bool
     */
    public static function es_rotulo_de_total(string $texto): bool
    {
        return self::empieza_como_total($texto) || self::cuenta_en_el_rotulo($texto);
    }

    /**
     * ¿El texto es un título decorado? Abre y cierra con el mismo signo repetido («--- X ---», «*** X ***»,
     * «=== X ===», «## X ##») y adentro no trae números: «-- Martillo 500g --» es un artículo.
     *
     * @param  string $texto
     * @return bool
     */
    public static function es_titulo_decorado(string $texto): bool
    {
        return preg_match('/^([-=*#_~•·])\1+\s*(.*?)\s*\1{2,}$/u', $texto, $partes) === 1
            && preg_match('/\pL/u', $partes[2]) === 1 && preg_match('/\d/', $partes[2]) !== 1;
    }

    /**
     * Lo que hay que saber de la hoja ENTERA antes de juzgar fila por fila (misión prueba-e2e-campana-imperio, E8,
     * E8.1 y E8.3). Una sola pasada sobre las filas crudas, sin guardarlas:
     *
     *  - `con_codigos` (E8.5): ¿los artículos de la hoja traen código? Sobre la hoja entera:
     *      · D = filas CON DATOS: el nombre y al menos otro dato mapeado (código, precio, costo, stock, marca,
     *        categoría, proveedor…), sin rótulo de total;
     *      · C = las de D que traen código;
     *      · S = filas SUELTAS: una sola celda (solo el nombre, o solo un valor en la columna del código: un texto,
     *        una fecha, un número, una firma), sin rótulo de total (es_fila_suelta());
     *      · con_codigos = C ≥ 10 y 4·C ≥ 3·D y 2·S ≤ D.
     *    Ni las sueltas ni los totales están en el denominador: son justo las filas que se juzgan (E8.1, E8.2 y
     *    E8.4 fallaron por contarlas). La tercera condición: si las sueltas son más de la mitad de las filas con
     *    datos («10 con código + 200 nombres pelados») no son títulos, son artículos, y la hoja no es «con
     *    códigos». Una hoja a medio codificar tampoco (E8.4: «10 con código + 200 con stock» perdía los 200).
     *  - `ultima_con_codigo`: la última fila de C (nombre + código).
     *  - `pie_por_total`: dónde empieza el pie que abre un total. Al final de la hoja hay una tanda de filas que
     *    son notas (un solo texto) o totales (es_fila_de_total_al_pie()); el pie empieza en el PRIMER total de esa
     *    tanda. Las notas que estén antes de ese total (sin total arriba) no son pie.
     *  - `ultimo_total`: la última fila cuyo rótulo es de total (un «Subtotal X» del pie antes de ella es subtotal).
     *
     * Reemplaza a fila_de_cierre() (la «última fila con datos», que con un pie que cuenta seguido de una nota
     * quedaba mal ubicada: E8.3) y a hoja_con_codigos().
     *
     * @param  iterable $filas  número de fila => celdas crudas
     * @param  array    $mapeo
     * @return array{con_codigos: bool, ultima_con_codigo: int|null, pie_por_total: int|null, ultimo_total: int|null}
     */
    public static function resumir_la_hoja(iterable $filas, array $mapeo): array
    {
        $de_codigo = self::columnas_de_codigo($mapeo);
        $de_importe = self::columnas_de_importe($mapeo);
        $del_nombre = isset($mapeo['nombre']) ? (int) $mapeo['nombre'] : null;

        // Las columnas mapeadas que no son el nombre: código, precio, costo, stock, marca, categoría, proveedor…
        $otras_mapeadas = [];
        foreach ($mapeo as $campo => $posicion) {
            if ($campo !== 'nombre') {
                $otras_mapeadas[(int) $posicion] = true;
            }
        }
        $con_datos = 0;   // D
        $con_codigo = 0;  // C
        $sueltas = 0;     // S
        $ultima_con_codigo = null;
        $primer_total_de_la_tanda = null;
        $ultimo_total = null;
        foreach ($filas as $numero => $fila) {
            $con_dato = self::celdas_con_dato($fila);
            if ($con_dato === []) {
                continue;
            }
            $rotulo = self::posicion_del_rotulo($con_dato, $del_nombre, $de_codigo);
            $es_total = $rotulo !== null && self::es_rotulo_de_total($con_dato[$rotulo]);
            if ($es_total) {
                $ultimo_total = (int) $numero;
            } elseif ($del_nombre !== null && isset($con_dato[$del_nombre]) && array_intersect_key($con_dato, $otras_mapeadas) !== []) {
                $con_datos++;
                // C ⊂ D (E8.6): una fila «con código» trae el nombre y el código; un valor solo en la columna del
                // código (una fecha, un número, una firma) es una fila suelta, nunca un código.
                if (array_intersect_key($con_dato, $de_codigo) !== []) {
                    $con_codigo++;
                    $ultima_con_codigo = (int) $numero;
                }
            } elseif (self::es_fila_suelta($con_dato, $del_nombre, $de_codigo)) {
                $sueltas++;
            }

            // La tanda del pie: notas y totales seguidos, hasta el final. Cualquier otra fila la corta.
            if (self::es_fila_de_total_al_pie($con_dato, $del_nombre, $de_codigo, $de_importe)) {
                $primer_total_de_la_tanda = $primer_total_de_la_tanda ?? (int) $numero;
            } elseif (!self::es_nota_suelta($con_dato)) {
                $primer_total_de_la_tanda = null;
            }
        }

        return [
            'con_codigos' => $de_codigo !== [] && $del_nombre !== null && $con_codigo >= 10
                && $con_codigo * 4 >= $con_datos * 3 && $sueltas * 2 <= $con_datos,
            'ultima_con_codigo' => $ultima_con_codigo,
            'pie_por_total' => $primer_total_de_la_tanda,
            'ultimo_total' => $ultimo_total,
            // Lo armó resumir_la_hoja() sobre la hoja entera (no un test de una fila suelta: ver motivo_para_saltear()).
            'hoja_entera' => true,
        ];
    }

    /**
     * Los motivos de las filas que no son artículos de una hoja ya en memoria: número de fila => motivo. Es lo mismo
     * que hace filas() (resumir_la_hoja() + motivo_de_la_fila(); desde E8.6 tampoco las filas sin datos mapeados se
     * saltean en silencio: tienen su motivo), para
     * poder medir la regla sin escribir un Excel (ImportacionDePruebaFilasQueNoSonDatosGenerativoTest).
     *
     * @param  array $filas    número de fila => celdas crudas (desde la primera fila de datos)
     * @param  array $mapeo
     * @param  array $titulos  Posición => título de la cabecera ya comparable
     * @return array<int, string>
     */
    public static function motivos_de_la_hoja(array $filas, array $mapeo, array $titulos = []): array
    {
        $resumen = self::resumir_la_hoja($filas, $mapeo);
        $motivos = [];
        foreach ($filas as $numero => $fila) {
            $motivo = self::motivo_de_la_fila($fila, $mapeo, $resumen, (int) $numero, $titulos);
            if ($motivo !== null) {
                $motivos[(int) $numero] = $motivo;
            }
        }

        return $motivos;
    }

    /**
     * ¿La fila no trae nada en ninguna columna mapeada? filas() la saltea sin contarla.
     *
     * @param  array $fila
     * @param  array $mapeo
     * @return bool
     */
    private static function sin_datos_mapeados(array $fila, array $mapeo): bool
    {
        foreach ($mapeo as $campo => $posicion) {
            $valor = ImportHelper::getColumnValue($fila, $campo, $mapeo, $campo === 'nombre');
            if ($valor !== null && $valor !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿La fila es una nota suelta? Una sola celda en todo su ancho que no es un rótulo de total («Consulte por
     * descuentos por cantidad.», «Stock valorizado al costo: $ 1.234.567», «14/11/2026», una suma sin rótulo): no
     * corta la tanda del pie.
     *
     * @param  array $con_dato
     * @return bool
     */
    private static function es_nota_suelta(array $con_dato): bool
    {
        if (count($con_dato) !== 1) {
            return false;
        }
        // Cualquier celda sola que no sea un rótulo de total: un texto, un número, un importe, una fecha (E8.6).
        return !self::es_rotulo_de_total((string) reset($con_dato));
    }

    /**
     * ¿Es una fila de total del pie? El rótulo de total (es_rotulo_de_total()) en la columna del nombre o, con el
     * nombre vacío, en una de código, y al lado: nada; o un solo número fuera de las columnas de código («TOTAL
     * LISTA | 12.345» en la del precio, como vino la lista del ensayo); o solo números fuera de las columnas de
     * código, precio y costo («TOTAL UNIDADES | 299 | 338», ver es_total_de_columnas()).
     *
     * @param  array    $con_dato
     * @param  int|null $columna_del_nombre
     * @param  array    $columnas_de_codigo
     * @param  array    $columnas_de_importe
     * @return bool
     */
    private static function es_fila_de_total_al_pie(array $con_dato, ?int $columna_del_nombre, array $columnas_de_codigo, array $columnas_de_importe): bool
    {
        if (self::es_total_de_columnas($con_dato, $columna_del_nombre, $columnas_de_codigo, $columnas_de_importe)) {
            return true;
        }
        if (count($con_dato) > 2) {
            return false;
        }
        $rotulo = self::posicion_del_rotulo($con_dato, $columna_del_nombre, $columnas_de_codigo);
        if ($rotulo === null || !self::es_rotulo_de_total($con_dato[$rotulo])) {
            return false;
        }
        $resto = array_diff_key($con_dato, [$rotulo => true]);
        if ($resto === []) {
            return true;
        }
        $otra = (int) array_key_first($resto);

        return !isset($columnas_de_codigo[$otra]) && self::importe($resto[$otra]) !== null;
    }

    /**
     * Dónde está el rótulo de la fila: la columna del nombre si trae dato o, si no, la primera de código con dato.
     *
     * @param  array    $con_dato
     * @param  int|null $columna_del_nombre
     * @param  array    $columnas_de_codigo
     * @return int|null
     */
    private static function posicion_del_rotulo(array $con_dato, ?int $columna_del_nombre, array $columnas_de_codigo): ?int
    {
        if ($columna_del_nombre !== null && isset($con_dato[$columna_del_nombre])) {
            return $columna_del_nombre;
        }
        foreach (array_keys($columnas_de_codigo) as $de_codigo) {
            if (isset($con_dato[$de_codigo])) {
                return $de_codigo;
            }
        }

        return null;
    }

    /**
     * Las posiciones de las columnas de código del mapeo (número, código de barras, SKU, código de proveedor).
     *
     * @param  array $mapeo
     * @return array<int, true>
     */
    private static function columnas_de_codigo(array $mapeo): array
    {
        $columnas = [];
        foreach (['numero', 'codigo_de_barras', 'sku', 'codigo_de_proveedor'] as $campo) {
            if (isset($mapeo[$campo])) {
                $columnas[(int) $mapeo[$campo]] = true;
            }
        }

        return $columnas;
    }

    /**
     * Las posiciones de las columnas de importe del artículo: precio de venta, costo y el precio final de una
     * lista de precios.
     *
     * @param  array $mapeo
     * @return array<int, true>
     */
    private static function columnas_de_importe(array $mapeo): array
    {
        $columnas = [];
        foreach ($mapeo as $campo => $posicion) {
            if (in_array($campo, ['precio', 'costo'], true) || strpos((string) $campo, '$_final_') === 0) {
                $columnas[(int) $posicion] = true;
            }
        }

        return $columnas;
    }

    /**
     * ¿El texto puede ser el nombre de un rubro? Solo letras y espacios (y como mucho «&», «/», «,», «.», «-»
     * entre ellas), sin números y de hasta cuatro palabras: «HERRAMIENTAS», «Herramientas manuales». Con
     * números o signos («Martillo 500g», «**Martillo») es un artículo.
     *
     * @param  string $texto
     * @return bool
     */
    private static function parece_un_rubro(string $texto): bool
    {
        return preg_match('/^\pL[\pL\s&\/,.\-]*$/u', $texto) === 1
            && count(preg_split('/\s+/u', trim($texto))) <= 4;
    }

    /**
     * Cuenta una fila salteada y guarda su texto (los primeros EJEMPLOS_DE_SALTEADAS de cada motivo).
     *
     * @param  int    $indice  Índice de la hoja
     * @param  string $motivo  self::FILA_TITULO, self::FILA_TOTAL o self::FILA_SUBTOTAL
     * @param  array  $fila
     * @param  array  $mapeo
     * @param  bool   $con_texto  Si se guarda su texto entre los ejemplos (la cabecera repetida solo se cuenta)
     * @return void
     */
    private function anotar_salteada(int $indice, string $motivo, array $fila, array $mapeo, bool $con_texto = true): void
    {
        $this->salteadas[$indice][$motivo] = ($this->salteadas[$indice][$motivo] ?? 0) + 1;
        if (!$con_texto) {
            // La cabecera repetida no se nombra («“COD”» no le dice nada al dueño), pero se cuenta para decirla (B2).
            $this->salteadas[$indice]['cabeceras'] = ($this->salteadas[$indice]['cabeceras'] ?? 0) + 1;
            return;
        }

        $con_dato = self::celdas_con_dato($fila);
        $texto = in_array($motivo, [self::FILA_TOTAL, self::FILA_SUBTOTAL], true) && isset($mapeo['nombre'], $con_dato[(int) $mapeo['nombre']])
            ? $con_dato[(int) $mapeo['nombre']] : (string) reset($con_dato);
        // Sin el punto final: la frase del resumen ya cierra con el suyo.
        $texto = rtrim($texto, '. ');
        $clave = 'textos_' . $motivo;
        if (count($this->salteadas[$indice][$clave] ?? []) < self::EJEMPLOS_DE_SALTEADAS) {
            $this->salteadas[$indice][$clave][] = mb_substr($texto, 0, 60);
        }
        // En el orden de la planilla (ronda 8, segunda pasada): una lista, que la columna JSON no reordena.
        if (count($this->salteadas[$indice]['orden'] ?? []) < self::SALTEADAS_EN_ORDEN) {
            $this->salteadas[$indice]['orden'][] = [$motivo, mb_substr($texto, 0, 60)];
        }
    }

    /** Cuántas filas salteadas se guardan en su orden (alcanza para elegir las que se nombran y los totales). */
    private const SALTEADAS_EN_ORDEN = 50;

    /**
     * Por qué una fila de la planilla no es un artículo, o null si hay que importarla (misión
     * prueba-e2e-campana-imperio, 11/11/2026).
     *
     * Las listas de proveedor traen títulos de rubro («--- TORNILLERIA ---» en la columna del código y el
     * resto vacío), notas sueltas al pie y una fila de total. Hasta hoy terminaban como artículos: el motor
     * solo descarta la fila que no tiene código NI nombre.
     *
     * 🔴 La regla es conservadora a propósito: descartar de más es perder un artículo sin que nadie lo vea.
     * Ante la duda la fila se importa, como siempre. Lo descartado sale nombrado en el resumen al dueño.
     *
     * Una fila es «no dato» por lo que ES (E8 a E8.3). «Es un total» lo decide UNA sola función,
     * es_rotulo_de_total(): empieza con la palabra completa total/totales/subtotal/suma(s) («TOTAL GENERAL»,
     * «Sub-total plomería:») o es el pie que cuenta («Total artículos: 21», «Son 21 artículos»).
     *
     *  - EL PIE (FILA_TOTAL si su rótulo es de total; FILA_TITULO si es una nota, una firma, una fecha):
     *      · en cualquier hoja, desde el primer total de la tanda final de notas y totales (ver
     *        resumir_la_hoja(), `pie_por_total`);
     *      · en una hoja cuyos artículos traen código (`con_codigos`, contado sobre la hoja entera), además,
     *        DEBAJO de la última fila con código: una sola celda de texto (solo el nombre, o solo un texto en la
     *        columna del código: «Actualizó: Mirta 12/11» después de «Total artículos: 20») o un rótulo de total.
     *        Una fila con el nombre y algún otro dato (stock, marca, precio) es un ARTÍCULO y entra (E8.4). Un
     *        nombre suelto ahí abajo se deja afuera, como ya hacía parece_un_rubro() en el medio: en una hoja con
     *        códigos un nombre sin ningún otro dato no se distingue de un rubro o una nota (queda nombrado).
     *  - En el medio de la hoja:
     *      · FILA_TITULO: la cabecera repetida entera (repite_la_cabecera()).
     *      · FILA_SUBTOTAL (o FILA_TOTAL si cuenta): solo en una hoja con códigos, rótulo de total en la columna
     *        del nombre (o, sin nombre, en la del código), sin código, sin precio ni costo y al lado solo números
     *        o nada («Subtotal plomeria | | 39»). En una hoja SIN códigos un «Total X» del medio no se toca: ahí
     *        la falta de código no dice nada.
     *      · FILA_TITULO con UN solo valor de texto en toda la fila: decorado («--- X ---», «*** X ***»), con
     *        rótulo de título («Rubro: X», «Pinturería:», es_titulo_con_rotulo()), en una columna de código sin
     *        parecer un código, o, en una hoja con códigos, en la del nombre y con forma de rubro (solo letras,
     *        hasta cuatro palabras: «HERRAMIENTAS»). Un nombre suelto en una hoja sin códigos es un artículo.
     *  «Totalgaz garrafa», «Total Quartz 5W40», «Suma dental x 12» y «Total Flex» con precio o con código son
     *  artículos.
     *
     * @param  array $fila              Celdas crudas de la fila, por posición
     * @param  array $mapeo             Campo del importador => posición de su columna
     * Esta firma juzga UNA fila suelta, con lo que de la hoja diga quien llama (la usan los tests de forma); la
     * carga usa motivo_de_la_fila() con el resumen de la hoja entera (resumir_la_hoja()).
     *
     * @param  bool  $es_el_cierre      Si la fila es el total del pie (la que abre la tanda final de notas y totales)
     * @param  bool  $despues_del_total Si la fila está debajo de ese total (una nota)
     * @param  bool  $hoja_con_codigos  Si los artículos de la hoja traen código
     * @param  array $titulos           Posición => título de la cabecera ya comparable (ver titulos_de_la_cabecera())
     * @return string|null              self::FILA_TITULO, self::FILA_TOTAL, self::FILA_SUBTOTAL o null
     */
    public static function motivo_para_saltear(array $fila, array $mapeo, bool $es_el_cierre = false, bool $despues_del_total = false, bool $hoja_con_codigos = false, array $titulos = []): ?string
    {
        $con_dato = self::celdas_con_dato($fila);
        // Debajo del total, un texto suelto es una nota al pie, esté en la columna que esté.
        if ($despues_del_total && count($con_dato) === 1 && self::importe((string) reset($con_dato)) === null
            && preg_match('/\pL/u', (string) reset($con_dato)) === 1) {
            return self::FILA_TITULO;
        }
        $resumen = ['con_codigos' => $hoja_con_codigos, 'ultima_con_codigo' => null, 'pie_por_total' => null, 'ultimo_total' => null];
        $columna_del_nombre = isset($mapeo['nombre']) ? (int) $mapeo['nombre'] : null;
        if ($es_el_cierre && self::es_fila_de_total_al_pie($con_dato, $columna_del_nombre, self::columnas_de_codigo($mapeo), self::columnas_de_importe($mapeo))) {
            $resumen['pie_por_total'] = 1;
        }

        return self::motivo_de_la_fila($fila, $mapeo, $resumen, 1, $titulos);
    }

    /**
     * La regla de verdad (ver motivo_para_saltear()), con el resumen de la hoja entera (resumir_la_hoja()). La usan
     * filas() y motivos_de_la_hoja().
     *
     * @param  array $fila
     * @param  array $mapeo
     * @param  array $resumen
     * @param  int   $numero
     * @param  array $titulos
     * @return string|null
     */
    private static function motivo_de_la_fila(array $fila, array $mapeo, array $resumen, int $numero, array $titulos): ?string
    {
        $con_dato = self::celdas_con_dato($fila);
        if ($con_dato === []) {
            return null;
        }

        $columna_del_nombre = isset($mapeo['nombre']) ? (int) $mapeo['nombre'] : null;
        $columnas_de_codigo = self::columnas_de_codigo($mapeo);
        $columnas_de_importe = self::columnas_de_importe($mapeo);
        $hoja_con_codigos = !empty($resumen['con_codigos']);

        // EL PIE (E8.3, E8.4). Lo que viene desde el total del pie (notas, firmas, fechas) y, en una hoja con códigos,
        // debajo de la última fila con código: una celda sola de texto o un rótulo de total. Una fila con el nombre y
        // algún otro dato (stock, marca, precio) es un artículo, esté donde esté.
        $en_el_pie = (isset($resumen['pie_por_total']) && $numero >= (int) $resumen['pie_por_total'])
            || ($hoja_con_codigos && $numero > (int) ($resumen['ultima_con_codigo'] ?? PHP_INT_MAX)
                && (self::es_fila_suelta($con_dato, $columna_del_nombre, $columnas_de_codigo)
                    || self::es_fila_de_total($con_dato, $columna_del_nombre, $columnas_de_codigo, $columnas_de_importe) !== null));
        if ($en_el_pie) {
            $rotulo = self::posicion_del_rotulo($con_dato, $columna_del_nombre, $columnas_de_codigo);
            $texto = $rotulo !== null ? $con_dato[$rotulo] : (string) reset($con_dato);

            if (!self::es_rotulo_de_total($texto)) {
                return self::FILA_TITULO;
            }
            // El subtotal del último rubro también cae debajo del último código: si hay un total más abajo, sigue
            // siendo un subtotal; el último rótulo de total de la hoja es EL total.
            $es_sub = preg_match('/^sub ?total/', self::titulo_comparable($texto)) === 1;

            return $es_sub && $numero < (int) ($resumen['ultimo_total'] ?? 0) ? self::FILA_SUBTOTAL : self::FILA_TOTAL;
        }

        // La cabecera repetida entera («Art | Descripcion | Stock» otra vez, a mitad de la hoja).
        if (self::repite_la_cabecera($con_dato, $titulos)) {
            return self::FILA_TITULO;
        }
        // Un subtotal o total en el medio de una hoja cuyos artículos traen código (E8.1). El que cuenta en su
        // rótulo («Total artículos: 21») es un total (E8.2).
        $rotulo = $hoja_con_codigos ? self::es_fila_de_total($con_dato, $columna_del_nombre, $columnas_de_codigo, $columnas_de_importe) : null;
        if ($rotulo !== null) {
            return self::cuenta_en_el_rotulo($rotulo) ? self::FILA_TOTAL : self::FILA_SUBTOTAL;
        }
        // En una hoja con códigos TODA fila suelta queda afuera, traiga lo que traiga (E8.6): un artículo ahí trae al
        // menos otro dato además del nombre.
        if ($hoja_con_codigos && self::es_fila_suelta($con_dato, $columna_del_nombre, $columnas_de_codigo)) {
            return self::FILA_TITULO;
        }
        if (!empty($resumen['hoja_entera'])) {
            // Una fila sin ningún dato en las columnas mapeadas (solo una suma en una columna sin mapear) no es nada:
            // se cuenta (E8.6).
            if (self::sin_datos_mapeados($fila, $mapeo)) {
                return self::FILA_TITULO;
            }
            // Sin nombre y sin un código de verdad (una fecha, un importe sueltos) no es un artículo (E8.6).
            if ($columna_del_nombre !== null && !isset($con_dato[$columna_del_nombre]) && !self::trae_un_codigo($con_dato, $columnas_de_codigo)) {
                return self::FILA_TITULO;
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
        if (self::es_titulo_decorado($valor) || self::es_titulo_con_rotulo($valor)) {
            return self::FILA_TITULO;
        }
        $parece_un_codigo = !preg_match('/\s/u', $valor) && preg_match('/\d/', $valor);
        if (isset($columnas_de_codigo[$posicion]) && $columna_del_nombre !== null && !$parece_un_codigo) {
            return self::FILA_TITULO;
        }
        // Un nombre suelto en la columna del nombre, en una hoja cuyos artículos traen código: el rubro sin decorar.
        if ($hoja_con_codigos && $posicion === $columna_del_nombre && self::parece_un_rubro($valor)) {
            return self::FILA_TITULO;
        }

        return null;
    }

    /**
     * ¿La fila es SUELTA? Una sola celda en todo su ancho, en la columna del nombre o en una de código, sin rótulo de
     * total: solo el nombre, o solo un valor en la columna del código (un texto, una fecha, un número, una firma).
     * Ver resumir_la_hoja() (S) y motivo_de_la_fila().
     *
     * @param  array    $con_dato
     * @param  int|null $columna_del_nombre
     * @param  array    $columnas_de_codigo
     * @return bool
     */
    private static function es_fila_suelta(array $con_dato, ?int $columna_del_nombre, array $columnas_de_codigo): bool
    {
        if (count($con_dato) !== 1) {
            return false;
        }
        $posicion = (int) array_key_first($con_dato);

        return ($posicion === $columna_del_nombre || isset($columnas_de_codigo[$posicion]))
            && !self::es_rotulo_de_total((string) reset($con_dato));
    }

    /**
     * ¿Hay en la fila un código de verdad? Un valor en una columna de código que no es una fecha («14/11/2026») ni un
     * importe con separadores o signo («$ 1.234», «1.234,50»). Un número entero solo (un código de barras) sí es un
     * código.
     *
     * @param  array $con_dato
     * @param  array $columnas_de_codigo
     * @return bool
     */
    private static function trae_un_codigo(array $con_dato, array $columnas_de_codigo): bool
    {
        foreach (array_intersect_key($con_dato, $columnas_de_codigo) as $valor) {
            $valor = (string) $valor;
            $fecha = preg_match('#^\d{1,4}[/.\-]\d{1,2}[/.\-]\d{1,4}$#', $valor) === 1;
            $importe = strpos($valor, '$') !== false || (self::importe($valor) !== null && !ctype_digit($valor));
            if (!$fecha && !$importe) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿El texto se presenta como título? Empieza con «Rubro», «Sección», «Categoría», «Familia» o «Línea» seguido de
     * dos puntos («Rubro: Construcción», «LINEA: Sanitarios»), o termina en dos puntos y no trae números
     * («Pinturería:»; «Cable 2x1,5:» es un artículo). Vale en cualquier hoja (E8.3, E8.4).
     *
     * @param  string $texto
     * @return bool
     */
    private static function es_titulo_con_rotulo(string $texto): bool
    {
        // «Cable 2x1,5:» es un artículo: el «:» final solo cuenta sin números (E8.4).
        return count(preg_split('/\s+/u', trim($texto))) <= 5
            && ((substr(rtrim($texto), -1) === ':' && preg_match('/\d/', $texto) !== 1)
                || preg_match('/^\s*(?:rubro|secci[oó]n|categor[ií]a|familia|l[ií]nea)s?\s*:/iu', $texto) === 1);
    }

    /**
     * ¿Es la fila de los totales por columna? El rótulo de total en la columna del nombre, nada en las columnas
     * de código ni en las de precio o costo, y al lado al menos un dato y solo importes («TOTAL UNIDADES»,
     * 299, 338). Quien llama ya sabe que es la fila de cierre.
     *
     * @param  array    $con_dato            Celdas con dato, por posición
     * @param  int|null $columna_del_nombre
     * @param  array    $columnas_de_codigo  Posición => true
     * @param  array    $columnas_de_importe Posición => true
     * @return bool
     */
    private static function es_total_de_columnas(array $con_dato, ?int $columna_del_nombre, array $columnas_de_codigo, array $columnas_de_importe): bool
    {
        if ($columna_del_nombre === null || !isset($con_dato[$columna_del_nombre]) || !self::es_rotulo_de_total($con_dato[$columna_del_nombre])) {
            return false;
        }
        $resto = array_diff_key($con_dato, [$columna_del_nombre => true]);
        if ($resto === [] || array_intersect_key($resto, $columnas_de_codigo + $columnas_de_importe) !== []) {
            return false;
        }
        foreach ($resto as $valor) {
            if (self::importe($valor) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Es una fila de total o subtotal, esté donde esté (E8.1)? El rótulo («Subtotal plomeria», «TOTAL
     * GENERAL», «Totales:») en la columna del nombre o, con el nombre vacío, en una columna de código; ninguna
     * otra celda en columnas de código ni de precio, costo o precio de lista; y el resto, si hay, solo números.
     * Quien llama ya sabe que la hoja trae códigos (ver motivo_para_saltear()).
     *
     * @param  array    $con_dato            Celdas con dato, por posición
     * @param  int|null $columna_del_nombre
     * @param  array    $columnas_de_codigo  Posición => true
     * @param  array    $columnas_de_importe Posición => true
     * @return string|null                   El rótulo, o null si no es una fila de total
     */
    private static function es_fila_de_total(array $con_dato, ?int $columna_del_nombre, array $columnas_de_codigo, array $columnas_de_importe): ?string
    {
        $rotulo = self::posicion_del_rotulo($con_dato, $columna_del_nombre, $columnas_de_codigo);
        if ($rotulo === null || !self::es_rotulo_de_total($con_dato[$rotulo])) {
            return null;
        }
        $resto = array_diff_key($con_dato, [$rotulo => true]);
        if (array_intersect_key($resto, $columnas_de_codigo + $columnas_de_importe) !== []) {
            return null;
        }
        foreach ($resto as $valor) {
            if (self::importe($valor) === null) {
                return null;
            }
        }

        return $con_dato[$rotulo];
    }

    /**
     * Los títulos de la cabecera de la hoja, por posición y ya comparables (sin acentos ni signos), o [] si la
     * hoja no los trae.
     *
     * @param  array $hoja
     * @return array<int, string>
     */
    private static function titulos_de_la_cabecera(array $hoja): array
    {
        $titulos = [];
        foreach ((array) ($hoja['columnas'] ?? []) as $posicion => $columna) {
            $titulo = self::titulo_comparable($columna['titulo'] ?? '');
            if ($titulo !== '') {
                $titulos[(int) $posicion] = $titulo;
            }
        }

        return $titulos;
    }

    /**
     * ¿La fila repite la cabecera? Dos celdas con dato o más y TODAS iguales al título de su misma columna
     * («Art | Descripcion | Marca | Stock» otra vez a mitad de la hoja, al pegar dos listas). Con una sola celda
     * no alcanza: «Marca» suelta puede ser un artículo o un rubro, y eso lo juzga el resto de las reglas.
     *
     * @param  array $con_dato  Celdas con dato, por posición
     * @param  array $titulos   Posición => título comparable
     * @return bool
     */
    private static function repite_la_cabecera(array $con_dato, array $titulos): bool
    {
        if ($titulos === [] || count($con_dato) < 2) {
            return false;
        }
        foreach ($con_dato as $posicion => $valor) {
            if (!isset($titulos[$posicion]) || self::titulo_comparable($valor) !== $titulos[$posicion]) {
                return false;
            }
        }

        return true;
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
        $subtotales = [];
        $totales = [];
        foreach ($hojas as $hoja) {
            $salteadas = $hoja['filas_salteadas'] ?? [];
            $filas += (int) ($salteadas[self::FILA_TITULO] ?? 0) + (int) ($salteadas[self::FILA_TOTAL] ?? 0)
                + (int) ($salteadas[self::FILA_SUBTOTAL] ?? 0);
            $titulos = array_merge($titulos, array_values($salteadas['textos_' . self::FILA_TITULO] ?? []));
            $subtotales = array_merge($subtotales, array_values($salteadas['textos_' . self::FILA_SUBTOTAL] ?? []));
            $totales = array_merge($totales, array_values($salteadas['textos_' . self::FILA_TOTAL] ?? []));
        }
        if ($filas === 0) {
            return '';
        }

        /*
         * B2 (ronda 8): hasta EJEMPLOS_DE_SALTEADAS se nombran TODAS, en su orden (títulos, subtotales y el total al
         * final); con más, «y K más» con el número, nunca un «…» mudo. La cabecera repetida no se nombra pero se dice.
         * Si no entran todas, el total va igual a la vista y queda lugar para al menos un subtotal.
         */
        $cabeceras = 0;
        foreach ($hojas as $hoja) {
            $cabeceras += (int) (($hoja['filas_salteadas'] ?? [])['cabeceras'] ?? 0);
        }
        $entre_comillas = function ($texto) {
            return '“' . $texto . '”';
        };
        // Segunda pasada: en el orden en que aparecen en la planilla, si la corrida lo guardó (las viejas, como antes).
        $en_orden = [];
        $con_orden = true;
        foreach ($hojas as $hoja) {
            $salteadas = $hoja['filas_salteadas'] ?? [];
            $con_textos = !empty($salteadas['textos_' . self::FILA_TITULO]) || !empty($salteadas['textos_' . self::FILA_SUBTOTAL])
                || !empty($salteadas['textos_' . self::FILA_TOTAL]);
            if ($con_textos && !isset($salteadas['orden'])) {
                $con_orden = false;
            }
            foreach ((array) ($salteadas['orden'] ?? []) as $item) {
                $en_orden[] = $item;
            }
        }
        if ($con_orden && $en_orden !== []) {
            if (count($en_orden) > self::EJEMPLOS_DE_SALTEADAS) {
                // No entran todas: el total (hasta dos) igual a la vista, y el resto del lugar para las primeras.
                $elegidas = [];
                foreach ($en_orden as $posicion => $item) {
                    if ($item[0] === self::FILA_TOTAL && count($elegidas) < 2) {
                        $elegidas[$posicion] = $item;
                    }
                }
                foreach ($en_orden as $posicion => $item) {
                    if ($item[0] !== self::FILA_TOTAL && count($elegidas) < self::EJEMPLOS_DE_SALTEADAS) {
                        $elegidas[$posicion] = $item;
                    }
                }
                ksort($elegidas);
                $en_orden = array_values($elegidas);
            }
            $partes = array_map(function ($item) use ($entre_comillas) {
                return $entre_comillas($item[1]);
            }, $en_orden);
        } else {
            $totales = array_slice($totales, 0, 2);
            $lugar = self::EJEMPLOS_DE_SALTEADAS - count($totales);
            $titulos = array_slice($titulos, 0, max(0, $lugar - min(1, count($subtotales))));
            $subtotales = array_slice($subtotales, 0, $lugar - count($titulos));
            $partes = array_map($entre_comillas, array_merge($titulos, $subtotales, $totales));
        }
        $resto = $filas - count($partes);
        $lista = implode(', ', $partes);
        if ($partes !== [] && $resto > 0) {
            $lista .= $resto === $cabeceras
                ? ($cabeceras === 1 ? ' y la cabecera repetida' : ' y la cabecera repetida ' . $cabeceras . ' veces')
                : ' y ' . $resto . ' más';
        }

        return ' Dejé afuera ' . $filas . ($filas === 1 ? ' fila que no es un artículo' : ' filas que no son artículos')
            . ($partes === [] ? '' : ': ' . $lista) . '.'
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
    public static function pregunta_del_margen(array $hojas, int $sin_ganancia = 0, ?string $nombre_del_articulo = null): array
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

        // Un solo artículo (ronda 8, D2): se le pregunta a cuánto lo vende, y vale también un precio (`acepta_precio`).
        if (!$para_todos && $sin_ganancia === 1) {
            $nombre = trim((string) $nombre_del_articulo);

            return [self::CLAVE_MARGEN => [
                'clave' => self::CLAVE_MARGEN,
                'texto' => 'En tu lista hay 1 artículo' . ($nombre !== '' ? ' («' . mb_substr($nombre, 0, 80) . '»)' : '')
                    . ' que trae el costo pero no la ganancia ni el precio de venta. ¿A cuánto lo vendés, o qué ganancia le sumamos?'
                    . ' Puede ser ' . implode(' %, ', self::MARGENES_SUGERIDOS) . ' % u otro porcentaje, o el precio que me digas.'
                    . ' Si preferís cargarlo vos después, decímelo y por ahora queda sin ganancia (al costo con IVA).',
                'opciones' => $opciones,
                'acepta_porcentaje' => true,
                'acepta_precio' => true,
            ]];
        }

        $pregunta = [
            'clave' => self::CLAVE_MARGEN,
            // Con columna de ganancia o de precio y filas que la dejan vacía, la pregunta es solo por esas filas.
            'texto' => ($para_todos
                    ? 'Tu lista trae el costo pero no dice a cuánto vendés. ¿Qué ganancia le sumamos a todos para calcular el precio de venta?'
                    : 'En tu lista hay ' . $sin_ganancia . ' artículos que traen el costo pero no la ganancia ni el precio de venta.'
                        . ' ¿Qué ganancia les sumamos a esos, que no la traen?')
                . ' Puede ser ' . implode(' %, ', self::MARGENES_SUGERIDOS) . ' % u otro porcentaje que me digas.'
                // D3 (ronda 8): «después» no deja los artículos sin precio, quedan al costo con IVA.
                . ($para_todos ? ' Si preferís cargar los precios vos después, decímelo y por ahora quedan sin ganancia (al costo con IVA).'
                    : ' Si preferís cargarlo vos después, decímelo y por ahora quedan sin ganancia (al costo con IVA).'),
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
        // `precio:N` (ronda 8, D2): el precio de venta del único artículo con costo y sin ganancia ni precio. Solo llega
        // acá si es exactamente uno (ImportacionDePruebaService::preparar descarta la decisión si no).
        $precio = self::precio_de_decision($decisiones[self::CLAVE_MARGEN] ?? null);
        foreach ($hojas as &$hoja) {
            unset($hoja['margen_respondido'], $hoja['margen_para_vacias'], $hoja['precio_para_vacia']);
            if ($precio !== null) {
                if (self::campos_de_venta($hoja['mapeo'] ?? []) !== []) {
                    $hoja['precio_para_vacia'] = $precio;
                }
                continue;
            }
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

    /**
     * La decisión del margen que soporte pasa al lanzar la importación (`margen_de_ganancia`, ronda 8, D2), validada:
     * un porcentaje (`40`, `35.5`: mayor a 0 y hasta MARGEN_MAXIMO), `despues` o `precio:N` (N mayor a 0). El admin ya
     * la manda normalizada con la misma regex de los tres repos; se devuelve tal cual (sin espacios ni mayúsculas) para
     * que `margen_previo` sea igual a lo enviado. null si no vale.
     *
     * @param  mixed $valor
     * @return string|null
     */
    public static function decision_previa_de_margen($valor): ?string
    {
        if (!is_scalar($valor)) {
            return null;
        }
        $valor = strtolower(trim((string) $valor));
        if (!preg_match('/^(despues|\d{1,4}(\.\d{1,2})?|precio:\d{1,9}(\.\d{1,2})?)$/', $valor)) {
            return null;
        }
        if ($valor === self::MARGEN_DESPUES) {
            return $valor;
        }
        if (strpos($valor, 'precio:') === 0) {
            return self::precio_de_decision($valor) === null ? null : $valor;
        }

        return self::margen_de_respuesta($valor) === null ? null : $valor;
    }

    /**
     * El precio de una decisión `precio:N` como texto para la planilla preparada («1300», «1300.5»), o null si la
     * decisión no es un precio o N no es mayor a 0.
     *
     * @param  mixed $decision
     * @return string|null
     */
    public static function precio_de_decision($decision): ?string
    {
        if (!is_string($decision) || !preg_match('/^precio:(\d{1,9}(?:\.\d{1,2})?)$/', trim($decision), $partes)) {
            return null;
        }
        $precio = (float) $partes[1];
        if ($precio <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($precio, 2, '.', ''), '0'), '.');
    }

    private static function escribir_fila($csv, array $fila): void
    {
        if (fputcsv($csv, $fila, ',', '"', '') === false) {
            throw new RuntimeException('No se pudo escribir una fila de la planilla preparada.');
        }
    }
}
