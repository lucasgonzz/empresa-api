<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\currentAcount\CurrentAcountPagoAltaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CreditAccount;
use Carbon\Carbon;

/**
 * Pago de cuenta corriente propuesto por el asistente de IA: un cobro a un cliente o un pago a un
 * proveedor (misión asistente-ia-acciones, §3.4 del plan).
 *
 * Se ejecuta por CurrentAcountPagoAltaHelper::registrar(), el cuerpo de
 * `CurrentAcountController::pago()` mudado sin cambios: el mismo create, la misma imputación FIFO,
 * los mismos movimientos de caja y el mismo recálculo de saldos que el modal de Pago de la pantalla.
 *
 * Fuera de alcance (lo dice el asistente y manda a la pantalla): cheques, tarjeta de crédito,
 * cobros en otra moneda que la de la cuenta (cotización) e imputar a un comprobante o cuota puntual.
 */
class PropuestaPagoIaHelper {

    /**
     * Herramienta proponer_pago.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input  tipo* (cliente|proveedor), id*, monto*, fecha, moneda, pagos, descripcion, reemplaza_a
     * @return array
     */
    static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        $model_name = ConsultasDeCargaIaHelper::model_name_de_tipo(EntradaDeCargaIa::texto($input, 'tipo'));

        if (is_null($model_name)) {

            return RespuestaDeCargaIa::faltan(['si es un pago de un cliente o a un proveedor']);
        }

        $es_cliente = $model_name === 'client';

