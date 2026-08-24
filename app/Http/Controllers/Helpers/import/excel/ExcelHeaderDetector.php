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
     * Piso del umbral de corte por fila de datos: por debajo de esta cantidad de celdas
     * llenas, una fila NO alcanza para cortar la busqueda por mas numeros que traiga.
     */
    const MINIMO_DE_CELDAS_PARA_CORTAR = 3;

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
     * @param  array $ventana     [numero_fila_1based => [valores...]]
     * @param  array $propagadas  [numero_fila_1based => [indice_columna => true]] celdas
     *                            que NO estaban en el archivo y las lleno propagar_fusiones()
     * @return array ['fila'=>int, 'motivo'=>string, 'confianza'=>'alta'|'baja',
     *                'columnas'=>[string...], 'columnas_sin_nombre'=>[int...]]
     */
    public static function detectar(array $ventana, array $propagadas = [])
    {
        ksort($ventana, SORT_NUMERIC);

        $umbral_de_corte = self::umbral_de_corte($ventana, $propagadas);

        $primera_con_contenido = null;
        $mejor_fila            = null;
        $mejor_cantidad        = 0;

        foreach ($ventana as $numero_fila => $valores) {
            $de_la_fila = isset($propagadas[$numero_fila]) ? $propagadas[$numero_fila] : [];

            $no_vacias  = self::celdas_no_vacias($valores);
            $originales = self::celdas_no_vacias($valores, $de_la_fila);

            if (count($no_vacias) > 0 && $primera_con_contenido === null) {
                $primera_con_contenido = (int) $numero_fila;
            }

            /*
             * CORTE POR FILA DE DATOS. Es la mitad de la regla que la vuelve segura, y la
             * que a alguien le va a dar ganas de sacar por "innecesaria": sin este corte,
             * una planilla donde una fila de datos tenga mas celdas llenas que el
             * encabezado gana por cantidad y se elige una fila de datos como encabezado.
             * Con el corte, en cuanto aparece la primera fila que ya son datos dejamos de
             * mirar: el encabezado no puede estar debajo de los datos.
             *
             * 🔴 EL UMBRAL ES RELATIVO AL ANCHO DE LA TABLA, NO ">= 2 CELDAS". Parece de mas
             * y no lo es: con ">= 2" cortaba cualquier renglon de membrete de una lista de
             * proveedor. "Distribuidora Bianchi S.A. | 30712345679" son dos celdas y una es
             * numerica (el CUIT); "Vigencia desde: | 2026-08-01", idem con la fecha. Los dos
             * mataban la busqueda en la fila 2 y el encabezado real de la fila 4 no se
             * miraba nunca — medido, daba fila=1 / sin_candidata_clara en las listas de
             * proveedor mas comunes que existen. Una fila de datos de verdad llena media
             * tabla; un membrete, dos o tres celdas sueltas. Eso es lo que separa el umbral.
             */
            if (count($originales) >= $umbral_de_corte && self::alguna_es_numerica_o_fecha($originales)) {
                break;
            }

            if (!self::es_candidata($no_vacias, $originales)) {
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

        $resultado = self::detectar($detalle['ventana'], $detalle['propagadas']);

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
     * @return array  ['ventana' => array, 'fusiones_aplicadas' => int, 'propagadas' => array]
     */
    protected static function propagar_fusiones($excel_path, $indice_hoja, array $ventana, $limite)
    {
        $todas = ExcelSheetInspector::fusiones($excel_path);

        if ($todas === null || !isset($todas[$indice_hoja])) {
            /* Sin fusiones legibles se sigue igual: las columnas vacias se reportan despues. */
            return ['ventana' => $ventana, 'fusiones_aplicadas' => 0, 'propagadas' => []];
        }

        $aplicadas  = 0;
        $propagadas = [];

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

                    /*
                     * Se anota QUE celda se lleno propagando, no solo cuantas. Sin esa marca
                     * no hay forma de que es_candidata() distinga un duplicado que trae el
                     * archivo de uno que generamos nosotros dos lineas mas arriba.
                     */
                    $propagadas[$fila][$col] = true;
                }
            }
        }

        return ['ventana' => $ventana, 'fusiones_aplicadas' => $aplicadas, 'propagadas' => $propagadas];
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
        $valores = isset($ventana[$fila]) ? $ventana[$fila] : [];

        $ancho_del_encabezado = self::extension_de_la_fila($valores);
        $usadas_por_los_datos = self::columnas_usadas_por_los_datos($ventana, $fila);

        $ancho = $ancho_del_encabezado;

        foreach ($usadas_por_los_datos as $indice => $si) {
            if (($indice + 1) > $ancho) {
                $ancho = $indice + 1;
            }
        }

        $columnas            = [];
        $columnas_sin_nombre = [];

        for ($i = 0; $i < $ancho; $i++) {
            $valor = isset($valores[$i]) ? self::texto_de($valores[$i]) : '';

            $columnas[] = $valor;

            /*
             * Una columna se denuncia como "sin nombre" solo si es un agujero ADENTRO del
             * encabezado o si las filas de datos la usan de verdad. Ver
             * columnas_usadas_por_los_datos().
             */
            if ($valor === '' && ($i < $ancho_del_encabezado || isset($usadas_por_los_datos[$i]))) {
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
     * Cantidad de celdas llenas de la fila mas ancha de la ventana, contando SOLO las
     * celdas que trae el archivo (las propagadas no cuentan).
     *
     * Las propagadas se excluyen a proposito: un titulo fusionado sobre A1:T1 propaga 20
     * celdas y, si contaran, el umbral de corte se iria a 10 en una tabla de 5 columnas y
     * ninguna fila de datos alcanzaria para cortar. El ancho que interesa es el de la
     * tabla, no el del membrete.
     *
     * @param  array $ventana
     * @param  array $propagadas
     * @return int
     */
    protected static function umbral_de_corte(array $ventana, array $propagadas)
    {
        $ancho = 0;

        foreach ($ventana as $numero_fila => $valores) {
            $de_la_fila = isset($propagadas[$numero_fila]) ? $propagadas[$numero_fila] : [];

            $cantidad = count(self::celdas_no_vacias($valores, $de_la_fila));

            if ($cantidad > $ancho) {
                $ancho = $cantidad;
            }
        }

        $mitad = (int) ceil($ancho / 2);

        return ($mitad > self::MINIMO_DE_CELDAS_PARA_CORTAR) ? $mitad : self::MINIMO_DE_CELDAS_PARA_CORTAR;
    }

    /**
     * Ultima columna con contenido de una fila, + 1.
     *
     * @param  array $valores
     * @return int
     */
    protected static function extension_de_la_fila(array $valores)
    {
        $ancho = 0;

        foreach ($valores as $indice => $valor) {
            if (self::texto_de($valor) !== '' && ($indice + 1) > $ancho) {
                $ancho = $indice + 1;
            }
        }

        return $ancho;
    }

    /**
     * Columnas que las FILAS DE DATOS usan de verdad: las que estan llenas en al menos la
     * mitad de las filas que hay debajo del encabezado, dentro de la ventana.
     *
     * 🔴 POR QUE "LA MITAD DE LAS FILAS DE DATOS" Y NO "CUALQUIER FILA DE LA VENTANA".
     * Este ancho es lo que decide `columnas_sin_nombre`, o sea la alerta amarilla del paso 2.
     * Midiendolo contra cualquier fila, una nota suelta en J3 ("Promo hasta fin de mes") —que
     * aparece en media planilla de proveedor— corria el ancho hasta la columna J y la alerta
     * salia con "Las columnas I, J no tienen nombre en el encabezado" sobre un archivo
     * perfecto (con la nota en AD2 eran 27 letras). A la tercera alerta amarilla sin motivo
     * el usuario deja de leer las alertas amarillas, incluida la que si importa. Una columna
     * que los datos no usan no es una columna: es una celda perdida a la derecha.
     *
     * @param  array $ventana
     * @param  int   $fila_encabezado
     * @return array  [indice_columna => true]
     */
    protected static function columnas_usadas_por_los_datos(array $ventana, $fila_encabezado)
    {
        $filas_de_datos = 0;
        $veces          = [];

        foreach ($ventana as $numero_fila => $valores) {
            if ((int) $numero_fila <= (int) $fila_encabezado) {
                continue;
            }

            $indices = [];

            foreach ($valores as $indice => $valor) {
                if (self::texto_de($valor) !== '') {
                    $indices[] = $indice;
                }
            }

            if (count($indices) === 0) {
                continue;
            }

            $filas_de_datos++;

            foreach ($indices as $indice) {
                $veces[$indice] = isset($veces[$indice]) ? ($veces[$indice] + 1) : 1;
            }
        }

        if ($filas_de_datos === 0) {
            return [];
        }

        $minimo = (int) ceil($filas_de_datos / 2);

        if ($minimo < 1) {
            $minimo = 1;
        }

        $usadas = [];

        foreach ($veces as $indice => $cantidad) {
            if ($cantidad >= $minimo) {
                $usadas[$indice] = true;
            }
        }

        return $usadas;
    }

    /**
     * Las cuatro condiciones de candidata de la regla.
     *
     * @param  array $no_vacias   celdas llenas, propagadas incluidas
     * @param  array $originales  celdas llenas que trae el archivo, sin las propagadas
     * @return bool
     */
    protected static function es_candidata(array $no_vacias, array $originales)
    {
        if (count($originales) < 2) {
            return false;
        }

        if (self::alguna_es_numerica_o_fecha($no_vacias)) {
            return false;
        }

        foreach ($no_vacias as $valor) {
            if (mb_strlen(self::texto_de($valor)) > self::LARGO_MAXIMO_DE_CELDA) {
                return false;
            }
        }

        /*
         * 🔴 "TODAS DISTINTAS" SE EVALUA SOBRE LAS CELDAS ORIGINALES, NO SOBRE LAS
         * PROPAGADAS. Parece una excepcion caprichosa y es lo que hace que los dos arreglos
         * convivan: una cabecera fusionada "PRECIOS" sobre E1:F1 se propaga a las dos
         * columnas —eso es justamente lo que arregla el defecto de las fusionadas— y deja el
         * encabezado con un duplicado que lo sacaba de candidato. O sea que el arreglo de
         * las fusionadas desactivaba el del encabezado corrido. Y ese duplicado lo generamos
         * nosotros al propagar: no viene del archivo, asi que no puede ser evidencia de
         * nada.
         *
         * La otra mitad de la regla es el `count($originales) < 2` de arriba, y tampoco se
         * puede sacar: un titulo fusionado sobre A1:F1 propaga seis celdas iguales, y sin
         * ese piso quedaria como candidato con seis celdas llenas y le ganaria por cantidad
         * al encabezado de verdad.
         */
        $normalizadas = [];

        foreach ($originales as $valor) {
            $normalizadas[] = mb_strtolower(self::texto_de($valor));
        }

        return count(array_unique($normalizadas)) === count($normalizadas);
    }

    /**
     * @param  array $valores
     * @param  array $excluidas  [indice_columna => true] celdas a saltear (las propagadas)
     * @return array  solo las celdas con contenido
     */
    protected static function celdas_no_vacias(array $valores, array $excluidas = [])
    {
        $no_vacias = [];

        foreach ($valores as $indice => $valor) {
            if (isset($excluidas[$indice])) {
                continue;
            }

            if ($valor instanceof \DateTime) {
                $no_vacias[] = $valor;

                continue;
            }

            if (self::texto_de($valor) !== '') {
                $no_vacias[] = $valor;
            }
        }

        return $no_vacias;
    }

    /**
     * Texto recortado de una celda. Un \DateTime no se puede castear a string en PHP 7.4
     * (fatal), asi que se lo trata aparte donde hace falta y aca devuelve ''.
     *
     * @param  mixed $valor
     * @return string
     */
    protected static function texto_de($valor)
    {
        if ($valor instanceof \DateTime) {
            return $valor->format('Y-m-d');
        }

        if (is_array($valor) || is_object($valor)) {
            return '';
        }

        return trim((string) $valor);
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

        $texto = self::texto_de($valor);

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
