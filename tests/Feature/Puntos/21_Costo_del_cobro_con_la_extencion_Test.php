<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 21 — EL COSTO DEL COBRO PARA EL COMERCIO QUE **SÍ** COMPRÓ EL MÓDULO.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  POR QUÉ ESTE ARCHIVO EXISTE, AL LADO DEL 11
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El archivo 11 mide el costo del comercio SIN la extensión, y da cero. Eso está bien y hay que
 *  seguir midiéndolo — pero era la única medición que había, y por eso nadie vio el N+1 del
 *  comercio que sí compró el módulo:
 *
 *      consultas CON la extencion : 112   (22 a movimiento_punto*)
 *      consultas SIN la extencion :  25
 *
 *  Ese número salió de un cliente con 21 ventas de historia, o sea ~4,1 consultas EXTRA por
 *  venta histórica, creciendo para siempre. `reconciliar_cuenta_corriente()` traía TODOS los
 *  `sale_id` con débito en la cuenta —sin filtro de fecha ni de estado— y llamaba a
 *  `reconciliar_venta()` por cada uno; cada visita pagaba `load('articles')`, la consulta de
 *  débitos, las dos de `factor_nota_credito()` y la de `mapa_de_movimientos()`.
 *
 *  Y esto no cuelga de una pantalla de reportes: cuelga del final de
 *  `CurrentAcountPagoHelper::init()`, o sea DEL COBRO DE TODOS LOS DÍAS, y de `checkPagos()`,
 *  que corre en `ProcessCheckSaldosChunk` y `ProcessRecalculateCurrentAcounts` sobre todas las
 *  cuentas de todos los clientes. Un mayorista con 2.000 ventas pagaba ~8.000 consultas por
 *  cobro.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LO QUE SE MIDE ES LA PENDIENTE, NO EL NÚMERO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Las aserciones centrales comparan el costo de un cobro con POCA historia contra el mismo
 *  cobro con MUCHA historia. Un tope absoluto solo envejece mal (cualquier cambio ajeno al
 *  módulo lo mueve y alguien lo sube sin pensar); lo que no puede volver a pasar es que el
 *  costo CREZCA CON LA HISTORIA DEL CLIENTE. Igual se deja un techo absoluto sobre las
 *  consultas del módulo, que sí es un número que el módulo controla entero.
 *
 *  Los dos caminos del cobro se miden por separado, porque son dos enganches distintos:
 *    - `current_date = 1` (el default de la SPA) -> `CurrentAcountPagoHelper::init()` derecho;
 *    - `current_date = 0`                        -> `CurrentAcountHelper::checkPagos()`.
 */
class Costo_del_cobro_con_la_extencion_Test extends PuntosTestCase
{
    /** Las cuatro tablas del módulo, iguales que en el archivo 11. */
    const TABLAS_DE_PUNTOS = [
        'movimiento_puntos',
        'movimiento_punto_consumos',
        'sistemas_de_puntos',
        'price_type_sistema_de_puntos',
    ];

    /** El total con IVA de la primera venta. Neto 100.000, o sea 100 puntos. */
    const TOTAL = 121000;

    /**
     * Cuánto se le suma al total de cada venta siguiente.
     *
     * 🔴 NO ES UN ADORNO: `SaleController@venta_ya_cread()` descarta EN SILENCIO (con un 200 y
     * cuerpo vacío) una venta del mismo cliente, el mismo vendedor y EL MISMO TOTAL creada
     * dentro de los cinco segundos anteriores. Un test que arma la historia en un loop crea las
     * quince ventas en el mismo segundo, así que sin totales distintos entraría solo la primera
     * y el test mediría una cuenta corriente vacía. Son $1.210 con IVA = $1.000 de neto = 1
     * punto más por venta, para que los números sigan siendo redondos.
     */
    const PASO = 1210;

    /**
     * Cuántas consultas del módulo puede costar UN cobro, sin importar la historia del cliente.
     *
     * Medido el 22/8/2026 con 15 ventas de historia: 2 consultas por cobro (la lectura de a
     * muchos del libro, y el INSERT del lote nuevo). El techo se deja en 8 —cuatro veces lo
     * medido— para que un cambio legítimo no lo rompa; con el N+1 puesto ese mismo cobro
     * gastaba 16, así que el techo sigue teniendo con qué distinguir.
     */
    const TECHO_CONSULTAS_DEL_MODULO = 8;

