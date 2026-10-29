<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El cuestionario de inicio de la app del agente (misión app-ia-y-memoria, 29/10/2026, §E2.4 del
 * plan): «Conozcámonos». El dueño cuenta en qué anda su negocio, qué quiere lograr, qué área quiere
 * mejorar, qué le importa hoy, cómo quiere que le respondan y cuántas confirmaciones quiere.
 *
 * 🔴 ESCRIBE DE FORMA DETERMINISTA, SIN LLAMAR A UN MODELO. Cada respuesta va a un lugar fijo de la
 * memoria por el único camino de escritura (AgenteContextoHelper::guardar, origen `cuestionario`):
 *
 *   negocio_frase              → negocio/resumen.md y la sección «El negocio» del maestro
 *   objetivos + objetivos_texto→ negocio/objetivos.md (y la lista en «Lo que le importa hoy»)
 *   areas + importa_hoy        → negocio/prioridades.md y «Lo que le importa hoy» del maestro
 *   largo + forma              → dueno/como_responder.md y «Cómo quiere que le respondan»
 *   extra                      → la sección «Lo que contó al empezar» de dueno/perfil.md
 *   confianza                  → users.agente_confianza, la MISMA columna y el mismo enum
 *                                (ConfianzaDelAgenteIaHelper::MODOS) que PUT user/asistente-config
 *
 * 🔴 LA CONFIANZA NO SE COPIA A LA MEMORIA. La fuente de verdad es la columna, que el dueño puede
 * cambiar después desde la configuración: una copia en un archivo quedaría vieja y le diría al modelo
 * lo contrario de lo que hace el sistema.
 *
 * Mandarlo dos veces con lo mismo no sube ninguna versión (guardar() no escribe lo que no cambió).
 * Mandarlo con otras respuestas reemplaza lo que escribió el cuestionario; lo anterior queda en las
 * revisiones.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CuestionarioDeInicioIaHelper
{
    /** Motivo de las revisiones que deja el cuestionario. */
    const MOTIVO = 'Cuestionario de inicio de la app';

    const LARGOS = ['breve', 'equilibrado', 'explayado'];

    const FORMAS = ['numeros', 'graficos', 'explicado'];

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
     * Las reglas de validación del POST (§E2.4 del plan).
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

                $resultado = AgenteContextoHelper::guardar(
                    $dueno,
                    $escritura['ruta'],
                    $escritura['operacion'],
                    $escritura['contenido'],
                    isset($escritura['resumen']) ? $escritura['resumen'] : null,
                    self::MOTIVO,
                    AgenteContextoHelper::ORIGEN_CUESTIONARIO,
                    null,
                    isset($escritura['seccion']) ? $escritura['seccion'] : null
                );

                if (!$resultado['ok']) {
                    /* Un tope no frena el cuestionario: lo demás se guarda y la app lo puede mostrar. */
                    $avisos[] = $escritura['ruta'] . ': ' . $resultado['error'];
                    continue;
                }

                if (!$resultado['sin_cambios'] && !in_array($escritura['ruta'], $escritos, true)) {
                    $escritos[] = $escritura['ruta'];
                }
            }

            $respuestas_guardadas = $respuestas;
            $respuestas_guardadas['respondido_en'] = now()->toIso8601String();

            /* La confianza va a la misma columna y con el mismo enum que PUT user/asistente-config. */
            $dueno->agente_confianza              = $respuestas['confianza'];
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
     * La lista de escrituras que generan las respuestas, en orden. Lo que no vino no escribe nada (un
     * archivo no se pisa con vacío porque el dueño no contestó esa pregunta).
     *
     * @param  array  $r      Respuestas normalizadas.
     * @param  string $fecha  d/m/Y de hoy.
     * @return array<int, array>
     */
    protected static function escrituras(array $r, $fecha)
    {
        $plantilla = AgenteContextoHelper::plantilla();
        $al_pie    = 'Lo contó en el cuestionario de inicio el ' . $fecha . '.';
        $lista     = [];

        // ---- Cómo quiere que le respondan (siempre viene: largo y forma son obligatorios) ----
        $largo = self::TEXTO_DEL_LARGO[$r['largo']];
        $forma = self::TEXTO_DE_LA_FORMA[$r['forma']];

        $lista[] = [
            'ruta'      => 'dueno/como_responder.md',
            'operacion' => AgenteContextoHelper::REEMPLAZAR,
            'contenido' => self::archivo($plantilla, 'dueno/como_responder.md', [
                '- Largo de las respuestas: ' . $largo,
                '- Forma: ' . $forma,
                '',
                $al_pie,
            ]),
            'resumen'   => 'Largo: ' . $r['largo'] . '. Forma: ' . $r['forma'] . '.',
        ];

        $lista[] = [
            'ruta'      => AgenteContextoHelper::MAESTRO,
            'operacion' => AgenteContextoHelper::REEMPLAZAR,
            'seccion'   => 'Cómo quiere que le respondan',
            'contenido' => '- Largo: ' . $largo . "\n" . '- Forma: ' . $forma . "\n" . '- El detalle está en dueno/como_responder.md.',
        ];

        // ---- El negocio en una frase ----
        if ($r['negocio_frase'] !== '') {

            $lista[] = [
                'ruta'      => 'negocio/resumen.md',
                'operacion' => AgenteContextoHelper::REEMPLAZAR,
                'contenido' => self::archivo($plantilla, 'negocio/resumen.md', [$r['negocio_frase'], '', $al_pie]),
                'resumen'   => $r['negocio_frase'],
            ];

            $lista[] = [
                'ruta'      => AgenteContextoHelper::MAESTRO,
                'operacion' => AgenteContextoHelper::REEMPLAZAR,
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
                'operacion' => AgenteContextoHelper::REEMPLAZAR,
                'contenido' => self::archivo($plantilla, 'negocio/objetivos.md', $renglones),
                'resumen'   => count($r['objetivos']) > 0
                    ? 'Quiere: ' . implode(', ', $r['objetivos']) . '.'
                    : self::corto($r['objetivos_texto']),
            ];
        }

        // ---- Prioridades: áreas a mejorar y lo que le importa hoy ----
        if (count($r['areas']) > 0 || $r['importa_hoy'] !== '') {

            $renglones = [];

            if (count($r['areas']) > 0) {
                $renglones[] = '## Áreas que quiere mejorar';
                $renglones[] = '';
                foreach ($r['areas'] as $area) {
                    $renglones[] = '- ' . self::con_mayuscula($area);
                }
                $renglones[] = '';
            }

            if ($r['importa_hoy'] !== '') {
                $renglones[] = '## Lo que le importa hoy';
                $renglones[] = '';
                $renglones[] = $r['importa_hoy'];
                $renglones[] = '';
            }

            $renglones[] = $al_pie;

            $lista[] = [
                'ruta'      => 'negocio/prioridades.md',
                'operacion' => AgenteContextoHelper::REEMPLAZAR,
                'contenido' => self::archivo($plantilla, 'negocio/prioridades.md', $renglones),
                'resumen'   => count($r['areas']) > 0
                    ? 'Quiere mejorar: ' . implode(', ', $r['areas']) . '.'
                    : self::corto($r['importa_hoy']),
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
                'operacion' => AgenteContextoHelper::REEMPLAZAR,
                'seccion'   => 'Lo que le importa hoy',
                'contenido' => implode("\n", $hoy) . "\n" . '- El detalle está en negocio/prioridades.md y negocio/objetivos.md.',
            ];
        }

        // ---- Algo más ----
        if ($r['extra'] !== '') {

            $lista[] = [
                'ruta'      => 'dueno/perfil.md',
                'operacion' => AgenteContextoHelper::REEMPLAZAR,
                'seccion'   => 'Lo que contó al empezar',
                'contenido' => $r['extra'] . "\n\n" . $al_pie,
            ];
        }

        return $lista;
    }

    /**
     * Un archivo completo con el encabezado de su plantilla (título y para qué es) y los renglones.
     *
     * @param  array  $plantilla
     * @param  string $ruta
     * @param  array  $renglones
     * @return string
     */
    protected static function archivo(array $plantilla, $ruta, array $renglones)
    {
        $titulo = $plantilla[$ruta][0];
        $para   = $plantilla[$ruta][1];

        return '# ' . $titulo . "\n\n"
             . '> Para qué es este archivo: ' . $para . "\n\n"
             . rtrim(implode("\n", $renglones)) . "\n";
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

    /**
     * La primera línea de un texto, en una línea, para un resumen del índice.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function corto($texto)
    {
        $una_linea = trim(preg_replace('/\s+/u', ' ', (string) $texto));

        return mb_strlen($una_linea) > 200 ? rtrim(mb_substr($una_linea, 0, 199)) . '…' : $una_linea;
    }
}
