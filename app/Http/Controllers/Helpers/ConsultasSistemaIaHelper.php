<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\Sale;
use App\Services\Mostrador\RecolectorBase;
use App\Services\ActividadDeClientes\ActividadDeClientesService;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de LECTURA sobre los datos del negocio, compartidas entre el
 * endpoint AdminSync (SistemaQueryController, canal "sistema:" de WhatsApp)
 * y las tools del asistente de IA (AsistenteIaService).
 *
 * Extraídas del controller (misión chat-ia-y-modulo-ia) para no duplicar las
 * queries: acá vive la consulta, y cada consumidor le pone su cáscara. El
 * shape de salida de cada método es EXACTAMENTE el que el endpoint devolvía
 * (mismas claves, mismos tipos), con una sola excepción declarada: clientes()
 * suma la clave `id`, que la tool de movimientos del chat necesita para
 * encadenar consultas; el controller la quita antes de responder para
 * conservar su contrato byte a byte con admin-api.
 *
 * Todos los métodos filtran por el user_id del DUEÑO y respetan MAX_RESULTS:
 * el que llama es responsable de resolver el owner (nunca se lee Auth acá,
 * porque el chat consulta desde un job sin sesión).
 */
class ConsultasSistemaIaHelper
{
    /**
     * Cantidad máxima de registros que se devuelven por consulta. El mismo
     * tope que siempre tuvo SistemaQueryController: acota el JSON que viaja
     * al prompt de Claude, no el negocio.
     *
     * @var int
     */
    const MAX_RESULTS = 20;

    /**
     * Techo duro de registros por consulta, para las tools que aceptan un límite por parámetro
     * (misión agente-ia-mano-derecha, bloque B).
     *
     * MAX_RESULTS sigue siendo el default de todas; esto es hasta dónde se puede estirar cuando el
     * modelo pide más porque la pregunta lo necesita. Existe porque el bloque de tools y sus
     * respuestas viajan enteros en cada vuelta del loop: sin un techo que no dependa de lo que pida
     * el modelo, una sola consulta se come el presupuesto de tiempo del asistente.
     *
     * @var int
     */
    const TOPE_DURO_DE_RESULTADOS = 100;

    /**
     * Artículos activos del dueño con precio y stock, filtrados por nombre,
     * código de barras o código de proveedor.
     *
     * @param  int     $owner_id  Id del dueño (articles.user_id).
     * @param  string  $busqueda  Texto ya depurado a buscar; vacío trae los primeros sin filtrar.
     * @return array<int, array<string, mixed>>
     */
    public static function stock_de_articulos(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $articles_query = Article::query()
            ->where('user_id', $owner_id)
            ->where('status', 'active');

        // Si hay una palabra clave útil, se filtra por nombre / código de barras / código de proveedor.
        if ($busqueda !== '') {
            $articles_query->where(function ($sub) use ($busqueda) {
                $sub->where('name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $busqueda . '%');
            });
        }

        $articles = $articles_query
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'bar_code', 'provider_code', 'price', 'final_price', 'stock']);

        // Misión asistente-omnisciente (21/9/2026): si el artículo tiene foto, en UNA consulta para
        // todo el lote. Es lo que le permite al modelo ofrecer mostrarla (mostrar_imagenes_de_articulos)
        // o decir que no hay, sin adivinar.
        $con_imagen = self::articulos_con_imagen($articles->pluck('id')->all());

        // Aplanamos a un array simple y legible para Claude.
        $result = [];
        foreach ($articles as $article) {
            $result[] = [
                'id'            => (int) $article->id,
                'nombre'        => (string) $article->name,
                'codigo'        => (string) ($article->bar_code ?? $article->provider_code ?? ''),
                'precio'        => $article->final_price !== null ? (float) $article->final_price : (float) $article->price,
                'stock'         => $article->stock !== null ? (float) $article->stock : 0,
                'tiene_imagen'  => isset($con_imagen[(int) $article->id]),
            ];
        }

