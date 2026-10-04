<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Los cheques que nacen de un método de pago de tipo cheque y, desde la misión
 * cheques-endoso-y-bancos (21/9/2026), EL ÚNICO CAMINO del endoso.
 *
 * Hasta esa misión el endoso vivía solo en ChequeController::endosar() (el botón del módulo de
 * cheques), que marcaba el recibido a mano y armaba un pago al proveedor; el pago creaba la copia
 * emitida por acá. Ahora hay tres puertas —el botón, la fila de un pago a proveedor y la fila de un
 * gasto— y las tres pasan por crear_cheque() con `cheque_id`, que llama a endosar(). Si alguna vez
 * aparece un `endosado_a_provider_id = ...` o un `endosado_en_expense_id = ...` escrito a mano
 * afuera de este archivo (la semilla es la excepción), es un cuarto camino que se va a desalinear
 * de los otros tres.
 *
 * 🔴 "SIGUE EN CARTERA" TIENE UNA SOLA DEFINICIÓN: sin_endosar() para un builder y en_cartera()
 * para un modelo. Un recibido sale de cartera por DOS columnas (`endosado_a_provider_id` cuando se
 * endosa a un proveedor, `endosado_en_expense_id` cuando se endosa en un gasto), y quien mire una
 * sola de las dos va a contar como plata que entra un cheque que ya se entregó. Los consumidores
 * (ChequeController::index, RecolectorCaja, FlujoCajaHelper) llaman acá y no repiten la condición.
 */
class ChequeHelper {

    /**
     * Días después de `fecha_pago` durante los que un cheque se puede depositar (y por lo tanto
     * endosar). Es el mismo plazo que agrupa ChequeController::index() ("vencidos" = pasó este
     * plazo) y el del mostrador (RecolectorCaja::DIAS_PARA_DEPOSITAR_UN_CHEQUE).
     */
    const DIAS_PARA_DEPOSITAR = 30;

    /**
     * El corte de un endoso cuyo destino es un proveedor que no es de esta cuenta (o que no existe).
     * Es el mismo texto del 422 de ChequeController::endosar(), que lo toma de acá.
     */
    const MENSAJE_PROVEEDOR_AJENO = 'El proveedor elegido no existe o no es de tu cuenta.';

    /** El corte de un endoso en un gasto que no es de esta cuenta. */
    const MENSAJE_GASTO_AJENO = 'El gasto en el que se endosa no es de tu cuenta.';

