<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Models\Caja;
use App\Models\CurrentAcountPaymentMethod;

/**
 * Validación común de "cómo se pagó" para las tres cargas del asistente de IA que mueven plata: el
 * gasto, el pago de cuenta corriente y marcar hecha una tarea con gasto (misión
 * asistente-ia-acciones, §3.4 del plan).
 *
 * Lo que valida es lo mismo que la pantalla no deja pasar, con los criterios de
 * PaymentMethodsStep.vue que espeja OpcionesDeCargaIaHelper:
 *   - Cada fila con un método existente y usable (ni cheque, ni tarjeta de crédito, ni el método 1).
 *   - Una sola fila sin monto toma el total; varias filas tienen que traer monto y sumar el total.
 *   - 🔴 CAJA OBLIGATORIA. Espejo de `hay_metodo_de_pago_sin_caja()` de
 *     src/mixins/metodos_de_pago_validacion.js (develop, misión agenda-ajustes-ux): si la cuenta
 *     tiene cajas, toda fila con monto necesita una caja; la que vino (tiene que estar entre las
 *     ofrecibles), la de por defecto, o se pregunta. Si la cuenta NO tiene ninguna caja, la fila va
 *     sin caja: PaymentMethodsStep.vue::show_caja_select() no dibuja el selector cuando
 *     `store.caja.models` está vacío, y ese store es CajaController@index (todas las cajas del
 *     dueño, abiertas o no), que es el universo de OpcionesDeCargaIaHelper::hay_cajas().
 *
 * Una fila con monto y sin caja no la rechaza el backend (solo loguea un warning), así que sin esta
 * validación la plata de esa fila no impacta en ninguna caja y el único rastro es un log.
 */
class PagosIaHelper {

    /**
     * Tolerancia para comparar la suma de los pagos con el total (centavos de redondeo).
     */
    const TOLERANCIA = 0.01;