        return $result;
    }

    /**
     * Qué artículos de un lote tienen al menos una imagen, en UNA consulta.
     *
     * 🔴 El `imageable_type` es el alias del morph map ('article', ver
     * AppServiceProvider::boot() → Relation::enforceMorphMap), no la clase: con la clase entera la
     * consulta no matchea ninguna fila y todo artículo aparece sin foto. Es exactamente lo que
     * consulta la relación Article::images().
     *
     * @param  array<int, int>  $article_ids
     * @return array<int, bool>  id => true, solo los que tienen imagen
     */
    protected static function articulos_con_imagen(array $article_ids): array
    {
        $article_ids = array_values(array_unique(array_filter(array_map('intval', $article_ids))));

        if (empty($article_ids)) {
            return [];
        }

        $con_imagen = [];

        $ids = DB::table('images')
            ->where('imageable_type', 'article')
            ->whereIn('imageable_id', $article_ids)
            ->distinct()
            ->pluck('imageable_id');

        foreach ($ids as $id) {
            $con_imagen[(int) $id] = true;
        }

        return $con_imagen;
    }

    /**
     * Clientes del dueño con teléfono, email y saldo de cuenta corriente
     * (saldo positivo = el cliente debe), filtrados por nombre.
     *
     * A diferencia del resto, incluye `id`: la tool de movimientos del chat
     * lo necesita para encadenar. El endpoint AdminSync lo quita al responder.
     *
     * 🔴 El saldo sale de credit_accounts (la deuda en pesos) y NO de
     * clients.saldo (misión asistente-ia-acciones, 15/9/2026). clients.saldo es
     * una columna muerta: CurrentAcountHelper ya no la escribe (el saldo vivo
     * se sincroniza en credit_accounts y en clients.saldo_pesos/saldo_dolares),
     * y leyéndola el asistente le decía a la persona "Juan no te debe nada"
     * justo antes de cargarle un pago. Mismas claves y mismos tipos que antes
     * (el canal "sistema:" de admin-api no cambia de forma): solo deja de mentir.
     *
     * @param  int     $owner_id  Id del dueño (clients.user_id).
     * @param  string  $busqueda  Nombre o parte del nombre; vacío trae los primeros.
     * @return array<int, array<string, mixed>>
     */
    public static function clientes(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $clients_query = Client::query()->where('user_id', $owner_id);

        if ($busqueda !== '') {
            $clients_query->where('name', 'LIKE', '%' . $busqueda . '%');
        }

        $clients = $clients_query
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'phone', 'email']);

        $saldos = self::saldos_en_pesos_de_clientes($owner_id, $clients->pluck('id')->all());

        $result = [];
        foreach ($clients as $client) {
            $result[] = [
                'id'        => (int) $client->id,
                'cliente'   => (string) $client->name,
                'telefono'  => (string) ($client->phone ?? ''),
                'email'     => (string) ($client->email ?? ''),
                // Sin cuenta corriente, 0 como cuando la columna vieja venía null.
                'saldo'     => isset($saldos[(int) $client->id]) ? $saldos[(int) $client->id] : 0,
            ];
        }

        return $result;
    }

    /**
     * Consulta base de las cuentas corrientes EN PESOS de los clientes del
     * dueño. Mismo criterio que RecolectorBase::consulta_deudas_en_pesos() del
     * mostrador: la fuente de verdad es credit_accounts.saldo (positivo = el
     * cliente debe) y una moneda_id null se trata como pesos. Una sola regla de
     * moneda para toda deuda que el sistema le cuenta a una IA: si el chat y el
     * informe del mostrador leyeran distinto, darían dos números para la misma
     * deuda.
     *
     * @param  int  $owner_id
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function cuentas_en_pesos_de_clientes(int $owner_id)
    {
        return DB::table('credit_accounts')
            ->where('credit_accounts.user_id', $owner_id)
            ->where('credit_accounts.model_name', 'client')
            ->where(function ($q) {
                /*
                 * 🔴 El criterio de que es pesos sale de RecolectorBase::MONEDAS_PESOS ([0, 1]) y no
                 * de un 1 escrito aca: develop lo corrigio el 15/9 (commit 8ddbac31) porque hay
                 * cuentas con moneda_id = 0 en produccion -las deja un alta donde el select de
                 * moneda no se eligio- y contando solo 1 esa deuda desaparecia. Si el chat y el
                 * informe del mostrador leyeran distinto, darian dos numeros para la misma deuda.
                 */
                $q->whereNull('credit_accounts.moneda_id')->orWhereIn('credit_accounts.moneda_id', RecolectorBase::MONEDAS_PESOS);
            });
    }

    /**
     * Saldo en pesos de un lote de clientes (suma de sus cuentas en pesos, como
     * RecolectorBase::deudas_en_pesos()).
     *
     * @param  int    $owner_id
     * @param  array  $client_ids
     * @return array<int, float>  client_id => saldo
     */
    protected static function saldos_en_pesos_de_clientes(int $owner_id, array $client_ids): array
    {
        $client_ids = array_values(array_unique(array_filter(array_map('intval', $client_ids))));

        if (empty($client_ids)) {
            return [];
        }

        $saldos = [];

        foreach (self::cuentas_en_pesos_de_clientes($owner_id)->whereIn('credit_accounts.model_id', $client_ids)->get(['credit_accounts.model_id', 'credit_accounts.saldo']) as $cuenta) {
            $client_id = (int) $cuenta->model_id;

            if (!isset($saldos[$client_id])) {
                $saldos[$client_id] = 0.0;
            }

            $saldos[$client_id] += (float) ($cuenta->saldo ?: 0);
        }

        return $saldos;
    }

    /**
     * Últimos movimientos de cuenta corriente de UN cliente del dueño:
     * fecha, detalle, debe, haber y saldo, del más nuevo al más viejo.
     *
     * Query nueva de la misión chat-ia-y-modulo-ia (el endpoint AdminSync no
     * la tenía): current_acounts no maneja soft deletes, así que no hay
     * filtro de borrados que aplicar.
     *
     * @param  int  $owner_id   Id del dueño (current_acounts.user_id).
     * @param  int  $client_id  Id del cliente (current_acounts.client_id).
     * @return array<int, array<string, mixed>>
     */
    public static function movimientos_de_cuenta_corriente(int $owner_id, int $client_id): array
    {
        $movimientos = CurrentAcount::query()
            ->where('user_id', $owner_id)
            ->where('client_id', $client_id)
            ->orderBy('created_at', 'DESC')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'detalle', 'description', 'debe', 'haber', 'saldo', 'created_at']);

        $result = [];
        foreach ($movimientos as $movimiento) {
            // detalle es el campo principal; description queda de respaldo para filas viejas.
            $detalle = trim((string) ($movimiento->detalle ?? ''));
            if ($detalle === '') {
                $detalle = trim((string) ($movimiento->description ?? ''));
            }

            $result[] = [
                'fecha'   => $movimiento->created_at ? $movimiento->created_at->format('d/m/Y H:i') : '',
                'detalle' => $detalle,
                'debe'    => $movimiento->debe !== null ? (float) $movimiento->debe : 0,
                'haber'   => $movimiento->haber !== null ? (float) $movimiento->haber : 0,
                'saldo'   => $movimiento->saldo !== null ? (float) $movimiento->saldo : 0,
            ];
        }

        return $result;
    }

    /**
     * LOS MOVIMIENTOS DE CUENTA CORRIENTE DE UN CLIENTE, CON VENTANA, TIPO, ORDEN Y PÁGINA.
     *
     * Es la versión que usa el asistente desde la misión agente-ia-mano-derecha. La de arriba
     * —`movimientos_de_cuenta_corriente()`— queda TAL CUAL estaba: es la forma que ya consumen los
     * tests del helper y el canal "sistema:", y un shape que cambia por abajo es una regresión que
     * no avisa. Acá abajo pasa todo lo que aquélla no podía hacer.
     *
     * 🔴 POR QUÉ EXISTE. Aquélla recibía sólo `client_id` y corría
     * `orderBy('created_at','DESC')->limit(20)`: del más NUEVO al más viejo y sin ventana. En un
     * cliente con veinte pagos recientes, la venta vieja que todavía debe queda afuera de la
     * ventana y el asistente contesta —con total seguridad— que no hay ninguna. Es el caso que
     * originó la misión.
     *
     * 🔴 Y NO FILTRABA POR CUENTA. Un cliente con cuenta en pesos y cuenta en dólares tiene las dos
     * en la misma tabla: sin filtro, las filas se intercalan por fecha y la columna `saldo` —que es
     * el acumulado DE SU CUENTA— salta entre dos cuentas distintas. El resultado no está de más:
     * está mal, y se lee perfectamente bien.
     *
     * ⚠️ `moneda_id = 0` ES PESOS, igual que el 1: el criterio sale de RecolectorBase::MONEDAS_PESOS
     * y no de un `== 1` escrito acá. Hay cuentas en 0 en producción (las deja un alta donde el
     * select de moneda no se eligió) y compararlas contra 1 deja afuera a clientes reales — un
     * defecto que ya costó caro el 15/9/2026.
     *
     * Por defecto, sin `credit_account_id`, se contestan los movimientos EN PESOS. Las cuentas del
     * cliente viajan en `cuentas` para que la siguiente pregunta pueda apuntar a la de dólares.
     *
     * @param  int          $owner_id           Id del dueño (current_acounts.user_id). Nunca Auth.
     * @param  int          $client_id          Id del cliente.
     * @param  string       $tipo               'debe' | 'haber' | 'todos' (por defecto).
     * @param  string|null  $desde              'AAAA-MM-DD' inclusive; null = sin piso.
     * @param  string|null  $hasta              'AAAA-MM-DD' inclusive; null = sin techo.
     * @param  string       $orden              'mas_viejos' | 'mas_nuevos' (por defecto).
     * @param  int          $pagina             1 en adelante.
     * @param  int          $limite             0 = MAX_RESULTS.
     * @param  int|null     $credit_account_id  Cuenta puntual; null = las de pesos.
     * @return array<string, mixed>  Vacío si el cliente no es del dueño o no existe
     */
    public static function movimientos_de_cuenta_corriente_detalle(
        int $owner_id,
        int $client_id,
        string $tipo = 'todos',
        $desde = null,
        $hasta = null,
        string $orden = 'mas_nuevos',
        int $pagina = 1,
        int $limite = 0,
        $credit_account_id = null
    ): array {
        $limite = self::limite_pedido($limite);

        if ($pagina < 1) {
            $pagina = 1;
        }

        $cliente = Client::query()
            ->where('user_id', $owner_id)
            ->where('id', $client_id)
            ->first(['id', 'name']);

        // Mismo criterio que ventas_impagas_de_un_cliente: "no lo encontré" nunca puede leerse
        // como "no tiene movimientos".
        if (is_null($cliente)) {
            return self::no_encontrado(
                'No encontré ningún cliente con el id ' . $client_id . ' en este negocio.',
                'Buscá el cliente con consultar_clientes y volvé a llamar con el id que devuelva.'
            );
        }

        $cuentas = self::cuentas_de_un_cliente($owner_id, (int) $cliente->id);

        $credit_account_id = is_null($credit_account_id) ? null : (int) $credit_account_id;

        $query = CurrentAcount::query()
            ->where('user_id', $owner_id)
            ->where('client_id', (int) $cliente->id);

        if (! is_null($credit_account_id) && $credit_account_id > 0) {
            $query = $query->where('credit_account_id', $credit_account_id);
        } else {
            /*
             * Sin cuenta pedida, los movimientos EN PESOS. La condición va sobre la moneda de la
             * propia fila y no sobre el credit_account_id: hay filas viejas sin cuenta asignada, y
             * filtrar por cuenta las haría desaparecer de una historia que sí existió.
             */
            $query = $query->where(function ($sub) {
                $sub->whereNull('moneda_id')->orWhereIn('moneda_id', RecolectorBase::MONEDAS_PESOS);
            });
        }

        if ($tipo === 'debe') {
            $query = $query->where('debe', '>', 0);
        } elseif ($tipo === 'haber') {
            $query = $query->where('haber', '>', 0);
        } else {
            $tipo = 'todos';
        }

        $desde = self::dia_del_input($desde);
        $hasta = self::dia_del_input($hasta);

        if (! is_null($desde)) {
            $query = $query->whereDate('created_at', '>=', $desde);
        }

        if (! is_null($hasta)) {
            $query = $query->whereDate('created_at', '<=', $hasta);
        }

        $encontrados = (clone $query)->count();

        $direccion = ($orden === 'mas_viejos') ? 'ASC' : 'DESC';

        $movimientos = (clone $query)
            // Desempate por id: created_at se repite entre movimientos de una misma venta, y con
            // LIMIT/OFFSET sobre una clave con empates MySQL puede repetir una fila en dos páginas.
            ->orderBy('created_at', $direccion)
            ->orderBy('id', $direccion)
            ->skip(($pagina - 1) * $limite)
            ->take($limite)
            ->get(['id', 'detalle', 'description', 'debe', 'haber', 'saldo', 'status', 'sale_id', 'credit_account_id', 'moneda_id', 'created_at']);

        $lista = [];

        foreach ($movimientos as $movimiento) {
            // detalle es el campo principal; description queda de respaldo para filas viejas.
            $detalle = trim((string) ($movimiento->detalle ?? ''));
            if ($detalle === '') {
                $detalle = trim((string) ($movimiento->description ?? ''));
            }

            $lista[] = [
                'movimiento_id'     => (int) $movimiento->id,
                'fecha'             => $movimiento->created_at ? $movimiento->created_at->format('d/m/Y H:i') : '',
                'detalle'           => $detalle,
                'debe'              => $movimiento->debe !== null ? (float) $movimiento->debe : 0,
                'haber'             => $movimiento->haber !== null ? (float) $movimiento->haber : 0,
                // Acumulado DE SU CUENTA: sólo se puede leer como una serie si todas las filas son
                // de la misma cuenta, que es lo que garantiza el filtro de arriba.
                'saldo'             => $movimiento->saldo !== null ? (float) $movimiento->saldo : 0,
                'estado'            => (string) $movimiento->status,
                // Para encadenar con consultar_ventas_impagas_de_un_cliente sin adivinar.
                'venta_id'          => is_null($movimiento->sale_id) ? null : (int) $movimiento->sale_id,
                'credit_account_id' => is_null($movimiento->credit_account_id) ? null : (int) $movimiento->credit_account_id,
            ];
        }

        $saldos = self::saldos_en_pesos_de_clientes($owner_id, [(int) $cliente->id]);

        return [
            'cliente'                   => (string) $cliente->name,
            'cliente_id'                => (int) $cliente->id,
            'filtro'                    => [
                'tipo'              => $tipo,
                'desde'             => $desde,
                'hasta'             => $hasta,
                'orden'             => $direccion === 'ASC' ? 'mas_viejos' : 'mas_nuevos',
                'credit_account_id' => $credit_account_id,
                // Qué se contestó cuando no se pidió cuenta: los pesos, no todo mezclado.
                'solo_pesos'        => is_null($credit_account_id) || $credit_account_id <= 0,
            ],
            'cuentas'                   => $cuentas,
            'saldo_en_pesos'            => isset($saldos[(int) $cliente->id]) ? $saldos[(int) $cliente->id] : 0,
            'movimientos_encontrados'   => (int) $encontrados,
            'movimientos_en_esta_lista' => count($lista),
            'pagina'                    => $pagina,
            // Nunca 0: "pagina 1 de 0" no se puede leer, y sin movimientos la unica pagina es la 1.
            'paginas'                   => $limite > 0 ? (int) max(1, ceil($encontrados / $limite)) : 1,
            'movimientos'               => $lista,
        ];
    }

    /**
     * Las cuentas corrientes de un cliente, con su moneda y su saldo.
     *
     * Viajan siempre en la respuesta de movimientos: son la forma de que el asistente sepa que el
     * cliente TIENE una cuenta en dólares sin tener que mezclarla con la de pesos para enterarse.
     *
     * @param  int  $owner_id
     * @param  int  $client_id
     * @return array<int, array<string, mixed>>
     */
    protected static function cuentas_de_un_cliente(int $owner_id, int $client_id): array
    {
        $filas = DB::table('credit_accounts')
            ->where('user_id', $owner_id)
            ->where('model_name', 'client')
            ->where('model_id', $client_id)
            ->orderBy('id')
            ->get(['id', 'moneda_id', 'saldo', 'limite_credito']);

        $resultado = [];

        foreach ($filas as $fila) {
            $es_pesos = is_null($fila->moneda_id) || in_array((int) $fila->moneda_id, RecolectorBase::MONEDAS_PESOS, true);

            $resultado[] = [
                'credit_account_id' => (int) $fila->id,
                'moneda_id'         => is_null($fila->moneda_id) ? null : (int) $fila->moneda_id,
                // moneda_id 0 y 1 son pesos (RecolectorBase::MONEDAS_PESOS); el resto no lo es, y
                // el número viaja al lado para que nadie tenga que adivinar cuál.
                'es_en_pesos'       => $es_pesos,
                'saldo'             => is_null($fila->saldo) ? 0.0 : (float) $fila->saldo,
                'limite_credito'    => is_null($fila->limite_credito) ? null : (float) $fila->limite_credito,
            ];
        }

        return $resultado;
    }

    /**
     * Normaliza una fecha que llegó por parámetro a 'AAAA-MM-DD', o null si no es usable.
     *
     * Un valor que no se puede interpretar devuelve null (= "no hay filtro") en vez de un
     * 01/01/1970 que recortaría la consulta entera sin que nada lo denuncie.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected static function dia_del_input($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        $limpio = trim((string) $valor);

        if ($limpio === '') {
            return null;
        }

        $momento = strtotime($limpio);

        return $momento === false ? null : date('Y-m-d', $momento);
    }

    /**
     * Artículos más vendidos del dueño en una ventana, con las unidades vendidas. Excluye ventas
     * borradas (soft delete de sales) y, desde la misión asistente-omnisciente (21/9/2026), las
     * ventas contenedoras de consolidación AFIP —el mismo criterio que quien_compro_un_articulo():
     * sin él, una consolidación suma las mismas unidades que las ventas que agrupa—.
     *
     * Los ítems de venta viven en la tabla pivot article_purchases
     * (article_id, sale_id, amount, price); se agrupan por artículo sumando cantidades.
     *
     * Misión asistente-omnisciente: `desde`/`hasta` mandan si vienen (inclusivos, por día, sobre la
     * fecha del renglón, que es la de la venta); si no, la ventana de `dias`. Cada fila suma
     * `articulo_id`, `total_en_pesos` y `unidades_sin_precio` con el MISMO criterio de precio que
     * quien_compro_un_articulo(): `article_purchases.price` se llena solo cuando la venta es en pesos
     * (ArticlePurchaseHelper::set_costo_y_price), así que un renglón con `price IS NULL` no vale
     * cero pesos —no entra al total y se cuenta aparte—. Y `tiene_imagen`, en una consulta para el
     * lote. `total_vendido` conserva su nombre: es el contrato del canal "sistema:" de admin-api.
     *
     * @param  int          $owner_id  Id del dueño (articles.user_id / sales.user_id).
     * @param  int          $dias      Ventana de días hacia atrás (si no hay desde/hasta).
     * @param  string|null  $desde     AAAA-MM-DD inclusive; manda sobre `dias`.
     * @param  string|null  $hasta     AAAA-MM-DD inclusive; manda sobre `dias`.
     * @param  int          $limite    0 = MAX_RESULTS.
     * @return array<int, array<string, mixed>>
     */
    public static function mas_vendidos(int $owner_id, int $dias = 30, $desde = null, $hasta = null, int $limite = 0): array
    {
        $limite = self::limite_pedido($limite);

        $query = DB::table('article_purchases')
            ->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $owner_id)
            ->whereNull('sales.deleted_at')
            ->where(function ($q) {
                $q->whereNull('sales.is_consolidacion_facturacion')
                    ->orWhere('sales.is_consolidacion_facturacion', 0);
            });

        $dia_desde = self::dia_del_input($desde);
        $dia_hasta = self::dia_del_input($hasta);

        if (! is_null($dia_desde) || ! is_null($dia_hasta)) {
            // Rango explícito: semiabierto por día, que es lo mismo que DATE(created_at) BETWEEN
            // con el índice usable.
            if (! is_null($dia_desde)) {
                $query->where('article_purchases.created_at', '>=', $dia_desde . ' 00:00:00');
            }

            if (! is_null($dia_hasta)) {
                $query->where('article_purchases.created_at', '<', date('Y-m-d', strtotime($dia_hasta . ' +1 day')) . ' 00:00:00');
            }
        } else {
            $query->where('article_purchases.created_at', '>=', now()->subDays($dias));
        }

        $top = $query
            ->select(
                'articles.id as articulo_id',
                'articles.name as nombre',
                DB::raw('SUM(article_purchases.amount) as total_vendido'),
                /*
                 * 🔴 UN RENGLÓN SIN PRECIO NO VALE CERO PESOS: no entra al total y se cuenta aparte
                 * (ver el docblock y quien_compro_un_articulo()).
                 */
                DB::raw('COALESCE(SUM(CASE WHEN article_purchases.price IS NOT NULL THEN article_purchases.amount * article_purchases.price ELSE 0 END), 0) as total_en_pesos'),
                DB::raw('COALESCE(SUM(CASE WHEN article_purchases.price IS NULL THEN article_purchases.amount ELSE 0 END), 0) as unidades_sin_precio')
            )
            ->groupBy('articles.id', 'articles.name')
            ->orderByDesc('total_vendido')
            ->orderBy('articles.id')
            ->limit($limite)
            ->get();

        $con_imagen = self::articulos_con_imagen($top->pluck('articulo_id')->all());

        $result = [];
        foreach ($top as $row) {
            $result[] = [
                'nombre'              => (string) $row->nombre,
                'total_vendido'       => (float) $row->total_vendido,
                'articulo_id'         => (int) $row->articulo_id,
                'total_en_pesos'      => round((float) $row->total_en_pesos, 2),
                'unidades_sin_precio' => (float) $row->unidades_sin_precio,
                'tiene_imagen'        => isset($con_imagen[(int) $row->articulo_id]),
            ];
        }

        return $result;
    }

    /**
     * Clientes del dueño con saldo pendiente de cobro (deuda en cuenta
     * corriente), ordenados por deuda descendente.
     *
     * 🔴 La deuda sale de credit_accounts en pesos y NO de la columna muerta
     * clients.saldo (misión asistente-ia-acciones, 15/9/2026; ver el docblock
     * de clientes()). Mismas claves y tipos que antes: el endpoint AdminSync
     * que consume admin-api no cambia de forma, solo deja de mentir.
     *
     * @param  int  $owner_id  Id del dueño (clients.user_id).
     * @return array<int, array<string, mixed>>
     */
    public static function clientes_con_saldo_pendiente(int $owner_id): array
    {
        // credit_accounts.saldo positivo = el cliente debe dinero (pendiente de cobro).
        // Los clientes borrados (soft delete) quedan afuera, como con Client::query().
        $filas = self::cuentas_en_pesos_de_clientes($owner_id)
            ->join('clients', 'clients.id', '=', 'credit_accounts.model_id')
            ->where('clients.user_id', $owner_id)
            ->whereNull('clients.deleted_at')
            ->groupBy('clients.id', 'clients.name', 'clients.phone')
            ->havingRaw('SUM(credit_accounts.saldo) > 0')
            ->orderByRaw('SUM(credit_accounts.saldo) DESC')
            ->orderBy('clients.id')
            ->limit(self::MAX_RESULTS)
            ->get(['clients.id', 'clients.name', 'clients.phone', DB::raw('SUM(credit_accounts.saldo) as deuda')]);

        $result = [];
        foreach ($filas as $fila) {
            $result[] = [
                'cliente'           => (string) $fila->name,
                'telefono'          => (string) ($fila->phone ?? ''),
                'saldo_pendiente'   => (float) $fila->deuda,
            ];
        }

        return $result;
    }

    /**
     * Última oferta vigente por (artículo, proveedor) que coincida con la
     * búsqueda (nombre de artículo o de proveedor), la más reciente primero.
     * Tool de lectura del chat de IA (misión sugerencias de compra): la IA
     * la usa para responder a cuánto ofrece un proveedor un artículo, desde
     * el histórico real (provider_price_offers), nunca inventado.
     *
     * @param  int     $owner_id  Id del dueño. Nunca Auth: esta tool corre en un job sin sesión.
     * @param  string  $busqueda  Nombre de artículo o de proveedor; vacío trae lo más reciente.
     * @return array<int, array<string, mixed>>
     */
    public static function precios_de_proveedores(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $query = DB::table('provider_price_offers')
            ->join('articles', 'articles.id', '=', 'provider_price_offers.article_id')
            ->join('providers', 'providers.id', '=', 'provider_price_offers.provider_id')
            ->where('provider_price_offers.user_id', $owner_id);

        if ($busqueda !== '') {
            $query->where(function ($sub) use ($busqueda) {
                $sub->where('articles.name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('providers.name', 'LIKE', '%' . $busqueda . '%');
            });
        }

        // Orden global por fecha (no agrupado por par primero) para que el
        // tope no se gaste entero en el historial de un solo par.
        $filas = $query->orderBy('provider_price_offers.fecha', 'DESC')
            ->orderBy('provider_price_offers.id', 'DESC')
            ->limit(self::MAX_RESULTS)
            ->get(['provider_price_offers.article_id', 'provider_price_offers.provider_id',
                'provider_price_offers.cost', 'provider_price_offers.fecha', 'provider_price_offers.origen',
                'articles.name as articulo_nombre', 'providers.name as proveedor_nombre']);

        // Una sola fila por par (artículo, proveedor): la primera que aparece es la más nueva por el ORDER BY de arriba.
        $vistos = [];
        $result = [];

        foreach ($filas as $fila) {
            $clave = $fila->article_id . '-' . $fila->provider_id;

            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;

            $result[] = [
                'articulo'  => (string) $fila->articulo_nombre,
                'proveedor' => (string) $fila->proveedor_nombre,
                'costo'     => (float) $fila->cost,
                'fecha'     => (string) $fila->fecha,
                'origen'    => (string) $fila->origen,
            ];
        }

        return $result;
    }

    /**
     * Ofertas personalizadas VIGENTES hoy: qué descuento tiene cada cliente
     * sobre cada artículo en la tienda. Tool de lectura del chat de IA (misión
     * motor de ofertas): la IA responde "qué le estoy ofreciendo a Fulano"
     * desde client_offers, nunca inventado.
     *
     * 🔴 La vigencia la da la FECHA, no solo `estado`. Una oferta cuyo `hasta`
     * ya pasó sigue con estado 'activa' hasta que el barrido de higiene de
     * ofertas:generar la marque 'vencida' (puede tardar hasta un día). Filtrar
     * solo por estado le haría contar al chat ofertas que la tienda ya no
     * aplica — es exactamente la misma condición que corre la tienda (query
     * textual en el docblock de 2026_08_17_100200_create_client_offers_table).
     *
     * Los tramos por cantidad NO se traen: `porcentaje` es null en ese caso
     * (por diseño, para que nadie lea el campo equivocado) y se informa el tipo
     * de descuento, que es lo que la IA necesita para contestar sin mentir un
     * número que depende de cuántas unidades lleve el cliente.
     *
     * @param  int     $owner_id  Id del dueño (client_offers.user_id). Nunca Auth: corre en un job sin sesión.
     * @param  string  $busqueda  Nombre de cliente o de artículo; vacío trae las que vencen primero.
     * @return array<int, array<string, mixed>>
     */
    public static function ofertas_activas(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);
        $hoy = date('Y-m-d');

        $query = DB::table('client_offers')
            ->join('clients', 'clients.id', '=', 'client_offers.client_id')
            ->join('articles', 'articles.id', '=', 'client_offers.article_id')
            ->where('client_offers.user_id', $owner_id)
            ->where('client_offers.estado', 'activa')
            ->where('client_offers.desde', '<=', $hoy)
            ->where('client_offers.hasta', '>=', $hoy);

        if ($busqueda !== '') {
            $query->where(function ($sub) use ($busqueda) {
                $sub->where('clients.name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('articles.name', 'LIKE', '%' . $busqueda . '%');
            });
        }

        // Primero las que se vencen antes: es lo urgente y lo que el
        // comerciante suele estar preguntando.
        $filas = $query->orderBy('client_offers.hasta', 'ASC')
            ->orderBy('client_offers.id', 'ASC')
            ->limit(self::MAX_RESULTS)
            ->get(['client_offers.id', 'client_offers.tipo_descuento', 'client_offers.porcentaje',
                'client_offers.desde', 'client_offers.hasta', 'client_offers.notificada_email_at',
                'clients.name as cliente_nombre', 'articles.name as articulo_nombre']);

        $result = [];

        foreach ($filas as $fila) {
            $result[] = [
                'cliente'        => (string) $fila->cliente_nombre,
                'articulo'       => (string) $fila->articulo_nombre,
                'tipo_descuento' => (string) $fila->tipo_descuento,
                // null real cuando es 'cantidad': el descuento vive en los tramos.
                'porcentaje'     => $fila->porcentaje !== null ? (float) $fila->porcentaje : null,
                'desde'          => (string) $fila->desde,
                'hasta'          => (string) $fila->hasta,
                'notificada'     => !is_null($fila->notificada_email_at),
            ];
        }

        return $result;
    }

    /**
     * Qué estuvo haciendo un cliente en la tienda online: qué artículos miró y cuánto tiempo, qué
     * buscó (marcando lo que buscó y NO encontró), qué puso en el carrito y qué cerró. Tool de
     * lectura del chat de IA (misión actividad-de-clientes-y-oferta-por-whatsapp).
     *
     * El dato sale del tracking de la tienda (`buyer_tracking_events`) a través de
     * ActividadDeClientesService, que es quien sabe de qué tabla leer según la antigüedad pedida y
     * quien SUMA los varios compradores que un mismo cliente del ERP puede tener
     * (`buyers.comercio_city_client_id`, mismo criterio que CriteriosDeOfertaService:408-412). Acá
     * no se toca el tracking por afuera de ese servicio: una segunda forma de leer las mismas
     * tablas es una segunda verdad, y el día que una cambie las dos van a contestar distinto sin
     * que nada lo denuncie.
     *
     * 🔴 LA RESPUESTA NO ES SÓLO LA LISTA: LLEVA LOS TOTALES ADELANTE, Y ESO ES UN ARREGLO Y NO UN
     * ADORNO. La lista está topeada por MAX_RESULTS, así que un cliente que miró 40 artículos entra
     * acá con 17 filas de vistas — y la IA, que no tiene ninguna otra cifra, contesta "miró 17". Es
     * el número de la PANTALLA informado como número del negocio: no hay error, hay un dato de la
     * herramienta haciéndose pasar por un dato del cliente. Los totales ya venían calculados y a
     * mano; lo único que faltaba era mandarlos. Y viajan también `movimientos_encontrados` y
     * `movimientos_en_esta_lista`, que es la forma de que "esto está recortado" se pueda leer.
     *
     * 🔴 Todas las filas de `movimientos` llevan SIEMPRE las mismas siete claves, con null donde no
     * aplica. Un JSON con filas de forma distinta según el tipo obliga a Claude a adivinar el shape,
     * y adivina mal.
     *
     * 🔴 `ultima_vez` en null es "no lo sé", NUNCA "no pasó". Las tres filas de resumen (compró /
     * empezó a comprar / sacó del carrito) y la del carrito por artículo sacan su momento de la
     * línea de tiempo, que está topeada en ActividadDeClientesService::MAX_EVENTOS_LINEA_DE_TIEMPO:
     * un evento más viejo que ese tope se informa sin fecha en vez de con una inventada.
     *
     * 🔴 Los minutos van SOLO en la fila 'vio'. El lector suma el dwell del artículo entero (lo que
     * miró más lo que carreteó) en un único total, así que repetirlo en la fila 'carrito' lo
     * contaría dos veces.
     *
     * 🔴 Los minutos salen de ActividadDeClientesService::a_minutos(), que redondea PARA ABAJO. No
     * se calculan acá con un round(): la convención de minutos vive en un solo lugar y su otra punta
     * es la pantalla (ver el docblock de ese método).
     *
     * El orden de las filas es de más a menos accionable, porque MAX_RESULTS corta la cola: lo que
     * cerró, lo que dejó por la mitad, lo que buscó sin encontrar, y recién después el detalle
     * largo de lo que miró.
     *
     * @param  int  $owner_id   Id del dueño. Nunca Auth: esta tool corre en un job sin sesión.
     * @param  int  $client_id  Id del cliente del ERP, tal como lo devolvió consultar_clientes.
     * @param  int  $dias       Ventana hacia atrás; un valor fuera de la lista blanca cae a 30.
     * @return array<string, mixed> Vacío si el cliente no hizo nada en la ventana
     */
    public static function actividad_de_un_cliente(int $owner_id, int $client_id, int $dias): array
    {
        $service = new ActividadDeClientesService($owner_id);

        /*
         * Un valor fuera de la lista blanca cae al default en vez de cambiar de tabla por lo bajo:
         * con $dias = 0 el servicio contesta desde el agregado, que no tiene hora ni línea de
         * tiempo, y esta tool quedaría sin ninguno de los momentos que informa.
         */
        if (! ActividadDeClientesService::es_periodo_valido($dias) || $dias <= 0) {
            $dias = 30;
        }

        /*
         * La tenencia la da la resolución de compradores (buyers.user_id = $owner_id): un cliente
         * de otro comercio devuelve lista vacía, y con lista vacía el bloque de actividad vuelve
         * en cero SIN tocar el tracking.
         */
        $actividad = $service->actividad($service->buyer_ids_de_un_cliente($client_id), $dias);

        if (! $actividad['hay_datos']) {
            return [];
        }

        // Un strtotime que falla no puede terminar en un 01/01/1970 con cara de fecha real.
        $fecha = function ($valor) {
            if (is_null($valor) || $valor === '') {
                return null;
            }

            $momento = strtotime($valor);

            return $momento === false ? null : date('d/m/Y', $momento);
        };

        /*
         * El momento más nuevo por tipo de evento, y por (tipo, artículo) cuando lo hay. La línea
         * de tiempo ya viene del más nuevo al más viejo, así que el primero que aparece gana.
         */
        $ultimo_de = [];

        foreach ($actividad['linea_de_tiempo'] as $evento) {
            $claves = [$evento['tipo']];

            if (! is_null($evento['article_id'])) {
                $claves[] = $evento['tipo'] . ':' . $evento['article_id'];
            }

            foreach ($claves as $clave) {
                if (! isset($ultimo_de[$clave])) {
                    $ultimo_de[$clave] = $evento['cuando'];
                }
            }
        }

        $momento_de = function ($clave) use ($ultimo_de, $fecha) {
            return $fecha(isset($ultimo_de[$clave]) ? $ultimo_de[$clave] : null);
        };

        // Las siete claves se arman en un solo lugar para que ninguna fila salga con una de menos.
        $armar = function ($tipo, $detalle, $veces, $segundos, $resultados, $monto, $ultima_vez) {
            return [
                'tipo'       => $tipo,
                'detalle'    => $detalle,
                'veces'      => (int) $veces,
                'minutos'    => ActividadDeClientesService::a_minutos($segundos),
                'resultados' => is_null($resultados) ? null : (int) $resultados,
                'monto'      => (float) $monto,
                'ultima_vez' => $ultima_vez,
            ];
        };

        $totales = $actividad['totales'];
        $filas   = [];

        /*
         * 🔴 QUÉ COMPRÓ, POR ARTÍCULO. Acá había UNA fila 'compro' con `detalle => null` y el total
         * de compras adentro, mientras la descripción de la tool le prometía a Claude "qué compró":
         * un null en la clave que contesta la pregunta es mentir por omisión, porque no se lee como
         * "no lo sé" sino como "no hay nada que decir". El detalle por artículo ahora existe
         * (`articulos[].comprados`) y va fila por fila.
         */
        foreach ($actividad['articulos'] as $articulo) {
            if ($articulo['comprados'] > 0) {
                $filas[] = $armar('compro', $articulo['nombre'], $articulo['comprados'], 0, null, 0, $momento_de('checkout_complete:' . $articulo['article_id']));
            }
        }

        /*
         * 🔴 Y lo que NO se pudo atribuir se dice con todas las letras, en vez de quedar como un
         * hueco entre el total de compras y la suma de las filas de arriba. El evento de checkout no
         * garantiza traer `article_id`, así que estas compras existen y el tracking no sabe de qué
         * fueron: sin esta fila, un artículo sin fila 'compro' se leería como "no lo compró".
         */
        if ($totales['compras_sin_articulo'] > 0) {
            $filas[] = $armar(
                'compro',
                'compras que la tienda no informo de que articulo eran',
                $totales['compras_sin_articulo'],
                0,
                null,
                0,
                $momento_de('checkout_complete')
            );
        }

        if ($totales['checkouts_empezados'] > 0) {
            $filas[] = $armar('empezo_a_comprar', null, $totales['checkouts_empezados'], 0, null, 0, $momento_de('checkout_start'));
        }

        if ($totales['quitados_del_carrito'] > 0) {
            $filas[] = $armar('saco_del_carrito', null, $totales['quitados_del_carrito'], 0, null, 0, $momento_de('cart_remove'));
        }

        /*
         * 🔴 Primero lo que buscó y NO encontró: es el dato más accionable que hay (hay demanda y
         * no hay oferta), así que no puede ser lo primero que se coma el tope. Adentro de cada
         * grupo se respeta el orden del lector, que ya viene por veces descendente.
         */
        foreach ([true, false] as $sin_resultado) {
            foreach ($actividad['busquedas'] as $busqueda) {
                if ($busqueda['sin_resultado'] !== $sin_resultado) {
                    continue;
                }

                $filas[] = $armar('busco', $busqueda['termino'], $busqueda['veces'], 0, $busqueda['resultados'], 0, $fecha($busqueda['ultima_vez']));
            }
        }

        foreach ($actividad['articulos'] as $articulo) {
            if ($articulo['agregados_al_carrito'] > 0) {
                // El momento sale de la línea de tiempo y no del artículo: el `ultima_vez` del
                // artículo es la última vez que anduvo con él de cualquier forma, y usarlo acá
                // diría "lo puso en el carrito" un día en que solamente lo miró.
                $filas[] = $armar('carrito', $articulo['nombre'], $articulo['agregados_al_carrito'], 0, null, 0, $momento_de('cart_add:' . $articulo['article_id']));
            }
        }

        foreach ($actividad['articulos'] as $articulo) {
            if ($articulo['vistas'] > 0) {
                $filas[] = $armar('vio', $articulo['nombre'], $articulo['vistas'], $articulo['tiempo_segundos'], null, 0, $fecha($articulo['ultima_vez']));
            }
        }

        /*
         * 🔴 MAX_RESULTS recorta la LISTA, nunca los totales. Los dos contadores de abajo son lo que
         * le permite a la IA decir "de los 42 movimientos te muestro los 20 más accionables" en vez
         * de contestar 20 como si fueran todos.
         */
        $mostradas = array_slice($filas, 0, self::MAX_RESULTS);

        return [
            'ventana_dias'              => $dias,
            'totales'                   => self::totales_de_actividad($actividad),
            'movimientos_encontrados'   => count($filas),
            'movimientos_en_esta_lista' => count($mostradas),
            'movimientos'               => $mostradas,
        ];
    }

    /**
     * Los totales del lector, tal cual los calculó, más los minutos con la convención única.
     *
     * 🔴 Acá no se recalcula NADA: se copian las claves que ya vienen hechas. Un total recalculado
     * en el camino es cómo se llega a que la pantalla y el chat contesten distinto sobre el mismo
     * cliente, que es justo el defecto que este bloque vino a arreglar.
     *
     * @param  array $actividad
     * @return array<string, mixed>
     */
    protected static function totales_de_actividad(array $actividad): array
    {
        $totales = $actividad['totales'];

        return [
            'vistas'                  => (int) $totales['vistas'],
            'articulos_distintos'     => (int) $totales['articulos_distintos'],
            'minutos_mirando'         => ActividadDeClientesService::a_minutos($totales['tiempo_total_segundos']),
            'busquedas'               => (int) $totales['busquedas'],
            'busquedas_sin_resultado' => (int) $totales['busquedas_sin_resultado'],
            'agregados_al_carrito'    => (int) $totales['agregados_al_carrito'],
            'quitados_del_carrito'    => (int) $totales['quitados_del_carrito'],
            'checkouts_empezados'     => (int) $totales['checkouts_empezados'],
            'compras'                 => (int) $totales['compras'],
            /*
             * Cuántas de esas compras el tracking no pudo atribuir a un artículo. Va SIEMPRE, aunque
             * sea 0: la IA tiene que poder distinguir "no compró ese artículo" de "compró y no
             * sabemos qué".
             */
            'compras_sin_articulo'    => (int) $totales['compras_sin_articulo'],
            'monto_comprado'          => (float) $totales['monto_comprado'],
            'ultima_actividad'        => $totales['ultima_actividad'],
        ];
    }

    /**
     * Quién anduvo mirando o carreteando un artículo en la tienda online y TODAVÍA NO LO COMPRÓ.
     * Tool de lectura del chat de IA (misión actividad-de-clientes-y-oferta-por-whatsapp): la IA
     * contesta "a quién le puedo ofrecer esto" con gente que de verdad lo miró, nunca inventada.
     *
     * El dato sale de `buyer_tracking_events` a través de ActividadDeClientesService, y el descarte
     * de "ya lo compró" lo hace ese servicio contra las DOS compras que hay: `article_purchases`
     * (las ventas confirmadas del ERP, con el filtro de ventas reales del motor de ofertas) y el
     * `checkout_complete` del propio tracking. Con sólo la primera la lista mentía, porque una
     * compra hecha en la tienda entra al ERP recién cuando el comerciante la confirma A MANO.
     *
     * 🔴 "TODAVÍA NO LO COMPRARON" es lo mejor que se sabe, no una certeza, y la descripción de la
     * tool lo dice así. Un checkout sin `article_id` no se puede atribuir, y una compra hecha por
     * fuera de la tienda (mostrador) tampoco aparece hasta que se factura.
     *
     * 🔴 Los visitantes ANÓNIMOS no son una FILA de la lista —sin cliente asociado no hay a quién
     * nombrar ni a quién llamar, y una fila sin nombre no sirve para nada—, pero SÍ se cuentan
     * aparte. Que no aparecieran en ningún lado hacía que un artículo mirado por quince visitantes
     * sin cuenta le llegara a la IA como una lista vacía, y la IA contestara "no lo está mirando
     * nadie": falso, y encima manda a no hacer nada.
     *
     * 🔴 La respuesta dice de QUÉ artículo está hablando. La búsqueda es difusa y puede matchear más
     * de uno: sin el nombre resuelto adentro, la IA contestaría con total seguridad sobre un
     * artículo que no es el que le preguntaron. Mismo criterio que precios_de_proveedores().
     *
     * @param  int     $owner_id  Id del dueño. Nunca Auth: esta tool corre en un job sin sesión.
     * @param  string  $busqueda  Nombre (o parte), código de barras o código de proveedor.
     * @param  int     $dias      Ventana hacia atrás; un valor fuera de la lista blanca cae a 30.
     * @return array<string, mixed> Vacío si la búsqueda no resolvió ningún artículo del dueño
     */
    public static function interesados_en_un_articulo(int $owner_id, string $busqueda, int $dias): array
    {
        $busqueda = trim($busqueda);

        /*
         * Sin búsqueda no hay pregunta que contestar. Traer "el primer artículo del catálogo" y
         * listar a sus interesados sería una respuesta perfectamente plausible sobre algo que nadie
         * preguntó, que es la peor forma de estar equivocado.
         */
        if ($busqueda === '') {
            return [];
        }

        if (! ActividadDeClientesService::es_periodo_valido($dias) || $dias <= 0) {
            $dias = 30;
        }

        $elegido = self::resolver_articulo($owner_id, $busqueda);

        if (is_null($elegido)) {
            return [];
        }

        $service = new ActividadDeClientesService($owner_id);
        /*
         * La tenencia del artículo la vuelve a controlar el servicio: sin artículo del dueño, lista
         * vacía y no se toca el tracking. Y el servicio devuelve TODOS los que pasaron el descarte
         * de "ya lo compró": el tope de la respuesta lo pone MAX_RESULTS acá, que es el único lugar
         * donde se sabe cuánto JSON aguanta el prompt.
         */
        $interesados = $service->interesados_en_un_articulo((int) $elegido->id, $dias);
        $anonimos    = $service->anonimos_de_un_articulo((int) $elegido->id, $dias);

        $lista = [];

        foreach (array_slice($interesados, 0, self::MAX_RESULTS) as $fila) {
            $momento = is_null($fila['ultima_vez']) ? false : strtotime($fila['ultima_vez']);

            $compra = is_null($fila['ultima_compra']) ? false : strtotime($fila['ultima_compra']);

            $lista[] = [
                'cliente'    => (string) $fila['cliente'],
                'telefono'   => (string) $fila['telefono'],
                'vistas'     => (int) $fila['vistas'],
                'minutos'    => ActividadDeClientesService::a_minutos($fila['segundos']),
                'al_carrito' => (int) $fila['al_carrito'],
                'ultima_vez' => $momento === false ? null : date('d/m/Y', $momento),
                /*
                 * 🔴 El que está en la lista HABIENDO COMPRADO antes lo dice. Queda porque volvió a
                 * mirarlo después de comprarlo —o sea que hay interés de recompra—, pero mudo abajo
                 * de "todavía no lo compraron" es contarle al comerciante lo contrario de lo que
                 * pasó. null es "no hay ninguna compra registrada", que es el caso normal.
                 */
                'lo_compro_antes_y_lo_volvio_a_mirar' => $compra === false ? null : date('d/m/Y', $compra),
            ];
        }

        return [
            'articulo'                   => (string) $elegido->name,
            'ventana_dias'               => $dias,
            'interesados_encontrados'    => count($interesados),
            'interesados_en_esta_lista'  => count($lista),
            'interesados'                => $lista,
            // Gente sin cuenta que anduvo sobre el mismo artículo. No se puede llamar a ninguno,
            // pero "lo están mirando 15 personas que no puedo nombrar" es un dato del negocio.
            'visitantes_anonimos'        => (int) $anonimos['visitantes'],
            'eventos_de_anonimos'        => (int) $anonimos['eventos'],
        ];
    }

    /**
     * Resuelve UN artículo del dueño a partir de lo que escribió la persona en el chat.
     *
     * Estaba escrito adentro de interesados_en_un_articulo() y se extrajo tal cual (misión
     * agente-ia-mano-derecha, bloque B): tres consultas nuevas eligen artículo igual que aquélla, y
     * una segunda forma de elegir es una segunda verdad — el día que una cambie, dos tools
     * contestarían sobre artículos distintos con la misma pregunta y nada lo denunciaría.
     *
     * 🔴 EL MATCH EXACTO SE BUSCA CONTRA LA BASE Y NO ADENTRO DE LOS CANDIDATOS. Antes se traían
     * 20 artículos ordenados por nombre y recién ahí se buscaba el nombre exacto: si el exacto
     * era el 21º alfabéticamente, quedaba afuera del tope y ganaba otro — con el docblock
     * prometiendo lo contrario. Y esto elige UN SOLO artículo para contestar, así que elegir mal
     * no devuelve de menos: devuelve la respuesta de otra cosa, con total seguridad y sin nada
     * que lo denuncie.
     *
     * @param  int     $owner_id  Id del dueño (articles.user_id).
     * @param  string  $busqueda  Nombre (o parte), código de barras o código de proveedor.
     * @return Article|null  null cuando la búsqueda viene vacía o no matchea ningún artículo del dueño
     */
    protected static function resolver_articulo(int $owner_id, string $busqueda)
    {
        $busqueda = trim($busqueda);

        /*
         * Sin búsqueda no hay pregunta que contestar. Traer "el primer artículo del catálogo" y
         * contestar sobre él sería una respuesta perfectamente plausible sobre algo que nadie
         * preguntó, que es la peor forma de estar equivocado.
         */
        if ($busqueda === '') {
            return null;
        }

        // Las columnas van fijas acá y no las elige el que llama: así ningún consumidor se lleva
        // un artículo a medio hidratar y después lee null donde hay dato.
        $columnas = ['id', 'name', 'bar_code', 'provider_code', 'stock', 'price', 'final_price'];

        $elegido = Article::query()
            ->where('user_id', $owner_id)
            ->where('name', $busqueda)
            ->orderBy('id')
            ->first($columnas);

        if (! is_null($elegido)) {
            return $elegido;
        }

        /*
         * 🔴 Los comodines del LIKE se escapan. Molde y porqué:
         * CriteriosDeOfertaService::articulos_que_matchean(). Un término real como "50%" o
         * "cable_2" los trae adentro, y sin escaparlos "50%" matchea todo lo que empieza con 50.
         * Acá pesa más que en el motor de ofertas: allá un comodín trae artículos de más, acá
         * CAMBIA SOBRE CUÁL ARTÍCULO se contesta.
         */
        $escapado = addcslashes($busqueda, '%_\\');

        $candidatos = Article::query()
            ->where('user_id', $owner_id)
            ->where(function ($sub) use ($escapado) {
                $sub->where('name', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $escapado . '%');
            })
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get($columnas);

        if ($candidatos->isEmpty()) {
            return null;
        }

        // Sin match exacto en la base, el primero por nombre. Antes de eso, el mismo nombre
        // ignorando mayúsculas y acentos, que es como lo escribe una persona en el chat.
        foreach ($candidatos as $candidato) {
            if (self::normalize_text((string) $candidato->name) === self::normalize_text($busqueda)) {
                return $candidato;
            }
        }

        return $candidatos->first();
    }

    /**
     * Resuelve UN proveedor del dueño a partir de lo que escribió la persona. Mismo criterio que
     * resolver_articulo(): exacto contra la base primero, después LIKE con los comodines escapados
     * y desempate por nombre normalizado. Busca por nombre, razón social y CUIT, que es como la
     * persona nombra a un proveedor.
     *
     * @param  int     $owner_id  Id del dueño (providers.user_id).
     * @param  string  $busqueda
     * @return Provider|null
     */
    protected static function resolver_proveedor(int $owner_id, string $busqueda)
    {
        $busqueda = trim($busqueda);

        if ($busqueda === '') {
            return null;
        }

        $columnas = ['id', 'name', 'razon_social', 'cuit', 'phone', 'email'];

        $elegido = Provider::query()
            ->where('user_id', $owner_id)
            ->where('name', $busqueda)
            ->orderBy('id')
            ->first($columnas);

        if (! is_null($elegido)) {
            return $elegido;
        }

        $escapado = addcslashes($busqueda, '%_\\');

        $candidatos = Provider::query()
            ->where('user_id', $owner_id)
            ->where(function ($sub) use ($escapado) {
                $sub->where('name', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('razon_social', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('cuit', 'LIKE', '%' . $escapado . '%');
            })
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get($columnas);

        if ($candidatos->isEmpty()) {
            return null;
        }

        foreach ($candidatos as $candidato) {
            if (self::normalize_text((string) $candidato->name) === self::normalize_text($busqueda)) {
                return $candidato;
            }
        }

        return $candidatos->first();
    }

    /**
     * "No encontré sobre qué contestar", dicho de forma que no se pueda leer como una respuesta.
     *
     * 🔴 POR QUÉ NO ALCANZA CON DEVOLVER VACÍO. Una lista vacía es indistinguible de "no hay nada
     * que informar", y las dos cosas se contestan MUY distinto: "Fulano no te debe nada" es una
     * afirmación sobre la plata del comerciante, y decirla porque no encontramos a Fulano es una
     * respuesta falsa dicha con total seguridad. Lo mismo con un artículo que nadie compró contra
     * un artículo que no existe.
     *
     * Misma forma que los errores de CatalogoDeDatosIaHelper: `error` con el motivo y `como_sigo`
     * con la salida, para que el modelo pueda corregir sin gastar una vuelta preguntando.
     *
     * @param  string  $motivo
     * @param  string  $como_sigo
     * @return array<string, string>
     */
    protected static function no_encontrado(string $motivo, string $como_sigo): array
    {
        return [
            'error'     => $motivo,
            'como_sigo' => $como_sigo,
        ];
    }

    /**
     * Normaliza el límite que pidió el que llama.
     *
     * 0 (o negativo) = "el de siempre", MAX_RESULTS. Un pedido más grande se acepta hasta
     * TOPE_DURO_DE_RESULTADOS y ahí se corta: el tope existe para que el JSON de una tool no se
     * coma el presupuesto de tiempo del asistente, así que no puede depender de lo que pida el
     * modelo.
     *
     * Es público porque la consulta genérica (CatalogoDeDatosIaHelper) normaliza su límite con
     * ESTE método y no con una copia: dos copias del mismo techo se desincronizan sin que nada lo
     * denuncie, y la que quede alta es la que se come el presupuesto.
     *
     * @param  int  $limite
     * @return int
     */
    public static function limite_pedido(int $limite): int
    {
        if ($limite <= 0) {
            return self::MAX_RESULTS;
        }

        if ($limite > self::TOPE_DURO_DE_RESULTADOS) {
            return self::TOPE_DURO_DE_RESULTADOS;
        }

        return $limite;
    }

    /**
     * Fecha de un Carbon (o null) en el formato que lee la persona. Un null es "no hay fecha", no
     * un 01/01/1970 con cara de fecha real.
     *
     * @param  mixed  $valor
     * @param  string $formato
     * @return string|null
     */
    protected static function fecha_legible($valor, string $formato = 'd/m/Y')
    {
        if (is_null($valor) || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format($formato);
        }

        $momento = strtotime((string) $valor);

        return $momento === false ? null : date($formato, $momento);
    }

    /**
     * LAS VENTAS QUE UN CLIENTE TODAVÍA NO PAGÓ, DE LA MÁS VIEJA A LA MÁS NUEVA.
     *
     * Es el caso que originó la misión agente-ia-mano-derecha: "¿cuál es la venta más vieja que me
     * debe Tucumana?". Hasta hoy el asistente lo intentaba con los movimientos de cuenta corriente,
     * que venían del más NUEVO al más viejo y topeados en 20: en un cliente con muchos pagos, la
     * venta vieja quedaba fuera de la ventana y el asistente contestaba que no había ninguna.
     *
     * 🔴 EL RECORTE NO SE REESCRIBE ACÁ: sale de VentasSinCobrarHelper::query_de_ventas(), que es
     * la misma query del listado de ventas sin cobrar de la pantalla y del recordatorio de cobro
     * por WhatsApp. Una segunda definición de "venta impaga" es el camino más corto a que la
     * pantalla y el chat le den dos respuestas distintas al mismo comerciante.
     *
     * ⚠️ Qué implica reusarla, dicho con todas las letras: la query pide que la venta tenga
     * `COALESCE(dias_alerta_venta_no_cobrada_personalizado, $dias)` días de antigüedad. Con el
     * $dias = 0 por defecto eso es "todas", salvo para una venta que tenga SU PROPIO umbral
     * cargado, que recién aparece cuando ese umbral se cumple. Es el criterio del sistema, no uno
     * inventado acá.
     *
     * 🔴 El orden por defecto es de la MÁS VIEJA a la más nueva, y no es un detalle de gusto: la
     * pregunta que hay que poder contestar en una sola vuelta es "la más vieja". Y por si el modelo
     * pide otro orden, `venta_impaga_mas_vieja` viaja igual, calculada aparte contra la query
     * entera y no contra la página.
     *
     * @param  int     $owner_id   Id del dueño (sales.user_id). Nunca Auth: esta tool corre en un job sin sesión.
     * @param  int     $client_id  Id del cliente, tal como lo devolvió consultar_clientes.
     * @param  string  $orden      'mas_viejas' (por defecto) o 'mas_nuevas'.
     * @param  int     $limite     0 = MAX_RESULTS.
     * @param  int     $dias       Umbral general de antigüedad; 0 = sin umbral general.
     * @return array<string, mixed>  Vacío si el cliente no es del dueño o no existe
     */
    public static function ventas_impagas_de_un_cliente(int $owner_id, int $client_id, string $orden = 'mas_viejas', int $limite = 0, int $dias = 0): array
    {
        $limite = self::limite_pedido($limite);

        $cliente = Client::query()
            ->where('user_id', $owner_id)
            ->where('id', $client_id)
            ->first(['id', 'name']);

        /*
         * La tenencia se controla acá y no adentro de la query: sin este first() un client_id de
         * otro comercio devolvería lista vacía (que es correcto) pero indistinguible de "este
         * cliente no te debe nada" (que es otra cosa muy distinta, y es plata).
         */
        if (is_null($cliente)) {
            return self::no_encontrado(
                'No encontré ningún cliente con el id ' . $client_id . ' en este negocio.',
                'Buscá el cliente con consultar_clientes y volvé a llamar con el id que devuelva. No digas que no debe nada: no se pudo mirar.'
            );
        }

        if ($dias < 0) {
            $dias = 0;
        }

        $base = VentasSinCobrarHelper::query_de_ventas($owner_id, null, $dias)
            ->where('sales.client_id', (int) $cliente->id);

        $encontradas = (clone $base)->count();

        $direccion = ($orden === 'mas_nuevas') ? 'DESC' : 'ASC';

        $ventas = (clone $base)
            ->with('current_acount')
            ->orderBy('sales.created_at', $direccion)
            ->orderBy('sales.id', $direccion)
            ->limit($limite)
            ->get();

        $lista = [];
        $pendiente_en_pesos = 0.0;
        $en_otra_moneda = 0;

        foreach ($ventas as $venta) {
            $fila = self::fila_de_venta_impaga($venta);

            /*
             * 🔴 El total suma SOLO las ventas en pesos, y las otras se cuentan aparte. Es la misma
             * regla de compras_a_un_proveedor y por el mismo motivo, escrito ahí: un total que
             * mezcla monedas es un número falso que nadie puede detectar mirándolo.
             */
            if ($fila['en_pesos']) {
                $pendiente_en_pesos += $fila['pendiente'];
            } else {
                $en_otra_moneda++;
            }

            $lista[] = $fila;
        }

        /*
         * La más vieja de TODAS, no la más vieja de la página: es la pregunta del caso original y
         * tiene que estar aunque el modelo haya pedido el orden inverso o un límite chico. Cuando
         * la lista YA viene de la más vieja a la más nueva, la primera fila es esa misma y no se
         * gasta una consulta de más: el presupuesto de tiempo del asistente es el recurso escaso
         * de toda esta misión.
         */
        if ($direccion === 'ASC' && ! empty($lista)) {
            $fila_mas_vieja = $lista[0];
        } else {
            $mas_vieja = (clone $base)
                ->with('current_acount')
                ->orderBy('sales.created_at', 'ASC')
                ->orderBy('sales.id', 'ASC')
                ->first();

            $fila_mas_vieja = is_null($mas_vieja) ? null : self::fila_de_venta_impaga($mas_vieja);
        }

        $saldos = self::saldos_en_pesos_de_clientes($owner_id, [(int) $cliente->id]);

        return [
            'cliente'                       => (string) $cliente->name,
            'cliente_id'                    => (int) $cliente->id,
            'orden'                         => $direccion === 'ASC' ? 'mas_viejas' : 'mas_nuevas',
            'ventas_impagas_encontradas'    => (int) $encontradas,
            'ventas_en_esta_lista'          => count($lista),
            /*
             * 🔴 El nombre dice las DOS cosas que lo acotan: es la suma de lo que está EN ESTA
             * LISTA (con la lista recortada no es la deuda del cliente) y solo de las ventas EN
             * PESOS. Un total de la pantalla informado como total del negocio es el defecto que
             * consultar_actividad_de_un_cliente vino a arreglar; un total que mezcla monedas es el
             * que arregla compras_a_un_proveedor. Acá pasaban los dos.
             */
            'total_pendiente_en_pesos_en_esta_lista' => round($pendiente_en_pesos, 2),
            // Cuántas de las ventas listadas NO están en ese total porque van en otra moneda.
            'ventas_en_otra_moneda_en_esta_lista'    => $en_otra_moneda,
            /*
             * La deuda de verdad sale de credit_accounts, que es la única fuente de deuda que el
             * sistema le cuenta a una IA (ver el docblock de clientes()). Incluye lo que no está en
             * ninguna venta: saldos iniciales, notas de crédito, ajustes. Y es SOLO en pesos, que es
             * la otra mitad de por qué las ventas en dólares tienen que declararse: si no, este
             * número y el de arriba no cierran y no hay forma de saber por qué.
             */
            'saldo_en_cuenta_corriente_en_pesos' => isset($saldos[(int) $cliente->id]) ? $saldos[(int) $cliente->id] : 0,
            'venta_impaga_mas_vieja'        => $fila_mas_vieja,
            'ventas'                        => $lista,
        ];
    }

    /**
     * Una venta impaga, con las mismas claves siempre. Un JSON con filas de forma distinta obliga
     * a Claude a adivinar el shape, y adivina mal.
     *
     * @param  Sale  $venta  Con current_acount ya cargada (o no: se resuelve igual).
     * @return array<string, mixed>
     */
    protected static function fila_de_venta_impaga($venta): array
    {
        $cuenta = $venta->current_acount;

        $debe      = (is_null($cuenta) || is_null($cuenta->debe)) ? 0.0 : (float) $cuenta->debe;
        $pagandose = (is_null($cuenta) || is_null($cuenta->pagandose)) ? 0.0 : (float) $cuenta->pagandose;

        /*
         * 🔴 LA MONEDA DE LA VENTA VIAJA EN LA FILA. Sin esto, una venta en dólares de un comercio
         * con la extensión `ventas_en_dolares` llega como un número pelado — y el prompt le dice al
         * asistente que los importes son en pesos salvo aviso, así que la informa en pesos. Es el
         * mismo criterio que ya aplican compras_a_un_proveedor (`en_pesos`) y compras_de_un_articulo
         * (`costo_en_dolares`): esta consulta había quedado afuera de su propia regla.
         *
         * Manda la moneda de la CUENTA CORRIENTE, que es la fila donde vive la deuda, y la de la
         * venta queda de respaldo para las filas viejas que no la tengan. null se lee como pesos,
         * igual que en todo el resto (RecolectorBase::MONEDAS_PESOS incluye el 0 además del 1).
         */
        $moneda_id = null;

        if (! is_null($cuenta) && ! is_null($cuenta->moneda_id)) {
            $moneda_id = (int) $cuenta->moneda_id;
        } elseif (! is_null($venta->moneda_id)) {
            $moneda_id = (int) $venta->moneda_id;
        }

        return [
            'venta_id'        => (int) $venta->id,
            'numero'          => is_null($venta->num) ? null : (int) $venta->num,
            'fecha'           => self::fecha_legible($venta->created_at),
            // Días desde la venta: el "desde cuándo" de la pregunta, ya calculado para que el
            // modelo no tenga que restar fechas (que es donde se equivoca).
            'dias_sin_cobrar' => is_null($venta->created_at) ? null : (int) $venta->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()),
            'total'           => is_null($venta->total) ? 0.0 : (float) $venta->total,
            'debe'            => $debe,
            // Lo que ya entregó a cuenta de ESTA venta; el sistema lo guarda aparte del haber.
            'pagado_a_cuenta' => $pagandose,
            'pendiente'       => round($debe - $pagandose, 2),
            'estado'          => is_null($cuenta) ? null : (string) $cuenta->status,
            'moneda_id'       => $moneda_id,
            'en_pesos'        => is_null($moneda_id) || in_array($moneda_id, RecolectorBase::MONEDAS_PESOS, true),
        ];
    }

    /**
     * QUÉ CLIENTES COMPRARON UN ARTÍCULO Y CUÁNTAS UNIDADES, EN EL ERP.
     *
     * Es el caso 2 de las capturas: "¿qué cliente me compró más la lámpara?". Hasta hoy contestaba
     * consultar_interesados_en_un_articulo, que corre sobre el tracking de la TIENDA ONLINE
     * (`buyer_tracking_events`: quién la miró, quién la puso en el carrito) — otra pregunta, y el
     * comerciante no tenía cómo darse cuenta.
     *
     * ⚠️ `article_purchases` es LO QUE EL CLIENTE COMPRÓ, no una compra a un proveedor. El nombre
     * de la tabla engaña: las compras a proveedores son `provider_orders`.
     *
     * 🔴 Va con Sale::scopeSoloVentasReales(): sin ese scope, una venta contenedora de
     * consolidación AFIP suma las mismas unidades que las ventas que agrupa, y el artículo aparece
     * vendido el doble. Es el mismo scope que usan los reportes de rendimiento, y por eso se
     * invoca y no se reescribe.
     *
     * 🔴 LAS UNIDADES SON EXACTAS; EL MONTO PUEDE ESTAR INCOMPLETO, Y CUÁNTO SE DICE.
     * `ArticlePurchaseHelper::set_costo_y_price()` (`:50-67`) llena `article_purchases.price` SOLO
     * cuando la venta tiene `moneda_id == 1`; con `== 2` llena `price_dolar`; y con null o 0 NO
     * LLENA NINGUNO DE LOS DOS. Y `sales.moneda_id` es nullable sin default desde la migración
     * `2025_08_29_162530`, que no hizo backfill: TODA venta anterior al 29/8/2025 lo tiene en null.
     *
     * Hasta el 16/9/2026 el monto se calculaba con `COALESCE(price, 0)`, así que esas unidades
     * entraban como CERO PESOS. No daba un cero sospechoso: daba un total bajo y plausible, y el
     * comerciante leía "Fulano te compró 500 unidades por $40.000" cuando fueron $300.000. Y la
     * ventana por defecto de esta consulta es TODA LA HISTORIA, o sea que la pregunta apunta justo
     * a las ventas viejas. Ahora esas unidades no entran al monto y viajan contadas en
     * `unidades_sin_precio_en_pesos`, por cliente y en el total.
     *
     * @param  int     $owner_id  Id del dueño. Nunca Auth: esta tool corre en un job sin sesión.
     * @param  string  $busqueda  Nombre (o parte), código de barras o código de proveedor.
     * @param  int     $dias      Ventana hacia atrás; 0 = toda la historia.
     * @param  int     $limite    0 = MAX_RESULTS.
     * @return array<string, mixed>  Vacío si la búsqueda no resolvió ningún artículo del dueño
     */
    public static function quien_compro_un_articulo(int $owner_id, string $busqueda, int $dias = 0, int $limite = 0): array
    {
        $limite = self::limite_pedido($limite);

        $articulo = self::resolver_articulo($owner_id, $busqueda);

        // "No encontre el articulo" y "nadie lo compro" / "no tiene compras" son dos respuestas muy
        // distintas, y vacio se lee como la segunda. Ver no_encontrado().
        if (is_null($articulo)) {
            return self::no_encontrado(
                trim($busqueda) === ''
                    ? 'Necesito el nombre o el codigo del articulo para poder buscarlo.'
                    : 'No encontre ningun articulo de este negocio que coincida con "' . trim($busqueda) . '".',
                'Busca el articulo con consultar_stock_de_articulos y volve a llamar con el nombre exacto que devuelva.'
            );
        }

        $base = Sale::query()
            ->soloVentasReales()
            ->where('sales.user_id', $owner_id)
            ->join('article_purchases', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('article_purchases.article_id', (int) $articulo->id);

        if ($dias > 0) {
            // La fecha que manda es la de la VENTA. article_purchases.created_at se copia de ahí
            // (ArticlePurchaseHelper:33), así que da lo mismo — pero una sola fecha es una sola
            // verdad el día que deje de copiarse.
            $base = $base->where('sales.created_at', '>=', now()->subDays($dias));
        }

        $totales = (clone $base)->toBase()->selectRaw(
            'COALESCE(SUM(article_purchases.amount), 0) as unidades, '
            . 'COUNT(DISTINCT sales.id) as ventas, '
            . 'COUNT(DISTINCT CASE WHEN article_purchases.client_id > 0 THEN article_purchases.client_id END) as clientes, '
            . 'COALESCE(SUM(CASE WHEN article_purchases.client_id IS NULL OR article_purchases.client_id = 0 THEN article_purchases.amount ELSE 0 END), 0) as sin_cliente, '
            . 'COALESCE(SUM(CASE WHEN article_purchases.price IS NULL THEN article_purchases.amount ELSE 0 END), 0) as sin_precio'
        )->first();

        $filas = (clone $base)
            ->where('article_purchases.client_id', '>', 0)
            ->leftJoin('clients', 'clients.id', '=', 'article_purchases.client_id')
            ->groupBy('article_purchases.client_id')
            ->toBase()
            // MAX() sobre las columnas que no están en el GROUP BY: con ONLY_FULL_GROUP_BY prendido
            // (el default de MySQL 8) un clients.name suelto acá es un error de SQL, no un warning.
            ->selectRaw(
                'article_purchases.client_id as client_id, '
                . 'MAX(clients.name) as cliente, '
                . 'COALESCE(SUM(article_purchases.amount), 0) as unidades, '
                . 'COUNT(DISTINCT sales.id) as ventas, '
                . 'MAX(sales.created_at) as ultima, '
                /*
                 * 🔴 UN RENGLÓN SIN PRECIO NO VALE CERO PESOS: NO ENTRA AL TOTAL Y SE CUENTA APARTE.
                 * Acá había un COALESCE(price, 0) y era plata mal informada en silencio (ver el
                 * docblock del método).
                 *
                 * ⚠️ La condición es `price IS NULL` y NO una sobre `sales.moneda_id`, y eso no es
                 * un gusto: en SQL, `NULL NOT IN (0, 1)` no da TRUE, da NULL — así que un CASE
                 * armado sobre la moneda deja afuera justamente las filas de las ventas viejas, que
                 * son las que se quiere contar. `price IS NULL` es la condición sobre la MISMA
                 * columna que lee el SUM, así que las dos ramas cubren todas las filas por
                 * construcción, sin importar qué diga (o no diga) la moneda de la venta.
                 */
                . 'COALESCE(SUM(CASE WHEN article_purchases.price IS NOT NULL THEN article_purchases.amount * article_purchases.price ELSE 0 END), 0) as monto, '
                . 'COALESCE(SUM(CASE WHEN article_purchases.price IS NULL THEN article_purchases.amount ELSE 0 END), 0) as sin_precio'
            )
            ->orderByDesc('unidades')
            ->orderBy('article_purchases.client_id')
            ->limit($limite)
            ->get();

        $lista = [];

        foreach ($filas as $fila) {
            $lista[] = [
                'cliente_id'     => (int) $fila->client_id,
                // El cliente pudo haberse borrado después de la venta: la compra sigue siendo real.
                'cliente'        => is_null($fila->cliente) ? 'cliente borrado' : (string) $fila->cliente,
                'unidades'       => (float) $fila->unidades,
                'ventas'         => (int) $fila->ventas,
                'ultima_compra'  => self::fecha_legible($fila->ultima),
                /*
                 * 🔴 EN PESOS Y SOLO DE LO QUE TIENE PRECIO, y por eso van las dos claves juntas.
                 * `article_purchases.price` lo llena ArticlePurchaseHelper::set_costo_y_price()
                 * SOLO cuando la venta tiene `moneda_id == 1`; con 2 llena `price_dolar`, y con
                 * null o 0 NO LLENA NINGUNO. Y `sales.moneda_id` es nullable sin default desde la
                 * migración del 29/8/2025, que no hizo backfill: toda venta anterior a esa fecha
                 * cae en el último caso.
                 */
                'monto_en_pesos' => round((float) $fila->monto, 2),
                /*
                 * Cuántas de las unidades de arriba NO están representadas en ese monto. Va SIEMPRE,
                 * aunque sea 0: sin este número, un total bajo sobre muchas unidades se lee como un
                 * artículo barato y no como un dato incompleto, que es la diferencia entre
                 * equivocarse y no saber que uno se equivocó.
                 */
                'unidades_sin_precio_en_pesos' => (float) $fila->sin_precio,
            ];
        }

        return [
            'articulo'                      => (string) $articulo->name,
            'articulo_id'                   => (int) $articulo->id,
            'ventana_dias'                  => $dias > 0 ? $dias : null,
            'clientes_encontrados'          => (int) $totales->clientes,
            'clientes_en_esta_lista'        => count($lista),
            'unidades_vendidas_en_total'    => (float) $totales->unidades,
            'ventas_en_total'               => (int) $totales->ventas,
            // Mostrador: ventas sin cliente cargado. Sin esta clave, un artículo que se vende todo
            // por mostrador llega como lista vacía y el asistente contesta "no lo compró nadie".
            'unidades_sin_cliente'          => (float) $totales->sin_cliente,
            /*
             * 🔴 Cuántas unidades quedaron afuera de los montos en pesos. Reemplaza a una bandera
             * anterior (`unidades_de_ventas_en_dolares`) que se calculaba con `sales.moneda_id = 2`
             * y por eso NO contaba las ventas viejas sin moneda, que son la mayoría de las que
             * faltan: prometía explicar el hueco del monto y explicaba una parte. Una bandera de
             * escape que no cubre todo el hueco es peor que ninguna, porque viaja en un número bajo
             * y se lee como "acá no hay nada raro".
             */
            'unidades_sin_precio_en_pesos'  => (float) $totales->sin_precio,
            'clientes'                      => $lista,
        ];
    }

    /**
     * LAS COMPRAS REALES DE UN ARTÍCULO: cuándo lo compré, a quién, cuánto y a qué costo.
     *
     * Es el caso 3 de las capturas: "¿cuándo fue la última compra que cargué de la lámpara y a
     * quién se la compré?". Hasta hoy contestaba consultar_precios_de_proveedores, que mira
     * `provider_price_offers` — el histórico de precios OFERTADOS, que no es una compra. Ninguna
     * tool tocaba `provider_orders`.
     *
     * 🔴 El orden es fijo, de la compra MÁS NUEVA a la más vieja: la pregunta es "la última", así
     * que la primera fila de la lista es la respuesta aunque el tope corte el resto.
     *
     * ⚠️ `costo_unitario` sale del pivot `article_provider_order.cost`, que puede estar en dólares
     * — la misma fila lo dice en `costo_en_dolares`. Informar ese número sin la bandera es cómo se
     * le contesta a un comerciante que compró la lámpara a 12 pesos.
     *
     * @param  int     $owner_id  Id del dueño (provider_orders.user_id). Nunca Auth.
     * @param  string  $busqueda  Nombre (o parte), código de barras o código de proveedor.
     * @param  int     $limite    0 = MAX_RESULTS.
     * @return array<string, mixed>  Vacío si la búsqueda no resolvió ningún artículo del dueño
     */
    public static function compras_de_un_articulo(int $owner_id, string $busqueda, int $limite = 0): array
    {
        $limite = self::limite_pedido($limite);

        $articulo = self::resolver_articulo($owner_id, $busqueda);

        // "No encontre el articulo" y "nadie lo compro" / "no tiene compras" son dos respuestas muy
        // distintas, y vacio se lee como la segunda. Ver no_encontrado().
        if (is_null($articulo)) {
            return self::no_encontrado(
                trim($busqueda) === ''
                    ? 'Necesito el nombre o el codigo del articulo para poder buscarlo.'
                    : 'No encontre ningun articulo de este negocio que coincida con "' . trim($busqueda) . '".',
                'Busca el articulo con consultar_stock_de_articulos y volve a llamar con el nombre exacto que devuelva.'
            );
        }

        $base = DB::table('article_provider_order')
            ->join('provider_orders', 'provider_orders.id', '=', 'article_provider_order.provider_order_id')
            ->where('provider_orders.user_id', $owner_id)
            ->where('article_provider_order.article_id', (int) $articulo->id);

        $totales = (clone $base)->selectRaw(
            'COUNT(*) as compras, '
            . 'COALESCE(SUM(article_provider_order.amount), 0) as pedidas, '
            . 'COALESCE(SUM(article_provider_order.received), 0) as recibidas, '
            . 'COUNT(DISTINCT provider_orders.provider_id) as proveedores'
        )->first();

        $filas = (clone $base)
            // leftJoin y no join: un proveedor borrado no puede hacer desaparecer una compra que
            // existió. El nombre viaja en null y la fila se lee igual.
            ->leftJoin('providers', 'providers.id', '=', 'provider_orders.provider_id')
            ->leftJoin('provider_order_statuses', 'provider_order_statuses.id', '=', 'provider_orders.provider_order_status_id')
            ->orderByDesc('provider_orders.created_at')
            ->orderByDesc('provider_orders.id')
            ->limit($limite)
            ->get([
                'provider_orders.id as compra_id',
                'provider_orders.num as numero',
                'provider_orders.created_at as fecha',
                'provider_orders.fecha_emision_comprobante as fecha_comprobante',
                'provider_orders.numero_comprobante as comprobante',
                'provider_orders.provider_id as proveedor_id',
                'providers.name as proveedor',
                'provider_order_statuses.name as estado',
                'article_provider_order.amount as pedida',
                'article_provider_order.received as recibida',
                'article_provider_order.cost as costo',
                'article_provider_order.received_cost as costo_recibido',
                'article_provider_order.cost_in_dollars as costo_en_dolares',
            ]);

        $lista = [];

        foreach ($filas as $fila) {
            $lista[] = [
                'compra_id'          => (int) $fila->compra_id,
                'numero'             => is_null($fila->numero) ? null : (int) $fila->numero,
                'fecha'              => self::fecha_legible($fila->fecha),
                // La del comprobante del proveedor, que puede no ser la de carga. null = no se cargó.
                'fecha_comprobante'  => self::fecha_legible($fila->fecha_comprobante),
                'comprobante'        => is_null($fila->comprobante) ? null : (string) $fila->comprobante,
                'proveedor_id'       => is_null($fila->proveedor_id) ? null : (int) $fila->proveedor_id,
                'proveedor'          => is_null($fila->proveedor) ? 'proveedor borrado' : (string) $fila->proveedor,
                // Sólo hay dos estados en el sistema: "En proceso" y "Recibido".
                'estado'             => is_null($fila->estado) ? null : (string) $fila->estado,
                'cantidad_pedida'    => is_null($fila->pedida) ? 0.0 : (float) $fila->pedida,
                'cantidad_recibida'  => is_null($fila->recibida) ? 0.0 : (float) $fila->recibida,
                'costo_unitario'     => is_null($fila->costo) ? null : (float) $fila->costo,
                'costo_al_recibir'   => is_null($fila->costo_recibido) ? null : (float) $fila->costo_recibido,
                'costo_en_dolares'   => (bool) $fila->costo_en_dolares,
            ];
        }

        return [
            'articulo'                     => (string) $articulo->name,
            'articulo_id'                  => (int) $articulo->id,
            'compras_encontradas'          => (int) $totales->compras,
            'compras_en_esta_lista'        => count($lista),
            'proveedores_distintos'        => (int) $totales->proveedores,
            'unidades_pedidas_en_total'    => (float) $totales->pedidas,
            'unidades_recibidas_en_total'  => (float) $totales->recibidas,
            // De la más nueva a la más vieja: la primera fila de `compras` es la última compra.
            'orden'                        => 'mas_nuevas_primero',
            'compras'                      => $lista,
        ];
    }

    /**
     * QUÉ LE COMPRÉ A UN PROVEEDOR: cada compra con su fecha, su comprobante, su estado y su total.
     *
     * Corre sobre `provider_orders`, que es la compra de verdad (no confundir con
     * `article_purchases`, que es lo que le compró un CLIENTE al comercio).
     *
     * 🔴 El total en pesos se suma sólo sobre las compras en pesos, con el mismo criterio de moneda
     * que usa todo el resto (RecolectorBase::MONEDAS_PESOS = [0, 1]: el 0 también es pesos, lo
     * deja un alta donde el select de moneda no se eligió). Las compras en otra moneda se cuentan
     * aparte en vez de sumarse a los pesos: un total que mezcla monedas es un número falso que
     * nadie puede detectar mirándolo.
     *
     * @param  int     $owner_id  Id del dueño (provider_orders.user_id). Nunca Auth.
     * @param  string  $busqueda  Nombre, razón social o CUIT del proveedor.
     * @param  int     $dias      Ventana hacia atrás; 0 = toda la historia.
     * @param  int     $limite    0 = MAX_RESULTS.
     * @return array<string, mixed>  Vacío si la búsqueda no resolvió ningún proveedor del dueño
     */
    public static function compras_a_un_proveedor(int $owner_id, string $busqueda, int $dias = 0, int $limite = 0): array
    {
        $limite = self::limite_pedido($limite);

        $proveedor = self::resolver_proveedor($owner_id, $busqueda);

        // Mismo criterio que con el articulo: vacio se leeria como "no le compraste nada".
        if (is_null($proveedor)) {
            return self::no_encontrado(
                trim($busqueda) === ''
                    ? 'Necesito el nombre, la razon social o el CUIT del proveedor para poder buscarlo.'
                    : 'No encontre ningun proveedor de este negocio que coincida con "' . trim($busqueda) . '".',
                'Preguntale a la persona por el nombre exacto del proveedor, o proba con parte del nombre.'
            );
        }

        $monedas_pesos = implode(',', RecolectorBase::MONEDAS_PESOS);

        $base = DB::table('provider_orders')
            ->where('provider_orders.user_id', $owner_id)
            ->where('provider_orders.provider_id', (int) $proveedor->id);

        if ($dias > 0) {
            $base = $base->where('provider_orders.created_at', '>=', now()->subDays($dias));
        }

        $totales = (clone $base)->selectRaw(
            'COUNT(*) as compras, '
            . 'COALESCE(SUM(CASE WHEN provider_orders.moneda_id IN (' . $monedas_pesos . ') THEN provider_orders.total ELSE 0 END), 0) as total_pesos, '
            . 'COUNT(CASE WHEN provider_orders.moneda_id NOT IN (' . $monedas_pesos . ') THEN 1 END) as otra_moneda'
        )->first();

        $filas = (clone $base)
            ->leftJoin('provider_order_statuses', 'provider_order_statuses.id', '=', 'provider_orders.provider_order_status_id')
            ->orderByDesc('provider_orders.created_at')
            ->orderByDesc('provider_orders.id')
            ->limit($limite)
            ->get([
                'provider_orders.id as compra_id',
                'provider_orders.num as numero',
                'provider_orders.created_at as fecha',
                'provider_orders.fecha_emision_comprobante as fecha_comprobante',
                'provider_orders.numero_comprobante as comprobante',
                'provider_orders.total as total',
                'provider_orders.moneda_id as moneda_id',
                'provider_order_statuses.name as estado',
            ]);

        $ids = [];
        foreach ($filas as $fila) {
            $ids[] = (int) $fila->compra_id;
        }

        $renglones = self::renglones_por_compra($ids);

        $lista = [];

        foreach ($filas as $fila) {
            $compra_id = (int) $fila->compra_id;

            $lista[] = [
                'compra_id'            => $compra_id,
                'numero'               => is_null($fila->numero) ? null : (int) $fila->numero,
                'fecha'                => self::fecha_legible($fila->fecha),
                'fecha_comprobante'    => self::fecha_legible($fila->fecha_comprobante),
                'comprobante'          => is_null($fila->comprobante) ? null : (string) $fila->comprobante,
                'estado'               => is_null($fila->estado) ? null : (string) $fila->estado,
                'total'                => is_null($fila->total) ? 0.0 : (float) $fila->total,
                // En qué moneda está ese total. Sin esta clave, un total en dólares se lee como pesos.
                'en_pesos'             => in_array((int) $fila->moneda_id, RecolectorBase::MONEDAS_PESOS, true),
                'articulos_distintos'  => isset($renglones[$compra_id]) ? (int) $renglones[$compra_id]['articulos'] : 0,
                'unidades'             => isset($renglones[$compra_id]) ? (float) $renglones[$compra_id]['unidades'] : 0.0,
            ];
        }

        return [
            'proveedor'                 => (string) $proveedor->name,
            'proveedor_id'              => (int) $proveedor->id,
            'ventana_dias'              => $dias > 0 ? $dias : null,
            'compras_encontradas'       => (int) $totales->compras,
            'compras_en_esta_lista'     => count($lista),
            'total_comprado_en_pesos'   => round((float) $totales->total_pesos, 2),
            // Cuántas de esas compras NO están en el total de arriba porque van en otra moneda.
            'compras_en_otra_moneda'    => (int) $totales->otra_moneda,
            'orden'                     => 'mas_nuevas_primero',
            'compras'                   => $lista,
        ];
    }

    /**
     * Cuántos artículos distintos y cuántas unidades tiene cada compra de la lista.
     *
     * Va en una consulta aparte y no en un JOIN sobre la query principal: con el join, el GROUP BY
     * multiplicaría las filas de provider_orders y el `total` de cada compra se contaría una vez
     * por renglón.
     *
     * @param  array<int, int>  $provider_order_ids
     * @return array<int, array<string, float>>  provider_order_id => ['articulos' => n, 'unidades' => n]
     */
    protected static function renglones_por_compra(array $provider_order_ids): array
    {
        $provider_order_ids = array_values(array_unique(array_filter(array_map('intval', $provider_order_ids))));

        if (empty($provider_order_ids)) {
            return [];
        }

        $filas = DB::table('article_provider_order')
            ->whereIn('provider_order_id', $provider_order_ids)
            ->groupBy('provider_order_id')
            ->selectRaw('provider_order_id, COUNT(DISTINCT article_id) as articulos, COALESCE(SUM(amount), 0) as unidades')
            ->get();

        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[(int) $fila->provider_order_id] = [
                'articulos' => (float) $fila->articulos,
                'unidades'  => (float) $fila->unidades,
            ];
        }

        return $resultado;
    }

    /**
     * EL STOCK DE UN ARTÍCULO EN CADA SUCURSAL.
     *
     * Sale del pivot `address_article` (Article::addresses(), con `pivot.amount`), que es donde el
     * sistema reparte el stock cuando el comercio tiene más de una sucursal.
     *
     * ⚠️ NO HAY EXTENSIÓN DE DEPÓSITOS: el criterio de "este comercio trabaja con depósitos" es
     * tener DOS O MÁS sucursales, exactamente como lo resuelve RecolectorStock (que devuelve
     * "no aplica" con una sola). Con una sola sucursal la respuesta viaja igual, con
     * `trabaja_con_depositos` en false: el stock total sigue siendo la respuesta correcta a la
     * pregunta, y el que pregunta tiene que poder distinguir "no hay reparto" de "no hay stock".
     *
     * 🔴 Van TODAS las sucursales, incluidas las que tienen 0. Un depósito sin stock es justamente
     * lo que el comerciante está buscando cuando pregunta dónde está la mercadería.
     *
     * 🔴 Viajan los dos números: el `stock` de la ficha del artículo y la suma del reparto por
     * sucursal. Cuando no coinciden hay un problema de datos real, y esconderlo detrás de un solo
     * número elegido por nosotros es decidir por el comerciante cuál de los dos es el bueno.
     *
     * @param  int     $owner_id  Id del dueño (articles.user_id / addresses.user_id). Nunca Auth.
     * @param  string  $busqueda  Nombre (o parte), código de barras o código de proveedor.
     * @param  int     $limite    0 = MAX_RESULTS.
     * @return array<string, mixed>  Vacío si la búsqueda no resolvió ningún artículo del dueño
     */
    public static function stock_por_deposito(int $owner_id, string $busqueda, int $limite = 0): array
    {
        $limite = self::limite_pedido($limite);

        $articulo = self::resolver_articulo($owner_id, $busqueda);

        // "No encontre el articulo" y "nadie lo compro" / "no tiene compras" son dos respuestas muy
        // distintas, y vacio se lee como la segunda. Ver no_encontrado().
        if (is_null($articulo)) {
            return self::no_encontrado(
                trim($busqueda) === ''
                    ? 'Necesito el nombre o el codigo del articulo para poder buscarlo.'
                    : 'No encontre ningun articulo de este negocio que coincida con "' . trim($busqueda) . '".',
                'Busca el articulo con consultar_stock_de_articulos y volve a llamar con el nombre exacto que devuelva.'
            );
        }

        // Las sucursales del comercio son las addresses del dueño, igual que en AddressController
        // y en RecolectorStock. (La tabla también guarda domicilios de compradores de la tienda,
        // que llevan buyer_id y no son del dueño.)
        $sucursales = DB::table('addresses')
            ->leftJoin('address_article', function ($join) use ($articulo) {
                $join->on('address_article.address_id', '=', 'addresses.id')
                    ->where('address_article.article_id', '=', (int) $articulo->id);
            })
            ->where('addresses.user_id', $owner_id)
            ->orderBy('addresses.id')
            ->get([
                'addresses.id as address_id',
                'addresses.street as nombre',
                'addresses.es_deposito_origen as es_deposito_origen',
                'address_article.amount as cantidad',
                'address_article.stock_min as stock_min',
                'address_article.stock_max as stock_max',
            ]);

        $repartido = 0.0;
        $todas = [];

        foreach ($sucursales as $sucursal) {
            $cantidad = is_null($sucursal->cantidad) ? 0.0 : (float) $sucursal->cantidad;

            $repartido += $cantidad;

            $todas[] = [
                'address_id'         => (int) $sucursal->address_id,
                'deposito'           => (string) ($sucursal->nombre === null ? '' : $sucursal->nombre),
                'stock'              => $cantidad,
                'stock_min'          => is_null($sucursal->stock_min) ? null : (float) $sucursal->stock_min,
                'stock_max'          => is_null($sucursal->stock_max) ? null : (float) $sucursal->stock_max,
                'es_deposito_origen' => (bool) $sucursal->es_deposito_origen,
            ];
        }

        $mostradas = array_slice($todas, 0, $limite);

        return [
            'articulo'                  => (string) $articulo->name,
            'articulo_id'               => (int) $articulo->id,
            'trabaja_con_depositos'     => count($todas) >= 2,
            'depositos_encontrados'     => count($todas),
            'depositos_en_esta_lista'   => count($mostradas),
            // El stock de la ficha del artículo: el número que muestra el listado.
            'stock_total_del_articulo'  => is_null($articulo->stock) ? 0.0 : (float) $articulo->stock,
            // La suma del reparto por sucursal. Si difiere del de arriba, el dato está roto y hay
            // que decirlo, no elegir uno.
            'stock_sumado_por_deposito' => round($repartido, 2),
            'depositos'                 => $mostradas,
        ];
    }

    /**
     * Extrae una palabra clave de producto/entidad desde el texto de la consulta.
     *
     * Quita los disparadores conocidos (stock, cuánto tengo, de, etc.) y devuelve el resto.
     *
     * @param  string  $query
     * @return string  Palabra clave depurada (puede quedar vacía).
     */
    public static function extract_product_keyword(string $query): string
    {
        // Palabras de relleno que no aportan al filtro de nombre.
        $stop_words = [
            'cuanto', 'cuanta', 'cuantos', 'cuantas', 'stock', 'inventario', 'tengo', 'hay', 'queda', 'quedan',
            'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'mi', 'mis', 'me', 'que', 'cual', 'cuales',
            'existencia', 'existencias', 'producto', 'productos', 'articulo', 'articulos', 'sistema',
            'cliente', 'clientes', 'factura', 'facturas', 'deuda', 'saldo', 'pendiente', 'pendientes',
        ];

        $normalized = self::normalize_text($query);
        // Separamos en palabras y descartamos las de relleno.
        $words = preg_split('/\s+/', $normalized) ?: [];

        $kept = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || in_array($word, $stop_words, true)) {
                continue;
            }
            $kept[] = $word;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * Normaliza texto a minúsculas sin acentos para comparaciones de palabras clave.
     *
     * @param  string  $text
     * @return string
     */
    public static function normalize_text(string $text): string
    {
        $text = mb_strtolower(trim($text));

        // Reemplazo simple de vocales acentuadas y ñ.
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr($text, $replacements);
    }
}
