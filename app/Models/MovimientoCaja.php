<?php

namespace App\Models;

use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Un ingreso o un egreso de una caja, dentro de una apertura (turno).
 *
 * 🔴 `movimiento_cajas` NO tiene `user_id`: el dueño sale de la caja (`cajas.user_id`, por
 * `caja_id`). Quien lea un movimiento por id tiene que cruzarlo con la caja del dueño
 * (ver MovimientoCajaController::movimiento_del_dueno()).
 *
 * Misión movimientos-caja-manuales (9/10/2026): solo un movimiento MANUAL de la apertura abierta se
 * puede corregir o eliminar desde Tesorería → Movimientos. Lo que generó el sistema (una venta, un
 * gasto, un pago, una transferencia, una compensación, el pago de una comisión) se corrige desde la
 * operación que lo originó: tocarlo desde la caja dejaba la caja y la operación diciendo dos cosas
 * distintas. Quién es quién lo deciden origen_automatico() y motivo_no_editable().
 */
class MovimientoCaja extends Model
{
    protected $guarded = [];

    /**
     * `manual` viaja como true / false / null. El cast no toca un NULL: una fila anterior a la
     * columna sigue siendo NULL (y se decide por concepto, ver origen_automatico()).
     */
    protected $casts = [
        'manual' => 'boolean',
    ];

    /*
     * Los conceptos que el sistema carga con id FIJO. Los escriben, con el número pelado:
     * SaleCajaHelper (1), ExpenseCajaHelper (2), CurrentAcountCajaHelper (3 y 4) y
     * MovimientoEntreCajaHelper (5). Los de 7 a 10 son las constantes de
     * DeleteCajaCompensacionHelper (se toman de ahí para no tener dos fuentes). 6 es "Varios", el
     * concepto de los movimientos que carga una persona.
     */
    const CONCEPTO_VENTA = 1;
    const CONCEPTO_GASTO = 2;
    const CONCEPTO_PAGO_DE_CLIENTE = 3;
    const CONCEPTO_PAGO_A_PROVEEDOR = 4;
    const CONCEPTO_MOVIMIENTO_ENTRE_CAJAS = 5;
    const CONCEPTO_VARIOS = 6;
    const CONCEPTO_ELIMINACION_VENTA = DeleteCajaCompensacionHelper::CONCEPTO_ELIMINACION_VENTA;
    const CONCEPTO_ELIMINACION_GASTO = DeleteCajaCompensacionHelper::CONCEPTO_ELIMINACION_GASTO;
    const CONCEPTO_ELIMINACION_PAGO_CLIENTE = DeleteCajaCompensacionHelper::CONCEPTO_ELIMINACION_PAGO_CLIENTE;
    const CONCEPTO_ELIMINACION_PAGO_PROVEEDOR = DeleteCajaCompensacionHelper::CONCEPTO_ELIMINACION_PAGO_PROVEEDOR;

    /**
     * "Pago a Vendedor" NO tiene id fijo: PagoVendedorHelper lo busca por nombre (lo siembra
     * ConceptoMovimientoCajaPagoVendedorSeeder) y, si no está, cae a "Varios".
     */
    const NOMBRE_CONCEPTO_PAGO_A_VENDEDOR = 'Pago a Vendedor';

    /** Las claves que devuelve origen_automatico(). */
    const ORIGEN_VENTA = 'venta';
    const ORIGEN_GASTO = 'gasto';
    const ORIGEN_PAGO = 'pago';
    const ORIGEN_TRANSFERENCIA = 'transferencia';
    const ORIGEN_COMPENSACION = 'compensacion';
    const ORIGEN_PAGO_VENDEDOR = 'pago_vendedor';
    const ORIGEN_SISTEMA = 'sistema';

    /**
     * El motivo de un movimiento de una apertura que ya no es la vigente de su caja. Va primero:
     * ni un manual se toca una vez cerrado el turno (el arqueo ya se hizo con ese número).
     */
    const MOTIVO_APERTURA_CERRADA = 'No se pueden editar movimientos de una apertura ya cerrada.';