    /**
     * Valida las filas de pago que mandó la IA y las devuelve con la forma completa que manda la
     * pantalla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $pagos  Input `pagos` de la herramienta: [{metodo_de_pago_id, monto?, caja_id?}].
     * @param  float  $total  Monto total de la carga.
     * @param  int  $moneda_id  Moneda de la carga (1 pesos, 2 dólares): filtra las cajas ofrecibles.
     * @return array  ['ok' => true, 'filas' => [...], 'renglones' => [...], 'resumen' => string], o la
     *                respuesta negativa de RespuestaDeCargaIa.
     */
    static function validar(ContextoDeCargaIa $contexto, $pagos, $total, $moneda_id) {

        $metodos = OpcionesDeCargaIaHelper::metodos_de_pago();

        $opciones_de_metodos = ['metodos_de_pago' => OpcionesDeCargaIaHelper::opciones_de_metodos($metodos)];

        if (!is_array($pagos) || !count($pagos)) {

            return RespuestaDeCargaIa::faltan(['cómo se pagó'], $opciones_de_metodos);
        }

        $filas = [];

        foreach (array_values($pagos) as $indice => $pago) {

            $pago = is_array($pago) ? $pago : [];

            $metodo_id = isset($pago['metodo_de_pago_id']) ? (int) $pago['metodo_de_pago_id'] : 0;

            if ($metodo_id <= 0) {

                return RespuestaDeCargaIa::faltan(['con qué método se hizo el pago '.($indice + 1)], $opciones_de_metodos);
            }

            $metodo = null;

            foreach ($metodos as $candidato) {

                if ((int) $candidato->id === $metodo_id) {

                    $metodo = $candidato;
                    break;
                }
            }

            if (is_null($metodo)) {

                return RespuestaDeCargaIa::error('No existe el método de pago con id '.$metodo_id.'.', $opciones_de_metodos);
            }

            $motivo = OpcionesDeCargaIaHelper::motivo_no_usable($metodo);

            if (!is_null($motivo)) {

                return RespuestaDeCargaIa::error($motivo, $opciones_de_metodos);
            }

            $filas[] = [
                'metodo'  => $metodo,
                'monto'   => array_key_exists('monto', $pago) ? $pago['monto'] : null,
                'caja_id' => isset($pago['caja_id']) ? (int) $pago['caja_id'] : 0,
                'caja'    => null,
            ];
        }

        // Una sola fila sin monto toma el total (es lo que hace el botón "completar" de la pantalla).
        if (count($filas) === 1 && self::vacio($filas[0]['monto'])) {

            $filas[0]['monto'] = $total;
        }

        $suma = 0;

        foreach ($filas as $fila) {

            if (self::vacio($fila['monto'])) {

                return RespuestaDeCargaIa::faltan(['cuánto se pagó con '.$fila['metodo']->name]);
            }

            if (!is_numeric($fila['monto']) || (float) $fila['monto'] <= 0) {

                return RespuestaDeCargaIa::error('El monto de cada pago tiene que ser un número mayor a 0.');
            }

            $suma += (float) $fila['monto'];
        }

        if (abs($suma - (float) $total) > self::TOLERANCIA) {

            return RespuestaDeCargaIa::error(
                'Los pagos suman '.FormatoIaHelper::monto($suma, $moneda_id).' y el total es '.FormatoIaHelper::monto($total, $moneda_id).': tienen que coincidir.'
            );
        }

        $calles = OpcionesDeCargaIaHelper::calles_de_sucursales($contexto->owner_id);

        if (OpcionesDeCargaIaHelper::hay_cajas($contexto->owner_id)) {

            $cajas = OpcionesDeCargaIaHelper::cajas_de_la_cuenta($contexto->owner_id);
            $ofrecibles = OpcionesDeCargaIaHelper::cajas_ofrecibles($contexto, $moneda_id, $cajas);

            if (!count($ofrecibles)) {

                return RespuestaDeCargaIa::error('No tenés ninguna caja abierta disponible, abrila en Tesorería.');
            }

            $opciones_de_cajas = [];

            foreach ($ofrecibles as $ofrecible) {

                $opciones_de_cajas[] = OpcionesDeCargaIaHelper::opcion_de_caja($ofrecible, $calles);
            }

            $faltan = [];

            foreach ($filas as $indice => $fila) {

                if ($fila['caja_id'] > 0) {

                    $caja = null;

                    foreach ($ofrecibles as $ofrecible) {

                        if ((int) $ofrecible->id === $fila['caja_id']) {

                            $caja = $ofrecible;
                            break;
                        }
                    }

                    if (is_null($caja)) {

                        return RespuestaDeCargaIa::error(
                            'La caja elegida para el pago con '.$fila['metodo']->name.' no está entre las que podés usar.',
                            ['cajas' => $opciones_de_cajas]
                        );
                    }

                } else {

                    $caja = OpcionesDeCargaIaHelper::caja_por_defecto($contexto, $fila['metodo']->id, $moneda_id, $ofrecibles, $cajas);

                    if (is_null($caja)) {

                        $faltan[] = 'a qué caja va el pago con '.$fila['metodo']->name;

                        continue;
                    }
                }

                $filas[$indice]['caja'] = $caja;
            }

            if (count($faltan)) {

                return RespuestaDeCargaIa::faltan($faltan, ['cajas' => $opciones_de_cajas]);
            }

        } else {

            foreach ($filas as $fila) {

                if ($fila['caja_id'] > 0) {

                    return RespuestaDeCargaIa::error('La cuenta no tiene ninguna caja creada: el pago se carga sin caja.');
                }
            }
        }

        $datos = [];
        $renglones = [];
        $partes = [];

        foreach ($filas as $fila) {

            $caja = $fila['caja'];

            $datos[] = self::fila_de_pantalla($fila['metodo']->id, (float) $fila['monto'], is_null($caja) ? 0 : (int) $caja->id, $moneda_id);

            $destino = is_null($caja) ? ' (sin caja)' : ' → '.OpcionesDeCargaIaHelper::nombre_de_caja($caja, $calles);

            $renglones[] = [
                'etiqueta' => 'Pago',
                'valor'    => $fila['metodo']->name.' · '.FormatoIaHelper::monto($fila['monto'], $moneda_id).$destino,
            ];

            $partes[] = $fila['metodo']->name.$destino;
        }

        return [
            'ok'        => true,
            'filas'     => $datos,
            'renglones' => $renglones,
            'resumen'   => implode(' + ', $partes),
        ];
    }