        if (!PermisosIaHelper::puede($contexto->persona, self::permiso($model_name))) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso(self::que_se_carga($model_name)));
        }

        $id = (int) EntradaDeCargaIa::valor($input, 'id');
        $monto = EntradaDeCargaIa::valor($input, 'monto');

        $faltan = [];

        if ($id <= 0) {

            $faltan[] = $es_cliente ? 'de qué cliente es el pago' : 'a qué proveedor es el pago';
        }

        if (EntradaDeCargaIa::vacio($monto)) {

            $faltan[] = 'de cuánto fue el pago';
        }

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan);
        }

        $modelo = ConsultasDeCargaIaHelper::modelo_del_dueno($contexto, $model_name, $id);

        if (is_null($modelo)) {

            return RespuestaDeCargaIa::error($es_cliente ? 'No encontré ese cliente entre los tuyos.' : 'No encontré ese proveedor entre los tuyos.');
        }

        $monto = EntradaDeCargaIa::monto_positivo($monto, 'El monto del pago tiene que ser un número mayor a 0.');

        if (is_array($monto)) {

            return $monto;
        }

        $fecha = EntradaDeCargaIa::fecha($contexto, EntradaDeCargaIa::valor($input, 'fecha'));

        if (is_array($fecha)) {

            return $fecha;
        }

        $cuentas = ConsultasDeCargaIaHelper::cuentas_del_modelo($contexto, $model_name, $modelo->id);

        if (!count($cuentas)) {

            return RespuestaDeCargaIa::error($es_cliente ? 'Ese cliente no tiene cuenta corriente.' : 'Ese proveedor no tiene cuenta corriente.');
        }

        $moneda_pedida = EntradaDeCargaIa::valor($input, 'moneda');

        if (EntradaDeCargaIa::vacio($moneda_pedida)) {

            // Con una sola cuenta visible no hay nada que preguntar; con dos, el asistente no elige.
            if (count($cuentas) > 1) {

                $opciones = [];

                foreach ($cuentas as $candidata) {

                    $opciones[] = [
                        'moneda'  => FormatoIaHelper::nombre_de_moneda(self::moneda_de_la_cuenta($candidata)),
                        'saldo'   => round((float) $candidata->saldo, 2),
                        'lectura' => ConsultasDeCargaIaHelper::lectura_de_saldo($model_name, $candidata->saldo, self::moneda_de_la_cuenta($candidata)),
                    ];
                }

                return RespuestaDeCargaIa::faltan(['en qué cuenta: pesos o dólares'], ['cuentas' => $opciones]);
            }

            $cuenta = $cuentas[0];

        } else {

            $moneda_id = EntradaDeCargaIa::moneda($contexto, $moneda_pedida);

            if (is_array($moneda_id)) {

                return $moneda_id;
            }

            $cuenta = null;

            foreach ($cuentas as $candidata) {

                if (self::moneda_de_la_cuenta($candidata) === $moneda_id) {

                    $cuenta = $candidata;
                }
            }

            if (is_null($cuenta)) {

                return RespuestaDeCargaIa::error(
                    ($es_cliente ? 'Ese cliente' : 'Ese proveedor').' no tiene cuenta corriente en '.FormatoIaHelper::nombre_de_moneda($moneda_id).'.'
                );
            }
        }

        $moneda_id = self::moneda_de_la_cuenta($cuenta);
        $nombre = (string) $modelo->name;
        $descripcion = EntradaDeCargaIa::texto($input, 'descripcion');
        $reemplaza_a = EntradaDeCargaIa::valor($input, 'reemplaza_a');

        /*
         * Derivada de la decisión 2 de Lucas: un pago con fecha futura todavía no pasó. Se agenda como
         * tarea para cobrar o pagar ese día (sin gasto asociado), en vez de mover hoy la cuenta y la
         * caja por una plata que todavía no entró ni salió.
         */
        if ($fecha->gt($contexto->hoy)) {

            return PropuestaTareaIaHelper::proponer_desde_carga_futura($contexto, $mensaje, [
                'detalle'            => ($es_cliente ? 'Cobrar a ' : 'Pagar a ').$nombre.' '.FormatoIaHelper::monto($monto, $moneda_id),
                'fecha'              => $fecha,
                'expense_concept_id' => null,
                'expense_amount'     => null,
                'notas'              => $descripcion !== '' ? $descripcion : null,
                'convertido_desde'   => AiMessageAction::TIPO_PAGO,
                'clave'              => 'tarea_nueva:pago:'.$cuenta->id,
                'reemplaza_a'        => $reemplaza_a,
            ]);
        }

        // Las filas van en la moneda de la cuenta: el asistente no carga cobros con cotización.
        $pagos = PagosIaHelper::validar($contexto, EntradaDeCargaIa::valor($input, 'pagos'), $monto, $moneda_id);

        if (RespuestaDeCargaIa::es_negativa($pagos)) {

            return $pagos;
        }

        $saldo_actual = round((float) $cuenta->saldo, 2);
        $saldo_despues = round($saldo_actual - $monto, 2);

        $renglones = [
            ['etiqueta' => $es_cliente ? 'Cliente' : 'Proveedor', 'valor' => $nombre.' · cuenta en '.FormatoIaHelper::nombre_de_moneda($moneda_id)],
            ['etiqueta' => 'Saldo actual', 'valor' => ConsultasDeCargaIaHelper::lectura_de_saldo($model_name, $saldo_actual, $moneda_id)],
            ['etiqueta' => 'Monto', 'valor' => FormatoIaHelper::monto($monto, $moneda_id)],
        ];

        foreach ($pagos['renglones'] as $renglon) {

            $renglones[] = $renglon;
        }

        $renglones[] = ['etiqueta' => 'Fecha', 'valor' => FormatoIaHelper::fecha_con_dia($fecha)];
        $renglones[] = ['etiqueta' => 'Saldo después', 'valor' => ConsultasDeCargaIaHelper::lectura_de_saldo($model_name, $saldo_despues, $moneda_id)];

        if ($descripcion !== '') {

            $renglones[] = ['etiqueta' => 'Descripción', 'valor' => $descripcion];
        }

        $aviso = null;

        if ($saldo_despues < -0.005) {

            $a_favor = FormatoIaHelper::monto(abs($saldo_despues), $moneda_id);

            $aviso = $es_cliente
                ? 'Supera lo que debe: le quedan '.$a_favor.' a favor.'
                : 'Supera lo que le debés: te quedan '.$a_favor.' a favor.';
        }

        $datos = [
            'model_name'                     => $model_name,
            'model_id'                       => (int) $modelo->id,
            'credit_account_id'              => (int) $cuenta->id,
            'haber'                          => $monto,
            'description'                    => $descripcion !== '' ? $descripcion : null,
            'fecha'                          => $fecha->format('Y-m-d'),
            'current_acount_payment_methods' => $pagos['filas'],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_PAGO,
            self::clave($cuenta->id),
            $datos,
            ['titulo' => $es_cliente ? 'Pago de cliente' : 'Pago a proveedor', 'renglones' => $renglones, 'aviso' => $aviso],
            $reemplaza_a
        );

        $resumen = ($es_cliente ? 'Pago de ' : 'Pago a ').$nombre.' '.FormatoIaHelper::monto($monto, $moneda_id).' · '.$pagos['resumen'].' · '.FormatoIaHelper::fecha_con_dia($fecha);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    /**
     * Identidad de un pago para el reemplazo: la cuenta corriente sobre la que entra.
     *
     * @param  int  $credit_account_id
     * @return string
     */
    static function clave($credit_account_id) {

        return 'pago:'.(int) $credit_account_id;
    }

    /**
     * Registra el pago de la tarjeta. Corre adentro de la transacción de EjecutorAccionesIaHelper,
     * autenticado como la persona que confirma (getNumReceipt() y el employee_id del pago leen la
     * sesión).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta: null}
     *
     * @throws AccionIaException
     */
    static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion) {

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $model_name = isset($datos['model_name']) && $datos['model_name'] === 'provider' ? 'provider' : 'client';
        $es_cliente = $model_name === 'client';

        if (!PermisosIaHelper::puede($contexto->persona, self::permiso($model_name))) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso(self::que_se_carga($model_name)));
        }

        $cuenta = CreditAccount::where('id', isset($datos['credit_account_id']) ? (int) $datos['credit_account_id'] : 0)
                                ->where('user_id', $contexto->owner_id)
                                ->where('model_name', $model_name)
                                ->where('model_id', isset($datos['model_id']) ? (int) $datos['model_id'] : 0)
                                ->first();

        if (is_null($cuenta)) {

            throw new AccionIaException(422, 'La cuenta corriente de la tarjeta ya no existe. Pedímelo de nuevo.');
        }

        $modelo = ConsultasDeCargaIaHelper::modelo_del_dueno($contexto, $model_name, $cuenta->model_id);

        if (is_null($modelo)) {

            throw new AccionIaException(422, $es_cliente ? 'El cliente de la tarjeta ya no existe.' : 'El proveedor de la tarjeta ya no existe.');
        }

        $payment_methods = isset($datos['current_acount_payment_methods']) && is_array($datos['current_acount_payment_methods'])
            ? $datos['current_acount_payment_methods']
            : [];

        // Mismo 422 que CurrentAcountController::pago() para una caja que nunca se abrió.
        PagosIaHelper::verificar_para_ejecutar($contexto, $payment_methods, 'el pago');

        $fecha = Carbon::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay();
        $es_de_hoy = $fecha->gte($contexto->hoy);

        /*
         * Las 12 claves que pago() lee del request, con los valores que manda el modal de Pago para
         * un cobro común: sin provisorio, sin orden de compra, sin imputación dirigida ni cuota, y
         * `current_date` según la fecha (con fecha pasada, created_at = ese día y la cuenta se
         * recalcula entera, igual que en la pantalla).
         */
        $pago = CurrentAcountPagoAltaHelper::registrar([
            'credit_account_id'              => (int) $cuenta->id,
            'model_name'                     => $model_name,
            'model_id'                       => (int) $modelo->id,
            'current_acount_payment_methods' => $payment_methods,
            'haber'                          => isset($datos['haber']) ? (float) $datos['haber'] : 0,
            'description'                    => isset($datos['description']) ? $datos['description'] : null,
            'numero_orden_de_compra'         => null,
            'is_provisorio'                  => 0,
            'current_date'                   => $es_de_hoy ? 1 : 0,
            'created_at'                     => $es_de_hoy ? null : $fecha->format('Y-m-d'),
            'to_pay'                         => null,
            'payment_plan_cuota'             => null,
        ]);

        $saldo = CreditAccount::where('id', $cuenta->id)->value('saldo');

        return [
            'texto' => 'Pago N° '.$pago->num_receipt.' registrado. Saldo de '.$modelo->name.': '.FormatoIaHelper::monto($saldo, self::moneda_de_la_cuenta($cuenta)),
            'ruta'  => null,
        ];
    }

    /**
     * @param  string  $model_name
     * @return string  Slug de permiso (ver PermisosIaHelper).
     */
    static function permiso($model_name) {

        return $model_name === 'provider' ? PermisosIaHelper::PAGOS_A_PROVEEDORES : PermisosIaHelper::PAGOS_DE_CLIENTES;
    }

    /**
     * @param  string  $model_name
     * @return string
     */
    static function que_se_carga($model_name) {

        return $model_name === 'provider' ? 'pagos a proveedores' : 'pagos de clientes';
    }

    /**
     * Moneda de una cuenta corriente (null = pesos, criterio de RecolectorBase).
     *
     * @param  \App\Models\CreditAccount  $cuenta
     * @return int
     */
    static function moneda_de_la_cuenta($cuenta) {

        return is_null($cuenta->moneda_id) ? 1 : (int) $cuenta->moneda_id;
    }
}
