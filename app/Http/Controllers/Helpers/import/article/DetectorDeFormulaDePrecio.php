<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\LocalImportHelper;

/**
 * Deduce, sin IA, cómo calcula el usuario su precio de venta a partir de las columnas del Excel
 * (misión importacion-formula-precio, 20/11/2026).
 *
 * El objetivo es el precio final que el comercio cobra. Se buscan candidatos a «precio de venta»
 * y a «costo base», y se prueban las combinaciones de las variables que el sistema sabe aplicar:
 *
 *     precio = costo × descuentos ÷ unidades individuales × (1 + margen) × (1 + IVA)
 *
 * sobre una muestra de filas. Gana la combinación que reproduce el precio del Excel en más filas
 * (con tolerancia de 1 %); ante empate, la que usa menos variables. El resultado trae una oración
 * para que el usuario confirme, las columnas que intervienen, las columnas intermedias (pasos del
 * cálculo que no hay que importar, como «costo con descuentos» o «precio sin IVA») y el mapeo
 * que corresponde en el sistema.
 *
 * Todo es aritmética sobre una muestra: no consume créditos de IA y corre en milisegundos.
 */
class DetectorDeFormulaDePrecio
{
    /** Tolerancia relativa para considerar que una fila «cierra» (1 %). */
    const TOLERANCIA = 0.01;

    /** Diferencia de tasa de acierto que se considera empate entre dos fórmulas (2 %). */
    const EMPATE = 0.02;

    /** Porcentaje mínimo de filas que tienen que cerrar para proponer la fórmula. */
    const MINIMO_PARA_PROPONER = 0.6;

    /** Palabras que sugieren el precio de venta final. */
    const PATRON_PRECIO = '/(total|final|venta|p\.?\s*v\.?\b|pvp|publico|público|c\s*[\/\\\\]\s*iva|con\s+iva|precio)/iu';

    /** Palabras que sugieren el costo. */
    const PATRON_COSTO = '/(costo|base|lista|compra|neto|importe|proveedor)/iu';

    /** Palabras que sugieren los descuentos. */
    const PATRON_DESCUENTOS = '/(desc|bonif|dto)/iu';

    /** Palabras que sugieren el margen de ganancia. */
    const PATRON_MARGEN = '/(benef|margen|ganancia|utilidad|markup|remarc)/iu';

    /** Palabras que sugieren el IVA. */
    const PATRON_IVA = '/(iva|alic)/iu';

    /** Palabras que sugieren las unidades individuales (bulto de compra). */
    const PATRON_UNIDADES = '/(eq\.?\s*c|equiv|unid|bulto|pack|x\s*caja|u\.?\s*x|cant.*caja|u\.?\s*indiv)/iu';

    /**
     * Encabezados del Excel, por índice 0-based.
     * @var array<int, string>
     */
    protected $encabezados;

    /**
     * Filas de muestra: arrays de celdas por índice 0-based.
     * @var array<int, array>
     */
    protected $filas;

    /**
     * Mapeo confirmado por el usuario: [system_property => índice 0-based].
     * @var array<string, int>
     */
    protected $mapeo;

    /**
     * Índices de las columnas numéricas (se calcula una sola vez).
     * @var int[]|null
     */
    protected $cache_numericas = null;

    /**
     * Primeras filas de la muestra, para descartar rápido las fórmulas que no cierran.
     * @var array<int, array>
     */
    protected $filas_rapidas = [];

    /**
     * @param array $encabezados  [índice => texto del encabezado]
     * @param array $filas        Muestra de filas (arrays de celdas).
     * @param array $mapeo        [system_property => índice] del mapeo confirmado.
     */
    public function __construct(array $encabezados, array $filas, array $mapeo = [])
    {
        $this->encabezados = $encabezados;
        $this->filas = $filas;
        $this->mapeo = $mapeo;
        $this->filas_rapidas = array_slice($this->filas, 0, 40, true);
    }