    /**
     * Crea el cheque de una fila de método de pago de tipo cheque, o —si la fila trae `cheque_id`—
     * endosa el recibido que la fila eligió en vez de crear uno desde cero.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model  El pago (CurrentAcount), el gasto
     *                                                      (Expense), la venta u otro modelo con
     *                                                      métodos de pago.
     * @param  array  $payment_method  La fila tal como la manda MultiPaymentMethods, con las claves
     *                                 del cheque (`numero`, `banco`, `cheque_banco_id`, `fecha_*`,
     *                                 `es_echeq`, `notes`) y, si es un endoso, `cheque_id`.
     * @param  bool  $from_expense  Compatibilidad con la firma vieja; hoy alcanza con que `$model`
     *                              sea un Expense.
     * @return \App\Models\Cheque
     *
     * @throws \RuntimeException  Si la fila pide endosar un cheque que ya no se puede endosar (la
     *                            prevalidación del controller ya corrió, así que acá es una carrera).
     */
    static function crear_cheque($model, $payment_method, $from_expense = false) {

        $cheque_id = self::cheque_id_de($payment_method);

        if ($cheque_id > 0) {

            $origen = Cheque::find($cheque_id);

            if (is_null($origen)) {

                throw new \RuntimeException('El cheque elegido para endosar ya no existe.');
            }

            return self::endosar($origen, $model, $payment_method);
        }

        $es_gasto = $from_expense || $model instanceof Expense;

        return Cheque::create([

            'numero'                    => $payment_method['numero'] ?? null,
            'banco'                     => $payment_method['banco'] ?? null,
            'cheque_banco_id'           => self::cheque_banco_id_de($payment_method, UserHelper::userId()),
            'amount'                    => $payment_method['amount'] ?? null,
            'fecha_emision'             => $payment_method['fecha_emision'] ?? null,
            'fecha_pago'                => $payment_method['fecha_pago'] ?? null,
            'es_echeq'                  => $payment_method['es_echeq'] ?? 0,

            /*
             * null y no 0: el default era el entero 0 (copiado del de `es_echeq`, que ahí sí es un
             * booleano), y todo cheque cargado sin notas quedaba con un "0" escrito en la columna
             * Notas del listado y en el campo Notas del formulario al endosarlo. Una nota vacía es
             * null, como el resto de las claves de este create.
             */
            'notes'                     => $payment_method['notes'] ?? null,

            // Tipo de cheque: recibido (de cliente) o emitido (a proveedor)
            'tipo'                      => Self::get_tipo($model, $es_gasto),

            // Cliente que entregó el cheque (si tipo = recibido)
            'client_id'                 => $model->client_id,

            // Proveedor al que se le emitió el cheque (si tipo = emitido)
            'provider_id'               => $model->provider_id,

            // Con dueño, nunca crudo de la fila: ver endosado_desde_client_id_de().
            'endosado_desde_client_id'  => self::endosado_desde_client_id_de($payment_method, UserHelper::userId()),

            /*
             * Cuenta corriente relacionada, o el gasto. Hasta el 21/9/2026 un cheque cargado en un
             * GASTO quedaba con `current_acount_id = id del gasto`, o sea apuntando a una cuenta
             * corriente cualquiera que casualmente tuviera ese id: ChequeController::cobrar() y
             * pagar() le pasan `$cheque->current_acount` a la caja, y el Excel lo lista como
             * "Cuenta corriente ID". El gasto va en su propia columna.
             */
            'current_acount_id'         => $es_gasto ? null : $model->id,
            'expense_id'                => $es_gasto ? $model->id : null,

            // Usuario que registró el cheque
            'employee_id'               => UserHelper::userId(false),
            'user_id'                   => UserHelper::userId(),

            // Caja utilizada al momento de cobro (recibido) o egreso (emitido)
            'caja_id'                   => null,

            // Datos de endoso (solo si tipo = recibido)
            'endosado_a_provider_id'    => null,
            'endosado_en_expense_id'    => null,
            'fecha_endoso'              => null,

            // Estado actual manual (solo si fue cobrado o rechazado)
            'estado_manual'             => null,
        ]);
    }

