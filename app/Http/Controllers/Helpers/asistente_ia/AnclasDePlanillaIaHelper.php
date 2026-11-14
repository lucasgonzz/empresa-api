<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Models\AiMessage;
use App\Models\AiPlanilla;
use Carbon\Carbon;

/**
 * Lo que ata un cambio de la planilla a lo que dijo el dueño, por CÓDIGO (misión agente-venta-por-planilla,
 * revisión del 02/11/2026).
 *
 * 🔴 ES EL ERROR DEL 31/10: UN MODELO QUE CAMBIA LO ACORDADO SIN QUE NADIE SE LO PIDA. El dueño contestó «sí» a una
 * lista correcta y el modelo, por su cuenta, le puso 12 cajas a un renglón que tenía una, y el dueño confirmó sin
 * notarlo. La tabla manda, pero el modelo que conversa puede llamar a las herramientas que la cambian: una regla en el
 * prompt no lo frena, porque ese modelo es justamente al que hay que frenar. Por eso cada cambio se contrasta acá contra
 * lo que escribió la persona: la cita tiene que estar en su último mensaje, y el valor nuevo también.
 *
 * Lo que se compara son TEXTOS DE LA PERSONA, nunca lo que dice el modelo que dijo. No es un parser de intención: si el
 * valor no aparece, se rechaza, y el modelo le pregunta. Un rechazo de más cuesta una pregunta; uno de menos, una venta
 * mal cargada.
 *
 * 🔴 LA REGLA GENERAL (tercera revisión, 02/11/2026). Para cambiar algo YA RESUELTO, el último mensaje dice las dos cosas, con
 * palabras distintas:
 *
 *   1. QUÉ RENGLÓN: el nombre del cliente, su documento, una palabra del artículo que ESE renglón tiene hoy, su código o
 *      «renglón/fila/línea N» (sin_el_renglon()). Una fecha o un número suelto no nombran nada: «el 2 de octubre», «confirmo la 11».
 *   2. EL VALOR NUEVO: el número, o el artículo o el cliente nuevo. Se busca en lo que queda del mensaje después de sacar lo que
 *      nombró el renglón, así que una misma palabra o número no cuenta para las dos.
 *   3. Y SI EL MENSAJE NOMBRA DOS RENGLONES, el valor va pegado al renglón pedido (parte_del_renglon()): en «Bruno lleva 3 y Ana lleva 2»,
 *      Bruno estaba nombrado y el 2 también, y la venta de uno se cargaba con la cantidad del otro.
 *
 * Lo que está en duda o sin resolver se contesta con el valor solo, o con un «sí» si el valor estaba en lo que preguntó el asistente.
 * Esto reemplaza las excepciones que se fueron sumando por síntoma: `articulo` y `cliente` caen en la misma regla que `cantidad` e
 * `importe`, y es lo que frena el cruce del 31/10 («el taladro son 2» sobre el renglón de otro artículo).
 *
 * Sin escrituras. Las únicas consultas son para traer los mensajes.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class AnclasDePlanillaIaHelper
{
    /** Cuántos minutos hacia atrás vale lo que dijo el dueño, si la planilla es más nueva que eso. */
    const MINUTOS_DE_LA_VENTANA = 30;

    /** Tope de mensajes de la ventana: alcanza para cualquier charla sobre una planilla. */
    const TOPE_DE_MENSAJES = 60;

    /** Las palabras que nombran el total de la venta. Solas no definen nada: «en total son 11 ventas». Sirven para ver si nombra las dos cosas. */
    const PALABRAS_DEL_TOTAL = ['total', 'totales'];

    /** Lo mismo para el precio de una unidad. «c/u» queda «c u» al normalizar. 🔴 «unidades» a secas no: «son dos unidades, es el total». */
    const PALABRAS_DE_LA_UNIDAD = ['unidad', 'unitario', 'unitarios', 'c u'];

    /**
     * Lo que además nombra la unidad en la PREGUNTA del asistente: «¿el total o el precio de cada una?». 🔴 No van en PALABRAS_DE_LA_UNIDAD: ahí
     * «SON DOS UNIDADES ES EL TOTAL» y «en total son 11 ventas, cada una con su factura» pasarían a nombrar las dos cosas.
     */
    const OTRAS_DE_LA_UNIDAD_EN_LA_PREGUNTA = ['cada uno', 'cada una', 'unidades'];

    /** 🔴 Las frases que DEFINEN el importe como total: nada de «en total son 11 ventas». */
    const FRASES_DEL_TOTAL = ['es el total', 'son el total', 'es total', 'son totales', 'total de la venta', 'total de cada'];

    /** Las que lo definen por unidad. Más «cada uno a 500», con su número: «cada una con su factura» no dice nada. */
    const FRASES_DE_LA_UNIDAD = ['por unidad', 'precio unitario', 'c u'];

    /** Lo que puede acompañar a la respuesta corta de la pregunta por el importe: «el total», «es unitario», «por la unidad». */
    const RELLENO_DE_LA_RESPUESTA = 'es|son|el|la|lo|los|las|de|del|por|precio|importe|si|no|todo|todos|todas';

    /** Las palabras con las que el dueño cambia lo que trae el título de una columna, sin nombrar un renglón. */
    const PALABRAS_DE_TODAS = ['todas', 'todos', 'todo', 'toda la planilla'];

    /** Las palabras que dicen que se cobró, sin método todavía. Un método de pago dicho también alcanza: PlanillaIaHelper::dijo_que_se_cobro(). */
    const PALABRAS_DEL_CONTADO = ['contado', 'cobrada', 'cobradas', 'cobrado', 'cobrados', 'cobraron', 'pagaron', 'pagada', 'pagadas', 'pagado', 'pagados'];

    /** Los meses, enteros o como vienen en las planillas: «el 2 de octubre», «13-oct». */
    const MESES = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre|ene|feb|mar|abr|may|jun|jul|ago|sep|set|oct|nov|dic';

    /** Las palabras con las que el dueño manda una venta a la cuenta corriente. */
    const PALABRAS_DE_LA_CUENTA = ['cuenta', 'cuentas', 'cta', 'corriente', 'cc', 'fiado', 'fiar'];

    /**
     * El pedido explícito de sacar un renglón o la planilla, ya normalizado. 🔴 Nombrar el renglón no alcanza: «Bruno lleva 3 y Ana lleva 2» con
     * descartar se llevaba la venta, y «TODAS CON FECHA 31/10» la planilla entera. «no saques a Bruno» pide lo contrario.
     */
    const PEDIDO_DE_SACAR = '/(?<!no )\b(?:saca|borra|quita|descarta|anula)(?:r|la|lo|las|los)?\b|\bno (?:(?:la|lo|las|los|se) )?(?:cargues|cargue|carga|cargan|cargar)\b|\bno van?\b|\bno (?:es|era|son|eran)\b.{0,30}\b(?:cargar|ventas?)\b/';

    /** Lo que dice que no es toda la planilla: «sacá todas menos la de Bruno». */
    const PALABRAS_DE_UNA_EXCEPCION = ['menos', 'salvo', 'excepto'];

    /** Las palabras con las que el dueño habla de toda la planilla. */
    const PALABRAS_DE_TODA_LA_PLANILLA = ['planilla', 'planillas', 'todas', 'todos', 'nada', 'ninguna', 'ninguno'];

    /**
     * Las formas en las que el dueño nombra una venta que no es de la planilla. «otra venta» y las demás tal cual:
     * son las que abren la puerta de proponer_venta (PlanillaIaHelper::venta_suelta_frenada()).
     */
    const PALABRAS_DE_OTRA_VENTA = ['otra venta', 'aparte', 'ademas', 'tambien', 'fuera de la planilla'];

    /** Lo que no identifica a nada: «de», «la», y lo que dice cualquier charla sobre una planilla. */
    const PALABRAS_VACIAS = [
        'de', 'del', 'la', 'el', 'los', 'las', 'lo', 'con', 'sin', 'para', 'por', 'una', 'uno', 'unos', 'unas', 'que', 'mas', 'muy',
        'sa', 'srl', 'sas', 'saic', 'sac', 'art', 'articulo', 'articulos', 'producto', 'productos', 'renglon', 'renglones', 'cliente',
        'clientes', 'venta', 'ventas', 'caja', 'cajas', 'sucursal', 'deposito', 'tipo', 'metodo', 'pago',
    ];

    /** 🔴 Las del importe tampoco nombran nada: con «Amoladora Total» en la planilla, «ES EL TOTAL» definía solo ese renglón y los demás seguían preguntando. */
    const PALABRAS_DEL_IMPORTE = ['total', 'totales', 'subtotal', 'suma', 'unidad', 'unidades', 'unitario', 'unitarios', 'cada', 'precio', 'precios'];

    /** Los nombres con los que se dice un mismo método de pago: cualquiera vale por cualquiera, en los dos sentidos. */
    const ALIAS_DE_METODOS = [
        ['mercado pago', 'mp'],
        ['transferencia', 'transfe', 'transf'],
    ];

    /** Los números que se escriben con letras. 🔴 Sin «un» ni «una»: «sí, con un cliente nuevo» cambió una cantidad de 2 a 1. Ver cantidad_en(). */
    const NUMEROS_EN_LETRAS = [
        'media' => 0.5, 'medio' => 0.5, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6,
        'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15,
        'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19, 'veinte' => 20, 'treinta' => 30, 'cuarenta' => 40,
        'cincuenta' => 50, 'cien' => 100,
    ];

    // =====================================================================================
    // Comparaciones
    // =====================================================================================

    /**
     * Minúsculas, sin tildes y sin signos: las palabras separadas por un espacio.
     *
     * @param  mixed  $texto
     * @return string
     */
    public static function normalizar($texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Roles::normalizar_texto($texto)));
    }

    /**
     * true si la cita está, palabra por palabra, en el mensaje. Sin tildes ni signos: el modelo copia, y no tiene por qué
     * acertar la coma.
     *
     * @param  mixed  $cita
     * @param  mixed  $mensaje
     * @return bool
     */
    public static function cita_en($cita, $mensaje): bool
    {
        $cita = self::normalizar($cita);

        return $cita !== '' && strpos(' ' . self::normalizar($mensaje) . ' ', ' ' . $cita . ' ') !== false;
    }

    /**
     * true si alguna de esas palabras (o frases, ya normalizadas) está en alguno de los mensajes.
     *
     * @param  array<int, string>  $palabras
     * @param  array<int, string>  $mensajes
     * @return bool
     */
    public static function alguna_palabra_en(array $palabras, array $mensajes): bool
    {
        foreach ($mensajes as $mensaje) {

            foreach ($palabras as $palabra) {

                if (self::cita_en($palabra, $mensaje)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si esa cantidad está dicha en el texto. 🔴 COMO CANTIDAD: «1,500» es 1,5. Si valiera también como 1500, un modelo que manda
     * 1500 pasa y multiplica la venta por mil. Y «1.500» no dice ninguna de las dos (es ambigua): se le pregunta al dueño.
     *
     * «un» y «una» valen como 1 solo con el renglón nombrado en el mismo mensaje: «Ana Prueba lleva una caja» dice cuánto, y «sí, con un
     * cliente nuevo» no.
     *
     * @param  mixed  $cantidad
     * @param  mixed  $texto
     * @param  bool  $con_el_renglon_nombrado
     * @return bool
     */
    public static function cantidad_en($cantidad, $texto, $con_el_renglon_nombrado = false): bool
    {
        foreach (self::cifras_de($texto) as $cifra) {

            $leida = Roles::a_cantidad($cifra);

            if (!is_null($leida) && abs($leida - (float) $cantidad) < 0.0005) {

                return true;
            }
        }

        foreach (explode(' ', self::normalizar(self::sin_las_fechas($texto))) as $palabra) {

            if (isset(self::NUMEROS_EN_LETRAS[$palabra]) && abs((float) self::NUMEROS_EN_LETRAS[$palabra] - (float) $cantidad) < 0.0005) {

                return true;
            }

            if ($con_el_renglon_nombrado && ($palabra === 'un' || $palabra === 'una') && abs((float) $cantidad - 1) < 0.0005) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si el texto trae una cantidad que se puede leer de dos formas («1.500»).
     *
     * @param  mixed  $texto
     * @return bool
     */
    public static function trae_una_cantidad_ambigua($texto): bool
    {
        foreach (self::cifras_de($texto) as $cifra) {

            if (Roles::cantidad_ambigua($cifra)) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si ese importe está dicho en el texto, como plata: «1,500» y «1.500» son mil quinientos, y «50 mil» son cincuenta mil.
     *
     * @param  mixed  $importe
     * @param  mixed  $texto
     * @return bool
     */
    public static function importe_en($importe, $texto): bool
    {
        foreach (self::cifras_de($texto) as $cifra) {

            $leido = Roles::a_numero($cifra);

            if (!is_null($leido) && abs($leido - (float) $importe) < 0.005) {

                return true;
            }
        }

        if (preg_match_all('/(\d[\d.,]*)\s*mil\b/', Roles::normalizar_texto($texto), $coincidencias)) {

            foreach ($coincidencias[1] as $cifra) {

                $leido = Roles::a_numero($cifra);

                if (!is_null($leido) && abs($leido * 1000 - (float) $importe) < 0.005) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * El texto sin las fechas: 🔴 «Bruno compró el 2 de octubre» no dice que lleve 2, y «13/10» no es ni 13 ni 10.
     *
     * @param  mixed  $texto
     * @return string
     */
    protected static function sin_las_fechas($texto): string
    {
        return (string) preg_replace(
            ['/\b[0-9a-z]+\s+de\s+(?:' . self::MESES . ')\b/', '/\b\d{1,2}[\/\-](?:\d{1,2}|' . self::MESES . ')(?:[\/\-]\d{2,4})?\b/'],
            ' ',
            Roles::normalizar_texto($texto)
        );
    }

    /**
     * Las cifras sueltas de un texto: las que no son parte de una palabra («c107»), ni una fecha, ni el número de un renglón («el
     * renglón 3 está mal» no dice que la cantidad sea 3).
     *
     * @param  mixed  $texto
     * @return array<int, string>
     */
    protected static function cifras_de($texto): array
    {
        $normal = (string) preg_replace('/\b(renglon|renglones|fila|linea)\s+\d+/', ' ', self::sin_las_fechas($texto));

        $cifras = [];

        foreach (preg_split('/[^a-z0-9.,]+/', $normal) as $pedazo) {

            $pedazo = rtrim($pedazo, '.,');

            if (preg_match('/^\d[\d.,]*$/', $pedazo)) {

                $cifras[] = $pedazo;
            }
        }

        return $cifras;
    }

    /**
     * true si el texto nombra a ESE renglón: la primera mitad de la regla general (ver el docblock de la clase).
     *
     * @param  array  $fila
     * @param  mixed  $texto
     * @param  string  $campo  Ver sin_el_renglon().
     * @return bool
     */
    public static function nombra_el_renglon(array $fila, $texto, $campo = ''): bool
    {
        return self::normalizar(self::sin_el_renglon($fila, $texto, $campo)) !== self::normalizar($texto);
    }

    /**
     * true si el texto, además de lo que nombra a este renglón, nombra a otro de la planilla.
     *
     * 🔴 LO COMPARTIDO NO CUENTA: en una planilla real el mismo artículo se repite en muchas ventas, y «Bruno lleva 3 bombas» no habla de
     * las otras bombas. Por eso primero se saca lo que nombra a este. Y el valor nuevo tampoco: «a Bruno ponele el foco» no habla del
     * renglón que tiene el foco (ver $campo en sin_el_renglon()), y un número suelto puede ser la cantidad: «son tres» no habla de
     * «Carla Tres», ni «lleva 12» del renglón con el código 12.
     *
     * @param  array  $fila
     * @param  array  $filas  Todos los renglones de la planilla.
     * @param  mixed  $texto
     * @param  string  $campo  El campo que se está cambiando.
     * @return bool
     */
    public static function nombra_otro_renglon(array $fila, array $filas, $texto, $campo): bool
    {
        $resto = (string) preg_replace(
            '/(?<!renglon )(?<!fila )(?<!linea )\b(?:\d{1,3}|' . implode('|', array_keys(self::NUMEROS_EN_LETRAS)) . ')\b/',
            ' ',
            self::sin_el_renglon($fila, $texto)
        );

        foreach ($filas as $otra) {

            if ((int) $otra['numero'] !== (int) $fila['numero'] && self::nombra_el_renglon($otra, $resto, $campo)) {

                return true;
            }
        }

        return false;
    }

    /**
     * Las partes de la cita que nombran a este renglón y a ningún otro: ahí tiene que estar el valor si el mensaje nombra dos renglones.
     * Las separan una coma, un punto, un punto y coma, dos puntos, un renglón o una «y». Sin nada que separe («Bruno 3 Ana 5») no queda
     * ninguna: no hay cómo saber de quién es cada número, y se pregunta. La coma de un decimal y el punto de los miles no separan.
     *
     * @param  array  $fila
     * @param  array  $filas  Todos los renglones de la planilla.
     * @param  mixed  $cita
     * @param  string  $campo  El campo que se está cambiando.
     * @return string  '' si no hay ninguna.
     */
    public static function parte_del_renglon(array $fila, array $filas, $cita, $campo): string
    {
        $partes = [];

        foreach (preg_split('/[,;:.]\s+|\n+|\s+(?:y|e)\s+/iu', (string) $cita) as $parte) {

            if (self::nombra_el_renglon($fila, $parte) && !self::nombra_otro_renglon($fila, $filas, $parte, $campo)) {

                $partes[] = $parte;
            }
        }

        return implode(', ', $partes);
    }

    /**
     * El texto sin lo que nombra a ese renglón: en lo que queda se busca el valor nuevo, y por eso una misma palabra o número no
     * cuenta para las dos cosas. Conserva puntos y comas, que hacen falta para leer un número.
     *
     * 🔴 LO QUE EL RENGLÓN TIENE HOY: si el dueño ya le cambió el artículo o el cliente, lo que decía la planilla dejó de nombrarlo.
     * Y «renglón/fila/línea N» con esa palabra delante: «el 2 de octubre», «confirmo la 11», «son 2 unidades» o «Bruno Dos» no son el
     * renglón 2. De cada palabra se saca una sola vez: «la bomba beta es la bomba alfa» nombra a una y dice la otra.
     *
     * @param  array  $fila
     * @param  mixed  $texto
     * @param  string  $campo  Al mirar si el texto nombra a OTRO renglón, el campo que se cambia: lo que dice el valor nuevo no nombra a
     *                         nadie («a Bruno ponele el foco», «la bomba es de Ana»).
     * @return string
     */
    public static function sin_el_renglon(array $fila, $texto, $campo = ''): string
    {
        $valores = isset($fila['valores']) && is_array($fila['valores']) ? $fila['valores'] : [];

        $limpio = (string) preg_replace('/\b(?:renglon|fila|linea)\s+(?:' . implode('|', self::formas_del_numero((int) $fila['numero'])) . ')\b/', ' ', Roles::normalizar_texto($texto), 1);

        $del_cliente = [$campo === 'cliente' ? '' : TextoDePlanillaIaHelper::nombre_del_cliente($fila)];

        if (empty($fila['cliente_elegido_id']) && $campo !== 'cliente') {

            $del_cliente[] = isset($valores[Roles::NOMBRE]) ? $valores[Roles::NOMBRE] : '';

            $limpio = self::sin_el_documento(isset($valores[Roles::DOCUMENTO]) ? $valores[Roles::DOCUMENTO] : '', $limpio);
        }

        foreach ($del_cliente as $nombre) {

            $limpio = self::sin_las_palabras_de($nombre, $limpio, 3);
        }

        $del_articulo = [$campo === 'articulo' || !isset($fila['articulo_nombre']) ? '' : $fila['articulo_nombre']];

        if (empty($fila['articulo_elegido_id']) && $campo !== 'articulo') {

            $del_articulo[] = isset($valores[Roles::DESCRIPCION]) ? $valores[Roles::DESCRIPCION] : '';

            $limpio = self::sin_la_frase(isset($valores[Roles::CODIGO]) ? Roles::codigo_limpio($valores[Roles::CODIGO]) : '', $limpio);
        }

        foreach ($del_articulo as $nombre) {

            $limpio = self::sin_las_palabras_de($nombre, self::sin_la_frase($nombre, $limpio), 4);
        }

        return $limpio;
    }

    /**
     * Las formas en las que se dice ese número: el dígito, y las letras si las tiene.
     *
     * @param  int  $numero
     * @return array<int, string>
     */
    protected static function formas_del_numero($numero): array
    {
        $formas = [(string) (int) $numero];

        foreach (self::NUMEROS_EN_LETRAS as $palabra => $valor) {

            if ((float) $valor === (float) (int) $numero) {

                $formas[] = $palabra;
            }
        }

        return $formas;
    }

    /**
     * El texto sin ese documento, con o sin separadores.
     *
     * @param  mixed  $documento
     * @param  string  $texto
     * @return string
     */
    protected static function sin_el_documento($documento, $texto): string
    {
        $digitos = Roles::solo_digitos($documento);

        if ($digitos === '') {

            return $texto;
        }

        return (string) preg_replace_callback('/\d[\d.\-]*\d/', function ($cifra) use ($digitos) {

            return Roles::solo_digitos($cifra[0]) === $digitos ? ' ' : $cifra[0];

        }, $texto);
    }

    /**
     * El texto sin esa frase, entera: un código con guiones («ZRRI-50LF») o un nombre con todas sus palabras cortas («Led 30w»). Una frase hecha
     * solo de palabras del importe no nombra nada: una descripción «TOTAL» no la nombra «ES EL TOTAL».
     *
     * @param  mixed  $frase
     * @param  string  $texto
     * @return string
     */
    protected static function sin_la_frase($frase, $texto): string
    {
        $palabras = self::normalizar($frase);

        if ($palabras === '' || !count(array_diff(explode(' ', $palabras), self::PALABRAS_DEL_IMPORTE))) {

            return $texto;
        }

        return (string) preg_replace('/(?<![a-z0-9])' . implode('[^a-z0-9]+', array_map('preg_quote', explode(' ', $palabras))) . '(?![a-z0-9])/', ' ', $texto, 1);
    }

    /**
     * El texto sin las palabras significativas de ese nombre, una vez cada una y con la ese del plural para los dos lados.
     *
     * @param  mixed  $nombre
     * @param  string  $texto
     * @param  int  $minimo  Letras que tiene que tener la palabra.
     * @return string
     */
    protected static function sin_las_palabras_de($nombre, $texto, $minimo): string
    {
        foreach (self::palabras_de($nombre, $minimo) as $palabra) {

            $texto = (string) preg_replace('/(?<![a-z0-9])' . preg_quote(rtrim($palabra, 's')) . 's?(?![a-z0-9])/', ' ', $texto, 1);
        }

        return $texto;
    }

    /**
     * Qué dice ese mensaje del importe: total, unidad, las_dos o null si no lo define.
     *
     * 🔴 SOLO DEFINE UNA FRASE INEQUÍVOCA O LA RESPUESTA A LA PREGUNTA. «En total son 11 ventas» o «cada una con su factura» no definen
     * nada, y un importe mal definido multiplica una venta sin que nadie lo note. La respuesta corta («el total», «unitario») vale solo si
     * lo último que dijo el asistente preguntaba eso. Si el mensaje nombra las dos cosas, las_dos: se pregunta.
     *
     * @param  mixed  $mensaje
     * @param  mixed  $pregunta_del_asistente  Lo último que dijo el asistente, si este mensaje es el que le contesta.
     * @return string|null
     */
    public static function lo_que_dice_del_importe($mensaje, $pregunta_del_asistente = '')
    {
        $crudo = Roles::normalizar_texto($mensaje);

        /* «Es el total y van a la cuenta corriente de cada uno»: ese «cada uno» es de los clientes, no del precio. */
        $mensaje = (string) preg_replace('/\bcuenta( corriente)? de cada (uno|una)\b/', ' ', self::normalizar($mensaje));

        $cada_uno_a = (bool) preg_match('/\bcada (?:uno|una) a \d/', $mensaje);

        if (self::alguna_palabra_en(self::PALABRAS_DEL_TOTAL, [$mensaje]) && ($cada_uno_a || self::alguna_palabra_en(self::PALABRAS_DE_LA_UNIDAD, [$mensaje]))) {

            return 'las_dos';
        }

        if (self::alguna_palabra_en(self::FRASES_DEL_TOTAL, [$mensaje])) {

            return AiPlanilla::IMPORTE_TOTAL;
        }

        if ($cada_uno_a || self::alguna_palabra_en(self::FRASES_DE_LA_UNIDAD, [$mensaje])) {

            return AiPlanilla::IMPORTE_UNIDAD;
        }

        return self::respuesta_a_la_pregunta_del_importe($crudo, $pregunta_del_asistente);
    }

    /**
     * Lo que contesta una respuesta corta, si el asistente preguntó justo eso: nombró el total y la unidad. Corta quiere decir que esa
     * parte del mensaje no dice otra cosa: «en total son 11 ventas» o «cada una con su factura» no contestan, por más que el asistente
     * haya preguntado. 🔴 POR PARTES: el modelo pregunta todo junto, y «total, efectivo, caja efectivo» o «total en efectivo» contesta las
     * tres cosas. Si se rechazara, le estaríamos pidiendo otra vez lo que acaba de decir.
     *
     * 🔴 LOS RECHAZOS LE PIDEN AL MODELO PREGUNTAR DE FORMA NATURAL, así que la pregunta puede ser «¿el total o el precio de cada una?» o «¿por las
     * dos unidades o por cada una?», que dice el total sin nombrarlo: ahí «por las dos» contesta. «unidades» sola nunca contesta.
     *
     * @param  string  $mensaje  En minúsculas y sin tildes, con sus signos: separan las partes.
     * @param  mixed  $pregunta
     * @return string|null
     */
    protected static function respuesta_a_la_pregunta_del_importe($mensaje, $pregunta)
    {
        $numero = '\d+|' . implode('|', array_keys(self::NUMEROS_EN_LETRAS));

        $por_las_unidades = (bool) preg_match('/\b(?:' . $numero . ') unidades\b/', self::normalizar($pregunta));

        if ((!$por_las_unidades && !self::alguna_palabra_en(self::PALABRAS_DEL_TOTAL, [$pregunta]))
            || !self::alguna_palabra_en(array_merge(self::PALABRAS_DE_LA_UNIDAD, self::OTRAS_DE_LA_UNIDAD_EN_LA_PREGUNTA), [$pregunta])) {

            return null;
        }

        foreach (preg_split('/[,;:.\n]+|\s+y\s+/u', $mensaje) as $parte) {

            $sin_relleno = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\b(?:' . self::RELLENO_DE_LA_RESPUESTA . ')\b/', ' ', self::normalizar($parte))));

            /* «total en efectivo», «unitario a la cuenta»: lo que sigue es otra respuesta. */
            if (preg_match('/^(?:total|totales)(?: (?:en|con|a|al)\b.*)?$/', $sin_relleno)) {

                return AiPlanilla::IMPORTE_TOTAL;
            }

            if (preg_match('/^(?:(?:unidad|unitario|unitarios|c u)(?: (?:en|con|a|al)\b.*)?|cada uno|cada una)$/', $sin_relleno)) {

                return AiPlanilla::IMPORTE_UNIDAD;
            }

            if ($por_las_unidades && preg_match('/^(?:' . $numero . ')(?: unidades)?$/', $sin_relleno)) {

                return AiPlanilla::IMPORTE_TOTAL;
            }
        }

        return null;
    }

    /**
     * true si el mensaje es un «sí» a lo que preguntó el asistente, con palabras que lo acompañan: «SI ES ESTE», «sí, esa».
     *
     * 🔴 SOLO PARA CONTESTAR UNA DUDA (PlanillaIaHelper::corregir()). No es ConfirmacionDeterministaIaHelper::es_afirmacion(), que ejecuta
     * una carga: «sí, esa» puede estar hablando de otra cosa, y acá lo único que hace es elegir el valor que el asistente acaba de preguntar.
     *
     * @param  mixed  $texto
     * @return bool
     */
    public static function es_si_a_una_duda($texto): bool
    {
        if (preg_match('/[?¿]/u', (string) $texto)) {

            return false;
        }

        return ConfirmacionDeterministaIaHelper::es_afirmacion(preg_replace('/\b(?:es|ese|esa|este|esta|eso|mismo|misma)\b/', ' ', self::normalizar($texto)));
    }

    /**
     * true si el texto pide sacar algo: ver PEDIDO_DE_SACAR.
     *
     * @param  mixed  $texto
     * @return bool
     */
    public static function pide_sacar($texto): bool
    {
        return (bool) preg_match(self::PEDIDO_DE_SACAR, self::normalizar($texto));
    }

    /**
     * true si el documento está en el mensaje, con o sin separadores.
     *
     * @param  mixed  $documento
     * @param  mixed  $mensaje
     * @return bool
     */
    public static function documento_en($documento, $mensaje): bool
    {
        $digitos = Roles::solo_digitos($documento);

        if ($digitos === '' || !preg_match_all('/\d[\d.\-]*\d/', (string) $mensaje, $coincidencias)) {

            return false;
        }

        foreach ($coincidencias[0] as $cifra) {

            if (Roles::solo_digitos($cifra) === $digitos) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si alguna palabra significativa del texto está en el mensaje: el nombre de un artículo o de un cliente. Con la
     * ese del plural para los dos lados: «bombas» nombra a «Bomba».
     *
     * @param  mixed  $texto
     * @param  mixed  $mensaje
     * @param  int  $minimo  Letras que tiene que tener la palabra.
     * @return bool
     */
    public static function palabra_de_en($texto, $mensaje, $minimo): bool
    {
        $normalizado = ' ' . self::normalizar($mensaje) . ' ';

        foreach (self::palabras_de($texto, $minimo) as $palabra) {

            $sin_ese = rtrim($palabra, 's');

            if (strpos($normalizado, ' ' . $palabra . ' ') !== false
                || strpos($normalizado, ' ' . $sin_ese . ' ') !== false
                || strpos($normalizado, ' ' . $sin_ese . 's ') !== false) {

                return true;
            }
        }

        return false;
    }

    /**
     * Las palabras que identifican a algo: sin las vacías, sin números sueltos y con ese largo como mínimo.
     *
     * @param  mixed  $texto
     * @param  int  $minimo
     * @return array<int, string>
     */
    public static function palabras_de($texto, $minimo): array
    {
        return array_values(array_filter(explode(' ', self::normalizar($texto)), function ($palabra) use ($minimo) {

            return mb_strlen($palabra) >= (int) $minimo && !ctype_digit($palabra) && !in_array($palabra, self::PALABRAS_VACIAS, true) && !in_array($palabra, self::PALABRAS_DEL_IMPORTE, true);
        }));
    }

    /**
     * true si un valor elegido entre opciones (un método de pago, una caja, una sucursal) está dicho en alguno de los
     * mensajes, por alguna de sus palabras o por un alias.
     *
     * @param  mixed  $valor
     * @param  array<int, string>  $mensajes
     * @return bool
     */
    public static function valor_en_mensajes($valor, array $mensajes): bool
    {
        $formas = [$valor];

        foreach (self::ALIAS_DE_METODOS as $grupo) {

            foreach ($grupo as $forma) {

                if (self::junto_o_separado_en($forma, $valor)) {

                    $formas = array_merge($formas, $grupo);

                    break;
                }
            }
        }

        foreach ($mensajes as $mensaje) {

            if (self::palabra_de_en($valor, $mensaje, 3)) {

                return true;
            }

            /* El nombre entero: «Caja 2» tiene una palabra vacía y un número, y por palabras nunca aparecería. */
            foreach ($formas as $forma) {

                if (self::junto_o_separado_en($forma, $mensaje)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si esa frase está en el texto, con o sin los espacios: «MercadoPago» dice «mercado pago», y al revés. Entera: «mp» no está en «compra».
     *
     * @param  mixed  $frase
     * @param  mixed  $texto
     * @return bool
     */
    protected static function junto_o_separado_en($frase, $texto): bool
    {
        $letras = str_replace(' ', '', self::normalizar($frase));

        return $letras !== '' && (bool) preg_match('/(?<![a-z0-9])' . implode(' ?', str_split($letras)) . '(?![a-z0-9])/', self::normalizar($texto));
    }

    // =====================================================================================
    // Los mensajes
    // =====================================================================================

    /**
     * El último mensaje del dueño antes de este turno.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $assistant_message
     * @return string
     */
    public static function ultimo_mensaje(ContextoDeCargaIa $contexto, $assistant_message): string
    {
        return (string) self::mensajes_del_dueno($contexto, $assistant_message)->value('contenido');
    }

    /**
     * Lo último que dijo el asistente antes del último mensaje del dueño: es lo que un «sí» está contestando.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $assistant_message
     * @return string
     */
    public static function anterior_del_asistente(ContextoDeCargaIa $contexto, $assistant_message): string
    {
        $ultimo = self::mensajes_del_dueno($contexto, $assistant_message)->value('id');

        if (is_null($ultimo)) {

            return '';
        }

        return (string) AiMessage::where('ai_conversation_id', (int) $contexto->conversation->id)
                                    ->where('rol', 'assistant')
                                    ->where('id', '<', (int) $ultimo)
                                    ->whereNotNull('contenido')
                                    ->orderBy('id', 'DESC')
                                    ->value('contenido');
    }

    /**
     * Lo que el dueño dijo desde que nació la planilla o en los últimos minutos (lo más viejo de los dos), en esta charla
     * y en sus hermanas. 🔴 Sin los mensajes del asistente: lo que dijo el modelo no ancla nada.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  mixed  $assistant_message
     * @return array<int, string>
     */
    public static function mensajes_de_la_ventana(ContextoDeCargaIa $contexto, AiPlanilla $planilla, $assistant_message): array
    {
        $desde = Carbon::now()->subMinutes(self::MINUTOS_DE_LA_VENTANA);

        if (!is_null($planilla->created_at) && $planilla->created_at->lt($desde)) {

            $desde = $planilla->created_at;
        }

        $consulta = AiMessage::whereIn('ai_conversation_id', FotosDeLaConversacionIaHelper::conversaciones_del_mismo_canal($contexto))
                                ->where('rol', 'user')
                                ->where('created_at', '>=', $desde);

        if ($assistant_message instanceof AiMessage) {

            $consulta->where('id', '<', (int) $assistant_message->id);
        }

        $mensajes = [];

        foreach ($consulta->orderBy('id', 'DESC')->limit(self::TOPE_DE_MENSAJES)->pluck('contenido') as $contenido) {

            $mensajes[] = (string) $contenido;
        }

        return $mensajes;
    }

    /**
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $assistant_message
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function mensajes_del_dueno(ContextoDeCargaIa $contexto, $assistant_message)
    {
        $consulta = AiMessage::where('ai_conversation_id', (int) $contexto->conversation->id)->where('rol', 'user');

        if ($assistant_message instanceof AiMessage) {

            $consulta->where('id', '<', (int) $assistant_message->id);
        }

        return $consulta->orderBy('id', 'DESC');
    }
}