    /**
     * Corre la detección.
     *
     * @return array|null  null si no hay suficientes datos para proponer nada.
     */
    public function detectar()
    {
        if (count($this->filas) < 5) {
            return null;
        }

        $numericas = $this->columnas_numericas();

        $precios = $this->candidatos($numericas, self::PATRON_PRECIO, 'precio');
        $costos = $this->candidatos($numericas, self::PATRON_COSTO, 'costo');
        $descuentos = $this->con_efecto($this->candidatos_de_descuentos(), 'descuento');
        $margenes = $this->con_efecto($this->candidatos_porcentuales(self::PATRON_MARGEN, 'margen_de_ganancia', 0, 1000), 'margen');
        $ivas = $this->con_efecto($this->candidatos_de_iva(), 'iva');
        $unidades = $this->con_efecto($this->candidatos_de_unidades($numericas), 'unidad');

        $mejor = null;

        foreach ($precios as $precio) {
            foreach ($costos as $costo) {
                if ($costo === $precio) {
                    continue;
                }

                // null = la variable no interviene. Se prueban todas las combinaciones.
                foreach (array_merge([null], $descuentos) as $descuento) {
                    foreach ($descuento === null ? [null] : ['con_signo', 'del_sistema'] as $lectura) {
                        foreach (array_merge([null], $unidades) as $unidad) {
                            foreach (array_merge([null], $margenes) as $margen) {
                                foreach (array_merge([null], $ivas) as $iva) {
                                    $formula = compact('precio', 'costo', 'descuento', 'lectura', 'unidad', 'margen', 'iva');

                                    // Descarte rápido: si en las primeras 40 filas no cierra ni un
                                    // tercio, no vale la pena recorrer toda la muestra.
                                    $rapido = $this->evaluar($formula, $this->filas_rapidas);
                                    if ($rapido['evaluadas'] === 0 || $rapido['tasa'] < 0.3) {
                                        continue;
                                    }

                                    $resultado = $this->evaluar($formula);

                                    if ($this->es_mejor($resultado, $mejor)) {
                                        $mejor = $resultado;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (is_null($mejor) || $mejor['tasa'] < self::MINIMO_PARA_PROPONER) {
            return [
                'encontrada' => false,
                'oracion'    => 'No pudimos deducir cómo se llega al precio de venta con las columnas del Excel. '
                              . 'Revisá que estén indicadas las columnas de costo, margen y precio.',
            ];
        }

        return $this->armar_respuesta($mejor);
    }

    /**
     * Evalúa una fórmula sobre la muestra.
     *
     * @param  array      $formula
     * @param  array|null $filas  Filas sobre las que se evalúa (default: toda la muestra).
     * @return array  La fórmula con 'evaluadas', 'cierran', 'tasa', 'excepciones' y 'variables'.
     */
    protected function evaluar(array $formula, $filas = null)
    {
        $evaluadas = 0;
        $cierran = 0;
        $excepciones = [];

        foreach (is_null($filas) ? $this->filas : $filas as $numero => $fila) {
            $precio = $this->numero($fila, $formula['precio']);
            $calculado = $this->calcular($fila, $formula);

            if (is_null($precio) || $precio <= 0 || is_null($calculado)) {
                continue;
            }

            $evaluadas++;

            if (abs($calculado - $precio) <= self::TOLERANCIA * $precio + 0.01) {
                $cierran++;
            } else if (count($excepciones) < 5) {
                $excepciones[] = ['fila' => $numero, 'excel' => round($precio, 2), 'calculado' => round($calculado, 2)];
            }
        }

        $formula['evaluadas'] = $evaluadas;
        $formula['cierran'] = $cierran;
        $formula['tasa'] = $evaluadas > 0 ? $cierran / $evaluadas : 0;
        $formula['excepciones'] = $excepciones;
        $formula['variables'] = count(array_filter([$formula['descuento'], $formula['unidad'], $formula['margen'], $formula['iva']], function ($v) {
            return !is_null($v);
        }));

        return $formula;
    }

    /**
     * Precio que da la fórmula para una fila, o null si falta algún dato.
     *
     * @param  array $fila
     * @param  array $formula
     * @param  string $hasta  Corta el cálculo en un paso intermedio: 'descuento' | 'unidad' | 'margen' | null.
     * @return float|null
     */
    protected function calcular(array $fila, array $formula, $hasta = null)
    {
        $valor = $this->numero($fila, $formula['costo']);
        if (is_null($valor) || $valor <= 0) {
            return null;
        }

        if (!is_null($formula['descuento'])) {
            $celda = $fila[$formula['descuento']] ?? null;
            $multiplicador = (is_null($celda) || trim((string) $celda) === '')
                ? 1.0
                : DescuentosDelExcel::multiplicador($celda, $formula['lectura']);
            if (is_null($multiplicador)) {
                return null;
            }
            $valor *= $multiplicador;
        }
        if ($hasta === 'descuento') {
            return $valor;
        }

        if (!is_null($formula['unidad'])) {
            $unidades = $this->numero($fila, $formula['unidad']);
            if (!is_null($unidades) && $unidades > 0) {
                $valor /= $unidades;
            }
        }
        if ($hasta === 'unidad') {
            return $valor;
        }

        if (!is_null($formula['margen'])) {
            $valor *= 1 + ($this->numero($fila, $formula['margen']) ?? 0) / 100;
        }
        if ($hasta === 'margen') {
            return $valor;
        }

        if (!is_null($formula['iva'])) {
            $iva = $this->iva($fila, $formula['iva']);
            if (is_null($iva)) {
                return null;
            }
            $valor *= 1 + $iva / 100;
        }

        return $valor;
    }

    /**
     * ¿El resultado nuevo le gana al mejor hasta ahora?
     *  1. Más filas que cierran (diferencias menores a EMPATE cuentan como empate: unas pocas
     *     filas con redondeos o impuestos internos no deberían decidir la fórmula).
     *  2. El precio objetivo más «final»: el mapeado, después el que se llama total / venta /
     *     con IVA (ver rango_de_precio()). El objetivo es el precio que cobra el comercio, no
     *     un paso intermedio como el precio sin IVA.
     *  3. MÁS variables: a igualdad de filas, la cuenta que arranca desde el costo más de base
     *     (antes de descuentos) explica mejor cómo se forma el precio. Las variables que no
     *     cambian nada (descuento vacío, unidades siempre 1) ya se descartaron como candidatas.
     *  4. Más columnas coincidentes con el mapeo confirmado.
     *
     * @param  array      $nuevo
     * @param  array|null $mejor
     * @return bool
     */
    protected function es_mejor(array $nuevo, $mejor)
    {
        if ($nuevo['evaluadas'] === 0) {
            return false;
        }
        if (is_null($mejor)) {
            return true;
        }
        if ($nuevo['tasa'] > $mejor['tasa'] + self::EMPATE) {
            return true;
        }
        if ($nuevo['tasa'] < $mejor['tasa'] - self::EMPATE) {
            return false;
        }
        $rango_nuevo = $this->rango_de_precio($nuevo['precio']);
        $rango_mejor = $this->rango_de_precio($mejor['precio']);
        if ($rango_nuevo !== $rango_mejor) {
            return $rango_nuevo < $rango_mejor;
        }
        if ($nuevo['variables'] !== $mejor['variables']) {
            return $nuevo['variables'] > $mejor['variables'];
        }
        return $this->puntaje_de_mapeo($nuevo) > $this->puntaje_de_mapeo($mejor);
    }

    /**
     * Qué tan «final» es una columna de precio (menor = más final):
     * 0 = la que mapeó el usuario como precio; 1 = total, final, venta o público;
     * 2 = «con IVA»; 3 = cualquier otro precio; 4 = sin IVA o neto.
     *
     * @param  int $indice
     * @return int
     */
    protected function rango_de_precio($indice)
    {
        if (($this->mapeo['precio'] ?? null) === $indice) {
            return 0;
        }
        $encabezado = (string) ($this->encabezados[$indice] ?? '');
        if (preg_match('/(sin\s+iva|s\s*[\/\\\\]\s*iva|neto)/iu', $encabezado)) {
            return 4;
        }
        if (preg_match('/(total|final|venta|pvp|publico|público)/iu', $encabezado)) {
            return 1;
        }
        if (preg_match('/(c\s*[\/\\\\]\s*iva|con\s+iva)/iu', $encabezado)) {
            return 2;
        }
        return 3;
    }

    /**
     * ¿La columna cambia el precio en alguna fila de la muestra? Una columna de unidades que
     * siempre vale 1, o un margen siempre en 0, no es una variable de la fórmula.
     *
     * @param  int    $indice
     * @param  string $tipo  'descuento' | 'unidad' | 'margen' | 'iva'
     * @return bool
     */
    protected function tiene_efecto($indice, $tipo)
    {
        foreach ($this->filas as $fila) {
            if ($tipo === 'descuento') {
                $multiplicador = DescuentosDelExcel::multiplicador($fila[$indice] ?? null, 'con_signo');
                if (!is_null($multiplicador) && abs($multiplicador - 1) > 0.0001) {
                    return true;
                }
                continue;
            }
            $valor = $tipo === 'iva' ? $this->iva($fila, $indice) : $this->numero($fila, $indice);
            if (is_null($valor)) {
                continue;
            }
            if ($tipo === 'unidad' ? $valor > 1 : abs($valor) > 0.0001) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deja solo los candidatos que cambian el precio en alguna fila.
     *
     * @param  int[]  $candidatos
     * @param  string $tipo
     * @return int[]
     */
    protected function con_efecto(array $candidatos, $tipo)
    {
        return array_values(array_filter($candidatos, function ($indice) use ($tipo) {
            return $this->tiene_efecto($indice, $tipo);
        }));
    }

    /**
     * Cuántas columnas de la fórmula coinciden con el mapeo confirmado (desempate).
     *
     * @param  array $formula
     * @return int
     */
    protected function puntaje_de_mapeo(array $formula)
    {
        $puntaje = 0;
        foreach (self::propiedades_del_sistema() as $clave => $propiedad) {
            if (!is_null($formula[$clave]) && ($this->mapeo[$propiedad] ?? null) === $formula[$clave]) {
                $puntaje++;
            }
        }
        return $puntaje;
    }

    /**
     * Qué propiedad del sistema le corresponde a cada variable de la fórmula.
     *
     * @return array<string, string>
     */
    public static function propiedades_del_sistema()
    {
        return [
            'costo'     => 'costo',
            'descuento' => 'descuentos',
            'unidad'    => 'u_individuales',
            'margen'    => 'margen_de_ganancia',
            'iva'       => 'iva',
            'precio'    => 'precio',
        ];
    }

    /**
     * Arma la respuesta para la pantalla: oración, columnas, intermedias y mapeo sugerido.
     *
     * @param  array $mejor
     * @return array
     */
    protected function armar_respuesta(array $mejor)
    {
        $partes = [];
        $partes[] = 'se parte del costo ' . $this->nombre($mejor['costo']);
        if (!is_null($mejor['descuento'])) {
            $partes[] = 'se le aplican los descuentos de ' . $this->nombre($mejor['descuento'])
                . ($mejor['lectura'] === 'con_signo'
                    ? ' (escritos con signo: «-25» baja el costo un 25 % y «5» lo sube un 5 %)'
                    : '');
        }
        if (!is_null($mejor['unidad'])) {
            $partes[] = 'se divide por las unidades individuales de ' . $this->nombre($mejor['unidad']);
        }
        if (!is_null($mejor['margen'])) {
            $partes[] = 'se le suma el margen de ganancia de ' . $this->nombre($mejor['margen']);
        }
        if (!is_null($mejor['iva'])) {
            $partes[] = 'se le suma el IVA de ' . $this->nombre($mejor['iva']);
        }

        $ultima = array_pop($partes);
        $pasos = empty($partes) ? $ultima : implode(', ', $partes) . ' y ' . $ultima;

        $oracion = 'Para llegar al precio de venta ' . $this->nombre($mejor['precio']) . ', ' . $pasos . '. '
                 . 'Esta cuenta coincide en ' . $mejor['cierran'] . ' de ' . $mejor['evaluadas']
                 . ' filas revisadas (' . round($mejor['tasa'] * 100) . ' %).';

        /*
         * Mapeo que corresponde en el sistema para cada variable que interviene.
         *
         * 🔴 Con margen, el precio NO se mapea: el sistema calcula el precio desde el costo y el
         * margen, y si se importan los dos el margen gana y el precio se descarta (ver
         * ArticleHelper). El precio del Excel queda como lo que es acá: el resultado contra el que
         * se verificó la cuenta. null en el mapeo sugerido = «no importar esta propiedad».
         */
        $mapeo_sugerido = [];
        foreach (self::propiedades_del_sistema() as $clave => $propiedad) {
            if (is_null($mejor[$clave])) {
                continue;
            }
            $mapeo_sugerido[$propiedad] = $mejor[$clave];
        }
        if (!is_null($mejor['margen'])) {
            $mapeo_sugerido['precio'] = null;
        }

        $diferencias = [];
        foreach ($mapeo_sugerido as $propiedad => $sugerida) {
            $actual = $this->mapeo[$propiedad] ?? null;
            if ($actual === $sugerida) {
                continue;
            }
            $diferencias[] = [
                'propiedad'        => $propiedad,
                'columna_sugerida' => $sugerida,
                'nombre_sugerido'  => is_null($sugerida) ? null : $this->encabezado_limpio($sugerida),
                'letra_sugerida'   => is_null($sugerida) ? null : self::letra($sugerida),
                'columna_actual'   => $actual,
                'nombre_actual'    => is_null($actual) ? null : $this->encabezado_limpio($actual),
                'letra_actual'     => is_null($actual) ? null : self::letra($actual),
            ];
        }

        return [
            'encontrada'      => true,
            'oracion'         => $oracion,
            'tasa'            => round($mejor['tasa'], 4),
            'filas_revisadas' => $mejor['evaluadas'],
            'filas_que_cierran' => $mejor['cierran'],
            'lectura_de_descuentos' => $mejor['lectura'],
            'columnas'        => array_map(function ($indice) {
                return is_null($indice) ? null : ['indice' => $indice, 'letra' => self::letra($indice), 'nombre' => $this->encabezado_limpio($indice)];
            }, array_intersect_key($mejor, self::propiedades_del_sistema())),
            'intermedias'     => $this->columnas_intermedias($mejor),
            'mapeo_sugerido'  => $mapeo_sugerido,
            'diferencias_con_el_mapeo' => $diferencias,
            'excepciones'     => $mejor['excepciones'],
        ];
    }

    /**
     * Columnas que son un paso intermedio del cálculo (no hay que importarlas): costo con
     * descuentos, costo unitario, precio sin IVA, o una copia del precio final.
     *
     * @param  array $formula
     * @return array
     */
    protected function columnas_intermedias(array $formula)
    {
        $usadas = array_filter(array_intersect_key($formula, self::propiedades_del_sistema()), function ($v) {
            return !is_null($v);
        });

        $pasos = [
            'descuento' => 'costo con los descuentos aplicados',
            'unidad'    => 'costo por unidad individual',
            'margen'    => 'precio sin IVA',
            null        => 'otra copia del precio de venta',
        ];

        $intermedias = [];

        foreach ($this->columnas_numericas() as $indice) {
            if (in_array($indice, $usadas, true)) {
                continue;
            }

            foreach ($pasos as $hasta => $descripcion) {
                $hasta = $hasta === '' ? null : $hasta;
                // Un paso que la fórmula no usa no genera una columna intermedia distinta.
                if (!is_null($hasta) && is_null($formula[$hasta])) {
                    continue;
                }

                $evaluadas = 0;
                $cierran = 0;
                foreach ($this->filas as $fila) {
                    $valor = $this->numero($fila, $indice);
                    $calculado = $this->calcular($fila, $formula, $hasta);
                    if (is_null($valor) || $valor <= 0 || is_null($calculado)) {
                        continue;
                    }
                    $evaluadas++;
                    if (abs($calculado - $valor) <= self::TOLERANCIA * $valor + 0.01) {
                        $cierran++;
                    }
                }

                if ($evaluadas >= 5 && $cierran / $evaluadas >= 0.8) {
                    $intermedias[] = [
                        'indice'      => $indice,
                        'letra'       => self::letra($indice),
                        'nombre'      => $this->encabezado_limpio($indice),
                        'descripcion' => $descripcion,
                    ];
                    break;
                }
            }
        }

        return $intermedias;
    }

    /**
     * Índices de las columnas que son numéricas en al menos el 60 % de las filas con valor.
     *
     * @return int[]
     */
    protected function columnas_numericas()
    {
        if (!is_null($this->cache_numericas)) {
            return $this->cache_numericas;
        }

        $resultado = [];
        foreach (array_keys($this->encabezados) as $indice) {
            $con_valor = 0;
            $numericas = 0;
            foreach ($this->filas as $fila) {
                $celda = $fila[$indice] ?? null;
                if (is_null($celda) || trim((string) $celda) === '') {
                    continue;
                }
                $con_valor++;
                if (!is_null($this->numero($fila, $indice))) {
                    $numericas++;
                }
            }
            if ($con_valor > 0 && $numericas / $con_valor >= 0.6) {
                $resultado[] = $indice;
            }
        }

        return $this->cache_numericas = $resultado;
    }

    /**
     * Candidatos a una variable monetaria (precio o costo): las columnas numéricas cuyo
     * encabezado coincide con el patrón, más la mapeada por el usuario.
     *
     * @param  int[]  $numericas
     * @param  string $patron
     * @param  string $propiedad
     * @return int[]
     */
    protected function candidatos(array $numericas, $patron, $propiedad)
    {
        $candidatos = [];

        if (isset($this->mapeo[$propiedad]) && in_array($this->mapeo[$propiedad], $numericas, true)) {
            $candidatos[] = $this->mapeo[$propiedad];
        }

        // Primero los encabezados más explícitos («total», «final», «venta» para el precio;
        // «costo», «base» para el costo): ante un empate gana el que se evaluó primero.
        $preferidos = $propiedad === 'precio'
            ? '/(total|final|venta|pvp|publico|público)/iu'
            : '/(costo|base|lista)/iu';

        foreach ([true, false] as $solo_preferidos) {
            foreach ($numericas as $indice) {
                $encabezado = $this->encabezados[$indice] ?? '';
                if (!preg_match($patron, $encabezado) || in_array($indice, $candidatos, true)) {
                    continue;
                }
                if ($solo_preferidos && !preg_match($preferidos, $encabezado)) {
                    continue;
                }
                $candidatos[] = $indice;
            }
        }

        return array_slice($candidatos, 0, 6);
    }

    /**
     * Candidatos a descuentos: la columna mapeada y las que se llaman como descuento.
     *
     * @return int[]
     */
    protected function candidatos_de_descuentos()
    {
        $candidatos = [];
        if (isset($this->mapeo['descuentos'])) {
            $candidatos[] = $this->mapeo['descuentos'];
        }
        foreach ($this->encabezados as $indice => $encabezado) {
            if (
                preg_match(self::PATRON_DESCUENTOS, (string) $encabezado)
                && !in_array($indice, $candidatos, true)
                && $this->parece_de_descuentos($indice)
            ) {
                $candidatos[] = $indice;
            }
        }
        return array_slice($candidatos, 0, 3);
    }

    /**
     * Candidatos a una variable porcentual (margen): numérica, en rango, con el nombre esperado.
     *
     * @param  string $patron
     * @param  string $propiedad
     * @param  float  $minimo
     * @param  float  $maximo
     * @return int[]
     */
    protected function candidatos_porcentuales($patron, $propiedad, $minimo, $maximo)
    {
        $candidatos = [];
        if (isset($this->mapeo[$propiedad])) {
            $candidatos[] = $this->mapeo[$propiedad];
        }
        foreach ($this->columnas_numericas() as $indice) {
            if (!preg_match($patron, $this->encabezados[$indice] ?? '') || in_array($indice, $candidatos, true)) {
                continue;
            }
            if ($this->en_rango($indice, $minimo, $maximo)) {
                $candidatos[] = $indice;
            }
        }
        return array_slice($candidatos, 0, 3);
    }

    /**
     * Candidatos a IVA: columnas que se llaman IVA y cuyos valores (limpios de «%») son
     * alícuotas plausibles (0 a 27).
     *
     * @return int[]
     */
    protected function candidatos_de_iva()
    {
        $candidatos = [];
        if (isset($this->mapeo['iva'])) {
            $candidatos[] = $this->mapeo['iva'];
        }
        foreach ($this->encabezados as $indice => $encabezado) {
            if (!preg_match(self::PATRON_IVA, (string) $encabezado) || in_array($indice, $candidatos, true)) {
                continue;
            }
            $validas = 0;
            $con_valor = 0;
            foreach ($this->filas as $fila) {
                $celda = $fila[$indice] ?? null;
                if (is_null($celda) || trim((string) $celda) === '') {
                    continue;
                }
                $con_valor++;
                $iva = $this->iva($fila, $indice);
                if (!is_null($iva) && $iva >= 0 && $iva <= 27) {
                    $validas++;
                }
            }
            if ($con_valor > 0 && $validas / $con_valor >= 0.8) {
                $candidatos[] = $indice;
            }
        }
        return array_slice($candidatos, 0, 3);
    }

    /**
     * Candidatos a unidades individuales: enteros ≥ 1, con nombre de equivalencia o bulto, y
     * que en alguna fila valgan más de 1 (si siempre es 1 no cambia el precio).
     *
     * @param  int[] $numericas
     * @return int[]
     */
    protected function candidatos_de_unidades(array $numericas)
    {
        $candidatos = [];
        if (isset($this->mapeo['u_individuales'])) {
            $candidatos[] = $this->mapeo['u_individuales'];
        }
        foreach ($numericas as $indice) {
            if (!preg_match(self::PATRON_UNIDADES, $this->encabezados[$indice] ?? '') || in_array($indice, $candidatos, true)) {
                continue;
            }
            if ($this->en_rango($indice, 1, 100000)) {
                $candidatos[] = $indice;
            }
        }
        return array_slice($candidatos, 0, 3);
    }

    /**
     * ¿Los valores numéricos de la columna están en el rango? Alcanza con el 90 %: un margen
     * mal cargado en una fila no descalifica la columna entera.
     *
     * @param  int   $indice
     * @param  float $minimo
     * @param  float $maximo
     * @return bool
     */
    protected function en_rango($indice, $minimo, $maximo)
    {
        $con_valor = 0;
        $dentro = 0;
        foreach ($this->filas as $fila) {
            $valor = $this->numero($fila, $indice);
            if (is_null($valor)) {
                continue;
            }
            $con_valor++;
            if ($valor >= $minimo && $valor <= $maximo) {
                $dentro++;
            }
        }
        return $con_valor > 0 && $dentro / $con_valor >= 0.9;
    }

    /**
     * ¿La columna parece de porcentajes de descuento? Cada parte entre −100 y 100 en el 90 % de
     * las celdas con valor. Descarta columnas monetarias que se llaman «…Descuento».
     *
     * @param  int $indice
     * @return bool
     */
    protected function parece_de_descuentos($indice)
    {
        $con_valor = 0;
        $validas = 0;
        foreach ($this->filas as $fila) {
            $partes = DescuentosDelExcel::separar($fila[$indice] ?? null);
            if (empty($partes)) {
                continue;
            }
            $con_valor++;
            $ok = true;
            foreach ($partes as $parte) {
                $valor = DescuentosDelExcel::valor_aproximado($parte);
                if (is_null($valor) || $valor < -100 || $valor > 100) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $validas++;
            }
        }
        return $con_valor > 0 && $validas / $con_valor >= 0.9;
    }

    /**
     * Número de una celda (tolera coma decimal, «$» y «%»). null si no es un número.
     *
     * @param  array    $fila
     * @param  int|null $indice
     * @return float|null
     */
    protected function numero(array $fila, $indice)
    {
        if (is_null($indice)) {
            return null;
        }
        $celda = $fila[$indice] ?? null;
        if (is_int($celda) || is_float($celda)) {
            return (float) $celda;
        }
        if (is_null($celda)) {
            return null;
        }
        $texto = str_replace(['$', '%', ' ', "'"], '', trim((string) $celda));
        // «1.234,56» → 1234.56; «12,5» → 12.5.
        if (strpos($texto, ',') !== false) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }
        return is_numeric($texto) ? (float) $texto : null;
    }

    /**
     * Alícuota de IVA de una celda, con la misma limpieza que usa la importación.
     *
     * @param  array $fila
     * @param  int   $indice
     * @return float|null
     */
    protected function iva(array $fila, $indice)
    {
        $texto = LocalImportHelper::normalizar_porcentaje_de_iva($fila[$indice] ?? '');
        return is_numeric($texto) ? (float) $texto : null;
    }

    /**
     * Encabezado y letra de una columna, para la oración: «Import. TOTAL» (columna M).
     *
     * @param  int $indice
     * @return string
     */
    protected function nombre($indice)
    {
        return '«' . $this->encabezado_limpio($indice) . '» (columna ' . self::letra($indice) . ')';
    }

    /**
     * Encabezado de una columna en una sola línea («Importe\nBase» → «Importe Base»).
     *
     * @param  int $indice
     * @return string
     */
    protected function encabezado_limpio($indice)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) ($this->encabezados[$indice] ?? '')));
    }

    /**
     * Letra de Excel de un índice 0-based: 0 → A, 25 → Z, 26 → AA.
     *
     * @param  int $indice
     * @return string
     */
    public static function letra($indice)
    {
        $letra = '';
        $numero = (int) $indice + 1;
        while ($numero > 0) {
            $resto = ($numero - 1) % 26;
            $letra = chr(65 + $resto) . $letra;
            $numero = intdiv($numero - 1, 26);
        }
        return $letra;
    }
}
