<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\agenda\AgendaCompletarHelper;
use App\Http\Controllers\Helpers\agenda\AgendaFechaInvalidaException;
use App\Http\Controllers\Helpers\agenda\AgendaGastoRequeridoException;
use App\Http\Controllers\Helpers\agenda\AgendaHelper;
use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;
use App\Http\Controllers\Helpers\agenda\AgendaYaCompletadaException;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExpenseConcept;
use App\Models\Pending;
use App\Models\PendingCompleted;
use App\Models\UnidadFrecuencia;
use Carbon\Carbon;

/**
 * Las tres cargas de la Agenda que propone el asistente de IA: una tarea nueva, cambios en una tarea
 * y marcar una tarea como hecha (misión asistente-ia-acciones, §3.4 del plan).
 *
 * Todo pasa por los mismos helpers que la pantalla de la Agenda: AgendaTareaHelper::validar() (las
 * mismas reglas y los mismos mensajes que `POST/PUT api/pending`), reanclar_si_cambio_la_regla() y
 * AgendaCompletarHelper::completar() (el candado y la transacción de `POST api/pending-completed`).
 */
class PropuestaTareaIaHelper {

    /**
     * Prefijo de la clave de una tarea nueva escrita a mano. La clave completa la arma
     * clave_de_tarea_nueva() con el detalle y la fecha, igual que las que nacen de un gasto o un
     * pago futuros llevan el id de su concepto o de su cuenta (ver proponer_desde_carga_futura()).
     */
    const CLAVE_TAREA_NUEVA = 'tarea_nueva';

    /**
     * Cuánto del detalle entra en la clave. La columna es string(100) y la clave lleva además el
     * prefijo y la fecha.
     */
    const LARGO_DETALLE_EN_CLAVE = 60;

    const AVISO_GASTO_FUTURO = 'Ese día, al marcarla como hecha, se registra el gasto con cómo se pagó.';

    const AVISO_PAGO_FUTURO = 'Es un recordatorio: ese día el pago se carga desde la cuenta corriente, o pidiéndomelo.';

    const AVISO_CAMBIO_DE_REGLA = 'Cambia las repeticiones de acá en adelante; lo ya hecho queda en Realizadas.';

    const AVISO_SIN_GASTO = 'Queda hecha sin registrar el gasto.';

    const AVISO_MONTO_ESTIMADO = 'Monto estimado de la tarea';

    const MENSAJE_TAREA_CAMBIADA = 'La tarea cambió desde que armé la tarjeta; pedímelo de nuevo.';

    const MENSAJE_TAREA_INEXISTENTE = 'La tarea ya no existe.';

    // -------------------------------------------------------------------------------------------
    // Tarea nueva
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_tarea.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input  detalle*, fecha*, repetir {cada*, unidad*, hasta}, subcategoria_id, monto_estimado, notas, reemplaza_a
     * @return array
     */
    static function proponer_tarea(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        if (!self::puede($contexto)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('tareas de la agenda'));
        }

        $detalle = EntradaDeCargaIa::texto($input, 'detalle');
        $fecha_pedida = EntradaDeCargaIa::valor($input, 'fecha');

        $faltan = [];

        if ($detalle === '') {

            $faltan[] = 'qué hay que hacer';
        }

