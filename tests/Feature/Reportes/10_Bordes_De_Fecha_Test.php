<?php

namespace Tests\Feature\Reportes;

use App\Http\Controllers\Helpers\contabilidad\ContabilidadRepository;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\ProviderOrderAfipTicket;
use App\Models\Sale;
use App\Models\SaleTax;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Misión contabilidad-sin-full-scan (21/9/2026) — los extremos del período siguen siendo inclusivos
 * después de sacar el `date()` de los filtros de fecha.
 *
 * 🔴 QUÉ PROTEGE, Y POR QUÉ ES EL TEST QUE IMPORTA DE ESTA MISIÓN.
 *
 * `ContabilidadRepository` filtraba con `whereDate('sales.created_at', '<=', $hasta)`, que genera
 * `date(created_at) <= '2026-09-21'`. Una columna envuelta en una función no puede usar índice: en
 * la base real de hipermax ese filtro examinaba 157.252 filas para devolver un número, y en
 * producción llegó a apilar 16 copias de la misma consulta y dejar el VPS en load 14,95.
 *
 * El arreglo es comparar contra la columna cruda (`created_at <= '2026-09-21 23:59:59'`), que sí usa
 * el índice `user_id, created_at` — 54 filas examinadas en vez de 157.252.
 *
 * Es seguro porque `ContabilidadRepository::rango()` normaliza los dos extremos
 * (`startOfDay()` / `endOfDay()`) antes de cualquier filtro. Pero eso es exactamente lo que este
 * archivo tiene que clavar: si alguien saca el `endOfDay()` de `rango()`, o "simplifica" el filtro
 * a una comparación contra la fecha pelada, **todos los registros del último día después de
 * medianoche desaparecen de los reportes de contabilidad en silencio** — y un reporte que devuelve
 * un número más chico no se ve roto, se ve como un mal mes.
 *
 * 🔴 AÑO 2013 A PROPÓSITO, Y ESTA VEZ MEDIDO. La primera versión de este archivo usaba 2014
 * diciendo que "los otros archivos de la carpeta usan 2015 a 2021". Era falso: el chequeo
 * independiente encontró que `9_Costo_De_Mercaderia_Neto_De_Iva_Test.php` usa 2014 en seis
 * períodos, y uno de ellos (`2014-03-01` a `2014-03-31`) contenía entero el período de acá. Un
 * `grep` sobre `tests/` completo da los años ocupados —2014 a 2021, y 2024 a 2037— así que 2013
 * está libre de verdad. La lección: "verificado archivo por archivo" escrito en un comentario no
 * vale nada si no salió de un comando.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 *
 * @group reportes
 */
class Bordes_De_Fecha_Test extends EmpresaTestCase
{
    /** Delta para comparar floats, mismo criterio que el resto de la carpeta. */
    const DELTA = 0.01;

    /** Primer día del período bajo prueba. */
    const DESDE = '2013-03-10';

    /** Último día del período bajo prueba (inclusive). */
    const HASTA = '2013-03-12';

    /** Primer día fuera del período, por arriba. */
    const DIA_SIGUIENTE = '2013-03-13';

    /** Último día fuera del período, por abajo. */
    const DIA_ANTERIOR = '2013-03-09';

    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\Client */
    protected $cliente;

    /** @var array<int,int> Ids de lo que siembra este archivo, para limpiar antes del rollback. */
    protected $sales_sembradas = [];

    /** @var array<int,int> */
    protected $expenses_sembrados = [];

    /** @var array<int,int> */
    protected $current_acounts_sembradas = [];

