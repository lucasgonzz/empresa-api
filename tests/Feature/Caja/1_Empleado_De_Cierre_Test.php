<?php

namespace Tests\Feature\Caja;

use App\Exports\AperturaCajaExport;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Prompt 611 — el arqueo tiene que guardar quién cerró la caja.
 *
 * Hasta agosto de 2026 `CajaCierreHelper::cerrar_apertura()` escribía `apertura_employee_id` al
 * cerrar. El daño era doble: `cierre_employee_id` quedaba siempre en null (nunca se supo quién
 * cerró una caja) y además se pisaba el id de quien la había abierto. La columna, la relación
 * `usuario_cierre()`, la línea "Cerró X" del modal y la fila "Empleado Cierre" del Excel ya
 * existían desde septiembre de 2024: lo único que faltaba era escribir en la columna correcta.
 *
 * Los cinco tests de acá abajo fallan los cinco si se revierte esa línea.
 *
 * @group caja
 */
class Empleado_De_Cierre_Test extends EmpresaTestCase
{
    /**
     * Saldo con el que arranca la caja en cada test. Distinto de cero a propósito: ver el
     * comentario del `update()` de `setUp()`.
     */
    const SALDO_DE_LA_CAJA = 4321.55;

    /**
     * Dueño de la cuenta del fixture de testing.
     *
     * @var \App\Models\User
     */
    protected $owner;

    /**
     * Empleado que abre la caja en los tests.
     *
     * @var \App\Models\User
     */
    protected $empleado_apertura;

    /**
     * Empleado que la cierra. Es otra persona a propósito: es la única forma de ver el defecto.
     *
     * @var \App\Models\User
     */
    protected $empleado_cierre;

    /**
     * Caja del fixture sobre la que corre todo el archivo.
     *
     * @var \App\Models\Caja
     */
    protected $caja;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $sufijo = uniqid();

        /*
            El fixture de testing NO siembra empleados: siembra un solo usuario dueño. Los dos
            empleados se crean acá, colgados del owner con `owner_id`. El sufijo del email evita
            chocar con lo que haya dejado una corrida anterior.
        */
        $this->empleado_apertura = User::create([
            'name'         => 'Empleado Apertura Caja',
            'company_name' => 'Ferreteria arqueo',
            'email'        => 'caja-apertura-'.$sufijo.'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner->id,
        ]);

        $this->empleado_cierre = User::create([
            'name'         => 'Empleado Cierre Caja',
            'company_name' => 'Ferreteria arqueo',
            'email'        => 'caja-cierre-'.$sufijo.'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner->id,
        ]);

        $this->caja = Caja::where('name', TestingFerreteriaSeeder::CAJA_EFECTIVO)
                        ->where('user_id', $this->owner->id)
                        ->first();

        if (is_null($this->caja)) {
            $this->fail(
                'No existe la caja "'.TestingFerreteriaSeeder::CAJA_EFECTIVO.'" del fixture de '.
                'testing. Sembrá la base del slot antes de correr esta suite.'
            );
        }

        /*
            Estado determinístico: la caja arranca cerrada y sin apertura en curso, pase lo que
            pase en la base del slot. Es escritura directa y no por endpoint a propósito: es
            infraestructura del test, no el comportamiento bajo prueba. `DatabaseTransactions`
            lo revierte al terminar.
        */
        Caja::where('id', $this->caja->id)->update([
            'abierta'                  => 0,
            'abierta_at'               => null,
            'cerrada_at'               => null,
            'current_apertura_caja_id' => null,
            /*
                🔴 El saldo se fuerza a un valor distinto de cero a propósito. La caja del
                fixture arranca en 0.00, y con ese valor la aserción de `saldo_cierre` del
                segundo test pasaba aunque se dejara de guardar el saldo: `(float) null` es
                `0.0` y comparaba igual. Lo encontró el chequeo independiente comentando la
                línea del helper y viendo que el test seguía en verde.
            */
            'saldo'                    => self::SALDO_DE_LA_CAJA,
        ]);

        $this->caja->refresh();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::forgetGuards();

