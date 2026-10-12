<?php

namespace App\Http\Controllers\Helpers\import\relaciones;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Http\Controllers\Helpers\asistente_ia\ModelosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use Illuminate\Support\Facades\Log;

/**
 * La IA que juzga los candidatos parecidos del chequeo de relaciones de la importación (misión
 * importacion-relaciones-parecidas, 8/10/2026).
 *
 * Recibe SOLO la lista corta que armó RelacionesParecidasDetector (cada valor con hasta tres
 * candidatos) y decide, por cada valor, si es alguno de los candidatos escrito de otra forma
 * (seguro o dudoso) o si es claramente otra cosa. UNA llamada por corrida, con tope de ítems (el
 * excedente queda con la capa determinista). Sin candidatos no se la llama: eso lo garantiza
 * RelacionesParecidasChequeo.
 *
 * 🔴 "Ante la duda" la IA tiene que contestar el candidato con `seguro: false`, NUNCA null. Null
 * hace que el ítem no se muestre: ante la duda no se preguntaría y se crearía el duplicado en
 * silencio, que es justo lo que esta misión vino a evitar. Medido el 8/10/2026 con la IA real:
 * con el prompt viejo ("ante la duda, null"), "Bulonera Tomy" contra "Bulonería Tomi" volvía null.
 *
 * Mismo esqueleto que AiExcelAnalyzer::ask_claude_for_recomendation(): el modelo lo elige el admin
 * por comercio (ModelosIaHelper::resolver con TAREA_EXCEL), el request lo arma ProveedorIaHelper,
 * el JSON se saca con ModelosIaHelper::extraer_json() y el consumo se registra con el proceso
 * `import_excel_relaciones`.
 *
 * 🔴 NUNCA LANZA. Si la IA no está configurada, no contesta, contesta un error o un JSON que no
 * sirve, devuelve null y el chequeo sigue con lo determinista: preguntar por nombres parecidos es
 * una ayuda, y la importación no puede trabarse por ella.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RelacionesParecidasIa
{
    const PROCESO = 'import_excel_relaciones';

    /** Techo de salida sin razonamiento: cada respuesta es {"n":X,"elegido":Y}, ~15 tokens. */
    const MAX_TOKENS = 4000;

    /** Cómo se nombra cada tipo en el prompt. */
    const NOMBRES_DE_TIPO = [
        'proveedor'      => 'proveedor',
        'categoria'      => 'categoría de artículos',
        'sub_categoria'  => 'subcategoría de artículos',
        'marca'          => 'marca',
        'provincia'      => 'provincia',
        'localidad'      => 'localidad',
        'vendedor'       => 'vendedor',
        'tipo_de_precio' => 'lista de precios',
    ];

    /**
     * Le pide a la IA que juzgue los candidatos.
     *
     * @param  int   $user_id    dueño del comercio (el que paga el consumo y el que eligió el modelo)
     * @param  array $consultas  [['n' => int, 'tipo' => string, 'valor' => string,
     *                            'candidatos' => [['c' => int, 'nombre' => string, 'origen' => 'cargado'|'archivo'], ...]], ...]
     * @return array|null  [n => ['c' => int, 'seguro' => bool] | null] con lo que contestó (un `n`
     *                     que no vino no está en el array; null = la IA dice que es otra cosa);
     *                     null si la IA no se pudo consultar o no contestó en forma
     */
    public static function juzgar($user_id, array $consultas)
    {
        if (count($consultas) === 0) {
            return [];
        }

        try {
            $ia = ModelosIaHelper::resolver(ModelosIaHelper::dueno_de($user_id), ModelosIaHelper::TAREA_EXCEL);

            if (is_null($ia)) {
                Log::warning('RelacionesParecidasIa: no hay clave de IA configurada; el chequeo sigue sin IA');
                return null;
            }

            $texto = self::llamar($user_id, $ia, self::armar_prompt($consultas));

            if (is_null($texto)) {
                return null;
            }

            $decodificado = ModelosIaHelper::extraer_json($texto, function ($candidato) {
                return isset($candidato['respuestas']) && is_array($candidato['respuestas']);
            });

            if (is_null($decodificado)) {
                Log::warning('RelacionesParecidasIa: la respuesta no trae un JSON con "respuestas"', [
                    'preview' => mb_substr($texto, 0, 300, 'UTF-8'),
                ]);

                return null;
            }

            return self::validar_respuestas($decodificado['respuestas'], $consultas);

        } catch (\Throwable $e) {
            Log::warning('RelacionesParecidasIa: no se pudo consultar a la IA; el chequeo sigue sin IA', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Se queda con las respuestas que tienen sentido: un `n` que se mandó y un `elegido` que es
     * null o uno de los `c` de ESE ítem. Lo demás se descarta (y ese valor queda como si la IA no
     * hubiera contestado por él).
     *
     * Tres niveles: elegido + `seguro: true` (es el mismo), elegido + `seguro: false` (puede ser el
     * mismo: se pregunta sin marcar nada) y `elegido: null` (es otra cosa: no se muestra).
     *
     * 🔴 `seguro` cuenta solo si es el booleano true. Ausente, "true" como texto, 1 o lo que sea,
     * vale false: lo único que hace un "seguro" de más es preseleccionar "usar el existente", y
     * ante una respuesta mal formada es mejor preguntar sin marcar que unir dos cosas por un clic
     * distraído.
     *
     * @param  array $respuestas
     * @param  array $consultas
     * @return array [n => ['c' => int, 'seguro' => bool] | null]
     */
    protected static function validar_respuestas(array $respuestas, array $consultas)
    {
        $validos = [];

        foreach ($consultas as $consulta) {
            $cs = [];

            foreach ($consulta['candidatos'] as $candidato) {
                $cs[(int) $candidato['c']] = true;
            }

            $validos[(int) $consulta['n']] = $cs;
        }

        $resultado = [];

        foreach ($respuestas as $respuesta) {
            if (!is_array($respuesta) || !isset($respuesta['n']) || !is_numeric($respuesta['n'])) {
                continue;
            }

            $n = (int) $respuesta['n'];

            if (!isset($validos[$n])) {
                continue;
            }

            /* Un `n` repetido: gana la primera respuesta (la IA no debería repetir; si lo hace, queda en el log). */
            if (array_key_exists($n, $resultado)) {
                Log::warning('RelacionesParecidasIa: la IA contestó dos veces el mismo valor; queda la primera respuesta', [
                    'n' => $n,
                ]);

                continue;
            }

            $elegido = array_key_exists('elegido', $respuesta) ? $respuesta['elegido'] : null;

            if (is_null($elegido) || $elegido === false || $elegido === '' || $elegido === 0 || $elegido === '0') {
                $resultado[$n] = null;
                continue;
            }

            if (is_numeric($elegido) && isset($validos[$n][(int) $elegido])) {
                $resultado[$n] = [
                    'c'      => (int) $elegido,
                    'seguro' => array_key_exists('seguro', $respuesta) && $respuesta['seguro'] === true,
                ];
            }
        }

        return $resultado;
    }

    /**
     * El prompt. Los datos van como JSON para que un nombre con comillas o saltos de línea no
     * rompa la estructura.
     *
     * @param  array $consultas
     * @return string
     */
    protected static function armar_prompt(array $consultas)
    {
        $datos = [];

        foreach ($consultas as $consulta) {
            $candidatos = [];

            foreach ($consulta['candidatos'] as $candidato) {
                $candidatos[] = [
                    'c'      => (int) $candidato['c'],
                    'nombre' => (string) $candidato['nombre'],
                    'origen' => (string) $candidato['origen'],
                ];
            }

            $datos[] = [
                'n'          => (int) $consulta['n'],
                'tipo'       => isset(self::NOMBRES_DE_TIPO[$consulta['tipo']]) ? self::NOMBRES_DE_TIPO[$consulta['tipo']] : (string) $consulta['tipo'],
                'valor'      => (string) $consulta['valor'],
                'candidatos' => $candidatos,
            ];
        }

        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Sos un asistente que revisa una importación de Excel a un sistema de gestión de un comercio.
Cada valor del Excel es el nombre de un proveedor, una categoría, una marca, una localidad, etc.
Para cada valor te paso hasta tres candidatos parecidos: nombres que el comercio YA tiene
cargados (origen "cargado") u otros valores del mismo archivo (origen "archivo").

Decidí, para cada valor, si es EL MISMO que alguno de sus candidatos, solo que escrito de otra
forma: error de tipeo, abreviatura, plural o singular, palabras en otro orden, con o sin tildes,
mayúsculas, espacios o signos de puntuación.

Tu respuesta NO se aplica sola: el comerciante ve cada sugerencia y la confirma antes de
importar. Lo peor que puede pasar es que NO le preguntes por un nombre que sí era el mismo:
ahí el sistema crea un duplicado y nadie se entera. Por eso hay tres respuestas posibles:

- Es el mismo y estás seguro: "elegido" con el número "c" de ese candidato y "seguro": true.
  Se le muestra al comerciante con ese candidato ya marcado.
- Se parece y PUEDE ser el mismo, pero no estás seguro: "elegido" con el número "c" del
  candidato más probable y "seguro": false. Se le pregunta al comerciante sin marcarle nada.
- Está claro que es OTRA cosa, porque difiere en un dato que importa (una medida, un número, un
  modelo, un color, una marca distinta, un lugar distinto, una variante): "elegido": null. Ese
  valor no se le muestra al comerciante.

Ante la duda, NO respondas null: respondé el candidato más probable con "seguro": false. Null es
solo para cuando está claro que son cosas distintas.

Si el valor coincide con un candidato "cargado" y también con uno del "archivo", elegí el
"cargado": es el nombre que el comercio ya usa.

Usá "seguro": true también cuando lo único que cambia es la forma societaria (SRL, S.R.L.,
SA, S.A., SAS, Hnos.) o una letra de más o de menos en una palabra larga, y el resto del
nombre coincide: "Pinturas y Solventes" es "Pinturas y Solventes SRL", y "Ferreteria del Sur
SA" es "Ferretera del Sur S.A.".

Un candidato que difiere en un número ("Mayorista 2", "Caño 3/4") NUNCA es el mismo con
seguridad: a lo sumo "seguro": false, y si el número es una medida o un modelo, null.

Por ejemplo: "Caño 1/2" y "Caño 3/4" NO son el mismo (cambia la medida): null.
"Ferret. del Norte" y "Ferretería Del Norte" SÍ son el mismo: elegido con "seguro": true.
"Mayoristas" es "Mayorista" (cargado), no "Mayorista 2" (archivo): elegido el cargado, con "seguro": true.

Valores a revisar (JSON):
{$json}

Respondé SOLO con un JSON válido, sin markdown ni texto adicional, con una entrada por cada "n":
{"respuestas": [{"n": 1, "elegido": 2, "seguro": true}, {"n": 2, "elegido": 1, "seguro": false}, {"n": 3, "elegido": null}]}
donde "elegido" es el número "c" del candidato, o null si ninguno puede ser el mismo.
PROMPT;
    }

    /**
     * UNA llamada a la IA. Devuelve el texto o null (y lo deja logueado) si no hubo respuesta útil.
     *
     * @param  int    $user_id
     * @param  array  $ia      ModelosIaHelper::resolver()
     * @param  string $prompt
     * @return string|null
     */
    protected static function llamar($user_id, array $ia, $prompt)
    {
        $proveedor = $ia['proveedor'];
        $modelo    = $ia['modelo'];

        $timeout = (int) config('services.importacion_excel_ia.timeout', 120);

        $http = ProveedorIaHelper::cliente_http($proveedor, $timeout > 0 ? $timeout : 120);

        $payload = ProveedorIaHelper::agregar_thinking([
            'model'      => $modelo,
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ], $ia['thinking']);

        /*
         * Con el thinking prendido (DeepSeek Pro, el default de la importación) el razonamiento
         * puede contar contra max_tokens: mismo techo que usa el análisis de la planilla.
         */
        if (is_array($ia['thinking']) && isset($ia['thinking']['type']) && (string) $ia['thinking']['type'] === 'enabled') {
            $techo = (int) config('services.importacion_excel_ia.max_tokens_con_razonamiento', 16000);

            if ($techo > 0) {
                $payload['max_tokens'] = max(self::MAX_TOKENS, $techo);
            }
        }

        $response = $http->post(ProveedorIaHelper::url_messages($proveedor), $payload);

        if (!$response->successful()) {
            Log::warning('RelacionesParecidasIa: la IA devolvió un error', [
                'proveedor' => $proveedor,
                'status'    => $response->status(),
                'body'      => mb_substr((string) $response->body(), 0, 500, 'UTF-8'),
            ]);

            return null;
        }

        $body = $response->json();

        /* Se paga aunque después no sirva: se registra con el body entero en la mano. */
        AiTokenUsageHelper::registrar([
            'user_id'   => (int) $user_id,
            'proceso'   => self::PROCESO,
            'body'      => is_array($body) ? $body : [],
            'proveedor' => $proveedor,
            'modelo'    => is_array($body) && isset($body['model']) && (string) $body['model'] !== ''
                ? (string) $body['model']
                : $modelo,
        ]);

        if (is_array($body) && isset($body['stop_reason']) && (string) $body['stop_reason'] === 'max_tokens') {
            Log::warning('RelacionesParecidasIa: la respuesta se cortó por el techo de salida', [
                'proveedor'  => $proveedor,
                'max_tokens' => $payload['max_tokens'],
            ]);

            return null;
        }

        return ModelosIaHelper::texto_de_respuesta($body);
    }
}