    /** @var array<int,int> */
    protected $tickets_compra_sembrados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->cliente = Client::where('user_id', $this->owner->id)->first();
    }

    /**
     * Red redundante sobre el rollback de DatabaseTransactions, mismo criterio que el resto de la
     * carpeta.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->sales_sembradas) >= 1) {
            Sale::whereIn('id', $this->sales_sembradas)->forceDelete();
        }

        if (count($this->expenses_sembrados) >= 1) {
            Expense::whereIn('id', $this->expenses_sembrados)->forceDelete();
        }

        if (count($this->current_acounts_sembradas) >= 1) {
            CurrentAcount::whereIn('id', $this->current_acounts_sembradas)->forceDelete();
        }

        if (count($this->tickets_compra_sembrados) >= 1) {
            ProviderOrderAfipTicket::whereIn('id', $this->tickets_compra_sembrados)->forceDelete();
        }

        parent::tearDown();
    }

    // =============================================================================================
    // SIEMBRA
    // =============================================================================================

    /**
     * Siembra una venta terminada con un `created_at` exacto.
     *
     * @param  string  $momento  'Y-m-d H:i:s'
     * @param  float   $total
     * @return \App\Models\Sale
     */
    protected function venta_en($momento, $total)
    {
        $creada = Carbon::parse($momento);

        $sale = Sale::create([
            'user_id'    => $this->owner->id,
            'client_id'  => is_null($this->cliente) ? null : $this->cliente->id,
            'moneda_id'  => 1,
            'total'      => $total,
            'terminada'  => 1,
            /* Se pasa en el create a propósito: Eloquent respeta `created_at` si ya viene sucio. */
            'created_at' => $creada,
            'updated_at' => $creada,
        ]);

        $this->sales_sembradas[] = $sale->id;

        return $sale;
    }

    /**
     * Siembra un gasto con un `created_at` exacto.
     *
     * @param  string     $momento
     * @param  float      $monto
     * @param  float|null $importe_iva  Si viene, el gasto entra además en `iva_credito()`.
     * @return \App\Models\Expense
     */
    protected function gasto_en($momento, $monto, $importe_iva = null)
    {
        $creado = Carbon::parse($momento);

        $expense = Expense::create([
            'user_id'     => $this->owner->id,
            'amount'      => $monto,
            'importe_iva' => $importe_iva,
            'created_at'  => $creado,
            'updated_at'  => $creado,
        ]);

        $this->expenses_sembrados[] = $expense->id;

        return $expense;
    }

    /**
     * Siembra una nota de crédito (la fuente de `devoluciones()`) con un `created_at` exacto.
     *
     * Sin `afip_ticket` asociado a propósito: así el `COALESCE(iva_nota_credito.iva_declarado, 0)`
     * de `expresion_devolucion_neta_de_iva()` la deja entera y el monto esperado es el `haber`
     * pelado. `moneda_id = 1` porque es la moneda que `aplicar_filtro_moneda_current_acount()` toma
     * por defecto cuando el filtro no viene.
     *
     * @param  string  $momento
     * @param  float   $haber
     * @return \App\Models\CurrentAcount
     */
    protected function nota_credito_en($momento, $haber)
    {
        $creada = Carbon::parse($momento);

        $current_acount = CurrentAcount::create([
            'user_id'    => $this->owner->id,
            'client_id'  => is_null($this->cliente) ? null : $this->cliente->id,
            'status'     => 'nota_credito',
            'haber'      => $haber,
            'moneda_id'  => 1,
            'created_at' => $creada,
            'updated_at' => $creada,
        ]);

        $this->current_acounts_sembradas[] = $current_acount->id;

        return $current_acount;
    }

    /**
     * Siembra una factura de compra con IVA, fechada por `issued_at`.
     *
     * 🔴 `issued_at` es la ÚNICA columna `timestamp` del cambio que no se fecha por `created_at`,
     * y es la que alimenta toda la Posición Fiscal (IVA crédito y percepciones sufridas). Sin este
     * helper, las 10 líneas del cambio que tocan `issued_at` no tendrían ninguna aserción de borde.
     *
     * @param  string  $momento
     * @param  float   $total_iva
     * @return \App\Models\ProviderOrderAfipTicket
     */
    protected function factura_de_compra_en($momento, $total_iva)
    {
        $emitida = Carbon::parse($momento);

        $ticket = ProviderOrderAfipTicket::create([
            'user_id'    => $this->owner->id,
            'issued_at'  => $emitida,
            'total_iva'  => $total_iva,
            'created_at' => $emitida,
            'updated_at' => $emitida,
        ]);

        $this->tickets_compra_sembrados[] = $ticket->id;

        return $ticket;
    }

    // =============================================================================================
    // LOS BORDES, TABLA POR TABLA
    // =============================================================================================

    /**
     * 🔴 EL TEST DE LA MISIÓN. Los dos extremos son inclusivos hasta el último segundo:
     * 00:00:00 del primer día entra, 23:59:59 del último día entra, y 00:00:00 del día siguiente
     * queda afuera.
     *
     * Si este test se pone rojo por el borde superior, el síntoma en producción es que los reportes
     * de contabilidad pierden todo lo facturado después de medianoche del último día del período.
     *
     * @test
     */
    public function los_dos_extremos_del_periodo_son_inclusivos_hasta_el_ultimo_segundo()
    {
        $base = ContabilidadRepository::ventas_brutas($this->owner->id, self::DESDE, self::HASTA);

        $this->venta_en(self::DESDE . ' 00:00:00', 100);          // primer instante del período
        $this->venta_en(self::HASTA . ' 23:59:59', 200);          // último instante del período
        $this->venta_en(self::DIA_SIGUIENTE . ' 00:00:00', 400);  // día siguiente: NO entra
        $this->venta_en(self::DIA_ANTERIOR . ' 23:59:59', 800);   // día anterior: NO entra

        $total = ContabilidadRepository::ventas_brutas($this->owner->id, self::DESDE, self::HASTA);

        $this->assertEqualsWithDelta(
            $base + 300,
            $total,
            self::DELTA,
            'El período tiene que incluir 00:00:00 del primer día y 23:59:59 del último, y excluir los días de afuera. '
                . 'Si falla por 200 de menos, se perdió el último día después de medianoche: es el bug que esta misión evita.'
        );
    }

    /**
     * Una venta a las 12:00 de un día del medio entra, para que el test de arriba no pueda pasar
     * por un filtro que no filtre nada.
     *
     * @test
     */
    public function una_venta_del_medio_del_periodo_entra()
    {
        $base = ContabilidadRepository::ventas_brutas($this->owner->id, self::DESDE, self::HASTA);

        $this->venta_en('2013-03-11 12:00:00', 500);

        $total = ContabilidadRepository::ventas_brutas($this->owner->id, self::DESDE, self::HASTA);

        $this->assertEqualsWithDelta($base + 500, $total, self::DELTA);
    }

    /**
     * Un período de un solo día (desde == hasta) tiene que traer lo de ese día entero, que es
     * exactamente la forma en que se llamaba la consulta que tumbó el VPS
     * (`date(created_at) >= hoy and date(created_at) <= hoy`).
     *
     * @test
     */
    public function un_periodo_de_un_solo_dia_trae_el_dia_entero()
    {
        $dia = '2013-03-11';

        $base = ContabilidadRepository::ventas_brutas($this->owner->id, $dia, $dia);

        $this->venta_en($dia . ' 00:00:00', 10);
        $this->venta_en($dia . ' 13:45:00', 20);
        $this->venta_en($dia . ' 23:59:59', 40);
        $this->venta_en('2013-03-12 00:00:00', 80); // día siguiente: afuera

        $total = ContabilidadRepository::ventas_brutas($this->owner->id, $dia, $dia);

        $this->assertEqualsWithDelta($base + 70, $total, self::DELTA);
    }

    /**
     * El mismo contrato de bordes sobre `expenses`, que es la otra tabla que el repositorio filtra
     * por `created_at` (gastos, comisiones de cobro y gastos pagados salen de ahí).
     *
     * @test
     */
    public function los_gastos_respetan_los_mismos_bordes()
    {
        $base = ContabilidadRepository::gastos_por_categoria($this->owner->id, self::DESDE, self::HASTA);
        $base_total = $this->sumar_montos($base);

        $this->gasto_en(self::DESDE . ' 00:00:00', 100);
        $this->gasto_en(self::HASTA . ' 23:59:59', 200);
        $this->gasto_en(self::DIA_SIGUIENTE . ' 00:00:00', 400); // afuera

        $despues = ContabilidadRepository::gastos_por_categoria($this->owner->id, self::DESDE, self::HASTA);

        $this->assertEqualsWithDelta(
            $base_total + 300,
            $this->sumar_montos($despues),
            self::DELTA,
            'Los gastos tienen que respetar los mismos bordes inclusivos que las ventas.'
        );
    }

    /**
     * El mismo contrato de bordes sobre `current_acounts`, la tercera tabla que el repositorio
     * filtra por `created_at` (notas de crédito, cobranzas y pagos a proveedores salen de ahí).
     *
     * Se prueba por `devoluciones()`, que es la pública más directa sobre esa tabla.
     *
     * @test
     */
    public function las_notas_de_credito_respetan_los_mismos_bordes()
    {
        $base = ContabilidadRepository::devoluciones($this->owner->id, self::DESDE, self::HASTA);

        $this->nota_credito_en(self::DESDE . ' 00:00:00', 100);
        $this->nota_credito_en(self::HASTA . ' 23:59:59', 200);
        $this->nota_credito_en(self::DIA_SIGUIENTE . ' 00:00:00', 400); // afuera
        $this->nota_credito_en(self::DIA_ANTERIOR . ' 23:59:59', 800);  // afuera

        $total = ContabilidadRepository::devoluciones($this->owner->id, self::DESDE, self::HASTA);

        $this->assertEqualsWithDelta(
            $base + 300,
            $total,
            self::DELTA,
            'Las notas de crédito tienen que respetar los mismos bordes inclusivos que las ventas. '
                . 'Si falla por 200 de menos, se perdió el último día después de medianoche.'
        );
    }

    /**
     * 🔴 El borde sobre `issued_at`, que es la cuarta columna del cambio y la única que no se fecha
     * por `created_at`. Alimenta el IVA crédito y las percepciones sufridas, o sea toda la Posición
     * Fiscal.
     *
     * Siembra las dos fuentes de `iva_credito()` a la vez —una factura de compra (por `issued_at`)
     * y un gasto con IVA (por `created_at`)— porque la pública las suma y así las dos privadas
     * quedan cubiertas por el mismo test.
     *
     * @test
     */
    public function el_iva_credito_respeta_los_mismos_bordes_por_issued_at()
    {
        $base = ContabilidadRepository::iva_credito($this->owner->id, self::DESDE, self::HASTA);

        $this->factura_de_compra_en(self::DESDE . ' 00:00:00', 100);
        $this->factura_de_compra_en(self::HASTA . ' 23:59:59', 200);
        $this->factura_de_compra_en(self::DIA_SIGUIENTE . ' 00:00:00', 400); // afuera
        $this->factura_de_compra_en(self::DIA_ANTERIOR . ' 23:59:59', 800);  // afuera

        $this->gasto_en(self::HASTA . ' 23:59:59', 1000, 50);          // el IVA del gasto SÍ entra
        $this->gasto_en(self::DIA_SIGUIENTE . ' 00:00:00', 1000, 70);  // afuera

        $total = ContabilidadRepository::iva_credito($this->owner->id, self::DESDE, self::HASTA);

        $this->assertEqualsWithDelta(
            $base + 350,
            $total,
            self::DELTA,
            'El IVA crédito tiene que respetar los bordes en sus DOS fuentes: la factura de compra '
                . '(fechada por issued_at) y el gasto (fechado por created_at). Si falla por 250 de '
                . 'menos, se perdieron los dos registros de las 23:59:59 del último día.'
        );
    }

    // =============================================================================================
    // LA GARANTÍA LOCAL
    // =============================================================================================

    /**
     * 🔴 LAS TRES PRIVADAS NORMALIZAN EL RANGO ELLAS MISMAS.
     *
     * `query_iva_credito_compras`, `query_iva_credito_gastos` y `query_lineas_para_sale_tax` son las
     * tres funciones del repositorio que filtran por columna cruda y NO eran las que llamaban a
     * `rango()`: se lo aplicaban sus llamadores. Mientras eso era cierto, todo andaba; el día que
     * alguien agregue un llamador que pase `'2013-03-12'` pelado, el `<=` compara contra
     * `2013-03-12 00:00:00` y el reporte pierde el último día entero, en silencio.
     *
     * Este test le pasa a las tres, directamente, una fecha pelada, y verifica que el binding que
     * sale hacia MySQL tenga la hora del final del día. Es el único test del archivo que no siembra
     * datos: lo que mide es el SQL, no el resultado.
     *
     * Va por Reflection a propósito. Llamarlas por sus públicas no probaría nada — las públicas
     * normalizan antes, que es exactamente la garantía contextual que este test existe para no
     * necesitar.
     *
     * @test
     */
    public function las_tres_privadas_normalizan_el_rango_aunque_les_pasen_una_fecha_pelada()
    {
        $sale_tax = new SaleTax();
        $sale_tax->apply_to_all = 1;

        /* [nombre de la privada, argumentos después de ($user_id, $desde, $hasta)] */
        $casos = array(
            array('query_iva_credito_compras', array()),
            array('query_iva_credito_gastos', array()),
            array('query_lineas_para_sale_tax', array($sale_tax)),
        );

        foreach ($casos as $caso) {
            list($nombre, $extra) = $caso;

            $metodo = new ReflectionMethod(ContabilidadRepository::class, $nombre);
            $metodo->setAccessible(true);

            $argumentos = array_merge(
                array($this->owner->id, self::DESDE, self::HASTA),
                $extra
            );

            $query = $metodo->invokeArgs(null, $argumentos);

            $bindings = $this->bindings_de_fecha($query);

            $this->assertContains(
                self::DESDE . ' 00:00:00',
                $bindings,
                $nombre . '() tiene que llevar el desde al arranque del día aunque le pasen la fecha pelada.'
            );

            $this->assertContains(
                self::HASTA . ' 23:59:59',
                $bindings,
                $nombre . '() tiene que llevar el hasta al final del día aunque le pasen la fecha pelada. '
                    . 'Si no lo hace, un llamador futuro que pase la fecha pelada pierde el último día del período.'
            );
        }
    }

    // =============================================================================================
    // AUXILIARES
    // =============================================================================================

    /**
     * Devuelve los bindings del query formateados como los va a recibir MySQL ('Y-m-d H:i:s').
     *
     * Se usa `prepareBindings()` de la conexión, que es el mismo paso por el que pasan de verdad:
     * así el test mide el string que viaja, no el objeto Carbon que quedó guardado.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return array<int,string>
     */
    protected function bindings_de_fecha($query)
    {
        /* Un Eloquent Builder delega en el query builder de abajo; uno plano ya lo es. */
        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;

        $preparados = $base->getConnection()->prepareBindings($base->getBindings());

        $solo_strings = array();

        foreach ($preparados as $valor) {
            if (is_string($valor)) {
                $solo_strings[] = $valor;
            }
        }

        return $solo_strings;
    }

    /**
     * Suma los montos de la estructura que devuelve `gastos_por_categoria()`, sin depender de la
     * forma exacta de cada fila (puede ser array u objeto según la versión).
     *
     * @param  mixed  $filas
     * @return float
     */
    protected function sumar_montos($filas)
    {
        $total = 0.0;

        foreach ((array) $filas as $fila) {
            $f = (array) $fila;

            foreach (array('monto', 'total', 'importe') as $clave) {
                if (isset($f[$clave])) {
                    $total += (float) $f[$clave];
                    break;
                }
            }
        }

        return $total;
    }
}
