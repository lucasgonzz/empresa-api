<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\expense\ExpenseHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExpenseConcept;
use Carbon\Carbon;

/**
 * Gasto propuesto por el asistente de IA (misión asistente-ia-acciones, §3.4 del plan): validación al
 * proponer, tarjeta, datos de ejecución y ejecución al confirmar.
 *
 * Se ejecuta por ExpenseHelper::crear(), el MISMO camino que `POST api/expense` de la pantalla de
 * Gastos: una sola forma de dar de alta un gasto con su desglose y sus movimientos de caja.
 */
class PropuestaGastoIaHelper {

    /**
     * Herramienta proponer_gasto.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  subcategoria_id*, monto*, fecha, moneda, pagos, observaciones, importe_iva, reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::GASTOS)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('gastos'));
        }

        $subcategoria_id = (int) EntradaDeCargaIa::valor($input, 'subcategoria_id');
        $monto = EntradaDeCargaIa::valor($input, 'monto');

        $faltan = [];

        if ($subcategoria_id <= 0) {

            $faltan[] = 'qué subcategoría de gasto es';
        }

        if (EntradaDeCargaIa::vacio($monto)) {

            $faltan[] = 'de cuánto fue el gasto';
        }

        if (count($faltan)) {

            $opciones = $subcategoria_id <= 0
                ? ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, '')]
                : [];

            return RespuestaDeCargaIa::faltan($faltan, $opciones);
        }

        $concepto = ExpenseConcept::where('user_id', $contexto->owner_id)
                                    ->where('id', $subcategoria_id)
                                    ->with('expense_category')
                                    ->first();

        if (is_null($concepto)) {

            return RespuestaDeCargaIa::error(
                'Esa subcategoría de gasto no existe entre las tuyas.',
                ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, '')]
            );
        }

        $monto = EntradaDeCargaIa::monto_positivo($monto, 'El monto del gasto tiene que ser un número mayor a 0.');

        if (is_array($monto)) {

            return $monto;
        }

        $moneda_id = EntradaDeCargaIa::moneda($contexto, EntradaDeCargaIa::valor($input, 'moneda'));

        if (is_array($moneda_id)) {

            return $moneda_id;
        }

        $fecha = EntradaDeCargaIa::fecha($contexto, EntradaDeCargaIa::valor($input, 'fecha'));

        if (is_array($fecha)) {

            return $fecha;
        }

        $observaciones = EntradaDeCargaIa::texto($input, 'observaciones');
        $reemplaza_a = EntradaDeCargaIa::valor($input, 'reemplaza_a');

        /*
         * 🔴 Decisión 2 de Lucas: un gasto con fecha futura todavía NO es un gasto. Registrarlo hoy
         * movería la caja hoy por una plata que sale otro día. Se agenda como tarea con su gasto
         * asociado (subcategoría + monto estimado) y, ese día, al marcarla como hecha, se pregunta
         * cómo se pagó y se registra el gasto. Por eso acá no se piden los pagos.
         */
        if ($fecha->gt($contexto->hoy)) {

            return PropuestaTareaIaHelper::proponer_desde_carga_futura($contexto, $mensaje, [
                'detalle'            => $observaciones !== '' ? $observaciones : (string) $concepto->name,
                'fecha'              => $fecha,
                'expense_concept_id' => (int) $concepto->id,
                'expense_amount'     => $monto,
                'notas'              => null,
                'convertido_desde'   => AiMessageAction::TIPO_GASTO,
                'clave'              => 'tarea_nueva:gasto:'.$concepto->id,
                'reemplaza_a'        => $reemplaza_a,
            ]);
        }

        $importe_iva = 0;
        $iva_pedido = EntradaDeCargaIa::valor($input, 'importe_iva');

        if (!EntradaDeCargaIa::vacio($iva_pedido)) {

            if (!is_numeric($iva_pedido) || (float) $iva_pedido < 0) {

                return RespuestaDeCargaIa::error('El importe de IVA tiene que ser un número mayor o igual a 0.');
            }

            $importe_iva = round((float) $iva_pedido, 2);
        }

        $pagos = PagosIaHelper::validar($contexto, EntradaDeCargaIa::valor($input, 'pagos'), $monto, $moneda_id);

        if (RespuestaDeCargaIa::es_negativa($pagos)) {

            return $pagos;
        }

        $subcategoria = OpcionesDeCargaIaHelper::nombre_de_subcategoria($concepto);

