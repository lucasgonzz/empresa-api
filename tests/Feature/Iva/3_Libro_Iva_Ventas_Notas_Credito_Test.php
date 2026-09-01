<?php

namespace Tests\Feature\Iva;

use App\Http\Controllers\AfipController;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\Discount;
use App\Models\Iva;
use App\Models\IvaCondition;
use App\Models\NotaCreditoDescription;
use App\Models\Sale;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\EmpresaTestCase;

/**
 * Los importes con los que el Libro IVA Ventas y los TXT de ARCA declaran una NOTA DE CREDITO
 * tienen que armarse con las mismas cinco partes con las que se le facturo la nota de credito a
 * ARCA.
 *
 * Por que existe este archivo: hasta el 1/9/2026 `AfipController::get_importes()` armaba el
 * `AfipHelper` de una nota de credito pasandole SOLO `articles` — sin servicios, sin las lineas de
 * descripcion libre y sin el modelo de la nota de credito (que aporta sus descuentos y recargos) —,
 * mientras que `AfipNotaCreditoHelper::interno()`, que es el que emite el comprobante ante ARCA, le
 * pasa las cinco. Los tres consumidores de `get_importes()` son justamente los archivos que el
 * contador sube a ARCA: `exportAlicuotasTxt()`, `exportComprobantesTxt()` (via
 * `get_cantidad_iva()`) y el PDF del Libro IVA Ventas. El efecto es SUBDECLARAR.
 *
 * 🔴 Cada test de esta clase arma tambien la construccion VIEJA a mano y comprueba que da MENOS.
 * Sin esa mitad, un verde no probaria nada: si las dos formas dieran igual, el escenario estaria
 * mal armado y las aserciones pasarian aunque el arreglo se revirtiera.
 *
 * 🔴 No toca la red. Se prueba `AfipController::get_importes()` y la prioridad del snapshot
 * fiscal, no la emision: `interno()` es el unico que habla con el webservice de ARCA y no se
 * invoca en ningun lado de este archivo.
 *
 * Rango de fechas propio: JULIO DE 2015. Verificado contra el resto de la suite (`Reportes`,
 * `Devoluciones`) — los meses en uso van de 2016 a 2021, asi que 2015 esta libre. Estos tests no
 * filtran por fecha (llaman al metodo directo, no al endpoint), pero las filas quedan igual fuera
 * de cualquier rango que mire otro test si alguna vez el rollback no alcanzara.
 *
 * @group iva-notas-credito
 */
