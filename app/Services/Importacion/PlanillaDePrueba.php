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

    private $run;
    private $propiedades;
    private $nombres;
    private $proveedores;

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

    private static function titulo_comparable($titulo): string
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
        foreach ($hojas as $hoja) {
            ExcelWorkbookReader::asegurar_csv($ruta, $hoja['indice']);
            foreach ($this->filas_crudas($ruta, $hoja['indice'], $hoja['inicio']) as $numero => $fila) {
                $datos = [];
                foreach ($hoja['mapeo'] as $campo => $posicion) {
                    $datos[$campo] = ImportHelper::getColumnValue($fila, $campo, $hoja['mapeo']);
                }
                if (array_filter($datos, function ($valor) { return $valor !== null && $valor !== ''; }) === []) {
                    continue;
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
                $ordenada = array_map(function ($campo) use ($datos) { return $datos[$campo] ?? ''; }, $columnas);
                self::escribir_fila($csv, $ordenada);
                $filas++;
            }
        } finally {
            fclose($csv);
        }

        return ['ruta' => $ruta, 'filas' => $filas, 'columnas' => array_flip($columnas)];
    }

    private static function escribir_fila($csv, array $fila): void
    {
        if (fputcsv($csv, $fila, ',', '"', '') === false) {
            throw new RuntimeException('No se pudo escribir una fila de la planilla preparada.');
        }
    }
}