    /**
     * Endosa un cheque recibido en un pago a proveedor o en un gasto: marca el origen como salido de
     * cartera y crea la copia EMITIDA que representa el cheque en manos del proveedor (o del gasto).
     *
     * La copia se arma con los datos DEL ORIGEN y no del payload: el cheque es el mismo papel, y lo
     * que la fila traiga en `numero`, `banco` o `amount` es lo que la SPA copió del cheque elegido —
     * si difiere, manda el origen.
     *
     * @param  \App\Models\Cheque  $origen  El recibido que se endosa.
     * @param  \App\Models\CurrentAcount|\App\Models\Expense  $model  El pago al proveedor o el gasto.
     * @param  array  $payment_method  La fila (solo para leer `cheque_banco_id` si el origen no tiene
     *                                 uno de esta cuenta, por cheque_banco_id_de() con el dueño: un
     *                                 banco de otra cuenta deja la copia sin banco).
     * @return \App\Models\Cheque  La copia emitida.
     *
     * @throws \RuntimeException  Si el origen ya no está disponible, si `$model` no es un destino de
     *                            endoso o si el destino (el proveedor del pago, el gasto) no es de
     *                            esta cuenta.
     */
    static function endosar(Cheque $origen, $model, array $payment_method) {

        $user_id = UserHelper::userId();

        $problemas = self::problemas_de_endoso($origen, $user_id);

        if (count($problemas)) {

            throw new \RuntimeException(implode('. ', $problemas));
        }

        if ($model instanceof CurrentAcount && !is_null($model->provider_id)) {

            $marca = ['endosado_a_provider_id' => $model->provider_id];

        } elseif ($model instanceof Expense) {

            $marca = ['endosado_en_expense_id' => $model->id];

        } else {

            /*
             * Un cobro a un cliente o una venta con `cheque_id` no es un endoso: el cheque recibido
             * no cambia de manos. Se corta acá y no se ignora en silencio, porque una fila que
             * "eligió un cheque" y no lo endosó es un pago registrado con un cheque que sigue en
             * cartera.
             */
            throw new \RuntimeException('Un cheque recibido solo se endosa en un pago a un proveedor o en un gasto.');
        }

        /*
         * 🔴 EL DESTINO TIENE QUE SER DE ESTA CUENTA, y se mira ACÁ, antes del UPDATE condicional:
         * este es el único camino del endoso (ver el docblock de la clase), así que acá lo cubre
         * para las tres puertas. Hasta el 3/10/2026 la fila de `POST current-acount/pago` con la
         * cuenta de un proveedor de otro comercio endosaba un cheque propio a ese proveedor y le
         * bajaba la cuenta: pago() y CurrentAcountPagoAltaHelper::registrar() no cruzan la cuenta
         * con el dueño (hallazgo abierto, fuera de este archivo). pago() corre adentro de un
         * DB::transaction, así que esta excepción revierte TODO el pago, no solo el endoso.
         */
        self::verificar_destino_del_endoso($model, $user_id);

        $marca['fecha_endoso'] = Carbon::now();

        /*
         * 🔴 LA MARCA ES UN UPDATE CONDICIONAL, NO UN save(). Dos requests con el mismo `cheque_id`
         * pueden pasar los dos la prevalidación del controller (los dos leyeron el cheque en cartera)
         * y llegar acá a la vez; con un `find` + `save` los dos marcaban y los dos creaban su copia
         * emitida: el mismo papel entregado dos veces, a dos proveedores. El UPDATE repite en el
         * WHERE todas las condiciones de "se puede endosar" (del dueño, recibido, sin marca manual,
         * en cartera), así que de dos carreras gana exactamente una: la otra afecta 0 filas y corta
         * ACÁ, antes de crear la copia. No importa quién leyó qué ni cuándo.
         */
        $q = Cheque::where('id', $origen->id)
                    ->where('cheques.user_id', $user_id)
                    ->where('cheques.tipo', 'recibido')
                    ->whereNull('cheques.estado_manual');

        $marcadas = self::sin_endosar($q)->update($marca);

        if ($marcadas !== 1) {

            throw new \RuntimeException('El cheque N° '.$origen->numero.' ya fue endosado o cambió de estado mientras se registraba: no se endosó dos veces.');
        }

        $origen->refresh();

        /*
         * 🔴 Lo que la copia HEREDA del origen también pasa por los lectores con dueño, no solo lo
         * que trae la fila. Un recibido que quedó atado a un banco (o a un cliente) de otro comercio
         * por los huecos de antes de la misión cheques-filtro-por-dueno (3/10/2026) le pasaba esa
         * referencia a la copia emitida nueva, y GET cheque la mostraba de nuevo. El banco del origen
         * que no es de esta cuenta se toma como "sin banco" (y entonces vale el de la fila, también
         * leído con dueño); el cliente, como "sin cliente". El origen no se toca: el endoso no repara
         * datos viejos.
         */
        $cheque_banco_id = self::id_del_dueno(ChequeBanco::class, $origen->cheque_banco_id, $user_id);

        if (is_null($cheque_banco_id)) {

            $cheque_banco_id = self::cheque_banco_id_de($payment_method, $user_id);
        }

        $endosado_desde_client_id = self::id_del_dueno(Client::class, $origen->client_id, $user_id);

        return Cheque::create([
            'numero'                    => $origen->numero,
            'banco'                     => $origen->banco,
            'cheque_banco_id'           => $cheque_banco_id,
            'amount'                    => $origen->amount,
            'fecha_emision'             => $origen->fecha_emision,
            'fecha_pago'                => $origen->fecha_pago,
            'es_echeq'                  => $origen->es_echeq,
            'notes'                     => $origen->notes,

            'tipo'                      => 'emitido',
            'client_id'                 => null,
            'provider_id'               => $model instanceof CurrentAcount ? $model->provider_id : null,
            'endosado_desde_client_id'  => $endosado_desde_client_id,
            'endosado_desde_cheque_id'  => $origen->id,

            'current_acount_id'         => $model instanceof CurrentAcount ? $model->id : null,
            'expense_id'                => $model instanceof Expense ? $model->id : null,

            'employee_id'               => UserHelper::userId(false),
            'user_id'                   => $user_id,
            'caja_id'                   => null,

            'endosado_a_provider_id'    => null,
            'endosado_en_expense_id'    => null,
            'fecha_endoso'              => null,
            'estado_manual'             => null,
        ]);
    }