    /**
     * Revalida las filas de pago de una tarjeta al CONFIRMARLA, adentro de la transacción del
     * ejecutor y antes de escribir nada.
     *
     * Entre la propuesta y el clic pueden pasar horas: una caja se pudo borrar, un método de pago
     * también, y una caja nueva nunca abierta pudo haber quedado elegida. Los tres casos, si pasaran,
     * terminarían a mitad de camino (un método borrado se saltea en silencio en
     * PaymentMethodHelper::attach_payment_methods(); una caja sin apertura revienta en
     * MovimientoCajaHelper con parte de la carga ya escrita), así que se cortan acá con un 422 que
     * dice qué pasó. La prevalidación de cajas sin apertura es la misma que hacen las pantallas
     * (CurrentAcountCajaHelper::cajas_sin_apertura_en_payload()), con el texto de cada una.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $payment_methods  Filas guardadas en `datos` de la tarjeta.
     * @param  string  $que  "el gasto" o "el pago", para el texto del 422 (el mismo que la pantalla).
     * @return void
     *
     * @throws AccionIaException
     */
    static function verificar_para_ejecutar(ContextoDeCargaIa $contexto, $payment_methods, $que) {

        $payment_methods = is_array($payment_methods) ? $payment_methods : [];

        foreach ($payment_methods as $fila) {

            $metodo_id = is_array($fila) && isset($fila['current_acount_payment_method_id']) ? (int) $fila['current_acount_payment_method_id'] : 0;

            if ($metodo_id <= 0 || !CurrentAcountPaymentMethod::where('id', $metodo_id)->exists()) {

                throw new AccionIaException(422, 'Uno de los métodos de pago de la tarjeta ya no existe. Pedímelo de nuevo.');
            }

            $caja_id = isset($fila['caja_id']) ? (int) $fila['caja_id'] : 0;

            if ($caja_id > 0 && !Caja::where('id', $caja_id)->where('user_id', $contexto->owner_id)->exists()) {

                throw new AccionIaException(422, 'Una de las cajas de la tarjeta ya no existe. Pedímelo de nuevo.');
            }
        }

        $sin_apertura = CurrentAcountCajaHelper::cajas_sin_apertura_en_payload($payment_methods);

        if (count($sin_apertura)) {

            throw new AccionIaException(
                422,
                'Las siguientes cajas nunca se abrieron: '.implode(', ', $sin_apertura).'. Hay que abrirlas para poder registrar '.$que.'.'
            );
        }
    }

    /**
     * Una fila de pago con TODAS las claves que manda MultiPaymentMethods en la pantalla (la misma
     * forma que tests/Feature/Agenda/AgendaTestCase.php::fila_metodo_de_pago). Se arma completa y no
     * con las tres claves que usa el alta, porque la carga va por los mismos helpers que la pantalla
     * y esos helpers leen más claves de las que parece (moneda, cotización, cuota): ejecutar con un
     * payload que el front nunca manda es otra clase de error conocida.
     *
     * @param  int  $metodo_id
     * @param  float  $monto
     * @param  int  $caja_id  0 = sin caja.
     * @param  int  $moneda_id
     * @return array
     */
    static function fila_de_pantalla($metodo_id, $monto, $caja_id, $moneda_id) {

        return [
            'current_acount_payment_method_id'  => (int) $metodo_id,
            'amount'                            => (float) $monto,
            'caja_id'                           => (int) $caja_id,
            'moneda_id'                         => (int) $moneda_id,
            'cotizacion'                        => 0,
            'amount_cotizado'                   => 0,
            'cuota_id'                          => 0,
            'bank'                              => '',
            'payment_date'                      => '',
            'num'                               => '',
            'credit_card_id'                    => 0,
            'credit_card_payment_plan_id'       => 0,
        ];
    }

    /**
     * @param  mixed  $valor
     * @return bool
     */
    protected static function vacio($valor) {

        return is_null($valor) || $valor === '';
    }
}
