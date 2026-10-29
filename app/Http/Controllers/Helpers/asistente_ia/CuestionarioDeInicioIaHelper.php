<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El cuestionario de inicio de la app del agente (misión app-ia-y-memoria, 29/10/2026, §E2.4 del
 * plan): «Conozcámonos». El dueño cuenta en qué anda su negocio, qué quiere lograr, qué área quiere
 * mejorar, qué le importa hoy, cómo quiere que le respondan y cuántas confirmaciones quiere. Y las
 * preferencias de respuesta, que el dueño cambia después desde la configuración de la app.
 *
 * 🔴 ESCRIBE DE FORMA DETERMINISTA, SIN LLAMAR A UN MODELO. Cada respuesta va a un lugar fijo de la
 * memoria por el camino de escritura de siempre (AgenteContextoHelper::guardar_bloque):
 *
 *   negocio_frase              → negocio/resumen.md y la sección «El negocio» del maestro
 *   objetivos + objetivos_texto→ negocio/objetivos.md (y la lista en «Lo que le importa hoy»)
 *   areas + importa_hoy        → negocio/prioridades.md y «Lo que le importa hoy» del maestro
 *   largo + forma              → dueno/como_responder.md y «Cómo quiere que le respondan»
 *   extra                      → la sección «Lo que contó al empezar» de dueno/perfil.md
 *   confianza                  → users.agente_confianza, la MISMA columna y el mismo enum
 *                                (ConfianzaDelAgenteIaHelper::MODOS) que PUT user/asistente-config
 *
 * 🔴 ESCRIBE EN BLOQUES PROPIOS Y NO PISA LO DE LOS DEMÁS (pedido del coordinador, 29/10/2026). Lo del
 * cuestionario va entre `<!-- cuestionario -->` y `<!-- /cuestionario -->`, y lo de cómo responder
 * entre `<!-- preferencias -->` y `<!-- /preferencias -->` (lo escriben el cuestionario y
 * PATCH ia-app/preferencias, el mismo bloque). Reenviar el cuestionario reemplaza SOLO esos bloques:
 * lo que el agente o el dueño agregaron afuera, en el mismo archivo o en la misma sección, sobrevive.
 * Por eso el cuestionario tampoco toca el resumen del índice de esos archivos (salvo el de
 * como_responder, que es todo suyo) ni escribe títulos «##» adentro de sus bloques.
 *
 * 🔴 LA CONFIANZA NO SE COPIA A LA MEMORIA. La fuente de verdad es la columna, que el dueño puede
 * cambiar después desde la configuración: una copia en un archivo quedaría vieja y le diría al modelo
 * lo contrario de lo que hace el sistema.
 *
 * Mandarlo dos veces con lo mismo (el mismo día) no sube ninguna versión: guardar_bloque() no escribe
 * lo que no cambió. Lo anterior queda siempre en las revisiones.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CuestionarioDeInicioIaHelper
{
    /** Motivo de las revisiones que deja el cuestionario. */
    const MOTIVO = 'Cuestionario de inicio de la app';

    /** Motivo de las revisiones que deja el cambio de preferencias desde la configuración. */
    const MOTIVO_PREFERENCIAS = 'Lo cambió el dueño en la configuración de la app';

    /** La marca de los bloques del cuestionario. */
    const MARCA = 'cuestionario';

    /** La marca del bloque de cómo responder (cuestionario y PATCH ia-app/preferencias). */
    const MARCA_PREFERENCIAS = 'preferencias';

    /** La sección del maestro donde van las preferencias. */
    const SECCION_PREFERENCIAS = 'Cómo quiere que le respondan';

    const LARGOS = ['breve', 'equilibrado', 'explayado'];

    const FORMAS = ['numeros', 'graficos', 'explicado'];

    /** Los valores vigentes cuando el dueño todavía no eligió nada (lo que el agente hace por defecto). */
    const LARGO_POR_DEFECTO = 'equilibrado';

    const FORMA_POR_DEFECTO = 'explicado';

    /** Los chips que muestra la app (el servidor acepta cualquier texto de hasta 60 caracteres). */
    const OBJETIVOS_SUGERIDOS = ['vender más', 'mejorar márgenes', 'ordenar el stock', 'cobrar a tiempo', 'ahorrar tiempo', 'crecer online', 'ordenar las finanzas', 'armar el equipo'];

    const AREAS_SUGERIDAS = ['ventas', 'compras y proveedores', 'stock', 'clientes y cobranzas', 'finanzas', 'equipo', 'tienda online', 'facturación'];

    /** Cómo se escribe en la memoria cada largo de respuesta. */
    const TEXTO_DEL_LARGO = [
        'breve'       => 'Breve: lo justo, en pocas líneas.',
        'equilibrado' => 'Equilibrado: lo importante y un poco de contexto.',
        'explayado'   => 'Explayado: con detalle y explicación.',
    ];

    /** Cómo se escribe en la memoria cada forma de respuesta. */
    const TEXTO_DE_LA_FORMA = [
        'numeros'   => 'Números directos, sin vueltas.',
        'graficos'  => 'Con gráficos cuando ayudan a ver algo; donde no se pueden mostrar, los números.',
        'explicado' => 'Explicado: qué significan los números y qué conviene hacer con eso.',
    ];

    /**
     * Las reglas de validación del POST del cuestionario (§E2.4 del plan).
     *
     * `objetivos` y `areas` se aceptan ausentes o vacías: la app las manda siempre, y una lista vacía
     * es una respuesta válida («no elegí ninguna»).
     *
     * @return array<string, string>
     */
    public static function reglas()
    {
        return [
            'negocio_frase'   => 'nullable|string|max:500',
            'objetivos'       => 'nullable|array|max:8',
            'objetivos.*'     => 'nullable|string|max:60',
            'objetivos_texto' => 'nullable|string|max:1000',
            'areas'           => 'nullable|array|max:8',
            'areas.*'         => 'nullable|string|max:60',
            'importa_hoy'     => 'nullable|string|max:1000',
            'largo'           => 'required|in:' . implode(',', self::LARGOS),
            'forma'           => 'required|in:' . implode(',', self::FORMAS),
            'confianza'       => 'required|in:' . implode(',', ConfianzaDelAgenteIaHelper::MODOS),
            'extra'           => 'nullable|string|max:1500',
        ];
    }

    /**
     * Las reglas del PATCH de preferencias: las tres opcionales (body parcial).
     *
     * @return array<string, string>
     */
    public static function reglas_de_preferencias()
    {
        return [
            'largo'     => 'nullable|in:' . implode(',', self::LARGOS),
            'forma'     => 'nullable|in:' . implode(',', self::FORMAS),
            'confianza' => 'nullable|in:' . implode(',', ConfianzaDelAgenteIaHelper::MODOS),
        ];
    }

    /**
     * Las respuestas ya validadas, normalizadas: textos recortados, listas sin vacíos ni repetidos.
     *
     * @param  array  $datos
     * @return array
     */
    public static function normalizar(array $datos)
    {
        return [
            'negocio_frase'   => self::texto(isset($datos['negocio_frase']) ? $datos['negocio_frase'] : null),
            'objetivos'       => self::lista(isset($datos['objetivos']) ? $datos['objetivos'] : []),
            'objetivos_texto' => self::texto(isset($datos['objetivos_texto']) ? $datos['objetivos_texto'] : null),
            'areas'           => self::lista(isset($datos['areas']) ? $datos['areas'] : []),
            'importa_hoy'     => self::texto(isset($datos['importa_hoy']) ? $datos['importa_hoy'] : null),
            'largo'           => (string) $datos['largo'],
            'forma'           => (string) $datos['forma'],
            'confianza'       => (string) $datos['confianza'],
            'extra'           => self::texto(isset($datos['extra']) ? $datos['extra'] : null),
        ];
    }

    /**
     * Escribe las respuestas en la memoria y en el dueño, todo o nada.
     *
     * @param  \App\Models\User  $owner
     * @param  array  $datos  Las respuestas validadas con reglas().
     * @return array{respuestas: array, escritos: array<int, string>, avisos: array<int, string>}
     */
    public static function completar(User $owner, array $datos)
    {
        $dueno      = AgenteContextoHelper::dueno($owner);
        $respuestas = self::normalizar($datos);
        $fecha      = now()->format('d/m/Y');

        return DB::transaction(function () use ($dueno, $respuestas, $fecha) {

            $escritos = [];
            $avisos   = [];

            foreach (self::escrituras($respuestas, $fecha) as $escritura) {
                self::escribir($dueno, $escritura, self::MOTIVO, AgenteContextoHelper::ORIGEN_CUESTIONARIO, $escritos, $avisos);
            }

            foreach (self::escrituras_de_preferencias($respuestas['largo'], $respuestas['forma']) as $escritura) {
                self::escribir($dueno, $escritura, self::MOTIVO, AgenteContextoHelper::ORIGEN_CUESTIONARIO, $escritos, $avisos);
            }

            $respuestas_guardadas = $respuestas;
            $respuestas_guardadas['respondido_en'] = now()->toIso8601String();

            /* La confianza va a la misma columna y con el mismo enum que PUT user/asistente-config. */
            $dueno->agente_confianza              = $respuestas['confianza'];
            $dueno->ia_preferencias               = json_encode(['largo' => $respuestas['largo'], 'forma' => $respuestas['forma']]);
            $dueno->ia_cuestionario_completado_at = now()->toDateTimeString();
            $dueno->ia_cuestionario_respuestas    = json_encode($respuestas_guardadas, JSON_UNESCAPED_UNICODE);
            $dueno->save();

            return [
                'respuestas' => $respuestas_guardadas,
                'escritos'   => $escritos,
                'avisos'     => $avisos,
            ];
        });
    }

    /**
     * PATCH ia-app/preferencias: cambia lo que vino de largo, forma y confianza (body parcial) y
     * reescribe SOLO el bloque de cómo responder (dueno/como_responder.md y la sección del maestro),
     * con origen `dueno` y su revisión. La confianza va por la misma columna que la configuración.
     *
     * @param  \App\Models\User  $owner
     * @param  array  $datos  Validado con reglas_de_preferencias(); lo que viene null o ausente no cambia.
     * @return array{preferencias: array, escritos: array<int, string>, avisos: array<int, string>}
     */
    public static function cambiar_preferencias(User $owner, array $datos)
    {
        $dueno  = AgenteContextoHelper::dueno($owner);
        $actual = self::preferencias($dueno);

        $largo     = !empty($datos['largo']) ? (string) $datos['largo'] : $actual['largo'];
        $forma     = !empty($datos['forma']) ? (string) $datos['forma'] : $actual['forma'];
        $confianza = !empty($datos['confianza']) ? (string) $datos['confianza'] : null;

        return DB::transaction(function () use ($dueno, $largo, $forma, $confianza, $datos) {

            $escritos = [];
            $avisos   = [];

            /* La memoria solo se toca si vino algo de cómo responder (cambiar la confianza no la toca). */
            if (!empty($datos['largo']) || !empty($datos['forma'])) {

                foreach (self::escrituras_de_preferencias($largo, $forma) as $escritura) {
                    self::escribir($dueno, $escritura, self::MOTIVO_PREFERENCIAS, AgenteContextoHelper::ORIGEN_DUENO, $escritos, $avisos);
                }

                $dueno->ia_preferencias = json_encode(['largo' => $largo, 'forma' => $forma]);
            }

            if (!is_null($confianza)) {
                $dueno->agente_confianza = $confianza;
            }

            if ($dueno->isDirty()) {
                $dueno->save();
            }

            return [
                'preferencias' => self::preferencias($dueno),
                'escritos'     => $escritos,
                'avisos'       => $avisos,
            ];
        });
    }

    /**
     * Las preferencias vigentes del dueño: `{largo, forma, confianza}`. Sin nada guardado, los valores
     * por defecto; la confianza, la de la configuración del asistente (con su default).
     *
     * @param  \App\Models\User|null  $owner
     * @return array{largo: string, forma: string, confianza: string}
     */
    public static function preferencias($owner)
    {
        $dueno = AgenteContextoHelper::dueno($owner);

        $guardadas = [];

        if (!is_null($dueno) && !empty($dueno->ia_preferencias)) {
            $valor     = $dueno->ia_preferencias;
            $guardadas = is_array($valor) ? $valor : json_decode((string) $valor, true);
            $guardadas = is_array($guardadas) ? $guardadas : [];
        }

        $largo = isset($guardadas['largo']) && in_array($guardadas['largo'], self::LARGOS, true) ? $guardadas['largo'] : self::LARGO_POR_DEFECTO;
        $forma = isset($guardadas['forma']) && in_array($guardadas['forma'], self::FORMAS, true) ? $guardadas['forma'] : self::FORMA_POR_DEFECTO;

        return [
            'largo'     => $largo,
            'forma'     => $forma,
            'confianza' => ConfianzaDelAgenteIaHelper::con_default($dueno),
        ];
    }

    /**
     * Marca el cuestionario como salteado («Ahora no»). Idempotente: si ya estaba salteado, deja la
     * primera fecha; si ya lo completó, no cambia nada.
     *
     * @param  \App\Models\User  $owner
     * @return void
     */
    public static function saltear(User $owner)
    {
        $dueno = AgenteContextoHelper::dueno($owner);

        if (is_null($dueno) || !empty($dueno->ia_cuestionario_completado_at) || !empty($dueno->ia_cuestionario_salteado_at)) {

            return;
        }

        $dueno->ia_cuestionario_salteado_at = now()->toDateTimeString();
        $dueno->save();
    }

    /**
     * Las respuestas guardadas del dueño, o null si nunca lo completó.
     *
     * @param  \App\Models\User  $owner
     * @return array|null
     */
    public static function respuestas(User $owner)
    {
        $dueno = AgenteContextoHelper::dueno($owner);

        if (is_null($dueno) || empty($dueno->ia_cuestionario_respuestas)) {

            return null;
        }

        $valor = $dueno->ia_cuestionario_respuestas;

        $decodificado = is_array($valor) ? $valor : json_decode((string) $valor, true);

        return is_array($decodificado) ? $decodificado : null;
    }

    /**
     * Una escritura de bloque, juntando lo escrito y los avisos (un tope no frena el resto).
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $escritura  {ruta, marca, contenido, resumen?, seccion?}
     * @param  string  $motivo
     * @param  string  $origen
     * @param  array  $escritos
     * @param  array  $avisos
     * @return void
     */
    protected static function escribir(User $dueno, array $escritura, $motivo, $origen, array &$escritos, array &$avisos)
    {
        $resultado = AgenteContextoHelper::guardar_bloque(
            $dueno,
            $escritura['ruta'],
            $escritura['marca'],
            $escritura['contenido'],
            isset($escritura['resumen']) ? $escritura['resumen'] : null,
            $motivo,
            $origen,
            null,
            isset($escritura['seccion']) ? $escritura['seccion'] : null
        );

        if (!$resultado['ok']) {
            $avisos[] = $escritura['ruta'] . ': ' . $resultado['error'];

            return;
        }

        if (!$resultado['sin_cambios'] && !in_array($escritura['ruta'], $escritos, true)) {
            $escritos[] = $escritura['ruta'];
        }
    }

    /**
     * Las escrituras del bloque de cómo responder: el archivo y la sección del maestro.
     *
     * @param  string  $largo
     * @param  string  $forma
     * @return array<int, array>
     */
    protected static function escrituras_de_preferencias($largo, $forma)
    {
        $texto_largo = self::TEXTO_DEL_LARGO[$largo];
        $texto_forma = self::TEXTO_DE_LA_FORMA[$forma];

        return [
            [
                'ruta'      => 'dueno/como_responder.md',
                'marca'     => self::MARCA_PREFERENCIAS,
                'contenido' => '- Largo de las respuestas: ' . $texto_largo . "\n" . '- Forma: ' . $texto_forma,
                'resumen'   => 'Largo: ' . $largo . '. Forma: ' . $forma . '.',
            ],
            [
                'ruta'      => AgenteContextoHelper::MAESTRO,
                'marca'     => self::MARCA_PREFERENCIAS,
                'seccion'   => self::SECCION_PREFERENCIAS,
                'contenido' => '- Largo: ' . $texto_largo . "\n" . '- Forma: ' . $texto_forma . "\n" . '- El detalle está en dueno/como_responder.md.',
            ],
        ];
    }

    /**
     * Las escrituras del cuestionario (sin las de cómo responder), en orden. Lo que no vino no escribe
     * nada: un bloque no se pisa con vacío porque el dueño no contestó esa pregunta.
     *
     * @param  array  $r      Respuestas normalizadas.
     * @param  string $fecha  d/m/Y de hoy.
     * @return array<int, array>
     */
    protected static function escrituras(array $r, $fecha)
    {
        $al_pie = 'Lo contó en el cuestionario de inicio el ' . $fecha . '.';
        $lista  = [];

        // ---- El negocio en una frase ----
        if ($r['negocio_frase'] !== '') {

            $lista[] = [
                'ruta'      => 'negocio/resumen.md',
                'marca'     => self::MARCA,
                'contenido' => $r['negocio_frase'] . "\n\n" . $al_pie,
            ];

            $lista[] = [
                'ruta'      => AgenteContextoHelper::MAESTRO,
                'marca'     => self::MARCA,
                'seccion'   => 'El negocio',
                'contenido' => $r['negocio_frase'],
            ];
        }

        // ---- Objetivos ----
        if (count($r['objetivos']) > 0 || $r['objetivos_texto'] !== '') {

            $renglones = [];

            foreach ($r['objetivos'] as $objetivo) {
                $renglones[] = '- ' . self::con_mayuscula($objetivo);
            }

            if ($r['objetivos_texto'] !== '') {
                if (count($renglones) > 0) {
                    $renglones[] = '';
                }
                $renglones[] = 'En sus palabras: ' . $r['objetivos_texto'];
            }

            $renglones[] = '';
            $renglones[] = $al_pie;

            $lista[] = [
                'ruta'      => 'negocio/objetivos.md',
                'marca'     => self::MARCA,
                'contenido' => implode("\n", $renglones),
            ];
        }

        // ---- Prioridades: áreas a mejorar y lo que le importa hoy (sin «##» adentro del bloque) ----
        if (count($r['areas']) > 0 || $r['importa_hoy'] !== '') {

            $renglones = [];

            if (count($r['areas']) > 0) {
                $renglones[] = 'Áreas que quiere mejorar:';
                foreach ($r['areas'] as $area) {
                    $renglones[] = '- ' . self::con_mayuscula($area);
                }
                $renglones[] = '';
            }

            if ($r['importa_hoy'] !== '') {
                $renglones[] = 'Lo que le importa hoy: ' . $r['importa_hoy'];
                $renglones[] = '';
            }

            $renglones[] = $al_pie;

            $lista[] = [
                'ruta'      => 'negocio/prioridades.md',
                'marca'     => self::MARCA,
                'contenido' => implode("\n", $renglones),
            ];
        }

        // ---- «Lo que le importa hoy» del maestro: lo de hoy, los objetivos y las áreas, corto ----
        $hoy = [];

        if ($r['importa_hoy'] !== '') {
            $hoy[] = $r['importa_hoy'];
        }

        if (count($r['objetivos']) > 0) {
            $hoy[] = '- Objetivos: ' . implode(', ', $r['objetivos']) . '.';
        }

        if (count($r['areas']) > 0) {
            $hoy[] = '- Quiere mejorar: ' . implode(', ', $r['areas']) . '.';
        }

        if (count($hoy) > 0) {

            $lista[] = [
                'ruta'      => AgenteContextoHelper::MAESTRO,
                'marca'     => self::MARCA,
                'seccion'   => 'Lo que le importa hoy',
                'contenido' => implode("\n", $hoy) . "\n" . '- El detalle está en negocio/prioridades.md y negocio/objetivos.md.',
            ];
        }

        // ---- Algo más ----
        if ($r['extra'] !== '') {

            $lista[] = [
                'ruta'      => 'dueno/perfil.md',
                'marca'     => self::MARCA,
                'seccion'   => 'Lo que contó al empezar',
                'contenido' => $r['extra'] . "\n\n" . $al_pie,
            ];
        }

        return $lista;
    }

    /**
     * Un texto libre recortado, con los saltos de línea de Windows normalizados.
     *
     * @param  mixed  $valor
     * @return string
     */
    protected static function texto($valor)
    {
        return trim(str_replace("\r\n", "\n", (string) $valor));
    }

    /**
     * Una lista de chips: sin vacíos, sin espacios de más y sin repetidos (sin mirar mayúsculas).
     *
     * @param  mixed  $valores
     * @return array<int, string>
     */
    protected static function lista($valores)
    {
        if (!is_array($valores)) {

            return [];
        }

        $lista = [];
        $vistos = [];

        foreach ($valores as $valor) {

            $limpio = trim(preg_replace('/\s+/u', ' ', (string) $valor));
            $clave  = mb_strtolower($limpio);

            if ($limpio === '' || isset($vistos[$clave])) {
                continue;
            }

            $vistos[$clave] = true;
            $lista[] = $limpio;
        }

        return $lista;
    }

    /**
     * «vender más» → «Vender más».
     *
     * @param  string  $texto
     * @return string
     */
    protected static function con_mayuscula($texto)
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