    /**
     * Que el DESTINO de un endoso sea de esta cuenta, o corta con una excepción.
     *
     * - Un pago (CurrentAcount): su proveedor tiene que ser un proveedor del dueño, con la misma
     *   consulta que el 422 de ChequeController::endosar() (id_del_dueno(): uno borrado cuenta como
     *   inexistente).
     * - Un gasto (Expense): `expenses.user_id` tiene que ser el dueño. Es seguro pedirlo: las tres
     *   puertas que crean un gasto con métodos de pago (ExpenseController::store, la Agenda y el
     *   asistente) pasan por ExpenseHelper::crear() con el dueño de la cuenta, y nada lo cambia
     *   después. Hoy ninguna llega acá con un gasto ajeno; esto es para la que llegue mañana.
     *
     * @param  \App\Models\CurrentAcount|\App\Models\Expense  $model
     * @param  int  $user_id  El dueño de la cuenta que endosa.
     * @return void
     *
     * @throws \RuntimeException  Si el proveedor o el gasto no son de esta cuenta.
     */
    protected static function verificar_destino_del_endoso($model, $user_id) {

        if ($model instanceof CurrentAcount) {

            if (is_null(self::id_del_dueno(Provider::class, $model->provider_id, $user_id))) {

                throw new \RuntimeException(self::MENSAJE_PROVEEDOR_AJENO);
            }

            return;
        }

        if ($model instanceof Expense && (int) $model->user_id !== (int) $user_id) {

            throw new \RuntimeException(self::MENSAJE_GASTO_AJENO);
        }
    }

    /**
     * El `cheque_id` de una fila: entero mayor a 0, o 0 si no pide endoso. Es LA lectura de esa
     * clave, para la prevalidación, el alta y el botón: un `'12abc'` tiene que ser "sin cheque" en
     * los tres lados, y no "sin cheque" en la prevalidación y 12 en el alta (un `(int)` pelado lo
     * volvía 12).
     *
     * @param  array  $payment_method
     * @return int
     */
    static function cheque_id_de($payment_method) {

        if (!is_array($payment_method) || !isset($payment_method['cheque_id']) || !is_numeric($payment_method['cheque_id'])) {

            return 0;
        }

        $id = (int) $payment_method['cheque_id'];

        return $id > 0 ? $id : 0;
    }

    /**
     * El `cheque_banco_id` de una fila: el id de un banco del catálogo DE ESE DUEÑO, o null (la SPA
     * manda 0 para "sin banco").
     *
     * Un banco de otra cuenta, o uno que no existe, se lee como "sin banco" —igual que un 0 o un
     * 'abc'— y el cheque se guarda con su texto `banco`, que es el dato del papel. No se rechaza la
     * fila: la arman tres pantallas y el asistente, y el único que manda un banco ajeno es alguien
     * armando el pedido a mano.
     *
     * 🔴 Hasta el 3/10/2026 (misión cheques-filtro-por-dueno) el id se guardaba sin cruzarlo con el
     * dueño: el cheque quedaba atado al banco de otro comercio y `GET cheque` (que carga
     * `cheque_banco`), el Excel y el asistente mostraban su nombre. Un id ajeno se contesta igual
     * que uno inexistente; en una base compartida los ids son correlativos entre comercios. Por eso
     * `$user_id` es OBLIGATORIO: nadie puede leer el banco de una fila sin decir de qué dueño.
     *
     * @param  array  $payment_method
     * @param  int  $user_id  El dueño de la cuenta: el mismo que se estampa en el `user_id` del cheque.
     * @return int|null
     */
    static function cheque_banco_id_de($payment_method, $user_id) {

        $valor = isset($payment_method['cheque_banco_id']) ? $payment_method['cheque_banco_id'] : null;

        return self::id_del_dueno(ChequeBanco::class, $valor, $user_id);
    }

