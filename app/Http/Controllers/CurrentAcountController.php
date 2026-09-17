<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\Helpers\CurrentAcountDeletePagoHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\NotaCreditoHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfPrintCurrentAcounts;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCuotaHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountPagoAltaHelper;
use App\Http\Controllers\Pdf\AfipTicketPdf;
use App\Http\Controllers\Pdf\CurrentAcountPdf;
use App\Http\Controllers\Pdf\CurrentAcount\NewPagoPdf;
use App\Http\Controllers\Pdf\NotaCreditoPdf;
use App\Http\Controllers\Pdf\PagoPdf;
use App\Imports\CurrentAcountsImport;
use App\Models\Commissioner;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\RetencionSufrida;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class CurrentAcountController extends Controller
{

    function index($model_name, $model_id, $months_ago) {
        $months_ago = Carbon::now()->subMonths($months_ago);
        $models = CurrentAcount::whereDate('created_at', '>=', $months_ago);
        if ($model_name == 'client') {
            $models = $models->where('client_id', $model_id);
        } else {
            $models = $models->where('provider_id', $model_id);
        }
        $models = $models->with('current_acount_payment_methods')
                        ->with('pagado_por')
                        ->with('cheques')
                        ->with('sale.afip_ticket')
                        ->orderBy('created_at', 'DESC')
                        // ->get();
                        ->get()
                        ->reverse() // <- Este es el truco
                        ->values(); // <- Esto resetea los índices del array
        // $models = CurrentAcountHelper::format($models);
        return response()->json(['models' => $models], 200);
    }

    function check_saldos_y_pagos($credit_account_id) {
        CurrentAcountHelper::check_saldos_y_pagos($credit_account_id);
    }

    public function pago(Request $request) {

        // CurrentAcountHelper::eliminar_pagos_provisorios($request->credit_account_id, $request->is_provisorio);

        /*
         * 🔴 Las cajas destino se validan ACA, antes de crear absolutamente nada.
         *
         * Un pago se puede repartir entre varias cajas (tipico con la extension de ventas en
         * dolares: efectivo en dolares a la caja en dolares + efectivo en pesos a la caja en
         * pesos). Los movimientos se crean uno por uno mas abajo, en attachPaymentMethods(), y si
         * la segunda caja no tenia apertura el flujo se cortaba ahi: el primer movimiento ya
         * estaba hecho, el pago ya estaba creado, y no llegaban a correr ni el saldo ni la
         * imputacion. Quedaba un pago huerfano y una caja sin la plata (reportado el 21/8/2026).
         *
         * Validar despues no alcanza: para cuando falla, la mitad del trabajo ya esta persistida.
         * Es la misma convencion que ya usa delete() de este mismo controlador para el borrado.
         *
         * 🔴 Lo que se valida es que la caja tenga ALGUNA apertura, no que este abierta ahora. Una
         * caja cerrada con aperturas previas registra el movimiento sin problema (se cuelga de la
         * ultima) y asi funciona hoy en todos los flujos: rechazarla seria romperle el cobro a
         * cualquier comercio que cierra la caja a la noche. Ver el detalle del criterio en
         * CurrentAcountCajaHelper::cajas_sin_apertura_en_payload().
         */
        $cajas_sin_apertura = CurrentAcountCajaHelper::cajas_sin_apertura_en_payload($request->current_acount_payment_methods);

        if (count($cajas_sin_apertura)) {

            return response()->json([
                'message' => 'Las siguientes cajas nunca se abrieron: '.implode(', ', $cajas_sin_apertura).'. Hay que abrirlas para poder registrar el pago.',
            ], 422);
        }

        /*
         * El alta en sí (el create, los métodos de pago con sus movimientos de caja, el saldo, la
         * imputación contra los débitos y la cuota) vive en CurrentAcountPagoAltaHelper::registrar()
         * desde la misión asistente-ia-acciones (15/9/2026), junto con get_haber(): el asistente de
         * IA registra pagos por el MISMO camino, adentro de su propia transacción. Acá queda lo que
         * es del HTTP: la prevalidación de cajas de arriba, armar las 12 claves que el alta le leía
         * al request, la notificación y la respuesta. El payload y las respuestas de
         * `POST api/current-acount/pago` no cambiaron, y esta pantalla sigue sin transacción
         * (hallazgo 5 del informe del 21/8/2026, fuera de alcance).
         */
        $datos = [];

        foreach (CurrentAcountPagoAltaHelper::CLAVES as $clave) {

            // `$request->clave` devuelve null si no vino: es exactamente lo que leía pago().
            $datos[$clave] = $request->{$clave};
        }

        $pago = CurrentAcountPagoAltaHelper::registrar($datos);

        /*
         * Los certificados de las filas de medio de pago que son RETENCIONES. Va despues del alta
         * porque necesita el id del cobro recien creado, y fuera de
         * CurrentAcountPagoAltaHelper::registrar() a proposito: el alta es el circuito de la PLATA
         * (el haber, el saldo, la imputacion, la caja) y ya funciona sin saber nada de esto. La
         * retencion cancela deuda por su fila de `current_acount_payment_methods`, igual que
         * cualquier otro medio de pago; lo que se guarda aca es el papel.
         */
        $this->guardar_retenciones_sufridas($pago, $request->current_acount_payment_methods, $request->model_name, $request->model_id);

        $this->sendAddModelNotification($request->model_name, $request->model_id);
        Log::info('Terminando de guardar pago');
        return response()->json(['current_acount' => $pago], 201);
    }

    /**
     * Guarda un `retenciones_sufridas` por cada fila de medio de pago del cobro cuyo tipo sea
     * `retencion` (mision compras-factura-manual-alicuotas, 17/9/2026, parte C).
     *
     * 🔴 ESTE METODO NO MUEVE UN PESO Y NO TIENE QUE MOVERLO. Lo que cancela la deuda es el `amount`
     * de la fila de medio de pago, que ya sumo al haber en
     * CurrentAcountPagoAltaHelper::get_haber(): si el cliente te debe $100.000 y te retiene $2.000,
     * te paga $98.000 y la deuda se cancela por $100.000. Si alguna vez alguien siente que aca hay
     * que restar, descontar o ajustar algo, es el error clasico de este circuito y le deja al
     * cliente $2.000 de deuda que ya pago.
     *
     * 🔴 SOLO PARA COBROS A CLIENTES. Una retencion SUFRIDA la practica el cliente cuando te paga.
     * En un pago a un PROVEEDOR el agente de retencion sos vos, o sea que esa retencion es
     * PRACTICADA, y meterla en esta tabla se la restaria a tu propia posicion de IVA o de IIBB como
     * si te la hubieran hecho a vos. El medio de pago sigue funcionando igual en los dos lados (la
     * deuda se cancela entera sin que salga plata de la caja, que para un pago a proveedor tambien
     * es lo correcto); lo unico que no se guarda es el certificado.
     *
     * @param  \App\Models\CurrentAcount $pago El cobro recien creado.
     * @param  array|null $payment_methods Las filas tal como las manda el modal de cobro.
     * @param  string|null $model_name `client` o `provider`.
     * @param  int|null $model_id
     * @return int Cantidad de certificados guardados.
     */
    protected function guardar_retenciones_sufridas($pago, $payment_methods, $model_name, $model_id) {

        if ($model_name != 'client' || !is_array($payment_methods)) {

            return 0;
        }

        $guardadas = 0;

        foreach ($payment_methods as $payment_method) {

            if (!isset($payment_method['current_acount_payment_method_id'])) {

                continue;
            }

            $metodo = CurrentAcountPaymentMethod::find($payment_method['current_acount_payment_method_id']);

            if (
                is_null($metodo)
                || is_null($metodo->type)
                || $metodo->type->slug != 'retencion'
            ) {

                continue;
            }

            $importe = isset($payment_method['amount']) ? (float) $payment_method['amount'] : 0;

            if ($importe <= 0) {

                /*
                 * Una fila de retencion sin monto no cancelo nada (attach_payment_methods tampoco
                 * la adjunta), asi que no hay certificado que guardar. Se avisa por log porque del
                 * lado del usuario parece cargada.
                 */
                Log::warning('guardar_retenciones_sufridas: el cobro '.$pago->id.' trae una fila de retencion sin importe. No se guarda certificado.');

                continue;
            }

            RetencionSufrida::create([
                'user_id'                       => $pago->user_id,
                'current_acount_id'             => $pago->id,
                'client_id'                     => $model_id,
                'impuesto'                      => RetencionSufrida::normalizar_impuesto($this->dato_de_retencion($payment_method, 'impuesto')),
                'numero_certificado'            => $this->dato_de_retencion($payment_method, 'numero_certificado'),
                /*
                 * La fecha del certificado es obligatoria: es la que decide en que periodo fiscal
                 * entra la retencion. Si el papel no la trae, se usa la del cobro — el agente
                 * entrega el certificado en el momento de practicar la retencion (RG 2233, art. 8),
                 * asi que es la mejor aproximacion posible y no bloquea el cobro por un campo
                 * administrativo.
                 */
                'fecha'                         => $this->fecha_de_retencion($payment_method, $pago),
                'regimen'                       => $this->dato_de_retencion($payment_method, 'regimen'),
                'base_imponible'                => $this->numero_de_retencion($payment_method, 'base_imponible'),
                'alicuota'                      => $this->numero_de_retencion($payment_method, 'alicuota'),
                'importe'                       => $importe,
                'origen'                        => RetencionSufrida::ORIGEN_COBRO,
                'provider_order_afip_ticket_id' => null,
            ]);

            $guardadas++;
        }

        return $guardadas;
    }

    /**
     * Lee un campo del certificado de una fila de medio de pago. Las claves viajan prefijadas con
     * `retencion_` para no chocar con las del cheque (`numero`, `banco`, `fecha_emision`), que
     * comparten la misma fila del payload.
     *
     * @param  array $payment_method
     * @param  string $campo Sin el prefijo.
     * @return string|null
     */
    private function dato_de_retencion($payment_method, $campo) {

        $clave = 'retencion_'.$campo;

        if (!isset($payment_method[$clave]) || $payment_method[$clave] === '') {

            return null;
        }

        return $payment_method[$clave];
    }

    /**
     * Idem, para los campos numericos: un string vacio tiene que quedar NULL y no 0, que se leeria
     * como "la base imponible fue cero".
     *
     * 🔴 LO MISMO VALE PARA UN TEXTO QUE NO ES UN NUMERO. Los dos campos son inputs de texto libre
     * en la SPA (no `type=number`, porque la coma y el punto decimal los escribe cada comercio como
     * puede), y `(float)'abc'` en PHP da 0 sin avisar. Un 0 guardado no se distingue de un cero
     * declarado, y en `base_imponible` eso es "la retencion se practico sobre una base de cero".
     * Lo que no es un numero es un campo NO CARGADO.
     *
     * @param  array $payment_method
     * @param  string $campo
     * @return float|null
     */
    private function numero_de_retencion($payment_method, $campo) {

        $valor = $this->dato_de_retencion($payment_method, $campo);

        if (is_null($valor)) {

            return null;
        }

        $valor = self::normalizar_numero_escrito_a_mano($valor);

        if (!is_numeric($valor)) {

            return null;
        }

        return (float) $valor;
    }

    /**
     * Pasa un numero escrito a mano al formato que entiende PHP.
     *
     * El comercio copia estos dos campos del certificado de papel, y en Argentina eso se escribe
     * "2.500,50": punto de miles y coma decimal. `is_numeric('2.500,50')` da false, asi que sin
     * esto el dato se descartaba en silencio — el comercio escribia la base imponible, la veia en
     * pantalla, y se guardaba NULL.
     *
     * Solo se toca el separador: lo que despues de esto sigue sin ser un numero (un "n/a", un
     * "-") lo descarta igual el `is_numeric` de arriba, que es lo correcto.
     *
     * @param  string $valor
     * @return string
     */
    private static function normalizar_numero_escrito_a_mano($valor) {

        $valor = trim((string) $valor);

        // Con coma Y punto, el ultimo es el decimal y el otro es separador de miles ("2.500,50" o
        // "2,500.50"). Con una sola coma, es el decimal ("2500,50").
        if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {

            $valor = (strrpos($valor, ',') > strrpos($valor, '.'))
                        ? str_replace(['.', ','], ['', '.'], $valor)
                        : str_replace(',', '', $valor);

        } else if (strpos($valor, ',') !== false) {

            $valor = str_replace(',', '.', $valor);
        }

        return $valor;
    }

    /**
     * Fecha del certificado, con la del cobro como respaldo (ver el comentario del create).
     *
     * @param  array $payment_method
     * @param  \App\Models\CurrentAcount $pago
     * @return string Fecha en formato Y-m-d.
     */
    private function fecha_de_retencion($payment_method, $pago) {

        $fecha = $this->dato_de_retencion($payment_method, 'fecha');

        if (!is_null($fecha)) {

            return Carbon::parse($fecha)->format('Y-m-d');
        }

        if (!is_null($pago->created_at)) {

            return Carbon::parse($pago->created_at)->format('Y-m-d');
        }

        return Carbon::now()->format('Y-m-d');
    }

    /**
     * Nota de crédito de MONTO LIBRE sobre la cuenta corriente del cliente.
     *
     * 🔴 ACÁ NO SE PASA `sale_id`, Y NO ES UN OLVIDO. El request de la SPA
     * (`components/common/current-acounts/NotaCredito.vue`) manda monto, descripción y
     * modelo, y nada más: esta NC es un ajuste a la CUENTA del cliente y puede no
     * corresponder a ninguna venta. Inventarle una acá —la más vieja sin saldar, por
     * ejemplo— sería escribir en `current_acounts.sale_id` un dato que nadie declaró, y ese
     * campo es el que después usan los comprobantes y el módulo de puntos para decir "esta NC
     * es de esta venta".
     *
     * La consecuencia para los puntos está resuelta del otro lado y hay que dejarla dicha,
     * porque es la parte que no se ve desde acá: esta NC entra a la cola FIFO y puede saldar
     * el débito de una venta. Que un débito quede saldado por una NC NO es un cobro, y quien
     * lo distingue es `PuntosAcumulacionHelper::corresponde_acumular()` mirando `pagado_por`
     * y el `status` de los haberes; cuánto de la venta anula, lo decide
     * `PuntosBaseHelper::factor_nota_credito()`. El enganche que vuelve a preguntar cuelga de
     * `CurrentAcountPagoHelper::init()`, por el que esta NC pasa sí o sí.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function notaCredito(Request $request) {
        $nota_credito = CurrentAcountHelper::notaCredito($request->credit_account_id, $request->form['nota_credito'], $request->form['description'], $request->model_name, $request->model_id);
        CurrentAcountHelper::checkCurrentAcountSaldo($request->credit_account_id);
        $this->sendAddModelNotification($request->model_name, $request->model_id);
        return response()->json(['current_acount' => $nota_credito], 201);
    }


    public function notaDebito(Request $request) {
        $nota_debito = CurrentAcount::create([
            'detalle'           => 'Nota de debito',
            'description'       => $request->description,
            'debe'              => $request->debe,
            'status'            => 'sin_pagar',
            'client_id'         => $request->model_name == 'client' ? $request->model_id : null,
            'provider_id'       => $request->model_name == 'provider' ? $request->model_id : null,
            'user_id'           => $this->userId(),
            'credit_account_id' => $request->credit_account_id,
        ]);
        $nota_debito->saldo = CurrentAcountHelper::getSaldo($request->credit_account_id, $nota_debito) + $request->debe;
        $nota_debito->save();

        CurrentAcountHelper::checkCurrentAcountSaldo($request->credit_account_id);
        CurrentAcountHelper::update_credit_account_saldo($request->credit_account_id);

        $this->sendAddModelNotification($request->model_name, $request->model_id);
        return response()->json(['current_acount' => $nota_debito], 201);
    }

    function updateDebe(Request $request) {
        $current_acount = CurrentAcount::find($request->id);
        $current_acount->debe = $request->debe;
        $current_acount->save();
        return response(null, 200);
        // $client_controller = new ClientController();
        // $client_controller->checkSaldoss($current_acount->client_id);
    }

    function saldoInicial(Request $request) {
        $current_acount = CurrentAcount::create([
            'detalle'       => 'Saldo inicial',
            'status'        => $request->is_for_debe ? 'sin_pagar' : 'pago_from_client',
            'client_id'     => $request->model_name == 'client' ? $request->model_id : null,
            'provider_id'   => $request->model_name == 'provider' ? $request->model_id : null,
            'debe'          => $request->is_for_debe ? $request->saldo_inicial : null,
            'haber'         => !$request->is_for_debe ? $request->saldo_inicial : null,
            'saldo'         => $request->is_for_debe ? $request->saldo_inicial : -$request->saldo_inicial,
        ]);
        CurrentAcountHelper::updateModelSaldo($current_acount, $request->model_name, $request->model_id);
        return response()->json(['current_acount' => $current_acount], 201);
    }

    function updateSaldo($client_id, $current_acounts) {
        foreach ($current_acounts as $current_acount) {
            if ($this->esUnPago($current_acount)) {
                $current_acount->saldo = CurrentAcountHelper::getSaldo($client_id, $current_acount) - $current_acount->haber;
            } else {
                $current_acount->saldo = CurrentAcountHelper::getSaldo($client_id, $current_acount) + $current_acount->debe;
            }
            $current_acount->save();
        }
    }

    function esUnPago($current_acount) {
        return $current_acount->status == 'pago_from_client' || $current_acount->status == 'nota_credito';
    }

    function import(Request $request, $client_id) {
        Excel::import(new CurrentAcountsImport($client_id), $request->file('current_acounts'));
        return response(null, 200);
    }

    function delete(Request $request, $model_name, $id) {
        $current_acount = CurrentAcount::find($id);

        /** Solo aplica a pagos en cuenta corriente con impacto en caja (no a notas de crédito en este alcance). */
        $compensar_caja = $request->boolean('compensar_caja');
        /** Helper para validar apertura de cajas y emitir movimientos compensatorios consistentes con ventas/gastos. */
        $helper_caja_compensacion = new DeleteCajaCompensacionHelper();
        $metodos_para_compensacion = null;

        if ($compensar_caja && $current_acount->status === 'pago_from_client') {
            $current_acount->loadMissing('current_acount_payment_methods');
            $cajas_cerradas = $helper_caja_compensacion->verificar_cajas_abiertas($current_acount->current_acount_payment_methods);
            if (count($cajas_cerradas)) {
                return response()->json([
                    'message' => 'Las siguientes cajas están cerradas: '.implode(', ', $cajas_cerradas).'. Debe abrirlas para poder eliminar el pago y compensar caja.',
                ], 422);
            }
            $metodos_para_compensacion = $current_acount->current_acount_payment_methods;
        }

        if ($current_acount->status == 'pago_from_client' || $current_acount->status == 'nota_credito') {

            // $ct = new CurrentAcountDeletePagoHelper($model_name, $current_acount);
            // $ct->deletePago();
            if ($current_acount->status == 'nota_credito') {
                NotaCreditoHelper::resetUnidadesDevueltas($current_acount);
            }
            // $current_acount->pagando_a()->detach();
            CurrentAcountHelper::updateSellerCommissionsStatus($current_acount);

        } else {
            // CurrentAcountDeleteNotaDebitoHelper::deleteNotaDebito($current_acount, $model_name);
        }

        $credit_account_id = $current_acount->credit_account_id;

        /** Texto de referencia para el movimiento de caja (se conserva antes del delete). */
        $notas_compensacion = 'Eliminación de pago en cuenta corriente';
        if (! is_null($current_acount->num_receipt)) {
            $notas_compensacion .= ' N° '.$current_acount->num_receipt;
        }

        $current_acount->delete();

        if ($compensar_caja && ! is_null($metodos_para_compensacion) && $metodos_para_compensacion->count()) {
            $helper_caja_compensacion->crear_movimientos_compensacion(
                $metodos_para_compensacion,
                DeleteCajaCompensacionHelper::MODEL_TYPE_CURRENT_ACOUNT,
                $model_name,
                $notas_compensacion,
                $current_acount->id
            );
        }
        
        CurrentAcountHelper::checkSaldos($credit_account_id);
        
        CurrentAcountHelper::checkPagos($credit_account_id, true);

        // $this->sendAddModelNotification($model_name, $model_id, false);
    }

    function pdfFromModel($current_acount_id, $cantidad_movimientos = 0, $type = 'simple') {
        
        // Si es > 0 son todos los movimientos de una credit_accounts
        if ($cantidad_movimientos > 0) {
            $models = CurrentAcount::where('credit_account_id', $current_acount_id)
                                    ->orderBy('created_at', 'DESC')
                                    ->take($cantidad_movimientos);
        } else {
            $models = CurrentAcount::where('id', $current_acount_id)
                                    ->orderBy('created_at', 'DESC');
        }

        if ($type == 'details') {
            $models = $models->with('articles', 'sale.articles');
        }

        $models = $models->get();

        $models = $models->reverse()->values();

        $user_id = null;
        if (count($models) >= 1) {
            $user_id = $models[0]->user_id;
            
            if ($user_id) {
                
                $user = User::find($user_id);

                if ($user->cc_ultimas_arriba) {
                    $models = $models->reverse()->values();
                }
            }

        }

        $credit_account = CreditAccount::find($models[0]->credit_account_id);

        if (is_null($credit_account)) {
            abort(404);
        }

        new CurrentAcountPdf($credit_account, $models, $type);
    }

    // function pdfFromModel($credit_account_id, $cantidad_movimientos) {
    //     $credit_account = CreditAccount::find($credit_account_id);
    //     $models = CurrentAcount::where('credit_account_id', $credit_account_id)
    //                             ->orderBy('created_at', 'ASC')
    //                             ->take($cantidad_movimientos)
    //                             ->get();
                                
    //     new CurrentAcountPdf($credit_account, $models);
    // }


    // Se usa para un pago o nota de credito
    function pdf($id) {

        $model = CurrentAcount::find($id);

        if (is_null($model)) {
            abort(404);
        }

        if ($model->status == 'pago_from_client') {
            if (!is_null($model->client_id)) {
                $model_name = 'client';
            } else {
                $model_name = 'provider';
            }
            $pdf = new NewPagoPdf($model, $model_name);

        } else if ($model->status == 'nota_credito') {
           
            if (!is_null($model->afip_ticket)) {
                $pdf = new AfipTicketPdf($model);
            } else {
                $pdf = new NotaCreditoPdf($model);
                $pdf->printCurrentAcounts();
            }
        }
    }
}