    /**
     * El motivo de cada origen automático. 🔴 La SPA los muestra TAL CUAL en un aviso: son textos
     * para el comerciante, no para un programador. Cada uno dice también cómo ajustar la caja
     * cuando la operación de origen ya no está o no tiene pantalla propia (una transferencia no se
     * corrige en ningún otro lado: se compensa con un manual en cada caja).
     */
    const MOTIVOS_POR_ORIGEN = [
        self::ORIGEN_VENTA          => 'Este movimiento lo generó una VENTA: se corrige desde la venta, no desde la caja. Si la venta ya no está, ajustá la caja con un movimiento manual.',
        self::ORIGEN_GASTO          => 'Este movimiento lo generó un GASTO: se corrige desde el gasto, no desde la caja. Si el gasto ya no está, ajustá la caja con un movimiento manual.',
        self::ORIGEN_PAGO           => 'Este movimiento lo generó un PAGO de cuenta corriente: se corrige desde la cuenta corriente, no desde la caja. Si el pago ya no está, ajustá la caja con un movimiento manual.',
        self::ORIGEN_TRANSFERENCIA  => 'Este movimiento es parte de una transferencia entre cajas y no se modifica. Para corregirla, cargá un movimiento manual en cada caja.',
        self::ORIGEN_COMPENSACION   => 'Este movimiento lo generó el sistema al eliminar una venta, un gasto o un pago con «Compensar caja»: no se corrige a mano. Si hace falta, ajustá la caja con un movimiento manual.',
        self::ORIGEN_PAGO_VENDEDOR  => 'Este movimiento lo generó un pago de comisión a un vendedor: no se corrige desde la caja. Si hace falta, ajustá la caja con un movimiento manual.',
        self::ORIGEN_SISTEMA        => 'Este movimiento lo generó el sistema: se corrige desde la operación que lo originó, o ajustando la caja con un movimiento manual.',
    ];

    /**
     * El id del concepto "Pago a Vendedor": false = todavía no se preguntó en este proceso; null =
     * no existe; un entero = el id. Se pregunta una vez y se recuerda: el listado lo necesita por
     * fila y no tiene sentido una consulta por movimiento.
     *
     * @var int|null|false
     */
    private static $id_concepto_pago_a_vendedor = false;

    /**
     * true = la columna `manual` ya existe (se recuerda por proceso); null = todavía no se vio.
     * Un "no existe" NO se recuerda: ver hay_columna_manual().
     *
     * @var bool|null
     */
    private static $hay_columna_manual = null;

    function scopeWithAll($q) {
        $q->with('sale');
    }

    function concepto_movimiento_caja() {
        return $this->belongsTo(ConceptoMovimientoCaja::class);
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }

