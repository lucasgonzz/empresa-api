<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\EmpresaTestCase;

/**
 * Archivo 8 — reimprimir un ticket AFIP que no tiene `afip_information_id` no puede reventar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTA SUITE CIERRA (mision reimpresion-ticket-afip-sin-vinculo, 23/9/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Los 70 tickets de golden-bike los emitio codigo anterior a diciembre de 2025, que no guardaba
 *  la FK `afip_tickets.afip_information_id`. Reimprimir cualquiera daba 500 con
 *  `Trying to get property 'iva_condition' of non-object` en `AfipImportesCalculator`: el
 *  calculador de importes y los PDF asumen que la configuracion fiscal siempre esta.
 *
 *  Lo que esos tickets SI guardaron es la foto del emisor: `cuit_negocio`, `iva_negocio` y
 *  `punto_venta`. El arreglo son dos capas:
 *
 *   - `AfipTicket::getAfipInformationAttribute()`: si no hay vinculo, busca UNA configuracion con
 *     ese cuit y ese punto de venta, del mismo duenio. Cero o mas de una, devuelve null.
 *   - `AfipImportesCalculator::condicion_iva_del_emisor()`: si tampoco hay configuracion, usa el
 *     `iva_negocio` del ticket; y si tampoco, corta con un mensaje que dice que ticket es.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  COMO SE EJERCITA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Sobre el camino real y con la base de por medio: las configuraciones, las ventas y los tickets
 *  son FILAS de verdad dentro de la transaccion del test (`DatabaseTransactions`, heredado de
 *  `EmpresaTestCase`), y se leen de nuevo con `AfipTicket::find()` para que lleguen como llega una
 *  reimpresion —sin ninguna relacion cargada—. NUNCA se llama a `MakeAfipTicket::make_afip_ticket()`,
 *  que le habla a ARCA de verdad.
 *
 *  Todo lo que se siembra lleva un cuit y un punto de venta que no existen en el fixture, para
 *  que un ticket "sin config" no encuentre por casualidad la del comercio de prueba.
 *
 *  Sobre las aserciones de plata: `assertEqualsWithDelta()`, NUNCA `assertEquals()` con un cuarto
 *  argumento — PHPUnit 9.6 lo descarta en silencio y compara con el EPSILON de 1e-10.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group facturacion
 * @group reimpresion-sin-vinculo
 */
