<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessageAction;
use App\Models\Pending;
use App\Models\UnidadFrecuencia;
use Carbon\Carbon;

/**
 * Base de las suites de la misión recordatorios-whatsapp (16/10/2026): «recordame el viernes a las
 * 10 que…» le llega al dueño por WhatsApp a esa hora. La escena (tareas con su `created_at`, las
 * llamadas del admin y la confirmación de una tarjeta) es la misma para el contrato con el admin
 * (16_Recordatorios_por_whatsapp_Test) y para lo que pasa al confirmar la tarea
 * (17_Recordatorio_al_confirmar_la_tarea_Test).
 *
 * 🔴 El reloj está fijo (Carbon::setTestNow) en el viernes 16/10/2026 a las 10:30: todo lo que se
 * mide acá es «qué vence ahora», y con el reloj de verdad cada test dependería de la hora a la que se
 * corre. Las tareas se crean con el reloj en el momento que el test necesita para su `created_at`.
 */
abstract class RecordatoriosTestCase extends AsistenteWhatsappTestCase
{
    /** "Ahora" en toda la suite: un viernes a las 10:30. */
    const AHORA = '2026-10-16 10:30:00';

    /** El día de "ahora". */
    const HOY = '2026-10-16';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Carbon::setTestNow(Carbon::parse(self::AHORA));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ─────────────────────────────── escena ─────────────────────────────── */

    /**
     * Una tarea del comercio que pide el aviso, creada a las 08:00 de hoy salvo que se diga otra cosa.
     *
     * @param  array  $columnas  Columnas a pisar.
     * @param  string  $creada_a  Momento del `created_at`.
     * @return \App\Models\Pending
     */
    protected function tarea(array $columnas = [], $creada_a = '2026-10-16 08:00:00')
    {
        Carbon::setTestNow(Carbon::parse($creada_a));

        $tarea = Pending::create(array_merge([
            'user_id'             => $this->comercio->id,
            'detalle'             => '10:00 · Llamar a Juan por el presupuesto',
            'fecha_realizacion'   => self::HOY . ' 00:00:00',
            'es_recurrente'       => 0,
            'completado'          => 0,
            'hora_aviso'          => '10:00:00',
            'avisar_por_whatsapp' => 1,
        ], $columnas));

        Carbon::setTestNow(Carbon::parse(self::AHORA));

        return $tarea->fresh();
    }

    /**
     * Unidad de frecuencia por slug, como AgendaTestCase::unidad() (el fixture no las siembra).
     *
     * @param  string  $slug
     * @return \App\Models\UnidadFrecuencia
     */
    protected function unidad($slug)
    {
        $unidad = UnidadFrecuencia::where('slug', $slug)->first();

        if (!is_null($unidad)) {
            return $unidad;
        }

        $unidad = new UnidadFrecuencia();
        $unidad->name = ucfirst($slug);
        $unidad->slug = $slug;
        $unidad->save();

        return $unidad;
    }

    /**
     * GET recordatorios-pendientes.
     *
     * @param  string  $query
     * @param  bool  $con_clave
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pendientes($query = '', $con_clave = true)
    {
        return $this->getJson('api/admin-sync/asistente/recordatorios-pendientes' . $query, $this->headers($con_clave));
    }

    /**
     * Los ids de tarea que vinieron en recordatorios-pendientes.
     *
     * @return array<int,int>
     */
    protected function tareas_pendientes()
    {
        $respuesta = $this->pendientes();

        $respuesta->assertStatus(200);

        return array_map(function ($recordatorio) {
            return (int) $recordatorio['tarea_id'];
        }, $respuesta->json('recordatorios'));
    }

    /**
     * POST recordatorios/avisado.
     *
     * @param  mixed  $id
     * @param  string|null  $wamid
     * @return \Illuminate\Testing\TestResponse
     */
    protected function avisado($id, $wamid = 'wamid.RECORDATORIO1')
    {
        return $this->postJson('api/admin-sync/asistente/recordatorios/avisado', ['id' => $id, 'whatsapp_message_id' => $wamid], $this->headers());
    }

    /**
     * El id del contrato de una tarea: "recordatorio:{id}-{created_at unix}:{Y-m-d}:{HHMM}".
     *
     * @param  \App\Models\Pending  $tarea
     * @param  string  $dia  Y-m-d de la ocurrencia.
     * @return string
     */
    protected function id_de($tarea, $dia = self::HOY)
    {
        return 'recordatorio:' . $tarea->id . '-' . $tarea->created_at->timestamp . ':' . $dia
            . ':' . str_replace(':', '', substr((string) $tarea->hora_aviso, 0, 5));
    }

    /**
     * Una tarjeta de tarea nueva en memoria, con el body de `POST api/pending`.
     *
     * @param  string  $detalle
     * @param  array  $datos
     * @return \App\Models\AiMessageAction
     */
    protected function tarjeta_de_tarea($detalle, array $datos = [])
    {
        $accion = new AiMessageAction();

        $accion->tipo = AiMessageAction::TIPO_TAREA_NUEVA;
        $accion->datos = array_merge([
            'detalle'               => $detalle,
            'fecha_realizacion'     => '2026-10-23',
            'es_recurrente'         => false,
            'unidad_frecuencia_id'  => null,
            'cantidad_frecuencia'   => null,
            'fecha_fin_recurrencia' => null,
            'expense_concept_id'    => null,
            'expense_amount'        => null,
            'notas'                 => null,
        ], $datos);

        return $accion;
    }

    /**
     * Confirma una tarjeta de tarea nueva como la persona dada (null = el dueño).
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  \App\Models\User|null  $persona
     * @return array  El resultado de ejecutar_tarea_nueva().
     */
    protected function confirmar_tarea(AiMessageAction $accion, $persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversacion = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);

        return PropuestaTareaIaHelper::ejecutar_tarea_nueva(ContextoDeCargaIa::de_la_conversacion($conversacion, $persona), $accion);
    }
}