    /**
     * El `endosado_desde_client_id` de una fila: el id de un cliente DE ESE DUEÑO, o null.
     *
     * Ninguna puerta lo manda legítimamente (ni la SPA ni el asistente: el endoso lo llena en la
     * copia desde el cliente del origen, ver endosar()), así que en una fila es siempre alguien
     * armando el pedido a mano. Un cliente de otra cuenta, o uno que no existe, se lee como null.
     *
     * 🔴 Hasta el 3/10/2026 (segunda vuelta de la misión cheques-filtro-por-dueno) se guardaba
     * CRUDO de la fila: un cobro o un gasto propio con el id de un cliente de otro comercio daba 201
     * y `GET cheque` —que carga `endosado_desde_client` por withAll, y Client no esconde nada—
     * devolvía su nombre, email, teléfono, CUIT y dirección. Un id ajeno se contesta igual que uno
     * inexistente; en una base compartida los ids son correlativos entre comercios. `$user_id` es
     * OBLIGATORIO por lo mismo que en cheque_banco_id_de(). Y ningún `*_id` de `cheques` sale crudo
     * de la fila: lo sostiene el test-mecanismo del centinela (9_Tenencia_de_cheques_Test).
     *
     * @param  array  $payment_method
     * @param  int  $user_id  El dueño de la cuenta: el mismo que se estampa en el `user_id` del cheque.
     * @return int|null
     */
    static function endosado_desde_client_id_de($payment_method, $user_id) {

        $valor = isset($payment_method['endosado_desde_client_id']) ? $payment_method['endosado_desde_client_id'] : null;

        return self::id_del_dueno(Client::class, $valor, $user_id);
    }

    /**
     * El id de una fila de `$clase` si es DE ESE DUEÑO; null si es de otra cuenta, si no existe (o
     * está borrada, para los modelos con SoftDeletes) o si lo que llegó no es un id. Es la consulta
     * de tenencia que comparten los lectores de esta clase (el banco y el cliente de una fila, lo
     * que el endoso hereda del origen, el destino del endoso) y los de ChequeController (la caja y
     * el proveedor del cuerpo).
     *
     * Un id ajeno se contesta igual que uno inexistente: en una base compartida los ids son
     * correlativos entre comercios, y un "existe pero no es tuyo" ya es una fuga.
     *
     * @param  string  $clase  Un modelo con columna `user_id` (ChequeBanco, Client, Provider, Caja).
     * @param  mixed  $valor  El id tal como llegó (de un pedido o de una columna).
     * @param  int  $user_id  El dueño de la cuenta.
     * @return int|null
     */
    static function id_del_dueno($clase, $valor, $user_id) {

        if (is_null($valor) || !is_numeric($valor)) {

            return null;
        }

        $id = (int) $valor;

        if ($id <= 0) {

            return null;
        }

        $es_del_dueno = $clase::where('user_id', $user_id)
                                ->where('id', $id)
                                ->exists();

        return $es_del_dueno ? $id : null;
    }

    static function get_tipo($model, $from_expense) {
        if ($from_expense) {
            return 'emitido';
        }
        if (!is_null($model->client_id)) {
            return 'recibido';
        }
        return 'emitido';
    }

    /**
     * Aplica a un builder (Eloquent o DB::table) la condición "sigue en cartera": ni endosado a un
     * proveedor (la columna vieja acepta 0 como "no") ni endosado en un gasto.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    static function sin_endosar($q) {

        return $q->where(function ($sub) {
                    $sub->whereNull('cheques.endosado_a_provider_id')->orWhere('cheques.endosado_a_provider_id', 0);
                })
                ->whereNull('cheques.endosado_en_expense_id');
    }

    /**
     * La misma condición que sin_endosar(), sobre un modelo ya cargado.
     *
     * @param  \App\Models\Cheque  $cheque
     * @return bool
     */
    static function en_cartera($cheque) {

        return empty($cheque->endosado_a_provider_id) && is_null($cheque->endosado_en_expense_id);
    }

