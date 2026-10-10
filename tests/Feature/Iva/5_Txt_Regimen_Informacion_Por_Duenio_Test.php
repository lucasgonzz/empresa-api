<?php

namespace Tests\Feature\Iva;

use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\EmpresaTestCase;

/**
 * Los dos TXT del regimen de informacion de ventas (Reportes -> Posicion fiscal, botones
 * "Comprobantes .txt" y "Alicuotas .txt") traen SOLO los comprobantes del duenio logueado.
 *
 * Por que existe este archivo (mision `afip-txt-por-duenio`, 10/10/2026): hasta ese dia
 * `AfipController::exportVentas()` y `exportAlicuotasTxt()` consultaban
 * `AfipTicket::whereBetween('created_at', ...)` sin filtro por duenio. En una base compartida
 * (`u767360347_empresa`, 51 comercios adentro) el TXT que el contador le sube a ARCA traia los
 * comprobantes de todos los comercios. Y un ticket sin venta legible hacia `dd()` y cortaba la
 * descarga entera.
 *
 * Se prueba por el camino real (HTTP, las mismas URLs que abre la SPA con `window.open`) con dos
 * duenios en la misma base, cada uno con su factura y su nota de credito en el mismo mes, con las
 * fechas INTERCALADAS (A, B, A, B) para que el orden por `created_at` tambien quede medido.
 *
 * Importes deterministas por SNAPSHOT (`imp_*_enviado` + `iva_detalle_enviado_json`): el TXT sale
 * de `AfipImportesResolver::resolve_from_snapshot()` y no de recalcular items, asi que los numeros
 * de cada renglon se pueden escribir a mano.
 *
 * 🔴 Mes propio: AGOSTO DE 2011. El plan sugeria marzo de 2014, pero ese mes ya lo usan
 * `Facturacion/3_Bucket_De_Alicuota_Y_Id_De_Arca_Test`, `8_Reimpresion_Ticket_Sin_Vinculo_Test` y
 * `9_Leyenda_Isib_Caba_Test`. Ningun archivo de `tests/` ni del fixture usa 2011 como fecha. Igual
 * se verifica al arrancar cada test (ver `setUp()`): con un ticket ajeno en el mes, las aserciones
 * de "solo los de A" no medirian lo que dicen.
 *
 * El contenido se lee del archivo que devuelve la `BinaryFileResponse`, sin `Storage::fake`: el
 * controller descarga por `storage_path()` real. El `tearDown()` borra los archivos generados.
 *
 * @group iva-txt
 */
class Txt_Regimen_Informacion_Por_Duenio_Test extends EmpresaTestCase
{
    /**
     * Mes de las filas sembradas por esta clase, en el formato que manda la SPA en la URL.
     */
    const MES = '2011-08';

    /**
     * Fechas intercaladas entre los dos duenios: el TXT de cada uno tiene que salir en orden de
     * `created_at` y sin los renglones del otro metidos en el medio.
     */
    const FECHA_FACTURA_A = '2011-08-10 10:00:00';
    const FECHA_VENTA_BORRADA_A = '2011-08-12 10:00:00';
    const FECHA_FACTURA_B = '2011-08-15 10:00:00';
    const FECHA_NOTA_CREDITO_A = '2011-08-20 10:00:00';
    const FECHA_NOTA_CREDITO_B = '2011-08-25 10:00:00';

    /**
     * Puntos de venta distinguibles: A factura por el 98 y B por el 97.
     */
    const PUNTO_VENTA_A = 98;
    const PUNTO_VENTA_B = 97;

    /**
     * Nombres de archivo de siempre (lo que ve el usuario al descargar).
     */
    const ARCHIVO_COMPROBANTES = 'Comprobantes_2011-08_a_2011-08.txt';
    const ARCHIVO_ALICUOTAS = 'Alicuotas_2011-08_a_2011-08.txt';

