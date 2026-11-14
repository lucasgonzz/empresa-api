<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiPlanilla;

/**
 * Qué es cada columna de una planilla de ventas, y cómo se comparan y se convierten sus celdas (misión
 * agente-venta-por-planilla, 02/11/2026). Sin modelos y sin base: funciones puras, las mismas para una
 * planilla que viene de una foto (LecturaDePlanillaIaHelper) y para una pegada como texto
 * (PlanillaDeTextoIaHelper).
 *
 * 🔴 LA COLUMNA SE RECONOCE POR SU TÍTULO Y POR LA FORMA DE SUS VALORES, EN CÓDIGO. Es el corazón del
 * caso real: una de las fotos del cliente no tiene encabezado, y el modelo que conversaba tomó el N° de
 * operación («#2000000000000001») como documento. Acá un número de 14 dígitos o más, o con «#», nunca
 * puede ser un documento: un CUIT tiene 11 y un DNI 7 u 8.
 *
 * 🔴 Y UN TÍTULO NO MANDA SOBRE LOS VALORES. El encabezado real dice «DNI O CUIT» en dos columnas: una
 * trae la palabra (DNI, CUIT) y la otra el número. Por eso primero se asignan las columnas cuya forma no
 * deja dudas y recién después los títulos, y todo título se contrasta con lo que hay abajo.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class RolesDePlanillaIaHelper
{
    const IMPORTE = 'importe';
    const FECHA = 'fecha';
    const CODIGO = 'codigo';
    const DESCRIPCION = 'descripcion';
    const OPERACION = 'operacion';
    const CANTIDAD = 'cantidad';
    const TIPO_DOCUMENTO = 'tipo_documento';
    const DOCUMENTO = 'documento';
    const NOMBRE = 'nombre';
    const DIRECCION = 'direccion';
    const COMPROBANTE = 'comprobante';

    /**
     * Una columna de números que puede ser el importe o la cantidad, y no hay cómo decidirlo: se le piden los títulos al dueño
     * (PlanillaIaHelper::lo_que_falta()). Es la única marca que queda en la fila: nunca se elige en silencio ni se asume cantidad 1.
     */
    const SIN_DECIDIR = 'sin_decidir';

    /**
     * Los roles que deciden QUÉ se vende, A QUIÉN y CÓMO. Una celda de estas sin consenso entre las lecturas es una DUDA y se le
     * pregunta al dueño. 🔴 También el comprobante, la operación y el tipo de documento: FACTURA A contra C FINAL decide el IVA del cliente
     * nuevo, y una operación mal leída no detecta la venta repetida. En las demás (fecha, descripción, dirección) se queda el valor de la
     * primera lectura: no mueven plata, stock ni a quién se le vende.
     */
    const ROLES_CRITICOS = [self::IMPORTE, self::CANTIDAD, self::CODIGO, self::DOCUMENTO, self::NOMBRE, self::COMPROBANTE, self::OPERACION, self::TIPO_DOCUMENTO];

    /** Lo único que dice una fila de totales, además de sus números. */
    const PALABRAS_DE_UNA_FILA_DE_TOTALES = ['total', 'totales', 'subtotal', 'suma'];

    /** Lo que se considera un tipo de documento en una celda. */
    const TIPOS_DE_DOCUMENTO = ['dni', 'cuit', 'cuil', 'le', 'lc', 'pasaporte'];

    /** Cuántos dígitos, como mínimo, tiene un número que solo puede ser un N° de operación. */
    const DIGITOS_DE_OPERACION = 14;

    /** Hasta cuánto llega una columna de enteros para poder ser la de cantidad. */
    const CANTIDAD_MAXIMA = 999;

    /** Qué parte de las celdas con datos tiene que tener una forma para que sea la de la columna. */
    const PROPORCION_DE_LA_FORMA = 0.8;

    // =====================================================================================
    // Normalización y conversión
    // =====================================================================================

    /**
     * Minúsculas, sin tildes y con un solo espacio entre palabras.
     *
     * @param  mixed  $texto
     * @return string
     */
    public static function normalizar_texto($texto): string
    {
        $texto = mb_strtolower(trim((string) $texto));

        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * La forma de una celda para COMPARARLA con la misma celda de otra lectura: dos lecturas coinciden si
     * esto da igual. «61.250,00», «61250,00» y «$ 61.250» son lo mismo; «SKU 70101» y «70101» también,
     * igual que «#2000…» y «2000…», y «Empresa S.A.» y «Empresa SA».
     *
     * @param  mixed  $celda
     * @return string
     */
    public static function normalizar_celda($celda): string
    {
        $texto = self::normalizar_texto($celda);

        if ($texto === '') {

            return '';
        }

        $texto = ltrim($texto, '# ');

        $texto = (string) preg_replace('/^sku[\s:\-]*/', '', $texto);

        // Solo dígitos: sin pasar por un float, que redondea una operación de 16 dígitos.
        if (ctype_digit($texto)) {

            $sin_ceros = ltrim($texto, '0');

            return $sin_ceros === '' ? '0' : $sin_ceros;
        }

        /*
         * «1,500» es 1,5 si la columna es de cantidad y 1500 si es de importe. Dos lecturas que dicen «1,500» y
         * «1.500» no coinciden: leyeron cosas distintas, y eso lo decide el dueño.
         */
        if (preg_match('/^\d{1,3},\d{3}$/', $texto)) {

            return $texto;
        }

        $numero = self::a_numero($texto);

        if (!is_null($numero)) {

            // Esta comparación también controla cantidades, que admiten milésimas. Redondear
            // a centavos hacía coincidir 0.125 con 0.126 y evitaba la lectura de desempate.
            return rtrim(rtrim(number_format($numero, 12, '.', ''), '0'), '.');
        }

        // Un documento con separadores es el mismo documento.
        if (preg_match('/^\d[\d\s.\-]*\d$/', $texto)) {

            return (string) preg_replace('/\D+/', '', $texto);
        }

        $texto = (string) preg_replace('/[^a-z0-9 ]+/', '', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    /**
     * El número de una celda escrita a la argentina (o a la inglesa), o null si no es un número.
     *
     * «62.300,00» → 62300; «62.300» → 62300; «62300.5» → 62300.5; «1,5» → 1.5; «1,500» → 1500; «$ 1.000» → 1000.
     * Un documento con puntos también da su número: quien lo necesita como documento usa solo_digitos().
     *
     * Con un cero adelante no hay miles que valgan: «0,500» es 0.5 y «0.125» es 0.125.
     *
     * 🔴 ES PARA IMPORTES. Una cantidad se lee con a_cantidad(): en una columna de plata «1,500» son mil
     * quinientos pesos, y en una de cantidades es kilo y medio.
     *
     * @param  mixed  $texto
     * @return float|null
     */
    public static function a_numero($texto)
    {
        $limpio = (string) preg_replace('/[\s$]+/u', '', trim((string) $texto));

        if ($limpio === '' || !preg_match('/^-?[\d.,]*\d[\d.,]*$/', $limpio)) {

            return null;
        }

        $ultima_coma = strrpos($limpio, ',');
        $ultimo_punto = strrpos($limpio, '.');

        if ($ultima_coma !== false && $ultimo_punto !== false) {

            // Con los dos separadores, el de más a la derecha es el decimal.
            $decimal = $ultima_coma > $ultimo_punto ? ',' : '.';
            $miles = $decimal === ',' ? '.' : ',';

            $limpio = str_replace($miles, '', $limpio);

            if (substr_count($limpio, $decimal) > 1) {

                return null;
            }

            $limpio = str_replace($decimal, '.', $limpio);

        } elseif ($ultima_coma !== false) {

            $limpio = preg_match('/^-?[1-9]\d{0,2}(,\d{3})+$/', $limpio) ? str_replace(',', '', $limpio) : str_replace(',', '.', $limpio);

        } elseif ($ultimo_punto !== false) {

            if (preg_match('/^-?[1-9]\d{0,2}(\.\d{3})+$/', $limpio)) {

                $limpio = str_replace('.', '', $limpio);
            }
        }

        return is_numeric($limpio) ? (float) $limpio : null;
    }

    /**
     * El número de una celda de CANTIDAD, o null si no se puede saber.
     *
     * 🔴 NO ES a_numero(), Y ESE ERROR MULTIPLICA LA VENTA POR MIL. En un negocio que vende por kilo o por metro,
     * «0,500» es medio y «1,500» es uno y medio: la coma es decimal, siempre. El punto, en cambio, es ambiguo con tres
     * cifras detrás: «1.500» puede ser mil quinientas unidades o una y media, y eso no se adivina
     * (cantidad_ambigua(): queda como duda y se le pregunta al dueño). Un número que ya viene como número (del JSON
     * de una lectura) es ese número.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    public static function a_cantidad($valor)
    {
        if (is_int($valor) || is_float($valor)) {

            return (float) $valor;
        }

        $limpio = (string) preg_replace('/\s+/u', '', trim((string) $valor));

        if ($limpio === '' || !preg_match('/^-?\d[\d.,]*$/', $limpio) || preg_match('/[.,]$/', $limpio)) {

            return null;
        }

        $con_coma = strpos($limpio, ',') !== false;
        $con_punto = strpos($limpio, '.') !== false;

        if ($con_coma && $con_punto) {

            return self::a_numero($limpio);
        }

        if ($con_coma) {

            return substr_count($limpio, ',') === 1 ? (float) str_replace(',', '.', $limpio) : null;
        }

        if ($con_punto) {

            return substr_count($limpio, '.') === 1 && !self::cantidad_ambigua($limpio) ? (float) $limpio : null;
        }

        return (float) $limpio;
    }

    /**
     * true si la cantidad se escribió con un punto que puede ser de miles o decimal: «1.500», «12.500». Con un cero
     * adelante no hay duda: «0.125» es decimal.
     *
     * @param  mixed  $valor
     * @return bool
     */
    public static function cantidad_ambigua($valor): bool
    {
        if (is_int($valor) || is_float($valor)) {

            return false;
        }

        return (bool) preg_match('/^-?[1-9]\d{0,2}(\.\d{3})+$/', trim((string) $valor));
    }

    /**
     * [tipo, número] si la celda trae los dos juntos: «DNI 14.000.002», «CUIT 30-71234567-1». Null si no.
     *
     * 🔴 LA PRUEBA REAL: en una tabla por espacios, una lectura deja «DNI 14000002» en una sola celda. Esa columna no tenía forma
     * de documento, se perdía su rol y las once ventas salían bien con once clientes creados sin documento, avisando además
     * que la Factura A no traía CUIT. Vale para cualquier origen: separar_tipo_de_documento().
     *
     * @param  mixed  $celda
     * @return array{0: string, 1: string}|null
     */
    public static function documento_con_tipo($celda)
    {
        if (!preg_match('/^(d\.?\s?n\.?\s?i\.?|cuit|cuil)\s*[:#\-]?\s*(\d[\d.\-\s]*\d)$/iu', trim((string) $celda), $partes)) {

            return null;
        }

        return [strtoupper((string) preg_replace('/[^a-z]/i', '', $partes[1])), self::solo_digitos($partes[2])];
    }

    /**
     * La tabla con la columna de «DNI 14000002» partida en dos: el tipo y el número. Solo si toda la columna es eso, o un número
     * suelto: en cualquier otra cosa (una dirección que dice «DNI», un texto) no se toca nada, y lo resuelve renglon().
     *
     * @param  array|null  $encabezado
     * @param  array  $filas
     * @return array{encabezado: array|null, filas: array}
     */
    public static function separar_tipo_de_documento($encabezado, array $filas): array
    {
        $columnas = 0;

        foreach ($filas as $fila) {

            $columnas = max($columnas, count($fila));
        }

        for ($c = $columnas - 1; $c >= 0; $c--) {

            $con_tipo = 0;
            $otras = 0;

            foreach ($filas as $fila) {

                $celda = isset($fila[$c]) ? trim((string) $fila[$c]) : '';

                if ($celda === '') {
                    continue;
                }

                if (!is_null(self::documento_con_tipo($celda))) {

                    $con_tipo++;

                } elseif (!preg_match('/^\d[\d.\-\s]*\d$/', $celda)) {

                    $otras++;
                }
            }

            if ($con_tipo === 0 || $otras > 0) {
                continue;
            }

            foreach ($filas as $f => $fila) {

                $fila = array_pad(array_values($fila), $c + 1, '');

                $par = self::documento_con_tipo($fila[$c]);

                array_splice($fila, $c, 1, is_null($par) ? ['', (string) $fila[$c]] : $par);

                $filas[$f] = $fila;
            }

            if (is_array($encabezado) && isset($encabezado[$c])) {

                array_splice($encabezado, $c, 0, ['TIPO']);
            }
        }

        return ['encabezado' => $encabezado, 'filas' => $filas];
    }

    /**
     * Solo los dígitos de una celda.
     *
     * @param  mixed  $texto
     * @return string
     */
    public static function solo_digitos($texto): string
    {
        return (string) preg_replace('/\D+/', '', (string) $texto);
    }

    /**
     * El código de artículo sin el prefijo SKU ni espacios alrededor. Respeta mayúsculas y ceros: la
     * base compara sin mirar mayúsculas, y los ceros los resuelve quien busca.
     *
     * @param  mixed  $texto
     * @return string
     */
    public static function codigo_limpio($texto): string
    {
        return trim((string) preg_replace('/^\s*sku[\s:\-]*/i', '', trim((string) $texto)));
    }

    // =====================================================================================
    // Forma de los valores
    // =====================================================================================

    /**
     * Qué tipo de valor es una celda: operacion, comprobante, tipo_documento, fecha, documento, dinero,
     * codigo, entero, texto, texto_con_numeros o vacio.
     *
     * @param  mixed  $celda
     * @return string
     */
    public static function forma_de($celda): string
    {
        $crudo = trim((string) $celda);

        if ($crudo === '') {

            return 'vacio';
        }

        $texto = self::normalizar_texto($crudo);

        if (!is_null(self::documento_con_tipo($crudo))) {

            return 'documento';
        }

        if (preg_match('/^#\s*[\w\-]+$/u', $crudo)) {

            return 'operacion';
        }

        if (in_array($texto, self::TIPOS_DE_DOCUMENTO, true)) {

            return 'tipo_documento';
        }

        if (self::es_comprobante($texto)) {

            return 'comprobante';
        }

        /* 🔴 Con punto, solo dd.mm.aaaa o con el nombre del mes: «1.5» es una cantidad, no el 1 de mayo. */
        if (preg_match('/^\d{1,2}[\/\-](\d{1,2}|[a-z]{3,10})([\/\-]\d{2,4})?$/', $texto)
            || preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/', $texto)
            || preg_match('/^\d{1,2}\.[a-z]{3,10}(\.\d{2,4})?$/', $texto)) {

            return 'fecha';
        }

        if (ctype_digit($texto)) {

            $largo = strlen($texto);

            if ($largo >= self::DIGITOS_DE_OPERACION) {

                return 'operacion';
            }

            if ($largo === 7 || $largo === 8 || $largo === 11) {

                return 'documento';
            }

            // 12 o 13 dígitos es un código de barras (EAN, UPC), no un documento ni una operación.
            return $largo >= 12 ? 'codigo' : 'entero';
        }

        // Un documento con separadores: 17.000.001 o 20-17000001-4.
        if (preg_match('/^\d{1,2}\.\d{3}\.\d{3}$/', $texto) || preg_match('/^\d{2}-\d{7,8}-\d$/', $texto)) {

            return 'documento';
        }

        if (strpos($crudo, '$') !== false && !is_null(self::a_numero($crudo))) {

            return 'dinero';
        }

        if (!is_null(self::a_numero($crudo))) {

            return 'dinero';
        }

        if (preg_match('/^sku[\s:\-]*\S+$/', $texto)) {

            return 'codigo';
        }

        // Una sola palabra con letras y números: wzz3324, ZRRI-50LF.
        if (preg_match('/^\S+$/u', $crudo) && preg_match('/\d/', $crudo) && preg_match('/\p{L}/u', $crudo)) {

            return 'codigo';
        }

        return preg_match('/\d/', $crudo) ? 'texto_con_numeros' : 'texto';
    }

    /**
     * true si el texto normalizado dice qué comprobante pide la planilla.
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function es_comprobante($texto): bool
    {
        return (bool) preg_match('/^(factura( [abc])?|fact\.? ?[abc]?|fc ?[abc]?|c\.? ?final|cons(umidor)?\.? final|ticket)$/', (string) $texto);
    }

    /**
     * La forma dominante de una columna, según sus celdas con datos.
     *
     * @param  array<int, string>  $celdas
     * @return array{forma: string, maximo: float, para_cantidad: bool, maximo_de_cantidad: float}  `maximo`: el mayor valor numérico.
     *                                  `para_cantidad`: si sus celdas se pueden leer como una cantidad. 🔴 Una ambigua («1.500») no
     *                                  lo impide, si hay alguna chica de verdad al lado: es esa celda la que queda con duda.
     *                                  `maximo_de_cantidad`: el mayor valor sin contar las ambiguas.
     */
    protected static function forma_de_la_columna(array $celdas): array
    {
        $cuentas = [];
        $total = 0;
        $maximo = 0.0;
        $para_cantidad = true;
        $chicas = 0;
        $maximo_de_cantidad = 0.0;
        $ambiguas = 0;

        foreach ($celdas as $celda) {

            $forma = self::forma_de($celda);

            if ($forma === 'vacio') {
                continue;
            }

            $total++;
            $cuentas[$forma] = (isset($cuentas[$forma]) ? $cuentas[$forma] : 0) + 1;

            if ($forma === 'entero' || $forma === 'dinero' || $forma === 'documento') {

                $maximo = max($maximo, (float) self::a_numero($celda));
            }

            if (self::cantidad_ambigua($celda)) {

                $ambiguas++;

                continue;
            }

            $como_cantidad = self::a_cantidad($celda);

            /* Centavos sobre cien o más («500,00») es plata: nadie anota así quinientas unidades. */
            if (is_null($como_cantidad) || $como_cantidad <= 0 || $como_cantidad > self::CANTIDAD_MAXIMA
                || ($como_cantidad >= 100 && preg_match('/[.,]\d{2}$/', trim((string) $celda)))) {

                $para_cantidad = false;

            } else {

                $chicas++;
                $maximo_de_cantidad = max($maximo_de_cantidad, $como_cantidad);
            }
        }

        if ($total === 0) {

            return ['forma' => 'vacio', 'maximo' => 0.0, 'para_cantidad' => false, 'maximo_de_cantidad' => 0.0];
        }

        /* Todas ambiguas («1.500», «12.500») es plata sin centavos: ninguna chica dice que sea una cantidad. */
        $para_cantidad = $para_cantidad && $chicas > 0;

        foreach (['operacion', 'comprobante', 'tipo_documento', 'fecha', 'dinero', 'documento', 'entero'] as $candidata) {

            if ((isset($cuentas[$candidata]) ? $cuentas[$candidata] : 0) / $total >= self::PROPORCION_DE_LA_FORMA) {

                return ['forma' => $candidata, 'solo_miles' => $ambiguas === $total, 'maximo' => $maximo, 'para_cantidad' => $para_cantidad, 'maximo_de_cantidad' => $maximo_de_cantidad];
            }
        }

        /*
         * Enteros y decimales mezclados («2», «1,5», «3») son números, aunque ninguna de las dos formas llegue a la
         * proporción: una columna chica es de cantidades, y una grande de plata.
         */
        $de_numeros = (isset($cuentas['entero']) ? $cuentas['entero'] : 0) + (isset($cuentas['dinero']) ? $cuentas['dinero'] : 0);

        if ($de_numeros === $total) {

            return ['forma' => $maximo <= self::CANTIDAD_MAXIMA ? 'entero' : 'dinero', 'maximo' => $maximo, 'para_cantidad' => $para_cantidad, 'maximo_de_cantidad' => $maximo_de_cantidad];
        }

        // Códigos sueltos: SKU 70103, wzz3324 y 70101 en la misma columna.
        $de_codigo = 0;

        foreach (['codigo', 'entero', 'documento'] as $candidata) {

            $de_codigo += isset($cuentas[$candidata]) ? $cuentas[$candidata] : 0;
        }

        if ($de_codigo === $total && isset($cuentas['codigo'])) {

            return ['forma' => 'codigo', 'maximo' => $maximo, 'para_cantidad' => false, 'maximo_de_cantidad' => 0.0];
        }

        $con_numeros = isset($cuentas['texto_con_numeros']) ? $cuentas['texto_con_numeros'] : 0;

        return ['forma' => $con_numeros * 2 >= $total ? 'texto_con_numeros' : 'texto', 'maximo' => $maximo, 'para_cantidad' => false, 'maximo_de_cantidad' => 0.0];
    }

    // =====================================================================================
    // Encabezado y roles
    // =====================================================================================

    /**
     * Si la primera fila son títulos y no datos, la saca: ['encabezado' => array|null, 'filas' => array].
     * Lo usan las dos entradas: un texto pegado no dice si trae títulos, y un modelo puede devolver los
     * títulos como una fila más.
     *
     * @param  array<int, array<int, string>>  $filas
     * @return array{encabezado: array|null, filas: array}
     */
    public static function separar_encabezado(array $filas): array
    {
        if (!count($filas)) {

            return ['encabezado' => null, 'filas' => $filas];
        }

        $primera = array_values($filas[0]);
        $con_titulo = 0;

        foreach ($primera as $celda) {

            if (in_array(self::forma_de($celda), ['dinero', 'operacion', 'documento', 'fecha', 'entero'], true)) {

                return ['encabezado' => null, 'filas' => $filas];
            }

            if (!is_null(self::rol_por_titulo($celda))) {

                $con_titulo++;
            }
        }

        if ($con_titulo < 2) {

            return ['encabezado' => null, 'filas' => $filas];
        }

        return ['encabezado' => $primera, 'filas' => array_values(array_slice($filas, 1))];
    }

    /**
     * El rol que sugiere el título de una columna, o null. Los títulos reales vienen cortados por el
     * ancho de la columna («CANTIDA», «O DNI O CUIT»): se busca adentro, no se compara entero.
     *
     * 🔴 EL ORDEN IMPORTA. «PRECIO UNIDAD» y «P. UNIT» son importes, y «UNID» sola es la cantidad: por eso las palabras de
     * unidad se miran después de las de plata, y «CANT…» antes que nada.
     *
     * @param  mixed  $titulo
     * @return string|null
     */
    public static function rol_por_titulo($titulo)
    {
        $texto = self::normalizar_texto($titulo);

        if ($texto === '') {

            return null;
        }

        $patrones = [
            [self::CANTIDAD, '/\bcant\w*/'],
            [self::IMPORTE, '/\b(total|importe|monto|precio|valor|unit\w*|c\/u)\b/'],
            [self::CANTIDAD, '/\b(unid\w*|uds?|qty)\b/'],
            [self::FECHA, '/\bfecha\b/'],
            [self::CODIGO, '/\b(codigo|cod|sku|barras?)\b/'],
            [self::DESCRIPCION, '/\b(descripcion|detalle|producto|articulo|item)\b/'],
            [self::OPERACION, '/\b(operacion|op|orden|pedido|transaccion)\b/'],
            [self::DOCUMENTO, '/\b(dni|cuit|cuil|documento|doc)\b/'],
            [self::NOMBRE, '/\b(nombre|cliente|comprador|razon social)\b/'],
            [self::DIRECCION, '/\b(direccion|domicilio|localidad)\b/'],
            [self::COMPROBANTE, '/\b(comprobante|factura|fact)\b/'],
        ];

        foreach ($patrones as $patron) {

            if (preg_match($patron[1], $texto)) {

                return $patron[0];
            }
        }

        return null;
    }

    /**
     * Las filas sin las de totales: una fila de Excel que abajo de todo dice TOTAL no es una venta.
     *
     * 🔴 SE SACA ANTES DE MIRAR LOS ROLES, Y NO SOLO ANTES DE VENDER. Medido el 03/11/2026, por los dos caminos entraba como un renglón más y se
     * preguntaba por ella. Además, con pocas filas, su celda «TOTAL» le sacaba el rol a la columna del comprobante, que es lo que decide el IVA de
     * un cliente nuevo; y en una foto, «ES EL TOTAL» la tomaba como el renglón nombrado y no definía los demás.
     *
     * Con documento o código es una venta, aunque diga «Total». Se mira en la columna y no en la forma de la celda, porque una suma sin decimales
     * («1.234.567») tiene forma de documento. Si todas parecen de totales, no hay nada que separar: no se toca.
     *
     * @param  array<int, string>|null  $encabezado
     * @param  array<int, array<int, string>>  $filas
     * @return array<int, array<int, string>>
     */
    public static function sin_filas_de_totales($encabezado, array $filas): array
    {
        $de_totales = array_filter($filas, function ($fila) {

            return self::parece_una_fila_de_totales($fila);
        });

        if (!count($de_totales) || count($de_totales) === count($filas)) {

            return $filas;
        }

        $roles = self::roles_de($encabezado, array_values(array_diff_key($filas, $de_totales)));

        foreach ([self::DOCUMENTO, self::CODIGO] as $rol) {

            $columna = array_search($rol, $roles, true);

            foreach ($de_totales as $indice => $fila) {

                if ($columna !== false && isset($fila[$columna]) && trim((string) $fila[$columna]) !== '') {

                    unset($de_totales[$indice]);
                }
            }
        }

        return array_values(array_diff_key($filas, $de_totales));
    }

    /**
     * true si el único texto de la fila es una de esas palabras: lo demás son números o está vacío.
     *
     * @param  array<int, string>  $fila
     * @return bool
     */
    protected static function parece_una_fila_de_totales(array $fila): bool
    {
        $palabras = 0;

        foreach ($fila as $celda) {

            $letras = (string) preg_replace('/[^a-z]/', '', self::normalizar_texto($celda));

            if ($letras === '') {
                continue;
            }

            if (!in_array($letras, self::PALABRAS_DE_UNA_FILA_DE_TOTALES, true)) {

                return false;
            }

            $palabras++;
        }

        return $palabras === 1;
    }

    /**
     * Qué es cada columna: [indice => rol]. Una columna que no se pudo identificar no aparece, y cada rol
     * va a una sola columna. El orden de los pasos es el contrato: ver el docblock de la clase.
     *
     * @param  array<int, string>|null  $encabezado
     * @param  array<int, array<int, string>>  $filas
     * @return array<int, string>
     */
    public static function roles_de($encabezado, array $filas): array
    {
        $columnas = 0;

        foreach ($filas as $fila) {

            $columnas = max($columnas, count($fila));
        }

        $formas = [];

        for ($c = 0; $c < $columnas; $c++) {

            $celdas = [];

            foreach ($filas as $fila) {

                $celdas[] = isset($fila[$c]) ? (string) $fila[$c] : '';
            }

            $formas[$c] = self::forma_de_la_columna($celdas);
        }

        $roles = [];

        /*
         * 0) 🔴 LA COLUMNA QUE DICE SER DE CANTIDAD ES LA DE CANTIDAD, aunque sus valores no lleguen a una forma: «1.5» y
         * «dos» en la misma columna no tienen forma dominante, y sin esto la venta sale con cantidad 1. Las celdas que no
         * se entiendan quedan como duda, renglón por renglón (renglon()). Va antes que todo porque «1/2» tiene forma de
         * fecha y acá es medio.
         */
        if (is_array($encabezado)) {

            foreach ($formas as $c => $datos) {

                if (isset($encabezado[$c]) && self::rol_por_titulo($encabezado[$c]) === self::CANTIDAD) {

                    self::asignar($roles, $c, self::CANTIDAD);
                }
            }
        }

        /* 1) Lo que la forma no deja dudas, venga el título que venga. */
        foreach ($formas as $c => $datos) {

            if (in_array($datos['forma'], ['operacion', 'comprobante', 'tipo_documento', 'fecha'], true)) {

                self::asignar($roles, $c, $datos['forma'] === 'tipo_documento' ? self::TIPO_DOCUMENTO : $datos['forma']);
            }
        }

        /* 2) Los títulos, contrastados con lo que hay abajo. */
        if (is_array($encabezado)) {

            foreach ($formas as $c => $datos) {

                if (isset($roles[$c]) || !isset($encabezado[$c]) || $datos['forma'] === 'vacio') {
                    continue;
                }

                $rol = self::rol_por_titulo($encabezado[$c]);

                if (!is_null($rol) && self::el_titulo_cuadra_con_la_forma($rol, $datos['forma'])) {

                    self::asignar($roles, $c, $rol);
                }
            }
        }

        /* 3) El documento: la forma, o la columna que sigue a la del tipo de documento. */
        foreach ($formas as $c => $datos) {

            if (!isset($roles[$c]) && $datos['forma'] === 'documento') {

                self::asignar($roles, $c, self::DOCUMENTO);
            }
        }

        $de_tipo = array_search(self::TIPO_DOCUMENTO, $roles, true);

        if ($de_tipo !== false && !in_array(self::DOCUMENTO, $roles, true) && isset($formas[$de_tipo + 1])
            && !isset($roles[$de_tipo + 1]) && in_array($formas[$de_tipo + 1]['forma'], ['entero', 'documento', 'dinero'], true)) {

            self::asignar($roles, $de_tipo + 1, self::DOCUMENTO);
        }

        /*
         * 4) El importe es la columna de plata con los valores MÁS ALTOS, entre las que no pueden ser una cantidad: «2, 1.500, 3» tiene un
         * valor grande y no es plata. 🔴 Junto a «45000, 30000, 12000» tampoco, aunque esos no tengan forma de plata: la chica quedaba como
         * importe y salían ventas de $ 2. Y el código, por su forma.
         */
        $del_importe = null;

        $hay_enteros_grandes = false;

        foreach ($formas as $c => $datos) {

            if (!isset($roles[$c]) && $datos['forma'] === 'entero' && !$datos['para_cantidad'] && $datos['maximo'] > self::CANTIDAD_MAXIMA) {

                $hay_enteros_grandes = true;
            }
        }

        foreach ($formas as $c => $datos) {

            if (!isset($roles[$c]) && $datos['forma'] === 'dinero' && !($datos['para_cantidad'] && $hay_enteros_grandes)) {

                $gana = is_null($del_importe)
                    || ($formas[$del_importe]['para_cantidad'] && !$datos['para_cantidad'])
                    || ($formas[$del_importe]['para_cantidad'] === $datos['para_cantidad'] && $datos['maximo'] > $formas[$del_importe]['maximo']);

                if ($gana) {

                    $del_importe = $c;
                }
            }

            if (!isset($roles[$c]) && $datos['forma'] === 'codigo') {

                self::asignar($roles, $c, self::CODIGO);
            }
        }

        $sin_decidir = is_null($del_importe) ? [] : self::columnas_sin_decidir($del_importe, $roles, $formas);

        foreach ($sin_decidir as $c) {

            $roles[$c] = self::SIN_DECIDIR;
        }

        if (!is_null($del_importe) && !count($sin_decidir)) {

            self::asignar($roles, $del_importe, self::IMPORTE);
        }

        /* 5) Las columnas de enteros. */
        self::asignar_enteros($roles, $formas);

        $del_importe = array_search(self::IMPORTE, $roles, true);

        /*
         * Y sin título: una columna con decimales chicos («0,5», «1,5»), o con formato de miles sin decimales («1.500») y más baja que el
         * importe, es candidata a cantidad. 🔴 Las celdas ambiguas quedan como duda (renglon()): sin esto, se asumía cantidad 1 en silencio.
         */
        foreach ($formas as $c => $datos) {

            if (!isset($roles[$c]) && $datos['forma'] === 'dinero'
                && ($datos['para_cantidad'] || (!empty($datos['solo_miles']) && $del_importe !== false && $datos['maximo'] < $formas[$del_importe]['maximo']))) {

                self::asignar($roles, $c, self::CANTIDAD);
            }
        }

        /* 6) Las de texto: cada una al lado de la que le da sentido. */
        self::asignar_textos($roles, $formas);

        ksort($roles);

        return $roles;
    }

    /**
     * Las columnas entre las que no se puede decidir cuál es el importe, o ninguna si se puede. Una columna con formato de miles sin
     * decimales («1.500», «12.500») MÁS ALTA que una con decimales («500,00», «800,00»): puede ser el total y el precio, o la plata y unas
     * cantidades raras. 🔴 Si no se puede decidir con seguridad, se pregunta: una elegida en silencio es plata mal cargada.
     *
     * @param  int  $del_importe  La que quedó con los valores más altos.
     * @param  array<int, string>  $roles
     * @param  array<int, array>  $formas
     * @return array<int, int>
     */
    protected static function columnas_sin_decidir($del_importe, array $roles, array $formas): array
    {
        if (empty($formas[$del_importe]['solo_miles'])) {

            return [];
        }

        $columnas = [];

        foreach ($formas as $c => $datos) {

            if (!isset($roles[$c]) && $c !== $del_importe && $datos['forma'] === 'dinero' && empty($datos['solo_miles']) && !$datos['para_cantidad']) {

                $columnas[] = $c;
            }
        }

        return count($columnas) ? array_merge([$del_importe], $columnas) : [];
    }

    /**
     * Deja el rol en esa columna, si el rol no estaba tomado.
     *
     * @param  array<int, string>  $roles
     * @param  int  $columna
     * @param  string  $rol
     * @return void
     */
    protected static function asignar(array &$roles, $columna, $rol)
    {
        if (!in_array($rol, $roles, true) && !isset($roles[$columna])) {

            $roles[$columna] = $rol;
        }
    }

    /**
     * true si los valores de la columna pueden ser lo que dice su título.
     *
     * @param  string  $rol
     * @param  string  $forma
     * @return bool
     */
    protected static function el_titulo_cuadra_con_la_forma($rol, $forma): bool
    {
        $numericas = ['dinero', 'entero', 'documento', 'codigo'];

        if ($rol === self::IMPORTE) {

            return in_array($forma, ['dinero', 'entero', 'documento'], true);
        }

        if ($rol === self::DOCUMENTO) {

            return in_array($forma, $numericas, true);
        }

        if ($rol === self::NOMBRE || $rol === self::DIRECCION || $rol === self::DESCRIPCION) {

            return in_array($forma, ['texto', 'texto_con_numeros', 'codigo'], true);
        }

        return true;
    }

    /**
     * Enteros sin separadores: la cantidad es la de valores chicos, y lo demás es el código o el importe.
     * Sin ninguna otra pista, dos columnas de enteros grandes no se adivinan: quedan SIN_DECIDIR y la planilla
     * pide que se la manden con títulos.
     *
     * @param  array<int, string>  $roles
     * @param  array<int, array>  $formas
     * @return void
     */
    protected static function asignar_enteros(array &$roles, array $formas)
    {
        $enteros = [];

        foreach ($formas as $c => $datos) {

            if (!isset($roles[$c]) && $datos['forma'] === 'entero') {

                /* Una celda ambigua no cuenta para saber si los valores son chicos: queda como duda (renglon()). */
                $enteros[$c] = $datos['para_cantidad'] ? (float) $datos['maximo_de_cantidad'] : (float) $datos['maximo'];
            }
        }

        if (!in_array(self::CANTIDAD, $roles, true)) {

            $elegida = null;

            foreach ($enteros as $c => $maximo) {

                if ($maximo <= self::CANTIDAD_MAXIMA && (is_null($elegida) || $maximo < $enteros[$elegida])) {
                    $elegida = $c;
                }
            }

            if (!is_null($elegida)) {

                self::asignar($roles, $elegida, self::CANTIDAD);

                unset($enteros[$elegida]);
            }
        }

        $hay_importe = in_array(self::IMPORTE, $roles, true) || in_array(self::SIN_DECIDIR, $roles, true);
        $hay_codigo = in_array(self::CODIGO, $roles, true);

        /* 🔴 Sin importe ni código, dos columnas de enteros no se adivinan: la que se elija puede ser el código. Se piden los títulos. */
        if (!$hay_importe && !$hay_codigo && count($enteros) > 1 && max($enteros) > self::CANTIDAD_MAXIMA) {

            foreach (array_keys($enteros) as $c) {

                $roles[$c] = self::SIN_DECIDIR;
            }

            return;
        }

        foreach ($enteros as $c => $maximo) {

            if ($hay_importe && !$hay_codigo) {

                self::asignar($roles, $c, self::CODIGO);

                $hay_codigo = true;

                continue;
            }

            if ($hay_codigo && !$hay_importe) {

                self::asignar($roles, $c, self::IMPORTE);

                $hay_importe = true;

                continue;
            }

            if (!$hay_importe && !$hay_codigo && count($enteros) === 1) {

                self::asignar($roles, $c, $maximo >= 1000 ? self::IMPORTE : self::CODIGO);
            }
        }
    }

    /**
     * Las columnas de texto. El nombre sigue al documento, la descripción al código y la dirección al
     * nombre, que es como vienen las planillas; lo que queda se decide por lo que tiene adentro.
     *
     * @param  array<int, string>  $roles
     * @param  array<int, array>  $formas
     * @return void
     */
    protected static function asignar_textos(array &$roles, array $formas)
    {
        $es_texto = function ($c) use ($roles, $formas) {

            return isset($formas[$c]) && !isset($roles[$c]) && in_array($formas[$c]['forma'], ['texto', 'texto_con_numeros'], true);
        };

        $de_documento = array_search(self::DOCUMENTO, $roles, true);

        if ($de_documento !== false && !in_array(self::NOMBRE, $roles, true) && $es_texto($de_documento + 1)) {

            self::asignar($roles, $de_documento + 1, self::NOMBRE);
        }

        $de_codigo = array_search(self::CODIGO, $roles, true);

        if ($de_codigo !== false && !in_array(self::DESCRIPCION, $roles, true) && isset($formas[$de_codigo + 1]) && !isset($roles[$de_codigo + 1])
            && in_array($formas[$de_codigo + 1]['forma'], ['texto', 'texto_con_numeros'], true)) {

            self::asignar($roles, $de_codigo + 1, self::DESCRIPCION);
        }

        $de_nombre = array_search(self::NOMBRE, $roles, true);

        if ($de_nombre !== false && !in_array(self::DIRECCION, $roles, true) && isset($formas[$de_nombre + 1]) && !isset($roles[$de_nombre + 1])
            && in_array($formas[$de_nombre + 1]['forma'], ['texto', 'texto_con_numeros'], true)) {

            self::asignar($roles, $de_nombre + 1, self::DIRECCION);
        }

        foreach ($formas as $c => $datos) {

            if (isset($roles[$c]) || !in_array($datos['forma'], ['texto', 'texto_con_numeros'], true)) {
                continue;
            }

            $con_numeros = $datos['forma'] === 'texto_con_numeros';

            if (!in_array(self::NOMBRE, $roles, true) && !$con_numeros) {

                self::asignar($roles, $c, self::NOMBRE);

            } elseif (!in_array(self::DESCRIPCION, $roles, true)) {

                self::asignar($roles, $c, self::DESCRIPCION);

            } elseif (!in_array(self::DIRECCION, $roles, true)) {

                self::asignar($roles, $c, self::DIRECCION);
            }
        }
    }

    /**
     * Qué dice el título de la columna del importe: total, unidad o null si no lo dice. 🔴 «PRECIO», «IMPORTE» y
     * «MONTO» a secas NO lo dicen: en una planilla de ventas un «PRECIO» de $ 88.400 con 2 unidades puede ser cualquiera
     * de los dos, y equivocarse duplica o parte la venta. Con alguna cantidad distinta de 1, se pregunta (lo_que_falta()).
     *
     * @param  array<int, string>|null  $encabezado
     * @param  array<int, string>  $roles
     * @return string|null
     */
    public static function importe_es_de($encabezado, array $roles)
    {
        $columna = array_search(self::IMPORTE, $roles, true);

        if ($columna === false || !is_array($encabezado) || !isset($encabezado[$columna])) {

            return null;
        }

        $titulo = self::normalizar_texto($encabezado[$columna]);

        if (preg_match('/\b(unit\w*|unidad|c\/u|cada una?)\b/', $titulo)) {

            return AiPlanilla::IMPORTE_UNIDAD;
        }

        return preg_match('/\btotal\b/', $titulo) ? AiPlanilla::IMPORTE_TOTAL : null;
    }

    // =====================================================================================
    // Renglones
    // =====================================================================================

    /**
     * Un renglón nuevo, con sus celdas por rol y sus dudas.
     *
     * @param  int  $numero
     * @param  array<int, string>  $celdas
     * @param  array<int, string>  $roles
     * @param  array<int, array<int, string>>  $dudas  [columna => opciones], de las celdas sin consenso.
     * @return array
     */
    public static function renglon($numero, array $celdas, array $roles, array $dudas = []): array
    {
        $valores = [];
        $dudas_del_renglon = [];

        foreach ($roles as $columna => $rol) {

            $valor = isset($celdas[$columna]) ? trim((string) $celdas[$columna]) : '';

            if ($valor !== '') {

                $valores[$rol] = $valor;
            }

            if (isset($dudas[$columna]) && in_array($rol, self::ROLES_CRITICOS, true)) {

                $dudas_del_renglon[] = ['rol' => $rol, 'opciones' => array_values($dudas[$columna])];

                unset($valores[$rol]);
            }
        }

        /* Lo que separar_tipo_de_documento() no pudo partir porque la columna traía otra cosa. */
        if (isset($valores[self::DOCUMENTO])) {

            $par = self::documento_con_tipo($valores[self::DOCUMENTO]);

            if (!is_null($par)) {

                $valores[self::DOCUMENTO] = $par[1];

                if (!isset($valores[self::TIPO_DOCUMENTO])) {

                    $valores[self::TIPO_DOCUMENTO] = $par[0];
                }
            }
        }

        /*
         * 🔴 Un documento que no sirve es una DUDA: nunca se «corrige» (es lo que hizo el modelo con el CUIT
         * real de la planilla). Se le pregunta al dueño, con el número tal cual se leyó.
         */
        if (isset($valores[self::DOCUMENTO])) {

            $motivo = CuitIaHelper::motivo_si_no_sirve($valores[self::DOCUMENTO]);

            if (!is_null($motivo)) {

                $dudas_del_renglon[] = ['rol' => self::DOCUMENTO, 'opciones' => [$valores[self::DOCUMENTO]], 'motivo' => $motivo];

                unset($valores[self::DOCUMENTO]);
            }
        }

        /*
         * 🔴 CON COLUMNA DE CANTIDAD, UNA CELDA QUE NO SE ENTIENDE NO ES 1: ES UNA DUDA. «1» por defecto es solo para la
         * planilla que no tiene ninguna columna de cantidad (cantidad_de()).
         */
        if (in_array(self::CANTIDAD, $roles, true) && !self::tiene_duda(['dudas' => $dudas_del_renglon], self::CANTIDAD)) {

            $de_la_celda = isset($valores[self::CANTIDAD]) ? $valores[self::CANTIDAD] : '';

            $cantidad = self::a_cantidad($de_la_celda);

            if (is_null($cantidad) || $cantidad <= 0) {

                $dudas_del_renglon[] = [
                    'rol'      => self::CANTIDAD,
                    'opciones' => $de_la_celda === '' ? [] : [$de_la_celda],
                    'motivo'   => self::cantidad_ambigua($de_la_celda) ? 'con ese punto puede ser una cantidad con miles o con decimales' : 'no se entiende como un número',
                ];

                unset($valores[self::CANTIDAD]);
            }
        }

        return [
            'numero'           => (int) $numero,
            'celdas'           => array_values($celdas),
            'huella'           => self::huella_de($celdas),
            'valores'          => $valores,
            'dudas'            => $dudas_del_renglon,
            'articulo_id'      => null,
            'cliente_id'       => null,
            'cliente_nuevo'    => false,
            'estado'           => AiPlanilla::FILA_PENDIENTE,
            'venta_numero'     => null,
            'motivo'           => null,
        ];
    }

    /**
     * Lo que identifica a una fila por lo que dice: sus celdas normalizadas. Es lo único que reconoce una fila sin
     * cliente ni operación que se vuelve a pegar otro día (PlanillaIaHelper::venta_ya_registrada()).
     *
     * @param  array  $celdas
     * @return string
     */
    public static function huella_de(array $celdas): string
    {
        $partes = [];

        foreach ($celdas as $celda) {

            $normal = self::normalizar_celda($celda);

            if ($normal !== '') {

                $partes[] = $normal;
            }
        }

        return md5(implode('|', $partes));
    }

    /**
     * La cantidad de un renglón. Sin columna de cantidad es 1; una celda ilegible es una duda y da null.
     *
     * @param  array  $fila
     * @return float|null
     */
    public static function cantidad_de(array $fila)
    {
        if (!isset($fila['valores'][self::CANTIDAD])) {

            /* 🔴 Sin esa columna es 1, salvo que haya una que puede serlo y no se decidió: ahí no se asume nada. */
            return self::tiene_duda($fila, self::CANTIDAD) || isset($fila['valores'][self::SIN_DECIDIR]) ? null : 1.0;
        }

        $cantidad = self::a_cantidad($fila['valores'][self::CANTIDAD]);

        return is_null($cantidad) || $cantidad <= 0 ? null : round($cantidad, 3);
    }

    /**
     * true si la cantidad tiene más decimales de los que guarda una venta: `article_sale.amount` es decimal(8,2), y
     * 0,125 se guardaría como 0,13: la venta quedaría con otro total. Se le pregunta al dueño antes del sí.
     *
     * @param  float|null  $cantidad
     * @return bool
     */
    public static function cantidad_con_decimales_de_mas($cantidad): bool
    {
        return !is_null($cantidad) && abs(round((float) $cantidad, 2) - (float) $cantidad) > 0.0001;
    }

    /**
     * Lo que vale el renglón entero: el importe si es el total, o importe × cantidad si es el precio de
     * una unidad. El que corrigió la persona (`total_corregido`) manda. Null si no se puede saber.
     *
     * 🔴 Un renglón que vino de otra tabla, con su propio título (`importe_es` de la fila), se cuenta como dice ese título: si no, una
     * tabla con P. UNIT sumada a una con TOTAL queda en la mitad de la plata, sin ningún aviso (PlanillaIaHelper::cruzar()).
     *
     * @param  array  $fila
     * @param  string|null  $importe_es
     * @return float|null
     */
    public static function total_de(array $fila, $importe_es)
    {
        if (isset($fila['total_corregido']) && is_numeric($fila['total_corregido'])) {

            return round((float) $fila['total_corregido'], 2);
        }

        if (!isset($fila['valores'][self::IMPORTE])) {

            return null;
        }

        if (isset($fila['importe_es'])) {

            $importe_es = $fila['importe_es'];
        }

        $importe = self::a_numero($fila['valores'][self::IMPORTE]);

        if (is_null($importe) || $importe <= 0) {

            return null;
        }

        if ($importe_es === AiPlanilla::IMPORTE_UNIDAD) {

            $cantidad = self::cantidad_de($fila);

            return is_null($cantidad) ? null : round($importe * $cantidad, 2);
        }

        return round($importe, 2);
    }

    /**
     * El precio por unidad que se le dicta a la venta: total ÷ cantidad (2 × $ 44.200 = $ 88.400).
     *
     * @param  array  $fila
     * @param  string|null  $importe_es
     * @return float|null
     */
    public static function precio_unitario_de(array $fila, $importe_es)
    {
        $total = self::total_de($fila, $importe_es);
        $cantidad = self::cantidad_de($fila);

        if (is_null($total) || is_null($cantidad) || $cantidad <= 0) {

            return null;
        }

        return round($total / $cantidad, 2);
    }

    /**
     * Lo que va a valer de verdad la venta del renglón: precio guardado × cantidad. 🔴 NO SIEMPRE ES total_de(): una
     * venta se guarda por unidad, con dos decimales, y $ 100 en 3 unidades quedan en 3 × $ 33,33 = $ 99,99. La lista
     * que confirma el dueño tiene que decir lo que se va a vender (con su aviso, TextoDePlanillaIaHelper::propuesta()).
     *
     * @param  array  $fila
     * @param  string|null  $importe_es
     * @return float|null
     */
    public static function total_que_se_vende_de(array $fila, $importe_es)
    {
        $precio = self::precio_unitario_de($fila, $importe_es);
        $cantidad = self::cantidad_de($fila);

        return is_null($precio) || is_null($cantidad) ? null : round($precio * $cantidad, 2);
    }

    /**
     * true si el renglón tiene una duda abierta sobre ese rol.
     *
     * @param  array  $fila
     * @param  string  $rol
     * @return bool
     */
    public static function tiene_duda(array $fila, $rol): bool
    {
        foreach (isset($fila['dudas']) && is_array($fila['dudas']) ? $fila['dudas'] : [] as $duda) {

            if (isset($duda['rol']) && $duda['rol'] === $rol) {

                return true;
            }
        }

        return false;
    }

    /**
     * El renglón sin las dudas de esos roles.
     *
     * @param  array  $fila
     * @param  array<int, string>  $roles
     * @return array
     */
    public static function sin_dudas_de(array $fila, array $roles): array
    {
        $quedan = [];

        foreach (isset($fila['dudas']) && is_array($fila['dudas']) ? $fila['dudas'] : [] as $duda) {

            if (!isset($duda['rol']) || !in_array($duda['rol'], $roles, true)) {

                $quedan[] = $duda;
            }
        }

        $fila['dudas'] = $quedan;

        return $fila;
    }

    /**
     * true si la columna del comprobante dice literalmente consumidor final («C FINAL», «CF»).
     *
     * @param  mixed  $comprobante
     * @return bool
     */
    public static function dice_consumidor_final($comprobante): bool
    {
        return (bool) preg_match('/^cf$|^c\.? ?final$|^cons(umidor)?\.? final$/', self::normalizar_texto($comprobante));
    }

    /**
     * Si el comprobante que pide la planilla es una factura A, una factura B o a consumidor final: 'a',
     * 'b' o null si no dice o no se entiende. Se usa solo con el valor de la columna del comprobante, donde
     * una letra sola ya es el tipo de factura.
     *
     * @param  mixed  $comprobante
     * @return string|null
     */
    public static function letra_de_comprobante($comprobante)
    {
        $texto = self::normalizar_texto($comprobante);

        if ($texto === '') {

            return null;
        }

        if (preg_match('/^((factura|fact\.?|fc) ?)?a$/', $texto)) {

            return 'a';
        }

        if (preg_match('/^((factura|fact\.?|fc) ?)?b$|^cf$|^c\.? ?final$|^cons(umidor)?\.? final$|^ticket$/', $texto)) {

            return 'b';
        }

        return null;
    }
}