    /**
     * true si el cheque ya pasó el plazo de depósito: hoy > fecha_pago + DIAS_PARA_DEPOSITAR. Es
     * el "vencidos" de ChequeController::index(); el día exacto del vencimiento todavía entra.
     *
     * @param  \App\Models\Cheque  $cheque
     * @param  \Carbon\Carbon|null  $hoy
     * @return bool
     */
    static function vencido($cheque, $hoy = null) {

        if (is_null($cheque->fecha_pago)) {

            return false;
        }

        $hoy = is_null($hoy) ? Carbon::today() : $hoy->copy()->startOfDay();

        $vencimiento = Carbon::parse($cheque->fecha_pago)->startOfDay()->addDays(self::DIAS_PARA_DEPOSITAR);

        return $hoy->gt($vencimiento);
    }

    /**
     * Los cheques recibidos que hoy se pueden endosar: sin marca manual, en cartera y no vencidos.
     * Es exactamente el criterio del botón Endosar del módulo (pendientes, disponibles para cobrar y
     * pronto a vencerse; los vencidos no). Ordenados por fecha de pago, el que vence antes primero.
     *
     * Un cheque SIN fecha de pago entra: ChequeController::index() lo lista igual (Carbon::parse(null)
     * es ahora, así que cae en "pendientes") y vencido() lo deja pasar, así que dejarlo afuera acá
     * sería un cheque que el módulo ofrece endosar con su botón y el select no muestra.
     *
     * @param  int  $user_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    static function disponibles_para_endosar($user_id) {

        $desde = Carbon::today()->subDays(self::DIAS_PARA_DEPOSITAR)->toDateString();

        $q = Cheque::where('cheques.user_id', $user_id)
                    ->where('cheques.tipo', 'recibido')
                    ->whereNull('cheques.estado_manual')
                    ->where(function ($sub) use ($desde) {
                        $sub->whereNull('cheques.fecha_pago')->orWhereDate('cheques.fecha_pago', '>=', $desde);
                    });

        return self::sin_endosar($q)
                    ->withAll()
                    ->orderBy('cheques.fecha_pago', 'ASC')
                    ->orderBy('cheques.id', 'ASC')
                    ->get();
    }

    /**
     * Por qué UN cheque no se puede endosar hoy, en lenguaje de comerciante. Vacío si se puede.
     *
     * @param  \App\Models\Cheque|null  $cheque
     * @param  int  $user_id  El dueño de la cuenta que endosa.
     * @param  \Carbon\Carbon|null  $hoy
     * @return array<int, string>
     */
    static function problemas_de_endoso($cheque, $user_id, $hoy = null) {

        if (is_null($cheque) || (int) $cheque->user_id !== (int) $user_id) {

            // Un id ajeno se contesta igual que uno inexistente: no se confirma qué hay del otro lado.
            return ['El cheque elegido para endosar no existe o no es de tu cuenta'];
        }

        $nombre = 'El cheque N° '.$cheque->numero;

        if ($cheque->tipo !== 'recibido') {

            return [$nombre.' no es un cheque recibido: solo se endosan los que te entregó un cliente'];
        }

        if ($cheque->estado_manual === 'cobrado') {

            return [$nombre.' ya fue cobrado'];
        }

        if ($cheque->estado_manual === 'rechazado') {

            return [$nombre.' fue rechazado'];
        }

        if (!self::en_cartera($cheque)) {

            return [$nombre.' ya fue endosado'];
        }

        if (self::vencido($cheque, $hoy)) {

            return [$nombre.' está vencido: pasaron más de '.self::DIAS_PARA_DEPOSITAR.' días de su fecha de pago'];
        }

        return [];
    }

