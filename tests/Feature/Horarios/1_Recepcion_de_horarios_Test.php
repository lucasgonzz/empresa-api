<?php

namespace Tests\Feature\Horarios;

use App\Models\BusinessHoursConfig;
use App\Models\User;
use App\Services\BusinessHoursReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Recepcion del horario comercial que empuja admin-api (ClientScheduleSyncService) por
 * PUT api/admin-sync/business-hours.
 *
 * 🔴 ESTOS TESTS PEGAN CONTRA EL ENDPOINT REAL CON EL JSON DEL CONTRATO, no contra el modelo.
 * Es a proposito: un contrato entre dos repos que se testea mirando columnas de la base deja
 * pasar justo el error que importa (una clave que se renombro, un verbo que cambio, una ruta
 * que quedo afuera del middleware). El emisor vive en otro repo y no puede correr aca, asi que
 * la unica forma de verificar esta punta es hablarle en el idioma exacto del cable.
 *
 * Lo que protege este archivo, en orden de gravedad:
 *
 *  1. `configurado: false` es "NO HAY DATO", NUNCA "cerrado". Es la razon de ser del contrato:
 *     colapsarlos le diria a un comprador que el comercio esta cerrado un martes a las 10.
 *  2. Idempotencia: el push llega por un job encolado y se repite. N pushes iguales tienen que
 *     dejar UNA fila y devolver EL MISMO cuerpo.
 *  3. Un push roto no puede pisar un horario bueno.
 *  4. Compatibilidad hacia atras: las claves nuevas del admin no rompen nada.
 *
 * @group horarios
 */
class Recepcion_de_horarios_Test extends EmpresaTestCase
{
    /** Ruta del contrato. Es la que arma ClientEmpresaApiUrlResolver::BUSINESS_HOURS_PATH. */
    const RUTA = 'api/admin-sync/business-hours';

    /** Zona horaria que manda el emisor en el payload. */
    const TZ = 'America/Argentina/Buenos_Aires';

    /** Claves de dia por indice de Carbon::dayOfWeek (0 = domingo), como las manda el admin. */
    const DIAS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    /** Etiquetas de dia por indice de Carbon::dayOfWeek, tal como viajan en `dia_label`. */
    const LABELS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    /**
     * Ademas de los guards de EmpresaTestCase, deja la tabla espejo vacia para que cada test
     * arranque de "no hay horario cargado". Corre adentro de la transaccion de
     * `DatabaseTransactions`, asi que no toca nada fuera del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        BusinessHoursConfig::query()->delete();
    }

    /**
     * Owner de la instancia, resuelto SIEMPRE con el criterio del contrato.
     *
     * ⚠️ Ningun test puede asumir que el usuario autenticado por el fixture es el owner: en la
     * base de un slot lo es por casualidad, y en otra instalacion puede no serlo.
     *
     * @return \App\Models\User
     */
    protected function owner()
    {
        return User::whereNull('owner_id')->orderBy('id')->first();
    }

    /**
     * Un dia de `semana` tal como lo arma el emisor, con los overrides que pida el test.
     *
     * @param int   $dow       Indice de Carbon::dayOfWeek (0 = domingo).
     * @param array $overrides Claves a pisar sobre el dia por defecto.
     *
     * @return array
     */
    protected function dia($dow, array $overrides = [])
    {
        $base = [
            'dia_semana' => $dow,
            'dia'        => self::DIAS[$dow],
            'dia_label'  => self::LABELS[$dow],
            'abierto'    => true,
            'estado'     => 'con_horario',
            'origen'     => 'todos_los_dias',
            'rangos'     => [['desde' => '09:00', 'hasta' => '18:00']],
            'cierre'     => '18:00',
        ];

        return array_merge($base, $overrides);
    }

    /**
     * Los siete dias resueltos, de domingo (0) a sabado (6).
     *
     * @return array
     */
    protected function semana_completa()
    {
        $semana = [];

        for ($dow = 0; $dow < 7; $dow++) {
            $semana[] = $this->dia($dow);
        }

        return $semana;
    }

