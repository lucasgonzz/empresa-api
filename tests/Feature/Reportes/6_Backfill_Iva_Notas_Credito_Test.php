<?php

namespace Tests\Feature\Reportes;

use App\Console\Commands\SetIvaNotasCredito;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\IvaCondition;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Tests del comando de backfill `php artisan set_iva_notas_credito`
 * (`App\Console\Commands\SetIvaNotasCredito`).
 *
 * 🔴 POR QUE ESTE COMANDO NECESITA TESTS Y NO ALCANZA CON LEERLO. Escribe una columna FISCAL
 * (`afip_tickets.importe_iva`) sobre comprobantes ya autorizados por ARCA, en la produccion de 40
 * clientes, y ese numero termina en el renglon "IVA de notas de credito emitidas" de la Posicion
 * Fiscal, o sea en una DDJJ. Un error hacia "menos IVA cancelado" no rompe nada visible: hace pagar
 * de mas, en silencio, y el reporte lo informa como un dato medido.
 *
 * 🔴 LO QUE ESTE ARCHIVO CUBRE, ANTES QUE NADA, ES EL GUARDIA DE LA ALICUOTA (test 1). La primera
 * version del comando anclaba solo en `afip_tickets.importe_total`, creyendo que reproducir el
 * total probaba que se habian reproducido tambien las alicuotas. Es falso, y en este sistema es
 * demostrable en una linea: los precios son CON IVA INCLUIDO, asi que
 * `AfipItemCalculator::get_price_without_iva()` devuelve `P / (1 + r)` y `monto_iva_del_precio()`
 * devuelve `P / (1 + r) * r`. Sumados dan `P` para CUALQUIER `r`. El test 1 arma exactamente ese
 * escenario —el mismo que quedo medido en el docblock del comando— y verifica que NO se escriba
 * nada: sin el guardia del pivot, el comando escribia $114,98 donde ARCA tiene $210, con delta
 * 0,00 en el control del total y un "exito" en el resumen.
 *
 * Estrategia de escenario. No hay forma de emitir un comprobante de verdad en un test (habria que
 * hablar con el webservice de ARCA), asi que las notas de credito se siembran directo —mismo
 * criterio que `2_Posicion_Fiscal_Test.php` y que `database/seeders/AfipTicketSeeder.php`— pero con
 * la MISMA FORMA que deja `AfipNotaCreditoHelper::create_afip_ticket()`: `sale_id` en null, el
 * vinculo a la venta por `sale_nota_credito_id`, la factura de venta por `sale_afip_ticket_id`, y
 * los articulos devueltos colgando del pivot `article_current_acount` con su `iva_percentage`
 * historico (lo que attachea `CurrentAcountHelper::attachNotaCreditoArticles()`). La venta que
 * origina la devolucion si se crea por el endpoint real (`crear_venta_cobrada()` del trait): el
 * recalculo la lee (`AfipHelper::__construct()` saca `sale` del afip_ticket de la factura), asi que
 * tiene que ser una venta de verdad.
 *
 * Reloj: siempre por `fijar_reloj_en()` del trait, nunca `Carbon::setTestNow()` directo.
 *
 * @group reportes
 */