class Libro_Iva_Ventas_Notas_Credito_Test extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca assertSame sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Fecha de las filas sembradas por esta clase. Ver el bloque de arriba: julio de 2015 esta
     * libre en toda la suite.
     */
    const FECHA = '2015-07-15 10:00:00';

    /**
     * Ids de lo sembrado por cada test, para borrarlo en el tearDown en orden inverso al de
     * creacion. El rollback de `DatabaseTransactions` ya deberia alcanzar (la base es InnoDB, lo
     * verifica `EmpresaTestCase::setUp()`), pero el borrado explicito es el mismo criterio que usa
     * `Tests\Feature\Devoluciones\Iva_De_La_Nota_De_Credito_Facturada_Test` y no depende de eso.
     *
     * @var array<string,array<int,int>>
     */
    protected $sembrado = [
        'afip_tickets'              => [],
        'nota_credito_descriptions' => [],
        'current_acounts'           => [],
        'sales'                     => [],
        'services'                  => [],
        'discounts'                 => [],
        'afip_information'          => [],
    ];

    /**
     * Borra lo sembrado en orden inverso al de creacion. Corre siempre, incluso si una asercion
     * corto el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->sembrado['afip_tickets'])) {
            // forceDelete y no delete: AfipTicket usa SoftDeletes y un borrado normal dejaria la
            // fila viva con deleted_at puesto.
            AfipTicket::whereIn('id', $this->sembrado['afip_tickets'])->forceDelete();
        }

        if (count($this->sembrado['nota_credito_descriptions'])) {
            NotaCreditoDescription::whereIn('id', $this->sembrado['nota_credito_descriptions'])->delete();
        }

        foreach ($this->sembrado['current_acounts'] as $current_acount_id) {
            $current_acount = CurrentAcount::find($current_acount_id);

            if (is_null($current_acount)) {
                continue;
            }

            $current_acount->articles()->detach();
            $current_acount->services()->detach();
            $current_acount->discounts()->detach();
            $current_acount->surchages()->detach();
            $current_acount->delete();
        }

        if (count($this->sembrado['sales'])) {
            Sale::whereIn('id', $this->sembrado['sales'])->forceDelete();
        }

        if (count($this->sembrado['services'])) {
            Service::whereIn('id', $this->sembrado['services'])->delete();
        }

        if (count($this->sembrado['discounts'])) {
            Discount::whereIn('id', $this->sembrado['discounts'])->delete();
        }

        if (count($this->sembrado['afip_information'])) {
            AfipInformation::whereIn('id', $this->sembrado['afip_information'])->delete();
        }

        parent::tearDown();
    }

    /**
     * Usuario duenio del fixture de testing (el mismo que autentica `EmpresaTestCase::setUp()`).
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Configuracion fiscal propia del test, Responsable inscripto.
     *
     * Se crea la propia en vez de usar la del fixture por el mismo motivo que en
     * `Devoluciones\Iva_De_La_Nota_De_Credito_Facturada_Test`: si maniana el seeder le cambia el
     * punto de venta o la condicion de IVA al fixture, estos tests no se enteran. La condicion
     * importa mucho aca: `AfipImportesCalculator::calculate()` solo desglosa por alicuota cuando el
     * emisor es Responsable inscripto, que es el unico caso donde se puede subdeclarar una base
     * imponible.
     *
     * @return \App\Models\AfipInformation
     */
    protected function configuracion_fiscal_responsable_inscripto()
    {
        return $this->configuracion_fiscal('Responsable inscripto');
    }

    /**
     * Configuracion fiscal propia del test, MONOTRIBUTISTA.
     *
     * Es la otra rama de `AfipImportesCalculator::calculate()`: cualquier emisor que no sea
     * "Responsable inscripto" cae en `calculate_for_no_responsable_inscripto()`, que no desglosa
     * por alicuota y arma el total de otra forma. El catalogo `iva_conditions` de la base de
     * testing tiene cuatro: Responsable inscripto, Monotributista, Consumidor final y Exento.
     * Se elige Monotributista y no Exento a proposito, porque `calculate()` le da a "Exento" un
     * tratamiento extra propio y eso mezclaria dos cosas en el mismo test.
     *
     * @return \App\Models\AfipInformation
     */
    protected function configuracion_fiscal_monotributista()
    {
        return $this->configuracion_fiscal('Monotributista');
    }

    /**
     * Crea la `AfipInformation` del test con la condicion de IVA pedida, resuelta POR NOMBRE.
     *
     * Por nombre y no por id porque es exactamente asi como la lee
     * `AfipImportesCalculator::calculate()` (`afip_information->iva_condition->name`): si el nombre
     * del catalogo cambiara, el test tiene que caerse con un mensaje claro y no medir otra rama.
     *
     * @param  string $nombre_condicion Nombre exacto en la tabla `iva_conditions`.
     * @return \App\Models\AfipInformation
     */
    protected function configuracion_fiscal($nombre_condicion)
    {
        $iva_condition = IvaCondition::where('name', $nombre_condicion)->first();

        if (is_null($iva_condition)) {
            $this->fail(
                'No existe la condicion de IVA "'.$nombre_condicion.'" en la base de testing. '.
                'AfipImportesCalculator::calculate() lee `afip_information->iva_condition->name` para '.
                'decidir si desglosa por alicuota, asi que sin ella estos tests no miden nada. Es un '.
                'problema del fixture para reportar, no algo para saltear.'
            );
        }

        $afip_information = AfipInformation::create([
            'user_id'                => $this->usuario_de_testing()->id,
            'iva_condition_id'       => $iva_condition->id,
            'razon_social'           => 'Comercio de test libro iva',
            'cuit'                   => '20000000000',
            'punto_venta'            => 99,
            // En 0 a proposito: marca homologacion. Ningun test de esta clase emite nada, pero un
            // fixture de testing no puede quedar marcado como produccion.
            'afip_ticket_production' => 0,
        ]);

        $this->sembrado['afip_information'][] = $afip_information->id;

        return $afip_information;
    }

    /**
     * Una venta terminada con su factura ya autorizada por ARCA.
     *
     * @param  \App\Models\AfipInformation $afip_information
     * @param  float $total
     * @return array{sale: \App\Models\Sale, afip_ticket: \App\Models\AfipTicket}
     */
    protected function venta_facturada($afip_information, $total)
    {
        $venta = Sale::create([
            'user_id'    => $this->usuario_de_testing()->id,
            'moneda_id'  => 1,
            'total'      => $total,
            'terminada'  => 1,
            'created_at' => self::FECHA,
        ]);

        $this->sembrado['sales'][] = $venta->id;

        $afip_ticket = AfipTicket::create([
            'sale_id'             => $venta->id,
            'afip_information_id' => $afip_information->id,
            'punto_venta'         => 99,
            'cbte_tipo'           => '1',
            'cbte_letra'          => 'A',
            'cbte_numero'         => (string) $venta->id,
            'resultado'           => 'A',
            'importe_total'       => $total,
            'cuit_negocio'        => '20000000000',
            'cae'                 => '00000000000000',
            'afip_fecha_emision'  => '2015-07-15',
            'created_at'          => self::FECHA,
        ]);

        $this->sembrado['afip_tickets'][] = $afip_ticket->id;

        return ['sale' => $venta, 'afip_ticket' => $afip_ticket];
    }

    /**
     * El movimiento de cuenta corriente de la nota de credito, mas su comprobante autorizado.
     *
     * El comprobante nace con `sale_id` en NULL y `nota_credito_id` apuntando al movimiento: es
     * exactamente la forma con la que lo crea `AfipNotaCreditoHelper::create_afip_ticket()`, y es
     * la que hace que `get_importes()` entre por la rama del `else if`.
     *
     * @param  \App\Models\AfipInformation $afip_information
     * @param  \App\Models\Sale $venta
     * @param  \App\Models\AfipTicket $afip_ticket_venta
     * @param  float $haber
     * @return array{nota_credito: \App\Models\CurrentAcount, afip_ticket: \App\Models\AfipTicket}
     */
    protected function nota_credito_facturada($afip_information, $venta, $afip_ticket_venta, $haber)
    {
        $nota_credito = CurrentAcount::create([
            'detalle'     => 'Nota Credito de test libro iva',
            'description' => 'Devolucion de test',
            'haber'       => $haber,
            'status'      => 'nota_credito',
            'sale_id'     => $venta->id,
            'user_id'     => $this->usuario_de_testing()->id,
            'moneda_id'   => 1,
            'created_at'  => self::FECHA,
        ]);

        $this->sembrado['current_acounts'][] = $nota_credito->id;

        $afip_ticket = AfipTicket::create([
            'afip_information_id'  => $afip_information->id,
            'punto_venta'          => 99,
            'nota_credito_id'      => $nota_credito->id,
            'sale_nota_credito_id' => $venta->id,
            'sale_afip_ticket_id'  => $afip_ticket_venta->id,
            'cbte_tipo'            => '3',
            'cbte_letra'           => 'A',
            'cbte_numero'          => (string) ($venta->id + 1),
            'resultado'            => 'A',
            'importe_total'        => $haber,
            'cuit_negocio'         => '20000000000',
            'cae'                  => '00000000000001',
            'afip_fecha_emision'   => '2015-07-15',
            'created_at'           => self::FECHA,
        ]);

        $this->sembrado['afip_tickets'][] = $afip_ticket->id;

        return ['nota_credito' => $nota_credito, 'afip_ticket' => $afip_ticket];
    }

    /**
     * Cuelga de la nota de credito un articulo devuelto, con su alicuota historica en el pivot.
     *
     * La alicuota va como texto crudo ('21', no '21.00') porque asi la compara
     * `AfipItemCalculator::get_importe_iva()`: `(string) $pivot->iva_percentage == (string) $iva`.
     *
     * @param  \App\Models\CurrentAcount $nota_credito
     * @param  string $nombre_articulo Nombre del articulo del fixture.
     * @param  float $price Precio unitario CON IVA incluido, como en todo el sistema.
     * @param  float $amount
     * @param  string $iva_percentage
     * @return void
     */
    protected function agregar_articulo($nota_credito, $nombre_articulo, $price, $amount, $iva_percentage = '21')
    {
        $articulo = $this->articulo($nombre_articulo);

        if (is_null($articulo)) {
            $this->fail('No existe el articulo "'.$nombre_articulo.'" en el fixture de testing.');
        }

        $nota_credito->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $price,
            'iva_percentage' => $iva_percentage,
        ]);
    }

    /**
     * Cuelga de la nota de credito una linea de descripcion libre: plata que no viene de ningun
     * articulo devuelto y que era exactamente lo que se perdia.
     *
     * @param  \App\Models\CurrentAcount $nota_credito
     * @param  string $notes
     * @param  float $price Precio CON IVA incluido.
     * @param  string $iva_percentage Alicuota del catalogo `ivas`.
     * @return \App\Models\NotaCreditoDescription
     */
    protected function agregar_descripcion($nota_credito, $notes, $price, $iva_percentage = '21')
    {
        $iva = Iva::where('percentage', $iva_percentage)->first();

        if (is_null($iva)) {
            $this->fail('No existe la alicuota "'.$iva_percentage.'" en la tabla ivas del fixture.');
        }

        $description = NotaCreditoDescription::create([
            'current_acount_id' => $nota_credito->id,
            'notes'             => $notes,
            'price'             => $price,
            'iva_id'            => $iva->id,
        ]);

        $this->sembrado['nota_credito_descriptions'][] = $description->id;

        return $description;
    }

    /**
     * Llama al metodo real que arma los importes con los que se exporta a ARCA.
     *
     * @param  \App\Models\AfipTicket $afip_ticket
     * @return array
     */
    protected function importes_del_libro_iva($afip_ticket)
    {
        $afip_controller = new AfipController();

        return $afip_controller->get_importes(AfipTicket::find($afip_ticket->id));
    }

    /**
     * Reproduce a mano la construccion VIEJA de `get_importes()` para una nota de credito: solo
     * `articles`, servicios en `[]`, sin descripciones y sin el modelo de la nota de credito.
     *
     * 🔴 Se arma aca adentro y NO se llama a `get_importes()`: despues de esta mision ese metodo ya
     * es la forma completa, asi que llamarlo daria las dos ramas iguales y el test no mediria nada.
     * Es el mismo cuidado que pide el comando de auditoria.
     *
     * @param  \App\Models\AfipTicket $afip_ticket Comprobante de la nota de credito.
     * @return array
     */
    protected function importes_de_la_construccion_vieja($afip_ticket)
    {
        $afip_ticket = AfipTicket::find($afip_ticket->id);

        $afip_helper = new AfipHelper(
            $afip_ticket,
            $afip_ticket->nota_credito->articles,
            [],
            null,
            $afip_ticket->sale_nota_credito
        );

        return $afip_helper->getImportes();
    }

    /**
     * Test 1 — EL QUE ATRAPA EL BUG. Una nota de credito con una linea de descripcion libre.
     *
     * Escenario, con numeros calculados a mano (los precios del sistema son CON IVA incluido):
     *   - articulo Pinza, alicuota 21 %, 1 unidad a 1210,00
     *       base = 1210 / 1,21 = 1000,00   IVA = 1000 * 0,21 = 210,00
     *   - descripcion libre "Reintegro por flete", alicuota 21 %, 605,00
     *       base =  605 / 1,21 =  500,00   IVA =  500 * 0,21 = 105,00
     *
     *   gravado = 1500,00   IVA = 315,00   total = 1815,00
     *
     * La construccion vieja (solo `articles`) da 1000,00 / 210,00 / 1210,00: se come los 605,00 de
     * la descripcion enteros. Eso es lo que salia en el archivo de alicuotas que el contador le
     * sube a ARCA, contra un archivo de comprobantes que declara el total real.
     *
     * @group iva-notas-credito
     * @test
     */
    public function una_nota_de_credito_con_descripciones_libres_declara_esas_lineas_en_el_libro_iva_ventas()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 1815);

        $this->agregar_articulo($nc['nota_credito'], 'Pinza', 1210, 1);
        $this->agregar_descripcion($nc['nota_credito'], 'Reintegro por flete', 605);

        $importes = $this->importes_del_libro_iva($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            1500.00,
            (float) $importes['gravado'],
            self::DELTA,
            'gravado de la nota de credito (literal: 1000,00 del articulo + 500,00 de la descripcion libre)'
        );

        $this->assertEqualsWithDelta(
            315.00,
            (float) $importes['iva'],
            self::DELTA,
            'IVA de la nota de credito (literal: 210,00 del articulo + 105,00 de la descripcion libre)'
        );

        $this->assertEqualsWithDelta(
            1815.00,
            (float) $importes['total'],
            self::DELTA,
            'total de la nota de credito (literal: 1500,00 + 315,00). Es el numero que tiene que cerrar contra el archivo de comprobantes'
        );

        $this->assertEqualsWithDelta(
            1500.00,
            (float) $importes['ivas']['21']['BaseImp'],
            self::DELTA,
            'base imponible de la alicuota 21 % (es el renglon literal del archivo de alicuotas)'
        );

        $this->assertEqualsWithDelta(
            315.00,
            (float) $importes['ivas']['21']['Importe'],
            self::DELTA,
            'IVA de la alicuota 21 % (renglon literal del archivo de alicuotas)'
        );

        /*
         * La otra mitad del test: la construccion vieja tiene que dar MENOS. Sin esto, un verde no
         * probaria nada — si las dos formas dieran igual, el escenario estaria mal armado.
         */
        $viejos = $this->importes_de_la_construccion_vieja($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            1000.00,
            (float) $viejos['gravado'],
            self::DELTA,
            'la construccion vieja (solo articles) se comia la descripcion libre entera: gravado 1000,00'
        );

        $this->assertEqualsWithDelta(
            210.00,
            (float) $viejos['iva'],
            self::DELTA,
            'la construccion vieja (solo articles) declaraba 210,00 de IVA en vez de 315,00'
        );

        $this->assertLessThan(
            (float) $importes['gravado'],
            (float) $viejos['gravado'],
            'ESCENARIO MAL ARMADO si esto falla: la forma vieja tiene que dar MENOS gravado que la nueva, o el test no esta midiendo el bug'
        );

        $this->assertLessThan(
            (float) $importes['iva'],
            (float) $viejos['iva'],
            'ESCENARIO MAL ARMADO si esto falla: la forma vieja tiene que dar MENOS IVA que la nueva'
        );

        $this->assertEqualsWithDelta(
            605.00,
            (float) $importes['total'] - (float) $viejos['total'],
            self::DELTA,
            'lo que se subdeclaraba es exactamente el precio de la descripcion libre (605,00)'
        );
    }

    /**
     * Test 2 — una nota de credito con SERVICIOS.
     *
     * Un servicio se liquida siempre al 21 % (`AfipItemCalculator::get_combo_iva()`), asi que con un
     * servicio de 1210,00 la cuenta es la misma que la del articulo:
     *   base = 1210 / 1,21 = 1000,00   IVA = 210,00
     *
     * Con el articulo de 1210,00: gravado 2000,00, IVA 420,00, total 2420,00. La forma vieja dejaba
     * `services` en `[]` y declaraba la mitad.
     *
     * @group iva-notas-credito
     * @test
     */
    public function una_nota_de_credito_con_servicios_los_declara_en_el_libro_iva_ventas()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 2420);

        $this->agregar_articulo($nc['nota_credito'], 'Pinza', 1210, 1);

        $servicio = Service::create([
            'user_id' => $this->usuario_de_testing()->id,
            'name'    => 'Mano de obra devuelta (test libro iva)',
            'price'   => 1210,
        ]);
        $this->sembrado['services'][] = $servicio->id;

        $nc['nota_credito']->services()->attach($servicio->id, [
            'amount' => 1,
            'price'  => 1210,
        ]);

        $importes = $this->importes_del_libro_iva($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            2000.00,
            (float) $importes['gravado'],
            self::DELTA,
            'gravado de la nota de credito (literal: 1000,00 del articulo + 1000,00 del servicio)'
        );

        $this->assertEqualsWithDelta(
            420.00,
            (float) $importes['iva'],
            self::DELTA,
            'IVA de la nota de credito (literal: 210,00 del articulo + 210,00 del servicio)'
        );

        $this->assertEqualsWithDelta(
            2420.00,
            (float) $importes['total'],
            self::DELTA,
            'total de la nota de credito (literal: 2000,00 + 420,00)'
        );

        $viejos = $this->importes_de_la_construccion_vieja($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            1000.00,
            (float) $viejos['gravado'],
            self::DELTA,
            'la construccion vieja dejaba services en [] y declaraba solo el articulo'
        );

        $this->assertLessThan(
            (float) $importes['gravado'],
            (float) $viejos['gravado'],
            'ESCENARIO MAL ARMADO si esto falla: la forma vieja tiene que dar MENOS gravado que la nueva'
        );

        $this->assertEqualsWithDelta(
            1210.00,
            (float) $importes['total'] - (float) $viejos['total'],
            self::DELTA,
            'lo que se subdeclaraba es exactamente el importe del servicio (1210,00)'
        );
    }

    /**
     * Test 3 — el DESCUENTO PROPIO de la nota de credito tiene que afectar los importes.
     *
     * Sin el modelo de la nota de credito, `AfipItemCalculator::get_article_price_with_discounts()`
     * cae a `$sale->discounts` (los descuentos de la VENTA, que en este escenario no existen) y el
     * descuento de la nota de credito no se aplica en ningun lado.
     *
     * Escenario: articulo de 1210,00 al 21 % y un descuento del 10 % propio de la nota de credito.
     *   precio con descuento = 1210 - 121 = 1089,00
     *   base = 1089 / 1,21 = 900,00   IVA = 900 * 0,21 = 189,00   total = 1089,00
     *
     * Aca el bug va para el OTRO lado —la forma vieja declaraba de MAS, 1210,00 en vez de 1089,00—,
     * y sigue siendo un numero que no coincide con el comprobante autorizado.
     *
     * @group iva-notas-credito
     * @test
     */
    public function el_descuento_propio_de_la_nota_de_credito_afecta_los_importes_del_libro_iva_ventas()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 1089);

        $this->agregar_articulo($nc['nota_credito'], 'Pinza', 1210, 1);

        $descuento = Discount::create([
            'user_id'    => $this->usuario_de_testing()->id,
            'num'        => 9915,
            'name'       => 'Descuento de nota de credito (test libro iva)',
            'percentage' => 10,
        ]);
        $this->sembrado['discounts'][] = $descuento->id;

        $nc['nota_credito']->discounts()->attach($descuento->id, ['percentage' => 10]);

        $importes = $this->importes_del_libro_iva($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            900.00,
            (float) $importes['gravado'],
            self::DELTA,
            'gravado con el descuento propio de la NC aplicado (literal: 1089,00 / 1,21)'
        );

        $this->assertEqualsWithDelta(
            189.00,
            (float) $importes['iva'],
            self::DELTA,
            'IVA con el descuento propio de la NC aplicado (literal: 900,00 * 0,21)'
        );

        $this->assertEqualsWithDelta(
            1089.00,
            (float) $importes['total'],
            self::DELTA,
            'total con el descuento propio de la NC aplicado (literal: 900,00 + 189,00)'
        );

        $viejos = $this->importes_de_la_construccion_vieja($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            1000.00,
            (float) $viejos['gravado'],
            self::DELTA,
            'sin el modelo de la NC el descuento no se aplicaba: la forma vieja declaraba el articulo entero'
        );

        $this->assertNotEqualsWithDelta(
            (float) $importes['total'],
            (float) $viejos['total'],
            self::DELTA,
            'ESCENARIO MAL ARMADO si esto falla: las dos formas tienen que dar distinto, o el test no mide nada'
        );

        $this->assertEqualsWithDelta(
            121.00,
            (float) $viejos['total'] - (float) $importes['total'],
            self::DELTA,
            'la diferencia es exactamente el 10 % de descuento de la nota de credito (121,00)'
        );
    }

    /**
     * Test 4 — EL SNAPSHOT GANA sobre el recalculo.
     *
     * ⚠️ Hoy NINGUNA nota de credito tiene snapshot: `AfipNotaCreditoHelper` no lo persiste. Esta
     * mision estuvo a punto de agregarlo y se dio marcha atras, porque el snapshot emite la clave
     * de alicuota `'10.5'` mientras que el recalculo emite `'10'`, y el PDF del Libro IVA Ventas
     * lee `['10']`: darle snapshot a las notas de credito les haria DESAPARECER el renglon de
     * 10,5 % del mismo reporte que esta mision viene a arreglar. Ver el informe.
     *
     * El test se queda igual porque la precedencia del snapshot ya existe y ya corre para las
     * facturas de venta: es el contrato de `AfipImportesResolver::resolve()`, y el dia que se
     * unifiquen las claves y las notas de credito pasen a tener snapshot, este test es el que
     * tiene que seguir en verde.
     *
     * Con `imp_total_enviado` e `iva_detalle_enviado_json` cargados, `get_importes()` tiene que
     * devolver lo que se le declaro a ARCA y NO el recalculo, aunque los dos difieran. Los numeros
     * del snapshot se eligen a proposito distintos de todo lo que podria salir del recalculo del
     * escenario (que daria 1000,00 / 210,00 / 1210,00): si el metodo recalculara, se veria.
     *
     * @group iva-notas-credito
     * @test
     */
    public function el_snapshot_fiscal_persistido_le_gana_al_recalculo()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 1210);

        $this->agregar_articulo($nc['nota_credito'], 'Pinza', 1210, 1);

        // Sin snapshot, el recalculo da 1000,00 / 210,00 / 1210,00. Se verifica primero para que la
        // segunda mitad del test no pueda pasar por casualidad.
        $sin_snapshot = $this->importes_del_libro_iva($nc['afip_ticket']);

        $this->assertEqualsWithDelta(1210.00, (float) $sin_snapshot['total'], self::DELTA, 'sin snapshot se recalcula: total 1210,00');

        $nc['afip_ticket']->update([
            'imp_total_enviado'        => 8712.00,
            'imp_tot_conc_enviado'     => 0,
            'imp_neto_enviado'         => 7200.00,
            'imp_op_ex_enviado'        => 0,
            'imp_iva_enviado'          => 1512.00,
            'iva_detalle_enviado_json' => [
                ['Id' => 5, 'BaseImp' => 7200.00, 'Importe' => 1512.00],
            ],
        ]);

        $importes = $this->importes_del_libro_iva($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            8712.00,
            (float) $importes['total'],
            self::DELTA,
            'con snapshot, el total tiene que salir de imp_total_enviado (8712,00) y no del recalculo (1210,00)'
        );

        $this->assertEqualsWithDelta(
            7200.00,
            (float) $importes['gravado'],
            self::DELTA,
            'con snapshot, el gravado tiene que salir de imp_neto_enviado'
        );

        $this->assertEqualsWithDelta(
            1512.00,
            (float) $importes['iva'],
            self::DELTA,
            'con snapshot, el IVA tiene que salir de imp_iva_enviado'
        );

        $this->assertEqualsWithDelta(
            7200.00,
            (float) $importes['ivas']['21']['BaseImp'],
            self::DELTA,
            'el desglose por alicuota tiene que salir del iva_detalle_enviado_json (Id 5 = 21 %)'
        );

        $this->assertEqualsWithDelta(
            1512.00,
            (float) $importes['ivas']['21']['Importe'],
            self::DELTA,
            'el IVA de la alicuota 21 % tiene que salir del iva_detalle_enviado_json'
        );
    }

    /**
     * Test 5 — LA FACTURA DE VENTA NO SE ROMPE. El `else if` no puede haber cambiado la otra rama.
     *
     * Se comprueba de las dos formas: contra un numero literal calculado a mano, y contra la
     * construccion de la rama de venta armada aparte (que es identica antes y despues de esta
     * mision, porque el arreglo solo toco el `else if`).
     *
     * Escenario: venta con 2 unidades de un articulo de 1210,00 al 21 %.
     *   base = 2 * (1210 / 1,21) = 2000,00   IVA = 420,00   total = 2420,00
     *
     * @group iva-notas-credito
     * @test
     */
    public function la_factura_de_una_venta_sigue_dando_lo_mismo_que_antes()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 2420);

        $pinza = $this->articulo('Pinza');

        $venta['sale']->articles()->attach($pinza->id, [
            'amount'         => 2,
            'price'          => 1210,
            'iva_percentage' => '21',
        ]);

        $importes = $this->importes_del_libro_iva($venta['afip_ticket']);

        $this->assertEqualsWithDelta(
            2000.00,
            (float) $importes['gravado'],
            self::DELTA,
            'gravado de la factura de venta (literal: 2 x 1210,00 / 1,21)'
        );

        $this->assertEqualsWithDelta(
            420.00,
            (float) $importes['iva'],
            self::DELTA,
            'IVA de la factura de venta (literal: 2000,00 * 0,21)'
        );

        $this->assertEqualsWithDelta(
            2420.00,
            (float) $importes['total'],
            self::DELTA,
            'total de la factura de venta (literal: 2000,00 + 420,00)'
        );

        /*
         * La rama de venta armada aparte, igual que antes del arreglo: articulos en null (los toma
         * de la venta), servicios desde el ticket, sin descripciones y sin modelo de nota de
         * credito. Tiene que dar EXACTAMENTE lo mismo que `get_importes()`.
         */
        $afip_ticket = AfipTicket::find($venta['afip_ticket']->id);

        $rama_de_venta = new AfipHelper(
            $afip_ticket,
            null,
            $afip_ticket->services,
            null,
            $afip_ticket->sale
        );

        $importes_rama_de_venta = $rama_de_venta->getImportes();

        $this->assertEqualsWithDelta(
            (float) $importes_rama_de_venta['gravado'],
            (float) $importes['gravado'],
            self::DELTA,
            'la rama de la factura de venta no puede haber cambiado con el arreglo del else if'
        );

        $this->assertEqualsWithDelta(
            (float) $importes_rama_de_venta['iva'],
            (float) $importes['iva'],
            self::DELTA,
            'la rama de la factura de venta no puede haber cambiado con el arreglo del else if'
        );

        $this->assertEqualsWithDelta(
            (float) $importes_rama_de_venta['total'],
            (float) $importes['total'],
            self::DELTA,
            'la rama de la factura de venta no puede haber cambiado con el arreglo del else if'
        );

        $venta['sale']->articles()->detach();
    }

    /**
     * Test 6 — LA RAMA NO RESPONSABLE INSCRIPTO, que es la que mas cambio con el arreglo.
     *
     * Por que este test existe: `AfipImportesCalculator::calculate_for_no_responsable_inscripto()`
     * tiene una rama que solo corre `if ($afip_helper->nota_credito_model && count($afip_helper->
     * articles) >= 1)`. Hasta el 1/9/2026 `get_importes()` pasaba SIEMPRE `nota_credito_model` en
     * null, asi que para una nota de credito esa rama no corria NUNCA. Ahora corre, y cambia el
     * numero de punta a punta — pero los otros seis tests de esta clase arman todos un emisor
     * Responsable inscripto y ninguno la toca.
     *
     * Escenario: comercio MONOTRIBUTISTA, venta facturada por 5000,00 entera, y una nota de credito
     * que devuelve un solo articulo de 1210,00.
     *
     *   - construccion VIEJA (sin `nota_credito_model`): la rama no corre, el total sale de
     *     `$afip_helper->sale->total` → 5000,00. O sea que el Libro IVA Ventas declaraba la VENTA
     *     ENTERA como si toda ella se hubiera anulado.
     *   - construccion NUEVA (con `nota_credito_model`): la rama corre y arma el total desde el
     *     pivot de los articulos devueltos → 1210,00 * 1 = 1210,00.
     *
     * 🔴 LIMITACION CONOCIDA QUE ESTE TEST FIJA A PROPOSITO: esa rama suma SOLO LOS ARTICULOS.
     * Ignora los servicios y las lineas de descripcion libre, que es exactamente lo que esta mision
     * arreglo para Responsable inscripto. Por eso el escenario cuelga tambien una descripcion de
     * 605,00 y un servicio de 1210,00 y el total sigue dando 1210,00 y no 3025,00. NO se arregla
     * acá: se deja medido para que se vea, y para que el dia que alguien la complete este test se
     * ponga en rojo y lo obligue a actualizar el numero a conciencia.
     *
     * Un emisor no RI tampoco discrimina IVA: `iva` y el desglose por alicuota quedan en cero, y el
     * gravado es el total bruto. Eso ya era asi antes del arreglo.
     *
     * @group iva-notas-credito
     * @test
     */
    public function una_nota_de_credito_de_un_comercio_no_responsable_inscripto_declara_solo_los_articulos_devueltos()
    {
        $afip_information = $this->configuracion_fiscal_monotributista();
        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 1210);

        $this->agregar_articulo($nc['nota_credito'], 'Pinza', 1210, 1);

        // Los dos que la rama no-RI ignora. Estan para que la limitacion quede medida, no de adorno.
        $this->agregar_descripcion($nc['nota_credito'], 'Reintegro por flete', 605);

        $servicio = Service::create([
            'user_id' => $this->usuario_de_testing()->id,
            'name'    => 'Mano de obra devuelta (test libro iva monotributo)',
            'price'   => 1210,
        ]);
        $this->sembrado['services'][] = $servicio->id;

        $nc['nota_credito']->services()->attach($servicio->id, [
            'amount' => 1,
            'price'  => 1210,
        ]);

        $importes = $this->importes_del_libro_iva($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            1210.00,
            (float) $importes['total'],
            self::DELTA,
            'total de la NC de un monotributista: sale del pivot de los articulos devueltos (1210,00 x 1), no del total de la venta'
        );

        $this->assertEqualsWithDelta(
            1210.00,
            (float) $importes['gravado'],
            self::DELTA,
            'un emisor no RI no separa neto de IVA: el gravado es el total bruto'
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $importes['iva'],
            self::DELTA,
            'un emisor no RI no discrimina IVA: `calculate_for_no_responsable_inscripto()` nunca acumula la columna iva'
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $importes['ivas']['21']['BaseImp'],
            self::DELTA,
            'un emisor no RI no lleva desglose por alicuota: los buckets quedan como los dejo default_ivas()'
        );

        /*
         * La limitacion, dicha con un numero. 3025,00 seria el total si la rama sumara tambien la
         * descripcion libre (605,00) y el servicio (1210,00). Hoy NO lo hace.
         */
        $this->assertNotEqualsWithDelta(
            3025.00,
            (float) $importes['total'],
            self::DELTA,
            'LIMITACION CONOCIDA: si esto falla, la rama no-RI empezo a sumar servicios y descripciones libres. No es un bug nuevo: es que alguien completo la rama y hay que actualizar este test a conciencia'
        );

        /*
         * La otra mitad, igual que en los demas tests de la clase: la construccion vieja tiene que
         * dar DISTINTO, o el escenario no esta midiendo la rama.
         */
        $viejos = $this->importes_de_la_construccion_vieja($nc['afip_ticket']);

        $this->assertEqualsWithDelta(
            5000.00,
            (float) $viejos['total'],
            self::DELTA,
            'sin el modelo de la NC la rama no corria y el total salia de sale->total: la VENTA ENTERA (5000,00) declarada como anulada'
        );

        $this->assertEqualsWithDelta(
            3790.00,
            (float) $viejos['total'] - (float) $importes['total'],
            self::DELTA,
            'la diferencia es exactamente lo que la venta tiene de mas que la devolucion (5000,00 - 1210,00)'
        );

        $this->assertLessThan(
            (float) $viejos['total'],
            (float) $importes['total'],
            'ESCENARIO MAL ARMADO si esto falla: en la rama no-RI la forma vieja declaraba de MAS, no de menos, o el test no esta midiendo nada'
        );

        $nc['nota_credito']->services()->detach();
    }

    /**
     * Test 7 — el comando de auditoria encuentra la nota de credito que se exporto subdeclarada y
     * dice de cuanto fue.
     *
     * Es la parte de la mision que contesta la pregunta que el arreglo NO contesta: el codigo
     * corrige lo que se exporte de ahora en mas, pero las DDJJ ya presentadas siguen teniendo el
     * numero viejo. Un comando que devolviera 0 en un escenario donde SI hay diferencia seria peor
     * que no tenerlo, asi que se prueba con el mismo escenario del test 1, cuyo delta esta
     * calculado a mano: 500,00 de base imponible y 105,00 de IVA.
     *
     * Con una vuelta de tuerca: el movimiento de cuenta corriente va con `user_id` en NULL, que es
     * el caso que el scope viejo del comando no veia y el exportador si exporta. Ver el comentario
     * de adentro.
     *
     * @group iva-notas-credito
     * @test
     */
    public function el_comando_de_auditoria_encuentra_la_nota_de_credito_subdeclarada_y_reporta_el_delta()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 1815);

        $this->agregar_articulo($nc['nota_credito'], 'Pinza', 1210, 1);
        $this->agregar_descripcion($nc['nota_credito'], 'Reintegro por flete', 605);

        /*
         * 🔴 EL MOVIMIENTO DE CUENTA CORRIENTE VA CON `user_id` EN NULL, A PROPOSITO. La columna es
         * nullable (verificado en la base de testing) y el PDF del Libro IVA Ventas exporta igual
         * esta nota de credito, porque el se scopea por `sale_nota_credito.user_id`, que si esta.
         * Hasta el 1/9/2026 el comando se scopeaba por `current_acounts.user_id` —el criterio de
         * `ContabilidadRepository`— y una fila asi se exportaba pero NO se auditaba: un agujero
         * silencioso. Si alguien vuelve a poner aquel scope, este test encuentra 0 y se pone rojo.
         */
        $nc['nota_credito']->update(['user_id' => null]);

        // El rango acota la corrida a las filas de este test (julio de 2015), asi que el resumen no
        // puede contaminarse con nada mas que haya en la base.
        Artisan::call('auditar_libro_iva_notas_credito', [
            'company_name' => $this->usuario_de_testing()->company_name,
            '--desde'      => '2015-07-01',
            '--hasta'      => '2015-07-31',
        ]);

        /*
         * Se normalizan los fines de linea antes de mirar la salida: `Artisan::output()` corta con
         * PHP_EOL, que en Windows es \r\n y en el server de Lucas \n. Sin esto, una asercion que
         * mira el final de un renglon pasaria en una maquina y no en la otra.
         */
        $salida = str_replace("\r\n", "\n", Artisan::output());

        /*
         * 🔴 EXACTA, no substring. `assertStringContainsString('periodo: 1')` tambien pasa con
         * "periodo: 12", "periodo: 100" o "periodo: 1734": el test decia "encontro una" y en
         * realidad estaba tapando que el scope se habia llevado media base. El \n ancla el final
         * del renglon.
         */
        $this->assertMatchesRegularExpression(
            '/Notas de credito autorizadas en el periodo: 1\n/',
            $salida,
            'el comando tiene que encontrar EXACTAMENTE la nota de credito del periodo y ninguna otra (si encuentra 0, el scope por comercio o el filtro de fecha estan mal; si encuentra mas, se esta llevando filas de otro comercio)'
        );

        $this->assertStringContainsString(
            'SUBDECLARADAS:',
            $salida,
            'el comando tiene que marcar la nota de credito como subdeclarada'
        );

        $this->assertStringContainsString(
            'SUBDECLARADO (la exportacion informo de MENOS que el comprobante autorizado):'."\n".
            '      base imponible: 500,00'."\n".
            '      IVA:            105,00',
            $salida,
            'el delta subdeclarado tiene que ir en su propio bloque y ser exactamente la descripcion libre neta (605,00 / 1,21 = 500,00) con su IVA (105,00)'
        );

        /*
         * La otra punta informada por separado. Es lo que impide que una NC declarada de mas
         * cancele a una subdeclarada y el titular quede por debajo de la exposicion real.
         */
        $this->assertStringContainsString(
            'SOBREDECLARADO (la exportacion informo de MAS):'."\n".
            '      base imponible: 0,00'."\n".
            '      IVA:            0,00',
            $salida,
            'en este escenario no hay ninguna NC declarada de mas, y el comando lo tiene que decir con su propio numero en vez de netearlo contra el subdeclarado'
        );

        $this->assertStringNotContainsString(
            'No hay ninguna nota de credito con diferencia',
            $salida,
            'un cero tranquilizador en un escenario que SI tiene diferencia seria peor que no tener el comando'
        );
    }

    /**
     * Test 8 — el comando NO puede decir "nada que rectificar" cuando no pudo medir nada.
     *
     * El agujero que fija este test: hasta el 1/9/2026 el cierre del comando miraba solo los
     * contadores `subdeclaradas` y `sobredeclaradas`. Una corrida donde TODOS los comprobantes
     * fallaron al calcularse imprimia el warning de "no medibles" y en el renglon siguiente
     * afirmaba "No hay ninguna nota de credito con diferencia en el periodo. Nada que rectificar."
     * —y devolvia 0—. Es el mismo cero tranquilizador que el comando existe para evitar, esta vez
     * escrito en el comando.
     *
     * Como se fuerza el fallo, sin tocar el codigo de produccion: la `AfipInformation` del
     * comprobante se crea con un `iva_condition_id` que no existe en el catalogo (la columna no
     * tiene foreign key, verificado en la base de testing). `AfipImportesCalculator::calculate()`
     * arranca leyendo `afip_information->iva_condition->name`, se encuentra con null y tira. El
     * comando lo atrapa con su `catch (Throwable)`, lo cuenta como no medible y sigue — que es
     * exactamente lo que tiene que pasar con una fila rota en una base de produccion.
     *
     * @group iva-notas-credito
     * @test
     */
    public function el_comando_de_auditoria_no_dice_nada_que_rectificar_cuando_no_pudo_medir()
    {
        $afip_information = AfipInformation::create([
            'user_id'                => $this->usuario_de_testing()->id,
            // Id inexistente a proposito: es lo que hace que el calculo tire y el comprobante
            // quede sin medir. No hay FK sobre esta columna, asi que la fila entra igual.
            'iva_condition_id'       => 999999,
            'razon_social'           => 'Comercio de test libro iva sin condicion de iva',
            'cuit'                   => '20000000000',
            'punto_venta'            => 99,
            'afip_ticket_production' => 0,
        ]);

        $this->sembrado['afip_information'][] = $afip_information->id;

        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 1210);

        $this->agregar_articulo($nc['nota_credito'], 'Pinza', 1210, 1);

        $exit_code = Artisan::call('auditar_libro_iva_notas_credito', [
            'company_name' => $this->usuario_de_testing()->company_name,
            '--desde'      => '2015-07-01',
            '--hasta'      => '2015-07-31',
        ]);

        $salida = str_replace("\r\n", "\n", Artisan::output());

        $this->assertMatchesRegularExpression(
            '/No medibles \(ver el detalle de arriba\): +1\n/',
            $salida,
            'el comprobante roto tiene que contarse como no medible, no desaparecer del resumen'
        );

        $this->assertStringNotContainsString(
            'Nada que rectificar',
            $salida,
            '🔴 EL AGUJERO: con comprobantes sin medir, el comando NO puede afirmar que no hay nada que rectificar. No midio nada, no sabe.'
        );

        $this->assertStringContainsString(
            'CONCLUSION PARCIAL',
            $salida,
            'el cierre tiene que decir con todas las letras que la conclusion es parcial y cuantos comprobantes quedaron sin medir'
        );

        $this->assertSame(
            2,
            $exit_code,
            'el exit code tiene que ser distinto de 0 para que un cron o un pipeline no lea como verde una corrida que no midio todo'
        );
    }
}