    /**
     * Los problemas de TODAS las filas con `cheque_id` de un payload de métodos de pago (el de
     * `POST current-acount/pago`, el de `POST expense` o la fila que arma el botón Endosar), para
     * responder 422 ANTES de escribir nada. Vacío si todo se puede endosar.
     *
     * Además de lo de cada cheque (problemas_de_endoso), mira lo que solo se ve con la fila en la
     * mano: que el monto de la fila sea el del cheque (un cheque se endosa ENTERO, no hay endoso
     * parcial), que el mismo cheque no esté en dos filas, que la fila sea de un método de tipo
     * cheque — attach_payment_methods() solo crea cheques para ese tipo, así que un `cheque_id` en
     * una fila de Efectivo se registraría como efectivo y el cheque seguiría en cartera sin que
     * nadie lo note — y que la fila venga SIN caja destino, porque un endoso no mueve caja.
     *
     * @param  array|null  $payment_methods  Las filas tal como las manda la SPA.
     * @param  int  $user_id
     * @return array<int, string>
     */
    static function problemas_de_endoso_en_payload($payment_methods, $user_id) {

        if (!is_array($payment_methods)) {

            return [];
        }

        $problemas = [];
        $vistos = [];
        $hoy = Carbon::today();

        foreach ($payment_methods as $payment_method) {

            $cheque_id = self::cheque_id_de($payment_method);

            if ($cheque_id <= 0) {

                continue;
            }

            $cheque = Cheque::find($cheque_id);

            $de_este_cheque = self::problemas_de_endoso($cheque, $user_id, $hoy);

            if (count($de_este_cheque)) {

                foreach ($de_este_cheque as $problema) {

                    $problemas[] = $problema;
                }

                continue;
            }

            $nombre = 'El cheque N° '.$cheque->numero;

            if (isset($vistos[$cheque_id])) {

                $problemas[] = $nombre.' está elegido en dos filas: un cheque se endosa una sola vez';

                continue;
            }

            $vistos[$cheque_id] = true;

            if (!self::fila_es_de_tipo_cheque($payment_method)) {

                $problemas[] = $nombre.' está en una fila que no es de tipo cheque: elegí el método de pago Cheque para endosarlo';
            }

            $amount = isset($payment_method['amount']) && is_numeric($payment_method['amount']) ? (float) $payment_method['amount'] : null;

            if (is_null($amount) || abs($amount - (float) $cheque->amount) > 0.01) {

                $problemas[] = $nombre.' es de $ '.Numbers::price($cheque->amount).': un cheque se endosa entero, el monto de la fila tiene que ser ese';
            }

            /*
             * Un endoso nunca es plata de caja: el papel cambia de manos y ninguna caja se mueve.
             * Pero una fila de tipo cheque con caja destino SÍ genera egreso (es la conducta vieja
             * del cheque nuevo, y el ABM de "caja por defecto por método de pago" se la propone al
             * método Cheque), así que con `cheque_id` la caja tiene que venir vacía. El botón del
             * módulo ya manda 0.
             */
            if (isset($payment_method['caja_id']) && is_numeric($payment_method['caja_id']) && (int) $payment_method['caja_id'] !== 0) {

                $problemas[] = $nombre.' se endosa sin caja: un cheque endosado no mueve plata de ninguna caja';
            }
        }

        return $problemas;
    }

    /**
     * true si alguna fila del payload trae un `cheque_id` mayor a 0, o sea si pide un endoso.
     *
     * @param  array|null  $payment_methods
     * @return bool
     */
    static function payload_pide_endoso($payment_methods) {

        if (!is_array($payment_methods)) {

            return false;
        }

        foreach ($payment_methods as $payment_method) {

            if (self::cheque_id_de($payment_method) > 0) {

                return true;
            }
        }

        return false;
    }

    /**
     * Si el método de pago de la fila es de tipo cheque, con la misma consulta que usa
     * attach_payment_methods() para decidir si crea el cheque.
     *
     * @param  array  $payment_method
     * @return bool
     */
    protected static function fila_es_de_tipo_cheque($payment_method) {

        if (!isset($payment_method['current_acount_payment_method_id']) || !is_numeric($payment_method['current_acount_payment_method_id'])) {

            return false;
        }

        $metodo = CurrentAcountPaymentMethod::find((int) $payment_method['current_acount_payment_method_id']);

        return !is_null($metodo) && !is_null($metodo->type) && $metodo->type->slug == 'cheque';
    }

}