class Backfill_Iva_Notas_Credito_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /**
     * Precio unitario CON IVA del articulo devuelto en las notas de credito de este archivo.
     * Elegido para que las cuentas den redondas al 21 %: 605 / 1,21 = 500 exactos.
     */
    const PRECIO_UNITARIO = 605;

    /** Unidades devueltas en cada nota de credito sembrada. */
    const CANTIDAD = 2;

    /**
     * Total del comprobante tal como quedo autorizado por ARCA: 2 x 605.
     *
     * 🔴 Este mismo numero lo reproduce el recalculo al 21 % (1.000 gravado + 210 de IVA) Y al
     * 10,5 % (1.095,02 gravado + 114,98 de IVA). Esa coincidencia no es casualidad ni un numero
     * elegido con maña: es la propiedad estructural del sistema (precios con IVA incluido) que hace
     * que el control del total sea CIEGO a la alicuota. Es la razon de ser del test 1.
     */
    const TOTAL_DECLARADO = 1210;

    /** IVA que el comercio le declaro a ARCA: el 21 % de 1.000 de base imponible. */
    const IVA_DECLARADO = 210;

    /**
     * IVA que el recalculo devolveria si tomara la alicuota de HOY (10,5 %) en vez de la historica:
     * 45 % menos que el declarado, y con el total reproduciendo exacto. Es el numero que el comando
     * escribia antes del arreglo `d69f15f8`.
     */
    const IVA_INVENTADO_CON_LA_ALICUOTA_DE_HOY = 114.98;

    /**
     * Momento de creacion del `afip_ticket` de la nota de credito. Se fija a mano (y no se deja al
     * reloj) porque `SetIvaNotasCredito::persistir()` saca la `afip_fecha_emision` justamente del
     * `created_at` del ticket: con el valor fijo, la fecha esperada se puede assertear literal.
     *
     * Hora elegida a proposito lejos de medianoche: el comando marca como "fecha de riesgo" todo lo
     * creado entre las 23:50 y las 00:10, y eso cambiaria la salida que este archivo verifica.
     */
    const CREADO_EN = '2021-03-15 14:30:00';

    /** Fecha (Y-m-d) que le corresponde a `CREADO_EN`. */
    const FECHA_DE_EMISION_ESPERADA = '2021-03-15';

    /**
     * Ids de los `afip_tickets` sembrados por este test (facturas de venta y notas de credito),
     * para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $afip_tickets_sembrados = [];

    /**
     * Ids de los movimientos de cuenta corriente (`current_acounts`) de las notas de credito
     * sembradas, para borrarlos en el tearDown. `EscenariosDePlata` no sabe nada de notas de
     * credito: si no las borra este archivo, no las borra nadie.
     *
     * @var array<int,int>
     */
    protected $notas_credito_sembradas = [];

    /**
     * `AfipInformation` creada por este test para que el recalculo tome el camino de Responsable
     * Inscripto (ver `afip_information_responsable_inscripto()`).
     *
     * @var \App\Models\AfipInformation|null
     */
    protected $afip_information_sembrada = null;

    /**
     * Marca si este test le cambio el IVA al articulo centinela, para restaurarlo en el tearDown y
     * no dejar el fixture roto para el resto de la suite.
     *
     * Va aparte del id original porque `articles.iva_id` es nullable: un `is_null()` sobre el id
     * guardado no distingue "no lo toque" de "lo toque y el original era null".
     *
     * @var bool
     */
    protected $centinela_con_iva_cambiado = false;

    /**
     * `iva_id` que tenia el articulo centinela antes de que este test se lo cambiara.
     *
     * @var int|null
     */
    protected $iva_id_original_del_centinela = null;

    /**
     * Deja el fixture como estaba: restaura el IVA del articulo centinela, borra los comprobantes
     * sembrados y libera lo que creo `EscenariosDePlata`. Corre siempre, incluso si una asercion
     * corto el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->restaurar_iva_del_centinela();

        // Los comprobantes van primero porque cuelgan de las ventas que borra
        // `limpiar_escenarios()`: al reves quedarian apuntando a una venta que ya no esta.
        $this->limpiar_comprobantes_sembrados();

        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Borra los `afip_tickets`, las notas de credito y la `AfipInformation` que sembro este test.
     *
     * Los `afip_tickets` se borran con `forceDelete()` y no con `delete()`: el modelo usa
     * `SoftDeletes`, asi que un borrado normal dejaria la fila viva con `deleted_at` puesto.
     *
     * @return void
     */
    protected function limpiar_comprobantes_sembrados()
    {
        if (count($this->afip_tickets_sembrados)) {
            AfipTicket::whereIn('id', $this->afip_tickets_sembrados)->forceDelete();
            $this->afip_tickets_sembrados = [];
        }

        if (count($this->notas_credito_sembradas)) {
            // El pivot no tiene borrado en cascada: se detachea antes de borrar el movimiento, o
            // quedan filas de `article_current_acount` apuntando a una nota de credito inexistente.
            $notas = CurrentAcount::whereIn('id', $this->notas_credito_sembradas)->get();

            foreach ($notas as $nota) {
                $nota->articles()->detach();
            }

            CurrentAcount::whereIn('id', $this->notas_credito_sembradas)->delete();
            $this->notas_credito_sembradas = [];
        }

        if (!is_null($this->afip_information_sembrada)) {
            AfipInformation::where('id', $this->afip_information_sembrada->id)->delete();
            $this->afip_information_sembrada = null;
        }
    }

    /**
     * Usuario dueño del fixture de testing (el mismo que autentica `EmpresaTestCase::setUp()`).
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * `company_name` del usuario del fixture, que es el UNICO argumento del comando
     * (`set_iva_notas_credito {company_name}`).
     *
     * Se lee de la base y no se hardcodea: el comando resuelve el usuario por esa columna, y un
     * literal que no coincida haria que el comando salga con "No existe ningun usuario" y exit 1 —
     * un test verde por no haber medido nada seria peor que uno rojo.
     *
     * @return string
     */
    protected function company_name_del_fixture()
    {
        $user = $this->usuario_de_testing();

        if (is_null($user->company_name) || trim($user->company_name) === '') {
            $this->fail(
                'El usuario del fixture ('.TestingFerreteriaSeeder::USER_EMAIL.') no tiene company_name, '.
                'que es el unico argumento con el que `set_iva_notas_credito` resuelve al comercio. '.
                'Sin eso el comando no encuentra nada y estos tests no medirian nada. Es un problema '.
                'del fixture para reportar, no algo para ajustar en las aserciones.'
            );
        }

        return $user->company_name;
    }

    /**
     * Crea (una sola vez por test) la configuracion fiscal del comercio con condicion de IVA
     * "Responsable inscripto".
     *
     * No es decorado: `AfipImportesCalculator::calculate()` decide por
     * `afip_ticket->afip_information->iva_condition->name` si recorre los items uno por uno (camino
     * RI, el unico que mira las alicuotas) o si arma el total de una. Sin esta fila el recalculo
     * tomaria la otra rama y estos tests no estarian probando el backfill real.
     *
     * @return \App\Models\AfipInformation
     */
    protected function afip_information_responsable_inscripto()
    {
        if (!is_null($this->afip_information_sembrada)) {
            return $this->afip_information_sembrada;
        }

        $condicion = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($condicion)) {
            $this->fail(
                'La base de testing no tiene la condicion de IVA "Responsable inscripto" en `iva_conditions`. '.
                'Sin ella, `AfipImportesCalculator` no toma el camino que recorre los items y estos tests no '.
                'medirian el recalculo. Es un problema del entorno para reportar, no algo para ajustar aca.'
            );
        }

        $this->afip_information_sembrada = AfipInformation::create([
            'user_id'          => $this->usuario_de_testing()->id,
            'iva_condition_id' => $condicion->id,
            'razon_social'     => 'Fixture de testing',
            'cuit'             => '20000000000',
            'punto_venta'      => 1,
        ]);

        return $this->afip_information_sembrada;
    }

    /**
     * Le cambia al articulo centinela el IVA que tiene HOY, simulando el caso real que rompe el
     * recalculo: el articulo se vendio a una alicuota y despues alguien se la cambio.
     *
     * Se escribe directo sobre el fixture (no hay endpoint para esto en el flujo de este archivo) y
     * se restaura en el tearDown, mismo criterio que `desactivar_iibb()` en `2_Posicion_Fiscal_Test`.
     * `timestamps = false` para no ensuciar el `updated_at` del fixture en cada corrida.
     *
     * @param string $porcentaje Valor de `ivas.percentage` (columna de texto: '21', '10.5', 'Exento').
     * @return \App\Models\Article
     */
    protected function poner_al_centinela_el_iva_de_hoy($porcentaje)
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $iva = Iva::where('percentage', $porcentaje)->first();

        if (is_null($iva)) {
            $this->fail(
                'La base de testing no tiene ninguna fila en `ivas` con percentage "'.$porcentaje.'". '.
                'Sin ella no se puede armar el escenario del articulo que cambio de alicuota, que es '.
                'justo el que el guardia del pivot existe para atrapar.'
            );
        }

        if (!$this->centinela_con_iva_cambiado) {
            $this->iva_id_original_del_centinela = $articulo->iva_id;
            $this->centinela_con_iva_cambiado = true;
        }

        $articulo->iva_id = $iva->id;
        $articulo->timestamps = false;
        $articulo->save();

        return $articulo;
    }

    /**
     * Devuelve el `iva_id` original al articulo centinela.
     *
     * @return void
     */
    protected function restaurar_iva_del_centinela()
    {
        if (!$this->centinela_con_iva_cambiado) {
            return;
        }

        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        if (!is_null($articulo)) {
            $articulo->iva_id = $this->iva_id_original_del_centinela;
            $articulo->timestamps = false;
            $articulo->save();
        }

        $this->centinela_con_iva_cambiado = false;
    }

    /**
     * Crea una venta cobrada por el endpoint real y le cuelga su `afip_ticket` de factura
     * autorizada, que es la que el recalculo usa como ancla (`sale_afip_ticket_id`).
     *
     * @param float $monto Monto cobrado de la venta.
     * @return array{venta: \App\Models\Sale, afip_ticket: \App\Models\AfipTicket}
     */
    protected function crear_factura_de_venta($monto)
    {
        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            $monto
        );

        $afip_ticket = AfipTicket::create([
            'sale_id'             => $venta->id,
            'afip_information_id' => $this->afip_information_responsable_inscripto()->id,
            'resultado'           => 'A',
            'importe_total'       => $monto,
            'afip_fecha_emision'  => Carbon::now()->format('Y-m-d'),
            'cbte_numero'         => (string) $venta->id,
            'cbte_letra'          => 'A',
            'cbte_tipo'           => 1,
            'cuit_negocio'        => '20000000000',
            'cae'                 => '00000000000000',
        ]);

        $this->afip_tickets_sembrados[] = $afip_ticket->id;

        return ['venta' => $venta, 'afip_ticket' => $afip_ticket];
    }

    /**
     * Siembra una nota de credito YA AUTORIZADA por ARCA pero SIN el IVA persistido: exactamente el
     * estado en el que quedaron todas las notas de credito emitidas antes del 1/9/2026, que es lo
     * que el backfill viene a recuperar (`importe_iva` e `afip_fecha_emision` los dos en null).
     *
     * @param array|null $factura Resultado de `crear_factura_de_venta()`, o null para una NC suelta.
     * @param array $items Articulos devueltos: `articulo` (nombre), `cantidad`, `precio`, `iva_percentage`.
     * @param float $importe_total Total que ARCA autorizo, contra el que el comando compara el recalculo.
     * @param array $overrides Clave `afip_ticket` para pisar campos del comprobante sembrado.
     * @return array{nota_credito: \App\Models\CurrentAcount, afip_ticket: \App\Models\AfipTicket}
     */
    protected function sembrar_nota_credito_sin_iva($factura, $items, $importe_total, $overrides = [])
    {
        $nota_credito = CurrentAcount::create([
            'detalle'     => 'Nota Credito de test (backfill)',
            'description' => 'Devolucion de test',
            'haber'       => $importe_total,
            'status'      => 'nota_credito',
            'sale_id'     => is_null($factura) ? null : $factura['venta']->id,
            'user_id'     => $this->usuario_de_testing()->id,
            'moneda_id'   => 1,
        ]);

        $this->notas_credito_sembradas[] = $nota_credito->id;

        foreach ($items as $item) {

            $articulo = $this->articulo($item['articulo']);

            if (is_null($articulo)) {
                $this->fail('El fixture no tiene el articulo "'.$item['articulo'].'", necesario para armar la nota de credito.');
            }

            /*
             * Mismo attach que hace `CurrentAcountHelper::attachNotaCreditoArticles()` en el flujo
             * real de devoluciones. `iva_percentage` es la columna que nacio con la migracion
             * 2026_06_11_000002: en null significa "nota de credito anterior a esa migracion", que
             * es el escenario del test 1.
             */
            $nota_credito->articles()->attach($articulo->id, [
                'amount'         => $item['cantidad'],
                'price'          => $item['precio'],
                'discount'       => null,
                'cost'           => 0,
                'iva_percentage' => $item['iva_percentage'],
            ]);
        }

        $afip_ticket = AfipTicket::create(array_merge([
            // sale_id en null y el vinculo por sale_nota_credito_id: es lo que deja
            // `AfipNotaCreditoHelper::create_afip_ticket()`, y lo que hace que `iva_debito()` no
            // cuente esta fila como si fuera una venta.
            'sale_id'              => null,
            'nota_credito_id'      => $nota_credito->id,
            'sale_nota_credito_id' => is_null($factura) ? null : $factura['venta']->id,
            'sale_afip_ticket_id'  => is_null($factura) ? null : $factura['afip_ticket']->id,
            'afip_information_id'  => $this->afip_information_responsable_inscripto()->id,
            'resultado'            => 'A',
            // Las dos columnas que el backfill viene a escribir, en el estado previo al backfill.
            'importe_iva'          => null,
            'afip_fecha_emision'   => null,
            'importe_total'        => $importe_total,
            'created_at'           => self::CREADO_EN,
            'cbte_numero'          => (string) $nota_credito->id,
            'cbte_letra'           => 'A',
            'cbte_tipo'            => 3,
            'cuit_negocio'         => '20000000000',
            'cae'                  => '00000000000000',
        ], isset($overrides['afip_ticket']) ? $overrides['afip_ticket'] : []));

        $this->afip_tickets_sembrados[] = $afip_ticket->id;

        return ['nota_credito' => $nota_credito, 'afip_ticket' => $afip_ticket];
    }

    /**
     * Item estandar de las notas de credito de este archivo: el articulo centinela, 2 unidades a
     * $605, con la alicuota historica que se indique (null = nota de credito vieja, sin pivot).
     *
     * @param string|null $iva_percentage Valor a persistir en `article_current_acount.iva_percentage`.
     * @return array<int,array<string,mixed>>
     */
    protected function items_estandar($iva_percentage)
    {
        return [
            [
                'articulo'       => TestingFerreteriaSeeder::ARTICULO_CENTINELA,
                'cantidad'       => self::CANTIDAD,
                'precio'         => self::PRECIO_UNITARIO,
                'iva_percentage' => $iva_percentage,
            ],
        ];
    }

    /**
     * Corre el comando de verdad y devuelve su exit code y su salida ya normalizada (una sola
     * linea, espacios colapsados) para poder buscar frases sin depender del ancho de la consola.
     *
     * @param bool $aplicar Si es false, corrida en seco (el default del comando).
     * @return array{codigo: int, salida: string}
     */
    protected function correr_backfill($aplicar)
    {
        $parametros = ['company_name' => $this->company_name_del_fixture()];

        if ($aplicar) {
            $parametros['--aplicar'] = true;
        }

        $codigo = Artisan::call('set_iva_notas_credito', $parametros);

        return [
            'codigo' => $codigo,
            'salida' => trim(preg_replace('/\s+/', ' ', Artisan::output())),
        ];
    }

    /**
     * Lee la fila cruda de `afip_tickets` desde la base, sin pasar por el modelo.
     *
     * A proposito con `DB::table()`: lo que se quiere verificar es lo que quedo PERSISTIDO, no lo
     * que tiene en memoria una instancia que el comando pudo haber dejado modificada.
     *
     * @param int $id
     * @return object|null
     */
    protected function leer_ticket_de_la_base($id)
    {
        return DB::table('afip_tickets')->where('id', $id)->first();
    }

    /**
     * Repite EXACTAMENTE el recalculo que hace `SetIvaNotasCredito`: el primer argumento es el
     * afip_ticket de la FACTURA DE VENTA, y despues van los articulos, servicios y descripciones de
     * la NOTA DE CREDITO mas el modelo de la NC (que aporta sus descuentos y recargos).
     *
     * Existe para poder assertear, dentro del test, QUE NUMERO habria escrito el comando si el
     * guardia del pivot no estuviera. Sin eso, el test 1 solo podria decir "no escribio nada", que
     * es lo mismo que diria si el escenario estuviera mal armado y el comando ni siquiera hubiera
     * llegado a mirar el comprobante.
     *
     * @param array $nota Resultado de `sembrar_nota_credito_sin_iva()`.
     * @param array $factura Resultado de `crear_factura_de_venta()`.
     * @return array Importes calculados (`total`, `iva`, `gravado`, ...).
     */
    protected function recalcular_como_lo_hace_el_comando($nota, $factura)
    {
        $nota_credito = CurrentAcount::findOrFail($nota['nota_credito']->id);
        $nota_credito->load(['discounts', 'surchages']);

        $afip_helper = new AfipHelper(
            AfipTicket::findOrFail($factura['afip_ticket']->id),
            $nota_credito->articles,
            $nota_credito->services,
            $this->usuario_de_testing(),
            null,
            $nota_credito->nota_credito_descriptions,
            $nota_credito
        );

        return $afip_helper->getImportes();
    }

    /**
     * Test 1 — 🔴 EL MAS IMPORTANTE: sin la alicuota historica en el pivot, el comando NO escribe
     * nada, ni siquiera con `--aplicar`.
     *
     * Este es el test que hubiera atrapado el bug arreglado en `d69f15f8`. El escenario es el que
     * quedo medido en el docblock del comando, armado tal cual:
     *
     *   - Una nota de credito ANTERIOR a la migracion 2026_06_11_000002: su fila del pivot
     *     `article_current_acount` tiene `iva_percentage` en NULL.
     *   - El comprobante se emitio al 21 %: total $1.210, IVA declarado a ARCA $210.
     *   - El articulo HOY figura al 10,5 % (alguien le cambio la alicuota despues de la venta).
     *
     * Con el pivot vacio, `AfipItemCalculator::resolve_article_iva_percentage()` cae a la alicuota
     * de HOY y el recalculo devuelve gravado 1.095,02 + IVA 114,98 = total 1.210,00. O sea: el
     * control del total PASA con delta 0,00 y el IVA recuperado seria un 45 % mas chico que el
     * real, informado como exito y camino a una DDJJ.
     *
     * Lo unico que puede frenar eso es el control del pivot, porque el del total es estructuralmente
     * ciego a la alicuota (precios con IVA incluido: `P/(1+r) + P/(1+r)*r = P` para todo `r`).
     *
     * Se verifica que las DOS columnas queden en null y que el comando lo reporte.
     *
     * @group reportes
     * @test
     */
    public function sin_la_alicuota_historica_en_el_pivot_no_se_escribe_nada_ni_con_aplicar()
    {
        $this->fijar_reloj_en('2021-03-15 10:00:00');

        // El articulo cambio de alicuota despues de la venta: hoy esta al 10,5 %.
        $centinela = $this->poner_al_centinela_el_iva_de_hoy('10.5');

        $factura = $this->crear_factura_de_venta(self::TOTAL_DECLARADO);

        // Nota de credito vieja: el pivot no tiene la alicuota con la que se emitio.
        $nota = $this->sembrar_nota_credito_sin_iva($factura, $this->items_estandar(null), self::TOTAL_DECLARADO);

        /*
         * 🔴 PRIMERO SE DEMUESTRA QUE EL ESCENARIO ES EL PELIGROSO, y recien despues se verifica
         * que el comando no escriba. Sin este bloque, un "no escribio nada" tambien seria el
         * resultado de un escenario mal armado que el comando ni siquiera llego a mirar, y el test
         * pasaria en verde sin medir nada.
         *
         * Se corre el MISMO recalculo que corre el comando y se verifica que:
         *   (a) el total reproduce dentro de la tolerancia del propio comando — o sea, el control
         *       del total le habria dado el visto bueno a este comprobante; y
         *   (b) el IVA que ese recalculo devuelve NO es el que se le declaro a ARCA.
         * Las dos cosas juntas son la definicion del bug arreglado en `d69f15f8`.
         */
        $importes_sin_el_guardia = $this->recalcular_como_lo_hace_el_comando($nota, $factura);

        $this->assertLessThanOrEqual(
            SetIvaNotasCredito::TOLERANCIA,
            abs((float) $importes_sin_el_guardia['total'] - self::TOTAL_DECLARADO),
            'El escenario tiene que ser uno donde el control del total PASA (delta dentro de la tolerancia del comando): '.
            'si el total no reprodujera, el comprobante se frenaria por el otro control y este test no estaria midiendo '.
            'el guardia de la alicuota.'
        );

        $this->assertEqualsWithDelta(
            self::IVA_INVENTADO_CON_LA_ALICUOTA_DE_HOY,
            (float) $importes_sin_el_guardia['iva'],
            self::DELTA,
            'El recalculo sin la alicuota historica devuelve el IVA de la alicuota de HOY (10,5 %), no el declarado. '.
            'Ese es exactamente el numero que el comando escribia antes del arreglo: un 45 % menos que los '.
            self::IVA_DECLARADO.' reales, con el control del total en verde.'
        );

        $corrida = $this->correr_backfill(true);

        $this->assertEquals(0, $corrida['codigo'], 'El comando tiene que terminar con exit 0: un comprobante que no se puede medir es un caso esperado, no una falla de ejecucion.');

        $fila = $this->leer_ticket_de_la_base($nota['afip_ticket']->id);

        $this->assertNull(
            $fila->importe_iva,
            'Sin `iva_percentage` en el pivot, el recalculo usaria la alicuota de HOY ('.
            self::IVA_INVENTADO_CON_LA_ALICUOTA_DE_HOY.' en vez de los '.self::IVA_DECLARADO.
            ' que se le declararon a ARCA) y ese numero seria inventado, no medido. El comando NO '.
            'tiene que escribir importe_iva.'
        );

        $this->assertNull(
            $fila->afip_fecha_emision,
            'Lo que no se pudo medir tampoco recibe fecha de emision: con la fecha puesta y el IVA en '.
            'null, el comprobante ENTRA al periodo del reporte aportando $0 (SUM ignora los null) y '.
            'aparece en el drill-down como una nota de credito real valuada en cero.'
        );

        // El comando no se puede callar: si no midio, tiene que decir cual comprobante y por que.
        $this->assertStringContainsString(
            'articulo(s) sin alicuota historica en el pivot (ids: '.$centinela->id.')',
            $corrida['salida'],
            'El comando tiene que reportar el comprobante y el articulo que lo dejo sin medir, para que se pueda revisar a mano.'
        );

        $this->assertStringContainsString(
            'SIN ALICUOTA HISTORICA (revisar a mano): 1',
            $corrida['salida'],
            'El resumen tiene que contar el comprobante que quedo sin medir: un backfill que no puede recuperar un valor no puede terminar informando solo exitos.'
        );

        $this->assertStringContainsString(
            'Recuperadas: 0',
            $corrida['salida'],
            'El comprobante sin alicuota historica no puede contarse como recuperado.'
        );
    }

    /**
     * Test 2 — Corrida en seco: sin `--aplicar` no se escribe NADA, aunque el escenario sea
     * perfectamente recuperable.
     *
     * Es la unica red que tiene una persona antes de tocar una columna fiscal en la produccion de
     * 40 clientes: se corre, se lee el resumen, y recien si cierra se corre con `--aplicar`. Si la
     * corrida en seco escribiera, esa red no existe. Se verifica leyendo la fila de vuelta de la
     * base, no confiando en el resumen.
     *
     * @group reportes
     * @test
     */
    public function la_corrida_en_seco_no_escribe_nada_aunque_pueda_recuperar_el_valor()
    {
        $this->fijar_reloj_en('2021-03-15 10:00:00');

        $factura = $this->crear_factura_de_venta(self::TOTAL_DECLARADO);

        $nota = $this->sembrar_nota_credito_sin_iva($factura, $this->items_estandar('21'), self::TOTAL_DECLARADO);

        $corrida = $this->correr_backfill(false);

        $this->assertEquals(0, $corrida['codigo']);

        $fila = $this->leer_ticket_de_la_base($nota['afip_ticket']->id);

        $this->assertNull($fila->importe_iva, 'Sin --aplicar el comando no puede escribir importe_iva, por mas que el escenario sea recuperable.');
        $this->assertNull($fila->afip_fecha_emision, 'Sin --aplicar el comando tampoco puede escribir afip_fecha_emision.');

        // El resumen si tiene que contar lo que HABRIA escrito: para eso se corre en seco.
        $this->assertStringContainsString('EN SECO', $corrida['salida'], 'La corrida en seco tiene que decir que lo es, o alguien va a creer que ya aplico.');
        $this->assertStringContainsString('Recuperadas: 1', $corrida['salida'], 'La corrida en seco tiene que informar cuantos comprobantes se recuperarian.');
    }

    /**
     * Test 3 — Camino feliz: con la alicuota historica en el pivot y un total que reproduce, se
     * escribe el IVA declarado y la fecha de emision sale del `created_at` del ticket.
     *
     * El articulo esta HOY al 10,5 % a proposito: asi el test no solo verifica que se escriba algo,
     * verifica que se escriba el numero HISTORICO ($210, del pivot al 21 %) y no el que saldria de
     * la alicuota de hoy ($114,98). Sin esa divergencia, el test pasaria igual con el recalculo
     * mirando el articulo actual y no probaria nada de lo que el comando promete.
     *
     * @group reportes
     * @test
     */
    public function con_la_alicuota_historica_escribe_el_iva_declarado_y_la_fecha_del_created_at()
    {
        $this->fijar_reloj_en('2021-03-15 10:00:00');

        // El articulo cambio de alicuota despues de la venta: el recalculo tiene que ignorarlo.
        $this->poner_al_centinela_el_iva_de_hoy('10.5');

        $factura = $this->crear_factura_de_venta(self::TOTAL_DECLARADO);

        $nota = $this->sembrar_nota_credito_sin_iva($factura, $this->items_estandar('21'), self::TOTAL_DECLARADO);

        $corrida = $this->correr_backfill(true);

        $this->assertEquals(0, $corrida['codigo']);

        $fila = $this->leer_ticket_de_la_base($nota['afip_ticket']->id);

        $this->assertNotNull($fila->importe_iva, 'Con el pivot completo y el total reproduciendo, el comando tiene que recuperar el IVA.');

        $this->assertEqualsWithDelta(
            self::IVA_DECLARADO,
            (float) $fila->importe_iva,
            self::DELTA,
            'El IVA recuperado tiene que ser el HISTORICO ('.self::IVA_DECLARADO.', al 21 % del pivot), no el que '.
            'saldria de la alicuota que el articulo tiene hoy ('.self::IVA_INVENTADO_CON_LA_ALICUOTA_DE_HOY.', al 10,5 %). '.
            'Si dio '.self::IVA_INVENTADO_CON_LA_ALICUOTA_DE_HOY.', el recalculo esta ignorando el pivot.'
        );

        $this->assertEquals(
            self::FECHA_DE_EMISION_ESPERADA,
            $fila->afip_fecha_emision,
            'La fecha de emision tiene que salir del created_at del propio ticket ('.self::CREADO_EN.'): '.
            '`AfipNotaCreditoHelper::create_afip_ticket()` crea esa fila en la misma request en la que se '.
            'manda `CbteFch => date(\'Ymd\')` a ARCA.'
        );

        $this->assertStringContainsString(
            'IVA '.self::IVA_DECLARADO.' sobre un total de '.self::TOTAL_DECLARADO,
            $corrida['salida'],
            'El comando tiene que dejar por escrito que numero le puso a cada comprobante.'
        );

        $this->assertStringContainsString('Recuperadas: 1', $corrida['salida']);
        $this->assertStringContainsString('APLICADO', $corrida['salida'], 'Con --aplicar el resumen tiene que decir que escribio.');
    }

    /**
     * Test 4 — Las dos columnas se escriben juntas o no se escribe ninguna.
     *
     * 🔴 Es el modo de falla que hacia entrar un comprobante al reporte aportando $0. Un ticket con
     * `afip_fecha_emision` puesta y `importe_iva` en null CAE dentro del rango del renglon (la query
     * fechea por esa columna) y suma $0, porque `SUM` ignora los null: en el drill-down aparece una
     * nota de credito real de ARCA valuada en cero, indistinguible de una que efectivamente no tuvo
     * IVA. Sin la fecha queda afuera, que es lo honesto: no se midio.
     *
     * El escenario elegido es el del OTRO control (el del total): el pivot tiene la alicuota, pero
     * el comprobante no reproduce —$2.000 autorizados contra $1.210 recalculados—, como pasaria si
     * a la nota de credito le hubieran borrado un item o cambiado una cantidad despues de emitida.
     *
     * @group reportes
     * @test
     */
    public function un_comprobante_que_no_reproduce_no_recibe_ninguna_de_las_dos_columnas()
    {
        $this->fijar_reloj_en('2021-03-15 10:00:00');

        $total_autorizado_por_arca = 2000;

        $factura = $this->crear_factura_de_venta($total_autorizado_por_arca);

        // Los items de la NC recalculan 1.210: no reproducen los 2.000 que autorizo ARCA.
        $nota = $this->sembrar_nota_credito_sin_iva($factura, $this->items_estandar('21'), $total_autorizado_por_arca);

        $corrida = $this->correr_backfill(true);

        $this->assertEquals(0, $corrida['codigo']);

        $fila = $this->leer_ticket_de_la_base($nota['afip_ticket']->id);

        $this->assertNull($fila->importe_iva, 'Si el recalculo no reproduce el comprobante, el IVA habria que inventarlo: no se escribe.');

        $this->assertNull(
            $fila->afip_fecha_emision,
            'Y tampoco se escribe la fecha. Con la fecha puesta y el IVA en null, este comprobante ENTRARIA '.
            'al periodo del reporte aportando $0 y apareceria en el drill-down como una nota de credito real '.
            'valuada en cero. Las dos columnas van juntas.'
        );

        $this->assertStringContainsString('el recalculo NO reproduce el comprobante', $corrida['salida']);
        $this->assertStringContainsString('NO REPRODUCIBLES (revisar a mano): 1', $corrida['salida']);
    }

    /**
     * Test 5 — Idempotencia: correrlo dos veces no cambia el valor ni duplica nada.
     *
     * Un backfill se corre a mano sobre 40 clientes; que alguien lo repita —o que se corte a mitad y
     * se vuelva a lanzar— es lo normal, no la excepcion. La segunda corrida tiene que reconocer lo
     * ya medido (`importe_iva` no null) y saltearlo, sin recalcular ni volver a escribir.
     *
     * @group reportes
     * @test
     */
    public function correrlo_dos_veces_no_cambia_el_valor_ni_lo_duplica()
    {
        $this->fijar_reloj_en('2021-03-15 10:00:00');

        $factura = $this->crear_factura_de_venta(self::TOTAL_DECLARADO);

        $nota = $this->sembrar_nota_credito_sin_iva($factura, $this->items_estandar('21'), self::TOTAL_DECLARADO);

        $primera = $this->correr_backfill(true);
        $fila_despues_de_la_primera = $this->leer_ticket_de_la_base($nota['afip_ticket']->id);

        $this->assertStringContainsString('Recuperadas: 1', $primera['salida'], 'La primera corrida tiene que recuperar el comprobante, o el test de idempotencia no mide nada.');
        $this->assertEqualsWithDelta(self::IVA_DECLARADO, (float) $fila_despues_de_la_primera->importe_iva, self::DELTA);

        $segunda = $this->correr_backfill(true);
        $fila_despues_de_la_segunda = $this->leer_ticket_de_la_base($nota['afip_ticket']->id);

        $this->assertEqualsWithDelta(
            (float) $fila_despues_de_la_primera->importe_iva,
            (float) $fila_despues_de_la_segunda->importe_iva,
            self::DELTA,
            'La segunda corrida no puede cambiar el IVA que ya habia quedado escrito.'
        );

        $this->assertEquals(
            $fila_despues_de_la_primera->afip_fecha_emision,
            $fila_despues_de_la_segunda->afip_fecha_emision,
            'La segunda corrida no puede cambiar la fecha de emision que ya habia quedado escrita.'
        );

        $this->assertStringContainsString('Ya tenian importe_iva: 1', $segunda['salida'], 'La segunda corrida tiene que reconocer el comprobante como ya medido.');
        $this->assertStringContainsString('Recuperadas: 0', $segunda['salida'], 'La segunda corrida no puede volver a contarlo como recuperado.');

        $this->assertEquals(
            1,
            AfipTicket::where('nota_credito_id', $nota['nota_credito']->id)->count(),
            'El backfill actualiza el comprobante existente: no puede crear una fila nueva por corrida.'
        );
    }

    /**
     * Test 6 — Nota de credito de EXPORTACION (`cbte_tipo` 21): recibe `importe_iva = 0` explicito.
     *
     * `FEXAuthorize` no lleva `ImpIVA`: el comprobante sale sin IVA discriminado, asi que el valor
     * es 0 EXACTO y no hay nada que recalcular ni que verificar. Tiene que quedar en 0 y no en null,
     * que es la diferencia entre "se midio y dio cero" y "no se midio": el contador
     * `notas_credito_sin_medir` de la Posicion Fiscal se apoya justo en esa distincion.
     *
     * @group reportes
     * @test
     */
    public function la_nota_de_credito_de_exportacion_recibe_iva_cero_explicito()
    {
        $this->fijar_reloj_en('2021-03-15 10:00:00');

        // Sin items ni factura de venta: el comando resuelve la exportacion antes de mirarlos.
        $nota = $this->sembrar_nota_credito_sin_iva(null, [], 1500, ['afip_ticket' => ['cbte_tipo' => 21]]);

        $corrida = $this->correr_backfill(true);

        $this->assertEquals(0, $corrida['codigo']);

        $fila = $this->leer_ticket_de_la_base($nota['afip_ticket']->id);

        $this->assertNotNull(
            $fila->importe_iva,
            'Una nota de credito de exportacion tiene IVA 0 medido, no IVA desconocido: dejarla en null la '.
            'sumaria al contador de "notas de credito sin medir" para siempre.'
        );

        $this->assertEqualsWithDelta(0, (float) $fila->importe_iva, self::DELTA, 'El IVA de una NC de exportacion es 0 exacto.');

        $this->assertEquals(
            self::FECHA_DE_EMISION_ESPERADA,
            $fila->afip_fecha_emision,
            'La fecha va junto con el IVA, tambien en la exportacion: con IVA 0 medido, el comprobante tiene que ENTRAR al periodo del reporte.'
        );

        $this->assertStringContainsString('De exportacion (IVA 0): 1', $corrida['salida']);
    }
}