    /**
     * El payload del contrato, con los overrides que pida el test.
     *
     * @param array $overrides Claves de nivel 1 a pisar.
     *
     * @return array
     */
    protected function payload(array $overrides = [])
    {
        $base = [
            'timezone'       => self::TZ,
            'actualizado_en' => '2026-08-25T10:00:00-03:00',
            'configurado'    => true,
            'semana'         => $this->semana_completa(),
            'dias_crudos'    => [
                [
                    'dia'       => 'todos',
                    'dia_label' => 'Todos los días',
                    'rangos'    => [['desde' => '09:00', 'hasta' => '18:00']],
                ],
            ],
        ];

        return array_merge($base, $overrides);
    }

    /**
     * La fila espejo del owner (o null si no hay).
     *
     * @return \App\Models\BusinessHoursConfig|null
     */
    protected function fila_del_owner()
    {
        return BusinessHoursConfig::where('user_id', $this->owner()->id)->first();
    }

    /**
     * Test 1 — el camino feliz: el push del admin queda guardado contra el OWNER de la
     * instancia, no contra el usuario autenticado ni contra cualquiera.
     *
     * @group horarios
     * @test
     */
    public function el_endpoint_guarda_la_semana_para_el_owner_de_la_instancia()
    {
        $owner = $this->owner();

        $this->assertNotNull($owner, 'La instancia tiene que tener un owner (users.owner_id null).');

        $respuesta = $this->putJson(self::RUTA, $this->payload());

        $respuesta->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user_id', $owner->id)
            ->assertJsonPath('configurado', true)
            ->assertJsonPath('dias', 7)
            ->assertJsonPath('timezone', self::TZ);

        $fila = $this->fila_del_owner();

        $this->assertNotNull($fila, 'Tiene que quedar la fila espejo del owner.');
        $this->assertTrue((bool) $fila->configurado, 'El horario llego configurado.');
        $this->assertEquals(self::TZ, $fila->timezone, 'El timezone del payload se guarda tal cual.');
        $this->assertEquals('2026-08-25T10:00:00-03:00', $fila->actualizado_en, 'El ISO del admin se guarda crudo.');
        $this->assertCount(7, $fila->semana, 'La semana se guarda con sus siete dias.');
        $this->assertEquals('Miércoles', $fila->semana[3]['dia_label'], 'Las tildes de dia_label viajan intactas (UTF-8).');
    }

    /**
     * Test 2 — 🔴 IDEMPOTENCIA. El push llega por un job encolado del admin y se puede repetir
     * con el mismo contenido (boton de reintento, guardados sucesivos): dos PUT identicos tienen
     * que dejar UNA sola fila y devolver EXACTAMENTE el mismo cuerpo las dos veces.
     *
     * @group horarios
     * @test
     */
    public function dos_put_identicos_dejan_una_sola_fila()
    {
        $payload = $this->payload();

        $primera  = $this->putJson(self::RUTA, $payload)->assertStatus(200);
        $segunda  = $this->putJson(self::RUTA, $payload)->assertStatus(200);

        $this->assertSame(
            $primera->json(),
            $segunda->json(),
            'El push 1 y el push N con el mismo contenido tienen que devolver el mismo cuerpo.'
        );

        $this->assertSame(
            1,
            BusinessHoursConfig::where('user_id', $this->owner()->id)->count(),
            'Dos pushes identicos no pueden dejar dos filas: el contrato es una semana por comercio.'
        );
    }

    /**
     * Test 3 — idempotencia NO es inmutabilidad: un segundo push con otro contenido pisa el
     * anterior, sin duplicar la fila. Si esto no pasara, un cambio de horario en el admin nunca
     * llegaria al agente.
     *
     * @group horarios
     * @test
     */
    public function un_segundo_put_distinto_pisa_el_contenido()
    {
        $this->putJson(self::RUTA, $this->payload())->assertStatus(200);

        // El comercio cambia el horario: ahora los martes cierra 22:00 y el resto sigue igual.
        $semana    = $this->semana_completa();
        $semana[2] = $this->dia(2, [
            'rangos' => [['desde' => '09:00', 'hasta' => '22:00']],
            'cierre' => '22:00',
        ]);

        $this->putJson(self::RUTA, $this->payload(['semana' => $semana]))->assertStatus(200);

        $this->assertSame(
            1,
            BusinessHoursConfig::where('user_id', $this->owner()->id)->count(),
            'El segundo push pisa la fila, no agrega otra.'
        );

        $fila = $this->fila_del_owner();

        $this->assertEquals('22:00', $fila->semana[2]['cierre'], 'El contenido guardado es el del ultimo push.');
    }

