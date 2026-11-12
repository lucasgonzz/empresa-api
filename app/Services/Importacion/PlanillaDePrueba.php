<?php

namespace App\Services\Importacion;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ArticleImportColumnsNormalizer;
use App\Http\Controllers\Helpers\import\article\IdentifierNormalizer;
use App\Http\Controllers\Helpers\import\excel\CsvDeHoja;
use App\Http\Controllers\Helpers\import\excel\EncabezadosRepetidos;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Models\Address;
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

    private $run;
    private $propiedades;
    private $nombres;
    private $proveedores;
    // Filas que `filas()` dejó afuera por no ser artículos: [índice de hoja => [motivo => cantidad, 'textos_<motivo>' => [...]]].
    private $salteadas = [];

    public function __construct(ImportacionDePrueba $run)
    {
        $this->run = $run;
        $this->propiedades = [];
        $this->nombres = [];
        $this->proveedores = Provider::where('user_id', $run->user_id)->pluck('name', 'id')->all();
        foreach (array_merge(AiExcelAnalyzer::propiedades_importables(), ['stock_minimo']) as $campo) {
            $canonico = ArticleImportColumnsNormalizer::normalize_property_key($campo);
            $this->propiedades[$canonico] = $canonico;
            $this->nombres[$canonico] = str_replace('_', ' ', $canonico);
        }

        foreach (Address::where('user_id', $run->user_id)->whereNull('buyer_id')->get(['id', 'street']) as $deposito) {
            $clave = str_replace(' ', '_', strtolower($deposito->street));
            foreach (['amount' => '', 'min' => 'min_', 'max' => 'max_'] as $subtipo => $prefijo) {
                $campo = 'address_' . $deposito->id . '_' . $subtipo;
                $this->propiedades[$campo] = $prefijo . $clave;
                $this->nombres[$campo] = 'Depósito ' . $deposito->street . ($prefijo === '' ? '' : ' (' . $subtipo . ')');
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
                    'muestra' => $muestras[$posicion],
                ];
            }

            foreach ($resultado['columnas_ambiguas'] ?? [] as $ambigua) {
                foreach ($ambigua['columnas'] as $posicion) {
                    if (isset($columnas[$posicion]) && $posicion !== $descripcion_como_nombre) {
                        $columnas[$posicion]['segura'] = false;
                    }
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

        return compact('muestras', 'descuentos', 'columnas_del_bloque');
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
            $opciones += $this->nombres;
            foreach ($hoja['columnas'] as $posicion => $columna) {
                if ($columna['segura']) {
                    continue;
                }
                $clave = self::clave_columna($hoja, (int) $posicion);
                $letra = Coordinate::stringFromColumnIndex((int) $posicion + 1);
                $referencia = 'columna ' . $letra . ' de «' . $hoja['nombre'] . '»';
                if (self::titulo_comparable($columna['titulo']) === 'precio' && isset($opciones['precio'], $opciones['costo'])) {
                    $texto = '¿Me confirmás si el precio de la ' . $referencia . ' es de venta o es el costo?';
                } else {
                    $referencia .= $columna['titulo'] !== '' ? ' (' . $columna['titulo'] . ')' : '';
                    $referencia .= $columna['muestra'] !== '' ? ', por ejemplo «' . $columna['muestra'] . '»' : '';
                    $texto = '¿Qué dato querés cargar con la ' . $referencia . '?';
                }
                $preguntas[$clave] = self::pregunta($clave, $texto, array_filter($opciones, 'is_string'));
            }
        }

        return $preguntas;
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
            $mapeo = [];
            foreach ($hoja['columnas'] as $posicion => $columna) {
                $campo = $columna['segura'] ? $columna['campo'] : ($decisiones[self::clave_columna($hoja, (int) $posicion)] ?? null);
                if (!isset($this->propiedades[$campo])) {
                    continue;
                }
                $propiedad = $this->propiedades[$campo];
                if (isset($mapeo[$propiedad])) {
                    throw new InvalidArgumentException('Dos columnas de «' . $hoja['nombre'] . '» quedaron asignadas al mismo dato.');
                }
                $mapeo[$propiedad] = (int) $posicion;
            }
            $hoja['mapeo'] = ArticleImportColumnsNormalizer::normalize($mapeo);
            $hoja['descuentos_por_proveedor'] = $this->resolver_descuentos($hoja);
        }
        unset($hoja);

        return array_values(array_filter($hojas, function (array $hoja) {
            return array_intersect(['nombre', 'numero', 'codigo_de_barras', 'sku', 'codigo_de_proveedor'], array_keys($hoja['mapeo'])) !== [];
        }));
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
            foreach ($this->filas_crudas($ruta, $hoja['indice'], $hoja['inicio']) as $numero => $fila) {
                $datos = [];
                foreach ($hoja['mapeo'] as $campo => $posicion) {
                    $datos[$campo] = ImportHelper::getColumnValue($fila, $campo, $hoja['mapeo']);
                }
                if (array_filter($datos, function ($valor) { return $valor !== null && $valor !== ''; }) === []) {
                    continue;
                }
                // Un título de rubro o el total al pie no son artículos: no llegan ni a los repetidos ni al motor.
                $motivo = $descarta ? self::motivo_para_saltear($fila, $hoja['mapeo'], $numero === $cierre) : null;
                if ($motivo !== null) {
                    $this->anotar_salteada((int) $hoja['indice'], $motivo, $fila, $hoja['mapeo']);
                    continue;
                }
                // La ganancia que contestó el dueño, como si la planilla trajera la columna del margen.
                if (isset($hoja['margen_respondido'])) {
                    $datos[self::CLAVE_MARGEN] = $hoja['margen_respondido'];
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
            if (isset($hoja['margen_respondido'])) {
                $campos[self::CLAVE_MARGEN] = true;
            }
        }
        // `filas()` ya corrió para buscar repetidos: la cuenta de las salteadas es la de ESTA pasada.
        $this->salteadas = [];
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

        return ['ruta' => $ruta, 'filas' => $filas, 'columnas' => array_flip($columnas), 'salteadas' => $this->salteadas];
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
        return preg_match('/^(?:sub ?total(?:es)?|total(?:es)?|sumas?)(?: |$)/', self::titulo_comparable($texto)) === 1;
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
    public static function motivo_para_saltear(array $fila, array $mapeo, bool $es_el_cierre = false): ?string
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

        if ($es_el_cierre && $columna_del_nombre !== null && isset($con_dato[$columna_del_nombre])
            && self::empieza_como_total($con_dato[$columna_del_nombre])) {
            $resto = array_diff_key($con_dato, [$columna_del_nombre => true]);
            if ($resto === []) {
                return self::FILA_TOTAL;
            }
            $otra = (int) array_key_first($resto);
            if (!isset($columnas_de_codigo[$otra]) && self::importe($resto[$otra]) !== null) {
                return self::FILA_TOTAL;
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
    public static function pregunta_del_margen(array $hojas): array
    {
        if (array_filter($hojas, [self::class, 'hoja_necesita_margen']) === []) {
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
            'texto' => 'Tu lista trae el costo pero no dice a cuánto vendés. ¿Qué ganancia le sumamos a todos para calcular el precio de venta?'
                . ' Puede ser ' . implode(' %, ', self::MARGENES_SUGERIDOS) . ' % u otro porcentaje que me digas.'
                . ' Si preferís cargar los precios vos después, decímelo y dejo solo el costo.',
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
            unset($hoja['margen_respondido']);
            if ($margen !== null && self::hoja_necesita_margen($hoja)) {
                $hoja['margen_respondido'] = $margen;
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
