<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\BusinessHoursConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Recibe el horario comercial que empuja admin-api (ClientScheduleSyncService) por
 * PUT api/admin-sync/business-hours, autenticado con X-Admin-Api-Key (middleware
 * admin.api.key), igual que el resto de los endpoints AdminSync.
 *
 * Es el UNICO escritor de business_hours_configs.
 *
 * 🔴 CUATRO REGLAS QUE GOBIERNAN ESTE CONTROLLER (y que alguien va a querer simplificar):
 *
 *  1. **La semana llega YA RESUELTA.** El admin aplica la precedencia de "Todos los dias" vs
 *     el dia puntual antes de mandar. Aca NO se reimplementa ninguna regla: no se ordenan
 *     rangos, no se infieren estados, no se completan dias. Si las dos puntas resolvieran el
 *     mismo invariante por su cuenta, el dia que se agregue un caso (feriados, medio dia)
 *     quedarian dos criterios y uno se iba a olvidar. `dias_crudos` viaja solo como comodidad
 *     de lectura y NO es fuente de verdad.
 *
 *  2. **`configurado: false` es "NO HAY DATO", jamas "cerrado".** Y adentro de la semana,
 *     `abierto` tiene tres valores (true / false / null = sin_configurar). Este controller
 *     guarda los tres tal como llegan; el que lea decide, pero nunca colapsando null a false.
 *
 *  3. **Idempotencia.** El push llega por un job encolado del admin y se puede repetir con el
 *     mismo contenido. updateOrCreate por user_id (+ unique en el motor) ⇒ N pushes iguales
 *     dejan UNA fila, HTTP 200 las N veces y EL MISMO cuerpo de respuesta. Ojo: idempotencia
 *     es "una sola fila con el ultimo contenido", no "la fila no se toca": `recibido_at` se
 *     estampa en cada push a proposito.
 *
 *  4. **Compatibilidad hacia atras.** Las claves desconocidas de NIVEL 1 se ignoran sin error
 *     (el admin puede empezar a mandar 'feriados' o 'version_contrato' antes de que este repo
 *     se entere). Las subclaves desconocidas de adentro de `semana` / `dias_crudos` se guardan
 *     VERBATIM, porque las columnas son json y se persiste el array tal como llego, sin
 *     re-mapear campo por campo. Tampoco se valida `estado` / `origen` contra una enumeracion,
 *     ni el formato HH:MM de las horas: rechazar el horario entero por un valor nuevo o un
 *     formato raro dejaria al comercio sin horario.
 */
class BusinessHoursController extends Controller
{
    /**
     * Cantidad de dias que trae una semana completa. Siempre siete: es una semana.
     *
     * @var int
     */
    private const DIAS_DE_LA_SEMANA = 7;

    /**
     * Guarda (o pisa) el horario comercial del owner.
     *
     * Body del contrato (ver ClientScheduleSyncService::build_payload() del admin-api):
     *  - timezone       (string|null): 'America/Argentina/Buenos_Aires'.
     *  - actualizado_en (string|null): ISO8601 armado por el admin, se guarda crudo.
     *  - configurado    (bool):        false = NO HAY DATO. Nunca "cerrado".
     *  - semana         (array):       los 7 dias ya resueltos, verbatim.
     *  - dias_crudos    (array):       los dias tal como estan cargados. Comodidad de lectura.
     *  - user_id        (int|null):    opcional; el emisor de hoy NO lo manda.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        /*
         * 🔴 EL GUARD MAS IMPORTANTE DEL ENDPOINT, Y VA PRIMERO.
         *
         * Un body vacio, mal formado o no-JSON llega a Laravel como input vacio. Sin este
         * corte, ese request escribiria `configurado = false, semana = null` ENCIMA de un
         * horario bueno: el comercio perderia el horario por un push roto, con 200 y sin que
         * nadie se entere.
         *
         * ⚠️ Un `configurado: false` legitimo SI pasa el guard: lo que se exige no es que el
         * valor sea "truthy" sino que sea USABLE. No cambiar esto por un `filled()` ni por un
         * chequeo de truthiness: seria rechazar justo el caso valido de "el comercio todavia no
         * cargo el horario", que viaja como `configurado: false` con `semana: []`.
         *
         * 🔴 Y NO alcanza con `has()`, que es `array_key_exists`: `{"configurado": null}` y
         * `{"semana": null}` tienen la clave presente con un valor que no dice nada, pasaban el
         * guard y terminaban pisando un horario bueno con `configurado = false, semana = []`,
         * devolviendo 200. O sea: exactamente el modo de falla que este guard existe para
         * evitar, entrando por la puerta de al lado. Por eso se mira el VALOR.
         */
        $configurado_crudo = $request->input('configurado');
        $semana_cruda      = $request->input('semana');