        parent::tearDown();
    }

    /**
     * Abrir con un empleado y cerrar con otro deja los dos ids, cada uno en su columna y
     * distintos entre sí. Es el test que prueba el defecto: con la línea vieja,
     * `apertura_employee_id` terminaba con el id del que cerró y `cierre_employee_id` en null.
     *
     * @group caja
     * @test
     * @return void
     */
    public function abrir_y_cerrar_con_dos_empleados_distintos_deja_los_dos_ids_correctos()
    {
        $apertura_id = $this->abrir_como($this->empleado_apertura);

        $this->cerrar_como($this->empleado_cierre);

        $apertura = AperturaCaja::find($apertura_id);

        $this->assertNotNull($apertura, 'La apertura creada por el test desapareció después de cerrar.');

        $this->assertEquals(
            $this->empleado_apertura->id,
            $apertura->apertura_employee_id,
            'El cierre pisó el id de quien abrió la caja.'
        );

        $this->assertEquals(
            $this->empleado_cierre->id,
            $apertura->cierre_employee_id,
            'No se guardó el empleado que cerró la caja.'
        );

        $this->assertNotEquals(
            $apertura->apertura_employee_id,
            $apertura->cierre_employee_id,
            'Abrió y cerró gente distinta, pero las dos columnas quedaron con el mismo id.'
        );

        // Sin esto se estaría asertando sobre una apertura que en realidad nunca se cerró.
        $this->assertNotNull($apertura->cerrada_at, 'La apertura quedó sin cerrada_at.');
        $this->assertEquals(0, (int) $this->caja->abierta, 'La caja quedó abierta después de cerrarla.');
    }

    /**
     * El caso "una sola persona atendió todo el día": las dos columnas quedan llenas y con el
     * mismo id, que es el dato correcto y no un descuadre. El resto del cierre (fecha y saldo)
     * sigue funcionando igual.
     *
     * @group caja
     * @test
     * @return void
     */
    public function abrir_y_cerrar_con_el_mismo_empleado_deja_los_dos_ids_iguales()
    {
        $apertura_id = $this->abrir_como($this->empleado_apertura);

        $this->cerrar_como($this->empleado_apertura);

        $apertura = AperturaCaja::find($apertura_id);

        $this->assertEquals($this->empleado_apertura->id, $apertura->apertura_employee_id);
        $this->assertEquals($this->empleado_apertura->id, $apertura->cierre_employee_id);

        $this->assertEquals(
            $apertura->apertura_employee_id,
            $apertura->cierre_employee_id,
            'Abrió y cerró la misma persona, pero las columnas quedaron con ids distintos.'
        );

        $this->assertNotNull($apertura->cerrada_at);
        $this->assertEquals(0, (int) $this->caja->abierta);

        // El assertNotNull va PRIMERO: sin él, un saldo_cierre en null pasaría la igualdad de
        // abajo si la caja valiera 0, que es exactamente el agujero que tenía este test.
        $this->assertNotNull($apertura->saldo_cierre, 'El saldo de cierre dejó de guardarse.');
        $this->assertEquals(
            self::SALDO_DE_LA_CAJA,
            (float) $apertura->saldo_cierre,
            'El saldo de cierre no es el que tenía la caja.'
        );
    }

    /**
     * La línea "Abrió X / Cerró Y" del modal, traducida a lo que el back puede garantizar.
     *
     * `InfoAperturaCaja.vue` NO lee ninguna relación del back: lee `apertura_employee_id` y
     * `cierre_employee_id` de la fila y resuelve el nombre contra su propio store, con
     * `get_perfil_usuario()` (`src/mixins/generals.js`). Así que lo único que el back tiene que
     * garantizar —y lo único testeable sin Playwright, que en esta misión no se corre— es que
     * los dos ids viajen en la respuesta de los dos endpoints que alimentan al modal.
     *
     * @group caja
     * @test
     * @return void
     */
    public function el_endpoint_que_alimenta_el_modal_devuelve_los_dos_empleados()
    {
        $apertura_id = $this->abrir_como($this->empleado_apertura);

        $this->cerrar_como($this->empleado_cierre);

        $this->actuar_como($this->owner);

        // a) Listado del modal "Aperturas": lo que carga BtnAperturas antes de abrirlo.
        $response = $this->getJson('api/apertura-caja/'.$this->caja->id);

        $response->assertStatus(200);

        /*
            Se busca la fila POR ID y no por posición: la base del slot tiene aperturas de otras
            suites, así que cualquier aserción sobre "la primera" sería falsa apenas alguien sume
            un test en otra carpeta.
        */
        $fila = null;

        foreach ($response->json('models') as $model) {
            if ((int) $model['id'] === (int) $apertura_id) {
                $fila = $model;
                break;
            }
        }

        $this->assertNotNull($fila, 'La apertura del test no vino en GET api/apertura-caja/{caja_id}.');

        // Si alguien agrega un $hidden al modelo y deja de mandar los ids, estos dos lo cazan.
        $this->assertArrayHasKey('apertura_employee_id', $fila);
        $this->assertArrayHasKey('cierre_employee_id', $fila);

        $this->assertEquals($this->empleado_apertura->id, $fila['apertura_employee_id']);
        $this->assertEquals($this->empleado_cierre->id, $fila['cierre_employee_id']);

        // b) La apertura individual, que es la que pide BtnMovimientos.vue para el encabezado.
        $show = $this->getJson('api/apertura-caja/show/'.$apertura_id);

        $show->assertStatus(200);

        $this->assertEquals($this->empleado_apertura->id, $show->json('model.apertura_employee_id'));
        $this->assertEquals($this->empleado_cierre->id, $show->json('model.cierre_employee_id'));
    }

    /**
     * Reabrir una apertura ya cerrada y volver a cerrarla con un TERCER empleado deja al que
     * abrió intacto y al último que cerró en su columna.
     *
     * Es el escenario donde el defecto viejo hacía más daño: cada cierre volvía a pisar
     * `apertura_employee_id`, así que reabrir y cerrar de nuevo terminaba de borrar cualquier
     * rastro del que había abierto. Y es el camino que el comentario de `cerrar_apertura()` usa
     * como argumento de por qué las dos columnas no son intercambiables, así que corresponde que
     * tenga test propio y no solo una afirmación en prosa.
     *
     * @group caja
     * @test
     * @return void
     */
    public function reabrir_y_cerrar_de_nuevo_no_pisa_al_que_abrio_y_guarda_al_ultimo_que_cerro()
    {
        $tercer_empleado = User::create([
            'name'         => 'Tercer Empleado Caja',
            'company_name' => 'Ferreteria arqueo',
            'email'        => 'caja-tercero-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner->id,
        ]);

        $apertura_id = $this->abrir_como($this->empleado_apertura);

        $this->cerrar_como($this->empleado_cierre);

        // Reabrir tiene que limpiar SOLO el cierre y dejar la apertura como estaba.
        $this->actuar_como($this->owner);

        $this->postJson('api/apertura-caja/reabrir/'.$apertura_id)->assertStatus(200);

        $apertura = AperturaCaja::find($apertura_id);

        $this->assertEquals(
            $this->empleado_apertura->id,
            $apertura->apertura_employee_id,
            'Reabrir borró al empleado que había abierto la caja.'
        );
        $this->assertNull($apertura->cierre_employee_id, 'Reabrir no limpió el empleado de cierre.');

        // Y ahora la cierra una tercera persona.
        $this->caja->refresh();

        $this->cerrar_como($tercer_empleado);

        $apertura = AperturaCaja::find($apertura_id);

        $this->assertEquals(
            $this->empleado_apertura->id,
            $apertura->apertura_employee_id,
            'El segundo cierre volvió a pisar al empleado que abrió la caja.'
        );
        $this->assertEquals(
            $tercer_empleado->id,
            $apertura->cierre_employee_id,
            'El segundo cierre no guardó al empleado que cerró.'
        );
    }

    /**
     * El Excel de arqueo trae la fila "Empleado Cierre" llena.
     *
     * Se testea el `map()` de la hoja de encabezado y no el xlsx binario: el contenido de esa
     * fila sale enteramente de ahí, y PhpSpreadsheet después solo serializa el array. Tampoco se
     * pega a `apertura-caja/excel/export/{id}`: esa ruta solo envuelve el mismo export en
     * `Excel::download()`, no agrega ninguna señal sobre la columna y sí agrega una dependencia
     * frágil (headers, nombre de archivo, filesystem).
     *
     * La hoja se toma de `sheets()` y no se instancia directo porque `AperturaCajaHeaderSheet`
     * está declarada dentro del mismo archivo que `AperturaCajaExport`: el autoloader PSR-4 no
     * la encuentra por nombre.
     *
     * @group caja
     * @test
     * @return void
     */
    public function el_excel_de_arqueo_trae_la_fila_empleado_cierre_con_el_nombre_de_quien_cerro()
    {
        $apertura_id = $this->abrir_como($this->empleado_apertura);

        $this->cerrar_como($this->empleado_cierre);

        $export = new AperturaCajaExport($apertura_id);

        $sheets = $export->sheets();

        $hoja_general = $sheets[0];

        // Si alguien invierte el orden de las hojas, el test lo dice en vez de fallar confuso.
        $this->assertEquals('General', $hoja_general->title());

        $apertura = $hoja_general->collection()->first();

        $valores = [];

        foreach ($hoja_general->map($apertura) as $fila) {
            $valores[$fila[0]] = $fila[1];
        }

        $this->assertArrayHasKey('Empleado Cierre', $valores);

        $this->assertEquals('Empleado Cierre Caja', $valores['Empleado Cierre']);

        /*
            Deja escrito el síntoma exacto del defecto: con la línea vieja, `usuario_cierre` era
            null en TODOS los arqueos y el `?? 'S/A'` del export imprimía "S/A" siempre.
        */
        $this->assertNotEquals('S/A', $valores['Empleado Cierre']);

        // El que abrió tampoco fue pisado en el Excel.
        $this->assertEquals('Empleado Apertura Caja', $valores['Empleado Apertura']);
    }

    /**
     * Cambia el usuario autenticado para las requests que siguen.
     *
     * 🔴 El `Auth::forgetGuards()` no es decorativo: las rutas de caja están bajo `auth:sanctum`,
     * y el guard de sanctum es un `RequestGuard` que CACHEA el usuario que resolvió la primera
     * vez y vive en el mismo container durante todo el test. Sin olvidarlo, el segundo
     * `actingAs()` escribe en el guard `web` pero la request sigue resolviendo al primero: los
     * dos empleados quedarían siendo el mismo y este archivo entero daría verde en falso, con el
     * defecto puesto. Es el mismo hallazgo que ya documentan los tests de Alertas.
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Abre la caja por el endpoint real, con el usuario indicado, y devuelve el id de la
     * apertura recién creada.
     *
     * @param \App\Models\User $user
     * @return int
     */
    protected function abrir_como($user)
    {
        $this->actuar_como($user);

        $response = $this->putJson('api/abrir-caja/'.$this->caja->id);

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'PUT api/abrir-caja devolvió '.$response->getStatusCode().': '.$response->getContent()
        );

        $this->caja->refresh();

        $this->assertNotNull(
            $this->caja->current_apertura_caja_id,
            'Después de abrir, la caja quedó sin current_apertura_caja_id.'
        );

        return $this->caja->current_apertura_caja_id;
    }

    /**
     * Cierra la caja por el endpoint real, con el usuario indicado.
     *
     * 🔴 El id de la apertura hay que haberlo capturado ANTES de llamar acá:
     * `CajaCierreHelper::marcar_cerrada()` pone `current_apertura_caja_id` en null.
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function cerrar_como($user)
    {
        $this->actuar_como($user);

        $response = $this->putJson('api/cerrar-caja/'.$this->caja->id);

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'PUT api/cerrar-caja devolvió '.$response->getStatusCode().': '.$response->getContent()
        );

        $this->caja->refresh();
    }
}