class Reimpresion_Ticket_Sin_Vinculo_Test extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata.
     */
    const DELTA = 0.01;

    /**
     * Fecha de lo que siembra esta clase. Marzo de 2014, la misma ventana reservada que usan las
     * otras suites de facturacion: nada mas de la suite cae ahi.
     */
    const FECHA = '2014-03-11 10:00:00';

    /**
     * CUIT de las configuraciones de prueba. No es el del fixture (20423548984).
     */
    const CUIT = '20111111112';

    /**
     * CUIT que NINGUNA configuracion tiene: para los tickets que tienen que quedar sin config.
     */
    const CUIT_SIN_CONFIG = '30999999996';

    /**
     * Punto de venta de las configuraciones de prueba. No es el del fixture (1).
     */
    const PUNTO_VENTA = 97;

    /**
     * Id de un duenio que no existe: las configuraciones ajenas se siembran a nombre de este.
     * `afip_information.user_id` no tiene FK, asi que no hace falta que el usuario exista.
     */
    const OTRO_DUENIO_ID = 987001;

    // -----------------------------------------------------------------------------------------
    // Montaje
    // -----------------------------------------------------------------------------------------

    /**
     * Usuario duenio del fixture.
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Crea (EN LA BASE) una configuracion fiscal del comercio del fixture.
     *
     * @param  string $condicion Nombre de la condicion de IVA del emisor.
     * @param  array $overrides Campos a pisar (`cuit`, `punto_venta`, `user_id`, `razon_social`...).
     * @return \App\Models\AfipInformation
     */
    protected function crear_config($condicion = 'Responsable inscripto', $overrides = [])
    {
        $iva_condition = IvaCondition::where('name', $condicion)->first();

        $this->assertNotNull(
            $iva_condition,
            'Falta la iva_condition "'.$condicion.'" en la base de testing. Sin ella el calculador '.
            'entra por la rama equivocada y este archivo no mide nada.'
        );

        return AfipInformation::create(array_merge([
            'user_id'                => $this->usuario_de_testing()->id,
            'iva_condition_id'       => $iva_condition->id,
            'cuit'                   => self::CUIT,
            'punto_venta'            => self::PUNTO_VENTA,
            'razon_social'           => 'zz Razon social de test',
            'domicilio_comercial'    => 'zz Calle de test 123',
            'afip_ticket_production' => 0,
        ], $overrides));
    }

    /**
     * Configuracion fiscal EN MEMORIA (sin guardar) con la condicion pedida: es la que tendria un
     * ticket "con vinculo" de un comercio de esa condicion.
     *
     * @param  string $condicion
     * @return \App\Models\AfipInformation
     */
    protected function config_en_memoria($condicion)
    {
        $iva_condition = IvaCondition::where('name', $condicion)->first();

        $this->assertNotNull($iva_condition, 'Falta la iva_condition "'.$condicion.'" en la base de testing.');

        $config = new AfipInformation();
        $config->iva_condition_id = $iva_condition->id;
        $config->punto_venta = self::PUNTO_VENTA;
        $config->cuit = self::CUIT;
        $config->setRelation('iva_condition', $iva_condition);

        return $config;
    }

    /**
     * Crea una venta minima del comercio del fixture.
     *
     * @param  float $total
     * @return \App\Models\Sale
     */
    protected function crear_venta($total)
    {
        return Sale::create([
            'user_id'                    => $this->usuario_de_testing()->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'moneda_id'                  => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'created_at'                 => self::FECHA,
        ]);
    }

    /**
     * Cuelga de la venta un articulo del fixture con su alicuota historica en el pivot.
     *
     * 🔴 La alicuota va como texto CRUDO ('10.5'): asi la compara `AfipItemCalculator`.
     *
     * @param  \App\Models\Sale $venta
     * @param  float $price Precio CON IVA incluido.
     * @param  string $iva_percentage
     * @return void
     */
    protected function agregar_articulo($venta, $price, $iva_percentage)
    {
        $articulo = $this->articulo('Cuchilla');

        if (is_null($articulo)) {
            $this->fail('No existe el articulo "Cuchilla" en el fixture de testing.');
        }

        $venta->articles()->attach($articulo->id, [
            'amount'         => 1,
            'price'          => $price,
            'iva_percentage' => $iva_percentage,
        ]);
    }

    /**
     * Crea (EN LA BASE) un ticket AFIP SIN `afip_information_id`, como los que emitia el codigo
     * anterior a diciembre de 2025, y lo devuelve leido de nuevo: sin ninguna relacion cargada,
     * que es como llega una reimpresion.
     *
     * @param  \App\Models\Sale|null $venta Venta del ticket; null = ticket sin venta.
     * @param  array $overrides Campos a pisar.
     * @return \App\Models\AfipTicket
     */
    protected function crear_ticket($venta, $overrides = [])
    {
        $ticket = AfipTicket::create(array_merge([
            'sale_id'             => is_null($venta) ? null : $venta->id,
            'cuit_negocio'        => self::CUIT,
            'iva_negocio'         => 'Responsable inscripto',
            'punto_venta'         => (string) self::PUNTO_VENTA,
            'cbte_letra'          => 'B',
            'cbte_tipo'           => '6',
            'cbte_numero'         => '12345',
            'cae'                 => '70123456789012',
            'cae_expired_at'      => '2014-03-21',
            'afip_information_id' => null,
        ], $overrides));

        return AfipTicket::find($ticket->id);
    }

    /**
     * Los importes de una REIMPRESION: el mismo camino que usa `SaleTicketPdf::iva_discriminado()`,
     * o sea `getImportes(true)` (modo lectura) sobre el ticket, con los articulos de su venta.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return array
     */
    protected function importes_de_reimpresion($ticket)
    {
        $afip_helper = new AfipHelper($ticket, null, null, $this->usuario_de_testing(), null, [], null);

        return $afip_helper->getImportes(true);
    }

    /**
     * Cuenta las consultas a la base que hace un bloque de codigo.
     *
     * @param  callable $bloque
     * @return int
     */
    protected function consultas_de($bloque)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $bloque();

        $cantidad = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $cantidad;
    }

    // -----------------------------------------------------------------------------------------
    // Capa 1 — el modelo
    // -----------------------------------------------------------------------------------------

    /**
     * Caso 1 — REGRESION: un ticket con su vinculo sigue devolviendo SU configuracion, la de la
     * relacion, y el respaldo no se entera.
     *
     * 🔴 Para que no sea un verde vacio, el ticket lleva a proposito el cuit y el punto de venta
     * de OTRA configuracion del mismo duenio. Si el accessor mirara primero el respaldo, devolveria
     * la equivocada. Los tres caminos de cargar la relacion (por la FK, con `with()` y con
     * `setRelation()`) tienen que devolver la propia.
     *
     * @test
     */
    public function un_ticket_con_vinculo_devuelve_su_configuracion_y_no_la_del_respaldo()
    {
        $propia = $this->crear_config('Monotributista', ['cuit' => '20222222223', 'punto_venta' => 5, 'razon_social' => 'zz Propia']);
        $decoy = $this->crear_config('Responsable inscripto', ['razon_social' => 'zz Decoy del respaldo']);

        $venta = $this->crear_venta(1105);

        // El ticket apunta a `propia` por la FK, pero su cuit y su punto de venta son los del decoy.
        $ticket_id = $this->crear_ticket($venta, ['afip_information_id' => $propia->id])->id;

        // (a) Por la FK, como llega una reimpresion normal.
        $por_fk = AfipTicket::find($ticket_id);
        $this->assertEquals(
            $propia->id,
            $por_fk->afip_information->id,
            'con la FK cargada tiene que devolver SU configuracion. Si devuelve la del cuit y punto de '.
            'venta, el respaldo le esta ganando a la relacion'
        );

        // (b) Con `with()`, como llegan las listas.
        $con_with = AfipTicket::with('afip_information')->find($ticket_id);
        $this->assertEquals($propia->id, $con_with->afip_information->id, 'idem, cargada con with()');

        // (c) En memoria, con `setRelation()`: tiene que ser EL MISMO objeto y no pagar ninguna consulta.
        $en_memoria = new AfipTicket();
        $en_memoria->cuit_negocio = self::CUIT;
        $en_memoria->punto_venta = (string) self::PUNTO_VENTA;
        $en_memoria->setRelation('afip_information', $propia);

        $devuelta = null;
        $consultas = $this->consultas_de(function () use ($en_memoria, &$devuelta) {
            $devuelta = $en_memoria->afip_information;
        });

        $this->assertSame($propia, $devuelta, 'con setRelation() tiene que devolver exactamente el objeto puesto');
        $this->assertSame(0, $consultas, 'con la relacion ya cargada no se consulta nada');

        // Y la relacion sigue siendo la de siempre: el metodo `afip_information()` no se toco.
        $this->assertEquals($propia->id, $por_fk->afip_information()->first()->id);

        // Control de que el decoy existe y de verdad coincidiria por cuit y punto de venta.
        $this->assertEquals(self::CUIT, $decoy->cuit);
    }

    /**
     * Caso 2 — un ticket SIN vinculo, con UNA configuracion del mismo duenio con su cuit y su
     * punto de venta, la resuelve; y los importes de reimpresion dan EXACTAMENTE lo mismo que con
     * el vinculo cargado.
     *
     * 🔴 Con el codigo viejo `afip_information` daba null y `getImportes()` reventaba con
     * "Trying to get property 'iva_condition' of non-object": este es el caso de golden-bike.
     *
     * Ademas: una segunda lectura no vuelve a la base (el resultado se cachea en la instancia), y
     * una FK colgante (apunta a una fila que no existe) se resuelve igual que una FK NULL.
     *
     * @test
     */
    public function un_ticket_sin_vinculo_resuelve_su_configuracion_y_da_los_mismos_importes()
    {
        $config = $this->crear_config();

        // Articulo al 10,5 %: 1105 con IVA = base 1000,00 + IVA 105,00.
        $venta = $this->crear_venta(1105);
        $this->agregar_articulo($venta, 1105, '10.5');

        $ticket_id = $this->crear_ticket($venta)->id;

        $sin_vinculo = AfipTicket::find($ticket_id);

        $this->assertNull(
            $sin_vinculo->afip_information_id,
            'ESCENARIO MAL ARMADO si esto falla: el ticket tiene que llegar SIN afip_information_id'
        );

        $resuelta = $sin_vinculo->afip_information;

        $this->assertNotNull(
            $resuelta,
            'EL DEFECTO EN UNA LINEA: un ticket sin afip_information_id, con una unica configuracion '.
            'con su cuit y su punto de venta, tiene que resolverla por ahi'
        );
        $this->assertEquals($config->id, $resuelta->id);
        $this->assertNotNull($resuelta->iva_condition, 'la configuracion resuelta tiene que traer su condicion de IVA');

        // El mismo ticket, pero con el vinculo cargado, que es el camino de siempre.
        $con_vinculo = AfipTicket::find($ticket_id);
        $con_vinculo->afip_information_id = $config->id;

        $importes_con_vinculo = $this->importes_de_reimpresion($con_vinculo);
        $importes_sin_vinculo = $this->importes_de_reimpresion($sin_vinculo);

        // No es un verde vacio: el emisor es RI, asi que el calculador desglosa el IVA del 10,5 %.
        $this->assertEqualsWithDelta(
            105.00,
            (float) $importes_con_vinculo['iva'],
            self::DELTA,
            'ESCENARIO MAL ARMADO si esto falla: con un emisor RI el calculador tiene que desglosar el '.
            'IVA (literal: 1105 - 1105 / 1,105). Si da 0 esta midiendo la rama de no inscripto'
        );

        $this->assertEquals(
            $importes_con_vinculo,
            $importes_sin_vinculo,
            'los importes de reimpresion de un ticket sin vinculo tienen que ser IDENTICOS a los del '.
            'mismo ticket con el vinculo cargado'
        );

        // El resultado se cachea: releerlo no vuelve a la base.
        $consultas = $this->consultas_de(function () use ($sin_vinculo) {
            $sin_vinculo->afip_information;
            $sin_vinculo->afip_information;
            $sin_vinculo->afip_information->iva_condition;
        });

        $this->assertSame(0, $consultas, 'una segunda lectura de afip_information no tiene que consultar la base');

        // FK colgante: apunta a una fila que no existe. Es el mismo caso que la FK NULL.
        $colgante = AfipTicket::find($ticket_id);
        $colgante->afip_information_id = 999999999;

        $this->assertNotNull(
            $colgante->afip_information,
            'una FK que apunta a una fila inexistente tiene que resolverse por cuit y punto de venta, '.
            'igual que una FK NULL'
        );
        $this->assertEquals($config->id, $colgante->afip_information->id);

        // Y resolver no escribe nada: el ticket sigue sin vinculo en la base.
        $this->assertNull(
            AfipTicket::find($ticket_id)->afip_information_id,
            'el respaldo es de SOLO LECTURA: no puede completar el afip_information_id del ticket'
        );

        /*
         * Ni pisa la relacion: con la FK NULL, Eloquent la deja cargada en null al leerla (eso ya
         * pasaba antes del respaldo), y la configuracion resuelta NO se cuela ahi. Asi toArray() y
         * toJson() de un ticket muestran exactamente lo mismo que antes de esta mision.
         */
        $this->assertNull(
            $sin_vinculo->getRelation('afip_information'),
            'el respaldo no tiene que dejar la configuracion resuelta como relacion cargada'
        );
        $this->assertNull(
            $sin_vinculo->toArray()['afip_information'],
            'la serializacion del ticket no tiene que cambiar por el respaldo'
        );
    }

    /**
     * Caso 3 — dos configuraciones del mismo duenio con el mismo cuit y DISTINTO punto de venta:
     * elige la del punto de venta del ticket.
     *
     * @test
     */
    public function con_dos_puntos_de_venta_elige_el_del_ticket()
    {
        $punto_3 = $this->crear_config('Responsable inscripto', ['punto_venta' => 3, 'razon_social' => 'zz Punto 3']);
        $punto_4 = $this->crear_config('Responsable inscripto', ['punto_venta' => 4, 'razon_social' => 'zz Punto 4']);

        $venta = $this->crear_venta(1105);

        $ticket_4 = $this->crear_ticket($venta, ['punto_venta' => '4']);
        $ticket_3 = $this->crear_ticket($venta, ['punto_venta' => '3']);

        $this->assertEquals(
            $punto_4->id,
            $ticket_4->afip_information->id,
            'un ticket del punto de venta 4 tiene que resolver la configuracion del punto de venta 4'
        );

        $this->assertEquals(
            $punto_3->id,
            $ticket_3->afip_information->id,
            'un ticket del punto de venta 3 tiene que resolver la configuracion del punto de venta 3'
        );
    }

    /**
     * Caso 4 — una configuracion de OTRO duenio con el mismo cuit y el mismo punto de venta NO se
     * toma.
     *
     * 🔴 Varios comercios comparten base, y dos pueden tener el mismo cuit y el mismo punto de
     * venta: la razon social o la condicion de IVA de otro emisor no pueden salir impresas en un
     * comprobante fiscal. Como control, en cuanto aparece la configuracion PROPIA la resuelve: lo
     * que descarto a la ajena fue el duenio y no otra cosa.
     *
     * @test
     */
    public function una_configuracion_de_otro_duenio_no_se_toma()
    {
        $this->crear_config('Responsable inscripto', [
            'user_id'      => self::OTRO_DUENIO_ID,
            'razon_social' => 'zz Comercio ajeno',
        ]);

        $venta = $this->crear_venta(1105);
        $ticket_id = $this->crear_ticket($venta)->id;

        $this->assertNull(
            AfipTicket::find($ticket_id)->afip_information,
            'EL DEFECTO EN UNA LINEA: el respaldo tomo la configuracion de OTRO duenio, con el mismo '.
            'cuit y punto de venta. En un comprobante fiscal eso imprime datos ajenos'
        );

        // Control: con la configuracion propia, ahora si la resuelve (instancia nueva: la anterior
        // cacheo el "no hay").
        $propia = $this->crear_config('Responsable inscripto', ['razon_social' => 'zz Comercio propio']);

        $resuelta = AfipTicket::find($ticket_id)->afip_information;

        $this->assertNotNull($resuelta, 'ESCENARIO MAL ARMADO si esto falla: la configuracion propia tiene que resolverse');
        $this->assertEquals($propia->id, $resuelta->id, 'tiene que ser la del duenio de la venta, no la ajena');
    }

    /**
     * Caso 5 — dos configuraciones del mismo duenio con el MISMO cuit y el MISMO punto de venta:
     * es ambiguo y devuelve null. Sin excepcion del modelo, y los importes igual salen (por el
     * `iva_negocio` que el ticket guardo).
     *
     * 🔴 Nunca se elige "la primera" entre dos: una configuracion equivocada imprime en un
     * comprobante la razon social o la condicion de IVA de otro emisor.
     *
     * @test
     */
    public function con_dos_configuraciones_iguales_no_adivina_y_devuelve_null()
    {
        $this->crear_config('Responsable inscripto', ['razon_social' => 'zz Duplicada A']);
        $this->crear_config('Monotributista', ['razon_social' => 'zz Duplicada B']);

        $venta = $this->crear_venta(1105);
        $this->agregar_articulo($venta, 1105, '10.5');

        $ticket = $this->crear_ticket($venta);

        $this->assertNull(
            $ticket->afip_information,
            'con dos configuraciones candidatas no se adivina: null'
        );

        // Releer no cambia el resultado y tampoco tira.
        $this->assertNull($ticket->afip_information);

        // Como el ticket guardo 'Responsable inscripto', el calculador puede seguir sin la config.
        $importes = $this->importes_de_reimpresion($ticket);

        $this->assertEqualsWithDelta(
            105.00,
            (float) $importes['iva'],
            self::DELTA,
            'sin configuracion resoluble el calculador usa el iva_negocio del ticket (RI: desglosa el IVA)'
        );
    }

    /**
     * Caso 8 — un ticket en memoria sin `cuit_negocio` (o sin un punto de venta numerico) devuelve
     * null y NO hace ninguna consulta.
     *
     * Es el ticket borrador: `MakeAfipTicket` y los helpers arman uno antes de tener todo, y leer
     * `afip_information` no puede convertirse en una consulta a la base por cada lectura.
     *
     * @test
     */
    public function un_ticket_sin_cuit_o_sin_punto_de_venta_no_consulta_la_base()
    {
        $sin_cuit = new AfipTicket();
        $sin_cuit->punto_venta = (string) self::PUNTO_VENTA;

        $cuit_vacio = new AfipTicket();
        $cuit_vacio->cuit_negocio = '   ';
        $cuit_vacio->punto_venta = (string) self::PUNTO_VENTA;

        $sin_punto_de_venta = new AfipTicket();
        $sin_punto_de_venta->cuit_negocio = self::CUIT;

        $punto_de_venta_no_numerico = new AfipTicket();
        $punto_de_venta_no_numerico->cuit_negocio = self::CUIT;
        $punto_de_venta_no_numerico->punto_venta = 'abc';

        $resultados = [];

        $consultas = $this->consultas_de(function () use ($sin_cuit, $cuit_vacio, $sin_punto_de_venta, $punto_de_venta_no_numerico, &$resultados) {
            $resultados[] = $sin_cuit->afip_information;
            $resultados[] = $cuit_vacio->afip_information;
            $resultados[] = $sin_punto_de_venta->afip_information;
            $resultados[] = $punto_de_venta_no_numerico->afip_information;
        });

        $this->assertSame([null, null, null, null], $resultados, 'sin cuit o sin punto de venta numerico no hay con que buscar');
        $this->assertSame(
            0,
            $consultas,
            'un ticket borrador no tiene que tocar la base: DB::getQueryLog() tiene que quedar vacio'
        );
    }

    /**
     * Extra — un ticket SIN venta (una prueba, un borrador con cuit y punto de venta) se busca sin
     * acotar por duenio, y una NOTA DE CREDITO (que no tiene `sale_id` pero si la venta que
     * acredita) se acota por el duenio de esa venta.
     *
     * Es lo que el plan pide para los tickets sin venta, mas el caso de la nota de credito, que se
     * agrego para no dejar afuera del filtro por duenio a un comprobante que si sabe de quien es.
     *
     * @test
     */
    public function un_ticket_sin_venta_no_acota_y_una_nota_de_credito_acota_por_la_venta_acreditada()
    {
        $ajena = $this->crear_config('Responsable inscripto', [
            'user_id'      => self::OTRO_DUENIO_ID,
            'razon_social' => 'zz Comercio ajeno',
        ]);

        // Sin venta: no hay a quien acotar, se busca por cuit y punto de venta a secas.
        $sin_venta = $this->crear_ticket(null);

        $this->assertNotNull($sin_venta->afip_information, 'un ticket sin ninguna venta se busca sin acotar por duenio');
        $this->assertEquals($ajena->id, $sin_venta->afip_information->id);

        // Nota de credito: sin `sale_id`, con la venta acreditada. La config ajena NO le sirve.
        $venta_acreditada = $this->crear_venta(1105);
        $nota_de_credito = $this->crear_ticket(null, ['sale_nota_credito_id' => $venta_acreditada->id]);

        $this->assertNull(
            $nota_de_credito->afip_information,
            'una nota de credito se acota por el duenio de la venta que acredita: la config ajena no aplica'
        );

        $propia = $this->crear_config('Responsable inscripto', ['razon_social' => 'zz Comercio propio']);

        $this->assertEquals(
            $propia->id,
            AfipTicket::find($nota_de_credito->id)->afip_information->id,
            'con la configuracion propia, la nota de credito la resuelve'
        );

        // Una venta que ya no existe: el ticket "tiene" venta pero no se puede saber de quien es. No se adivina.
        $huerfano = $this->crear_ticket(null, ['sale_id' => 987654321]);

        $this->assertNull(
            $huerfano->afip_information,
            'con una venta que no se puede leer no se sabe a quien acotar, y entonces no se adivina'
        );
    }

    // -----------------------------------------------------------------------------------------
    // Capa 2 — el calculador de importes
    // -----------------------------------------------------------------------------------------

    /**
     * Caso 6 — sin ninguna configuracion, el calculador usa el `iva_negocio` del ticket:
     *  - 'Responsable inscripto' discrimina el IVA;
     *  - 'Monotributista' y 'Exento' NO lo discriminan;
     * y en los tres casos da los mismos importes que el camino con configuracion de esa condicion.
     *
     * 🔴 Lo que fija el `iva == 0` de los dos ultimos es que NUNCA se asume 'Responsable inscripto'
     * por defecto: discriminar IVA de mas en un comprobante es un error fiscal.
     *
     * @test
     */
    public function sin_configuracion_el_calculador_usa_el_iva_negocio_del_ticket()
    {
        // Articulo al 10,5 %, vendido a 1105 con IVA incluido.
        $venta = $this->crear_venta(1105);
        $this->agregar_articulo($venta, 1105, '10.5');

        /** @var array<string, bool> Condicion guardada en el ticket => si tiene que discriminar IVA. */
        $esperado = [
            'Responsable inscripto' => true,
            'Monotributista'        => false,
            'Exento'                => false,
        ];

        foreach ($esperado as $condicion => $discrimina) {

            $sin_config = $this->crear_ticket($venta, [
                'cuit_negocio' => self::CUIT_SIN_CONFIG,
                'iva_negocio'  => $condicion,
            ]);

            $this->assertNull(
                $sin_config->afip_information,
                'ESCENARIO MAL ARMADO si esto falla: con ese cuit no puede haber ninguna configuracion'
            );

            $importes = $this->importes_de_reimpresion($sin_config);

            // La referencia: el mismo ticket con la configuracion de esa condicion cargada.
            $con_config = AfipTicket::find($sin_config->id);
            $con_config->setRelation('afip_information', $this->config_en_memoria($condicion));

            $this->assertEquals(
                $this->importes_de_reimpresion($con_config),
                $importes,
                'con iva_negocio "'.$condicion.'" y sin configuracion, los importes tienen que ser los '.
                'mismos que con una configuracion de esa condicion'
            );

            if ($discrimina) {
                $this->assertEqualsWithDelta(
                    105.00,
                    (float) $importes['iva'],
                    self::DELTA,
                    '"'.$condicion.'" discrimina IVA (literal: 1105 - 1105 / 1,105)'
                );
                $this->assertEqualsWithDelta(
                    105.00,
                    (float) $importes['ivas']['10']['Importe'],
                    self::DELTA,
                    'y lo tiene que desglosar en el bucket del 10,5 %'
                );
            } else {
                $this->assertEqualsWithDelta(
                    0.00,
                    (float) $importes['iva'],
                    self::DELTA,
                    'EL DEFECTO EN UNA LINEA: "'.$condicion.'" NO discrimina IVA. Si da distinto de 0, el '.
                    'calculador esta asumiendo Responsable inscripto por defecto'
                );
            }

            $this->assertEqualsWithDelta(
                1105.00,
                (float) $importes['total'],
                self::DELTA,
                'el total del comprobante no cambia con la condicion: mueve el desglose, nunca la plata'
            );
        }
    }

    /**
     * Caso 7 — sin configuracion Y sin `iva_negocio`, el calculador corta con un mensaje legible que
     * nombra el ticket. No con el "non-object" de antes, y sin asumir ninguna condicion.
     *
     * @test
     */
    public function sin_configuracion_ni_iva_negocio_corta_con_un_mensaje_legible()
    {
        $venta = $this->crear_venta(1105);
        $this->agregar_articulo($venta, 1105, '10.5');

        $ticket = $this->crear_ticket($venta, [
            'cuit_negocio' => self::CUIT_SIN_CONFIG,
            'iva_negocio'  => null,
            'cbte_numero'  => '00777',
        ]);

        /** @var \Throwable|null $excepcion Lo que tiro el calculador. */
        $excepcion = null;
        /** @var array|null $importes Lo que devolvio, si es que devolvio algo. */
        $importes = null;

        try {
            $importes = $this->importes_de_reimpresion($ticket);
        } catch (\Throwable $e) {
            $excepcion = $e;
        }

        $this->assertNull(
            $importes,
            'sin configuracion ni condicion de IVA no se puede saber si discrimina: no puede devolver importes'
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $excepcion,
            'tiene que ser un RuntimeException con mensaje, no el error de PHP por leer una propiedad de null'
        );

        $this->assertStringContainsString(
            'no tiene la configuración fiscal del emisor ni su condición de IVA guardada',
            $excepcion->getMessage(),
            'el mensaje tiene que decir que es lo que le falta al ticket'
        );

        $this->assertStringContainsString(
            '00777',
            $excepcion->getMessage(),
            'el mensaje tiene que nombrar el ticket, o el que lo lee no sabe cual arreglar'
        );

        $this->assertStringNotContainsString(
            'non-object',
            $excepcion->getMessage(),
            'el "non-object" era justamente el mensaje que no decia nada'
        );
    }

    /**
     * Caso 6 bis — el `iva_negocio` es TEXTO LIBRE y los tickets viejos lo traen con otra escritura.
     * El barrido de produccion del 23/9/2026 encontro 'Responsable Inscripto' (otra mayuscula) y
     * un 'RRII'. Comparado por literal, el primero se trataria como "no responsable inscripto" y
     * el comprobante saldria SIN discriminar el IVA.
     *
     *  - Sin distinguir mayusculas ni espacios de los bordes, 'Responsable Inscripto' DISCRIMINA
     *    igual que 'Responsable inscripto'.
     *  - Lo que no es ninguna de las tres condiciones ('RRII') corta con un mensaje que lo nombra:
     *    no se adivina.
     *
     * @test
     */
    public function el_iva_negocio_se_lee_sin_distinguir_mayusculas_y_lo_desconocido_no_se_adivina()
    {
        $venta = $this->crear_venta(1105);
        $this->agregar_articulo($venta, 1105, '10.5');

        foreach (['Responsable Inscripto', 'RESPONSABLE INSCRIPTO', '  responsable inscripto '] as $texto) {

            $ticket = $this->crear_ticket($venta, [
                'cuit_negocio' => self::CUIT_SIN_CONFIG,
                'iva_negocio'  => $texto,
            ]);

            $importes = $this->importes_de_reimpresion($ticket);

            $this->assertEqualsWithDelta(
                105.00,
                (float) $importes['iva'],
                self::DELTA,
                'con iva_negocio "'.$texto.'" tiene que discriminar el IVA como cualquier Responsable inscripto'
            );
        }

        foreach (['monotributista', 'EXENTO'] as $texto) {

            $ticket = $this->crear_ticket($venta, [
                'cuit_negocio' => self::CUIT_SIN_CONFIG,
                'iva_negocio'  => $texto,
            ]);

            $this->assertEqualsWithDelta(
                0.00,
                (float) $this->importes_de_reimpresion($ticket)['iva'],
                self::DELTA,
                'con iva_negocio "'.$texto.'" NO discrimina IVA'
            );
        }

        $desconocido = $this->crear_ticket($venta, [
            'cuit_negocio' => self::CUIT_SIN_CONFIG,
            'iva_negocio'  => 'RRII',
            'cbte_numero'  => '00888',
        ]);

        /** @var \Throwable|null $excepcion Lo que tiro el calculador. */
        $excepcion = null;

        try {
            $this->importes_de_reimpresion($desconocido);
        } catch (\Throwable $e) {
            $excepcion = $e;
        }

        $this->assertInstanceOf(
            RuntimeException::class,
            $excepcion,
            'un iva_negocio que no se reconoce ("RRII") no se adivina: tiene que cortar, no devolver importes'
        );

        $this->assertStringContainsString('"RRII"', $excepcion->getMessage(), 'el mensaje nombra el texto que no reconocio');
        $this->assertStringContainsString('00888', $excepcion->getMessage(), 'y nombra el ticket');
    }

    // -----------------------------------------------------------------------------------------
    // El PDF
    // -----------------------------------------------------------------------------------------

    /**
     * Caso 9 — el ticket de 80mm (`SaleTicketPdf`) de un ticket SIN vinculo, con su configuracion
     * resoluble, se genera sin excepcion, es un PDF de verdad, e imprime el bloque fiscal.
     *
     * 🔴 Con el codigo viejo, `afipInformation()` salteaba el bloque en silencio (guarda con
     * `is_null`) y el pie reventaba en `iva_discriminado()` -> `AfipImportesCalculator`.
     *
     * ⚠️ POR QUE UN ESPIA: el constructor real de `SaleTicketPdf` termina en `Output(); exit;` y
     * mataria el proceso de PHPUnit en el acto, sin resumen y sin rojo. El espia arma el PDF con
     * los MISMOS pasos del constructor real (mismo `Header()`, `items()` y `Footer()`, que son lo
     * que se mide) y lo devuelve como texto con `Output('S')` en vez de mandarlo a la salida. Con
     * la compresion apagada el texto de las celdas queda legible en el binario.
     *
     * Dos cosas se saltean a proposito, porque son de red y no del defecto: el QR (le pega a
     * api.qrserver.com) y el logo (baja una imagen de una URL).
     *
     * Se declara ADENTRO del metodo por lo mismo que en tests/Feature/ForzarTotal/7: los archivos de
     * `Pdf/` hacen `require` de fpdf y no pueden convivir dos en un mismo proceso.
     *
     * @test
     */
    public function el_ticket_de_80mm_de_un_ticket_sin_vinculo_se_genera_y_trae_el_bloque_fiscal()
    {
        $this->crear_config('Responsable inscripto', [
            'razon_social' => 'zz Razon social de la reimpresion',
        ]);

        $venta = $this->crear_venta(1105);
        $this->agregar_articulo($venta, 1105, '10.5');

        $ticket = $this->crear_ticket($venta);

        $this->assertNull($ticket->afip_information_id, 'ESCENARIO MAL ARMADO: el ticket tiene que llegar sin vinculo');

        $espia = new class(Sale::find($venta->id), $ticket) extends SaleTicketPdf {

            /** El PDF armado, como texto. */
            public $pdf_generado = null;

            public function __construct($sale, $afip_ticket = null)
            {
                $this->line_height = 5;
                $this->user = UserHelper::getFullModel();
                // Sin logo: bajarlo es una llamada de red y no es lo que se esta midiendo.
                $this->user->image_url = null;
                $this->sale = $sale;
                $this->afip_ticket = $afip_ticket;
                $this->x_incial = 4;
                $this->ancho = $this->user->sale_ticket_width;
                $this->cell_ancho = $this->ancho - 8;
                $this->name_font_size = 12;
                $this->price_font_size = 10;

                // El constructor de FPDF, no el de SaleTicketPdf: ese termina en `exit`.
                \FPDF::__construct('P', 'mm', [$this->ancho, $this->getPdfHeight()]);
                $this->SetCompression(false);
                $this->SetAutoPageBreak(false);
                $this->b = 0;

                $this->AddPage();
                $this->items();

                $this->pdf_generado = $this->Output('S');
            }

            /** El QR de ARCA le pega a un servicio externo: fuera de este test. */
            public function qr()
            {
            }
        };

        $pdf = $espia->pdf_generado;

        $this->assertIsString($pdf);
        $this->assertSame(
            '%PDF',
            substr($pdf, 0, 4),
            'EL DEFECTO EN UNA LINEA: reimprimir un ticket sin afip_information_id tiene que generar un '.
            'PDF, no reventar'
        );

        $this->assertStringContainsString(
            'Cuit: '.self::CUIT,
            $pdf,
            'el bloque fiscal tiene que salir con el cuit de la configuracion resuelta (con el codigo '.
            'viejo se salteaba en silencio)'
        );

        $this->assertStringContainsString(
            'Razon social: zz Razon social de la reimpresion',
            $pdf,
            'y con la razon social de la configuracion resuelta'
        );

        $this->assertStringContainsString(
            'Punto de venta: '.self::PUNTO_VENTA,
            $pdf,
            'y con su punto de venta'
        );

        $this->assertStringContainsString(
            'Imp Neto Gravado',
            $pdf,
            'el pie tiene que discriminar el IVA: el emisor es Responsable inscripto'
        );
    }
}