        $renglones = [
            ['etiqueta' => 'Subcategoría', 'valor' => $subcategoria],
            ['etiqueta' => 'Monto', 'valor' => FormatoIaHelper::monto($monto, $moneda_id)],
        ];

        foreach ($pagos['renglones'] as $renglon) {

            $renglones[] = $renglon;
        }

        $renglones[] = ['etiqueta' => 'Fecha', 'valor' => FormatoIaHelper::fecha_con_dia($fecha)];

        if ($importe_iva > 0) {

            // El IVA de un gasto va SIEMPRE en pesos (descripción del campo en src/models/expense.js).
            $renglones[] = ['etiqueta' => 'IVA', 'valor' => FormatoIaHelper::monto($importe_iva)];
        }

        if ($observaciones !== '') {

            $renglones[] = ['etiqueta' => 'Observaciones', 'valor' => $observaciones];
        }

        $datos = [
            'expense_concept_id' => (int) $concepto->id,
            'amount'             => $monto,
            'moneda_id'          => $moneda_id,
            'importe_iva'        => $importe_iva,
            'observations'       => $observaciones !== '' ? $observaciones : null,
            'fecha'              => $fecha->format('Y-m-d'),
            'payment_methods'    => $pagos['filas'],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_GASTO,
            self::clave($concepto->id),
            $datos,
            ['titulo' => 'Gasto', 'renglones' => $renglones, 'aviso' => null],
            $reemplaza_a
        );

        $resumen = 'Gasto '.$subcategoria.' '.FormatoIaHelper::monto($monto, $moneda_id).' · '.$pagos['resumen'].' · '.FormatoIaHelper::fecha_con_dia($fecha);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    /**
     * Identidad de un gasto para el reemplazo: una corrección del mismo gasto reemplaza la tarjeta,
     * y un gasto de otra subcategoría ("cargame también la nafta") no la pisa.
     *
     * @param  int  $expense_concept_id
     * @return string
     */
    static function clave($expense_concept_id) {

        return 'gasto:'.(int) $expense_concept_id;
    }

    /**
     * Registra el gasto de la tarjeta. Corre adentro de la transacción de EjecutorAccionesIaHelper,
     * autenticado como la persona que confirma.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @param  callable  $num_expense_resolver  Controller::num('expenses'), resuelto adentro de la transacción.
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion, $num_expense_resolver) {

        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::GASTOS)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('gastos'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $expense_concept_id = isset($datos['expense_concept_id']) ? (int) $datos['expense_concept_id'] : 0;

        if (!ExpenseConcept::where('user_id', $contexto->owner_id)->where('id', $expense_concept_id)->exists()) {

            throw new AccionIaException(422, 'La subcategoría de gasto de la tarjeta ya no existe. Pedímelo de nuevo.');
        }

        $payment_methods = isset($datos['payment_methods']) && is_array($datos['payment_methods']) ? $datos['payment_methods'] : [];

        // Mismo 422 que ExpenseController::store() para una caja que nunca se abrió.
        PagosIaHelper::verificar_para_ejecutar($contexto, $payment_methods, 'el gasto');

        $fecha = Carbon::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay();
        $ahora = Carbon::now();

        /*
         * La hora del gasto: si la fecha es hoy, ahora; si es anterior, ese día con la hora actual
         * (el formulario de Gastos manda solo el día y el listado ordena por created_at).
         */
        $created_at = $fecha->gte($contexto->hoy)
            ? $ahora->format('Y-m-d H:i:s')
            : $fecha->format('Y-m-d').' '.$ahora->format('H:i:s');

        $gasto = ExpenseHelper::crear([
            'expense_concept_id' => $expense_concept_id,
            'amount'             => isset($datos['amount']) ? (float) $datos['amount'] : 0,
            'moneda_id'          => ExpenseHelper::normalizar_moneda_id(isset($datos['moneda_id']) ? $datos['moneda_id'] : null),
            'importe_iva'        => isset($datos['importe_iva']) ? (float) $datos['importe_iva'] : 0,
            'observations'       => isset($datos['observations']) ? $datos['observations'] : null,
            'created_at'         => $created_at,
            'payment_methods'    => $payment_methods,
        ], $contexto->owner_id, $num_expense_resolver);

        return [
            'texto' => 'Gasto N° '.$gasto->num.' registrado',
            'ruta'  => [
                'name'   => 'expense',
                'params' => new \stdClass(),
                'texto'  => 'Ver en Gastos',
            ],
        ];
    }
}