    /**
     * Test 4 — 🔴 EL CASO QUE JUSTIFICA TODO EL CONTRATO: un comercio que todavia no cargo su
     * horario llega con `configurado: false` y `semana: []`. Eso es "NO HAY DATO", jamas
     * "cerrado".
     *
     * Por eso las aserciones son `assertNull` + `assertNotFalse` y no `assertFalse`: la
     * diferencia entre `null` y `false` es el punto entero del contrato. Si `abierto_hoy()`
     * devolviera `false`, el agente le diria a un comprador que el negocio esta cerrado un
     * martes a las 10 de la manana sin tener idea de si es cierto.
     *
     * @group horarios
     * @test
     */
    public function configurado_false_no_se_lee_como_cerrado()
    {
        $respuesta = $this->putJson(self::RUTA, $this->payload([
            'configurado' => false,
            'semana'      => [],
            'dias_crudos' => [],
        ]));

        $respuesta->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('configurado', false)
            ->assertJsonPath('dias', 0);

        $fila = $this->fila_del_owner();

        $this->assertNotNull($fila, 'Un comercio sin horario cargado igual deja su fila espejo.');
        $this->assertFalse((bool) $fila->configurado, 'La bandera `configurado` queda en false.');

        $lector = BusinessHoursReader::for_instance();

        $this->assertFalse($lector->hay_dato(), 'Sin horario cargado no hay dato que leer.');

        $abierto_hoy = $lector->abierto_hoy();

        $this->assertNull($abierto_hoy, 'Sin dato, `abierto_hoy()` es null: no se sabe.');
        $this->assertNotFalse($abierto_hoy, '🔴 "No hay dato" NO puede leerse como "cerrado".');

        $detalle = $lector->cierre_de_hoy_detallado();

        $this->assertEquals('sin_dato', $detalle['motivo'], 'El motivo es "sin_dato", no "cerrado_hoy".');
        $this->assertNull($detalle['abierto'], 'El detalle tampoco colapsa el "no se sabe" a false.');
        $this->assertNull($detalle['cierre'], 'Sin dato no hay hora de cierre que dar.');
    }

    /**
     * Test 5 — compatibilidad hacia atras, las dos mitades:
     *
     *  (a) una clave desconocida de NIVEL 1 (`feriados`, `version_contrato`) se ignora sin error;
     *  (b) una subclave desconocida ADENTRO de un dia de `semana` (`medio_dia`) se GUARDA.
     *
     * Es la diferencia entre "el admin puede agregar campos cuando quiera" y "cada campo nuevo
     * del admin necesita una migracion de este lado". Las dos puntas nunca llegan a produccion
     * al mismo tiempo.
     *
     * @group horarios
     * @test
     */
    public function las_claves_desconocidas_se_ignoran_y_las_subclaves_sobreviven()
    {
        $semana    = $this->semana_completa();
        $semana[3] = $this->dia(3, [
            // Subclave que el admin todavia no manda: tiene que sobrevivir verbatim.
            'medio_dia' => true,
            'nota'      => 'cierra al mediodia por inventario',
        ]);

        $respuesta = $this->putJson(self::RUTA, $this->payload([
            'semana'           => $semana,
            // Claves de nivel 1 que este repo no conoce: se ignoran, no rompen.
            'feriados'         => [['fecha' => '2026-12-25', 'abierto' => false]],
            'version_contrato' => 3,
        ]));

        $respuesta->assertStatus(200)->assertJsonPath('ok', true);

        $fila = $this->fila_del_owner();

        $this->assertArrayHasKey(
            'medio_dia',
            $fila->semana[3],
            'Una subclave nueva adentro de un dia se guarda verbatim, sin migracion.'
        );
        $this->assertTrue($fila->semana[3]['medio_dia'], 'Y con su valor, no normalizada.');
        $this->assertEquals('cierra al mediodia por inventario', $fila->semana[3]['nota']);

        // Las claves de nivel 1 desconocidas no se guardan ni se responden: se ignoran, punto.
        $this->assertArrayNotHasKey('feriados', $respuesta->json(), 'Una clave desconocida de nivel 1 no vuelve en el acuse.');
    }

