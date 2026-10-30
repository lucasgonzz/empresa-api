<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Http\Controllers\ProviderOrderScanController;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderScan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «VOLVÉ A CARGARLA CON OTRO PROVEEDOR»: cambiarle el proveedor a una compra que el agente acaba de
 * crear, en vez de crear otra (misión agente-mejoras-uso-real, E1 punto 7, 29/10/2026).
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 19 del 29/10/2026). El agente cargó la Compra N° 1357 de
 * FERRETOTAL con la foto de la factura; nueve minutos después Pablo escribió «la podés volver a cargar
 * con el proveedor mariano limpieza». El agente no tenía cómo tocar una compra ya creada: le pidió la
 * foto de nuevo (la que ya había mandado quedó "usada") y creó la Compra N° 1359 de mariano limpieza.
 * Quedaron DOS compras para la misma factura —la 1357 con el proveedor equivocado— y nadie le avisó que
 * la primera seguía ahí. Lo que pidió es lo que hace la pantalla de la compra al cambiarle el proveedor.
 *
 * 🔴 SOLO SOBRE UNA COMPRA QUE CREÓ EL AGENTE Y QUE NADIE TOCÓ DESPUÉS, y las cuatro condiciones van
 * juntas (ver motivo_para_no_cambiar()):
 *   1. LA CREÓ EL AGENTE en las últimas HORAS horas: hay una tarjeta confirmada de compra con factura o
 *      de compra sin artículos cuyo `resultado.provider_order_id` es esta compra. Una compra que cargó
 *      el dueño desde la pantalla no se toca desde acá: para eso está la pantalla, que muestra todo.
 *   2. VACÍA: sin artículos. Con artículos, el proveedor decide costos, códigos de proveedor y precios
 *      de venta de esos renglones; cambiarlo de un saque no es «la cargué mal».
 *   3. SIN FACTURA A MANO: ninguna factura (`provider_order_afip_tickets`) que no haya cargado el
 *      propio agente, sin `numero_comprobante` ni fecha de comprobante escritos en la pantalla.
 *   4. SU ESCANEO NO ESTÁ CONFIRMADO. Uno confirmado ya asentó renglones con este proveedor.
 *   5. SIN PAGOS IMPUTADOS (ronda de arreglos, 30/10/2026): el pago quedaría a favor en la cuenta del
 *      proveedor viejo. Y si el proveedor nuevo hay que darlo de alta, la tarjeta espera el sí siempre.
 *
 * ⚠️ UN ESCANEO EN CURSO NO BLOQUEA, a propósito. En la CONV 19 el pedido llegó nueve minutos después
 * de la compra, con el escaneo quizás todavía corriendo (la ventana de "en curso" es de una hora), y
 * bloquear ahí es devolver al dueño al mismo callejón: «esperá», y él vuelve a mandar la foto. El
 * escaneo busca los artículos por los códigos del proveedor de la compra cuando procesa
 * (EscaneoFacturaCompraService), así que el resultado se lo avisa para que lo mire al revisarlo.
 *
 * 🔴 LA PLATA SE MUEVE POR EL MISMO CAMINO QUE LA PANTALLA. Se cambia `provider_id` con el candado de
 * las DOS cuentas (como ProviderOrderController::update) y se recalcula con
 * FacturaDeCompraHelper::recalcular_compra(): NewProviderOrderHelper::set_current_acount() ve que el
 * movimiento de la compra está en la cuenta de OTRO proveedor (check_cambio_moneda(): busca el
 * movimiento por la cuenta nueva, no lo encuentra, borra el viejo y recalcula los saldos de la cuenta
 * vieja) y crea el movimiento en la cuenta del proveedor nuevo con su saldo. `actualizar_current_acount()`
 * NO reasigna `credit_account_id` —por eso no alcanza con un save—: el traspaso lo hace ese borrado y
 * alta.
 *
 * El escaneo de la factura SE CONSERVA: cuelga de la compra, no del proveedor, y al revisarlo el dueño
 * ya ve la compra con el proveedor nuevo.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class CambioDeProveedorDeCompraIaHelper
{
    /** Hasta cuántas horas después de crearla el agente puede cambiarle el proveedor a una compra. */
    const HORAS = AiMessageAction::HORAS_VENCIMIENTO;

    /** Los tipos de tarjeta que CREAN una compra (los únicos cuyas compras se pueden corregir acá). */
    const TIPOS_QUE_CREAN_COMPRAS = [
        AiMessageAction::TIPO_COMPRA_CON_FACTURA,
        AiMessageAction::TIPO_COMPRA_SIN_ARTICULOS,
    ];

    /**
     * Herramienta proponer_cambio_de_proveedor_de_compra.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  proveedor* (el nuevo), compra_num (si falta: la última que creó el agente
     *                        en esta conversación), reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        $compra = self::compra_a_corregir($contexto, EntradaDeCargaIa::valor($input, 'compra_num'));

        if (RespuestaDeCargaIa::es_negativa($compra)) {

            return $compra;
        }

        $motivo = self::motivo_para_no_cambiar($contexto, $compra);

        if (!is_null($motivo)) {

            return RespuestaDeCargaIa::error($motivo);
        }

        $anterior = Provider::where('user_id', $contexto->owner_id)->where('id', (int) $compra->provider_id)->first();

        $nombre_anterior = is_null($anterior) ? 'sin proveedor' : (string) $anterior->name;

        /* El proveedor nuevo, con el reconocimiento de siempre (ProveedorDeLaFacturaIaHelper). */
        $proveedor = ProveedorDeLaFacturaIaHelper::resolver($contexto, EntradaDeCargaIa::texto($input, 'proveedor'), '', $mensaje);

        if (RespuestaDeCargaIa::es_negativa($proveedor)) {

            return $proveedor;
        }

        $proveedor_nuevo = null;

        if (is_array($proveedor)) {

            $proveedor_nuevo = PropuestaCompraConFacturaIaHelper::alta_del_proveedor($contexto, $proveedor['nuevo']);

            if (RespuestaDeCargaIa::es_negativa($proveedor_nuevo)) {

                return $proveedor_nuevo;
            }

        } elseif ((int) $proveedor->id === (int) $compra->provider_id) {

            return RespuestaDeCargaIa::error('La Compra N° ' . $compra->num . ' ya es de ' . $proveedor->name . ': no hay nada que cambiar.');
        }

        $nombre_nuevo = is_null($proveedor_nuevo) ? (string) $proveedor->name : $proveedor_nuevo['nombre'];

        $renglones = [
            ['etiqueta' => 'Compra', 'valor' => 'N° ' . $compra->num . ' del ' . Carbon::parse($compra->created_at)->format('d/m/Y')],
            ['etiqueta' => 'Proveedor', 'valor' => 'De ' . $nombre_anterior . ' a ' . $nombre_nuevo . (is_null($proveedor_nuevo) ? '' : ' (nuevo: lo doy de alta)')],
            ['etiqueta' => 'Qué se hace', 'valor' => 'Se cambia el proveedor de esta compra: no se crea otra, y su factura sigue colgada de ella'],
        ];

        $total = round((float) $compra->total, 2);

        if ($compra->generate_current_acount && $total > 0) {

            $renglones[] = [
                'etiqueta' => 'Cuenta corriente',
                'valor'    => 'Los ' . FormatoIaHelper::monto($total) . ' pasan de la cuenta de ' . $nombre_anterior . ' a la de ' . $nombre_nuevo,
            ];
        }

        $datos = [
            'provider_order_id'     => (int) $compra->id,
            'compra_num'            => (int) $compra->num,
            /* El de antes, para revalidar al confirmar que nadie la cambió en el medio. */
            'provider_id_anterior'  => is_null($compra->provider_id) ? null : (int) $compra->provider_id,
            'proveedor_anterior'    => $nombre_anterior,
            'provider_id'           => is_null($proveedor_nuevo) ? (int) $proveedor->id : null,
            'proveedor'             => $nombre_nuevo,
            'proveedor_nuevo'       => is_null($proveedor_nuevo) ? null : $proveedor_nuevo['alta'],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_CAMBIO_PROVEEDOR_COMPRA,
            self::clave($compra->id),
            $datos,
            [
                'titulo'    => 'Cambiar el proveedor de una compra',
                'renglones' => $renglones,
                'aviso'     => is_null($proveedor_nuevo)
                    ? null
                    : 'No tenés ningún proveedor "' . $nombre_nuevo . '": confirmá que sea ése antes de que lo dé de alta y le pase la compra.',
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        /*
         * 🔴 UN PROVEEDOR QUE HAY QUE DAR DE ALTA NO SE CREA SOLO (revisión independiente, arreglo 7a,
         * 30/10/2026), como en la compra con factura: con el dueño en "directo" este cambio se ejecuta en
         * el acto, y un nombre mal entendido («mariano limpieza» por audio) dejaría un proveedor nuevo con
         * la deuda encima. `requiere_confirmacion` hace que la puerta de la auto-confirmación deje la
         * tarjeta esperando el sí, en todos los modos.
         */
        $extra = [];

        if (!is_null($proveedor_nuevo)) {

            $extra['proveedor_nuevo'] = $nombre_nuevo;
            $extra['requiere_confirmacion'] = true;
            $extra['nota'] = 'El proveedor "' . $nombre_nuevo . '" no existe: quedó esperando el sí de la persona para darlo de alta y pasarle la compra. '
                . 'Decile eso y preguntale si es ése; no digas que ya está cambiado.';
        }

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Compra N° ' . $compra->num . ': de ' . $nombre_anterior . ' a ' . $nombre_nuevo,
            $extra
        );
    }

    /**
     * Identidad de la carga: una corrección del cambio sobre la misma compra reemplaza la tarjeta.
     *
     * @param  int  $provider_order_id
     * @return string
     */
    public static function clave($provider_order_id)
    {
        return 'cambio_proveedor_compra:' . (int) $provider_order_id;
    }

    /**
     * Le cambia el proveedor a la compra y le pasa su movimiento de cuenta corriente al proveedor nuevo.
     * Corre adentro de la transacción de EjecutorAccionesIaHelper, con la persona autenticada.
     *
     * 🔴 TODO SE RE-VERIFICA ACÁ (entre la tarjeta y el sí pueden pasar horas): que la compra siga
     * siendo del agente, vacía, sin factura a mano y sin escaneo confirmado ni en curso, y que siga
     * teniendo el proveedor de la tarjeta (si alguien se lo cambió desde la pantalla, manda la pantalla).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, provider_id, provider_order_id}
     *
     * @throws AccionIaException  422 si el cambio ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $compra = ProviderOrder::where('user_id', $contexto->owner_id)
                                ->where('id', isset($datos['provider_order_id']) ? (int) $datos['provider_order_id'] : 0)
                                ->first();

        if (is_null($compra)) {

            throw new AccionIaException(422, 'Esa compra ya no existe. Pedímelo de nuevo.');
        }

        $anterior_id = isset($datos['provider_id_anterior']) ? (int) $datos['provider_id_anterior'] : 0;

        if ((int) $compra->provider_id !== $anterior_id) {

            throw new AccionIaException(422, 'A la Compra N° ' . $compra->num . ' ya le cambiaron el proveedor desde que te lo propuse. Mirala en Compras y, si hace falta, pedímelo de nuevo.');
        }

        $motivo = self::motivo_para_no_cambiar($contexto, $compra);

        if (!is_null($motivo)) {

            throw new AccionIaException(422, $motivo);
        }

        $es_nuevo = empty($datos['provider_id']) && !empty($datos['proveedor_nuevo']) && is_array($datos['proveedor_nuevo']);

        $creado = false;

        if ($es_nuevo) {

            list($proveedor, $creado) = PropuestaCompraConFacturaIaHelper::proveedor_nuevo($contexto, $datos);

        } else {

            $proveedor = Provider::where('user_id', $contexto->owner_id)
                                    ->where('id', isset($datos['provider_id']) ? (int) $datos['provider_id'] : 0)
                                    ->first();
        }

        if (is_null($proveedor)) {

            throw new AccionIaException(422, 'Ese proveedor ya no existe entre los tuyos. Pedímelo de nuevo.');
        }

        /*
         * Sin cuenta corriente en la moneda de la compra, set_current_acount() revienta leyendo
         * `credit_account->id` (no tiene guarda). Se dice qué pasa en vez de un error técnico.
         */
        if ($compra->generate_current_acount && !self::tiene_cuenta($proveedor, (int) ($compra->moneda_id ?: 1))) {

            throw new AccionIaException(422, 'El proveedor ' . $proveedor->name . ' no tiene cuenta corriente para esta compra. Abrilo desde Proveedores y guardalo de nuevo, y después pedímelo otra vez.');
        }

        $anterior = Provider::where('id', $anterior_id)->first();

        $nombre_anterior = is_null($anterior) ? 'sin proveedor' : (string) $anterior->name;

        /*
         * 🔴 El candado de las DOS cuentas, como ProviderOrderController::update(): el movimiento sale
         * de una y entra a la otra, y las dos cadenas de saldos se recalculan en esta transacción.
         */
        CuentaCorrienteLock::bloquear('provider', [$anterior_id, (int) $proveedor->id]);

        $compra->provider_id = (int) $proveedor->id;

        /*
         * La compra con factura hereda del proveedor si sus costos vienen con IVA (el prefill del
         * formulario, ver PropuestaCompraConFacturaIaHelper): con el proveedor nuevo, su default. La
         * compra sin artículos («Total desde facturas») no usa ese dato y queda como está.
         */
        if (!$compra->total_from_provider_order_afip_tickets) {

            $compra->precios_incluyen_iva = $proveedor->precios_incluyen_iva ? 1 : 0;
        }

        $compra->save();

        /* El total y el traspaso del movimiento de cuenta corriente: ver el 🔴 del docblock de la clase. */
        FacturaDeCompraHelper::recalcular_compra($compra->id);

        $compra->refresh();

        $total = round((float) $compra->total, 2);

        $texto = 'Le cambié el proveedor a la Compra N° ' . $compra->num . ': ahora es de ' . $proveedor->name
            . ($creado ? ' (proveedor nuevo, lo di de alta)' : '') . ', antes era de ' . $nombre_anterior . '. '
            . 'No creé otra compra: la factura sigue en esa misma.';

        if ($compra->generate_current_acount && $total > 0) {

            $texto .= ' Los ' . FormatoIaHelper::monto($total) . ' pasaron de la cuenta corriente de ' . $nombre_anterior . ' a la de ' . $proveedor->name . '.';
        }

        /* Ver el ⚠️ del docblock de la clase: el escaneo en curso no se frena, se avisa. */
        if (self::tiene_escaneo_en_curso($compra)) {

            $texto .= ' La factura todavía se está leyendo: cuando la revises en Compras, fijate que los artículos reconocidos sean los de ' . $proveedor->name . '.';
        }

        return [
            'texto'             => $texto,
            'ruta'              => [
                'name'   => 'provider',
                'params' => ['view' => 'compras'],
                'texto'  => 'Ver en Compras',
                'fecha'  => Carbon::parse($compra->created_at)->format('Y-m-d'),
            ],
            'provider_id'       => (int) $proveedor->id,
            'provider_order_id' => (int) $compra->id,
        ];
    }

    /**
     * La compra que se quiere corregir: la del número que dijo la persona o, si no vino, la ÚLTIMA que
     * creó el agente en esta conversación (es «la recién hecha» de «volvé a cargarla con…»). La
     * respuesta negativa si no hay ninguna.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $compra_num
     * @return \App\Models\ProviderOrder|array
     */
    public static function compra_a_corregir(ContextoDeCargaIa $contexto, $compra_num)
    {
        $num = (is_numeric($compra_num) && (int) $compra_num > 0) ? (int) $compra_num : 0;

        if ($num > 0) {

            $compra = ProviderOrder::where('user_id', $contexto->owner_id)->where('num', $num)->first();

            if (is_null($compra)) {

                return RespuestaDeCargaIa::error('No encontré ninguna Compra N° ' . $num . ' entre las tuyas. Fijate el número en Compras.');
            }

            return $compra;
        }

        foreach (self::acciones_que_crearon_compras($contexto, true) as $accion) {

            $compra = ProviderOrder::where('user_id', $contexto->owner_id)
                                    ->where('id', (int) $accion->resultado->provider_order_id)
                                    ->first();

            if (!is_null($compra)) {

                return $compra;
            }
        }

        return RespuestaDeCargaIa::faltan(['el número de la compra a la que le cambio el proveedor']);
    }

    /**
     * Por qué a esta compra NO se le puede cambiar el proveedor desde acá, o null si se puede. Son las
     * cuatro condiciones del docblock de la clase; el texto le dice a la persona qué hacer en cada caso.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\ProviderOrder  $compra
     * @return string|null
     */
    public static function motivo_para_no_cambiar(ContextoDeCargaIa $contexto, ProviderOrder $compra)
    {
        $creadora = self::accion_que_la_creo($contexto, $compra);

        if (is_null($creadora)) {

            return 'La Compra N° ' . $compra->num . ' no la cargué yo en las últimas ' . self::HORAS . ' horas, así que no le cambio el proveedor desde acá: '
                . 'cambiáselo desde la pantalla de la compra en Compras.';
        }

        if ($compra->articles()->count() > 0) {

            return 'La Compra N° ' . $compra->num . ' ya tiene artículos cargados: el proveedor decide sus costos y códigos, así que cambiáselo desde la pantalla de la compra en Compras.';
        }

        if (self::tiene_factura_a_mano($compra, $creadora)) {

            return 'La Compra N° ' . $compra->num . ' tiene una factura cargada a mano: cambiale el proveedor desde la pantalla de la compra en Compras.';
        }

        /*
         * 🔴 CON PAGOS IMPUTADOS NO SE CAMBIA (revisión independiente, arreglo 7b, 30/10/2026). Se eligió lo
         * más seguro: el movimiento de la compra pasaría a la cuenta del proveedor nuevo y el pago que la
         * cubría quedaría A FAVOR en la cuenta del proveedor viejo, sin que nadie lo vea. Eso se arregla a
         * mano desde la pantalla, mirando las dos cuentas.
         */
        if (self::tiene_pagos_imputados($compra)) {

            return 'La Compra N° ' . $compra->num . ' ya tiene pagos imputados en la cuenta corriente: si le cambio el proveedor, ese pago quedaría a favor '
                . 'en la cuenta del proveedor de antes. Cambiáselo desde la pantalla de la compra en Compras, mirando las dos cuentas.';
        }

        $confirmado = ProviderOrderScan::where('provider_order_id', $compra->id)
                                        ->where('resultado_gestion', 'confirmado')
                                        ->exists();

        if ($confirmado) {

            return 'El escaneo de la factura de la Compra N° ' . $compra->num . ' ya está confirmado (sus renglones se cargaron con este proveedor): cambiáselo desde Compras.';
        }

        return null;
    }

    /**
     * true si la compra tiene un escaneo todavía en curso (misma ventana que ProviderOrderScanController:
     * uno más viejo está abandonado). Ver el ⚠️ del docblock de la clase: no bloquea el cambio, lo avisa.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return bool
     */
    protected static function tiene_escaneo_en_curso(ProviderOrder $compra)
    {
        return ProviderOrderScan::where('provider_order_id', $compra->id)
                                ->whereIn('estado', ProviderOrderScanController::ESTADOS_EN_CURSO)
                                ->where('created_at', '>=', Carbon::now()->subMinutes(ProviderOrderScanController::MINUTOS_ESCANEO_EN_CURSO))
                                ->exists();
    }

    /**
     * La tarjeta confirmada que CREÓ esta compra en las últimas HORAS horas (una compra con factura o
     * una compra sin artículos del agente), o null si la compra no la creó el agente.
     *
     * Se busca en TODAS las conversaciones del dueño y no solo en esta: por WhatsApp la charla se parte
     * en hilos por tema (CONV 19 y CONV 20 de Ferre Total eran dos hilos del mismo mediodía), y la
     * compra que el agente cargó en un hilo se corrige en el siguiente.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\ProviderOrder  $compra
     * @return \App\Models\AiMessageAction|null
     */
    public static function accion_que_la_creo(ContextoDeCargaIa $contexto, ProviderOrder $compra)
    {
        foreach (self::acciones_que_crearon_compras($contexto, false) as $accion) {

            if ((int) $accion->resultado->provider_order_id === (int) $compra->id) {

                return $accion;
            }
        }

        return null;
    }

    /**
     * Las tarjetas confirmadas de las últimas HORAS horas que crearon una compra, de la más nueva a la
     * más vieja, solo las que tienen el `provider_order_id` en el resultado.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  bool  $solo_esta_conversacion
     * @return array<int, \App\Models\AiMessageAction>
     */
    protected static function acciones_que_crearon_compras(ContextoDeCargaIa $contexto, $solo_esta_conversacion)
    {
        $consulta = AiMessageAction::where('user_id', $contexto->owner_id)
                                    ->whereIn('tipo', self::TIPOS_QUE_CREAN_COMPRAS)
                                    ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                                    ->where('resuelta_at', '>=', Carbon::now()->subHours(self::HORAS))
                                    ->orderBy('id', 'DESC');

        if ($solo_esta_conversacion && !is_null($contexto->conversation)) {

            $consulta->where('ai_conversation_id', (int) $contexto->conversation->id);
        }

        $acciones = [];

        foreach ($consulta->get() as $accion) {

            if (is_object($accion->resultado) && isset($accion->resultado->provider_order_id)) {

                $acciones[] = $accion;
            }
        }

        return $acciones;
    }

    /**
     * true si la compra tiene una factura que NO cargó el agente: el número o la fecha del comprobante
     * escritos en la pantalla de la compra, o una fila de `provider_order_afip_tickets` distinta de la
     * que dejó la compra sin artículos que la creó (`resultado.provider_order_afip_ticket_id`).
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  \App\Models\AiMessageAction  $creadora
     * @return bool
     */
    protected static function tiene_factura_a_mano(ProviderOrder $compra, AiMessageAction $creadora)
    {
        if (!is_null($compra->fecha_emision_comprobante) || trim((string) $compra->numero_comprobante) !== '') {

            return true;
        }

        $del_agente = isset($creadora->resultado->provider_order_afip_ticket_id)
            ? (int) $creadora->resultado->provider_order_afip_ticket_id
            : 0;

        return ProviderOrderAfipTicket::where('provider_order_id', $compra->id)
                                        ->where('id', '!=', $del_agente)
                                        ->exists();
    }

    /**
     * true si el movimiento de cuenta corriente de la compra ya tiene algún pago imputado: está
     * `pagandose` o `pagado`, o tiene filas en `pagado_por` (la imputación de CurrentAcountHelper).
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return bool
     */
    protected static function tiene_pagos_imputados(ProviderOrder $compra)
    {
        foreach (CurrentAcount::where('provider_order_id', (int) $compra->id)->get() as $movimiento) {

            if (in_array((string) $movimiento->status, ['pagandose', 'pagado'], true)) {

                return true;
            }

            /* La pivote directa: la relación pagado_por() trae un orderBy que choca con el exists(). */
            if (DB::table('pagado_por')->where('debe_id', (int) $movimiento->id)->exists()) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si el proveedor tiene cuenta corriente en esa moneda.
     *
     * @param  \App\Models\Provider  $proveedor
     * @param  int  $moneda_id
     * @return bool
     */
    protected static function tiene_cuenta(Provider $proveedor, $moneda_id)
    {
        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', (int) $proveedor->id)
                            ->where('moneda_id', (int) $moneda_id)
                            ->exists();
    }
}
