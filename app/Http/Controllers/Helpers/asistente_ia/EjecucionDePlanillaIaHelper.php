<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Article;
use App\Models\Client;
use App\Models\IvaCondition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo que pasa cuando se confirma la tarjeta de una planilla: crea los clientes nuevos y una venta por renglón
 * (misión agente-venta-por-planilla, 02/11/2026). Corre en dos etapas, sin ninguna transacción del ejecutor
 * abierta (EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS), con la persona autenticada.
 *
 * 🔴 EL MISMO CAMINO QUE UNA VENTA Y UN ALTA SUELTAS. Cada venta sale por PropuestaVentaIaHelper::ejecutar()
 * (SaleController::store(), con el payload de Vender) y cada cliente por EjecutorGenericoIaHelper::ejecutar()
 * (ClientController::store()), con una tarjeta en memoria que nunca se guarda: las guardas, el correlativo, el
 * stock, la caja y la cuenta corriente son los de siempre. Acá no se escribe ninguna venta a mano.
 *
 * 🔴 IDEMPOTENTE POR RENGLÓN, CON TRES REDES. Un reintento hace solo lo que falta:
 *   1. El N° de venta se guarda en el renglón apenas se crea: un renglón `registrada` no se vuelve a mirar.
 *   2. Cada venta lleva un `offline_uuid` propio del renglón (planilla + número): si el proceso muere entre
 *      crear la venta y guardar el N°, SaleController::store() devuelve esa misma venta en vez de crear otra.
 *      Es la red que ya usa la subida de ventas sin conexión (VentaOfflineHelper).
 *   3. Antes de crear, se mira si la venta ya está (PlanillaIaHelper::venta_ya_registrada()), por lo que se
 *      haya cargado a mano en el medio.
 *
 * 🔴 UN RENGLÓN QUE FALLA NO TUMBA A LOS OTROS: queda `bloqueada` con su motivo, y se informa. Solo si no se pudo
 * cargar NINGUNO se lanza, y la tarjeta vuelve a quedar para confirmar.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class EjecucionDePlanillaIaHelper
{
    /** La pantalla de «Ver en Ventas»: la misma de una venta suelta (PropuestaVentaIaHelper::RUTA_VENTAS). */
    const RUTA_VENTAS = 'sale';

    /**
     * Cuánto puede tardar la ejecución de una tarjeta. Lo que no entra queda pendiente y lo hace la tarjeta
     * siguiente: una venta a medias no se deja, y el job no muere con el trabajo adentro. Con el admin
     * esperando 180 s por WhatsApp, esto deja tiempo para contestar.
     */
    const SEGUNDOS_MAXIMOS = 120;

    /**
     * La espera antes de reintentar un renglón que frenó la guarda anti doble clic (SaleController::venta_ya_cread(),
     * ±5 segundos): dos renglones iguales de la misma persona son dos ventas, no un doble clic.
     */
    const SEGUNDOS_DE_LA_GUARDA = 6;

    /**
     * Ejecuta la tarjeta y devuelve su resultado.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array
     *
     * @throws AccionIaException  Si no se pudo cargar ningún renglón, o si la tarjeta ya no vale.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, PropuestaVentaIaHelper::PERMISO)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('ventas'));
        }

        $persona = $contexto->persona;

        if (is_null($persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $persona->id) {

            throw new AccionIaException(500, 'Las ventas no se pueden registrar sin la persona autenticada.');
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $planilla = AiPlanilla::where('id', (int) ($datos['planilla_id'] ?? 0))->where('user_id', (int) $contexto->owner_id)->first();

        if (is_null($planilla)) {

            throw new AccionIaException(422, 'La planilla de esta tarjeta ya no existe. Mandámela de nuevo.');
        }

        $numeros = isset($datos['renglones']) && is_array($datos['renglones']) ? $datos['renglones'] : [];

        /*
         * 🔴 Lo que se confirma tiene que ser lo que se mostró. Si la tabla cambió después de armar la tarjeta, no
         * se ejecuta: la persona dijo que sí a otra cosa. Normalmente ni llega acá (corregir y definir reemplazan
         * la tarjeta), esto es el cinturón.
         */
        if (!isset($datos['firma']) || !hash_equals((string) $datos['firma'], PlanillaIaHelper::firma($planilla, $numeros))) {

            throw new AccionIaException(422, 'La planilla cambió después de armar esta tarjeta. Pedime las ventas de nuevo.');
        }

        $faltan = [];
        $opciones = [];

        $cobro = PlanillaIaHelper::definiciones_de_cobro($contexto, $planilla, $faltan, $opciones);

        if (RespuestaDeCargaIa::es_negativa($cobro)) {

            throw new AccionIaException(422, (string) $cobro['error']);
        }

        if (count($faltan)) {

            throw new AccionIaException(422, 'Falta definir ' . implode('; ', $faltan));
        }

        $inicio = time();

        $cargadas = [];
        $ya = [];
        $no_cargadas = [];
        $sin_intentar = 0;
        $clientes_creados = 0;

        foreach ($numeros as $numero) {

            if (time() - $inicio > self::SEGUNDOS_MAXIMOS) {

                $sin_intentar++;

                continue;
            }

            /* 🔴 El nombre del cliente que se creó, aunque el renglón falle después: el cliente queda, y hay que decirlo. */
            $cliente_creado = null;
            $fila = [];

            try {

                $reserva = self::reservar_renglon($planilla, (int) $numero, $numeros, (string) $datos['firma']);

                if ($reserva['estado'] === 'saltar') {
                    continue;
                }

                if ($reserva['estado'] === 'esperar') {
                    $sin_intentar++;
                    continue;
                }

                $fila = $reserva['fila'];

                if ($reserva['estado'] === 'ya_registrada') {
                    $ya[] = self::linea_de_venta($fila, $planilla);
                    continue;
                }

                $hecho = self::ejecutar_renglon($contexto, $planilla, $fila, $cobro, $inicio, $cliente_creado);

                if ($hecho['ya_estaba']) {

                    $ya[] = self::linea_de_venta($hecho['fila'], $planilla);

                } else {

                    $cargadas[] = self::linea_de_venta($hecho['fila'], $planilla);
                }

            } catch (AccionIaException $e) {

                $no_cargadas[] = self::renglon_que_fallo($planilla, (int) $numero, self::con_el_cliente_creado($e->getMessage(), $cliente_creado), $fila);

            } catch (\Throwable $e) {

                Log::error('EjecucionDePlanillaIaHelper: falló un renglón de una planilla', [
                    'ai_planilla_id' => (int) $planilla->id,
                    'renglon'        => (int) $numero,
                    'error'          => $e->getMessage(),
                ]);

                $no_cargadas[] = self::renglon_que_fallo($planilla, (int) $numero, self::con_el_cliente_creado(EjecutorAccionesIaHelper::MENSAJE_ERROR_GENERICO, $cliente_creado), $fila);
            }

            $clientes_creados += is_null($cliente_creado) ? 0 : 1;
        }

        /* Si no se hizo nada, la tarjeta sigue para confirmar y el motivo lo dice. */
        if (!count($cargadas) && !count($ya) && count($no_cargadas)) {

            $motivos = [];

            foreach ($no_cargadas as $renglon) {

                $motivos[] = 'renglón ' . (int) $renglon['renglon'] . ': ' . $renglon['motivo'];
            }

            throw new AccionIaException(422, 'No se pudo cargar ninguna venta. ' . mb_substr(implode('; ', $motivos), 0, 900));
        }

        $planilla->refresh();

        PlanillaIaHelper::cerrar_si_termino($planilla);

        return self::resultado($planilla, $numeros, $cargadas, $ya, $no_cargadas, $sin_intentar, $clientes_creados);
    }

    /**
     * Reserva antes de cualquier efecto, con la firma y la fila frescas dentro del mismo candado.
     *
     * Dos tarjetas pueden estar EN_CURSO a la vez: la reserva de la tarjeta no reserva sus renglones.
     * Leer en_curso y marcarlo después dejaba pasar a las dos. Además, otra tanda de esta planilla espera
     * mientras hay una fila en curso: así no nacen dos clientes iguales en renglones de tarjetas distintas.
     * El candado se cierra acá; los controllers y sus HTTP corren siempre afuera de esta transacción.
     *
     * @return array{estado: string, fila: array}
     */
    protected static function reservar_renglon(AiPlanilla $planilla, $numero, array $numeros, $firma): array
    {
        return DB::transaction(function () use ($planilla, $numero, $numeros, $firma) {

            $fresca = AiPlanilla::where('id', (int) $planilla->id)->where('user_id', (int) $planilla->user_id)->lockForUpdate()->first();

            if (is_null($fresca)) {
                throw new AccionIaException(422, 'La planilla de esta tarjeta ya no existe. Mandámela de nuevo.');
            }

            if (!hash_equals((string) $firma, PlanillaIaHelper::firma($fresca, $numeros))) {
                throw new AccionIaException(422, 'La planilla cambió después de armar esta tarjeta. Pedime las ventas de nuevo.');
            }

            $fila = PlanillaIaHelper::fila_numero($fresca, (int) $numero);

            if (!count($fila) || $fila['estado'] === AiPlanilla::FILA_DESCARTADA) {
                return ['estado' => 'saltar', 'fila' => $fila];
            }

            if (in_array($fila['estado'], [AiPlanilla::FILA_REGISTRADA, AiPlanilla::FILA_YA_REGISTRADA], true)) {
                return ['estado' => 'ya_registrada', 'fila' => $fila];
            }

            if ($fresca->estado !== AiPlanilla::ESTADO_ACTIVA) {
                throw new AccionIaException(422, 'La planilla de esta tarjeta ya no está activa. Pedime las ventas de nuevo.');
            }

            $filas = is_array($fresca->filas) ? $fresca->filas : [];

            foreach ($filas as $otra) {
                if (PlanillaIaHelper::en_curso($otra)) {
                    return ['estado' => 'esperar', 'fila' => $fila];
                }
            }

            $fila['en_curso'] = time();
            $fila['reserva_token'] = bin2hex(random_bytes(16));

            foreach ($filas as $i => $otra) {
                if ((int) $otra['numero'] === (int) $numero) {
                    $filas[$i] = $fila;
                }
            }

            $fresca->filas = $filas;
            $fresca->save();
            $planilla->filas = $filas;

            return ['estado' => 'reservada', 'fila' => $fila];
        });
    }

    /**
     * Un renglón: mira si ya está, crea el cliente si hace falta y crea la venta. Cada paso queda guardado apenas
     * ocurre: ver el 🔴 de la clase.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  array  $fila  Se actualiza por referencia: un fallo posterior solo puede escribir sobre su propia última copia.
     * @param  array  $cobro
     * @param  int  $inicio
     * @param  string|null  $cliente_creado  Se llena con el nombre, si este renglón creó a su cliente.
     * @return array{fila: array, ya_estaba: bool}
     *
     * @throws AccionIaException
     */
    protected static function ejecutar_renglon(ContextoDeCargaIa $contexto, AiPlanilla $planilla, array &$fila, array $cobro, $inicio, &$cliente_creado): array
    {
        $usadas = [];

        foreach (is_array($planilla->filas) ? $planilla->filas : [] as $otra) {

            if (!empty($otra['venta_id']) && (int) $otra['numero'] !== (int) $fila['numero']) {

                $usadas[] = (int) $otra['venta_id'];
            }
        }

        /* La persona dijo que es otra compra (`cargar_igual`): no se vuelve a marcar. */
        $ya = empty($fila['cargar_igual']) ? PlanillaIaHelper::venta_ya_registrada($contexto, $planilla, $fila, $usadas) : null;

        if (!is_null($ya)) {

            $fila = self::guardar_renglon($planilla, $fila, [
                'estado'       => AiPlanilla::FILA_YA_REGISTRADA,
                'venta_id'     => (int) $ya->id,
                'venta_numero' => (int) $ya->num,
                'motivo'       => null,
                'en_curso'     => null,
                'reserva_token' => null,
            ]);

            return ['fila' => $fila, 'ya_estaba' => true];
        }

        list($cliente, $se_creo) = self::cliente_del_renglon($contexto, $fila);

        if ($se_creo) {

            $cliente_creado = (string) $cliente->name;
        }

        if (!is_null($cliente)) {

            $fila = self::guardar_renglon($planilla, $fila, [
                'cliente_id'     => (int) $cliente->id,
                'cliente_nombre' => (string) $cliente->name,
                'cliente_nuevo'  => false,
            ]);
        }

        $venta = self::crear_la_venta($contexto, $planilla, $fila, $cliente, $cobro, $inicio);

        $fila = self::guardar_renglon($planilla, $fila, [
            'estado'       => AiPlanilla::FILA_REGISTRADA,
            'venta_id'     => (int) ($venta['venta_id'] ?? 0),
            'venta_numero' => (int) ($venta['numero'] ?? 0),
            'total_vendido' => (float) ($venta['total'] ?? 0),
            'motivo'       => null,
            'en_curso'     => null,
            'reserva_token' => null,
        ]);

        return ['fila' => $fila, 'ya_estaba' => false];
    }

    /**
     * El motivo de un renglón que falló, diciendo si el cliente quedó creado.
     *
     * @param  string  $motivo
     * @param  string|null  $cliente_creado
     * @return string
     */
    protected static function con_el_cliente_creado($motivo, $cliente_creado): string
    {
        return is_null($cliente_creado) ? (string) $motivo : (string) $motivo . ' (el cliente ' . $cliente_creado . ' quedó creado)';
    }

    /**
     * El cliente del renglón. Un cliente «nuevo» se vuelve a buscar por su documento antes de crearlo: pudo
     * crearlo el renglón anterior de esta misma planilla (dos compras de la misma persona), un intento anterior
     * que falló después, o alguien a mano.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $fila
     * @return array{0: \App\Models\Client|null, 1: bool}  El cliente (null: venta sin cliente) y si se creó recién.
     *
     * @throws AccionIaException
     */
    protected static function cliente_del_renglon(ContextoDeCargaIa $contexto, array $fila): array
    {
        if (!empty($fila['cliente_id'])) {

            $cliente = Client::where('user_id', (int) $contexto->owner_id)->where('id', (int) $fila['cliente_id'])->first();

            if (is_null($cliente)) {

                throw new AccionIaException(422, 'Ese cliente ya no existe entre los tuyos.');
            }

            return [$cliente, false];
        }

        if (empty($fila['cliente_nuevo'])) {

            return [null, false];
        }

        $documento = isset($fila['valores'][RolesDePlanillaIaHelper::DOCUMENTO]) ? RolesDePlanillaIaHelper::solo_digitos($fila['valores'][RolesDePlanillaIaHelper::DOCUMENTO]) : '';

        if ($documento !== '') {

            $existentes = PlanillaIaHelper::clientes_por_documento($contexto, $documento);

            if (count($existentes) > 1) {

                throw new AccionIaException(422, 'Hay más de un cliente con el documento ' . $documento . '.');
            }

            if (count($existentes) === 1) {

                return [Client::where('id', (int) $existentes[0]->id)->first(), false];
            }

        } else {

            $por_nombre = Client::where('user_id', (int) $contexto->owner_id)
                ->where('name', (string) ($fila['valores'][RolesDePlanillaIaHelper::NOMBRE] ?? ''))
                ->orderBy('id')
                ->limit(2)
                ->get();

            /* Pueden haber nacido homónimos después de mostrar la tarjeta: el primero no identifica a la persona. */
            if ($por_nombre->count() > 1) {
                throw new AccionIaException(422, 'Hay más de un cliente con ese nombre. Decime cuál corresponde antes de cargar la venta.');
            }

            if ($por_nombre->count() === 1) {
                return [$por_nombre->first(), false];
            }
        }

        return [self::crear_cliente($contexto, $fila, $documento), true];
    }

    /**
     * Crea un cliente por el camino del alta genérica, con una tarjeta en memoria. Nombre, documento y dirección de
     * la planilla, y la condición de IVA según lo que pide su columna de comprobante, para que al facturar salga el
     * tipo correcto. 🔴 Sin teléfono: el alta no lo exige (`CatalogoDeEscrituraIaHelper`: el único obligatorio de un
     * cliente es el nombre).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $fila
     * @param  string  $documento
     * @return \App\Models\Client
     *
     * @throws AccionIaException
     */
    protected static function crear_cliente(ContextoDeCargaIa $contexto, array $fila, $documento)
    {
        $declaracion = PropuestaGenericaIaHelper::entidad_para($contexto, 'client', Catalogo::OP_ALTA);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            throw new AccionIaException(422, (string) ($declaracion['error'] ?: 'No se pudo crear el cliente.'));
        }

        $nombre = isset($fila['valores'][RolesDePlanillaIaHelper::NOMBRE]) ? trim((string) $fila['valores'][RolesDePlanillaIaHelper::NOMBRE]) : '';

        $campos = ['name' => $nombre];

        if (strlen($documento) === CuitIaHelper::LARGO_DE_CUIT) {

            $campos['cuit'] = $documento;
            $campos['razon_social'] = $nombre;

        } elseif ($documento !== '') {

            $campos['dni'] = $documento;
        }

        if (isset($fila['valores'][RolesDePlanillaIaHelper::DIRECCION])) {

            $campos['address'] = (string) $fila['valores'][RolesDePlanillaIaHelper::DIRECCION];
        }

        $iva = self::condicion_de_iva($fila);

        if (!is_null($iva['id'])) {

            $campos['iva_condition_id'] = $iva['id'];
        }

        $validado = PropuestaGenericaIaHelper::validar_campos($contexto, $declaracion, Catalogo::OP_ALTA, $campos);

        if (RespuestaDeCargaIa::es_negativa($validado)) {

            throw new AccionIaException(422, (string) ($validado['error'] ?: 'Faltan datos para crear el cliente: ' . implode(', ', $validado['faltan'])));
        }

        $tarjeta = new AiMessageAction();

        $tarjeta->datos = [
            'entidad'   => $declaracion['entidad'],
            'operacion' => Catalogo::OP_ALTA,
            'payload'   => PropuestaGenericaIaHelper::payload_de_alta($declaracion, $validado['payload']),
            'pedidos'   => $validado['pedidos'],
        ];

        $resultado = EjecutorGenericoIaHelper::ejecutar($contexto, $tarjeta);

        $cliente = Client::where('user_id', (int) $contexto->owner_id)->where('id', (int) ($resultado['id'] ?? 0))->first();

        if (is_null($cliente)) {

            throw new AccionIaException(422, 'El cliente no quedó creado.');
        }

        return $cliente;
    }

    /**
     * La condición de IVA con la que nace el cliente de un renglón: su id, o null si la planilla no alcanza para saberla, y
     * un aviso para antes del sí cuando lo que pide no cuadra con el documento. El catálogo es global y se busca por nombre.
     *
     * 🔴 SIN ADIVINAR, PORQUE DESPUÉS SE FACTURA CON ESTO:
     *   - Responsable inscripto solo con CUIT y Factura A. Una Factura A con un DNI no se puede emitir: sin condición, con aviso.
     *   - Consumidor final solo si la planilla lo dice (C FINAL, CF) y el CUIT no es de una sociedad (30, 33, 34): una
     *     empresa no es consumidor final. Con esa combinación, sin condición y con aviso.
     *   - Una Factura B u otra cosa no dice la condición: puede ser monotributista o exento. Sin condición, sin aviso.
     *
     * @param  array  $fila
     * @return array{id: int|null, aviso: string|null}
     */
    public static function condicion_de_iva(array $fila): array
    {
        $comprobante = isset($fila['valores'][RolesDePlanillaIaHelper::COMPROBANTE]) ? $fila['valores'][RolesDePlanillaIaHelper::COMPROBANTE] : '';

        $documento = isset($fila['valores'][RolesDePlanillaIaHelper::DOCUMENTO]) ? RolesDePlanillaIaHelper::solo_digitos($fila['valores'][RolesDePlanillaIaHelper::DOCUMENTO]) : '';

        $con_cuit = strlen($documento) === CuitIaHelper::LARGO_DE_CUIT;

        if (RolesDePlanillaIaHelper::letra_de_comprobante($comprobante) === 'a') {

            if ($con_cuit) {

                return ['id' => self::id_de_la_condicion('Responsable inscripto'), 'aviso' => null];
            }

            return ['id' => null, 'aviso' => $documento === '' ? 'pide Factura A y no trae CUIT' : 'pide Factura A y trae un DNI'];
        }

        if (RolesDePlanillaIaHelper::dice_consumidor_final($comprobante)) {

            if ($con_cuit && in_array(substr($documento, 0, 2), ['30', '33', '34'], true)) {

                return ['id' => null, 'aviso' => 'dice consumidor final y trae el CUIT de una sociedad'];
            }

            return ['id' => self::id_de_la_condicion('Consumidor final'), 'aviso' => null];
        }

        return ['id' => null, 'aviso' => null];
    }

    /**
     * @param  string  $nombre
     * @return int|null
     */
    protected static function id_de_la_condicion($nombre)
    {
        $id = IvaCondition::where('name', $nombre)->value('id');

        return is_null($id) ? null : (int) $id;
    }

    /**
     * Crea la venta de un renglón por el mismo camino que una venta suelta, con el precio dictado (total ÷ cantidad).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  array  $fila
     * @param  \App\Models\Client|null  $cliente
     * @param  array  $cobro
     * @param  int  $inicio
     * @return array  Lo que devolvió PropuestaVentaIaHelper::ejecutar().
     *
     * @throws AccionIaException
     */
    protected static function crear_la_venta(ContextoDeCargaIa $contexto, AiPlanilla $planilla, array $fila, $cliente, array $cobro, $inicio): array
    {
        if ($cobro['cobro'] === PropuestaVentaIaHelper::COBRO_CUENTA_CORRIENTE) {

            $de_la_cuenta = PropuestaVentaIaHelper::resolver_cobro($contexto, $cliente, PropuestaVentaIaHelper::COBRO_CUENTA_CORRIENTE);

            if (RespuestaDeCargaIa::es_negativa($de_la_cuenta)) {

                throw new AccionIaException(422, (string) $de_la_cuenta['error']);
            }
        }

        $lista = PropuestaVentaIaHelper::resolver_lista_de_precios($contexto, $cliente, '');

        if (RespuestaDeCargaIa::es_negativa($lista)) {

            throw new AccionIaException(422, (string) ($lista['error'] ?: 'Falta la lista de precios.'));
        }

        $faltan = [];
        $opciones = [];

        $renglones = PropuestaVentaIaHelper::resolver_renglones(
            $contexto,
            [[
                'articulo_id'     => (int) $fila['articulo_id'],
                'cantidad'        => RolesDePlanillaIaHelper::cantidad_de($fila),
                'precio_unitario' => RolesDePlanillaIaHelper::precio_unitario_de($fila, $planilla->importe_es),
            ]],
            $lista,
            null,
            $faltan,
            $opciones
        );

        if (RespuestaDeCargaIa::es_negativa($renglones)) {

            throw new AccionIaException(422, (string) ($renglones['error'] ?: 'El artículo no se puede vender desde acá.'));
        }

        $totales = PropuestaVentaIaHelper::totales($renglones, null);

        $payload = PropuestaVentaIaHelper::payload(
            $renglones,
            $cliente,
            $cobro['cobro'],
            $cobro['metodo'],
            $cobro['caja'],
            $lista,
            null,
            $cobro['sucursal'],
            $cobro['tipo_de_venta'],
            null,
            self::observaciones($planilla, $fila),
            $totales
        );

        /*
         * La red 2 del docblock de la clase: el mismo renglón, la misma venta. Con `cargar_igual` es otra: si un intento
         * anterior dejó una venta con este identificador, la marca que la persona reabrió no puede volver a devolverla.
         */
        $payload['offline_uuid'] = 'planilla-' . (int) $planilla->id . '-' . (int) $fila['numero'] . (empty($fila['cargar_igual']) ? '' : '-otra');

        $tarjeta = new AiMessageAction();

        $tarjeta->datos = ['payload' => $payload];

        try {

            return PropuestaVentaIaHelper::ejecutar($contexto, $tarjeta);

        } catch (AccionIaException $e) {

            /*
             * La guarda anti doble clic del store: dos renglones de la misma persona por el mismo total, seguidos.
             * No es un doble clic: son dos ventas de la planilla. Se espera y se reintenta una vez, si queda tiempo.
             */
            if ($e->getMessage() !== PropuestaVentaIaHelper::MENSAJE_DUPLICADA || time() - $inicio > self::SEGUNDOS_MAXIMOS) {

                throw $e;
            }

            sleep(self::SEGUNDOS_DE_LA_GUARDA);

            return PropuestaVentaIaHelper::ejecutar($contexto, $tarjeta);
        }
    }

    /**
     * «Planilla N° 12, renglón 3 · 13-oct · Op. #2000000000000003». La fecha de la planilla va acá y no a la fecha de
     * la venta (queda la de hoy: decisión de la misión). Es lo que después permite rastrearla y reconocerla.
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  array  $fila
     * @return string
     */
    protected static function observaciones(AiPlanilla $planilla, array $fila): string
    {
        $partes = ['Planilla N° ' . (int) $planilla->id . ', renglón ' . (int) $fila['numero']];

        if (isset($fila['valores'][RolesDePlanillaIaHelper::FECHA])) {

            $partes[] = (string) $fila['valores'][RolesDePlanillaIaHelper::FECHA];
        }

        if (isset($fila['valores'][RolesDePlanillaIaHelper::OPERACION])) {

            $partes[] = 'Op. #' . ltrim((string) $fila['valores'][RolesDePlanillaIaHelper::OPERACION], '# ');
        }

        return implode(' · ', $partes);
    }

    /**
     * Escribe solo si conserva la reserva y la misma copia de la fila, dentro del candado de la planilla.
     * Una ejecución demorada no puede marcar fallida una venta que otra ya registró ni colgarle su resultado
     * a un renglón corregido. El token distingue ejecuciones incluso si reservaron en el mismo segundo.
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  array  $fila
     * @param  array  $cambios
     * @return array  El renglón como quedó.
     */
    protected static function guardar_renglon(AiPlanilla $planilla, array $fila, array $cambios): array
    {
        return DB::transaction(function () use ($planilla, $fila, $cambios) {

            $fresca = AiPlanilla::where('id', (int) $planilla->id)->lockForUpdate()->first();

            if (is_null($fresca)) {
                throw new AccionIaException(422, 'La planilla ya no existe; no se puede guardar el resultado de este renglón.');
            }

            $filas = is_array($fresca->filas) ? $fresca->filas : [];

            foreach ($filas as $i => $otra) {

                if ((int) $otra['numero'] === (int) $fila['numero']) {

                    if (empty($fila['reserva_token'])
                        || empty($otra['reserva_token'])
                        || !hash_equals((string) $fila['reserva_token'], (string) $otra['reserva_token'])
                        || in_array($otra['estado'], [AiPlanilla::FILA_REGISTRADA, AiPlanilla::FILA_YA_REGISTRADA], true)
                        || self::con_claves_ordenadas($otra) !== self::con_claves_ordenadas($fila)) {

                        throw new AccionIaException(422, 'El renglón cambió o ya lo está terminando otra ejecución. No se pisó su resultado.');
                    }

                    $filas[$i] = array_merge($otra, $cambios);
                    $como_quedo = $filas[$i];

                    $fresca->filas = $filas;
                    $fresca->save();
                    $planilla->filas = $filas;

                    return $como_quedo;
                }
            }

            throw new AccionIaException(422, 'El renglón ya no existe; no se puede guardar su resultado.');
        });
    }

    /**
     * MySQL normaliza el orden de las claves de un objeto JSON. Eso no es una corrección de la fila:
     * se comparan claves ordenadas recursivamente, conservando tipos, valores y orden de las listas.
     *
     * @param  mixed  $valor
     * @return mixed
     */
    protected static function con_claves_ordenadas($valor)
    {
        if (!is_array($valor)) {
            return $valor;
        }

        $es_lista = $valor === [] || array_keys($valor) === range(0, count($valor) - 1);

        foreach ($valor as $clave => $contenido) {
            $valor[$clave] = self::con_claves_ordenadas($contenido);
        }

        if (!$es_lista) {
            ksort($valor, SORT_STRING);
        }

        return $valor;
    }

    /**
     * Deja el renglón `bloqueada` con su motivo y devuelve la línea para informarlo.
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  int  $numero
     * @param  string  $motivo
     * @param  array  $reservada  La copia de esta ejecución: una lectura nueva no le da propiedad sobre la fila.
     * @return array{renglon: int, cliente: string, motivo: string}
     */
    protected static function renglon_que_fallo(AiPlanilla $planilla, $numero, $motivo, array $reservada): array
    {
        if (count($reservada)) {
            try {
                self::guardar_renglon($planilla, $reservada, [
                    'estado' => AiPlanilla::FILA_BLOQUEADA,
                    'motivo' => mb_substr((string) $motivo, 0, 500),
                    'en_curso' => null,
                    'reserva_token' => null,
                ]);
            } catch (AccionIaException $e) {
                /* Perdió la reserva o cambió la fila: el error anterior no puede borrar lo que quedó después. */
            }
        }

        $planilla->refresh();
        $fila = PlanillaIaHelper::fila_numero($planilla, (int) $numero);

        return [
            'renglon' => (int) $numero,
            'cliente' => TextoDePlanillaIaHelper::nombre_del_cliente($fila ?: ['valores' => []]),
            'motivo'  => (string) $motivo,
        ];
    }

    /**
     * Una venta para informar.
     *
     * @param  array  $fila
     * @param  \App\Models\AiPlanilla  $planilla
     * @return array
     */
    protected static function linea_de_venta(array $fila, AiPlanilla $planilla): array
    {
        return [
            'renglon'  => (int) $fila['numero'],
            'cliente'  => TextoDePlanillaIaHelper::nombre_del_cliente($fila),
            'numero'   => (int) ($fila['venta_numero'] ?? 0),
            'venta_id' => (int) ($fila['venta_id'] ?? 0),
            'total'    => isset($fila['total_vendido']) ? (float) $fila['total_vendido'] : (float) RolesDePlanillaIaHelper::total_de($fila, $planilla->importe_es),
        ];
    }

    /**
     * Los artículos de estas filas que quedaron con stock en negativo después de cargar, con su stock. Con nombre: el dueño del
     * caso real contestó «poné el stock de esos en 0», y para eso el mensaje tiene que haber dicho cuáles son.
     *
     * @param  array  $filas
     * @return array<int, array{nombre: string, stock: float}>
     */
    protected static function articulos_en_negativo(array $filas): array
    {
        $ids = [];

        foreach ($filas as $fila) {

            if ($fila['estado'] === AiPlanilla::FILA_REGISTRADA && !empty($fila['articulo_id'])) {

                $ids[] = (int) $fila['articulo_id'];
            }
        }

        if (!count($ids)) {

            return [];
        }

        $en_negativo = [];

        foreach (Article::whereIn('id', array_values(array_unique($ids)))->whereNotNull('stock')->where('stock', '<', 0)->orderBy('name')->get(['name', 'stock']) as $articulo) {

            $en_negativo[] = ['nombre' => (string) $articulo->name, 'stock' => (float) $articulo->stock];
        }

        return $en_negativo;
    }

    /**
     * El resultado de la tarjeta. `texto` es el que pinta la tarjeta y lo que queda en el historial: dice también lo
     * que no se pudo cargar. El texto largo para el dueño lo arma TextoDePlanillaIaHelper::resultado() con esto.
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  array  $numeros
     * @param  array  $cargadas
     * @param  array  $ya
     * @param  array  $no_cargadas
     * @param  int  $sin_intentar
     * @param  int  $clientes_creados
     * @return array
     */
    protected static function resultado(AiPlanilla $planilla, array $numeros, array $cargadas, array $ya, array $no_cargadas, $sin_intentar, $clientes_creados): array
    {
        $total = 0.0;
        $numeros_de_venta = [];

        foreach ($cargadas as $venta) {

            $total += (float) $venta['total'];
            $numeros_de_venta[] = (int) $venta['numero'];
        }

        $partes = [];

        if (count($cargadas)) {

            $partes[] = TextoDePlanillaIaHelper::cuantas_ventas(count($cargadas)) . ' registrada' . (count($cargadas) === 1 ? '' : 's')
                . ' (N° ' . (count($numeros_de_venta) > 1 ? min($numeros_de_venta) . ' a ' . max($numeros_de_venta) : $numeros_de_venta[0]) . ')'
                . ', total ' . FormatoIaHelper::monto($total);
        }

        if (count($ya)) {

            $partes[] = count($ya) . ' ya estaba' . (count($ya) === 1 ? '' : 'n') . ' registrada' . (count($ya) === 1 ? '' : 's');
        }

        if (count($no_cargadas)) {

            $partes[] = count($no_cargadas) . ' no se pud' . (count($no_cargadas) === 1 ? 'o' : 'ieron') . ' cargar';
        }

        if ($sin_intentar > 0) {

            $partes[] = $sin_intentar . ' sin cargar: pedime seguir';
        }

        $filas = [];

        foreach ($numeros as $numero) {

            $fila = PlanillaIaHelper::fila_numero($planilla, (int) $numero);

            if (count($fila)) {

                $filas[] = $fila;
            }
        }

        return [
            'en_negativo'      => self::articulos_en_negativo($filas),
            'texto'            => implode('; ', $partes),
            'ruta'             => [
                'name'   => self::RUTA_VENTAS,
                'params' => new \stdClass(),
                'texto'  => 'Ver en Ventas',
            ],
            'planilla_id'      => (int) $planilla->id,
            'total'            => round($total, 2),
            'ventas'           => $cargadas,
            'ya_registradas'   => $ya,
            'no_cargadas'      => $no_cargadas,
            'sin_intentar'     => (int) $sin_intentar,
            'clientes_creados' => (int) $clientes_creados,
            'sin_facturar'     => TextoDePlanillaIaHelper::sin_facturar($filas),
        ];
    }
}