    function apertura_caja() {
        return $this->belongsTo(AperturaCaja::class);
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    /**
     * ¿Ya está la columna `manual`?
     *
     * 🔴 Existe por la ventana del deploy: el upgrade sube los archivos y DESPUÉS corre las
     * migraciones. En ese rato la columna no existe, y nombrarla en el `create()` de
     * MovimientoCajaHelper::crear_movimiento() tumbaría TODA venta, gasto o pago que mueva caja.
     * Mismo patrón que VarianteEnPresupuestoEsquemaHelper.
     *
     * Solo se recuerda el SÍ: en el caso normal (la columna está) es una consulta por proceso. Un
     * NO se vuelve a preguntar la próxima vez, para que un proceso largo que arrancó antes de la
     * migración (un worker de cola) se entere solo cuando la columna aparece, sin reiniciarlo.
     *
     * @return bool
     */
    static function hay_columna_manual() {

        if (self::$hay_columna_manual === true) {
            return true;
        }

        $existe = Schema::hasColumn('movimiento_cajas', 'manual');

        if ($existe) {
            self::$hay_columna_manual = true;
        }

        return $existe;
    }

    /**
     * Olvida lo recordado por proceso (la columna y el id de "Pago a Vendedor"): para un proceso
     * largo que corre la migración o el seeder en el medio, o un test que crea el concepto.
     *
     * @return void
     */
    static function olvidar_cache() {
        self::$hay_columna_manual = null;
        self::$id_concepto_pago_a_vendedor = false;
    }

    /**
     * El id del concepto "Pago a Vendedor", o null si no existe. Una consulta por proceso.
     *
     * @return int|null
     */
    static function id_concepto_pago_a_vendedor() {

        if (self::$id_concepto_pago_a_vendedor === false) {

            $concepto = ConceptoMovimientoCaja::where('name', self::NOMBRE_CONCEPTO_PAGO_A_VENDEDOR)
                                            ->orderBy('id')
                                            ->first();

            self::$id_concepto_pago_a_vendedor = is_null($concepto) ? null : (int) $concepto->id;
        }

        return self::$id_concepto_pago_a_vendedor;
    }

    /**
     * El origen automático que se deduce de un concepto, o null si el concepto no es de sistema
     * ("Varios" o uno creado por el comercio).
     *
     * @param  int  $concepto_id
     * @return string|null
     */
    static function origen_por_concepto($concepto_id) {

        $concepto_id = (int) $concepto_id;

        if ($concepto_id === self::CONCEPTO_VENTA) {
            return self::ORIGEN_VENTA;
        }

        if ($concepto_id === self::CONCEPTO_GASTO) {
            return self::ORIGEN_GASTO;
        }

        if (in_array($concepto_id, [self::CONCEPTO_PAGO_DE_CLIENTE, self::CONCEPTO_PAGO_A_PROVEEDOR], true)) {
            return self::ORIGEN_PAGO;
        }

        if ($concepto_id === self::CONCEPTO_MOVIMIENTO_ENTRE_CAJAS) {
            return self::ORIGEN_TRANSFERENCIA;
        }

        if (in_array($concepto_id, [
            self::CONCEPTO_ELIMINACION_VENTA,
            self::CONCEPTO_ELIMINACION_GASTO,
            self::CONCEPTO_ELIMINACION_PAGO_CLIENTE,
            self::CONCEPTO_ELIMINACION_PAGO_PROVEEDOR,
        ], true)) {
            return self::ORIGEN_COMPENSACION;
        }

        $id_pago_a_vendedor = self::id_concepto_pago_a_vendedor();

        if (!is_null($id_pago_a_vendedor) && $concepto_id === $id_pago_a_vendedor) {
            return self::ORIGEN_PAGO_VENDEDOR;
        }

        return null;
    }

    /**
     * Qué operación generó este movimiento, o null si lo cargó una persona.
     *
     * El orden importa:
     * 1. Lo que está ATADO a una operación (`sale_id`, `expense_id`, `current_acount_id`,
     *    `movimiento_entre_caja_id`) es de esa operación, diga lo que diga la marca. Hoy solo se
     *    persisten los dos primeros: `current_acount_id` está comentado en CurrentAcountCajaHelper
     *    y `movimiento_entre_caja_id` se pierde en crear_movimiento(). Se miran igual por si algún
     *    día se guardan.
     * 2. Marca `manual` en true → lo cargó una persona.
     * 3. Por concepto (los de sistema, ver origen_por_concepto()).
     * 4. Marca en false sin concepto de sistema → "sistema" (ej.: el pago a vendedor que cayó a
     *    "Varios" porque falta su concepto).
     * 5. Marca NULL (fila anterior a la columna) sin concepto de sistema → manual viejo (null).
     *
     * @return string|null  Una de las constantes ORIGEN_*, o null si es manual.
     */
    function origen_automatico() {

        if (!empty($this->sale_id)) {
            return self::ORIGEN_VENTA;
        }

        if (!empty($this->expense_id)) {
            return self::ORIGEN_GASTO;
        }

        if (!empty($this->current_acount_id)) {
            return self::ORIGEN_PAGO;
        }

        if (!empty($this->movimiento_entre_caja_id)) {
            return self::ORIGEN_TRANSFERENCIA;
        }

        $manual = $this->manual;

        if (!is_null($manual) && (bool) $manual) {
            return null;
        }

        $origen = self::origen_por_concepto($this->concepto_movimiento_caja_id);

        if (!is_null($origen)) {
            return $origen;
        }

        if (!is_null($manual)) {
            return self::ORIGEN_SISTEMA;
        }

        return null;
    }

    /**
     * ¿La apertura ya no es la vigente de su caja? Cerrada (`cerrada_at`), o la caja apunta a otra
     * apertura (o a ninguna: CajaCierreHelper deja `current_apertura_caja_id` en null). Es la misma
     * regla que mira la SPA (`apertura_caja_id != caja.current_apertura_caja_id`), y la que necesita
     * MovimientoCajaHelper::recalcular_saldos(null, $caja_id), que recalcula SOLO la apertura
     * vigente de la caja.
     *
     * @param  \App\Models\AperturaCaja|null  $apertura
     * @param  \App\Models\Caja|null  $caja
     * @return bool
     */
    static function apertura_esta_cerrada($apertura, $caja) {

        if (is_null($apertura) || is_null($caja)) {
            return true;
        }

        if (!is_null($apertura->cerrada_at)) {
            return true;
        }

        return (int) $caja->current_apertura_caja_id !== (int) $apertura->id;
    }

    /**
     * ¿Este movimiento es de una apertura que ya no es la vigente? Lee la apertura y la caja.
     *
     * @return bool
     */
    function esta_en_apertura_cerrada() {
        return self::apertura_esta_cerrada(AperturaCaja::find($this->apertura_caja_id), Caja::find($this->caja_id));
    }

    /**
     * Por qué este movimiento NO se puede corregir ni eliminar desde la caja, o null si se puede.
     * Primero la apertura cerrada, después el origen.
     *
     * @param  bool|null  $apertura_cerrada  Si el llamador ya lo sabe (el listado de una apertura lo
     *                                       calcula una vez para todas las filas); null = se averigua.
     * @return string|null
     */
    function motivo_no_editable($apertura_cerrada = null) {

        if (is_null($apertura_cerrada)) {
            $apertura_cerrada = $this->esta_en_apertura_cerrada();
        }

        if ($apertura_cerrada) {
            return self::MOTIVO_APERTURA_CERRADA;
        }

        $origen = $this->origen_automatico();

        if (is_null($origen)) {
            return null;
        }

        return self::MOTIVOS_POR_ORIGEN[$origen];
    }

}
