<?php

namespace App\Http\Controllers;

use App\Exports\ChequesFilteredExport;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountPagoAltaHelper;
use App\Models\Caja;
use App\Models\Cheque;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\CreditAccount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChequeController extends Controller
{
    /**
     * El 422 de un `cheque_id` del cuerpo que no es de esta cuenta (cobrar, pagar, rechazar). Es el
     * mismo para un cheque ajeno y para uno que no existe: no se confirma qué hay del otro lado.
     */
    const MENSAJE_CHEQUE_AJENO = 'El cheque elegido no existe o no es de tu cuenta.';

    /**
     * El 404 de un cheque de la ruta (destroy) que no es de esta cuenta, no existe o no es un id: un
     * mensaje de comerciante y el mismo cuerpo para los tres.
     */
    const MENSAJE_CHEQUE_NO_ENCONTRADO = 'El cheque no existe o no es de tu cuenta.';

    /** El 422 de un `caja_id` del cuerpo que no es de esta cuenta (cobrar, pagar). */
    const MENSAJE_CAJA_AJENA = 'La caja elegida no existe o no es de tu cuenta.';

    /**
     * El 422 de un `provider_id` del cuerpo que no es de esta cuenta (endosar). Es el texto con el
     * que corta ChequeHelper::endosar() cuando el destino del endoso no es de esta cuenta: uno solo.
     */
    const MENSAJE_PROVEEDOR_AJENO = ChequeHelper::MENSAJE_PROVEEDOR_AJENO;

    /**
     * Descarga un Excel con los cheques indicados por ID (los mismos que muestra el front al filtrar).
     *
     * @param \Illuminate\Http\Request $request Query `cheque_ids` (ids separados por guión, ej. 12-45-88).
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function excel_export(Request $request)
    {
        $cheques = $this->get_cheques_for_excel_export($request);

        return Excel::download(
            new ChequesFilteredExport($cheques),
            'cheques_'.date_format(Carbon::now(), 'd-m-y').'.xlsx'
        );
    }

    /**
     * Carga cheques por IDs enviados desde la SPA, respetando owner y orden del listado.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Support\Collection
     */
    protected function get_cheques_for_excel_export(Request $request)
    {
        if (!$request->has('cheque_ids')) {
            return collect();
        }

        $raw_ids = explode('-', (string) $request->query('cheque_ids'));
        $ids = [];

        foreach ($raw_ids as $raw_id) {
            // LA lectura de ids (ChequeHelper::id_del_pedido()): '12abc' o '12.5' no son el 12.
            $id = ChequeHelper::id_del_pedido(trim($raw_id));
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!count($ids)) {
            return collect();
        }

        $cheques_by_id = Cheque::where('user_id', $this->userId())
            ->whereIn('id', $ids)
            ->withAll()
            ->get()
            ->keyBy('id');

        $ordered = collect();

        foreach ($ids as $id) {
            if (isset($cheques_by_id[$id])) {
                $ordered->push($cheques_by_id[$id]);
            }
        }

        return $ordered;
    }

    function index() {
        $hoy = Carbon::today();

        $diasProntoAVencer = 3; // por defecto, 3 días
        // $diasProntoAVencer = $request->input('dias_pronto_a_vencer', 3); // por defecto, 3 días

        $cheques = Cheque::where('user_id', $this->userId())
                        ->withAll()
                        ->orderBy('id', 'DESC')
                        ->get();

        $agrupados = [
            'recibido' => [
                'pendientes' => [],
                'disponibles_para_cobrar' => [],
                'pronto_a_vencerse' => [],
                'vencidos' => [],
                'cobrados' => [],
                'rechazados' => [],
                'endosados' => [],
            ],
            'emitido' => [
                'pendientes' => [],
                'disponibles_para_cobrar' => [],
                'pronto_a_vencerse' => [],
                'vencidos' => [],
                'cobrados' => [],
                'rechazados' => [],
            ]
        ];

        foreach ($cheques as $cheque) {
            // Si es recibido y fue endosado (a un proveedor O en un gasto: la definición es una
            // sola y vive en ChequeHelper, no acá).
            //
            // 🔴 Este chequeo va ANTES de la marca manual (cobrado/rechazado) a propósito: el endoso
            // manda sobre `estado_manual` (misión cheques-solapa-endosados, 2/10/2026). El módulo
            // tiene una solapa de primer nivel "Endosado" que tiene que mostrar TODOS los cheques
            // que salieron de cartera, y un recibido endosado que además tuviera la marca manual
            // (ninguna pantalla lo deja hacer, pero la API no lo impide) caía en Cobrados o
            // Rechazados y desaparecía de esa solapa. Es el mismo criterio de
            // ChequeHelper::sin_endosar(): lo que ya no está en cartera no es un cheque "cobrable".
            // Solo vale para `recibido`: la copia emitida que nace del endoso sigue su propio ciclo
            // en Emitido (el proveedor la cobra).
            if ($cheque->tipo === 'recibido' && !ChequeHelper::en_cartera($cheque)) {
                $agrupados['recibido']['endosados'][] = $cheque;
                continue;
            }

            // Si está marcado manualmente, va a estado final
            if ($cheque->estado_manual === 'cobrado') {
                $agrupados[$cheque->tipo]['cobrados'][] = $cheque;
                continue;
            }

            if ($cheque->estado_manual === 'rechazado') {
                $agrupados[$cheque->tipo]['rechazados'][] = $cheque;
                continue;
            }

            // Cálculo de estado dinámico (fecha de cobro = fecha_pago + 30 días).
            // Importante: el último día hábil del plazo debe incluirse en la ventana de cobro;
            // si se usa solo lt(fechaVencimiento), el día exacto de vencimiento no entra en ninguna
            // rama y el cheque desaparece del listado agrupado (off-by-one).
            $fechaPago = Carbon::parse($cheque->fecha_pago);
            $fechaVencimiento = $fechaPago->copy()->addDays(30);
            $diasHastaVencimiento = $hoy->diffInDays($fechaVencimiento, false);

            if ($hoy->lt($fechaPago)) {
                $agrupados[$cheque->tipo]['pendientes'][] = $cheque;
            } elseif ($hoy->gte($fechaPago) && $hoy->lte($fechaVencimiento)) {
                if ($diasHastaVencimiento <= $diasProntoAVencer) {
                    $agrupados[$cheque->tipo]['pronto_a_vencerse'][] = $cheque;
                } else {
                    $agrupados[$cheque->tipo]['disponibles_para_cobrar'][] = $cheque;
                }
            } elseif ($hoy->gt($fechaVencimiento)) {
                $agrupados[$cheque->tipo]['vencidos'][] = $cheque;
            }
        }
                            
        return response()->json(['models' => $agrupados], 200);
    }

    /**
     * Marca como pagado (`estado_manual = cobrado`) un cheque EMITIDO y, si viene una caja,
     * registra el egreso en ella.
     *
     * @param  \Illuminate\Http\Request  $request  {cheque_id, caja_id}, como lo manda PagarCheque.vue.
     * @return \Illuminate\Http\JsonResponse  200 con el cheque; 422 si el cheque o la caja no son de
     *                                        esta cuenta (o no existen), sin escribir nada.
     */
    function pagar(Request $request) {

        $cheque = $this->cheque_del_dueno($request->cheque_id);

        if (is_null($cheque)) {

            return response()->json(['message' => self::MENSAJE_CHEQUE_AJENO], 422);
        }

        // La caja se valida ANTES de marcar el cheque: hasta el 3/10/2026 el cheque quedaba pagado
        // aunque la caja no fuera de esta cuenta (y el egreso salía de la caja de otro comercio).
        $caja_id = $this->caja_id_del_dueno($request->caja_id);

        if (is_null($caja_id)) {

            return response()->json(['message' => self::MENSAJE_CAJA_AJENA], 422);
        }

        $cheque->estado_manual = 'cobrado';
        $cheque->cobrado_en = Carbon::now();
        $cheque->cobrado_por_id = $this->userId(false);
        $cheque->save();

        if ($caja_id > 0) {
            CurrentAcountCajaHelper::guardar_pago($cheque->amount, $caja_id, 'provider', $cheque->current_acount, 'Pago cheque N° '.$cheque->numero);
        }

        return response()->json(['model' => $cheque], 200);
    }

    /**
     * Marca como cobrado un cheque RECIBIDO y, si viene una caja, registra el ingreso en ella.
     *
     * @param  \Illuminate\Http\Request  $request  {cheque_id, caja_id}, como lo manda CobrarCheque.vue.
     * @return \Illuminate\Http\JsonResponse  200 con el cheque; 422 si el cheque o la caja no son de
     *                                        esta cuenta (o no existen), sin escribir nada.
     */
    function cobrar(Request $request) {

        $cheque = $this->cheque_del_dueno($request->cheque_id);

        if (is_null($cheque)) {

            return response()->json(['message' => self::MENSAJE_CHEQUE_AJENO], 422);
        }

        // La caja se valida ANTES de marcar el cheque: hasta el 3/10/2026 el cheque quedaba cobrado
        // aunque la caja no fuera de esta cuenta (y el ingreso entraba en la caja de otro comercio).
        $caja_id = $this->caja_id_del_dueno($request->caja_id);

        if (is_null($caja_id)) {

            return response()->json(['message' => self::MENSAJE_CAJA_AJENA], 422);
        }

        $cheque->estado_manual = 'cobrado';
        $cheque->cobrado_en = Carbon::now();
        $cheque->cobrado_por_id = $this->userId(false);
        $cheque->save();

        if ($caja_id > 0) {
            CurrentAcountCajaHelper::guardar_pago($cheque->amount, $caja_id, 'client', $cheque->current_acount, 'Cobro cheque N° '.$cheque->numero);
        }

        return response()->json(['model' => $cheque], 200);
    }

    /**
     * Marca un cheque como rechazado y guarda el motivo del rechazo.
     *
     * Hasta la misión cheque-motivo-rechazo (9/10/2026) el motivo se perdía SIEMPRE: el modal lo
     * manda como `notas`, acá se leía `rechazado_observaciones` (que nunca llegaba) y la columna era
     * un entero. Ahora se aceptan las dos claves (ChequeHelper::motivo_de_rechazo_del_pedido(): si
     * vienen las dos con texto, gana `rechazado_observaciones`).
     *
     * 🔴 Compatibilidad: el SPA sigue mandando `notas` a propósito (la API vieja la ignora sin
     * romper), y esta API no escribe el motivo mientras la columna siga siendo INT —la ventana de un
     * deploy entre subir la API y migrar—: rechaza igual, sin motivo, y deja un warning
     * (ChequeHelper::asignar_motivo_de_rechazo()).
     *
     * @param  \Illuminate\Http\Request  $request  {cheque_id, rechazado_observaciones | notas}.
     *                                             RechazarCheque.vue manda {cheque_id, notas}.
     * @return \Illuminate\Http\JsonResponse  200 con el cheque. 422 sin escribir nada si el cheque no
     *                                        es de esta cuenta (o no existe), o si el motivo no es un
     *                                        texto o supera los ChequeHelper::MOTIVO_DE_RECHAZO_MAX
     *                                        caracteres.
     */
    function rechazar(Request $request) {

        $cheque = $this->cheque_del_dueno($request->cheque_id);

        if (is_null($cheque)) {

            return response()->json(['message' => self::MENSAJE_CHEQUE_AJENO], 422);
        }

        // El motivo se valida ANTES de marcar nada: un motivo inválido no deja el cheque rechazado.
        $pedido = ChequeHelper::motivo_de_rechazo_del_pedido($request);

        if (!is_null($pedido['error'])) {

            return response()->json(['message' => $pedido['error']], 422);
        }

        $cheque->estado_manual = 'rechazado';
        $cheque->rechazado_en = Carbon::now();
        $cheque->rechazado_por_id = $this->userId(false);

        ChequeHelper::asignar_motivo_de_rechazo($cheque, $pedido['motivo']);

        $cheque->save();

        return response()->json(['model' => $cheque], 200);
    }

    /**
     * Los cheques recibidos que hoy se pueden endosar, para el select de la fila de pago a
     * proveedor y de la fila de gasto (misión cheques-endoso-y-bancos, 21/9/2026).
     *
     * @return \Illuminate\Http\JsonResponse  {models: Cheque[] withAll}
     */
    function disponibles_para_endosar() {

        return response()->json(['models' => ChequeHelper::disponibles_para_endosar($this->userId())], 200);
    }

    /**
     * El botón Endosar del módulo de cheques: registra un pago al proveedor con una fila de tipo
     * cheque que ELIGE el recibido (`cheque_id`), y el endoso en sí lo hace ChequeHelper por el
     * mismo camino que el pago de cuenta corriente y el gasto. Hasta el 21/9/2026 este método
     * marcaba el recibido a mano antes de armar el pago, y era el único de los tres caminos que lo
     * hacía así.
     *
     * Responde 422 si el cheque ya no se puede endosar, y también si el proveedor no tiene cuenta
     * corriente en la moneda del cheque: antes en ese caso no se creaba nada y se devolvía 200 con
     * el cheque sin marcar, o sea un endoso que "salió bien" sin registrar nada.
     *
     * Y desde el 3/10/2026, 422 si el proveedor no es de esta cuenta (o no existe): hasta entonces un
     * endoso propio registraba el pago en la cuenta corriente del proveedor de otro comercio.
     */
    function endosar(Request $request) {

        // El mismo resolvedor que cobrar, pagar, rechazar y destroy: la lectura de `cheque_id` es la
        // de la fila de pago (un '12abc' es "sin cheque", no el 12) y el cheque tiene que ser del
        // dueño. El mensaje es el propio del endoso, el de siempre.
        $cheque = $this->cheque_del_dueno($request->cheque_id);

        if (is_null($cheque)) {

            return response()->json(['message' => 'El cheque elegido para endosar no existe o no es de tu cuenta.'], 422);
        }

        $problemas = ChequeHelper::problemas_de_endoso_en_payload([
            [
                'cheque_id'                        => $cheque->id,
                'current_acount_payment_method_id' => $this->metodo_de_pago_cheque_id(),
                'amount'                           => $cheque->amount,
                'caja_id'                          => 0,
            ],
        ], $this->userId());

        if (count($problemas)) {

            return response()->json(['message' => implode('. ', $problemas).'.'], 422);
        }

        // LA lectura de ids (ChequeHelper::id_del_pedido()): true, [5] o '5abc' son "sin proveedor"
        // y caen en el 422 de siempre. Hasta el 3/10/2026 era un (int) pelado: true y [5] eran el
        // proveedor 1, y '5abc' el 5.
        $provider_id = ChequeHelper::id_del_pedido($request->provider_id);

        if ($provider_id <= 0) {

            return response()->json(['message' => 'Elegí el proveedor al que le endosás el cheque.'], 422);
        }

        // El proveedor tiene que ser del dueño ANTES de buscar su cuenta corriente: ni
        // get_provider_credit_account() ni CurrentAcountPagoAltaHelper::registrar() lo miran.
        if (is_null($this->proveedor_del_dueno($provider_id))) {

            return response()->json(['message' => self::MENSAJE_PROVEEDOR_AJENO], 422);
        }

        $credit_account = $this->get_provider_credit_account($cheque, $provider_id);

        if (is_null($credit_account)) {

            return response()->json(['message' => 'El proveedor no tiene cuenta corriente en la moneda del cheque N° '.$cheque->numero.', así que el endoso no se puede registrar.'], 422);
        }

        $this->crear_provider_current_acount($cheque, $provider_id, $credit_account);

        return response()->json(['model' => $this->fullModel('Cheque', $cheque->id)], 200);
    }

    /**
     * El pago al proveedor que deja registrado el endoso, por CurrentAcountPagoAltaHelper::registrar():
     * el MISMO alta que `POST current-acount/pago` y que el asistente. La fila de método de pago es la
     * que armaría la pantalla de pago a proveedor eligiendo "Endosar un cheque recibido": método
     * Cheque, `cheque_id` del origen y su monto. attachPaymentMethods → attach_payment_methods →
     * ChequeHelper::crear_cheque ve el `cheque_id` y endosa.
     *
     * Hasta el 21/9/2026 este método armaba el pago a mano y terminaba con
     * `$credit_account->saldo = $pago->saldo` SIN save(): el botón nunca actualizó el saldo de la
     * cuenta corriente del proveedor. Por registrar() pasa por update_credit_account_saldo() como
     * cualquier otro pago (lo fija el test de paridad 4_Endosar_desde_el_modulo_Test).
     *
     * @param  \App\Models\Cheque  $cheque
     * @param  int  $provider_id
     * @param  \App\Models\CreditAccount  $credit_account
     * @return \App\Models\CurrentAcount
     */
    function crear_provider_current_acount($cheque, $provider_id, $credit_account) {

        $payment_methods = [
            [
                'current_acount_payment_method_id' => $this->metodo_de_pago_cheque_id(),
                'amount'                           => $cheque->amount,
                'cheque_id'                        => $cheque->id,
                'cheque_banco_id'                  => $cheque->cheque_banco_id,
                'caja_id'                          => 0,

                'numero'                           => $cheque->numero,
                'banco'                            => $cheque->banco,
                'fecha_emision'                    => $cheque->fecha_emision,
                'fecha_pago'                       => $cheque->fecha_pago,
                'es_echeq'                         => $cheque->es_echeq,
            ],
        ];

        // Adentro de una transacción por lo mismo que CurrentAcountController::pago(): si el
        // cheque lo endosó otra request en el medio, el endoso corta y el pago no queda huérfano.
        return DB::transaction(function () use ($credit_account, $provider_id, $payment_methods, $cheque) {

            return CurrentAcountPagoAltaHelper::registrar([
                'credit_account_id'              => $credit_account->id,
                'model_name'                     => 'provider',
                'model_id'                       => $provider_id,
                'current_acount_payment_methods' => $payment_methods,
                'haber'                          => $cheque->amount,
                'description'                    => null,
                'numero_orden_de_compra'         => null,
                'is_provisorio'                  => 0,
                'current_date'                   => 1,
                'created_at'                     => null,
                'to_pay'                         => null,
                'payment_plan_cuota'             => null,
            ]);
        });
    }

    /**
     * El id del método de pago de tipo cheque del catálogo (el 1, "Cheque", sembrado fijo), resuelto
     * por su tipo y no escrito a mano.
     *
     * @return int
     */
    protected function metodo_de_pago_cheque_id() {

        $metodo = CurrentAcountPaymentMethod::whereHas('type', function ($q) {
            $q->where('slug', 'cheque');
        })->orderBy('id')->first();

        return !is_null($metodo) ? (int) $metodo->id : 1;
    }

    /**
     * La cuenta corriente del proveedor en la moneda del cheque. La moneda sale de la cuenta
     * corriente del cobro en el que entró el cheque; si el cheque no viene de un cobro (una venta,
     * un cheque viejo sin `current_acount`), se toma pesos.
     *
     * @param  \App\Models\Cheque  $cheque
     * @param  int  $provider_id
     * @return \App\Models\CreditAccount|null
     */
    function get_provider_credit_account($cheque, $provider_id) {

        $moneda_id = 1;

        if (!is_null($cheque->current_acount) && !is_null($cheque->current_acount->credit_account) && !is_null($cheque->current_acount->credit_account->moneda_id)) {

            $moneda_id = $cheque->current_acount->credit_account->moneda_id;
        }

        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', $provider_id)
                            ->where('moneda_id', $moneda_id)
                            ->first();
    }

    /**
     * La edición ACOTADA de un cheque (misión cheque-edicion-acotada, 8/10/2026): solo se pueden
     * cambiar el número, el banco, las notas, la fecha de emisión y la fecha de pago. Todo lo demás
     * (tipo, cliente, proveedor, monto, cuenta corriente, caja, estado, endoso...) mueve plata o
     * cuentas, y este endpoint ni lo mira: se ignora en silencio, porque la SPA manda el modelo
     * entero en cada guardado y rechazar por "campo de más" rompería el guardado legítimo.
     *
     * 🔴 El pedido NO se vuelca al modelo: se leen SUS cinco claves por nombre
     * (ChequeHelper::CAMPOS_EDITABLES) y nada más. Nunca `$request->all()`, `fill()` ni
     * `update($request->all())` acá: sería abrirle la puerta a `client_id`, `amount`, `user_id`...
     * La lógica (reglas del banco, fechas, replicación al par endosado) vive en
     * ChequeHelper::actualizar_datos().
     *
     * @param  \Illuminate\Http\Request  $request  El modelo del cheque; solo cuentan las claves
     *                                             `numero`, `cheque_banco_id`, `notes`,
     *                                             `fecha_emision` y `fecha_pago`.
     * @param  string  $id  El id de la ruta.
     * @return \Illuminate\Http\JsonResponse  200 `{model}` con el cheque ya actualizado; 404 si el
     *                                        cheque no es de esta cuenta, no existe o no es un id
     *                                        (el mismo cuerpo que destroy); 422 `{message}` si el
     *                                        banco no es de esta cuenta o una fecha es inválida,
     *                                        sin escribir nada.
     */
    function update(Request $request, $id) {

        $cheque = $this->cheque_del_dueno($id);

        if (is_null($cheque)) {

            return response()->json(['message' => self::MENSAJE_CHEQUE_NO_ENCONTRADO], 404);
        }

        // Las cinco claves por nombre: `only()` trae solo las que vienen (una ausente no se toca,
        // una null sí viene y significa "vaciar").
        $problemas = ChequeHelper::actualizar_datos($cheque, $request->only(ChequeHelper::CAMPOS_EDITABLES), $this->userId());

        if (count($problemas)) {

            return response()->json(['message' => implode(' ', $problemas)], 422);
        }

        return response()->json(['model' => $this->fullModel('Cheque', $cheque->id)], 200);
    }

    /**
     * Borra un cheque del dueño.
     *
     * Hasta el 3/10/2026 era `Cheque::find($id)->delete()`: borraba el cheque de cualquier comercio
     * y, con un id que no existía, reventaba en 500 (delete() sobre null) y se reportaba como error.
     * Ahora un id ajeno, inexistente o que no es un id es un 404 con un mensaje de comerciante y el
     * MISMO cuerpo para los tres, igual que los bancos.
     *
     * Es un JsonResponse y no un firstOrFail() ni un abort(): el 404 de Laravel traía su mensaje
     * técnico ("No query results for model [App\Models\Cheque]."), que la SPA muestra tal cual en
     * un aviso, y el ejecutor del asistente (EjecutorAccionDePantallaIaHelper) traduce un
     * JsonResponse con estado >= 400 a un 422 con este mensaje.
     *
     * @param  string  $id  El id de la ruta.
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    function destroy($id) {
        $model = $this->cheque_del_dueno($id);

        if (is_null($model)) {

            return response()->json(['message' => self::MENSAJE_CHEQUE_NO_ENCONTRADO], 404);
        }

        /*
         * Si es la copia emitida de un endoso que sigue pendiente, su recibido vuelve a la cartera en
         * la misma transacción (misión cheque-endoso-deshacer-al-borrar-pago, 9/10/2026): hasta
         * entonces quedaba endosado para siempre, fuera de la cartera y sin copia. Ver
         * ChequeHelper::devolver_a_la_cartera_el_origen_de().
         */
        $user_id = $this->userId();

        DB::transaction(function () use ($model, $user_id) {

            // Releída con la fila bloqueada: si en el medio la marcaron pagada o rechazada, eso manda.
            $copia = Cheque::where('id', $model->id)->lockForUpdate()->first();

            if (is_null($copia)) {

                return;
            }

            ChequeHelper::devolver_a_la_cartera_el_origen_de($copia, $user_id);

            $copia->delete();
        });

        return response(null, 200);
    }

    /**
     * El cheque que nombra el pedido —en el cuerpo o en la ruta— si es del dueño de la sesión, o
     * null si es de otra cuenta, no existe o lo que llegó no es un id. Es EL resolvedor de los ids
     * de cheque de este controller: cobrar, pagar, rechazar y endosar contestan 422 si da null, y
     * destroy 404.
     *
     * 🔴 No volver a un `Cheque::find($request->cheque_id)` pelado: un id ajeno se contesta igual
     * que uno inexistente; en una base compartida los ids son correlativos entre comercios, así que
     * el cheque de otro comercio está a un "+1" de distancia. Hasta el 3/10/2026 cobrar, pagar,
     * rechazar y destroy resolvían el cheque así y marcaban (o borraban) el de cualquier comercio.
     *
     * El id se lee con ChequeHelper::cheque_id_de() (o sea, con ChequeHelper::id_del_pedido()), la
     * misma lectura que la fila de pago y el endoso: un '12abc', un '12.5', un true, un array o un
     * negativo son "sin cheque" — nunca el 12, ni el 1 al que resuelve `Cheque::find(true)`.
     *
     * @param  mixed  $cheque_id  El id tal como llegó.
     * @return \App\Models\Cheque|null
     */
    protected function cheque_del_dueno($cheque_id) {

        $id = ChequeHelper::cheque_id_de(['cheque_id' => $cheque_id]);

        if ($id <= 0) {

            return null;
        }

        return Cheque::where('user_id', $this->userId())
                        ->where('id', $id)
                        ->first();
    }

    /**
     * La caja del cuerpo de cobrar y pagar, resuelta contra el dueño de la sesión ANTES de escribir.
     *
     * `0`, `null` y `''` son "sin caja", como siempre: el cheque se marca y ninguna caja se mueve.
     * Cualquier otro valor tiene que ser el id de una caja de esta cuenta, leído con LA lectura de
     * ids (ChequeHelper::id_del_pedido(): un entero o un texto de solo dígitos); si no lo es (otra
     * cuenta, inexistente, '5abc', 'abc', '5.0', true, un array, un negativo), el llamador contesta
     * 422.
     *
     * 🔴 No volver a pasarle `$request->caja_id` derecho a CurrentAcountCajaHelper::guardar_pago():
     * ni ese helper ni MovimientoCajaHelper::crear_movimiento() miran de quién es la caja, y así el
     * cobro de un cheque propio entraba como ingreso en la caja de otro comercio y le movía el saldo.
     * Un id ajeno se contesta igual que uno inexistente; en una base compartida los ids son
     * correlativos entre comercios.
     *
     * @param  mixed  $caja_id  Lo que mandó el pedido.
     * @return int|null  0 si no hay caja; el id si la caja es del dueño; null si no lo es.
     */
    protected function caja_id_del_dueno($caja_id) {

        // "Sin caja": la MISMA definición que la regla del endoso (ChequeHelper::es_sin_caja(), por
        // lista blanca: null, '', el entero 0 o un texto de solo ceros).
        if (ChequeHelper::es_sin_caja($caja_id)) {

            return 0;
        }

        return ChequeHelper::id_del_dueno(Caja::class, $caja_id, $this->userId());
    }

    /**
     * El proveedor al que se endosa, si es del dueño de la sesión; null si es de otra cuenta o no
     * existe.
     *
     * 🔴 No sacar este filtro confiando en que la cuenta corriente "ya es del proveedor":
     * get_provider_credit_account() busca la cuenta por `model_id` sin mirar el dueño y
     * CurrentAcountPagoAltaHelper::registrar() tampoco lo verifica, así que sin esto un endoso
     * propio registraba un pago en la cuenta corriente del proveedor de otro comercio y le
     * recalculaba el saldo. Un id ajeno se contesta igual que uno inexistente; en una base
     * compartida los ids son correlativos entre comercios.
     *
     * Un proveedor borrado (soft delete) cuenta como "no existe", como en el resto del sistema: el
     * endoso tampoco podría terminar, porque CurrentAcountHelper::update_credit_account_saldo() lo
     * busca por el morphTo de la cuenta corriente, que no ve los borrados (hasta el 3/10/2026 eso
     * era un 500 que se revertía; ahora es este 422, antes de escribir nada).
     *
     * La consulta es ChequeHelper::id_del_dueno(), la MISMA con la que ChequeHelper::endosar()
     * verifica el destino para las tres puertas del endoso: acá se adelanta para contestar 422
     * antes de buscar la cuenta corriente.
     *
     * @param  int  $provider_id  Ya normalizado y mayor a 0.
     * @return int|null  El id del proveedor si es de esta cuenta.
     */
    protected function proveedor_del_dueno($provider_id) {

        return ChequeHelper::id_del_dueno(Provider::class, $provider_id, $this->userId());
    }
}
 