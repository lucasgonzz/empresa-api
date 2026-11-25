<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConocimientoDelNegocio;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El índice de conocimiento del negocio (misión onboarding-conversacional-whatsapp, 23/11/2026): los 32 temas
 * que el asistente tiene que conocer del negocio de su dueño, cuáles son esenciales, a qué archivo de la memoria
 * va lo que el dueño cuente de cada uno y cuáles ya sabe, cuáles el dueño no quiso contestar y cuáles se le
 * quedaron viejos.
 *
 * El CONTENIDO va a la memoria por archivos que ya existe (AgenteContextoHelper, sin tocarla); acá sólo está el
 * ESTADO por tema (tabla `ai_conocimiento_del_negocio`, una fila por tema marcado):
 *   - falta  = no hay fila;
 *   - sabido = el dueño lo contó (o lo trajo el formulario «Conozcámonos»);
 *   - omitido = no aplica o no quiso contestarlo: no se vuelve a preguntar;
 *   - viejo  = un `sabido` cuya vigencia venció. Se CALCULA al leer, no se guarda, y no reabre el onboarding.
 *
 * El ciclo de vida (cuándo arranca, el cursor de la pregunta en curso, los recordatorios, cuándo termina) vive
 * en OnboardingDelNegocioIaHelper.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class ConocimientoDelNegocioIaHelper
{
    const FALTA = 'falta';

    const VIEJO = 'viejo';

    /** @var bool|null  Sólo se guarda el «true»: si la tabla y las columnas del dueño ya existen (por proceso). */
    protected static $hay_tablas = null;

    /**
     * true si esta base tiene la tabla de temas y las columnas del onboarding en `users`. Una instalación a
     * medio actualizar (tienda desplegada antes, o la migración todavía sin correr) no ofrece nada en silencio.
     *
     * 🔴 Sólo se cachea el «true»: un worker vivo que arrancó antes de migrar no se queda con el «false» hasta
     * reiniciarse (misma lección que TareasDelAsistenteIaHelper::hay_tablas()).
     *
     * @return bool
     */
    public static function hay_tablas(): bool
    {
        if (self::$hay_tablas === true) {

            return true;
        }

        try {

            $hay = Schema::hasTable('ai_conocimiento_del_negocio')
                && Schema::hasColumn('users', 'ia_onboarding_estado')
                && Schema::hasColumn('users', 'ia_onboarding_cursor_desde');

        } catch (\Throwable $e) {

            return false;
        }

        if ($hay) {

            self::$hay_tablas = true;
        }

        return $hay;
    }

    /**
     * Los 32 temas, en el orden del catálogo, por clave. Cada uno: bloque, esencial (★), destino (archivo de la
     * memoria donde va lo que cuente), vigencia (días hasta que se considera viejo; null = no vence) y la pregunta
     * guía (el agente la dice a su manera).
     *
     * 13 son esenciales. El orden en que SE PREGUNTA lo da orden_de_preguntas(): los esenciales primero.
     *
     * @return array<string, array{clave: string, bloque: string, esencial: bool, destino: string, vigencia: int|null, pregunta: string}>
     */
    public static function catalogo(): array
    {
        $filas = [
            ['historia', 'identidad', true, 'dueno/perfil.md', null, '¿Cómo arrancó el negocio y por qué te dedicaste a esto?'],
            ['como_lo_describen', 'identidad', true, 'negocio/identidad.md', null, 'Si un cliente describe tu negocio, ¿qué querés que diga? ¿Y qué no?'],
            ['posicionamiento', 'identidad', true, 'negocio/identidad.md', 365, '¿El más barato, el de mejor atención, el más completo o el más premium? Uno.'],
            ['competencia', 'identidad', false, 'negocio/identidad.md', 365, '¿Contra quién competís y qué hace mejor cada uno?'],
            ['vision_3_anios', 'identidad', false, 'negocio/objetivos.md', 365, '¿Dónde ves el negocio en 3 años?'],
            ['que_vende_y_a_quien', 'negocio', true, 'negocio/resumen.md', null, '¿Qué vendés y a quién? ¿Minorista, mayorista, precios distintos?'],
            ['reglas_del_negocio', 'negocio', true, 'negocio/reglas.md', 365, 'Horarios, reparto, mínimos, medios de pago, cambios, señas.'],
            ['preguntas_frecuentes', 'negocio', true, 'negocio/reglas.md', 365, '¿Qué te preguntan siempre los clientes?'],
            ['que_decide_solo', 'negocio', false, 'dueno/perfil.md', null, '¿Qué decidís sólo vos y qué resuelve cualquiera?'],
            ['cuenta_corriente', 'negocio', false, 'areas/clientes_y_cobranzas.md', 365, '¿Fiás? ¿Cómo manejás la deuda y los recordatorios?'],
            ['mejor_cliente', 'clientes', true, 'areas/clientes_y_cobranzas.md', null, 'Describime al cliente que querrías tener 100 veces.'],
            ['cliente_complicado', 'clientes', false, 'areas/clientes_y_cobranzas.md', null, '¿Y el que te complica la vida?'],
            ['por_que_lo_eligen', 'clientes', false, 'negocio/identidad.md', 365, '¿Por qué te eligen a vos y no al de enfrente?'],
            ['lo_que_piden', 'clientes', false, 'areas/ventas.md', 180, '¿Qué te piden que todavía no ofrecés?'],
            ['recorrido_de_un_pedido', 'procesos', true, 'areas/ventas.md', 365, 'Contame un pedido típico de punta a punta.'],
            ['equipo', 'procesos', true, 'areas/equipo.md', 180, '¿Quiénes trabajan con vos y qué hace cada uno?'],
            ['proveedores_clave', 'procesos', true, 'areas/compras_y_proveedores.md', 365, '¿Con qué proveedores trabajás y por qué? ¿Cómo comprás?'],
            ['cuello_de_botella', 'procesos', false, 'negocio/prioridades.md', 180, '¿Dónde se traba todo cuando hay mucho trabajo?'],
            ['tarea_repetitiva', 'procesos', false, 'negocio/prioridades.md', 180, '¿Qué tarea repetitiva odiás vos o tu equipo?'],
            ['papel_o_excel', 'procesos', false, 'negocio/prioridades.md', 180, '¿Qué se hace todavía en papel o en un Excel aparte?'],
            ['stock_que_no_falta', 'procesos', false, 'areas/stock.md', 365, '¿Qué no te puede faltar nunca? ¿Cada cuánto reponés?'],
            ['como_llegan_clientes', 'marketing', true, 'areas/marketing.md', 365, '¿Cómo te llegan los clientes nuevos?'],
            ['tono_con_clientes', 'marketing', true, 'areas/marketing.md', null, '¿Cómo le hablás a tus clientes? ¿De vos, de usted, con emojis?'],
            ['promos', 'marketing', false, 'areas/marketing.md', 365, '¿Qué promoción te funcionó mejor? ¿Y cuál fue un fracaso?'],
            ['temporadas', 'marketing', false, 'areas/ventas.md', null, '¿Cuáles son tus temporadas fuertes y muertas?'],
            ['redes_y_tienda', 'marketing', false, 'areas/tienda_online.md', 365, '¿Publicás en redes o vendés online? ¿Quién lo hace?'],
            ['meta_del_anio', 'objetivos', true, 'negocio/objetivos.md', 365, '¿Cuál es LA meta de este año, en una frase?'],
            ['margen_buscado', 'objetivos', false, 'areas/finanzas.md', 365, '¿Qué margen buscás? ¿Productos para ganar y para atraer?'],
            ['estrellas_y_muertos', 'objetivos', false, 'areas/stock.md', 180, '¿Cuáles son tus productos estrella y los que no salen? (su opinión, no números)'],
            ['numero_que_mira', 'objetivos', false, 'areas/finanzas.md', 365, '¿Qué número mirás cada semana para saber si viene bien?'],
            ['prioridad_hoy', 'objetivos', false, 'negocio/prioridades.md', 90, '¿Qué es lo que más te importa hoy?'],
            ['como_responder', 'dueno', false, 'dueno/como_responder.md', null, '¿Preferís respuestas cortas o explicadas? ¿Con números o gráficos?'],
        ];

        $catalogo = [];

        foreach ($filas as $fila) {

            $catalogo[$fila[0]] = [
                'clave'    => $fila[0],
                'bloque'   => $fila[1],
                'esencial' => $fila[2],
                'destino'  => $fila[3],
                'vigencia' => $fila[4],
                'pregunta' => $fila[5],
            ];
        }

        return $catalogo;
    }

    /**
     * Las claves en el orden en que se le preguntan al dueño: primero los esenciales (en el orden del catálogo) y
     * después el resto.
     *
     * @return array<int, string>
     */
    public static function orden_de_preguntas(): array
    {
        $esenciales = [];
        $resto = [];

        foreach (self::catalogo() as $clave => $tema) {

            if ($tema['esencial']) {

                $esenciales[] = $clave;

            } else {

                $resto[] = $clave;
            }
        }

        return array_merge($esenciales, $resto);
    }

    /**
     * Las claves válidas, en el orden del catálogo.
     *
     * @return array<int, string>
     */
    public static function claves(): array
    {
        return array_keys(self::catalogo());
    }

    /**
     * El estado de los 32 temas del dueño y las cuentas.
     *
     * Por tema: `estado` (falta | sabido | omitido | viejo), `fuente` y `marcado_at` (null si falta). Un tema
     * `viejo` es un `sabido` cuya vigencia venció; para las cuentas sigue siendo «sabido» (no reabre el onboarding).
     *
     * @param  \App\Models\User  $dueno
     * @return array{temas: array<string, array<string,mixed>>, cuentas: array<string,int>}
     */
    public static function estado(User $dueno): array
    {
        $por_clave = [];

        if (self::hay_tablas()) {

            $filas = DB::table('ai_conocimiento_del_negocio')
                ->where('owner_id', (int) $dueno->id)
                ->orderBy('marcado_at')
                ->orderBy('id')
                ->get();

            /* Si una carrera dejó dos filas de la misma clave, gana la de `marcado_at` mayor (la última del orden). */
            foreach ($filas as $fila) {

                $por_clave[$fila->clave] = $fila;
            }
        }

        $ahora = Carbon::now();
        $temas = [];
        $cuentas = ['total' => 0, 'sabidos' => 0, 'omitidos' => 0, 'faltan' => 0, 'viejos' => 0, 'esenciales_total' => 0, 'esenciales_listos' => 0];

        foreach (self::catalogo() as $clave => $tema) {

            $cuentas['total']++;

            if ($tema['esencial']) {

                $cuentas['esenciales_total']++;
            }

            if (!isset($por_clave[$clave])) {

                $temas[$clave] = ['estado' => self::FALTA, 'fuente' => null, 'marcado_at' => null, 'esencial' => $tema['esencial']];
                $cuentas['faltan']++;

                continue;
            }

            $fila = $por_clave[$clave];
            $estado = (string) $fila->estado;

            if ($estado === AiConocimientoDelNegocio::ESTADO_SABIDO) {

                $viejo = !is_null($tema['vigencia']) && Carbon::parse($fila->marcado_at)->addDays($tema['vigencia'])->lt($ahora);

                $estado = $viejo ? self::VIEJO : AiConocimientoDelNegocio::ESTADO_SABIDO;

                $cuentas['sabidos']++;

                if ($viejo) {

                    $cuentas['viejos']++;
                }

            } else {

                $estado = AiConocimientoDelNegocio::ESTADO_OMITIDO;
                $cuentas['omitidos']++;
            }

            if ($tema['esencial']) {

                $cuentas['esenciales_listos']++;
            }

            $temas[$clave] = ['estado' => $estado, 'fuente' => $fila->fuente, 'marcado_at' => $fila->marcado_at, 'esencial' => $tema['esencial']];
        }

        return ['temas' => $temas, 'cuentas' => $cuentas];
    }

    /**
     * Marca temas como sabidos u omitidos (por el único camino de escritura: lo usan las dos herramientas del
     * agente) y deja al día el onboarding (cursor, esenciales, completo).
     *
     * Las claves desconocidas NO hacen fallar nada: vuelven en `desconocidos` para que el modelo las corrija.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $claves  Claves del catálogo.
     * @param  string  $estado  `sabido` u `omitido`.
     * @param  string  $fuente  `agente` | `cuestionario` | `dueno`.
     * @param  int|null  $conversacion_id  La conversación donde lo contó.
     * @return array{marcados: array<int,string>, desconocidos: array<int,string>, cruzo_esenciales: bool, quedo_completo: bool, aviso: string}
     */
    public static function marcar(User $dueno, array $claves, $estado, $fuente, $conversacion_id = null): array
    {
        $validas = self::claves();
        $marcados = [];
        $desconocidos = [];

        foreach ($claves as $clave) {

            $clave = is_string($clave) ? trim($clave) : '';

            if ($clave === '') {

                continue;
            }

            if (!in_array($clave, $validas, true)) {

                $desconocidos[] = $clave;

            } elseif (!in_array($clave, $marcados, true)) {

                $marcados[] = $clave;
            }
        }

        $resultado = ['marcados' => [], 'desconocidos' => array_values(array_unique($desconocidos)), 'cruzo_esenciales' => false, 'quedo_completo' => false, 'aviso' => ''];

        if (!count($marcados) || !self::hay_tablas()) {

            return $resultado;
        }

        $antes = self::estado($dueno)['cuentas'];

        DB::transaction(function () use ($dueno, $marcados, $estado, $fuente, $conversacion_id) {

            $ahora = Carbon::now()->format('Y-m-d H:i:s');

            foreach ($marcados as $clave) {

                $fila = AiConocimientoDelNegocio::where('owner_id', (int) $dueno->id)
                    ->where('clave', $clave)
                    ->orderBy('marcado_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();

                $datos = [
                    'estado'             => $estado,
                    'fuente'             => $fuente,
                    'ai_conversation_id' => is_null($conversacion_id) ? null : (int) $conversacion_id,
                    'marcado_at'         => $ahora,
                ];

                if (is_null($fila)) {

                    AiConocimientoDelNegocio::create(array_merge($datos, ['owner_id' => (int) $dueno->id, 'clave' => $clave]));

                } else {

                    $fila->update($datos);
                }
            }
        });

        $despues = self::estado($dueno)['cuentas'];

        $resultado['marcados'] = $marcados;
        $resultado['cruzo_esenciales'] = $antes['esenciales_listos'] < $antes['esenciales_total'] && $despues['esenciales_listos'] >= $despues['esenciales_total'];
        $resultado['quedo_completo'] = $antes['faltan'] > 0 && $despues['faltan'] === 0;

        try {

            $resultado['aviso'] = OnboardingDelNegocioIaHelper::al_marcar($dueno, $resultado);

        } catch (\Throwable $e) {

            Log::warning('ConocimientoDelNegocioIaHelper: no se pudo actualizar el onboarding después de marcar', ['owner_id' => $dueno->id, 'error' => $e->getMessage()]);
        }

        return $resultado;
    }

    /**
     * Siembra desde el formulario «Conozcámonos» lo que el dueño ya contó, para no volver a preguntárselo.
     * Idempotente y sin pisar: si el tema ya tiene fila (el agente lo marcó antes), queda la que estaba.
     *
     *   negocio_frase                       → que_vende_y_a_quien
     *   objetivos u objetivos_texto         → meta_del_anio
     *   areas o importa_hoy                 → prioridad_hoy
     *   formulario completado               → como_responder
     *
     * @param  \App\Models\User  $dueno
     * @return int  Cuántos temas sembró.
     */
    public static function sembrar_desde_cuestionario(User $dueno): int
    {
        if (!self::hay_tablas()) {

            return 0;
        }

        $propietario = AgenteContextoHelper::dueno($dueno);

        if (is_null($propietario) || empty($propietario->ia_cuestionario_completado_at)) {

            return 0;
        }

        $respuestas = CuestionarioDeInicioIaHelper::respuestas($propietario);
        $respuestas = is_array($respuestas) ? $respuestas : [];

        $lleno = function ($campo) use ($respuestas) {

            if (!isset($respuestas[$campo])) {

                return false;
            }

            return is_array($respuestas[$campo]) ? count($respuestas[$campo]) > 0 : trim((string) $respuestas[$campo]) !== '';
        };

        $temas = ['como_responder'];

        if ($lleno('negocio_frase')) {

            $temas[] = 'que_vende_y_a_quien';
        }

        if ($lleno('objetivos') || $lleno('objetivos_texto')) {

            $temas[] = 'meta_del_anio';
        }

        if ($lleno('areas') || $lleno('importa_hoy')) {

            $temas[] = 'prioridad_hoy';
        }

        $ya_marcados = DB::table('ai_conocimiento_del_negocio')->where('owner_id', (int) $propietario->id)->pluck('clave')->all();
        $momento = Carbon::parse($propietario->ia_cuestionario_completado_at)->format('Y-m-d H:i:s');
        $sembrados = 0;

        foreach ($temas as $clave) {

            if (in_array($clave, $ya_marcados, true)) {

                continue;
            }

            AiConocimientoDelNegocio::create([
                'owner_id'           => (int) $propietario->id,
                'clave'              => $clave,
                'estado'             => AiConocimientoDelNegocio::ESTADO_SABIDO,
                'fuente'             => AiConocimientoDelNegocio::FUENTE_CUESTIONARIO,
                'ai_conversation_id' => null,
                'marcado_at'         => $momento,
            ]);

            $sembrados++;
        }

        if ($sembrados > 0) {

            try {

                OnboardingDelNegocioIaHelper::al_marcar($propietario, ['marcados' => $temas, 'cruzo_esenciales' => false, 'quedo_completo' => false]);

            } catch (\Throwable $e) {

                Log::warning('ConocimientoDelNegocioIaHelper: no se pudo actualizar el onboarding después de sembrar', ['owner_id' => $propietario->id, 'error' => $e->getMessage()]);
            }
        }

        return $sembrados;
    }
}