    /**
     * Ids de lo sembrado, para borrarlo en el tearDown en orden inverso al de creacion. El
     * rollback de `DatabaseTransactions` ya deberia alcanzar (la base es InnoDB, lo verifica
     * `EmpresaTestCase::setUp()`), pero el borrado explicito no depende de eso. Mismo criterio que
     * `3_Libro_Iva_Ventas_Notas_Credito_Test`.
     *
     * @var array<string,array<int,int>>
     */
    protected $sembrado = [
        'afip_tickets'     => [],
        'current_acounts'  => [],
        'sales'            => [],
        'clients'          => [],
        'afip_information' => [],
        'users'            => [],
    ];

    /**
     * Duenios cuyas carpetas `storage/app/afip-txt/<id>/` pudo haber escrito el test. El
     * `tearDown()` borra ahi los dos archivos de este mes.
     *
     * @var array<int,int>
     */
    protected $duenios_con_archivos = [];

    /**
     * setUp: el del padre (guards de entorno + sesion del duenio del fixture) y despues el seguro
     * del mes propio.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->duenios_con_archivos[] = $this->duenio_a()->id;

        /** Tickets que ya hay en el mes de esta clase, borrados incluidos. Tiene que dar cero. */
        $tickets_en_el_mes = AfipTicket::withTrashed()
                                        ->whereBetween('created_at', [
                                            Carbon::parse(self::MES)->startOfMonth(),
                                            Carbon::parse(self::MES)->endOfMonth(),
                                        ])
                                        ->count();