        if ($configurado_crudo === null && ! is_array($semana_cruda)) {
            return response()->json([
                'error'   => 'payload_vacio',
                'message' => 'El body no trae "configurado" ni una "semana" usable. No se pisa el '
                    . 'horario guardado con un push vacio o mal formado.',
            ], 422);
        }

        /*
         * Validacion acotada, con la excepcion habilitada para contrato externo. Se valida SOLO
         * lo que puede romper la fila o dejar un dia mal ubicado; todo lo demas viaja libre para
         * no romper la compatibilidad hacia atras (ver regla 4 del docblock de la clase).
         */
        $validator = Validator::make($request->all(), [
            // Entran a columnas de 64 y 40 caracteres.
            'timezone'       => 'nullable|string|max:64',
            'actualizado_en' => 'nullable|string|max:40',
            // Bandera de nivel payload.
            'configurado'    => 'nullable|boolean',
            // Si vienen, tienen que ser listas.
            'semana'         => 'nullable|array',
            'dias_crudos'    => 'nullable|array',
            // Sin indice de dia el item es inutil, y ubicarlo mal seria peor que no tenerlo.
            'semana.*.dia_semana' => 'required|integer|between:0,6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'validacion',
                'message' => (string) $validator->errors()->first(),
            ], 422);
        }

        // `semana` y `dias_crudos` se toman TAL CUAL vinieron: no se recorren ni se re-mapean.
        $dias_crudos = $request->input('dias_crudos');
        $semana      = is_array($semana_cruda) ? $semana_cruda : [];
        $dias_crudos = is_array($dias_crudos) ? $dias_crudos : [];

        /*
         * Si el admin no manda `configurado` (contrato viejo o emisor futuro que lo deje de
         * mandar), se deriva de si hay al menos un dia en la semana. Es la unica inferencia del
         * controller y es la conservadora: sin dias, no hay dato.
         */
        $configurado = $configurado_crudo !== null
            ? $request->boolean('configurado')
            : (count($semana) > 0);

        /*
         * Contradiccion del contrato: el emisor NUNCA produce `configurado: true` con la semana
         * vacia. Si aparece, es que el emisor cambio, y queremos enterarnos por el
         * `schedule_sync_status = failed` del admin — no guardar un estado a medias que despues
         * el lector tenga que adivinar.
         */
        if ($configurado && count($semana) === 0) {
            return response()->json([
                'error'   => 'semana_vacia_con_configurado_true',
                'message' => 'Llego "configurado": true con la semana vacia. El contrato no permite '
                    . 'esa combinacion: o hay semana, o "configurado" es false.',
            ], 422);
        }

        /*
         * Una semana con una cantidad de dias distinta de 7 se ACEPTA y se guarda tal cual: el
         * lector completa los que falten como "sin dato". Rechazar el push dejaria al comercio
         * sin NINGUN horario, que es peor que tener uno incompleto.
         */
        if ($configurado && count($semana) !== self::DIAS_DE_LA_SEMANA) {
            Log::warning('AdminSync business-hours: la semana no trae 7 dias.', [
                'dias' => count($semana),
            ]);
        }

        $owner = $this->resolve_owner($request);

        /*
         * Owner inexistente ⇒ 422, a proposito DISTINTO de SistemaQueryController, que en este
         * caso devuelve 200 con una `note`. Aca un 200 seria mentir ("lo guarde") y el admin
         * marcaria `success` sobre un push que no persistio absolutamente nada.
         */
        if ($owner === null) {
            return response()->json([
                'error'   => 'owner_no_encontrado',
                'message' => 'No se encontro un owner en el sistema al que guardarle el horario.',
            ], 422);
        }

        $timezone       = trim((string) $request->input('timezone'));
        $actualizado_en = trim((string) $request->input('actualizado_en'));

        $valores = [
            'timezone'       => $timezone === '' ? null : $timezone,
            'actualizado_en' => $actualizado_en === '' ? null : $actualizado_en,
            'configurado'    => $configurado,
            'semana'         => $semana,
            'dias_crudos'    => $dias_crudos,
            'recibido_at'    => Carbon::now(),
        ];

        try {
            /*
             * updateOrCreate por user_id: es lo que hace idempotente al endpoint. El unique
             * bhc_user_id_unique de la migracion es el respaldo del motor por si alguna vez
             * entran dos pushes en paralelo.
             *
             * 🔴 Pero ese respaldo TIRA el request, no lo absorbe: dos pushes concurrentes hacen
             * los dos el SELECT, los dos no encuentran nada, los dos INSERT, y el segundo choca
             * contra el unique (23000) o contra un deadlock del motor (40001). Reproducido de
             * verdad en la base de testing del slot. Sin este reintento, ese segundo push
             * termina en 500 y el admin lo marca `failed` por una carrera que se resuelve sola:
             * en la segunda vuelta la fila ya existe y el updateOrCreate hace UPDATE.
             */
            $this->guardar_con_reintento($owner->id, $valores);
        } catch (\Throwable $e) {
            Log::error('AdminSync business-hours: ' . $e->getMessage());

            return response()->json(['error' => 'internal error'], 500);
        }

        Log::info('AdminSync business-hours OK', [
            'user_id'     => $owner->id,
            'configurado' => $configurado,
            'dias'        => count($semana),
        ]);

        /*
         * Acuse, no el modelo: el emisor solo mira el 2xx. El cuerpo se arma con los valores que
         * se acaban de guardar, asi que el push 1 y el push N con el mismo contenido devuelven
         * exactamente lo mismo (`recibido_at`, que si cambia en cada push, NO viaja en la
         * respuesta justamente por eso).
         */
        return response()->json([
            'ok'          => true,
            'user_id'     => $owner->id,
            'configurado' => $configurado,
            'dias'        => count($semana),
            'timezone'    => $timezone === '' ? null : $timezone,
        ], 200);
    }

    /**
     * Guarda la fila del owner, reintentando una vez si dos pushes entraron en paralelo.
     *
     * Solo se reintenta el choque de concurrencia (violacion de unique / deadlock), que es el
     * que se resuelve solo en la segunda vuelta porque para entonces la fila ya existe y el
     * updateOrCreate pasa a hacer UPDATE. Cualquier otro QueryException (columna que no existe,
     * tabla sin migrar, valor invalido) se propaga tal cual: reintentarlo seria repetir el
     * mismo error dos veces y tapar el motivo real.
     *
     * @param  int    $user_id  Owner dueño de la fila.
     * @param  array  $valores  Campos a guardar.
     * @return void
     */
    protected function guardar_con_reintento(int $user_id, array $valores)
    {
        try {
            BusinessHoursConfig::updateOrCreate(['user_id' => $user_id], $valores);

            return;
        } catch (\Illuminate\Database\QueryException $e) {
            /** SQLSTATE: 23000 = violacion de integridad (el unique), 40001 = deadlock. */
            $sqlstate = (string) $e->getCode();

            if ($sqlstate !== '23000' && $sqlstate !== '40001') {
                throw $e;
            }

            Log::warning('AdminSync business-hours: dos pushes en paralelo, reintentando.', [
                'user_id'  => $user_id,
                'sqlstate' => $sqlstate,
            ]);
        }

        BusinessHoursConfig::updateOrCreate(['user_id' => $user_id], $valores);
    }

    /**
     * Owner al que se le guarda el horario.
     *
     * Mismo criterio que SistemaQueryController::resolve_owner(): primero un `user_id` explicito
     * del body (el emisor de hoy NO lo manda, pero el contrato lo deja abierto), y si no, el
     * owner de la instancia.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Models\User|null
     */
    protected function resolve_owner(Request $request): ?User
    {
        $explicit_user_id = $request->input('user_id');

        if ($explicit_user_id !== null && is_numeric($explicit_user_id) && (int) $explicit_user_id > 0) {
            $owner = User::find((int) $explicit_user_id);

            if ($owner !== null) {
                return $owner;
            }
        }

        // Por defecto: la cuenta principal de la instalacion.
        return BusinessHoursConfig::owner_de_la_instancia();
    }
}
