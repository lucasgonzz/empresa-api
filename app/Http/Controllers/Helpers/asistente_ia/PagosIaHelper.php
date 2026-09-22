<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Models\Caja;
use App\Models\CurrentAcountPaymentMethod;
use Carbon\Carbon;

/**
 * Validación común de "cómo se pagó" para las tres cargas del asistente de IA que mueven plata: el
 * gasto, el pago de cuenta corriente y marcar hecha una tarea con gasto (misión
 * asistente-ia-acciones, §3.4 del plan).
 *
 * Lo que valida es lo mismo que la pantalla no deja pasar, con los criterios de
 * PaymentMethodsStep.vue que espeja OpcionesDeCargaIaHelper:
 *   - Cada fila con un método existente y usable (ni tarjeta de crédito, ni retención).
 *   - Una sola fila sin monto toma el total; varias filas tienen que traer monto y sumar el total.
 *   - 🔴 EL CHEQUE, DESDE EL 22/9/2026 (misión asistente-capacidades-y-hilos). Su fila lleva seis
 *     claves más —número, banco, las dos fechas, si es e-cheq y las notas— que lee
 *     `ChequeHelper::crear_cheque()`, y NO lleva caja: un cheque no mueve caja al cargarse, la
 *     plata se mueve recién cuando se lo cobra o se lo entrega. Ver fila_de_cheque() y
 *     validar_cheque(). 🔴 Lo que sigue siendo de la pantalla es ENDOSAR un cheque recibido
 *     (`cheque_id`), porque sus prevalidaciones viven en `CurrentAcountController::pago()` y el
 *     camino del asistente no pasa por ahí.
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

            /*
             * El CHEQUE (misión asistente-capacidades-y-hilos, 22/9/2026): su fila lleva seis
             * claves más y NO lleva caja. Se valida acá, con la fila todavía en la mano, para que
             * el "faltan" nombre el método del que se está hablando.
             */
            $es_cheque = OpcionesDeCargaIaHelper::es_cheque($metodo);

            $cheque = null;

            if ($es_cheque) {

                if (isset($pago['caja_id']) && (int) $pago['caja_id'] > 0) {

                    return RespuestaDeCargaIa::error(
                        'Un cheque no entra a ninguna caja al cargarse: la plata se mueve recién cuando lo cobrás o lo entregás, desde la pantalla de Cheques. No me mandes caja para el cheque.'
                    );
                }

                $cheque = self::validar_cheque($contexto, EntradaDeCargaIa::valor($pago, 'cheque'), (string) $metodo->name);

                if (RespuestaDeCargaIa::es_negativa($cheque)) {

                    return $cheque;
                }
            }

            $filas[] = [
                'metodo'  => $metodo,
                'monto'   => array_key_exists('monto', $pago) ? $pago['monto'] : null,
                'caja_id' => isset($pago['caja_id']) ? (int) $pago['caja_id'] : 0,
                'caja'    => null,
                'cheque'  => $cheque,
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

                /*
                 * 🔴 EL CHEQUE NO PASA POR ACÁ. No mueve caja al cargarse, y la pantalla tampoco le
                 * dibuja el selector (`PaymentMethodsStep.vue::show_caja_select()`): pedirle una
                 * caja sería inventar un requisito que el sistema no tiene, y elegirle la de por
                 * defecto sería colgar el movimiento de una caja que no recibió nada.
                 */
                if (!is_null($fila['cheque'])) {

                    continue;
                }

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

                    return RespuestaDeCargaIa::error('Esta cuenta no tiene ninguna caja creada: no me mandes caja.');
                }
            }
        }

        $datos = [];
        $renglones = [];
        $partes = [];

        foreach ($filas as $fila) {

            $caja = $fila['caja'];

            if (!is_null($fila['cheque'])) {

                $datos[] = self::fila_de_cheque($fila['metodo']->id, (float) $fila['monto'], $moneda_id, $fila['cheque']);

                $detalle = ' · N° '.$fila['cheque']['numero'].' de '.$fila['cheque']['banco']
                    .' · vence '.FormatoIaHelper::fecha_con_dia(Carbon::createFromFormat('Y-m-d', $fila['cheque']['fecha_pago'])->startOfDay());

                $renglones[] = [
                    'etiqueta' => 'Cheque',
                    'valor'    => FormatoIaHelper::monto($fila['monto'], $moneda_id).$detalle,
                ];

                $partes[] = $fila['metodo']->name.' N° '.$fila['cheque']['numero'];

                continue;
            }

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

        self::verificar_cajas_ofrecibles($contexto, $payment_methods);

        $sin_apertura = CurrentAcountCajaHelper::cajas_sin_apertura_en_payload($payment_methods);

        if (count($sin_apertura)) {

            throw new AccionIaException(
                422,
                'Las siguientes cajas nunca se abrieron: '.implode(', ', $sin_apertura).'. Hay que abrirlas para poder registrar '.$que.'.'
            );
        }
    }

    /**
     * 🔴 LA CAJA TIENE QUE SEGUIR SIENDO OFRECIBLE, NO SOLO TENER ALGUNA APERTURA.
     *
     * `cajas_sin_apertura_en_payload()` deja pasar a propósito una caja CERRADA que tenga aperturas
     * previas, y para la pantalla eso está bien: su desplegable nunca ofrece una caja cerrada
     * (`get_caja_options()` arranca con `filter(caja => caja.abierta)`), así que el caso no puede
     * darse desde ahí. Acá sí puede: la tarjeta se propone con la caja abierta y la persona la
     * confirma hasta 24 h después, con el cajero habiendo cerrado la caja en el medio. El movimiento
     * se colgaría de una apertura YA CERRADA y descuadraría ese arqueo, sin que nada lo avise.
     *
     * Se revalida contra el MISMO desplegable que se le ofreció (`cajas_ofrecibles`), no contra una
     * condición propia: eso cubre de una las otras tres cosas que podían cambiar entre la propuesta y
     * el clic y tampoco se revisaban — la sucursal de la caja, su moneda y quién la puede usar
     * (`caja_user`).
     *
     * La moneda sale de cada fila (`moneda_id`, que guarda `fila_de_pantalla()`), porque un pago
     * repartido puede tener una fila en pesos y otra en dólares y cada una tiene su propio universo
     * de cajas.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $payment_methods  Filas guardadas en `datos` de la tarjeta.
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_cajas_ofrecibles(ContextoDeCargaIa $contexto, array $payment_methods) {

        /** Cajas de la cuenta, cargadas una sola vez (null = todavía no hizo falta). */
        $cajas = null;

        /** Calles de las sucursales, para nombrar las cajas igual que el desplegable. */
        $calles = [];

        /** Ofrecibles por moneda, para no recalcular el filtro en cada fila. */
        $ofrecibles_por_moneda = [];

        foreach ($payment_methods as $fila) {

            $caja_id = is_array($fila) && isset($fila['caja_id']) ? (int) $fila['caja_id'] : 0;

            // Una fila sin caja es válida (cuenta sin cajas): no hay nada que revalidar.
            if ($caja_id <= 0) {

                continue;
            }

            $moneda_id = is_array($fila) && isset($fila['moneda_id']) ? (int) $fila['moneda_id'] : 1;

            if (is_null($cajas)) {

                $cajas = OpcionesDeCargaIaHelper::cajas_de_la_cuenta($contexto->owner_id);
                $calles = OpcionesDeCargaIaHelper::calles_de_sucursales($contexto->owner_id);
            }

            if (!isset($ofrecibles_por_moneda[$moneda_id])) {

                $ofrecibles_por_moneda[$moneda_id] = OpcionesDeCargaIaHelper::cajas_ofrecibles($contexto, $moneda_id, $cajas);
            }

            $sigue_ofrecible = false;

            foreach ($ofrecibles_por_moneda[$moneda_id] as $ofrecible) {

                if ((int) $ofrecible->id === $caja_id) {

                    $sigue_ofrecible = true;
                    break;
                }
            }

            if ($sigue_ofrecible) {

                continue;
            }

            throw new AccionIaException(422, self::mensaje_de_caja_no_ofrecible($cajas, $ofrecibles_por_moneda[$moneda_id], $calles, $caja_id));
        }
    }

    /**
     * El 422 de una caja que salió del desplegable: la nombra y dice cuáles quedan, para que la
     * persona pueda pedir la carga de nuevo sin adivinar (el prompt obliga a repetir el motivo tal
     * cual, así que el texto es el que va a leer).
     *
     * @param  \Illuminate\Support\Collection  $cajas  Cajas de la cuenta.
     * @param  array  $ofrecibles  Las que el desplegable ofrece ahora para esa moneda.
     * @param  array<int,string>  $calles
     * @param  int  $caja_id  La que ya no se puede usar.
     * @return string
     */
    protected static function mensaje_de_caja_no_ofrecible($cajas, array $ofrecibles, array $calles, $caja_id) {

        $nombre = 'La caja de la tarjeta';

        foreach ($cajas as $candidata) {

            if ((int) $candidata->id === (int) $caja_id) {

                $nombre = OpcionesDeCargaIaHelper::nombre_de_caja($candidata, $calles);
                break;
            }
        }

        if (!count($ofrecibles)) {

            return $nombre.' ya no está disponible y no te queda ninguna otra: abrí una caja en Tesorería y pedímelo de nuevo.';
        }

        $nombres = [];

        foreach ($ofrecibles as $ofrecible) {

            $nombres[] = OpcionesDeCargaIaHelper::nombre_de_caja($ofrecible, $calles);
        }

        return $nombre.' ya no está disponible (la cerraron, cambió de sucursal o de moneda, o ya no la podés usar). '.
            'Ahora podés usar: '.implode(', ', $nombres).'. Pedímelo de nuevo con una de esas.';
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
     * La fila de pantalla MÁS las claves del cheque (misión asistente-capacidades-y-hilos,
     * 22/9/2026).
     *
     * 🔴 SON EXACTAMENTE LAS QUE LEE `ChequeHelper::crear_cheque()` (`:58-129`), con sus nombres.
     * `fila_de_pantalla()` manda `bank`, `payment_date` y `num` —nombres viejos de otra época— que
     * `crear_cheque()` NO lee: si la fila de un cheque fuera solo ésa, el cheque se crearía con
     * número, banco y fechas en null y nadie se enteraría, porque todas las columnas de `cheques`
     * son nullable y el helper no valida nada.
     *
     * 🔴 Y EL `caja_id` VA EN 0 SIEMPRE. Un cheque no mueve caja al cargarse: la plata se mueve
     * recién con `PUT /cheque/cobrar` o `/pagar`, que el asistente NO llama. Es lo mismo que hace
     * la pantalla, que para este método no dibuja el selector de caja.
     *
     * ⚠️ SIN `cheque_id`, a propósito: ese campo ENDOSA un cheque recibido en vez de crear uno
     * nuevo, solo vale en un pago a proveedor o en un gasto, y tiene sus propias cinco
     * prevalidaciones (`ChequeHelper::problemas_de_endoso_en_payload()`) que el camino del
     * asistente NO ejecuta — porque no pasa por `CurrentAcountController::pago()`, va directo a
     * `CurrentAcountPagoAltaHelper::registrar()`. Endosar se sigue haciendo desde la pantalla.
     *
     * @param  int  $metodo_id
     * @param  float  $monto
     * @param  int  $moneda_id
     * @param  array  $cheque  numero, banco, fecha_emision, fecha_pago, es_echeq, notes.
     * @return array
     */
    static function fila_de_cheque($metodo_id, $monto, $moneda_id, array $cheque) {

        $fila = self::fila_de_pantalla($metodo_id, $monto, 0, $moneda_id);

        $fila['numero']          = $cheque['numero'];
        $fila['banco']           = $cheque['banco'];
        // 0 = sin banco del catálogo. `ChequeHelper::cheque_banco_id_de()` lo lee como null, y el
        // texto libre de `banco` queda igual: el catálogo se arma después con
        // proponer_unificar_bancos_de_cheques, que es la herramienta que existe para eso.
        $fila['cheque_banco_id'] = 0;
        $fila['fecha_emision']   = $cheque['fecha_emision'];
        $fila['fecha_pago']      = $cheque['fecha_pago'];
        $fila['es_echeq']        = $cheque['es_echeq'];
        $fila['notes']           = $cheque['notes'];

        return $fila;
    }

    /**
     * Valida los datos del cheque de una fila y los devuelve normalizados, o la respuesta negativa.
     *
     * Qué se pide y por qué: el NÚMERO y el BANCO son lo que identifica al cheque en la cartera (sin
     * ellos, la pantalla de Cheques muestra una fila en blanco que nadie puede reconciliar), y la
     * FECHA DE PAGO es el vencimiento: es el dato con el que el comercio decide si lo deposita, lo
     * endosa o lo espera. La fecha de emisión, si no la dicen, es hoy.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $cheque  El objeto `cheque` de la fila que mandó la IA.
     * @param  string  $nombre_del_metodo  Para el texto de lo que falta.
     * @return array  ['numero', 'banco', 'fecha_emision', 'fecha_pago', 'es_echeq', 'notes'] o la negativa.
     */
    static function validar_cheque(ContextoDeCargaIa $contexto, $cheque, $nombre_del_metodo) {

        $cheque = is_array($cheque) ? $cheque : [];

        $faltan = [];

        $numero = EntradaDeCargaIa::texto($cheque, 'numero');

        if ($numero === '') {

            $faltan[] = 'el número del cheque';
        }

        $banco = EntradaDeCargaIa::texto($cheque, 'banco');

        if ($banco === '') {

            $faltan[] = 'de qué banco es el cheque';
        }

        $fecha_pago = EntradaDeCargaIa::valor($cheque, 'fecha_pago');

        if (EntradaDeCargaIa::vacio($fecha_pago)) {

            $faltan[] = 'para qué fecha es el cheque (su vencimiento)';
        }

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan);
        }

        $fecha_pago = EntradaDeCargaIa::fecha($contexto, $fecha_pago);

        if (RespuestaDeCargaIa::es_negativa($fecha_pago)) {

            return $fecha_pago;
        }

        $fecha_emision = EntradaDeCargaIa::valor($cheque, 'fecha_emision');

        if (EntradaDeCargaIa::vacio($fecha_emision)) {

            $fecha_emision = $contexto->hoy;

        } else {

            $fecha_emision = EntradaDeCargaIa::fecha($contexto, $fecha_emision);

            if (RespuestaDeCargaIa::es_negativa($fecha_emision)) {

                return $fecha_emision;
            }
        }

        if ($fecha_pago->lt($fecha_emision)) {

            return RespuestaDeCargaIa::error(
                'El cheque no puede vencer antes de emitirse: decime bien la fecha de pago del cheque de '.$nombre_del_metodo.'.'
            );
        }

        $notas = EntradaDeCargaIa::texto($cheque, 'notes');

        return [
            'numero'        => mb_substr($numero, 0, 191),
            'banco'         => mb_substr($banco, 0, 191),
            'fecha_emision' => $fecha_emision->format('Y-m-d'),
            'fecha_pago'    => $fecha_pago->format('Y-m-d'),
            'es_echeq'      => EntradaDeCargaIa::valor($cheque, 'es_echeq') ? 1 : 0,
            'notes'         => $notas === '' ? null : mb_substr($notas, 0, 500),
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