    /**
     * Test 6 — 🔴 UN PUSH ROTO NO PUEDE BORRAR UN HORARIO BUENO.
     *
     * Un body vacio, mal formado o no-JSON llega a Laravel como input vacio. Sin el guard, ese
     * request escribiria `configurado = false, semana = null` ENCIMA del horario guardado: el
     * comercio perderia el horario, con 200 y sin que nadie se entere.
     *
     * @group horarios
     * @test
     */
    public function un_body_sin_configurado_ni_semana_devuelve_422_y_no_pisa_lo_guardado()
    {
        // Precondicion: hay un horario bueno guardado.
        $this->putJson(self::RUTA, $this->payload())->assertStatus(200);

        $this->assertCount(7, $this->fila_del_owner()->semana);

        // Push roto: trae solo el timezone, ni `configurado` ni `semana`.
        $this->putJson(self::RUTA, ['timezone' => self::TZ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'payload_vacio');

        $fila = $this->fila_del_owner();

        $this->assertTrue((bool) $fila->configurado, 'El horario bueno sigue configurado.');
        $this->assertCount(7, $fila->semana, 'El horario bueno sigue entero: el push roto no lo piso.');
        $this->assertEquals('18:00', $fila->semana[1]['cierre'], 'Y con el mismo contenido de antes.');
    }

    /**
     * Test 6bis — la variante del push roto que ANTES se colaba: la clave esta presente pero
     * su valor es `null`.
     *
     * 🔴 El guard chequeaba `$request->has()`, que es `array_key_exists`: `{"configurado": null}`
     * y `{"semana": null}` lo pasaban, se derivaba `configurado = false, semana = []` y se
     * pisaba el horario bueno devolviendo 200. O sea, exactamente el modo de falla que el guard
     * existe para evitar, entrando por la puerta de al lado. Por eso ahora se mira el VALOR.
     *
     * ⚠️ Y el caso legitimo tiene que seguir pasando: `configurado: false` con `semana: []` es
     * "el comercio todavia no cargo el horario", que es un dato valido y se guarda. Ese lo
     * cubre `configurado_false_no_se_lee_como_cerrado`.
     *
     * @group horarios
     * @test
     */
    public function un_body_con_las_claves_en_null_tampoco_pisa_lo_guardado()
    {
        $this->putJson(self::RUTA, $this->payload())->assertStatus(200);

        $cuerpos_rotos = [
            ['configurado' => null],
            ['semana'      => null],
            ['configurado' => null, 'semana' => null],
        ];

        foreach ($cuerpos_rotos as $cuerpo) {
            $this->putJson(self::RUTA, $cuerpo)
                ->assertStatus(422)
                ->assertJsonPath('error', 'payload_vacio');

            $fila = $this->fila_del_owner();

            $this->assertTrue(
                (bool) $fila->configurado,
                'El horario bueno sigue configurado despues del push con '.json_encode($cuerpo)
            );
            $this->assertCount(
                7,
                $fila->semana,
                'El horario bueno sigue entero despues del push con '.json_encode($cuerpo)
            );
        }
    }

    /**
     * Test 7 — contradiccion del contrato: `configurado: true` con la semana vacia. El emisor
     * nunca la produce; si aparece, es que el emisor cambio y queremos enterarnos por el
     * `schedule_sync_status = failed` del admin, no guardar un estado a medias que despues el
     * lector tenga que adivinar.
     *
     * @group horarios
     * @test
     */
    public function configurado_true_con_semana_vacia_devuelve_422()
    {
        $this->putJson(self::RUTA, $this->payload(['configurado' => true, 'semana' => []]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'semana_vacia_con_configurado_true');

        $this->assertNull($this->fila_del_owner(), 'Un payload contradictorio no escribe nada.');
    }

    /**
     * Test 8 — un `dia_semana` fuera de 0..6 corta con 422. Es de lo poco que se valida: sin un
     * indice de dia usable el item es inutil, y ubicarlo mal seria peor que no tenerlo (el
     * agente contestaria el horario del dia equivocado).
     *
     * @group horarios
     * @test
     */
    public function un_dia_semana_fuera_de_rango_devuelve_422()
    {
        $semana   = $this->semana_completa();
        $semana[] = $this->dia(6, ['dia_semana' => 7]);

        $this->putJson(self::RUTA, $this->payload(['semana' => $semana]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'validacion');

        $this->assertNull($this->fila_del_owner(), 'Un payload que no valida no escribe nada.');
    }

    /**
     * Test 9 — el contrato deja abierto un `user_id` explicito en el body (el emisor de hoy NO
     * lo manda). Cuando viene, manda sobre el owner por defecto.
     *
     * El usuario se crea con `owner_id` cargado a proposito, para que NO pueda ser el owner de
     * la instancia y el test mida lo que dice medir.
     *
     * @group horarios
     * @test
     */
    public function un_user_id_explicito_manda_sobre_el_owner_por_defecto()
    {
        $owner = $this->owner();

        $empleado = User::create([
            'name'         => 'Empleado de horarios',
            'company_name' => 'Ferreteria horarios',
            'email'        => 'horarios-empleado-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $owner->id,
        ]);

        $this->putJson(self::RUTA, $this->payload(['user_id' => $empleado->id]))
            ->assertStatus(200)
            ->assertJsonPath('user_id', $empleado->id);

        $this->assertNotNull(
            BusinessHoursConfig::where('user_id', $empleado->id)->first(),
            'El horario se guarda contra el user_id explicito del body.'
        );

        $this->assertNull(
            BusinessHoursConfig::where('user_id', $owner->id)->first(),
            'Y no contra el owner por defecto.'
        );
    }

    /**
     * Test 10 — la ruta vive adentro del grupo `admin.api.key`, igual que el resto de AdminSync.
     * Con el flag prendido: sin header es 401, con el header correcto es 200.
     *
     * Sin este test, la ruta podria quedar publicada afuera del grupo del middleware y nadie se
     * enteraria hasta que alguien de afuera pisara el horario de un comercio.
     *
     * @group horarios
     * @test
     */
    public function la_ruta_esta_bajo_admin_api_key()
    {
        config([
            'services.admin_api.require_api_key' => true,
            'services.admin_api.api_key'         => 'k',
        ]);

        $this->putJson(self::RUTA, $this->payload())
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');

        $this->assertNull($this->fila_del_owner(), 'Un request sin credencial no escribe nada.');

        $this->putJson(self::RUTA, $this->payload(), ['X-Admin-Api-Key' => 'k'])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    /**
     * Test 11 — el esquema: la tabla espejo existe con sus columnas, y el `unique(user_id)` esta
     * puesto de verdad en el motor. Ese unique es el respaldo de la idempotencia del
     * `updateOrCreate`: si dos pushes entraran en paralelo, es lo unico que impide dos horarios
     * para el mismo comercio.
     *
     * @group horarios
     * @test
     */
    public function el_esquema_tiene_la_tabla_y_el_unique_por_user_id()
    {
        $this->assertTrue(Schema::hasTable('business_hours_configs'), 'Falta la tabla espejo.');

        $columnas = ['user_id', 'timezone', 'actualizado_en', 'configurado', 'semana', 'dias_crudos', 'recibido_at'];

        foreach ($columnas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('business_hours_configs', $columna),
                'Falta la columna "'.$columna.'" de business_hours_configs.'
            );
        }

        $indices_unicos = DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'business_hours_configs')
            ->where('column_name', 'user_id')
            ->where('non_unique', 0)
            ->count();

        $this->assertGreaterThan(
            0,
            $indices_unicos,
            'business_hours_configs.user_id tiene que tener un indice UNIQUE: es el respaldo del motor a la idempotencia.'
        );

        // Y que el motor lo haga cumplir de verdad, no solo que el indice figure declarado.
        $owner = $this->owner();

        BusinessHoursConfig::create([
            'user_id'     => $owner->id,
            'configurado' => false,
            'semana'      => [],
            'dias_crudos' => [],
        ]);

        $rechazo = false;

        try {
            BusinessHoursConfig::create([
                'user_id'     => $owner->id,
                'configurado' => false,
                'semana'      => [],
                'dias_crudos' => [],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $rechazo = true;
        }

        $this->assertTrue($rechazo, 'El motor tiene que rechazar una segunda fila para el mismo owner.');
    }
}