        if (EntradaDeCargaIa::vacio($fecha_pedida)) {

            $faltan[] = 'qué día';
        }

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan);
        }

        $fecha = EntradaDeCargaIa::fecha($contexto, $fecha_pedida);

        if (is_array($fecha)) {

            return $fecha;
        }

        $datos = self::datos_de_una_tarea_nueva($detalle, $fecha);

        $notas = EntradaDeCargaIa::texto($input, 'notas');
        $datos['notas'] = $notas !== '' ? $notas : null;

        $repetir = EntradaDeCargaIa::valor($input, 'repetir');

        if (is_array($repetir) && count($repetir)) {

            $regla = self::regla_de_repeticion($repetir);

            if (RespuestaDeCargaIa::es_negativa($regla)) {

                return $regla;
            }

            $datos = array_merge($datos, $regla);
        }

        $subcategoria_id = (int) EntradaDeCargaIa::valor($input, 'subcategoria_id');
        $monto_estimado = EntradaDeCargaIa::valor($input, 'monto_estimado');

        if ($subcategoria_id > 0) {

            if (!ExpenseConcept::where('user_id', $contexto->owner_id)->where('id', $subcategoria_id)->exists()) {

                return RespuestaDeCargaIa::error(
                    'Esa subcategoría de gasto no existe entre las tuyas.',
                    ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, '')]
                );
            }

            $datos['expense_concept_id'] = $subcategoria_id;
            // Sin monto la tarea queda con "monto a definir al pagarlo" (0), como en la pantalla.
            $datos['expense_amount'] = EntradaDeCargaIa::vacio($monto_estimado) ? 0 : $monto_estimado;

        } elseif (!EntradaDeCargaIa::vacio($monto_estimado)) {

            return RespuestaDeCargaIa::faltan(
                ['qué subcategoría de gasto tiene la tarea (sin subcategoría no se guarda el monto estimado)'],
                ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, '')]
            );
        }

        return self::armar_tarea_nueva($contexto, $mensaje, $datos, self::clave_de_tarea_nueva($detalle, $fecha), null, EntradaDeCargaIa::valor($input, 'reemplaza_a'), []);
    }

    /**
     * Clave de reemplazo de una tarea nueva: prefijo + detalle + fecha.
     *
     * 🔴 CON LA CLAVE LITERAL `tarea_nueva`, DOS TAREAS DISTINTAS DEL MISMO TURNO SE PISABAN. "Agendame
     * llamar al contador el jueves y pagar el alquiler el viernes" son dos proponer_tarea en el mismo
     * mensaje: la segunda reemplazaba a la primera y la persona veía una sola tarjeta más un
     * "Reemplazada por una versión corregida" sobre algo que nadie corrigió. Se perdía una tarea que
     * había pedido.
     *
     * El detalle se normaliza (minúsculas y espacios colapsados) para que una corrección que solo
     * cambia cómo está escrito el mismo pedido siga reemplazando; una corrección que cambia el texto
     * de verdad viaja por `reemplaza_a`, que es el camino explícito y no depende de la clave.
     *
     * @param  string  $detalle
     * @param  Carbon  $fecha
     * @return string
     */
    protected static function clave_de_tarea_nueva($detalle, Carbon $fecha) {

        $normalizado = mb_strtolower(trim((string) $detalle), 'UTF-8');
        $normalizado = preg_replace('/\s+/u', ' ', $normalizado);
        $normalizado = mb_substr($normalizado, 0, self::LARGO_DETALLE_EN_CLAVE, 'UTF-8');

        return self::CLAVE_TAREA_NUEVA.':'.$normalizado.':'.$fecha->format('Y-m-d');
    }

    /**
     * Tarea nueva que nace de un gasto o de un pago con fecha futura (decisión 2 de Lucas y su
     * derivada para pagos). La llaman PropuestaGastoIaHelper y PropuestaPagoIaHelper, que ya
     * validaron lo suyo; acá se pide el permiso de la agenda, porque lo que se va a guardar es una
     * tarea.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $carga  detalle, fecha (Carbon), expense_concept_id, expense_amount, notas,
     *                        convertido_desde ('gasto'|'pago'), clave, reemplaza_a.
     * @return array
     */
    static function proponer_desde_carga_futura(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $carga) {

        if (!self::puede($contexto)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('tareas de la agenda'));
        }

        $datos = self::datos_de_una_tarea_nueva($carga['detalle'], $carga['fecha']);

        $datos['expense_concept_id'] = $carga['expense_concept_id'];
        $datos['expense_amount'] = $carga['expense_amount'];
        $datos['notas'] = $carga['notas'];

        $aviso = $carga['convertido_desde'] === AiMessageAction::TIPO_GASTO ? self::AVISO_GASTO_FUTURO : self::AVISO_PAGO_FUTURO;

        return self::armar_tarea_nueva($contexto, $mensaje, $datos, $carga['clave'], $aviso, $carga['reemplaza_a'], [
            'convertido_desde' => $carga['convertido_desde'],
            'motivo'           => 'fecha futura',
        ]);
    }

    /**
     * Guarda la tarea nueva de la tarjeta, igual que PendingController::store().
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException
     */
    static function ejecutar_tarea_nueva(ContextoDeCargaIa $contexto, AiMessageAction $accion) {

        if (!self::puede($contexto)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('tareas de la agenda'));
        }

        // Se valida de nuevo: la subcategoría o la unidad de repetición se pudieron borrar después de armar la tarjeta.
        $columnas = AgendaTareaHelper::validar(is_array($accion->datos) ? $accion->datos : [], $contexto->owner_id);

        if (is_string($columnas)) {

            throw new AccionIaException(422, $columnas);
        }

        $columnas['completado'] = 0;
        $columnas['user_id'] = $contexto->owner_id;

        $tarea = Pending::create($columnas);

        return [
            'texto' => 'Tarea agendada para el '.FormatoIaHelper::fecha_con_dia(Carbon::parse($tarea->fecha_realizacion)->startOfDay()),
            'ruta'  => self::ruta_de_la_agenda(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Cambios en una tarea
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_cambios_en_tarea: superpone los cambios sobre los valores actuales y los
     * valida con las reglas de la pantalla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input  tarea_id*, detalle, fecha, repetir, quitar_repeticion, subcategoria_id,
     *                        quitar_gasto_asociado, monto_estimado, notas, reemplaza_a
     * @return array
     */
    static function proponer_cambios(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        if (!self::puede($contexto)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('tareas de la agenda'));
        }

        $tarea_id = (int) EntradaDeCargaIa::valor($input, 'tarea_id');

        if ($tarea_id <= 0) {

            return RespuestaDeCargaIa::faltan(['qué tarea hay que cambiar']);
        }

        $pending = self::tarea_del_dueno($contexto, $tarea_id);

        if (is_null($pending)) {

            return RespuestaDeCargaIa::error('No encontré esa tarea en tu agenda.');
        }

        $nuevos = self::datos_de_la_tarea($pending);

        $detalle = EntradaDeCargaIa::texto($input, 'detalle');

        if ($detalle !== '') {

            $nuevos['detalle'] = $detalle;
        }

        $fecha_pedida = EntradaDeCargaIa::valor($input, 'fecha');

        if (!EntradaDeCargaIa::vacio($fecha_pedida)) {

            $fecha = EntradaDeCargaIa::fecha($contexto, $fecha_pedida);

            if (is_array($fecha)) {

                return $fecha;
            }

            $nuevos['fecha_realizacion'] = $fecha->format('Y-m-d');
        }

        if (!empty($input['quitar_repeticion'])) {

            $nuevos['es_recurrente'] = false;
            $nuevos['unidad_frecuencia_id'] = null;
            $nuevos['cantidad_frecuencia'] = null;
            $nuevos['fecha_fin_recurrencia'] = null;

        } else {

            $repetir = EntradaDeCargaIa::valor($input, 'repetir');

            if (is_array($repetir) && count($repetir)) {

                $regla = self::regla_de_repeticion($repetir);

                if (RespuestaDeCargaIa::es_negativa($regla)) {

                    return $regla;
                }

                // Sin "hasta" se conserva el fin que la tarea ya tenía.
                if (!array_key_exists('hasta', $repetir)) {

                    unset($regla['fecha_fin_recurrencia']);
                }

                $nuevos = array_merge($nuevos, $regla);
            }
        }

        if (!empty($input['quitar_gasto_asociado'])) {

            $nuevos['expense_concept_id'] = null;
            $nuevos['expense_amount'] = null;

        } else {

            $subcategoria_id = (int) EntradaDeCargaIa::valor($input, 'subcategoria_id');

            if ($subcategoria_id > 0) {

                if (!ExpenseConcept::where('user_id', $contexto->owner_id)->where('id', $subcategoria_id)->exists()) {

                    return RespuestaDeCargaIa::error(
                        'Esa subcategoría de gasto no existe entre las tuyas.',
                        ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, '')]
                    );
                }

                if ((int) $nuevos['expense_concept_id'] !== $subcategoria_id) {

                    $nuevos['expense_concept_id'] = $subcategoria_id;

                    if (EntradaDeCargaIa::vacio($nuevos['expense_amount'])) {

                        $nuevos['expense_amount'] = 0;
                    }
                }
            }

            $monto_estimado = EntradaDeCargaIa::valor($input, 'monto_estimado');

            if (!EntradaDeCargaIa::vacio($monto_estimado)) {

                if ((int) $nuevos['expense_concept_id'] <= 0) {

                    return RespuestaDeCargaIa::faltan(
                        ['qué subcategoría de gasto tiene la tarea (sin subcategoría no se guarda el monto estimado)'],
                        ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, '')]
                    );
                }

                $nuevos['expense_amount'] = $monto_estimado;
            }
        }

        // Unas notas vacías no borran las que había: la IA suele mandar las claves opcionales en blanco.
        $notas = EntradaDeCargaIa::texto($input, 'notas');

        if ($notas !== '') {

            $nuevos['notas'] = $notas;
        }

        $despues = AgendaTareaHelper::validar($nuevos, $contexto->owner_id);

        if (is_string($despues)) {

            return RespuestaDeCargaIa::error($despues);
        }

        $antes = self::columnas_de_la_tarea($pending);

        $cambios = self::campos_que_cambian($antes, $despues);

        if (!count($cambios)) {

            return RespuestaDeCargaIa::error('Esa tarea ya está así: no hay nada para cambiar.');
        }

        $descripcion_antes = self::descripcion_de_tarea($antes);
        $descripcion_despues = self::descripcion_de_tarea($despues);

        $etiquetas = [
            'que'        => 'Qué',
            'fecha'      => 'Cuándo',
            'repeticion' => 'Repetición',
            'gasto'      => 'Gasto asociado',
            'notas'      => 'Notas',
        ];

        $renglones = [];

        // Si no cambia el detalle, la tarjeta igual tiene que decir de qué tarea se trata.
        if (!in_array('que', $cambios, true)) {

            $renglones[] = ['etiqueta' => 'Tarea', 'valor' => $antes['detalle']];
        }

        foreach ($etiquetas as $campo => $etiqueta) {

            if (in_array($campo, $cambios, true)) {

                $renglones[] = ['etiqueta' => $etiqueta, 'valor' => $descripcion_antes[$campo].' → '.$descripcion_despues[$campo]];
            }
        }

        $aviso = self::cambia_la_regla($antes, $despues) ? self::AVISO_CAMBIO_DE_REGLA : null;

        $datos = $nuevos;
        $datos['pending_id'] = (int) $pending->id;

        // El updated_at de la tarea al armar la tarjeta: si alguien la edita en el medio, confirmar da 422.
        $referencia = is_null($pending->updated_at) ? null : $pending->updated_at->format('Y-m-d H:i:s');

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_TAREA_EDITAR,
            'tarea:'.$pending->id,
            $datos,
            ['titulo' => 'Cambios en la tarea', 'renglones' => $renglones, 'aviso' => $aviso],
            EntradaDeCargaIa::valor($input, 'reemplaza_a'),
            $referencia
        );

        $nombres = [];

        foreach ($cambios as $campo) {

            $nombres[] = mb_strtolower($etiquetas[$campo]);
        }

        return AccionesIaHelper::respuesta_de_propuesta($creada, 'Cambios en la tarea '.$antes['detalle'].': '.implode(', ', $nombres));
    }

    /**
     * Guarda los cambios de la tarjeta, igual que PendingController::update(): valida, reancla la
     * regla si cambió y escribe las columnas.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException
     */
    static function ejecutar_cambios(ContextoDeCargaIa $contexto, AiMessageAction $accion) {

        if (!self::puede($contexto)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('tareas de la agenda'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $pending = Pending::where('id', isset($datos['pending_id']) ? (int) $datos['pending_id'] : 0)
                            ->where('user_id', $contexto->owner_id)
                            ->lockForUpdate()
                            ->first();

        if (is_null($pending)) {

            throw new AccionIaException(422, self::MENSAJE_TAREA_INEXISTENTE);
        }

        /*
         * 🔴 Los cambios de la tarjeta se armaron sobre la tarea como estaba en ese momento. Si
         * alguien la editó después (desde la Agenda o con otra tarjeta), confirmar pisaría ese
         * cambio con valores viejos sin que nadie lo vea: se corta con 422 y se pide de nuevo.
         */
        $actual = is_null($pending->updated_at) ? null : $pending->updated_at->format('Y-m-d H:i:s');
        $referencia = $accion->referencia_updated_at;

        if (!is_null($referencia)) {

            $referencia = Carbon::parse($referencia)->format('Y-m-d H:i:s');
        }

        if ($actual !== $referencia) {

            throw new AccionIaException(422, self::MENSAJE_TAREA_CAMBIADA);
        }

        $columnas = AgendaTareaHelper::validar($datos, $contexto->owner_id);

        if (is_string($columnas)) {

            throw new AccionIaException(422, $columnas);
        }

        $columnas['fecha_realizacion'] = AgendaTareaHelper::reanclar_si_cambio_la_regla($pending, $columnas);

        foreach ($columnas as $columna => $valor) {

            $pending->{$columna} = $valor;
        }

        $pending->save();

        return [
            'texto' => 'Tarea actualizada',
            'ruta'  => self::ruta_de_la_agenda(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Marcar como hecha
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_marcar_tarea_hecha.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input  tarea_id*, fecha (obligatoria si se repite), sin_gasto, gasto {monto, pagos, observaciones, moneda}, reemplaza_a
     * @return array
     */
    static function proponer_marcar_hecha(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        if (!self::puede($contexto)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('tareas de la agenda'));
        }

        $tarea_id = (int) EntradaDeCargaIa::valor($input, 'tarea_id');

        if ($tarea_id <= 0) {

            return RespuestaDeCargaIa::faltan(['qué tarea se hizo']);
        }

        $pending = self::tarea_del_dueno($contexto, $tarea_id);

        if (is_null($pending)) {

            return RespuestaDeCargaIa::error('No encontré esa tarea en tu agenda.');
        }

        $fecha_pedida = EntradaDeCargaIa::valor($input, 'fecha');

        if (EntradaDeCargaIa::vacio($fecha_pedida)) {

            // Mismo criterio que PendingCompletedController::store(): una puntual tiene una sola fecha posible.
            if (!$pending->es_recurrente && !is_null($pending->fecha_realizacion)) {

                $fecha = AgendaHelper::fecha_base($pending);

            } else {

                return RespuestaDeCargaIa::faltan(['qué fecha de la tarea se hizo'], ['fechas' => self::fechas_pendientes($contexto, $pending)]);
            }

        } else {

            $fecha = EntradaDeCargaIa::fecha($contexto, $fecha_pedida);

            if (is_array($fecha)) {

                return $fecha;
            }
        }

        if (!AgendaHelper::es_ocurrencia($pending, $fecha)) {

            return RespuestaDeCargaIa::error(AgendaCompletarHelper::MENSAJE_FECHA_INVALIDA, ['fechas' => self::fechas_pendientes($contexto, $pending)]);
        }

        if (self::ya_hecha($pending, $fecha)) {

            return RespuestaDeCargaIa::error(AgendaCompletarHelper::MENSAJE_YA_COMPLETADA);
        }

        $tiene_gasto = (int) $pending->expense_concept_id > 0;
        $sin_gasto = $tiene_gasto && !empty($input['sin_gasto']);

        $renglones = [
            ['etiqueta' => 'Tarea', 'valor' => (string) $pending->detalle],
            ['etiqueta' => 'Fecha', 'valor' => FormatoIaHelper::fecha_con_dia($fecha)],
        ];

        $expense = [];
        $aviso = null;
        $resumen_del_gasto = '';

        if ($tiene_gasto && !$sin_gasto) {

            $gasto = EntradaDeCargaIa::valor($input, 'gasto');
            $gasto = is_array($gasto) ? $gasto : [];

            $monto = EntradaDeCargaIa::valor($gasto, 'monto');

            if (EntradaDeCargaIa::vacio($monto)) {

                if ((float) $pending->expense_amount > 0) {

                    $monto = (float) $pending->expense_amount;
                    $aviso = self::AVISO_MONTO_ESTIMADO;

                } else {

                    return RespuestaDeCargaIa::faltan(['de cuánto fue el gasto de la tarea']);
                }
            }

            $monto = EntradaDeCargaIa::monto_positivo($monto, 'El monto del gasto tiene que ser un número mayor a 0.');

            if (is_array($monto)) {

                return $monto;
            }

            $moneda_id = EntradaDeCargaIa::moneda($contexto, EntradaDeCargaIa::valor($gasto, 'moneda'));

            if (is_array($moneda_id)) {

                return $moneda_id;
            }

            $pagos = PagosIaHelper::validar($contexto, EntradaDeCargaIa::valor($gasto, 'pagos'), $monto, $moneda_id);

            if (RespuestaDeCargaIa::es_negativa($pagos)) {

                return $pagos;
            }

            $expense = [
                'amount'          => $monto,
                'moneda_id'       => $moneda_id,
                'importe_iva'     => 0,
                'observations'    => EntradaDeCargaIa::texto($gasto, 'observaciones'),
                'payment_methods' => $pagos['filas'],
            ];

            $subcategoria = is_null($pending->expense_concept) ? 'Gasto' : OpcionesDeCargaIaHelper::nombre_de_subcategoria($pending->expense_concept);

            $renglones[] = ['etiqueta' => 'Gasto', 'valor' => $subcategoria.' · '.FormatoIaHelper::monto($monto, $moneda_id)];

            foreach ($pagos['renglones'] as $renglon) {

                $renglones[] = $renglon;
            }

            $resumen_del_gasto = ' · gasto '.FormatoIaHelper::monto($monto, $moneda_id).' · '.$pagos['resumen'];

        } elseif ($sin_gasto) {

            $aviso = self::AVISO_SIN_GASTO;
        }

        $datos = [
            'pending_id' => (int) $pending->id,
            'fecha'      => $fecha->format('Y-m-d'),
            'sin_gasto'  => $sin_gasto,
            'expense'    => $expense,
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_TAREA_COMPLETAR,
            'tarea:'.$pending->id.':'.$fecha->format('Y-m-d'),
            $datos,
            ['titulo' => 'Marcar como hecha', 'renglones' => $renglones, 'aviso' => $aviso],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Marcar como hecha: '.$pending->detalle.' · '.FormatoIaHelper::fecha_con_dia($fecha).$resumen_del_gasto
        );
    }

    /**
     * Marca la ocurrencia de la tarjeta como hecha por AgendaCompletarHelper::completar(), el mismo
     * camino (candado, transacción, gasto) que `POST api/pending-completed`.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @param  callable  $num_expense_resolver
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException
     */
    static function ejecutar_marcar_hecha(ContextoDeCargaIa $contexto, AiMessageAction $accion, $num_expense_resolver) {

        if (!self::puede($contexto)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('tareas de la agenda'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $pending = Pending::where('id', isset($datos['pending_id']) ? (int) $datos['pending_id'] : 0)
                            ->where('user_id', $contexto->owner_id)
                            ->first();

        if (is_null($pending)) {

            throw new AccionIaException(422, self::MENSAJE_TAREA_INEXISTENTE);
        }

        $expense = isset($datos['expense']) && is_array($datos['expense']) ? $datos['expense'] : [];
        $sin_gasto = !empty($datos['sin_gasto']);

        if ((int) $pending->expense_concept_id > 0 && !$sin_gasto) {

            // Mismo 422 que PendingCompletedController::store() para una caja que nunca se abrió.
            PagosIaHelper::verificar_para_ejecutar($contexto, isset($expense['payment_methods']) ? $expense['payment_methods'] : [], 'el gasto');
        }

        /*
         * Las excepciones de negocio del helper (ya estaba hecha, falta el gasto, la fecha no es de la
         * tarea) se traducen a 422 con su mensaje: para la tarjeta todas son "no se pudo, por esto".
         * Cualquier otra excepción sigue de largo y el ejecutor la trata como error inesperado.
         */
        try {

            $completada = AgendaCompletarHelper::completar(
                $pending,
                Carbon::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay(),
                [
                    'notas'     => null,
                    'sin_gasto' => $sin_gasto,
                    'expense'   => $expense,
                ],
                $contexto->owner_id,
                $num_expense_resolver
            );

        } catch (AgendaYaCompletadaException $e) {

            throw new AccionIaException(422, $e->getMessage());

        } catch (AgendaGastoRequeridoException $e) {

            throw new AccionIaException(422, $e->getMessage());

        } catch (AgendaFechaInvalidaException $e) {

            throw new AccionIaException(422, $e->getMessage());

        } catch (\RuntimeException $e) {

            // AgendaCompletarHelper tira esta RuntimeException si la tarea se borró entre la lectura y el candado.
            if ($e->getMessage() === self::MENSAJE_TAREA_INEXISTENTE) {

                throw new AccionIaException(422, $e->getMessage());
            }

            throw $e;
        }

        $texto = 'Tarea marcada como hecha';

        if (!is_null($completada->expense)) {

            $texto .= ' y Gasto N° '.$completada->expense->num.' registrado';
        }

        return [
            'texto' => $texto,
            'ruta'  => self::ruta_de_la_agenda(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Piezas comunes
    // -------------------------------------------------------------------------------------------

    /**
     * @param  ContextoDeCargaIa  $contexto
     * @return bool
     */
    protected static function puede(ContextoDeCargaIa $contexto) {

        return PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::TAREAS);
    }

    /**
     * Arma, valida y guarda la tarjeta de una tarea nueva.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $datos  Claves del body de `POST api/pending`.
     * @param  string  $clave
     * @param  string|null  $aviso
     * @param  mixed  $reemplaza_a
     * @param  array  $extra  Claves adicionales de la respuesta de la herramienta.
     * @return array
     */
    protected static function armar_tarea_nueva(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $datos, $clave, $aviso, $reemplaza_a, array $extra) {

        $columnas = AgendaTareaHelper::validar($datos, $contexto->owner_id);

        if (is_string($columnas)) {

            return RespuestaDeCargaIa::error($columnas);
        }

        $descripcion = self::descripcion_de_tarea($columnas);

        $renglones = [
            ['etiqueta' => 'Qué', 'valor' => $descripcion['que']],
            ['etiqueta' => 'Cuándo', 'valor' => $descripcion['cuando']],
        ];

        if (!is_null($columnas['expense_concept_id'])) {

            $renglones[] = ['etiqueta' => 'Gasto asociado', 'valor' => $descripcion['gasto']];
        }

        if (!is_null($columnas['notas'])) {

            $renglones[] = ['etiqueta' => 'Notas', 'valor' => $columnas['notas']];
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_TAREA_NUEVA,
            $clave,
            $datos,
            ['titulo' => 'Tarea en la agenda', 'renglones' => $renglones, 'aviso' => $aviso],
            $reemplaza_a
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, 'Tarea en la agenda: '.$columnas['detalle'].' · '.$descripcion['cuando'], $extra);
    }

    /**
     * Body de `POST api/pending` para una tarea puntual, sin gasto ni notas.
     *
     * @param  string  $detalle
     * @param  \Carbon\Carbon  $fecha
     * @return array
     */
    protected static function datos_de_una_tarea_nueva($detalle, Carbon $fecha) {

        return [
            'detalle'               => (string) $detalle,
            'fecha_realizacion'     => $fecha->format('Y-m-d'),
            'es_recurrente'         => false,
            'unidad_frecuencia_id'  => null,
            'cantidad_frecuencia'   => null,
            'fecha_fin_recurrencia' => null,
            'expense_concept_id'    => null,
            'expense_amount'        => null,
            'notas'                 => null,
        ];
    }

    /**
     * Claves de repetición del body a partir de `repetir` {cada, unidad, hasta}. La cantidad y el fin
     * los valida después AgendaTareaHelper::validar(), con los mensajes de la pantalla.
     *
     * @param  array  $repetir
     * @return array  Claves del body, o la respuesta negativa.
     */
    protected static function regla_de_repeticion(array $repetir) {

        $cada = EntradaDeCargaIa::valor($repetir, 'cada');
        $unidad_pedida = EntradaDeCargaIa::valor($repetir, 'unidad');

        $faltan = [];

        if (EntradaDeCargaIa::vacio($cada)) {

            $faltan[] = 'cada cuántos días, semanas, meses o años se repite';
        }

        if (EntradaDeCargaIa::vacio($unidad_pedida)) {

            $faltan[] = 'si se repite por día, semana, mes o año';
        }

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan);
        }

        /*
         * Un cliente viejo puede no tener cargada la tabla unidad_frecuencias (UnidadFrecuenciaSeeder
         * nunca corrió). Sin ella la regla no se puede guardar, y un error explícito es mejor que una
         * tarea que no se repite.
         */
        if (!UnidadFrecuencia::whereIn('slug', array_keys(OpcionesDeCargaIaHelper::UNIDADES))->exists()) {

            return RespuestaDeCargaIa::error('Esta cuenta no tiene cargadas las unidades de repetición, así que una tarea que se repite hay que crearla desde la Agenda.');
        }

        $unidad = OpcionesDeCargaIaHelper::unidad_por_nombre($unidad_pedida);

        if (is_null($unidad)) {

            return RespuestaDeCargaIa::error(
                'La repetición tiene que ser por día, semana, mes o año.',
                ['unidades_de_repeticion' => OpcionesDeCargaIaHelper::unidades_de_repeticion()]
            );
        }

        $hasta = EntradaDeCargaIa::valor($repetir, 'hasta');

        return [
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => (int) $unidad->id,
            'cantidad_frecuencia'   => $cada,
            'fecha_fin_recurrencia' => EntradaDeCargaIa::vacio($hasta) ? null : $hasta,
        ];
    }

    /**
     * La tarea del dueño con lo que hace falta para describirla y expandirla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $tarea_id
     * @return \App\Models\Pending|null
     */
    protected static function tarea_del_dueno(ContextoDeCargaIa $contexto, $tarea_id) {

        return Pending::where('user_id', $contexto->owner_id)
                        ->where('id', (int) $tarea_id)
                        ->with('unidad_frecuencia', 'expense_concept.expense_category')
                        ->first();
    }

    /**
     * La tarea guardada, con la forma del body de `PUT api/pending` (base para superponer cambios).
     *
     * @param  \App\Models\Pending  $pending
     * @return array
     */
    protected static function datos_de_la_tarea($pending) {

        return [
            'detalle'               => (string) $pending->detalle,
            'fecha_realizacion'     => Carbon::parse($pending->fecha_realizacion)->format('Y-m-d'),
            'es_recurrente'         => (bool) $pending->es_recurrente,
            'unidad_frecuencia_id'  => $pending->unidad_frecuencia_id,
            'cantidad_frecuencia'   => $pending->cantidad_frecuencia,
            'fecha_fin_recurrencia' => is_null($pending->fecha_fin_recurrencia) ? null : Carbon::parse($pending->fecha_fin_recurrencia)->format('Y-m-d'),
            'expense_concept_id'    => $pending->expense_concept_id,
            'expense_amount'        => $pending->expense_amount,
            'notas'                 => $pending->notas,
        ];
    }

    /**
     * La tarea guardada con la forma de las columnas que devuelve AgendaTareaHelper::validar(), para
     * compararla con los cambios.
     *
     * @param  \App\Models\Pending  $pending
     * @return array
     */
    protected static function columnas_de_la_tarea($pending) {

        $es_recurrente = (bool) $pending->es_recurrente;
        $tiene_gasto = (int) $pending->expense_concept_id > 0;

        return [
            'detalle'               => (string) $pending->detalle,
            'fecha_realizacion'     => Carbon::parse($pending->fecha_realizacion)->format('Y-m-d 00:00:00'),
            'es_recurrente'         => $es_recurrente ? 1 : 0,
            'unidad_frecuencia_id'  => $es_recurrente && !is_null($pending->unidad_frecuencia_id) ? (int) $pending->unidad_frecuencia_id : null,
            'cantidad_frecuencia'   => $es_recurrente && !is_null($pending->cantidad_frecuencia) ? (int) $pending->cantidad_frecuencia : null,
            'fecha_fin_recurrencia' => $es_recurrente && !is_null($pending->fecha_fin_recurrencia) ? Carbon::parse($pending->fecha_fin_recurrencia)->format('Y-m-d') : null,
            'expense_concept_id'    => $tiene_gasto ? (int) $pending->expense_concept_id : null,
            'expense_amount'        => $tiene_gasto ? (float) $pending->expense_amount : null,
            'notas'                 => is_string($pending->notas) && trim($pending->notas) !== '' ? $pending->notas : null,
        ];
    }

    /**
     * Qué campos de la tarjeta cambian entre la tarea guardada y la editada.
     *
     * @param  array  $antes  columnas_de_la_tarea()
     * @param  array  $despues  AgendaTareaHelper::validar()
     * @return array<int,string>  que | fecha | repeticion | gasto | notas
     */
    protected static function campos_que_cambian(array $antes, array $despues) {

        $cambios = [];

        if ($antes['detalle'] !== $despues['detalle']) {

            $cambios[] = 'que';
        }

        if (substr($antes['fecha_realizacion'], 0, 10) !== substr($despues['fecha_realizacion'], 0, 10)) {

            $cambios[] = 'fecha';
        }

        if ((int) $antes['es_recurrente'] !== (int) $despues['es_recurrente']
            || self::entero_o_null($antes['unidad_frecuencia_id']) !== self::entero_o_null($despues['unidad_frecuencia_id'])
            || self::entero_o_null($antes['cantidad_frecuencia']) !== self::entero_o_null($despues['cantidad_frecuencia'])
            || $antes['fecha_fin_recurrencia'] !== $despues['fecha_fin_recurrencia']) {

            $cambios[] = 'repeticion';
        }

        $monto_antes = is_null($antes['expense_amount']) ? null : round((float) $antes['expense_amount'], 2);
        $monto_despues = is_null($despues['expense_amount']) ? null : round((float) $despues['expense_amount'], 2);

        if (self::entero_o_null($antes['expense_concept_id']) !== self::entero_o_null($despues['expense_concept_id'])
            || is_null($monto_antes) !== is_null($monto_despues)
            || (!is_null($monto_antes) && abs($monto_antes - $monto_despues) > 0.005)) {

            $cambios[] = 'gasto';
        }

        if ($antes['notas'] !== $despues['notas']) {

            $cambios[] = 'notas';
        }

        return $cambios;
    }

    /**
     * true si la edición cambia la REGLA de repetición (misma condición que
     * AgendaTareaHelper::reanclar_si_cambio_la_regla()) o saca la repetición.
     *
     * @param  array  $antes
     * @param  array  $despues
     * @return bool
     */
    protected static function cambia_la_regla(array $antes, array $despues) {

        if (!$despues['es_recurrente']) {

            return (bool) $antes['es_recurrente'];
        }

        $misma_regla = (bool) $antes['es_recurrente']
            && substr($antes['fecha_realizacion'], 0, 10) === substr($despues['fecha_realizacion'], 0, 10)
            && self::entero_o_null($antes['unidad_frecuencia_id']) === self::entero_o_null($despues['unidad_frecuencia_id'])
            && self::entero_o_null($antes['cantidad_frecuencia']) === self::entero_o_null($despues['cantidad_frecuencia']);

        return !$misma_regla;
    }

    /**
     * Textos de una tarea para la tarjeta, a partir de sus columnas.
     *
     * @param  array  $columnas  Con la forma de AgendaTareaHelper::validar().
     * @return array  que, fecha, repeticion, cuando, gasto, notas
     */
    protected static function descripcion_de_tarea(array $columnas) {

        $fecha = FormatoIaHelper::fecha_con_dia(Carbon::parse($columnas['fecha_realizacion'])->startOfDay());

        $repeticion = 'no se repite';
        $cuando = $fecha;

        if ($columnas['es_recurrente']) {

            $unidad = is_null($columnas['unidad_frecuencia_id']) ? null : UnidadFrecuencia::find($columnas['unidad_frecuencia_id']);

            $repeticion = FormatoIaHelper::repeticion($columnas['cantidad_frecuencia'], is_null($unidad) ? '' : $unidad->slug);

            if (!is_null($columnas['fecha_fin_recurrencia'])) {

                $repeticion .= ', hasta '.Carbon::parse($columnas['fecha_fin_recurrencia'])->format('d/m/Y');
            }

            $cuando .= ' y se repite '.$repeticion;
        }

        $gasto = 'sin gasto';

        if (!is_null($columnas['expense_concept_id'])) {

            $concepto = ExpenseConcept::with('expense_category')->find($columnas['expense_concept_id']);

            $nombre = is_null($concepto) ? 'Gasto' : OpcionesDeCargaIaHelper::nombre_de_subcategoria($concepto);

            $monto = (float) $columnas['expense_amount'];

            $gasto = $nombre.' · '.($monto > 0 ? FormatoIaHelper::monto($monto).' estimado' : 'monto a definir al pagarlo');
        }

        return [
            'que'        => (string) $columnas['detalle'],
            'fecha'      => $fecha,
            'repeticion' => $repeticion,
            'cuando'     => $cuando,
            'gasto'      => $gasto,
            'notas'      => is_null($columnas['notas']) ? 'sin notas' : (string) $columnas['notas'],
        ];
    }

    /**
     * true si esa ocurrencia ya está hecha (mismo criterio que AgendaCompletarHelper::completar()).
     *
     * @param  \App\Models\Pending  $pending
     * @param  \Carbon\Carbon  $fecha
     * @return bool
     */
    protected static function ya_hecha($pending, Carbon $fecha) {

        $completada = PendingCompleted::where('pending_id', $pending->id)
                                        ->whereDate('fecha_realizacion', $fecha->format('Y-m-d'))
                                        ->exists();

        return $completada || (!$pending->es_recurrente && $pending->completado);
    }

    /**
     * Fechas sin hacer de una tarea para ofrecer cuando falta la fecha: las 10 vencidas más
     * recientes y las 10 próximas dentro de 30 días.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Pending  $pending
     * @return array  [{fecha, dia, vencida}]
     */
    protected static function fechas_pendientes(ContextoDeCargaIa $contexto, $pending) {

        $hoy = $contexto->hoy->copy()->startOfDay();

        $vencidas = [];

        foreach (AgendaHelper::vencidas($contexto->owner_id, $hoy) as $ocurrencia) {

            if ((int) $ocurrencia['pending_id'] === (int) $pending->id) {

                $vencidas[] = self::fecha_para_la_ia($ocurrencia['fecha'], true);
            }
        }

        $proximas = [];

        foreach (AgendaHelper::ocurrencias_entre($contexto->owner_id, $hoy, $hoy->copy()->addDays(30)) as $ocurrencia) {

            if ((int) $ocurrencia['pending_id'] === (int) $pending->id && !$ocurrencia['completado']) {

                $proximas[] = self::fecha_para_la_ia($ocurrencia['fecha'], false);
            }
        }

        return array_merge(array_slice($vencidas, -10), array_slice($proximas, 0, 10));
    }

    /**
     * @param  string  $fecha  Y-m-d
     * @param  bool  $vencida
     * @return array
     */
    protected static function fecha_para_la_ia($fecha, $vencida) {

        return [
            'fecha'   => $fecha,
            'dia'     => FormatoIaHelper::fecha_con_dia(Carbon::createFromFormat('Y-m-d', $fecha)->startOfDay()),
            'vencida' => (bool) $vencida,
        ];
    }

    /**
     * Ruta de la SPA para ver la Agenda (contrato §2.3: `{name, params, texto}`).
     *
     * @return array
     */
    protected static function ruta_de_la_agenda() {

        return [
            'name'   => 'pending',
            'params' => new \stdClass(),
            'texto'  => 'Ver en la Agenda',
        ];
    }

    /**
     * @param  mixed  $valor
     * @return int|null
     */
    protected static function entero_o_null($valor) {

        return is_null($valor) || $valor === '' ? null : (int) $valor;
    }
}
