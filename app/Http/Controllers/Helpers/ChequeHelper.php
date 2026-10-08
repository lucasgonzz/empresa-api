<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
     * Un cheque a endosar que no es de esta cuenta, o que no existe: el mismo texto para los dos, en
     * problemas_de_endoso() y en crear_cheque(). Sin punto final: problemas_de_endoso() lo junta con
     * otros y el punto lo pone quien arma el mensaje.
     */
    const CHEQUE_DE_ENDOSO_AJENO = 'El cheque elegido para endosar no existe o no es de tu cuenta';

    /**
     * Las ÚNICAS cinco claves que la edición de un cheque (ChequeController::update) lee del pedido.
     * `cheque_banco_id` es el banco del catálogo: el texto `banco` se deriva de él y no se edita.
     * 🔴 Agregar una acá es abrirle una columna más al PUT: antes de hacerlo, preguntarse si cambiarla
     * mueve plata o cuentas (cliente, proveedor, monto, estado y endoso, sí).
     */
    const CAMPOS_EDITABLES = ['numero', 'cheque_banco_id', 'notes', 'fecha_emision', 'fecha_pago'];

    /** El 422 de la edición con un banco que no es del catálogo de esta cuenta (o que no existe). */
    const MENSAJE_BANCO_AJENO = 'El banco elegido no existe o no es de tu cuenta.';

    /** El corte de la edición cuando el cheque no es de esta cuenta (defensa: el controller ya lo filtró). */
    const MENSAJE_CHEQUE_AJENO = 'El cheque no existe o no es de tu cuenta.';

    /**
     * Largo máximo del número de cheque: `cheques.numero` es un varchar(191), no de 255, porque
     * AppServiceProvider llama a Schema::defaultStringLength(191). Pasarse es un 500 en modo estricto.
     */
    const LARGO_MAXIMO_NUMERO = 191;

    /** Largo máximo de las notas: es un `text` (65.535 bytes) en la base; con margen para multibyte. */
    const LARGO_MAXIMO_NOTAS = 20000;

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
     * @throws \RuntimeException  Si la fila pide endosar un cheque que no es de esta cuenta (o no
     *                            existe) o que ya no se puede endosar (la prevalidación del
     *                            controller ya corrió, así que acá es una carrera).
     */
    static function crear_cheque($model, $payment_method, $from_expense = false) {

        $cheque_id = self::cheque_id_de($payment_method);

        if ($cheque_id > 0) {

            /*
             * Scopeado por dueño, y un cheque de otra cuenta se contesta EXACTAMENTE igual que uno
             * que no existe, con el texto de problemas_de_endoso(). Hasta el 3/10/2026 era un
             * Cheque::find() pelado que los distinguía ("ya no existe." / "no existe o no es de tu
             * cuenta"): por la puerta de VENDER, que no prevalida, con APP_DEBUG=true se veía cuál
             * de los dos era. En una base compartida los ids son correlativos entre comercios.
             */
            $origen = Cheque::where('user_id', UserHelper::userId())
                            ->where('id', $cheque_id)
                            ->first();

            if (is_null($origen)) {

                throw new \RuntimeException(self::CHEQUE_DE_ENDOSO_AJENO);
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
         *
         * Para lo heredado, BORRADO NO ES AJENO (el `true` del final): el cliente propio de un
         * recibido puede estar borrado (SoftDeletes) y la copia lo conserva, como antes de esta
         * misión, para que al restaurarlo se vuelva a ver. Lo que llega en un pedido y el proveedor
         * destino, en cambio, se leen sin los borrados.
         */
        $cheque_banco_id = self::id_del_dueno(ChequeBanco::class, $origen->cheque_banco_id, $user_id, true);

        if (is_null($cheque_banco_id)) {

            $cheque_banco_id = self::cheque_banco_id_de($payment_method, $user_id);
        }

        $endosado_desde_client_id = self::id_del_dueno(Client::class, $origen->client_id, $user_id, true);

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
     *   inexistente), y su `credit_account_id` —adonde va la plata— una cuenta DE ese proveedor.
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

            /*
             * 🔴 Y la plata va adonde dice el rótulo. El pago escribe en `credit_account_id` (el
             * create, el saldo y el recálculo de CurrentAcountPagoAltaHelper::registrar()), no en
             * `provider_id`: hasta el 3/10/2026 un pago con un proveedor PROPIO en `model_id` y la
             * cuenta corriente del proveedor de OTRO comercio en `credit_account_id` endosaba el
             * cheque y le bajaba la cuenta al otro comercio. La cuenta tiene que ser DE ese
             * proveedor, que ya se verificó que es de esta cuenta.
             */
            if (!is_null($model->credit_account_id)) {

                $es_su_cuenta = CreditAccount::where('id', $model->credit_account_id)
                                                ->where('model_name', 'provider')
                                                ->where('model_id', $model->provider_id)
                                                ->exists();

                if (!$es_su_cuenta) {

                    throw new \RuntimeException(self::MENSAJE_PROVEEDOR_AJENO);
                }
            }

            return;
        }

        if ($model instanceof Expense && (int) $model->user_id !== (int) $user_id) {

            throw new \RuntimeException(self::MENSAJE_GASTO_AJENO);
        }
    }

    /**
     * LA lectura de un id que llega en un pedido —en la ruta, en el cuerpo o en una fila de pago—
     * para los cuatro archivos de cheques: un entero mayor a 0, o un texto de SOLO dígitos ASCII
     * (sin espacios, signo, decimales ni exponente). Todo lo demás —un decimal, un booleano, un
     * array, '12abc', '80e1', ' 12', '+12', "12\n", "1²"— es "sin id" (0).
     *
     * 🔴 Que sea UNA sola. Hasta el 3/10/2026 (segunda vuelta de la misión cheques-filtro-por-dueno)
     * convivían tres: is_numeric en cheque_id_de() ('12.5' era el 12, '80e1' el 800), un (int)
     * pelado en el provider_id de ChequeController::endosar() (true era el proveedor 1, [5] el 1,
     * '5abc' el 5) y el where('id', $id) con el texto de la ruta en
     * ChequeBancoController::banco_del_dueno() (MySQL castea: 'cheque-banco/84abc' era el 84). Con
     * tres lecturas, el mismo texto era "sin id" en una puerta y un id en otra. La SPA manda
     * siempre enteros y el ejecutor del asistente ya rechaza decimales, booleanos y textos que no
     * son enteros: lo único que cae afuera es un pedido armado a mano.
     *
     * 🔴 "Solo dígitos" es preg_match('/\A[0-9]+\z/'), NO ctype_digit: ctype_digit depende del
     * locale, y en el PHP de Windows (LC_CTYPE Spanish_Argentina.1252) "1²" —un 1 y un "²"— es
     * "solo dígitos": hasta el 3/10/2026 `PUT cheque/rechazar` por formulario con ese cheque_id
     * rechazaba el cheque 1, cosa que en el Linux de producción no pasa. Y con \z y no con $: un $
     * acepta un "\n" al final.
     *
     * @param  mixed  $valor
     * @return int  El id, o 0 si lo que llegó no es un id.
     */
    static function id_del_pedido($valor) {

        if (is_int($valor)) {

            return $valor > 0 ? $valor : 0;
        }

        if (is_string($valor) && preg_match('/\A[0-9]+\z/', $valor) === 1) {

            $id = (int) $valor;

            return $id > 0 ? $id : 0;
        }

        return 0;
    }

    /**
     * LA definición de "sin caja" de una fila de pago o de un pedido, por LISTA BLANCA: ausente
     * (el llamador pasa null), null, '', el entero 0 o un texto de solo ceros. Todo lo demás —true,
     * 0.5, "0.5", "abc", un id— es "con caja".
     *
     * 🔴 Es una sola para la regla del endoso (problemas_de_endoso_en_payload(): un endoso va sin
     * caja) y para el cobro y el pago de un cheque (ChequeController::caja_id_del_dueno(): sin caja
     * no se mueve ninguna). Por lista blanca y no por "lo que no parezca un número", porque el que
     * escribe —PaymentMethodHelper::attach_payment_methods(), fuera de los archivos de cheques—
     * mueve caja con cualquier cosa que sea `!= 0`: hasta el 3/10/2026 `true` o `0.5` pasaban por
     * "sin caja" en la prevalidación y el alta sacaba la plata de la caja 1. Los ceros se reconocen
     * con preg_match y no con ctype_digit, que depende del locale.
     *
     * @param  mixed  $valor
     * @return bool
     */
    static function es_sin_caja($valor) {

        if (is_null($valor) || $valor === '' || $valor === 0) {

            return true;
        }

        return is_string($valor) && preg_match('/\A0+\z/', $valor) === 1;
    }

    /**
     * El `cheque_id` de una fila: el id (por id_del_pedido()), o 0 si no pide endoso. Es LA lectura
     * de esa clave, para la prevalidación, el alta y el botón: un `'12abc'` o un `'12.5'` tienen que
     * ser "sin cheque" en los tres lados, y no "sin cheque" en la prevalidación y 12 en el alta.
     *
     * @param  array  $payment_method
     * @return int
     */
    static function cheque_id_de($payment_method) {

        if (!is_array($payment_method) || !array_key_exists('cheque_id', $payment_method)) {

            return 0;
        }

        return self::id_del_pedido($payment_method['cheque_id']);
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
     * está borrada, para los modelos con SoftDeletes) o si lo que llegó no es un id (se lee con
     * id_del_pedido(): un decimal, '12abc' o un booleano no son ningún id). Es la consulta
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
     * @param  bool  $incluir_borrados  true para lo HEREDADO de una fila que ya existe (el cliente o
     *                                  el banco del origen de un endoso): un borrado (SoftDeletes)
     *                                  sigue siendo de su dueño, no es ajeno. Lo que llega en un
     *                                  pedido y el proveedor destino se leen sin los borrados.
     * @return int|null
     */
    static function id_del_dueno($clase, $valor, $user_id, $incluir_borrados = false) {

        // Sin dueño no hay nada de nadie. Con un `$user_id` null la consulta de abajo sería
        // `where user_id is null` y devolvería una fila SIN dueño: fallaba abierta (latente: solo
        // si UserHelper::userId() diera null, pero es tenencia).
        if (empty($user_id)) {

            return null;
        }

        $id = self::id_del_pedido($valor);

        if ($id === 0) {

            return null;
        }

        $q = $clase::query();

        if ($incluir_borrados && in_array(SoftDeletes::class, class_uses_recursive($clase), true)) {

            $q = $clase::withTrashed();
        }

        $es_del_dueno = $q->where('user_id', $user_id)
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
            return [self::CHEQUE_DE_ENDOSO_AJENO];
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

            // Scopeado por dueño: un cheque de otra cuenta ni se carga (problemas_de_endoso() lo
            // contesta igual que a uno que no existe).
            $cheque = Cheque::where('user_id', $user_id)
                            ->where('id', $cheque_id)
                            ->first();

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
             *
             * 🔴 "Vacía" es la LISTA BLANCA de es_sin_caja(), no "lo que no parezca un número": el
             * alta (PaymentMethodHelper::attach_payment_methods()) mueve caja si la fila trae
             * `caja_id` y es `!= 0`, así que hasta el 3/10/2026 `true`, `0.5` o `"0.5"` pasaban esta
             * regla y el alta sacaba la plata de la caja 1, aunque fuera de otro comercio.
             */
            $caja_id = array_key_exists('caja_id', $payment_method) ? $payment_method['caja_id'] : null;

            if (!self::es_sin_caja($caja_id)) {

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
     * La edición ACOTADA de un cheque (misión cheque-edicion-acotada, 8/10/2026): aplica al cheque
     * SOLO el número, el banco, las notas, la fecha de emisión y la fecha de pago que vengan en el
     * pedido, y nada más. Es todo-o-nada: si cualquiera de los valores es inválido no se escribe
     * NADA (ni los campos válidos).
     *
     * - Una clave AUSENTE del pedido no se toca; una clave presente y vacía (null, '') vacía el campo.
     * - `numero`: se recorta con trim, hasta 191 caracteres. `notes`: ídem, texto largo.
     * - `cheque_banco_id`: tiene que ser un banco DEL CATÁLOGO DE ESTE DUEÑO (id_del_dueno()); el texto
     *   legacy `banco` se reescribe con su nombre para quedar en sincronía (Excel, mostrador, SPA
     *   vieja). Vacío (null, '', 0) con un banco previo = "sin banco": ambos quedan en null. Vacío sin
     *   banco previo (cheque viejo que solo tiene el texto) = no se toca nada, para no perder ese texto.
     * - Fechas: `YYYY-MM-DD` o un ISO datetime; se toman los primeros 10 caracteres y se valida con
     *   checkdate(), SIN pasar por zona horaria (un `...T23:00:00-03:00` es ese día, no el siguiente).
     * - Un recibido endosado y su copia emitida (`endosado_desde_cheque_id`) son el MISMO papel:
     *   número, banco y fechas se replican al otro en la misma transacción. Las notas NO (cada lado
     *   anota lo suyo). El par se filtra por el dueño.
     *
     * 🔴 Lo único que se escribe sale de $cambios, armado a mano clave por clave: ningún valor del
     * pedido llega a una columna que no sea una de las cinco (y `banco`, derivado del catálogo).
     * Cliente, proveedor, monto, cuenta corriente, caja, estado y endoso no se tocan JAMÁS por acá.
     *
     * @param  \App\Models\Cheque  $cheque  El cheque ya resuelto contra el dueño.
     * @param  array  $pedido  Solo las claves de CAMPOS_EDITABLES que vinieron en el pedido.
     * @param  int  $user_id  El dueño de la cuenta.
     * @return array<int, string>  Los problemas, en lenguaje de comerciante; vacío si se aplicó (o si
     *                             no había nada para cambiar).
     */
    static function actualizar_datos(Cheque $cheque, array $pedido, $user_id) {

        // Defensa en profundidad: el controller ya resolvió el cheque con cheque_del_dueno().
        if (empty($user_id) || (int) $cheque->user_id !== (int) $user_id) {

            return [self::MENSAJE_CHEQUE_AJENO];
        }

        $problemas = [];
        $cambios = [];

        if (array_key_exists('numero', $pedido)) {

            list($numero, $problema) = self::leer_texto_de_cheque($pedido['numero'], self::LARGO_MAXIMO_NUMERO, 'El número del cheque no es válido.', 'El número del cheque es demasiado largo: el máximo es de '.self::LARGO_MAXIMO_NUMERO.' caracteres.');

            if (is_null($problema)) {

                $cambios['numero'] = $numero;

            } else {

                $problemas[] = $problema;
            }
        }

        if (array_key_exists('notes', $pedido)) {

            list($notas, $problema) = self::leer_texto_de_cheque($pedido['notes'], self::LARGO_MAXIMO_NOTAS, 'Las notas del cheque no son válidas.', 'Las notas del cheque son demasiado largas.');

            if (is_null($problema)) {

                $cambios['notes'] = $notas;

            } else {

                $problemas[] = $problema;
            }
        }

        foreach (['fecha_emision' => 'La fecha de emisión', 'fecha_pago' => 'La fecha de pago'] as $clave => $rotulo) {

            if (!array_key_exists($clave, $pedido)) {

                continue;
            }

            list($fecha, $problema) = self::leer_fecha_de_cheque($pedido[$clave], $rotulo.' no es válida.');

            if (is_null($problema)) {

                $cambios[$clave] = $fecha;

            } else {

                $problemas[] = $problema;
            }
        }

        if (array_key_exists('cheque_banco_id', $pedido)) {

            // "Vacío" es la misma lista blanca de es_sin_caja(): null, '', el entero 0 o solo ceros.
            if (self::es_sin_caja($pedido['cheque_banco_id'])) {

                // Solo si había banco del catálogo: un cheque viejo con apenas el texto `banco` recibe
                // del formulario un "sin banco" que NO es una orden de borrar ese texto.
                if (!empty($cheque->cheque_banco_id)) {

                    $cambios['cheque_banco_id'] = null;
                    $cambios['banco'] = null;
                }

            } else {

                $banco_id = self::id_del_dueno(ChequeBanco::class, $pedido['cheque_banco_id'], $user_id);

                if (is_null($banco_id)) {

                    $problemas[] = self::MENSAJE_BANCO_AJENO;

                } else {

                    $cambios['cheque_banco_id'] = $banco_id;
                    $cambios['banco'] = ChequeBanco::where('id', $banco_id)->value('name');
                }
            }
        }

        if (count($problemas)) {

            return $problemas;
        }

        if (!count($cambios)) {

            return [];
        }

        // Lo que se replica al otro papel del endoso: todo menos las notas.
        $para_el_par = $cambios;
        unset($para_el_par['notes']);

        DB::transaction(function () use ($cheque, $cambios, $para_el_par, $user_id) {

            Cheque::where('id', $cheque->id)
                    ->where('user_id', $user_id)
                    ->update($cambios);

            if (count($para_el_par)) {

                $par = self::par_del_endoso($cheque, $user_id);

                if (!is_null($par)) {

                    $par->update($para_el_par);
                }
            }
        });

        return [];
    }

    /**
     * El builder de las filas que son "el otro papel" del mismo cheque endosado, siempre del dueño:
     * para un recibido, las copias emitidas que nacieron de él (`endosado_desde_cheque_id` = su id);
     * para una copia emitida, el recibido del que salió. null si el cheque no está en ningún endoso.
     *
     * @param  \App\Models\Cheque  $cheque
     * @param  int  $user_id
     * @return \Illuminate\Database\Eloquent\Builder|null
     */
    protected static function par_del_endoso(Cheque $cheque, $user_id) {

        if ($cheque->tipo === 'recibido') {

            $q = Cheque::where('endosado_desde_cheque_id', $cheque->id);

        } elseif (!empty($cheque->endosado_desde_cheque_id)) {

            $q = Cheque::where('id', $cheque->endosado_desde_cheque_id);

        } else {

            return null;
        }

        return $q->where('user_id', $user_id);
    }

    /**
     * Lee un texto libre del pedido (número, notas): null y '' son "vaciar"; un número entero o
     * decimal se toma como texto; un booleano o un array no son texto. Se recorta con trim().
     *
     * @param  mixed  $valor
     * @param  int  $largo_maximo
     * @param  string  $mensaje_invalido
     * @param  string  $mensaje_largo
     * @return array{0: string|null, 1: string|null}  [valor a guardar, problema]
     */
    protected static function leer_texto_de_cheque($valor, $largo_maximo, $mensaje_invalido, $mensaje_largo) {

        if (is_null($valor)) {

            return [null, null];
        }

        if (!is_string($valor) && !is_int($valor) && !is_float($valor)) {

            return [null, $mensaje_invalido];
        }

        $texto = trim((string) $valor);

        if ($texto === '') {

            return [null, null];
        }

        if (mb_strlen($texto) > $largo_maximo) {

            return [null, $mensaje_largo];
        }

        return [$texto, null];
    }

    /**
     * Lee una fecha del pedido: null y '' son "vaciar"; `YYYY-MM-DD` o un ISO datetime (`...T...`
     * o `... ...`) valen por sus PRIMEROS 10 caracteres, validados con checkdate() y sin pasar por
     * Carbon ni zona horaria: el día que escribió el usuario es el día que se guarda.
     *
     * @param  mixed  $valor
     * @param  string  $mensaje_invalido
     * @return array{0: string|null, 1: string|null}  [`YYYY-MM-DD` a guardar, problema]
     */
    protected static function leer_fecha_de_cheque($valor, $mensaje_invalido) {

        if (is_null($valor)) {

            return [null, null];
        }

        if (!is_string($valor)) {

            return [null, $mensaje_invalido];
        }

        $texto = trim($valor);

        if ($texto === '') {

            return [null, null];
        }

        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?\z/s', $texto, $m) !== 1) {

            return [null, $mensaje_invalido];
        }

        if ((int) $m[1] < 1900 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {

            return [null, $mensaje_invalido];
        }

        return [$m[1].'-'.$m[2].'-'.$m[3], null];
    }

    /**
     * Si el método de pago de la fila es de tipo cheque, con la misma consulta que usa
     * attach_payment_methods() para decidir si crea el cheque.
     *
     * 🔴 El id del método se lee con id_del_pedido(), no con is_numeric + (int). Hasta el 3/10/2026
     * "1.5" era acá el método 1 (Cheque) y la fila pasaba la prevalidación, pero el alta lo busca
     * crudo —find("1.5") no encuentra nada— y saltea la fila: quedaba un pago registrado SIN métodos,
     * con el cheque en cartera y la cuenta del proveedor bajada, que es justo lo que esta función
     * existe para evitar. Ahora lo que no es un id no es ningún método: la fila "no es de tipo
     * cheque" y la prevalidación la corta.
     *
     * @param  array  $payment_method
     * @return bool
     */
    protected static function fila_es_de_tipo_cheque($payment_method) {

        $valor = isset($payment_method['current_acount_payment_method_id']) ? $payment_method['current_acount_payment_method_id'] : null;

        $id = self::id_del_pedido($valor);

        if ($id === 0) {

            return false;
        }

        $metodo = CurrentAcountPaymentMethod::find($id);

        return !is_null($metodo) && !is_null($metodo->type) && $metodo->type->slug == 'cheque';
    }

}