    /**
     * El total con IVA de la enésima venta de la serie (la primera es la número 1).
     *
     * @param  int  $numero
     * @return int
     */
    protected static function total_de_la_venta($numero)
    {
        return self::TOTAL + (($numero - 1) * self::PASO);
    }

    /**
     * Los puntos que otorga la enésima venta de la serie.
     *
     * @param  int  $numero
     * @return int
     */
    protected static function puntos_de_la_venta($numero)
    {
        return 100 + ($numero - 1);
    }

    /**
     * @return void
     */
    protected function empezar_a_medir()
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    /**
     * Cuántas de las consultas registradas tocan una tabla del módulo.
     *
     * @return int
     */
    protected function consultas_de_puntos()
    {
        $total = 0;

        foreach (DB::getQueryLog() as $consulta) {

            foreach (self::TABLAS_DE_PUNTOS as $tabla) {

                if (strpos($consulta['query'], $tabla) !== false) {
                    $total++;
                    break;
                }
            }
        }

        return $total;
    }

    /**
     * @return int
     */
    protected function consultas_totales()
    {
        return count(DB::getQueryLog());
    }

    /**
     * El escenario: la extensión, el programa y el cliente de cuenta corriente con su cuenta.
     *
     * @return \App\Models\Client
     */
    protected function escenario()
    {
        $this->dar_extencion();

        $this->crear_programa(['puntos_cada' => 1000, 'puntos_por_tramo' => 1]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        $this->asegurar_cuenta_corriente($cliente);

        return $cliente;
    }

    /**
     * Guarda una venta de cuenta corriente y la cobra entera, midiendo lo que costó el COBRO.
     *
     * Se mide solo el cobro y no el alta de la venta: el enganche que tenía el N+1 es el de la
     * cuenta corriente, y el alta pasa por otro camino (`SaleHelper::attachProperies()`).
     *
     * @param  \App\Models\Client  $cliente
     * @param  int                 $numero        Cuál de la serie es (ver self::PASO).
     * @param  int                 $current_date  1 = init() derecho; 0 = checkPagos().
     * @return array  ['puntos' => int, 'total' => int]
     */
    protected function una_venta_y_su_cobro($cliente, $numero, $current_date = 1)
    {
        $total = self::total_de_la_venta($numero);

        $this->crear_venta($this->payload_venta($cliente->id, $total));

        $this->empezar_a_medir();

        $this->pagar($cliente, $total, $current_date)->assertStatus(201);

        return [
            'puntos' => $this->consultas_de_puntos(),
            'total'  => $this->consultas_totales(),
        ];
    }

    /**
     * 🔴 LA MEDICIÓN CENTRAL: el cobro de todos los días (`current_date = 1`).
     *
     * Se cobran quince ventas seguidas y se comparan la tercera y la decimoquinta. Si el costo
     * del cobro crece con la cantidad de ventas ya saldadas del cliente, volvió el N+1.
     *
     * @group puntos
     * @test
     */
    public function el_cobro_de_todos_los_dias_no_se_encarece_con_la_historia_del_cliente()
    {
        $cliente = $this->escenario();

        $mediciones = [];

        for ($i = 1; $i <= 15; $i++) {
            $mediciones[$i] = $this->una_venta_y_su_cobro($cliente, $i, 1);
        }

        $corta = $mediciones[3];
        $larga = $mediciones[15];

        $this->assertLessThanOrEqual(
            self::TECHO_CONSULTAS_DEL_MODULO,
            $larga['puntos'],
            'Un cobro le costó al módulo '.$larga['puntos'].' consultas con 15 ventas de historia. '.
            'El reconciliador de la cuenta corriente tiene que leer el libro DE A MUCHOS: si lee '.
            'venta por venta, el mayorista con 2.000 ventas paga miles de consultas por cobro.'
        );

        $this->assertLessThanOrEqual(
            2,
            $larga['puntos'] - $corta['puntos'],
            'Las consultas del módulo por cobro crecieron de '.$corta['puntos'].' (3 ventas de historia) '.
            'a '.$larga['puntos'].' (15 ventas). Tienen que ser CONSTANTES: el costo no puede depender '.
            'de cuántas ventas viejas y ya reconciliadas tenga el cliente.'
        );

        $this->assertLessThanOrEqual(
            6,
            $larga['total'] - $corta['total'],
            'El cobro entero pasó de '.$corta['total'].' a '.$larga['total'].' consultas al agregarle '.
            '12 ventas de historia al cliente. Eso es un N+1: son ~'.
            round(($larga['total'] - $corta['total']) / 12, 1).' consultas extra por venta histórica.'
        );
    }

    /**
     * El otro camino: `current_date = 0`, que re-imputa la cuenta entera por `checkPagos()`.
     *
     * Es el más caro de los dos y el que corren los jobs de fondo sobre todos los clientes.
     *
     * @group puntos
     * @test
     */
    public function el_cobro_por_checkpagos_tampoco_se_encarece_con_la_historia()
    {
        $cliente = $this->escenario();

        $mediciones = [];

        for ($i = 1; $i <= 12; $i++) {
            $mediciones[$i] = $this->una_venta_y_su_cobro($cliente, $i, 0);
        }

        $corta = $mediciones[3];
        $larga = $mediciones[12];

        $this->assertLessThanOrEqual(
            self::TECHO_CONSULTAS_DEL_MODULO,
            $larga['puntos'],
            'Por checkPagos() el módulo gastó '.$larga['puntos'].' consultas en un solo cobro.'
        );

        $this->assertLessThanOrEqual(
            2,
            $larga['puntos'] - $corta['puntos'],
            'Las consultas del módulo por cobro pasaron de '.$corta['puntos'].' a '.$larga['puntos'].
            ' al crecer la historia del cliente: volvió el N+1 en el camino de checkPagos().'
        );
    }

    /**
     * 🔴 EL BARRIDO DE LOS JOBS DE FONDO. `ProcessCheckSaldosChunk` y
     * `ProcessRecalculateCurrentAcounts` llaman a `checkPagos()` sobre TODAS las cuentas de
     * TODOS los clientes, y ahí no hay ninguna venta nueva que reconciliar: todo está en su
     * lugar y el reconciliador no tiene que escribir una sola fila.
     *
     * Con el N+1, esa corrida "que no hace nada" costaba 4 consultas por venta de historia.
     *
     * @group puntos
     * @test
     */
    public function una_corrida_de_checkpagos_que_no_cambia_nada_cuesta_un_punado_de_consultas()
    {
        $cliente = $this->escenario();

        $cuenta = $this->credit_account($cliente);

        for ($i = 1; $i <= 15; $i++) {
            $total = self::total_de_la_venta($i);
            $this->crear_venta($this->payload_venta($cliente->id, $total));
            $this->pagar($cliente, $total, 1)->assertStatus(201);
        }

        // Todo ya está reconciliado: esta corrida no puede escribir nada.
        $this->empezar_a_medir();

        CurrentAcountHelper::checkPagos($cuenta->id);

        $consultas_del_modulo = $this->consultas_de_puntos();

        $this->assertLessThanOrEqual(
            self::TECHO_CONSULTAS_DEL_MODULO,
            $consultas_del_modulo,
            'Una corrida de checkPagos() sobre una cuenta que ya está reconciliada le costó al '.
            'módulo '.$consultas_del_modulo.' consultas con 15 ventas de historia. Tiene que ser un '.
            'número CONSTANTE: el reconciliador compara el estado escrito contra el que corresponde '.
            'de a muchos, y sale sin visitar una sola venta cuando coinciden.'
        );
    }

    /**
     * Contraste obligatorio: el módulo SIGUE otorgando bien los puntos.
     *
     * Sin esto, los tests de arriba pasarían perfecto si el reconciliador se hubiera "optimizado"
     * hasta no reconciliar nada. Quince ventas cobradas son quince lotes de 100 puntos.
     *
     * @group puntos
     * @test
     */
    public function con_el_filtro_puesto_los_puntos_se_siguen_otorgando_igual()
    {
        $cliente = $this->escenario();

        $puntos_esperados = 0;

        for ($i = 1; $i <= 15; $i++) {
            $total = self::total_de_la_venta($i);
            $this->crear_venta($this->payload_venta($cliente->id, $total));
            $this->pagar($cliente, $total, 1)->assertStatus(201);
            $puntos_esperados += self::puntos_de_la_venta($i);
        }

        $lotes = $this->movimientos_del_cliente($cliente, 'ganados');

        $this->assertCount(15, $lotes, 'Cada venta cobrada tiene que dejar su lote.');

        $this->assertEqualsWithDelta(
            $puntos_esperados,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Las quince ventas cobradas tienen que sumar '.$puntos_esperados.' puntos. Si el filtro de '.
            'ventas a reconciliar dejara alguna afuera, acá faltarían puntos.'
        );
    }

    /**
     * 🔴 Y EL CASO QUE EL FILTRO NO PUEDE PERDERSE: un pago que salda MÁS DE UNA VENTA.
     *
     * "Reconciliar solo la venta del pago" sería el atajo obvio y es incorrecto: la imputación
     * FIFO cascadea, así que un solo cobro puede saldar tres ventas de una. Las tres tienen que
     * salir con sus puntos.
     *
     * @group puntos
     * @test
     */
    public function un_solo_pago_que_salda_tres_ventas_otorga_los_puntos_de_las_tres()
    {
        $cliente = $this->escenario();

        $deuda  = 0;
        $puntos = 0;

        for ($i = 1; $i <= 3; $i++) {
            $total = self::total_de_la_venta($i);
            $this->crear_venta($this->payload_venta($cliente->id, $total));
            $deuda  += $total;
            $puntos += self::puntos_de_la_venta($i);
        }

        $this->assertCount(0, $this->movimientos_del_cliente($cliente, 'ganados'), 'Sin cobrar no hay puntos.');

        $this->pagar($cliente, $deuda, 1)->assertStatus(201);

        $this->assertCount(
            3,
            $this->movimientos_del_cliente($cliente, 'ganados'),
            'Un pago que salda tres ventas tiene que dejar los tres lotes: la imputación FIFO cascadea.'
        );

        $this->assertEqualsWithDelta($puntos, $this->saldo_de_puntos($cliente), self::DELTA);
    }

    /**
     * 🔴 Y EL CASO INVERSO: borrar un pago DESALDA una venta vieja, y sus puntos tienen que
     * volver atrás.
     *
     * Es el otro motivo por el que el filtro no puede ser "solo la venta del pago": `checkPagos()`
     * borra las imputaciones de la cuenta entera y las vuelve a repartir, así que un pago de hoy
     * puede desaldar una venta de hace meses.
     *
     * @group puntos
     * @test
     */
    public function borrar_un_pago_desalda_una_venta_vieja_y_le_revierte_los_puntos()
    {
        $cliente = $this->escenario();

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::total_de_la_venta(1)));

        $deuda  = self::total_de_la_venta(1);
        $puntos = self::puntos_de_la_venta(1);

        // Historia posterior, para que la venta vieja quede lejos en la cola.
        for ($i = 2; $i <= 6; $i++) {
            $total = self::total_de_la_venta($i);
            $this->crear_venta($this->payload_venta($cliente->id, $total));
            $deuda  += $total;
            $puntos += self::puntos_de_la_venta($i);
        }

        $respuesta = $this->pagar($cliente, $deuda, 0);
        $respuesta->assertStatus(201);

        $this->assertEqualsWithDelta($puntos, $this->saldo_de_puntos($cliente), self::DELTA, 'Las seis ventas quedaron saldadas.');

        $pago_id = json_decode($respuesta->getContent(), true)['current_acount']['id'];

        $this->deleteJson('api/current-acount/client/'.$pago_id)->assertStatus(200);

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Al borrar el pago las seis ventas quedaron impagas: los seis lotes tienen que estar '.
            'revertidos. Si el filtro de ventas a reconciliar solo mirara la venta del pago, acá '.
            'quedarían puntos vivos de ventas que el cliente no pagó.'
        );

        $reversos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $reversos, 'La venta más vieja también tiene que estar revertida.');
    }
}