        if ($tickets_en_el_mes > 0) {
            $this->fail(
                'SEGURO: la base de testing ya tiene '.$tickets_en_el_mes.' afip_ticket(s) en '.self::MES.
                ', el mes propio de este test. Con comprobantes ajenos en el mes, las aserciones de '.
                '"solo los de A" no miden lo que dicen. Buscar que test los dejo (o elegir otro mes '.
                'libre para esta clase), no saltear el seguro.'
            );
        }
    }

    /**
     * Borra lo sembrado en orden inverso al de creacion, y los archivos que generaron las
     * descargas. Corre siempre, incluso si una asercion corto el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->sembrado['afip_tickets'])) {
            // forceDelete y withTrashed: AfipTicket usa SoftDeletes.
            AfipTicket::withTrashed()->whereIn('id', $this->sembrado['afip_tickets'])->forceDelete();
        }

        if (count($this->sembrado['current_acounts'])) {
            CurrentAcount::whereIn('id', $this->sembrado['current_acounts'])->delete();
        }

        if (count($this->sembrado['sales'])) {
            // withTrashed: el test 5 borra (soft) una de estas ventas a proposito.
            Sale::withTrashed()->whereIn('id', $this->sembrado['sales'])->forceDelete();
        }

        if (count($this->sembrado['clients'])) {
            Client::withTrashed()->whereIn('id', $this->sembrado['clients'])->forceDelete();
        }

        if (count($this->sembrado['afip_information'])) {
            AfipInformation::whereIn('id', $this->sembrado['afip_information'])->delete();
        }

        if (count($this->sembrado['users'])) {
            User::whereIn('id', $this->sembrado['users'])->delete();
        }

        $this->borrar_archivos_generados();

        parent::tearDown();
    }

    /**
     * Borra los dos TXT de este mes de la carpeta de cada duenio del test, y la carpeta si quedo
     * vacia (la del duenio B nace con el test). Si `storage/app/afip-txt` quedo vacia, tambien.
     *
     * @return void
     */
    protected function borrar_archivos_generados()
    {
        /** Carpeta raiz de los TXT por duenio, con barras normales. */
        $raiz = str_replace('\\', '/', storage_path('app/afip-txt'));

        foreach (array_unique($this->duenios_con_archivos) as $user_id) {

            $carpeta = $raiz.'/'.$user_id;

            foreach ([self::ARCHIVO_COMPROBANTES, self::ARCHIVO_ALICUOTAS] as $nombre) {
                if (is_file($carpeta.'/'.$nombre)) {
                    unlink($carpeta.'/'.$nombre);
                }
            }

            if (is_dir($carpeta) && count(scandir($carpeta)) === 2) {
                rmdir($carpeta);
            }
        }

        if (is_dir($raiz) && count(scandir($raiz)) === 2) {
            rmdir($raiz);
        }
    }

    /**
     * Duenio A: el usuario del fixture, que es el que autentica `EmpresaTestCase::setUp()`.
     *
     * @return \App\Models\User
     */
    protected function duenio_a()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Duenio B: otro comercio en la misma base (`owner_id` null), igual que
     * `CuentaCorriente/10_Saldo_inicial_Test::otro_duenio()`.
     *
     * @return \App\Models\User
     */
    protected function crear_duenio_b()
    {
        $usuario = User::create([
            'name'     => 'Otro comercio txt afip',
            'email'    => 'txt-afip-otro-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => null,
        ]);

        $this->sembrado['users'][] = $usuario->id;
        $this->duenios_con_archivos[] = $usuario->id;

        return $usuario;
    }

    /**
     * Configuracion fiscal propia de un duenio, Responsable inscripto.
     *
     * @param  int $user_id
     * @param  int $punto_venta
     * @return \App\Models\AfipInformation
     */
    protected function configuracion_fiscal($user_id, $punto_venta)
    {
        $iva_condition = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($iva_condition)) {
            $this->fail('No existe la condicion de IVA "Responsable inscripto" en la base de testing: es un problema del fixture.');
        }

        $afip_information = AfipInformation::create([
            'user_id'                => $user_id,
            'iva_condition_id'       => $iva_condition->id,
            'razon_social'           => 'Comercio de test txt afip '.$user_id,
            'cuit'                   => '20000000000',
            'punto_venta'            => $punto_venta,
            // En 0 a proposito: homologacion. Ningun test de esta clase emite nada.
            'afip_ticket_production' => 0,
        ]);

        $this->sembrado['afip_information'][] = $afip_information->id;

        return $afip_information;
    }

    /**
     * Una venta terminada de un duenio.
     *
     * @param  int $user_id
     * @param  int|null $client_id
     * @param  string $fecha
     * @return \App\Models\Sale
     */
    protected function venta($user_id, $client_id, $fecha)
    {
        $venta = Sale::create([
            'user_id'    => $user_id,
            'client_id'  => $client_id,
            'moneda_id'  => 1,
            'total'      => 1000,
            'terminada'  => 1,
            'created_at' => $fecha,
        ]);

        $this->sembrado['sales'][] = $venta->id;

        return $venta;
    }

    /**
     * Columnas del snapshot fiscal que `AfipImportesResolver::resolve_from_snapshot()` usa en vez
     * de recalcular: lo que se le declaro a ARCA al autorizar.
     *
     * @param  float $total
     * @param  float $neto
     * @param  float $iva
     * @param  array $detalle Renglones `['Id' => ..., 'BaseImp' => ..., 'Importe' => ...]`.
     * @return array
     */
    protected function snapshot($total, $neto, $iva, $detalle)
    {
        return [
            'imp_total_enviado'        => $total,
            'imp_tot_conc_enviado'     => 0,
            'imp_neto_enviado'         => $neto,
            'imp_op_ex_enviado'        => 0,
            'imp_iva_enviado'          => $iva,
            'iva_detalle_enviado_json' => $detalle,
        ];
    }

    /**
     * La factura autorizada de una venta (`sale_id` puesto), con su snapshot.
     *
     * @param  \App\Models\AfipInformation $afip_information
     * @param  \App\Models\Sale $venta
     * @param  string $cbte_tipo
     * @param  int $cbte_numero
     * @param  string $fecha
     * @param  array $snapshot Ver `snapshot()`.
     * @return \App\Models\AfipTicket
     */
    protected function factura($afip_information, $venta, $cbte_tipo, $cbte_numero, $fecha, $snapshot)
    {
        $afip_ticket = AfipTicket::create(array_merge([
            'sale_id'             => $venta->id,
            'afip_information_id' => $afip_information->id,
            'punto_venta'         => $afip_information->punto_venta,
            'cbte_tipo'           => $cbte_tipo,
            'cbte_numero'         => (string) $cbte_numero,
            'resultado'           => 'A',
            'importe_total'       => $snapshot['imp_total_enviado'],
            'cuit_negocio'        => '20000000000',
            'cae'                 => '00000000000000',
            'created_at'          => $fecha,
        ], $snapshot));

        $this->sembrado['afip_tickets'][] = $afip_ticket->id;

        return $afip_ticket;
    }

    /**
     * La nota de credito autorizada de una venta: el movimiento de cuenta corriente y su ticket,
     * que nace con `sale_id` en NULL y `sale_nota_credito_id` apuntando a la venta, igual que lo
     * crea `AfipNotaCreditoHelper::create_afip_ticket()`.
     *
     * @param  \App\Models\AfipInformation $afip_information
     * @param  \App\Models\Sale $venta
     * @param  \App\Models\AfipTicket $factura
     * @param  string $cbte_tipo
     * @param  int $cbte_numero
     * @param  string $fecha
     * @param  array $snapshot Ver `snapshot()`.
     * @return \App\Models\AfipTicket
     */
    protected function nota_credito($afip_information, $venta, $factura, $cbte_tipo, $cbte_numero, $fecha, $snapshot)
    {
        $movimiento = CurrentAcount::create([
            'detalle'     => 'Nota Credito de test txt afip',
            'description' => 'Devolucion de test',
            'haber'       => $snapshot['imp_total_enviado'],
            'status'      => 'nota_credito',
            'sale_id'     => $venta->id,
            'user_id'     => $venta->user_id,
            'moneda_id'   => 1,
            'created_at'  => $fecha,
        ]);

        $this->sembrado['current_acounts'][] = $movimiento->id;

        $afip_ticket = AfipTicket::create(array_merge([
            'afip_information_id'  => $afip_information->id,
            'punto_venta'          => $afip_information->punto_venta,
            'nota_credito_id'      => $movimiento->id,
            'sale_nota_credito_id' => $venta->id,
            'sale_afip_ticket_id'  => $factura->id,
            'cbte_tipo'            => $cbte_tipo,
            'cbte_numero'          => (string) $cbte_numero,
            'resultado'            => 'A',
            'importe_total'        => $snapshot['imp_total_enviado'],
            'cuit_negocio'         => '20000000000',
            'cae'                  => '00000000000001',
            'created_at'           => $fecha,
        ], $snapshot));

        $this->sembrado['afip_tickets'][] = $afip_ticket->id;

        return $afip_ticket;
    }

    /**
     * Arma el escenario de los dos duenios en el mismo mes, con las fechas intercaladas:
     *
     *   10/8  A  Factura A (001)  pv 98  nro 70001  cliente con CUIT   21 % 1000/210 + 10,5 % 200/21 = 1431
     *   15/8  B  Factura B (006)  pv 97  nro 80001  sin cliente        21 %  200/42                  =  242
     *   20/8  A  NC A      (003)  pv 98  nro 70002                     21 %  500/105                 =  605
     *   25/8  B  NC B      (008)  pv 97  nro 80002                     21 %  100/21                  =  121
     *
     * @return array{duenio_b: \App\Models\User, afip_information_a: \App\Models\AfipInformation}
     */
    protected function escenario()
    {
        $duenio_a = $this->duenio_a();
        $duenio_b = $this->crear_duenio_b();

        $afip_information_a = $this->configuracion_fiscal($duenio_a->id, self::PUNTO_VENTA_A);
        $afip_information_b = $this->configuracion_fiscal($duenio_b->id, self::PUNTO_VENTA_B);

        // El cliente de A tiene CUIT: el renglon de A mide tambien el tipo y numero de documento.
        $cliente_a = Client::create([
            'name'    => 'Cliente TXT Duenio A',
            'cuit'    => '20111111112',
            'user_id' => $duenio_a->id,
        ]);
        $this->sembrado['clients'][] = $cliente_a->id;

        $venta_a = $this->venta($duenio_a->id, $cliente_a->id, self::FECHA_FACTURA_A);
        $venta_b = $this->venta($duenio_b->id, null, self::FECHA_FACTURA_B);

        $factura_a = $this->factura($afip_information_a, $venta_a, '1', 70001, self::FECHA_FACTURA_A, $this->snapshot(1431, 1200, 231, [
            ['Id' => 5, 'BaseImp' => 1000, 'Importe' => 210],
            ['Id' => 4, 'BaseImp' => 200, 'Importe' => 21],
        ]));

        $factura_b = $this->factura($afip_information_b, $venta_b, '6', 80001, self::FECHA_FACTURA_B, $this->snapshot(242, 200, 42, [
            ['Id' => 5, 'BaseImp' => 200, 'Importe' => 42],
        ]));

        $this->nota_credito($afip_information_a, $venta_a, $factura_a, '3', 70002, self::FECHA_NOTA_CREDITO_A, $this->snapshot(605, 500, 105, [
            ['Id' => 5, 'BaseImp' => 500, 'Importe' => 105],
        ]));

        $this->nota_credito($afip_information_b, $venta_b, $factura_b, '8', 80002, self::FECHA_NOTA_CREDITO_B, $this->snapshot(121, 100, 21, [
            ['Id' => 5, 'BaseImp' => 100, 'Importe' => 21],
        ]));

        return ['duenio_b' => $duenio_b, 'afip_information_a' => $afip_information_a];
    }

    /**
     * Pega a una de las dos URLs con la sesion activa, exige un 200 con archivo adjunto y
     * devuelve la respuesta y el contenido del archivo que se descargo.
     *
     * @param  string $url
     * @return array{0: \Illuminate\Testing\TestResponse, 1: string}
     */
    protected function descargar($url)
    {
        $respuesta = $this->get($url);

        $respuesta->assertStatus(200);

        $this->assertInstanceOf(
            BinaryFileResponse::class,
            $respuesta->baseResponse,
            'el TXT se descarga como archivo (response()->download)'
        );

        $contenido = file_get_contents($respuesta->baseResponse->getFile()->getPathname());

        return [$respuesta, $contenido];
    }

    /**
     * Renglones de un TXT. El separador es "\r\n", como siempre.
     *
     * @param  string $contenido
     * @return array<int,string>
     */
    protected function renglones($contenido)
    {
        if ($contenido === '') {
            return [];
        }

        return explode("\r\n", $contenido);
    }

    /**
     * Identifica cada renglon del TXT de comprobantes por tipo, punto de venta y numero
     * (posiciones 9-11, 12-16 y 17-36 del diseno de ARCA).
     *
     * @param  string $contenido
     * @return array<int,string> `tipo-punto_venta-numero` por renglon, en el orden del archivo.
     */
    protected function comprobantes_del_txt($contenido)
    {
        $comprobantes = [];

        foreach ($this->renglones($contenido) as $renglon) {
            $comprobantes[] = substr($renglon, 8, 3).'-'.substr($renglon, 11, 5).'-'.substr($renglon, 16, 20);
        }

        return $comprobantes;
    }

    /**
     * Lo mismo para el TXT de alicuotas, donde el renglon arranca por el tipo (posiciones 1-3,
     * 4-8 y 9-28).
     *
     * @param  string $contenido
     * @return array<int,string> `tipo-punto_venta-numero` por renglon (uno por alicuota).
     */
    protected function comprobantes_del_txt_de_alicuotas($contenido)
    {
        $comprobantes = [];

        foreach ($this->renglones($contenido) as $renglon) {
            $comprobantes[] = substr($renglon, 0, 3).'-'.substr($renglon, 3, 5).'-'.substr($renglon, 8, 20);
        }

        return $comprobantes;
    }

    /**
     * Ruta (con barras normales) del archivo que devolvio una descarga.
     *
     * @param  \Illuminate\Testing\TestResponse $respuesta
     * @return string
     */
    protected function ruta_del_archivo($respuesta)
    {
        return str_replace('\\', '/', $respuesta->baseResponse->getFile()->getPathname());
    }

    /**
     * Test 1 — el duenio A baja el TXT de comprobantes: SOLO su factura y su nota de credito, en
     * orden de `created_at`, con el nombre de archivo de siempre.
     *
     * Contra el codigo viejo da cuatro renglones (A, B, A, B): los de B entran en el medio.
     *
     * @group iva-txt
     * @test
     */
    public function el_txt_de_comprobantes_del_duenio_a_trae_solo_sus_comprobantes()
    {
        $this->escenario();

        list($respuesta, $contenido) = $this->descargar('afip-txt/'.self::MES.'/'.self::MES);

        $this->assertSame(
            'attachment; filename='.self::ARCHIVO_COMPROBANTES,
            $respuesta->headers->get('Content-Disposition'),
            'el nombre del archivo descargado tiene que ser exactamente el de siempre'
        );

        $this->assertSame(
            [
                '001-00098-00000000000000070001',
                '003-00098-00000000000000070002',
            ],
            $this->comprobantes_del_txt($contenido),
            'el TXT de A trae SOLO la factura y la nota de credito de A, en orden de created_at; ningun renglon de B'
        );

        $this->assertStringEndsWith(
            'app/afip-txt/'.$this->duenio_a()->id.'/'.self::ARCHIVO_COMPROBANTES,
            $this->ruta_del_archivo($respuesta),
            'el archivo se guarda en la carpeta propia del duenio, no suelto en storage/app'
        );
    }

    /**
     * Test 2 — el duenio B baja el mismo periodo: SOLO lo suyo, y en su propia carpeta.
     *
     * Contra el codigo viejo B tambien recibe los de A.
     *
     * @group iva-txt
     * @test
     */
    public function el_txt_de_comprobantes_del_duenio_b_trae_solo_sus_comprobantes()
    {
        $escenario = $this->escenario();

        Auth::forgetGuards();
        $this->actingAs($escenario['duenio_b'], 'web');

        list($respuesta, $contenido) = $this->descargar('afip-txt/'.self::MES.'/'.self::MES);

        $this->assertSame(
            'attachment; filename='.self::ARCHIVO_COMPROBANTES,
            $respuesta->headers->get('Content-Disposition'),
            'el nombre descargado es el mismo para cualquier duenio'
        );

        $this->assertSame(
            [
                '006-00097-00000000000000080001',
                '008-00097-00000000000000080002',
            ],
            $this->comprobantes_del_txt($contenido),
            'el TXT de B trae SOLO la factura y la nota de credito de B, en orden de created_at; ningun renglon de A'
        );

        $this->assertStringEndsWith(
            'app/afip-txt/'.$escenario['duenio_b']->id.'/'.self::ARCHIVO_COMPROBANTES,
            $this->ruta_del_archivo($respuesta),
            'el archivo de B va a la carpeta de B: dos duenios bajando el mismo periodo no comparten archivo'
        );
    }

    /**
     * Test 3 — lo mismo para el TXT de alicuotas, con los dos duenios en el mismo test.
     *
     * La factura de A tiene dos alicuotas, asi que son dos renglones del mismo comprobante.
     *
     * @group iva-txt
     * @test
     */
    public function el_txt_de_alicuotas_trae_solo_los_comprobantes_de_cada_duenio()
    {
        $escenario = $this->escenario();

        list($respuesta_a, $contenido_a) = $this->descargar('afip-txt-alicuotas/'.self::MES.'/'.self::MES);

        $this->assertSame(
            'attachment; filename='.self::ARCHIVO_ALICUOTAS,
            $respuesta_a->headers->get('Content-Disposition'),
            'el nombre del archivo de alicuotas descargado tiene que ser exactamente el de siempre'
        );

        $this->assertSame(
            [
                '001-00098-00000000000000070001',
                '001-00098-00000000000000070001',
                '003-00098-00000000000000070002',
            ],
            $this->comprobantes_del_txt_de_alicuotas($contenido_a),
            'el TXT de alicuotas de A trae SOLO sus comprobantes (la factura con sus dos alicuotas y la nota de credito); ningun renglon de B'
        );

        Auth::forgetGuards();
        $this->actingAs($escenario['duenio_b'], 'web');

        list($respuesta_b, $contenido_b) = $this->descargar('afip-txt-alicuotas/'.self::MES.'/'.self::MES);

        $this->assertSame(
            [
                '006-00097-00000000000000080001',
                '008-00097-00000000000000080002',
            ],
            $this->comprobantes_del_txt_de_alicuotas($contenido_b),
            'el TXT de alicuotas de B trae SOLO sus comprobantes; ningun renglon de A'
        );

        $this->assertNotSame(
            $this->ruta_del_archivo($respuesta_a),
            $this->ruta_del_archivo($respuesta_b),
            'cada duenio escribe su propio archivo de alicuotas'
        );
    }

    /**
     * Test 4 — el renglon EXACTO. El pedido dice que el formato no cambia: se fija armando a mano,
     * campo por campo, el renglon de la factura de A en los dos TXT.
     *
     * @group iva-txt
     * @test
     */
    public function el_renglon_de_la_factura_sale_exactamente_con_el_formato_de_siempre()
    {
        $this->escenario();

        list($respuesta, $contenido) = $this->descargar('afip-txt/'.self::MES.'/'.self::MES);

        /** Renglon de la factura de A en el TXT de comprobantes, campo por campo. */
        $renglon_esperado = '20110810'                       // fecha del comprobante (Ymd del created_at)
                          . '001'                            // tipo de comprobante (Factura A)
                          . '00098'                          // punto de venta
                          . '00000000000000070001'           // numero desde
                          . '00000000000000070001'           // numero hasta (el mismo)
                          . '80'                             // codigo de documento del comprador (CUIT)
                          . '00000000020111111112'           // numero de documento
                          . 'Cliente TXT Duenio A          ' // comprador, 30 posiciones
                          . '000000000143100'                // importe total (1431,00, del snapshot)
                          . '000000000000000'                // conceptos no gravados
                          . '000000000000000'                // percepcion a no categorizados
                          . '000000000000000'                // operaciones exentas
                          . '000000000000000'                // percepciones nacionales
                          . '000000000000000'                // percepciones de ingresos brutos
                          . '000000000000000'                // percepciones municipales
                          . '000000000000000'                // impuestos internos
                          . 'PES'                            // moneda
                          . '0001000000'                     // tipo de cambio (1,000000)
                          . '2'                              // cantidad de alicuotas (21 % y 10,5 %)
                          . '0'                              // codigo de operacion
                          . '000000000000000'                // otros tributos
                          . '20110810';                      // fecha de vencimiento de pago

        $renglones = $this->renglones($contenido);

        $this->assertSame(
            $renglon_esperado,
            $renglones[0],
            'el renglon de la factura de A tiene que salir caracter por caracter como siempre'
        );

        $this->assertSame(266, strlen($renglones[0]), 'el renglon de comprobantes mide 266 posiciones, como pide ARCA');

        list($respuesta_alicuotas, $contenido_alicuotas) = $this->descargar('afip-txt-alicuotas/'.self::MES.'/'.self::MES);

        $renglones_alicuotas = $this->renglones($contenido_alicuotas);

        /** Los dos renglones de alicuotas de la factura de A, en el orden del snapshot. */
        $alicuota_21 = '001'                  // tipo de comprobante
                     . '00098'                // punto de venta
                     . '00000000000000070001' // numero
                     . '000000000100000'      // neto gravado (1000,00)
                     . '0005'                 // alicuota (Id 5 = 21 %)
                     . '000000000021000';     // IVA liquidado (210,00)

        $alicuota_10_5 = '001'
                       . '00098'
                       . '00000000000000070001'
                       . '000000000020000'    // neto gravado (200,00)
                       . '0004'               // alicuota (Id 4 = 10,5 %)
                       . '000000000002100';   // IVA liquidado (21,00)

        $this->assertSame($alicuota_21, $renglones_alicuotas[0], 'renglon de la alicuota 21 % de la factura de A, caracter por caracter');
        $this->assertSame($alicuota_10_5, $renglones_alicuotas[1], 'renglon de la alicuota 10,5 % de la factura de A, caracter por caracter');
        $this->assertSame(62, strlen($renglones_alicuotas[0]), 'el renglon de alicuotas mide 62 posiciones, como pide ARCA');
    }

    /**
     * Test 5 — un ticket de A con CAE cuya venta esta soft-deleted. La consulta lo trae (lee las
     * ventas con `withTrashed()`), pero `$afip_ticket->sale` no ve una venta borrada. Antes era un
     * `dd()` que cortaba la descarga entera; ahora la descarga sale 200, ese ticket queda afuera,
     * el resto de A sale entero (incluida la nota de credito, que es POSTERIOR al ticket roto) y
     * queda un aviso en el log por cada TXT.
     *
     * 🔴 Este test no se corre contra el codigo viejo: el `dd()` hace `exit` y mata el proceso de
     * PHPUnit entero.
     *
     * @group iva-txt
     * @test
     */
    public function un_ticket_con_la_venta_borrada_no_corta_la_descarga()
    {
        $escenario = $this->escenario();

        $venta_borrada = $this->venta($this->duenio_a()->id, null, self::FECHA_VENTA_BORRADA_A);

        $ticket_huerfano = $this->factura($escenario['afip_information_a'], $venta_borrada, '1', 70003, self::FECHA_VENTA_BORRADA_A, $this->snapshot(363, 300, 63, [
            ['Id' => 5, 'BaseImp' => 300, 'Importe' => 63],
        ]));

        // Soft delete: la fila sigue, con deleted_at puesto.
        $venta_borrada->delete();

        /** Mensajes de nivel warning que se escriban en el log durante las dos descargas. */
        $avisos = [];

        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $evento) use (&$avisos) {
            if ($evento->level === 'warning') {
                $avisos[] = $evento->message;
            }
        });

        list($respuesta, $contenido) = $this->descargar('afip-txt/'.self::MES.'/'.self::MES);

        $this->assertSame(
            [
                '001-00098-00000000000000070001',
                '003-00098-00000000000000070002',
            ],
            $this->comprobantes_del_txt($contenido),
            'el ticket con la venta borrada queda afuera y el resto de A sale entero, incluida la nota de credito posterior'
        );

        list($respuesta_alicuotas, $contenido_alicuotas) = $this->descargar('afip-txt-alicuotas/'.self::MES.'/'.self::MES);

        $this->assertSame(
            [
                '001-00098-00000000000000070001',
                '001-00098-00000000000000070001',
                '003-00098-00000000000000070002',
            ],
            $this->comprobantes_del_txt_de_alicuotas($contenido_alicuotas),
            'en el TXT de alicuotas tambien: el ticket con la venta borrada queda afuera y el resto sale'
        );

        /** Avisos que nombran al ticket huerfano. */
        $avisos_del_ticket = array_values(array_filter($avisos, function ($mensaje) use ($ticket_huerfano) {
            return strpos($mensaje, 'afip_ticket '.$ticket_huerfano->id.' ') !== false;
        }));

        $this->assertCount(
            2,
            $avisos_del_ticket,
            'tiene que quedar un warning en el log por cada TXT que nombre al ticket salteado'
        );

        foreach ($avisos_del_ticket as $aviso) {
            $this->assertStringContainsString('Quedo fuera del TXT', $aviso, 'el aviso dice que el ticket quedo fuera del TXT');
        }
    }

    /**
     * Test 6 — sin sesion, los dos endpoints dan 401.
     *
     * `$this->userId()` sin sesion NO da null: `UserHelper::userId()` cae en
     * `config('app.USER_ID')`. Sin la guarda explicita, el TXT de ese duenio le saldria a
     * cualquiera. Antes daba 500 de casualidad (`AfipHelper` hacia `$this->user->id` sobre null).
     * Se siembra el escenario para que haya comprobantes en el mes: la guarda tiene que cortar
     * antes de mirarlos.
     *
     * @group iva-txt
     * @test
     */
    public function sin_sesion_los_dos_txt_dan_401()
    {
        $this->escenario();

        // Sin ningun usuario autenticado: el guard se vuelve a armar desde una sesion vacia.
        Auth::forgetGuards();

        $this->get('afip-txt/'.self::MES.'/'.self::MES)->assertStatus(401);

        $this->get('afip-txt-alicuotas/'.self::MES.'/'.self::MES)->assertStatus(401);
    }
}
