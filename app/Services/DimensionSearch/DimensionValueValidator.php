<?php

namespace App\Services\DimensionSearch;

/**
 * Valida y convierte UN dato físico (peso o una medida) que devolvió la IA al leer una página (misión
 * medidas-masivas-catalogo, 27/10/2026). Es el corazón del pedido «si vienen en mm, que se transformen».
 *
 * 🔴 NO SE CONFÍA EN LA CONVERSIÓN DEL MODELO. Cada campo llega como `{value, unit, evidence,
 * source_url, scope}`. Lo que el modelo diga en `value` y `unit` es solo una AFIRMACIÓN que este
 * validador comprueba contra la cita:
 *
 *   1. La cita (`evidence`) tiene que estar literalmente en el texto de la página que se le pasó a la
 *      IA (con espacios y mayúsculas normalizados) y `source_url` tiene que ser una de esas páginas.
 *   2. La cita se PARSEA por cuenta propia: se buscan pares número + unidad («70 mm», «820 g», «0,0109 kg»,
 *      «16"») y agrupaciones tipo «70 x 11 x 100 mm» (varios números con una sola unidad al final que
 *      vale para todos). Cada par se convierte a la unidad de la base: peso en kg; alto, ancho y
 *      profundidad en cm.
 *   3. El campo VALE si algún par de la cita, ya convertido, coincide con el `value` del modelo
 *      convertido desde su `unit` (tolerancia relativa del 1 % o 0,001 absoluto). Así «70 mm» con el
 *      modelo diciendo 7 cm vale, «820 g» con 0,82 kg vale, y el modelo diciendo 70 cm con la cita «70 mm»
 *      NO vale (70 cm contra 7 cm).
 *   4. Lo que se guarda es el valor del par de la CITA, convertido por este validador (4 decimales), con
 *      el valor y la unidad originales y la marca `convertido`.
 *   5. Topes de cordura: peso en (0, 2000] kg; cada medida en (0, 1000] cm.
 *
 * Qué NO garantiza: que el número de la cita sea el de ESTE campo y no el de otra medida de la misma
 * cita (en «70 x 11 x 100 mm», el modelo dice cuál es el alto y cuál el ancho). Esa orientación es la
 * de la IA y por eso las asignaciones solas exigen además código en la página y coincidencia exacta.
 *
 * ProductFactsResearchService::shippingValue() es OTRA regla, más estricta (exige palabras de envío y
 * que el número de la cita sea el mismo que el valor) y no se toca ni se reutiliza: rechaza buenos datos.
 *
 * Sin estado ni red: puro texto y números, para poder probarlo con casos reales.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionValueValidator
{
    const TIPO_PESO   = 'peso';
    const TIPO_MEDIDA = 'medida';

    /** Campo del artículo => tipo de magnitud. `profundidad` es el largo. */
    const CAMPOS = [
        'peso'        => self::TIPO_PESO,
        'alto'        => self::TIPO_MEDIDA,
        'ancho'       => self::TIPO_MEDIDA,
        'profundidad' => self::TIPO_MEDIDA,
    ];

    /** La unidad de la base de cada tipo. */
    const UNIDAD_CANONICA = [
        self::TIPO_PESO   => 'kg',
        self::TIPO_MEDIDA => 'cm',
    ];

    /** Topes de cordura (en la unidad de la base). */
    const MAXIMO_PESO_KG   = 2000;
    const MAXIMO_MEDIDA_CM = 1000;

    /** Tolerancia al comparar el valor del modelo con el de la cita: 1 % relativo o 0,001 absoluto. */
    const TOLERANCIA_RELATIVA = 0.01;
    const TOLERANCIA_ABSOLUTA = 0.001;

    /** Decimales con los que se guarda (la base es decimal(30,4)). */
    const DECIMALES = 4;

    /**
     * Las unidades que se reconocen: alias (en minúsculas y sin tildes) => [tipo, factor a la unidad de
     * la base, etiqueta que se muestra]. Peso a kg; medidas a cm.
     */
    const UNIDADES = [
        // Peso.
        'kg'          => [self::TIPO_PESO, 1, 'kg'],
        'kgs'         => [self::TIPO_PESO, 1, 'kg'],
        'kilo'        => [self::TIPO_PESO, 1, 'kg'],
        'kilos'       => [self::TIPO_PESO, 1, 'kg'],
        'kilogramo'   => [self::TIPO_PESO, 1, 'kg'],
        'kilogramos'  => [self::TIPO_PESO, 1, 'kg'],
        'g'           => [self::TIPO_PESO, 0.001, 'g'],
        'gr'          => [self::TIPO_PESO, 0.001, 'g'],
        'grs'         => [self::TIPO_PESO, 0.001, 'g'],
        'gramo'       => [self::TIPO_PESO, 0.001, 'g'],
        'gramos'      => [self::TIPO_PESO, 0.001, 'g'],
        'mg'          => [self::TIPO_PESO, 0.000001, 'mg'],
        'miligramo'   => [self::TIPO_PESO, 0.000001, 'mg'],
        'miligramos'  => [self::TIPO_PESO, 0.000001, 'mg'],
        'lb'          => [self::TIPO_PESO, 0.45359237, 'lb'],
        'lbs'         => [self::TIPO_PESO, 0.45359237, 'lb'],
        'libra'       => [self::TIPO_PESO, 0.45359237, 'lb'],
        'libras'      => [self::TIPO_PESO, 0.45359237, 'lb'],
        'oz'          => [self::TIPO_PESO, 0.028349523125, 'oz'],
        'onza'        => [self::TIPO_PESO, 0.028349523125, 'oz'],
        'onzas'       => [self::TIPO_PESO, 0.028349523125, 'oz'],
        't'           => [self::TIPO_PESO, 1000, 't'],
        'tn'          => [self::TIPO_PESO, 1000, 't'],
        'ton'         => [self::TIPO_PESO, 1000, 't'],
        'tonelada'    => [self::TIPO_PESO, 1000, 't'],
        'toneladas'   => [self::TIPO_PESO, 1000, 't'],
        // Medidas.
        'cm'          => [self::TIPO_MEDIDA, 1, 'cm'],
        'cms'         => [self::TIPO_MEDIDA, 1, 'cm'],
        'centimetro'  => [self::TIPO_MEDIDA, 1, 'cm'],
        'centimetros' => [self::TIPO_MEDIDA, 1, 'cm'],
        'mm'          => [self::TIPO_MEDIDA, 0.1, 'mm'],
        'mms'         => [self::TIPO_MEDIDA, 0.1, 'mm'],
        'milimetro'   => [self::TIPO_MEDIDA, 0.1, 'mm'],
        'milimetros'  => [self::TIPO_MEDIDA, 0.1, 'mm'],
        'm'           => [self::TIPO_MEDIDA, 100, 'm'],
        'mt'          => [self::TIPO_MEDIDA, 100, 'm'],
        'mts'         => [self::TIPO_MEDIDA, 100, 'm'],
        'metro'       => [self::TIPO_MEDIDA, 100, 'm'],
        'metros'      => [self::TIPO_MEDIDA, 100, 'm'],
        // 🔴 «in» pelado NO es pulgada (es la preposición inglesa, o parte de un código): solo «"», «pulg»,
        // «pulgadas», «inch» e «inches».
        'inch'        => [self::TIPO_MEDIDA, 2.54, 'in'],
        'inches'      => [self::TIPO_MEDIDA, 2.54, 'in'],
        'pulg'        => [self::TIPO_MEDIDA, 2.54, 'in'],
        'pulgada'     => [self::TIPO_MEDIDA, 2.54, 'in'],
        'pulgadas'    => [self::TIPO_MEDIDA, 2.54, 'in'],
        '"'           => [self::TIPO_MEDIDA, 2.54, 'in'],
        '″'           => [self::TIPO_MEDIDA, 2.54, 'in'],
        '”'           => [self::TIPO_MEDIDA, 2.54, 'in'],
    ];

    /**
     * Las palabras que, en la cita, dicen A QUÉ medida corresponde el número (sin tildes, en minúsculas).
     * Para una asignación sola, la cita de cada campo tiene que traer una de las suyas, o una de las
     * genéricas, o ser una agrupación «A x B x C unidad». Una cita pelada («25 cm») no dice qué medida es.
     */
    const ETIQUETAS_DEL_CAMPO = [
        'peso'        => ['peso', 'pesa', 'weight'],
        'alto'        => ['alto', 'altura', 'height'],
        'ancho'       => ['ancho', 'anchura', 'width'],
        'profundidad' => ['largo', 'longitud', 'profundidad', 'fondo', 'length', 'depth'],
    ];

    /** Etiquetas que valen para cualquier campo de medidas / de peso. */
    const ETIQUETAS_GENERICAS_DE_MEDIDAS = ['medidas', 'dimensiones', 'dimensions', 'tamano', 'tamaño', 'size', 'envase', 'embalaje'];
    const ETIQUETAS_GENERICAS_DE_PESO    = ['envase', 'embalaje'];

    /** Alcance que se guarda cuando la IA no dice uno válido. */
    const ALCANCES = ['envio', 'producto'];

    /** @var string|null Alternativa de regex con las unidades de letras (cacheada). */
    protected static $regex_de_unidades = null;

    /**
     * Valida un campo.
     *
     * @param  string $campo    peso | alto | ancho | profundidad.
     * @param  mixed  $dato     Lo que devolvió la IA: {value, unit, evidence, source_url, scope} o null.
     * @param  array  $paginas  url => texto de la página, EL MISMO texto que se le pasó a la IA.
     * @return array  ['ok' => bool, 'motivo' => string|null, 'dato' => array|null]. Con `ok`, `dato` trae
     *                valor (en kg o cm), unidad ('kg'|'cm'), valor_original, unidad_original, convertido,
     *                cita, url y alcance. Sin `ok`, `motivo` es un código corto para el diagnóstico.
     */
    public function validar($campo, $dato, array $paginas)
    {
        if (!isset(self::CAMPOS[$campo])) {
            return $this->rechazo('campo_desconocido');
        }

        $tipo = self::CAMPOS[$campo];

        // Sin dato: la IA no encontró evidencia. No es un error, es la respuesta «no está».
        if (!is_array($dato) || empty($dato)) {
            return $this->rechazo('no_informado');
        }

        $valor = $this->numero_del_modelo(isset($dato['value']) ? $dato['value'] : null);

        if (is_null($valor) || $valor <= 0) {
            return $this->rechazo('valor_invalido');
        }

        $unidad = $this->unidad_del_modelo(isset($dato['unit']) ? $dato['unit'] : null);

        if (is_null($unidad) || $unidad[0] !== $tipo) {
            return $this->rechazo('unidad_invalida');
        }

        $cita = isset($dato['evidence']) && is_string($dato['evidence']) ? trim($dato['evidence']) : '';

        if ($cita === '') {
            return $this->rechazo('sin_cita');
        }

        $url = isset($dato['source_url']) && is_string($dato['source_url']) ? $dato['source_url'] : '';

        if ($url === '' || !isset($paginas[$url])) {
            return $this->rechazo('fuente_desconocida');
        }

        if (!$this->cita_esta_en_la_pagina($cita, (string) $paginas[$url])) {
            return $this->rechazo('cita_no_esta_en_la_pagina');
        }

        // Lo que el modelo afirma, llevado a la unidad de la base.
        $afirmado = $valor * $unidad[1];

        // Lo que la cita dice por su cuenta: el par más cercano a lo afirmado.
        $mejor = null;

        foreach ($this->parsear_pares($cita) as $par) {
            if ($par['tipo'] !== $tipo) {
                continue;
            }

            if (!$this->coincide($par['canonico'], $afirmado)) {
                continue;
            }

            // Un par limpio le gana a uno ambiguo o de rango; entre iguales, el más cercano a lo afirmado.
            if (is_null($mejor) || $this->es_mejor($par, $mejor, $afirmado)) {
                $mejor = $par;
            }
        }

        if (is_null($mejor)) {
            return $this->rechazo('cita_no_coincide_con_el_valor');
        }

        $canonico = round($mejor['canonico'], self::DECIMALES);
        $maximo   = $tipo === self::TIPO_PESO ? self::MAXIMO_PESO_KG : self::MAXIMO_MEDIDA_CM;

        if ($canonico <= 0 || $canonico > $maximo) {
            return $this->rechazo('fuera_de_rango');
        }

        $alcance = isset($dato['scope']) && in_array($dato['scope'], self::ALCANCES, true) ? $dato['scope'] : 'producto';

        // Lo que obliga a que una persona lo mire: el punto de miles que puede ser decimal y el rango.
        $aviso = null;

        if (!empty($mejor['rango'])) {
            $aviso = 'La página da un rango: '.$mejor['texto'];
        } elseif (!empty($mejor['ambiguo'])) {
            $aviso = $this->frase_de_ambiguedad($mejor);
        }

        return [
            'ok'     => true,
            'motivo' => null,
            'dato'   => [
                'valor'           => $canonico,
                'unidad'          => self::UNIDAD_CANONICA[$tipo],
                'valor_original'  => $mejor['numero'],
                'unidad_original' => $mejor['etiqueta'],
                'convertido'      => $mejor['etiqueta'] !== self::UNIDAD_CANONICA[$tipo],
                'cita'            => mb_substr($cita, 0, 300),
                'url'             => $url,
                'alcance'         => $alcance,
                // 🔴 B1: «1.200 g» puede ser 1,2 o 1.200. Se guarda la lectura que dijo el modelo, pero un
                // valor ambiguo NUNCA se escribe solo.
                'ambiguo'         => !empty($mejor['ambiguo']),
                // M2: «10-20 cm» da un rango; el extremo que valida no es «la» medida. Tampoco se escribe solo.
                'rango'           => !empty($mejor['rango']),
                // M1(c): la cita dice a qué medida corresponde (una etiqueta del campo o «A x B x C unidad»).
                'etiquetada'      => !empty($mejor['grupo']) || $this->cita_tiene_etiqueta($campo, $cita),
                // La frase lista para mostrar cuando hay ambigüedad o rango (null si no).
                'aviso'           => $aviso,
            ],
        ];
    }

    /**
     * ¿El par candidato es mejor que el mejor hasta ahora? Primero el más limpio (sin ambigüedad ni rango),
     * después el más cercano a lo que afirmó el modelo.
     *
     * @param  array $candidato
     * @param  array $actual
     * @param  float $afirmado
     * @return bool
     */
    protected function es_mejor(array $candidato, array $actual, $afirmado)
    {
        $penalidad_candidato = (empty($candidato['ambiguo']) ? 0 : 1) + (empty($candidato['rango']) ? 0 : 1);
        $penalidad_actual    = (empty($actual['ambiguo']) ? 0 : 1) + (empty($actual['rango']) ? 0 : 1);

        if ($penalidad_candidato !== $penalidad_actual) {
            return $penalidad_candidato < $penalidad_actual;
        }

        $diferencia_candidato = abs($candidato['canonico'] - $afirmado);
        $diferencia_actual    = abs($actual['canonico'] - $afirmado);

        // Misma distancia (el mismo número leído suelto y dentro de «A x B x C unidad»): gana el de la agrupación,
        // que además dice de qué medidas habla.
        if (abs($diferencia_candidato - $diferencia_actual) < 0.000000001) {
            return !empty($candidato['grupo']) && empty($actual['grupo']);
        }

        return $diferencia_candidato < $diferencia_actual;
    }

    /**
     * «La página escribe «1.200 g»: puede ser 1,2 o 1.200».
     *
     * @param  array $par  Un par ambiguo (trae `texto` y `token`).
     * @return string
     */
    protected function frase_de_ambiguedad(array $par)
    {
        $partes = preg_split('/[.,]/', (string) $par['token']);
        $entera = $partes[0];
        $miles  = isset($partes[1]) ? $partes[1] : '';

        $decimal = rtrim($miles, '0');

        return 'La página escribe «'.$par['texto'].'»: puede ser '.$entera.($decimal === '' ? '' : ','.$decimal).' o '.$entera.'.'.$miles;
    }

    /**
     * ¿La cita trae una etiqueta de este campo (o una genérica)?
     *
     * @param  string $campo
     * @param  string $cita
     * @return bool
     */
    protected function cita_tiene_etiqueta($campo, $cita)
    {
        $etiquetas = array_merge(
            self::ETIQUETAS_DEL_CAMPO[$campo],
            self::CAMPOS[$campo] === self::TIPO_PESO ? self::ETIQUETAS_GENERICAS_DE_PESO : self::ETIQUETAS_GENERICAS_DE_MEDIDAS
        );

        $texto = mb_strtolower($this->con_espacios_comunes((string) $cita), 'UTF-8');
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        $alternativas = [];

        foreach ($etiquetas as $etiqueta) {
            $alternativas[] = preg_quote($etiqueta, '/');
        }

        return (bool) preg_match('/(?<![\p{L}])(?:'.implode('|', $alternativas).')(?![\p{L}])/u', $texto);
    }

    /**
     * Los pares número + unidad de un texto, ya llevados a la unidad de la base. Reconoce «70 mm»,
     * «0,82 kg», «16"», agrupaciones «70 x 11 x 100 mm» (la unidad final vale para todos los números) y
     * rangos («10-20 cm», «10 a 20 cm», «entre 10 y 20 cm», «10~20 cm»).
     *
     * Marcas de cada par:
     *   - `ambiguo`: el número es de la forma «1.200» / «1,500» (un punto o coma y exactamente tres
     *     dígitos), que puede ser 1,2 o 1.200: se devuelven las DOS lecturas y las dos van marcadas.
     *   - `rango`: el número es un extremo de un rango; ninguno de los dos es «la» medida.
     *   - `grupo`: viene de una agrupación «A x B x C unidad».
     *   - `texto`: lo que estaba escrito («1.200 g», «10 a 20 cm») y `token`: el número tal cual.
     *
     * @param  string $texto
     * @return array  Lista de ['numero', 'tipo' (peso|medida), 'etiqueta', 'canonico', 'ambiguo', 'rango', 'grupo', 'texto', 'token'].
     */
    public function parsear_pares($texto)
    {
        // Sin tildes para reconocer «centímetros» y «milímetros» con la tabla de unidades (que no las lleva).
        $texto = strtr($this->con_espacios_comunes((string) $texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
        $pares  = [];
        $unidad = $this->regex_de_unidad();
        $numero = '\d+(?:[.,]\d+)*';

        // Rangos: «10-20 cm», «10 a 20 cm», «entre 10 y 20 cm», «10~20 cm», «10 cm a 20 cm». Van PRIMERO:
        // los extremos marcan a los pares sueltos que repiten su valor.
        $rangos = [];
        $union  = '(?:-|–|—|~|\ba\b|\bal\b|\by\b|\bhasta\b)';
        $rango  = '/(?<![\p{L}\d.,])('.$numero.')\s*(?:('.$unidad.')\s*)?'.$union.'\s*('.$numero.')\s*('.$unidad.')/iu';

        if (preg_match_all($rango, $texto, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $c) {
                $final  = $this->unidad_del_modelo($c[4]);
                $inicio = $c[2] !== '' ? $this->unidad_del_modelo($c[2]) : $final;

                // «100 mm - 820 g» no es un rango: son dos datos de magnitudes distintas.
                if (is_null($final) || is_null($inicio) || $inicio[0] !== $final[0]) {
                    continue;
                }

                $desde = $this->lecturas_del_numero($c[1]);
                $hasta = $this->lecturas_del_numero($c[3]);

                if (empty($desde) || empty($hasta) || min($desde) * $inicio[1] >= max($hasta) * $final[1]) {
                    continue;
                }

                $extra = ['rango' => true, 'texto' => trim($c[0])];

                $this->sumar_pares($pares, [$c[1]], $c[2] !== '' ? $c[2] : $c[4], $extra);
                $this->sumar_pares($pares, [$c[3]], $c[4], $extra);

                foreach ($pares as $par) {
                    $rangos[$par['tipo'].'|'.round($par['canonico'], 6)] = trim($c[0]);
                }
            }
        }

        // Un número seguido de su unidad: «820 g», «5,5cm», «16"».
        $simple = '/(?<![\p{L}\d.,])('.$numero.')\s*('.$unidad.')/iu';

        if (preg_match_all($simple, $texto, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $c) {
                $this->sumar_pares($pares, [$c[1]], $c[2], ['texto' => trim($c[0])], $rangos);
            }
        }

        // Varios números con una sola unidad al final: «70 x 11 x 100 mm».
        $separador = '(?:\s*[x×*]\s*|\s+por\s+)';
        $grupo     = '/(?<![\p{L}\d.,])('.$numero.'(?:'.$separador.$numero.'){1,2})\s*('.$unidad.')/iu';

        if (preg_match_all($grupo, $texto, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $c) {
                preg_match_all('/'.$numero.'/u', $c[1], $numeros);
                $this->sumar_pares($pares, $numeros[0], $c[2], ['grupo' => true, 'texto' => trim($c[0])], $rangos);
            }
        }

        return $pares;
    }

    /**
     * Todas las lecturas numéricas posibles de un token como «1.234,5», «0,82» o «1.500».
     *
     * Reglas: sin separador, el entero. Con uno solo: si tiene exactamente tres dígitos después y la parte
     * entera no es «0» ni tiene más de tres dígitos, es ambiguo (miles o decimal) y se devuelven las dos
     * lecturas; si no, es decimal. Con varios: si son todos iguales y de a tres dígitos son miles; si
     * están mezclados, el último es el decimal y los anteriores son miles. Cualquier otra cosa no es un
     * número («1.2.3») y no devuelve nada.
     *
     * @param  string $token
     * @return array  Lista de float.
     */
    public function lecturas_del_numero($token)
    {
        $token = (string) $token;

        if (!preg_match('/^\d+(?:[.,]\d+)*$/', $token)) {
            return [];
        }

        if (!preg_match_all('/[.,]/', $token, $separadores)) {
            return [(float) $token];
        }

        $grupos = preg_split('/[.,]/', $token);

        if (count($separadores[0]) === 1) {
            $entera  = $grupos[0];
            $decimal = $grupos[1];
            $lectura_decimal = (float) ($entera.'.'.$decimal);

            if (strlen($decimal) === 3 && $entera !== '0' && strlen($entera) <= 3) {
                return [$lectura_decimal, (float) ($entera.$decimal)];
            }

            return [$lectura_decimal];
        }

        // Varios separadores: ¿todos iguales?
        $distintos = array_unique($separadores[0]);

        if (count($distintos) === 1) {
            // Solo miles: cada grupo después del primero tiene que ser de tres dígitos.
            for ($i = 1; $i < count($grupos); $i++) {
                if (strlen($grupos[$i]) !== 3) {
                    return [];
                }
            }

            return [(float) implode('', $grupos)];
        }

        // Mezclados: el último separador es el decimal, los anteriores son miles.
        $ultimo = strlen($token) - 1;

        while ($ultimo >= 0 && !in_array($token[$ultimo], ['.', ','], true)) {
            $ultimo--;
        }

        $entera  = preg_replace('/[.,]/', '', substr($token, 0, $ultimo));
        $decimal = substr($token, $ultimo + 1);

        return [(float) ($entera.'.'.$decimal)];
    }

    /**
     * Suma a $pares todas las lecturas de los números $numeros con la unidad $unidad.
     *
     * @param  array  $pares
     * @param  array  $numeros  Tokens de texto.
     * @param  string $unidad   La unidad tal como estaba escrita.
     * @param  array  $extra    Marcas del par: rango, grupo, texto.
     * @param  array  $rangos   Valores que son extremo de un rango (tipo|valor => texto): marcan el par como rango.
     * @return void
     */
    protected function sumar_pares(array &$pares, array $numeros, $unidad, array $extra = [], array $rangos = [])
    {
        $datos = $this->unidad_del_modelo($unidad);

        if (is_null($datos)) {
            return;
        }

        foreach ($numeros as $token) {
            $lecturas = $this->lecturas_del_numero($token);

            foreach ($lecturas as $lectura) {
                $canonico = $lectura * $datos[1];
                $clave    = $datos[0].'|'.round($canonico, 6);

                $par = array_merge([
                    'numero'   => $lectura,
                    'tipo'     => $datos[0],
                    'etiqueta' => $datos[2],
                    'canonico' => $canonico,
                    // Dos lecturas posibles = el token es de la forma «1.200» / «1,500».
                    'ambiguo'  => count($lecturas) > 1,
                    'rango'    => false,
                    'grupo'    => false,
                    'texto'    => $token.' '.$unidad,
                    'token'    => $token,
                ], $extra);

                if (isset($rangos[$clave])) {
                    $par['rango'] = true;
                    $par['texto'] = $rangos[$clave];
                }

                $pares[] = $par;
            }
        }
    }

    /**
     * La unidad que escribió el modelo (o que está en la cita) como [tipo, factor, etiqueta]; null si no
     * se reconoce. Insensible a mayúsculas, tildes y al punto final («Kg.», «Gramos», «mts»).
     *
     * @param  mixed $unidad
     * @return array|null
     */
    protected function unidad_del_modelo($unidad)
    {
        if (!is_string($unidad)) {
            return null;
        }

        $clave = mb_strtolower(trim($unidad), 'UTF-8');
        $clave = strtr($clave, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $clave = rtrim($clave, '.');

        return isset(self::UNIDADES[$clave]) ? self::UNIDADES[$clave] : null;
    }

    /**
     * El número que afirma el modelo: acepta un número o un texto como «7,5» o «7.5». Null si no es un número.
     *
     * @param  mixed $valor
     * @return float|null
     */
    protected function numero_del_modelo($valor)
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        if (is_string($valor) && preg_match('/^\s*\d+(?:[.,]\d+)?\s*$/', $valor)) {
            return (float) str_replace(',', '.', trim($valor));
        }

        return null;
    }

    /**
     * ¿La cita está en el texto de la página? Con espacios y mayúsculas normalizados. Una cita de varios
     * renglones que el modelo unió con « / » vale si cada parte está.
     *
     * @param  string $cita
     * @param  string $pagina
     * @return bool
     */
    protected function cita_esta_en_la_pagina($cita, $pagina)
    {
        $texto = $this->plano($pagina);

        // Sin comillas ni puntos suspensivos en las puntas (los pone el modelo, no la página).
        $entera = $this->sin_comillas($this->plano($cita));

        if ($entera !== '' && mb_strpos($texto, $entera) !== false) {
            return true;
        }

        $partes = preg_split('#\s+/\s+#u', $this->plano($cita));

        if (count($partes) < 2) {
            return false;
        }

        foreach ($partes as $parte) {
            $parte = $this->sin_comillas($parte);

            if ($parte === '' || mb_strpos($texto, $parte) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * El texto sin comillas, espacios ni puntos suspensivos en las puntas. Con regex /u y no con trim():
     * trim() con una máscara multibyte corta BYTES y puede romper la última letra con tilde.
     *
     * @param  string $texto
     * @return string
     */
    protected function sin_comillas($texto)
    {
        return (string) preg_replace("/^[\\s\"'“”‘’…]+|[\\s\"'“”‘’…]+$/u", '', $texto);
    }

    /**
     * ¿Coinciden dos valores (en la misma unidad)? Tolerancia relativa del 1 % o 0,001 absoluto.
     *
     * @param  float $a
     * @param  float $b
     * @return bool
     */
    protected function coincide($a, $b)
    {
        $tolerancia = max(self::TOLERANCIA_ABSOLUTA, self::TOLERANCIA_RELATIVA * max(abs($a), abs($b)));

        return abs($a - $b) <= $tolerancia;
    }

    /**
     * Texto en minúsculas, con los espacios (incluidos los no cortantes) colapsados a uno.
     *
     * @param  string $texto
     * @return string
     */
    protected function plano($texto)
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($this->con_espacios_comunes((string) $texto), 'UTF-8')));
    }

    /**
     * Cambia los espacios no cortantes (U+00A0, U+202F, U+2009) por un espacio común.
     *
     * @param  string $texto
     * @return string
     */
    protected function con_espacios_comunes($texto)
    {
        return str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"], ' ', $texto);
    }

    /**
     * La alternativa de regex con TODAS las unidades: las de letras (de la más larga a la más corta, con
     * un corte para que «m» no se coma el principio de «metros» ni «g» el de «gato») y las comillas.
     *
     * @return string
     */
    protected function regex_de_unidad()
    {
        if (is_null(self::$regex_de_unidades)) {
            $letras = [];
            $signos = [];

            foreach (array_keys(self::UNIDADES) as $alias) {
                if (preg_match('/^[a-z]+$/', $alias)) {
                    $letras[] = $alias;
                } else {
                    $signos[] = preg_quote($alias, '/');
                }
            }

            usort($letras, function ($a, $b) {
                return strlen($b) - strlen($a);
            });

            $letras_regex = '(?:'.implode('|', $letras).')(?![\p{L}\d²³\/])';
            $signos_regex = '(?:'.implode('|', $signos).')';

            self::$regex_de_unidades = '(?:'.$letras_regex.'|'.$signos_regex.')';
        }

        return self::$regex_de_unidades;
    }

    /**
     * @param  string $motivo
     * @return array
     */
    protected function rechazo($motivo)
    {
        return ['ok' => false, 'motivo' => $motivo, 'dato' => null];
    }
}
