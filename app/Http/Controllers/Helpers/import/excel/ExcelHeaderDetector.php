<?php

namespace App\Http\Controllers\Helpers\import\excel;

/**
 * Decide cual de las primeras filas de la hoja es el encabezado.
 *
 * El defecto que arregla: con un titulo ("LISTA DE PRECIOS AGOSTO 2026") y la razon
 * social arriba de la tabla, la regla vieja —"encabezado = primera fila con algun
 * contenido"— tomaba el titulo como encabezado, mapeaba las columnas contra una sola
 * celda y contaba el titulo y la razon social como filas de datos. Todo en silencio.
 *
 * 🔴 SI CAMBIAS ESTA REGLA, CAMBIA TAMBIEN LA DE
 * `empresa-spa/src/components/listado/modals/ai-excel-import/Index.vue`, metodo
 * `detect_header_row()` — es el mismo invariante decidido en dos lenguajes. El navegador
 * calcula `start_row` con su copia y el backend arma el mapeo con esta; si divergen se
 * llega al peor escenario posible: el mapeo armado con la fila 4 y la importacion
 * arrancando en la fila 2, sin ningun error visible. Es la clase de error
 * "el mismo invariante decidido con dos criterios distintos en front y back" de
 * contexto/APRENDER_NO_PARCHEAR.md (12/8/2026).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class ExcelHeaderDetector
{
    /** Filas FISICAS del Excel que se miran (con filas vacias incluidas). */
    const VENTANA = 20;

    /** Largo maximo, en caracteres, de una celda para que la fila siga siendo candidata. */
    const LARGO_MAXIMO_DE_CELDA = 40;

    /**
     * Primeras $limite filas fisicas de la hoja, con las fusiones ya propagadas.
     *
     * Las filas se leen con preservar_filas_vacias = true a proposito: la clave del array
     * es el numero de fila REAL del Excel, el mismo que ve el usuario y el mismo que despues
     * usa start_row. Si se leyera salteando vacias, la fila 4 del Excel seria la 3 del array
     * y todos los numeros que se le muestran al usuario quedarian corridos.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja  0-based
     * @param  int    $limite
     * @return array  [numero_fila_1based => [valores string...]]
     *
     * @throws \RuntimeException  si el archivo no abre (viene de ExcelWorkbookReader)
     */
    public static function leer_ventana($excel_path, $indice_hoja, $limite = self::VENTANA)
    {
        $detalle = self::leer_ventana_con_detalle($excel_path, $indice_hoja, $limite);

        return $detalle['ventana'];
    }

    /**
     * Aplica la regla de deteccion sobre una ventana ya leida.
     *
     * @param  array $ventana  [numero_fila_1based => [valores...]]
     * @return array ['fila'=>int, 'motivo'=>string, 'confianza'=>'alta'|'baja',
     *                'columnas'=>[string...], 'columnas_sin_nombre'=>[int...]]
     */
    public static function detectar(array $ventana)
    {
        ksort($ventana, SORT_NUMERIC);

        $primera_con_contenido = null;
        $mejor_fila            = null;
        $mejor_cantidad        = 0;

        foreach ($ventana as $numero_fila => $valores) {
            $no_vacias = self::celdas_no_vacias($valores);

            if (count($no_vacias) > 0 && $primera_con_contenido === null) {
                $primera_con_contenido = (int) $numero_fila;
            }

            /*
             * CORTE POR FILA DE DATOS. Es la mitad de la regla que la vuelve segura, y la
             * que a alguien le va a dar ganas de sacar por "innecesaria": sin este corte,
             * una planilla donde una fila de datos tenga mas celdas llenas que el
             * encabezado gana por cantidad y se elige una fila de datos como encabezado.
             * Con el corte, en cuanto aparece la primera fila que ya son datos (>= 2
             * celdas llenas y al menos una numerica o una fecha) dejamos de mirar: el
             * encabezado no puede estar debajo de los datos.
             */
            if (count($no_vacias) >= 2 && self::alguna_es_numerica_o_fecha($no_vacias)) {
                break;
            }

            if (!self::es_candidata($no_vacias)) {
                continue;
            }

            /* Empate por cantidad => gana la de mas arriba, por eso el > estricto. */
            if (count($no_vacias) > $mejor_cantidad) {
                $mejor_cantidad = count($no_vacias);
                $mejor_fila     = (int) $numero_fila;
            }
        }

        if ($primera_con_contenido === null) {
            $primera_con_contenido = 1;
        }

        if ($mejor_fila === null) {
            /*
             * Sin candidata clara se devuelve el resultado de la regla vieja (primera fila
             * con algun contenido), exactamente lo de hoy. La confianza baja es lo que hace
             * que la SPA muestre el campo de fila de encabezado abierto y resaltado en vez
             * de escondido: si no sabemos, que decida el usuario.
             */
            return self::armar_resultado($ventana, $primera_con_contenido, 'sin_candidata_clara', 'baja');
        }

        $motivo = ($mejor_fila === $primera_con_contenido)
            ? 'primera_fila_con_contenido'
            : 'encabezado_corrido';

        return self::armar_resultado($ventana, $mejor_fila, $motivo, 'alta');
    }

    /**
     * Atajo leer_ventana + detectar.
     *
     * Suma al resultado de detectar() la clave 'fusiones_aplicadas' (cuantas celdas del
     * encabezado se llenaron propagando una fusion), que solo se sabe leyendo el archivo.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @return array
     */
    public static function detectar_en($excel_path, $indice_hoja = 0)
    {
        $detalle = self::leer_ventana_con_detalle($excel_path, $indice_hoja, self::VENTANA);

        $resultado = self::detectar($detalle['ventana']);

        $resultado['fusiones_aplicadas'] = $detalle['fusiones_aplicadas'];

        return $resultado;
    }

    /**
     * Lee la ventana y ademas informa cuantas celdas se llenaron propagando fusiones.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @param  int    $limite
     * @return array  ['ventana' => array, 'fusiones_aplicadas' => int]
     */
    protected static function leer_ventana_con_detalle($excel_path, $indice_hoja, $limite)
    {
        $limite = (int) $limite;

        if ($limite < 1) {
            $limite = self::VENTANA;
        }

        $ventana = [];

        $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, true);

        $numero_fila = 0;

        foreach ($lectura->filas() as $row) {
            $numero_fila++;

            $valores = [];

            foreach ($row->getCells() as $cell) {
                $valor = $cell->getValue();

                if ($valor instanceof \DateTime) {
                    $valor = $valor->format('Y-m-d');
                }

                $valores[] = (string) ($valor === null ? '' : $valor);
            }

            $ventana[$numero_fila] = $valores;

            if ($numero_fila >= $limite) {
                break;
            }
        }

        $lectura->cerrar();

        return self::propagar_fusiones($excel_path, $indice_hoja, $ventana, $limite);
    }

    /**
     * Copia el valor de la celda ancla de cada rango fusionado a las celdas cubiertas que
     * quedaron vacias, dentro de la ventana.
     *
     * ALCANCE ACOTADO A PROPOSITO: solo la ventana de encabezado. NO se propagan fusiones
     * en las filas de datos. Una fusion vertical de categoria a lo largo de 200 filas es
     * otra feature, con otro riesgo, y no es lo que se vino a arreglar. Queda escrito aca
     * para que el proximo no crea que se olvidaron.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @param  array  $ventana
     * @param  int    $limite
     * @return array  ['ventana' => array, 'fusiones_aplicadas' => int]
     */
    protected static function propagar_fusiones($excel_path, $indice_hoja, array $ventana, $limite)
    {
        $todas = ExcelSheetInspector::fusiones($excel_path);

        if ($todas === null || !isset($todas[$indice_hoja])) {
            /* Sin fusiones legibles se sigue igual: las columnas vacias se reportan despues. */
            return ['ventana' => $ventana, 'fusiones_aplicadas' => 0];
        }

        $aplicadas = 0;

        foreach ($todas[$indice_hoja] as $rango) {
            if ($rango['fila_desde'] > $limite) {
                continue;
            }

            if (!isset($ventana[$rango['fila_desde']])) {
                continue;
            }

            $ancla = isset($ventana[$rango['fila_desde']][$rango['col_desde']])
                ? $ventana[$rango['fila_desde']][$rango['col_desde']]
                : '';

            if (trim($ancla) === '') {
                continue;
            }

            $fila_hasta = min($rango['fila_hasta'], $limite);

            for ($fila = $rango['fila_desde']; $fila <= $fila_hasta; $fila++) {
                if (!isset($ventana[$fila])) {
                    continue;
                }

                for ($col = $rango['col_desde']; $col <= $rango['col_hasta']; $col++) {
                    if ($fila === $rango['fila_desde'] && $col === $rango['col_desde']) {
                        continue;
                    }

                    /*
                     * La celda cubierta suele NO existir en el XML (Excel no la escribe),
                     * asi que hay que estirar la fila hasta ella. Sin esto, una cabecera
                     * fusionada E1:F1 deja la columna F sin nombre y el mapeo pierde una
                     * columna entera.
                     */
                    while (count($ventana[$fila]) <= $col) {
                        $ventana[$fila][] = '';
                    }

                    if (trim($ventana[$fila][$col]) !== '') {
                        continue;
                    }

                    $ventana[$fila][$col] = $ancla;
                    $aplicadas++;
                }
            }
        }

        return ['ventana' => $ventana, 'fusiones_aplicadas' => $aplicadas];
    }

    /**
     * Arma el resultado con las columnas del encabezado elegido y las que quedaron sin
     * nombre.
     *
     * @param  array  $ventana
     * @param  int    $fila
     * @param  string $motivo
     * @param  string $confianza
     * @return array
     */
    protected static function armar_resultado(array $ventana, $fila, $motivo, $confianza)
    {
        $ancho = self::ancho_de_la_ventana($ventana);

        $columnas            = [];
        $columnas_sin_nombre = [];

        $valores = isset($ventana[$fila]) ? $ventana[$fila] : [];

        for ($i = 0; $i < $ancho; $i++) {
            $valor = isset($valores[$i]) ? trim((string) $valores[$i]) : '';

            $columnas[] = $valor;

            if ($valor === '') {
                $columnas_sin_nombre[] = $i;
            }
        }

        return [
            'fila'                => (int) $fila,
            'motivo'              => $motivo,
            'confianza'           => $confianza,
            'columnas'            => $columnas,
            'columnas_sin_nombre' => $columnas_sin_nombre,
        ];
    }

    /**
     * Ancho util de la ventana: la ultima columna con contenido en CUALQUIER fila, + 1.
     *
     * Se mide contra toda la ventana y no contra la fila del encabezado porque el caso
     * que hay que detectar es justamente el contrario: el encabezado tiene menos columnas
     * llenas que los datos (una cabecera fusionada que no se pudo leer deja la columna F
     * vacia mientras las filas de datos la usan). Midiendo solo el encabezado, esa columna
     * no existiria y no habria nada que avisar.
     *
     * @param  array $ventana
     * @return int
     */
    protected static function ancho_de_la_ventana(array $ventana)
    {
        $ancho = 0;

        foreach ($ventana as $valores) {
            foreach ($valores as $indice => $valor) {
                if (trim((string) $valor) !== '' && ($indice + 1) > $ancho) {
                    $ancho = $indice + 1;
                }
            }
        }

        return $ancho;
    }

    /**
     * Las cuatro condiciones de candidata de la regla.
     *
     * @param  array $no_vacias
     * @return bool
     */
    protected static function es_candidata(array $no_vacias)
    {
        if (count($no_vacias) < 2) {
            return false;
        }

        if (self::alguna_es_numerica_o_fecha($no_vacias)) {
            return false;
        }

        $normalizadas = [];

        foreach ($no_vacias as $valor) {
            $texto = trim((string) $valor);

            if (mb_strlen($texto) > self::LARGO_MAXIMO_DE_CELDA) {
                return false;
            }

            $normalizadas[] = mb_strtolower($texto);
        }

        return count(array_unique($normalizadas)) === count($normalizadas);
    }

    /**
     * @param  array $valores
     * @return array  solo las celdas con contenido
     */
    protected static function celdas_no_vacias(array $valores)
    {
        $no_vacias = [];

        foreach ($valores as $valor) {
            if ($valor instanceof \DateTime) {
                $no_vacias[] = $valor;

                continue;
            }

            if (trim((string) $valor) !== '') {
                $no_vacias[] = $valor;
            }
        }

        return $no_vacias;
    }

    /**
     * @param  array $valores
     * @return bool
     */
    protected static function alguna_es_numerica_o_fecha(array $valores)
    {
        foreach ($valores as $valor) {
            if (self::es_numerica_o_fecha($valor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed $valor
     * @return bool
     */
    protected static function es_numerica_o_fecha($valor)
    {
        if ($valor instanceof \DateTime) {
            return true;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return false;
        }

        if (is_numeric($texto)) {
            return true;
        }

        /*
         * leer_ventana() devuelve strings, asi que una fecha real del Excel llega aca
         * como 'Y-m-d' y no como \DateTime. Sin este chequeo la mitad "ni fecha" de la
         * regla seria letra muerta para todo archivo leido de disco, y una planilla con
         * una columna de fechas y ninguna columna numerica se le escaparia al corte.
         */
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1;
    }
}
