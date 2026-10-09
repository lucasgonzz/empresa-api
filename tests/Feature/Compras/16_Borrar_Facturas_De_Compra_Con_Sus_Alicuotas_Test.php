<?php

namespace Tests\Feature\Compras;

use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderExtraCost;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Misión `factura-compra-tres-defectos` (9/10/2026), Defecto 1 — las facturas de compra se borran
 * CON sus alícuotas, y borrar la compra borra sus facturas.
 *
 * Lo que cubre:
 *
 *   1. Borrar una compra (`DELETE api/provider-order/{id}`) borra sus facturas y sus alícuotas —la
 *      principal, una cargada a mano y el comprobante aparte de un costo extra— y NO toca las de
 *      otra compra. Hasta esta misión la compra desaparecía y sus facturas quedaban vivas: la
 *      Posición Fiscal seguía sumándolas (eso lo mide `Reportes\Posicion_Fiscal_Sin_Facturas_Huerfanas_Test`).
 *   2. Pasar la compra a "sin factura" borra las facturas CON sus alícuotas (antes, solo las facturas).
 *   3. Borrar una factura (`DELETE api/provider-order-afip-ticket/{id}`) borra sus alícuotas.
 *   4. El comando `facturas-de-compra:huerfanas`: sin `--aplicar` lista y no escribe; con
 *      `--aplicar` borra las huérfanas viejas, y no toca ni las de otro dueño, ni las recientes, ni
 *      las que tienen compra.
 *
 * Mismo escenario que `Factura_De_Compra_Total_Y_Percepciones_Test`: proveedor Rosario, sin tocar
 * precios ni stock, sin cuenta corriente. Todo lo que se crea se borra en el `tearDown()` además del
 * rollback de `DatabaseTransactions` (ver el docblock de `ComprasTestCase`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group compras
 */
class Borrar_Facturas_De_Compra_Con_Sus_Alicuotas_Test extends ComprasTestCase
{
    /** @var array<int,int> Compras creadas por el test, para la limpieza. */
    protected $compras_creadas = [];

    /** @var array<int,int> Facturas sembradas a mano (huérfanas), para la limpieza. */
    protected $facturas_sembradas = [];

    /** @var array<int,int> Alícuotas sembradas a mano (huérfanas), para la limpieza. */
    protected $alicuotas_sembradas = [];

    /* ------------------------------------------------------------------ */
    /* Helpers del escenario                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Crea una compra por el endpoint real y la registra para la limpieza.
     *
     * @param  array<string,mixed> $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra($overrides = [])
    {
        $payload = $this->payload_compra(array_merge([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'total_with_iva'          => 0,
            'generate_current_acount' => 0,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ], $overrides));

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $compra_id = $response->json('model.id');

        $this->compras_creadas[] = $compra_id;

        return ProviderOrder::find($compra_id);
    }

    /**
     * Crea una factura colgada de una compra, con una alícuota de 21%, por los endpoints reales.
     *
     * En una compra AUTOMÁTICA el endpoint de la alícuota contesta 422 (las alícuotas las calcula
     * el sistema), así que ahí la alícuota se siembra directo en la tabla: es el setup del borrado,
     * no lo que se está probando, y es exactamente lo que deja una compra que fue manual y después
     * se pasó a automática.
     *
     * @param  int   $provider_order_id
     * @param  bool  $alicuota_por_endpoint
     * @return \App\Models\ProviderOrderAfipTicket
     */
    protected function crear_factura_con_alicuota($provider_order_id, $alicuota_por_endpoint = true)
    {
        $response = $this->postJson('api/provider-order-afip-ticket', [
            'model_id'        => $provider_order_id,
            'code'            => '0001-00000001',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => 300,
            'percepcion_iva'  => 200,
        ]);

        $response->assertStatus(201);

        $factura_id = $response->json('model.id');

        $alicuota = [
            'iva_id'      => $this->iva('21')->id,
            'neto'        => 1000,
            'iva_importe' => 210,
        ];

        if ($alicuota_por_endpoint) {
            $this->postJson('api/provider-order-afip-ticket-iva', array_merge(['model_id' => $factura_id], $alicuota))
                 ->assertStatus(201);
        } else {
            ProviderOrderAfipTicketIva::create(array_merge(['provider_order_afip_ticket_id' => $factura_id], $alicuota));
        }

        return ProviderOrderAfipTicket::find($factura_id);
    }

    /**
     * @param  string $porcentaje
     * @return \App\Models\Iva
     */
    protected function iva($porcentaje)
    {
        $iva = Iva::where('percentage', $porcentaje)->first();

        $this->assertNotNull($iva, 'La base de testing tiene que tener sembrada la alícuota "'.$porcentaje.'".');

        return $iva;
    }

    /**
     * Ids de las facturas (de cualquier clase) que cuelgan de una compra.
     *
     * @param  int $provider_order_id
     * @return array<int,int>
     */
    protected function ids_de_facturas_de($provider_order_id)
    {
        return ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)->pluck('id')->all();
    }

    /**
     * Cuántas alícuotas cuelgan de esas facturas.
     *
     * @param  array<int,int> $ids_de_facturas
     * @return int
     */
    protected function alicuotas_de(array $ids_de_facturas)
    {
        if (count($ids_de_facturas) === 0) {
            return 0;
        }

        return ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids_de_facturas)->count();
    }

    /**
     * Siembra una factura huérfana directo en la tabla: así quedaron las que dejaba el código viejo,
     * y no hay endpoint que las produzca hoy.
     *
     * @param  int          $user_id
     * @param  int|null     $provider_order_id
     * @param  \Carbon\Carbon $creada
     * @return \App\Models\ProviderOrderAfipTicket
     */
    protected function sembrar_factura($user_id, $provider_order_id, $creada)
    {
        $factura = ProviderOrderAfipTicket::create([
            'provider_order_id' => $provider_order_id,
            'user_id'           => $user_id,
            'issued_at'         => '2026-09-17 00:00:00',
            'total_iva'         => 100,
            'total'             => 600,
            'percepcion_iva'    => 10,
            'percepcion_iibb'   => 20,
        ]);

        // `created_at` se pisa por afuera de Eloquent: es la antigüedad lo que se está probando.
        DB::table('provider_order_afip_tickets')->where('id', $factura->id)->update(['created_at' => $creada]);

        $this->facturas_sembradas[] = $factura->id;

        return $factura;
    }

    /**
     * Siembra una alícuota colgada de una factura (que puede no existir) o sin factura.
     *
     * @param  int|null     $provider_order_afip_ticket_id
     * @param  \Carbon\Carbon $creada
     * @return \App\Models\ProviderOrderAfipTicketIva
     */
    protected function sembrar_alicuota($provider_order_afip_ticket_id, $creada)
    {
        $alicuota = ProviderOrderAfipTicketIva::create([
            'provider_order_afip_ticket_id' => $provider_order_afip_ticket_id,
            'iva_id'                        => $this->iva('21')->id,
            'neto'                          => 100,
            'iva_importe'                   => 21,
        ]);

        DB::table('provider_order_afip_ticket_ivas')->where('id', $alicuota->id)->update(['created_at' => $creada]);

        $this->alicuotas_sembradas[] = $alicuota->id;

        return $alicuota;
    }

    /**
     * Un id de compra que seguro no existe: el máximo actual más un margen.
     *
     * @return int
     */
    protected function id_de_compra_inexistente()
    {
        return (int) ProviderOrder::max('id') + 100000;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            $ids = ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->pluck('id')->all();

            ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->delete();
            ProviderOrderAfipTicket::whereIn('id', $ids)->delete();
            ProviderOrderExtraCost::where('provider_order_id', $compra_id)->delete();
            CurrentAcount::where('provider_order_id', $compra_id)->delete();
            ProviderOrder::where('id', $compra_id)->delete();
        }

        ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $this->facturas_sembradas)->delete();
        ProviderOrderAfipTicket::whereIn('id', $this->facturas_sembradas)->delete();
        ProviderOrderAfipTicketIva::whereIn('id', $this->alicuotas_sembradas)->delete();

        $this->compras_creadas     = [];
        $this->facturas_sembradas  = [];
        $this->alicuotas_sembradas = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Las bajas                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Test 1 — Borrar una compra borra sus facturas y sus alícuotas: la factura principal del modo
     * automático, una factura cargada a mano y el comprobante aparte de un costo extra. Las de OTRA
     * compra quedan intactas.
     *
     * @group compras
     * @test
     */
    public function borrar_la_compra_borra_sus_facturas_con_sus_alicuotas_y_no_las_de_otra()
    {
        $this->set_condicion_iva('RRII');

        // La compra que se va a borrar: automática (factura principal con desglose) ...
        $compra = $this->crear_compra(['modo_facturacion' => 'automatico']);

        // ... con un costo extra facturado aparte, que genera su propio comprobante con alícuota al
        // volver a guardar la compra (mismo camino que `Iva\Costo_Extra_Factura_Aparte_Test`).
        ProviderOrderExtraCost::create([
            'provider_order_id'   => $compra->id,
            'description'         => 'Flete tercerizado',
            'value'               => 605,
            'tipo'                => ProviderOrderExtraCost::TIPO_TRANSPORTE,
            'facturado'           => true,
            'en_factura_compra'   => false,
            'iva_id'              => $this->iva('21')->id,
            'emisor_razon_social' => 'Fletes SRL',
            'emisor_cuit'         => '30-99999999-1',
        ]);

        $this->putJson('api/provider-order/'.$compra->id, $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'automatico',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'total_with_iva'          => 0,
            'generate_current_acount' => 0,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ]))->assertStatus(200);

        // ... y una factura más cargada a mano (el endpoint de la factura no mira el modo; su
        // alícuota sí, por eso va sembrada).
        $this->crear_factura_con_alicuota($compra->id, false);

        $ids_de_la_compra = $this->ids_de_facturas_de($compra->id);

        $this->assertCount(3, $ids_de_la_compra, 'El escenario tiene que dejar tres facturas: la automática, la aparte del costo extra y la cargada a mano.');
        $this->assertGreaterThanOrEqual(3, $this->alicuotas_de($ids_de_la_compra), 'Cada una de las tres facturas tiene que tener al menos una alícuota.');

        // La otra compra, que no se toca.
        $otra = $this->crear_compra();
        $factura_de_la_otra = $this->crear_factura_con_alicuota($otra->id);

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

        $this->assertNull(ProviderOrder::find($compra->id), 'La compra tiene que haberse borrado.');

        $this->assertSame(
            0,
            ProviderOrderAfipTicket::whereIn('id', $ids_de_la_compra)->count(),
            'Borrar la compra tiene que borrar TODAS sus facturas: la automática, la aparte y la manual.'
        );

        $this->assertSame(
            0,
            $this->alicuotas_de($ids_de_la_compra),
            'Y las alícuotas de esas facturas: sin clave foránea, nadie más las iba a borrar.'
        );

        $this->assertNotNull(ProviderOrderAfipTicket::find($factura_de_la_otra->id), 'La factura de la otra compra no se toca.');
        $this->assertSame(1, $this->alicuotas_de([$factura_de_la_otra->id]), 'Ni su alícuota.');
    }

    /**
     * Test 2 — Pasar la compra a "sin factura" borra sus facturas CON sus alícuotas. Antes
     * `check_modo_facturacion()` borraba las facturas y dejaba el desglose colgando.
     *
     * @group compras
     * @test
     */
    public function pasar_la_compra_a_sin_factura_borra_las_facturas_con_sus_alicuotas()
    {
        $compra  = $this->crear_compra();
        $factura = $this->crear_factura_con_alicuota($compra->id);

        $this->assertSame(1, $this->alicuotas_de([$factura->id]));

        $this->putJson('api/provider-order/'.$compra->id, $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'sin factura',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'total_with_iva'          => 0,
            'generate_current_acount' => 0,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ]))->assertStatus(200);

        $this->assertNull(ProviderOrderAfipTicket::find($factura->id), 'En "sin factura" la factura se borra.');
        $this->assertSame(0, $this->alicuotas_de([$factura->id]), 'Y su alícuota también.');
    }

    /**
     * Test 3 — Borrar una factura desde su endpoint borra sus alícuotas.
     *
     * @group compras
     * @test
     */
    public function borrar_una_factura_borra_sus_alicuotas()
    {
        $compra  = $this->crear_compra();
        $factura = $this->crear_factura_con_alicuota($compra->id);

        $this->deleteJson('api/provider-order-afip-ticket/'.$factura->id)->assertStatus(200);

        $this->assertNull(ProviderOrderAfipTicket::find($factura->id));
        $this->assertSame(0, $this->alicuotas_de([$factura->id]), 'La alícuota no puede quedar colgando de una factura borrada.');
    }

    /* ------------------------------------------------------------------ */
    /* El comando                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Arma las huérfanas y las no huérfanas del escenario del comando. Devuelve los modelos por
     * nombre, para que cada test mire lo que le toca.
     *
     * @return array<string,mixed>
     */
    protected function escenario_del_comando()
    {
        $dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // Otro dueño de la misma base (owner_id NULL), con su propia factura huérfana.
        $otro_dueno = User::create([
            'name'     => 'Otro comercio facturas huerfanas',
            'email'    => 'facturas-huerfanas-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $vieja    = Carbon::now()->subDays(2);
        $reciente = Carbon::now()->subHours(2);

        $compra = $this->crear_compra();

        $escenario = [
            'otro_dueno'               => $otro_dueno,
            // Huérfanas del dueño: compra borrada (con su alícuota) y sin compra vieja.
            'compra_borrada'           => $this->sembrar_factura($dueno->id, $this->id_de_compra_inexistente(), $vieja),
            'sin_compra_vieja'         => $this->sembrar_factura($dueno->id, null, $vieja),
            // No huérfanas: sin compra pero reciente (alguien está cargando la compra ahora), y
            // una con compra que existe.
            'sin_compra_reciente'      => $this->sembrar_factura($dueno->id, null, $reciente),
            'con_compra'               => $this->sembrar_factura($dueno->id, $compra->id, $vieja),
            // Huérfana, pero de otro dueño.
            'de_otro_dueno'            => $this->sembrar_factura($otro_dueno->id, $this->id_de_compra_inexistente(), $vieja),
        ];

        $escenario['alicuota_de_compra_borrada'] = $this->sembrar_alicuota($escenario['compra_borrada']->id, $vieja);
        $escenario['alicuota_de_con_compra']     = $this->sembrar_alicuota($escenario['con_compra']->id, $vieja);

        // Alícuotas huérfanas sin dueño: factura inexistente y sin factura vieja; y una sin factura
        // reciente, que no se toca.
        $id_de_factura_inexistente = (int) ProviderOrderAfipTicket::max('id') + 100000;

        $escenario['alicuota_factura_inexistente'] = $this->sembrar_alicuota($id_de_factura_inexistente, $vieja);
        $escenario['alicuota_sin_factura_vieja']   = $this->sembrar_alicuota(null, $vieja);
        $escenario['alicuota_sin_factura_reciente'] = $this->sembrar_alicuota(null, $reciente);

        return $escenario;
    }

    /**
     * Test 4 — Sin `--aplicar`, el comando lista las huérfanas del dueño y no borra nada.
     *
     * @group compras
     * @test
     */
    public function el_comando_sin_aplicar_lista_y_no_escribe_nada()
    {
        $escenario = $this->escenario_del_comando();

        $facturas_antes  = ProviderOrderAfipTicket::count();
        $alicuotas_antes = ProviderOrderAfipTicketIva::count();

        $codigo = Artisan::call('facturas-de-compra:huerfanas');
        $salida = Artisan::output();

        $this->assertSame(0, $codigo);

        $this->assertSame($facturas_antes, ProviderOrderAfipTicket::count(), 'Sin --aplicar no se borra ninguna factura.');
        $this->assertSame($alicuotas_antes, ProviderOrderAfipTicketIva::count(), 'Sin --aplicar no se borra ninguna alícuota.');

        $this->assertStringContainsString('Solo lectura', $salida);
        $this->assertStringContainsString('Otro comercio facturas huerfanas', $salida, 'El otro dueño tiene su huérfana y tiene que aparecer en el listado.');
        $this->assertStringContainsString('No se borró nada', $salida);

        // La fila del dueño del fixture: 1 por compra borrada, 1 sin compra (la reciente no
        // cuenta), 1 alícuota arrastrada, y 200 de IVA crédito (dos facturas de 100).
        $this->assertMatchesRegularExpression('/\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*\$200,00\s*\|/', $salida, 'La fila del dueño tiene que contar 1 + 1 facturas, 1 alícuota y $200 de IVA crédito. Salida: '.$salida);

        $this->assertNotNull(ProviderOrderAfipTicket::find($escenario['compra_borrada']->id));
    }

    /**
     * Test 5 — Con `--aplicar` borra las huérfanas viejas y sus alícuotas, y deja todo lo demás: la
     * factura reciente sin compra, la que tiene compra (con su alícuota) y la alícuota reciente sin
     * factura. La huérfana del otro dueño también se borra (es SU huérfana), pero en su propia
     * pasada: lo que se prueba es que cada dueño ve y borra lo suyo, y que lo que no es huérfano no
     * se toca de ningún dueño.
     *
     * @group compras
     * @test
     */
    public function el_comando_con_aplicar_borra_solo_las_huerfanas_viejas()
    {
        $escenario = $this->escenario_del_comando();

        $codigo = Artisan::call('facturas-de-compra:huerfanas', ['--aplicar' => true]);

        $this->assertSame(0, $codigo, Artisan::output());

        // Borradas.
        $this->assertNull(ProviderOrderAfipTicket::find($escenario['compra_borrada']->id), 'La factura de una compra inexistente es huérfana.');
        $this->assertNull(ProviderOrderAfipTicket::find($escenario['sin_compra_vieja']->id), 'La factura sin compra de hace dos días es huérfana.');
        $this->assertNull(ProviderOrderAfipTicketIva::find($escenario['alicuota_de_compra_borrada']->id), 'La alícuota de una factura huérfana se va con ella.');
        $this->assertNull(ProviderOrderAfipTicketIva::find($escenario['alicuota_factura_inexistente']->id), 'La alícuota de una factura inexistente es huérfana.');
        $this->assertNull(ProviderOrderAfipTicketIva::find($escenario['alicuota_sin_factura_vieja']->id), 'La alícuota sin factura de hace dos días es huérfana.');
        $this->assertNull(ProviderOrderAfipTicket::find($escenario['de_otro_dueno']->id), 'El otro dueño también es un dueño: su huérfana se borra en su pasada.');

        // Intactas.
        $this->assertNotNull(ProviderOrderAfipTicket::find($escenario['sin_compra_reciente']->id), 'Una factura sin compra de hace dos horas puede ser la de una compra que se está cargando: no se toca.');
        $this->assertNotNull(ProviderOrderAfipTicket::find($escenario['con_compra']->id), 'Una factura con compra no es huérfana.');
        $this->assertNotNull(ProviderOrderAfipTicketIva::find($escenario['alicuota_de_con_compra']->id), 'Ni su alícuota.');
        $this->assertNotNull(ProviderOrderAfipTicketIva::find($escenario['alicuota_sin_factura_reciente']->id), 'Una alícuota sin factura de hace dos horas no se toca.');
    }

    /**
     * Test 6 — El comando recorre SOLO dueños: una factura huérfana cuyo `user_id` es el de un
     * empleado (`owner_id` no NULL) no se lista ni se borra. El criterio de a quién le suma una
     * factura es el `user_id` por el que la leen los reportes, que siempre es el del dueño.
     *
     * @group compras
     * @test
     */
    public function el_comando_no_toca_facturas_de_un_usuario_que_no_es_dueno()
    {
        $dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $empleado = User::create([
            'name'     => 'Empleado facturas huerfanas',
            'email'    => 'empleado-huerfanas-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $dueno->id,
        ]);

        $de_empleado = $this->sembrar_factura($empleado->id, $this->id_de_compra_inexistente(), Carbon::now()->subDays(2));

        Artisan::call('facturas-de-compra:huerfanas', ['--aplicar' => true]);

        $this->assertNotNull(ProviderOrderAfipTicket::find($de_empleado->id), 'El comando itera dueños: la factura de un empleado no entra.');
    }
}
