<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\BuyerTrackingEvent;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * La pata de TIENDA de la semilla: deja a los clientes que además son compradores del ecommerce en
 * condiciones de recibir una oferta, y les inventa el comportamiento de navegación que el motor de
 * ofertas necesita para tener algo que leer.
 *
 * 🔴 POR QUÉ EXISTE ESTA CLASE, Y NO ES UN ADORNO. `CriteriosDeOfertaService::candidatos()` corre
 * cuatro criterios y después los pasa por `excluir_malos_pagadores()`, que descarta ENTEROS a los
 * clientes con una venta en cuenta corriente impaga vencida. `UserSeeder` deja
 * `users.dias_alertar_administradores_ventas_no_cobradas = 1`, o sea que "vencida" es cualquier
 * venta de ayer para atrás. Sin el paso 1 de acá, después de doce meses de siembra TODOS los
 * clientes arrastran deuda vieja, el filtro de crédito los saca a todos y `ofertas:generar`
 * devuelve cero líneas — sin una sola excepción ni un solo warning. Es el modo de falla más caro de
 * toda la semilla justamente porque no se ve.
 *
 * Y los otros dos criterios (`carrito_abandonado` e `interes_ecommerce`) leen `buyer_tracking_events`,
 * que la escribe tienda-api y en una base local está en cero filas. Sin el paso 2, la corrida
 * prueba la mitad del motor y nadie se entera de que la otra mitad nunca se ejecutó.
 *
 * Lo que NO se toca para lograr esto: `UserSeeder` (es compartido por todos los `FOR_USER`, bajarle
 * los días de alerta le cambiaría el comportamiento a instancias que no tienen nada que ver) y
 * ningún servicio de `app/Services/` (son los que se están probando: ajustarlos para que devuelvan
 * lo que queremos ver invalida el ejercicio entero). Se siembran datos, no se corren reglas.
 *
 * 🔴 SE LLAMA AL FINAL DE LA CORRIDA, DESPUÉS DE LAS VENTAS. Dos razones duras: el saldo que hay que
 * cancelar no existe hasta que las ventas a cuenta corriente estén sembradas, y los artículos que un
 * cliente "no compró" no se pueden calcular contra una tabla `article_purchases` vacía. Además
 * `sembrar()` re-siembra el generador aleatorio global (ver el porqué en `sembrar()`), así que
 * cualquier reparto de plata posterior dejaría de reproducirse igual entre corridas.
 *
 * PHP 7.4: sin `match`, `?->`, `str_contains` ni `#[...]`.
 */
class ActividadTiendaHelper
{
    /**
     * Ventana de las vistas y las búsquedas, en días.
     *
     * No es un número lindo: `CriteriosDeOfertaService::interes_en_el_ecommerce()` mira
     * `dias_carrito_abandonado * MULTIPLICADOR_VENTANA_INTERES`, que con el default 7 de la columna
     * (`2026_08_17_100000_create_offer_suggestions_table.php:93`) da 14 días. Diez deja margen para
     * que la demo se mire varios días después de sembrada y el criterio siga devolviendo algo.
     */
    const VENTANA_DIAS_INTERES = 10;

    /**
     * Ventana de los movimientos de carrito, en días. Acá el margen es más finito: la ventana real
     * de `carrito_abandonado()` es `dias_carrito_abandonado` sin multiplicar, o sea 7 días.
     */
    const VENTANA_DIAS_CARRITO = 5;

    /**
     * Días que se agregan a `buyer_tracking_daily`. Uno más que la ventana más ancha, porque un
     * evento de hace 9 días y 23 horas cae en el décimo día calendario hacia atrás y ese día también
     * tiene que quedar agregado.
     */
    const DIAS_A_AGREGAR = 11;

    /**
     * Desplazamiento sobre `semilla.semilla_aleatoria`. Es lo que separa el flujo de números
     * aleatorios de esta clase del que usa el comando para repartir la plata: con la misma semilla,
     * agregar o sacar un evento acá correría todos los montos del comando y dos corridas dejarían de
     * ser comparables.
     */
    const DESPLAZAMIENTO_SEMILLA = 606;

    /** Piso y techo de permanencia en la ficha del producto, en milisegundos (3 s a 3 min). */
    const DWELL_MS_MINIMO = 3000;
    const DWELL_MS_MAXIMO = 180000;

    /** Artículos que cada comprador "sigue". Un set chico es lo que hace que vuelva sobre los mismos. */
    const ARTICULOS_PREFERIDOS_POR_BUYER = 5;

    /**
     * Perfiles de comportamiento, en cantidades de eventos [mínimo, máximo] por tipo.
     *
     * Existen porque un solo perfil deja a los cuatro criterios viendo la misma señal en todos los
     * clientes, y entonces la pantalla de ofertas muestra 14 filas iguales: no se puede juzgar si el
     * motor discrimina bien. Cada perfil dispara un criterio distinto: el `explorador` mira mucho y
     * no agrega nada (interés en el ecommerce), el `indeciso` llena el carrito y no compra (carrito
     * abandonado, el score más alto de los cuatro) y el `comprador` cierra el pedido (que es el que
     * NO tiene que generar oferta de lo que ya se llevó).
     */
    const PERFILES = [
        'explorador' => [
            'product_view'      => [16, 20],
            'search'            => [5, 6],
            'cart_add'          => [2, 2],
            'cart_remove'       => [1, 1],
            'checkout_start'    => [1, 1],
            'checkout_complete' => [0, 0],
        ],
        'indeciso' => [
            'product_view'      => [11, 15],
            'search'            => [4, 5],
            'cart_add'          => [3, 4],
            'cart_remove'       => [2, 2],
            'checkout_start'    => [2, 2],
            'checkout_complete' => [0, 0],
        ],
        'comprador' => [
            'product_view'      => [8, 10],
            'search'            => [3, 4],
            'cart_add'          => [2, 3],
            'cart_remove'       => [1, 1],
            'checkout_start'    => [1, 2],
            'checkout_complete' => [1, 1],
        ],
    ];

    /**
     * Términos de búsqueda que SÍ existen en el catálogo de ferretería.
     *
     * 🔴 No son palabras inventadas ni traducciones: están sacadas a mano de
     * `FerreteriaArticlesSeeder::get_catalog()` y cada una matchea al menos un `articles.name` real.
     * Es la única forma de que el criterio devuelva algo: `CriteriosDeOfertaService::busquedas()` no
     * tiene ninguna FK hacia el catálogo, el único puente es un `LIKE '%termino%'` contra el nombre
     * (`articulos_que_matchean()`). Un término que no matchea produce el evento igual, pero cero
     * candidatos, y desde afuera parece que el criterio está roto.
     *
     * Van sin acentos y en minúsculas porque así están los nombres del catálogo (ESPATULA, LAMPARA)
     * y porque es lo que la tienda normaliza antes de guardar (`search_term`, migración
     * 2026_08_15_140000:116).
     */
    const TERMINOS_QUE_MATCHEAN = [
        'espatula', 'lampara', 'disco', 'filtro', 'azada', 'llave',
        'cinta', 'precinto', 'sopapa', 'cerradura', 'led', 'pinza',
    ];

    /**
     * Términos que el catálogo NO tiene.
     *
     * Se siembran a propósito: `results_count = 0` es "hay demanda y no hay oferta", que es el dato
     * más valioso que guarda el rollup diario (por eso `AgregarBuyerTracking::agregar_dia()` usa
     * MAX y no SUM sobre esa columna, y por eso la columna es nullable). Con todos los términos
     * matcheando, ese caso no aparece nunca en la demo.
     */
    const TERMINOS_SIN_STOCK = ['amoladora', 'taladro'];

    /** @var int Dueño del comercio al que se le siembra */
    protected $user_id;

    /** @var \App\Http\Controllers\Helpers\Seeders\SemillaHelper */
    protected $semilla;

    /** @var \Illuminate\Support\Collection Sucursales del usuario */
    protected $addresses;

    /** @var array<int,\App\Models\Article> Catálogo activo del usuario, en orden estable por id */
    protected $articulos = [];

    /** @var array<string,string> session_id por clave "buyer_id-Y-m-d" */
    protected $sesiones = [];

    /** @var array<string,int> Resultados de cada término, memoizados contra el catálogo cargado */
    protected $resultados_por_termino = [];

    /** @var array<string,int> Conteo de eventos escritos, por event_type */
    protected $eventos_por_tipo = [];

    /** @var array<int,string> Cosas que salieron a medias y el que corre el comando tiene que ver */
    protected $avisos = [];

    /**
     * Punto de entrada único. Cancela el saldo de los clientes de tienda, les siembra el
     * comportamiento de navegación y agrega los días al rollup.
     *
     * Devuelve el resumen de lo que se PIDIÓ sembrar (no lo que se consulta después en la base), que
     * es la misma regla que sigue `escribir_planilla_de_control()`: el comando lo guarda tal cual en
     * el bloque `actividad_tienda` de `storage/app/semilla/control.json`.
     *
     * @param int $user_id Dueño del comercio (config('semilla.user_id')).
     * @return array<string,mixed>
     */
    public function sembrar($user_id)
    {
        $this->user_id = (int) $user_id;
        $this->semilla = new SemillaHelper();
        $this->avisos = [];

        /*
         * Se re-siembra el generador global (no hay forma de guardar y restaurar su estado en PHP),
         * y por eso esta clase va última en la corrida. Con el desplazamiento, esta parte de la
         * semilla es reproducible por su cuenta sin arrastrar los repartos de plata del comando.
         */
        mt_srand((int) config('semilla.semilla_aleatoria') + self::DESPLAZAMIENTO_SEMILLA);

        try {
            return $this->sembrar_con_el_generador_sembrado();
        } finally {
            /*
             * 🔴 Y se lo devuelve al azar al salir. Esto no es cosmético: `DemoSetupHelper` invoca
             * `semilla:datos` con `Artisan::call()` DENTRO de un request, así que sin esta línea el
             * resto del request hereda un `mt_rand()` sembrado con una constante que además está en
             * el repo (`semilla.semilla_aleatoria` + `DESPLAZAMIENTO_SEMILLA`), o sea predecible.
             * PHP no deja guardar y restaurar el estado del generador; `mt_srand()` SIN argumento
             * lo re-siembra con `GENERATE_SEED()`, que es lo más cerca de "como estaba" que hay.
             * En `finally` para que valga también por los `return` tempranos del método de abajo.
             */
            mt_srand();
        }
    }

    /**
     * El cuerpo de `sembrar()`, separado solo para que el `try/finally` que restaura el generador
     * aleatorio no obligue a indentar el método entero (y para que ningún `return` temprano nuevo
     * se saltee esa restauración por olvido).
     *
     * @return array<string,mixed>
     */
    protected function sembrar_con_el_generador_sembrado()
    {
        $this->addresses = Address::where('user_id', $this->user_id)->orderBy('num')->get();
        $this->articulos = Article::where('user_id', $this->user_id)
            ->where('status', '!=', 'inactive')
            ->orderBy('id')
            ->get()
            ->values()
            ->all();

        foreach (BuyerTrackingEvent::TIPOS as $tipo) {
            $this->eventos_por_tipo[$tipo] = 0;
        }

        $buyers = Buyer::where('user_id', $this->user_id)
            ->whereNotNull('comercio_city_client_id')
            ->orderBy('id')
            ->get();

        $resumen = [
            'buyers_con_cliente'  => $buyers->count(),
            'perfiles'            => [],
            'cobranzas_de_cierre' => ['clientes' => 0, 'monto' => 0.0, 'por_caja' => [], 'por_cliente' => []],
            'eventos_por_tipo'    => $this->eventos_por_tipo,
            'eventos_totales'     => 0,
            'ventana_dias'        => [
                'vistas_y_busquedas' => self::VENTANA_DIAS_INTERES,
                'carrito'            => self::VENTANA_DIAS_CARRITO,
            ],
            'terminos_buscados'   => [],
            'dias_agregados'      => 0,
            'avisos'              => [],
        ];

        if ($buyers->isEmpty()) {
            /*
             * Sin `comercio_city_client_id` un Buyer no genera NADA: los dos criterios de tracking
             * joinean por esa columna y descartan las filas nulas. No es una excepción porque una
             * instancia sin ecommerce es un caso legítimo, pero tiene que quedar dicho.
             */
            return $this->cerrar($resumen, 'No hay ningún Buyer con comercio_city_client_id: no se siembra actividad de tienda.');
        }

        $resumen['cobranzas_de_cierre'] = $this->cancelar_saldos($buyers);

        if (!Schema::hasTable('buyer_tracking_events')) {
            return $this->cerrar($resumen, 'La tabla buyer_tracking_events no existe en esta base: no se siembran eventos ni rollup.');
        }

        $resumen['perfiles'] = $this->sembrar_eventos($buyers);
        $resumen['eventos_por_tipo'] = $this->eventos_por_tipo;
        $resumen['eventos_totales'] = array_sum($this->eventos_por_tipo);
        $resumen['terminos_buscados'] = $this->resultados_por_termino;
        $resumen['dias_agregados'] = $this->agregar_dias();

        return $this->cerrar($resumen, null);
    }

    /**
     * Paso 1 — deja en cero la cuenta corriente de los clientes que además compran por la tienda.
     *
     * Se cobra por efectivo y por la caja de la sucursal del cliente, con la fecha de HOY: es un
     * cobro de verdad, por el camino real (`SemillaHelper::cobro_cuenta_corriente()`), no un UPDATE
     * a mano sobre `current_acounts.status`. Un UPDATE dejaría el saldo del `credit_account`, el
     * movimiento de caja y las comisiones desalineados entre sí, que es exactamente el desastre que
     * `SemillaHelper` entero viene a evitar.
     *
     * 🔴 El resultado trae `por_cliente` (client_id => monto cobrado) además del total y del
     * desglose por caja. No es un adorno: `SembrarDatosDePrueba::escribir_planilla_de_control()`
     * necesita RESTAR estas cobranzas de `deuda_esperada_por_cliente`, porque la deuda que la
     * planilla calculó mes a mes acá se cancela de verdad. Sin el detalle por cliente, la planilla
     * informaba deuda de clientes que en la base ya estaban en cero (12 de 25 en la corrida
     * completa) -- que es exactamente el tipo de número que rompe el ejercicio de verificar un
     * reporte contra la planilla.
     *
     * @param \Illuminate\Support\Collection $buyers
     * @return array<string,mixed>
     */
    protected function cancelar_saldos($buyers)
    {
        $resultado = ['clientes' => 0, 'monto' => 0.0, 'por_caja' => [], 'por_cliente' => []];

        if ($this->addresses->isEmpty()) {
            $this->avisos[] = 'El usuario no tiene ninguna Address: no se puede resolver la caja del cobro y no se cancela ningún saldo.';

            return $resultado;
        }

        $client_ids = array_values(array_unique(array_map('intval', $buyers->pluck('comercio_city_client_id')->all())));

        /*
         * Se filtra por user_id y no se confía en el id que trae el Buyer: `comercio_city_client_id`
         * lo setea una persona a mano (BuyerController) o el importador de clientes, así que puede
         * apuntar a un cliente de otro comercio o a uno que ya no está.
         */
        $clientes = Client::where('user_id', $this->user_id)->whereIn('id', $client_ids)->orderBy('id')->get();

        if ($clientes->count() < count($client_ids)) {
            $this->avisos[] = 'Hay Buyers cuyo comercio_city_client_id no corresponde a un cliente de este comercio: esos no reciben ofertas.';
        }

        foreach ($clientes as $cliente) {
            $credit_account = CreditAccount::where('model_name', 'client')
                ->where('model_id', $cliente->id)
                ->where('moneda_id', 1)
                ->first();

            if (is_null($credit_account)) {
                $this->avisos[] = 'El cliente '.$cliente->id.' no tiene credit_account de moneda 1: no se le puede cancelar el saldo.';
                continue;
            }

            $pendiente = $this->deuda_pendiente($credit_account->id);

            if ($pendiente <= 0) {
                continue;
            }

            $address_id = $this->address_de($cliente);
            $caja_id = $this->semilla->caja_para(3, $address_id);

            $this->semilla->cobro_cuenta_corriente(Carbon::now(), $pendiente, $cliente->id, [
                ['current_acount_payment_method_id' => 3, 'amount' => $pendiente, 'caja_id' => $caja_id],
            ]);

            $resultado['clientes']++;
            $resultado['monto'] += $pendiente;
            $resultado['por_caja'][$caja_id] = (isset($resultado['por_caja'][$caja_id]) ? $resultado['por_caja'][$caja_id] : 0) + $pendiente;
            // Se acumula en vez de asignar aunque `$clientes` traiga cada cliente una sola vez
            // (viene de un `whereIn` sobre ids únicos): si mañana el origen cambia, el número sigue
            // siendo el total cobrado a ese cliente y no el último cobro.
            $resultado['por_cliente'][$cliente->id] = (isset($resultado['por_cliente'][$cliente->id]) ? $resultado['por_cliente'][$cliente->id] : 0) + $pendiente;
        }

        $resultado['monto'] = round($resultado['monto'], 2);

        return $resultado;
    }

    /**
     * Lo que hay que pagar para que no quede ni un débito abierto en esa cuenta.
     *
     * 🔴 Se suma `debe - pagandose` de los débitos pendientes y NO se lee `credit_accounts.saldo`, y
     * la diferencia importa: el objetivo no es dejar el saldo en cero, es que ningún `current_acount`
     * quede con `status` en `sin_pagar`/`pagandose`, que es lo ÚNICO que mira
     * `HistorialCrediticioService::malos_pagadores()`. Esta suma es exactamente la que va a consumir
     * `CurrentAcountPagoHelper::init()` imputando FIFO, así que alcanza justo y no sobra.
     *
     * No se filtra por `is_provisorio` a propósito: `CurrentAcountPagoHelper` tampoco lo hace al
     * armar sus débitos pendientes, y pedir menos plata de la que ese helper va a querer imputar
     * dejaría el último débito en `pagandose`.
     *
     * @param int $credit_account_id
     * @return float
     */
    protected function deuda_pendiente($credit_account_id)
    {
        $pendiente = DB::table('current_acounts')
            ->where('credit_account_id', $credit_account_id)
            ->whereIn('status', ['sin_pagar', 'pagandose'])
            ->selectRaw('COALESCE(SUM(COALESCE(debe, 0) - COALESCE(pagandose, 0)), 0) as pendiente')
            ->value('pendiente');

        return round((float) $pendiente, 2);
    }

    /**
     * Sucursal con la que se resuelve la caja del cobro.
     *
     * `clients.address_id` es nullable y además guarda un entero suelto que nadie valida contra
     * `addresses`, así que se verifica que exista antes de usarlo: si no, `caja_para()` tira y se
     * cae la corrida entera en el último paso, después de quince minutos de siembra.
     *
     * @param \App\Models\Client $cliente
     * @return int
     */
    protected function address_de($cliente)
    {
        $ids = array_map('intval', $this->addresses->pluck('id')->all());

        if (!is_null($cliente->address_id) && in_array((int) $cliente->address_id, $ids, true)) {
            return (int) $cliente->address_id;
        }

        return (int) $this->addresses->first()->id;
    }

    /**
     * Paso 2 — el comportamiento de navegación de cada comprador.
     *
     * @param \Illuminate\Support\Collection $buyers
     * @return array<string,int> Cuántos compradores quedaron en cada perfil
     */
    protected function sembrar_eventos($buyers)
    {
        $conteo_perfiles = [];

        if (empty($this->articulos)) {
            $this->avisos[] = 'El usuario no tiene artículos activos: no se puede sembrar ni una vista de producto.';

            return $conteo_perfiles;
        }

        $nombres_perfil = array_keys(self::PERFILES);

        foreach ($buyers->values() as $indice => $buyer) {
            $perfil = $nombres_perfil[$indice % count($nombres_perfil)];
            $plan = self::PERFILES[$perfil];

            $conteo_perfiles[$perfil] = (isset($conteo_perfiles[$perfil]) ? $conteo_perfiles[$perfil] : 0) + 1;

            /*
             * El visitor_id es estable por comprador y el session_id cambia por día: así es como lo
             * escribe la tienda (uno vive en el localStorage, el otro se renueva a los 30 minutos de
             * inactividad) y es lo que hace que `COUNT(DISTINCT visitor_id)` del rollup diga
             * "personas" y no "eventos".
             */
            $visitor_id = $this->uuid_determinista();

            $preferidos = $this->articulos_preferidos($indice);

            $this->sembrar_vistas($buyer, $visitor_id, $preferidos, $plan);
            $this->sembrar_busquedas($buyer, $visitor_id, $indice, $plan);

            $agregados = $this->sembrar_carrito($buyer, $visitor_id, $indice, $preferidos, $plan);

            $this->sembrar_quitados_del_carrito($buyer, $visitor_id, $agregados, $plan);
            $this->sembrar_checkout($buyer, $visitor_id, $plan);
        }

        return $conteo_perfiles;
    }

    /**
     * Vistas de producto. El comprador vuelve sobre su propio set de artículos, no recorre el
     * catálogo al azar: `vistas_con_tiempo()` puntúa las visitas REPETIDAS al mismo par
     * (cliente, artículo), así que con vistas dispersas el bonus por repetición nunca se activa y
     * todas las líneas salen con el mismo score base.
     *
     * @param \App\Models\Buyer $buyer
     * @param string $visitor_id
     * @param array<int,\App\Models\Article> $preferidos
     * @param array<string,array<int,int>> $plan
     * @return void
     */
    protected function sembrar_vistas($buyer, $visitor_id, $preferidos, $plan)
    {
        $cantidad = $this->entre($plan['product_view']);

        for ($i = 0; $i < $cantidad; $i++) {
            $articulo = $preferidos[$i % count($preferidos)];

            $this->crear_evento($buyer, $visitor_id, $this->momento_en_ventana(self::VENTANA_DIAS_INTERES), 'product_view', [
                'article_id'      => $articulo->id,
                'category_id'     => $articulo->category_id,
                'sub_category_id' => $articulo->sub_category_id,
                'dwell_ms'        => mt_rand(self::DWELL_MS_MINIMO, self::DWELL_MS_MAXIMO),
            ]);
        }
    }

    /**
     * Búsquedas en el buscador de la tienda.
     *
     * @param \App\Models\Buyer $buyer
     * @param string $visitor_id
     * @param int $indice Posición del comprador, para rotar los términos sin repetir el mismo set.
     * @param array<string,array<int,int>> $plan
     * @return void
     */
    protected function sembrar_busquedas($buyer, $visitor_id, $indice, $plan)
    {
        foreach ($this->terminos_para($indice, $this->entre($plan['search'])) as $termino) {
            $this->crear_evento($buyer, $visitor_id, $this->momento_en_ventana(self::VENTANA_DIAS_INTERES), 'search', [
                'search_term'   => $termino,
                'results_count' => $this->resultados_de($termino),
            ]);
        }
    }

    /**
     * Agregados al carrito.
     *
     * 🔴 Solo sobre artículos que ese cliente NO compró. `carrito_abandonado()` descarta el par
     * (cliente, artículo) que tenga una compra real posterior al `cart_add`, así que sembrar el
     * carrito sobre lo ya vendido produce eventos que el criterio tira y la corrida vuelve vacía sin
     * explicar por qué.
     *
     * @param \App\Models\Buyer $buyer
     * @param string $visitor_id
     * @param int $indice
     * @param array<int,\App\Models\Article> $preferidos
     * @param array<string,array<int,int>> $plan
     * @return array<int,array<string,mixed>> Lo agregado, con su momento, para poder quitarlo después
     */
    protected function sembrar_carrito($buyer, $visitor_id, $indice, $preferidos, $plan)
    {
        $candidatos = $this->articulos_no_comprados($buyer->comercio_city_client_id, $preferidos, $indice);
        $agregados = [];

        if (empty($candidatos)) {
            $this->avisos[] = 'El cliente '.$buyer->comercio_city_client_id.' ya compró todo el catálogo: no se le puede sembrar un carrito abandonado.';

            return $agregados;
        }

        $cantidad = $this->entre($plan['cart_add']);

        for ($i = 0; $i < $cantidad; $i++) {
            $articulo = $candidatos[$i % count($candidatos)];
            $momento = $this->momento_en_ventana(self::VENTANA_DIAS_CARRITO);

            $this->crear_evento($buyer, $visitor_id, $momento, 'cart_add', [
                'article_id'      => $articulo->id,
                'category_id'     => $articulo->category_id,
                'sub_category_id' => $articulo->sub_category_id,
                'quantity'        => mt_rand(1, 3),
            ]);

            $agregados[] = ['articulo' => $articulo, 'momento' => $momento];
        }

        return $agregados;
    }

    /**
     * Quitados del carrito. Van después del agregado correspondiente porque un `cart_remove` de un
     * artículo que todavía no se agregó es un recorrido imposible, y estos datos se van a mirar en
     * la pantalla de comportamiento.
     *
     * @param \App\Models\Buyer $buyer
     * @param string $visitor_id
     * @param array<int,array<string,mixed>> $agregados
     * @param array<string,array<int,int>> $plan
     * @return void
     */
    protected function sembrar_quitados_del_carrito($buyer, $visitor_id, $agregados, $plan)
    {
        if (empty($agregados)) {
            return;
        }

        $cantidad = min($this->entre($plan['cart_remove']), count($agregados));

        for ($i = 0; $i < $cantidad; $i++) {
            $articulo = $agregados[$i]['articulo'];
            $momento = $agregados[$i]['momento']->copy()->addMinutes(mt_rand(1, 120));

            // El agregado puede ser de hace un rato: sin este tope el quitado quedaría en el futuro.
            if ($momento->greaterThan(Carbon::now())) {
                $momento = Carbon::now();
            }

            $this->crear_evento($buyer, $visitor_id, $momento, 'cart_remove', [
                'article_id'      => $articulo->id,
                'category_id'     => $articulo->category_id,
                'sub_category_id' => $articulo->sub_category_id,
                'quantity'        => 1,
            ]);
        }
    }

    /**
     * Entradas al checkout y pedidos cerrados.
     *
     * `order_id` queda en null: la semilla no crea `orders` (los pedidos los crea la tienda, que en
     * local no está levantada) y poner un id inventado ahí sería una FK lógica que apunta al vacío,
     * peor que el null que la columna ya admite.
     *
     * @param \App\Models\Buyer $buyer
     * @param string $visitor_id
     * @param array<string,array<int,int>> $plan
     * @return void
     */
    protected function sembrar_checkout($buyer, $visitor_id, $plan)
    {
        $inicios = $this->entre($plan['checkout_start']);

        for ($i = 0; $i < $inicios; $i++) {
            $this->crear_evento($buyer, $visitor_id, $this->momento_en_ventana(self::VENTANA_DIAS_CARRITO), 'checkout_start', []);
        }

        $cerrados = $this->entre($plan['checkout_complete']);

        for ($i = 0; $i < $cerrados; $i++) {
            $this->crear_evento($buyer, $visitor_id, $this->momento_en_ventana(self::VENTANA_DIAS_CARRITO), 'checkout_complete', [
                'amount' => mt_rand(15, 180) * 1000,
            ]);
        }
    }

    /**
     * Paso 3 — colapsa los días sembrados en `buyer_tracking_daily`.
     *
     * Sin esto la tabla del agregado queda en cero y las pantallas de comportamiento del ERP —que
     * leen el agregado, no los eventos crudos— muestran una demo vacía al lado de un motor de
     * ofertas que sí devuelve líneas.
     *
     * @return int Días efectivamente agregados
     */
    protected function agregar_dias()
    {
        /*
         * El comando resuelve el comercio por config('app.USER_ID') puertas adentro, no por
         * parámetro. Si la semilla apunta a otro usuario, el rollup agregaría los días del usuario
         * equivocado (o ninguno) y nadie lo notaría: mejor no correrlo y decirlo.
         */
        if ((int) config('app.USER_ID') !== $this->user_id) {
            $this->avisos[] = 'semilla.user_id ('.$this->user_id.') difiere de app.USER_ID ('.config('app.USER_ID').'): no se corre tracking:agregar-buyers.';

            return 0;
        }

        $user = User::with('extencions')->find($this->user_id);

        /*
         * `AgregarBuyerTracking` sale con código 0 y sin hacer nada si falta la extensión. Se
         * chequea acá antes para que el resultado no sea "corrió bien y la tabla quedó vacía", que
         * es indistinguible de un bug.
         */
        if (is_null($user) || !UserHelper::hasExtencion('tracking_buyers', $user)) {
            $this->avisos[] = 'El comercio no tiene la extensión tracking_buyers: tracking:agregar-buyers no agrega nada y buyer_tracking_daily queda vacía.';

            return 0;
        }

        $agregados = 0;

        for ($i = self::DIAS_A_AGREGAR - 1; $i >= 0; $i--) {
            $fecha = Carbon::now()->subDays($i)->format('Y-m-d');

            // La firma real del comando es {--fecha= : Y-m-d}; con otro formato devuelve 1 sin tocar nada.
            if (Artisan::call('tracking:agregar-buyers', ['--fecha' => $fecha]) !== 0) {
                $this->avisos[] = 'tracking:agregar-buyers falló para el día '.$fecha.'.';
                continue;
            }

            $agregados++;
        }

        return $agregados;
    }

    /**
     * Escribe un evento y lo cuenta.
     *
     * @param \App\Models\Buyer $buyer
     * @param string $visitor_id
     * @param \Carbon\Carbon $momento
     * @param string $tipo
     * @param array<string,mixed> $extra Columnas propias del tipo de evento.
     * @return void
     */
    protected function crear_evento($buyer, $visitor_id, $momento, $tipo, $extra)
    {
        /*
         * La lista blanca del modelo es EL contrato con tienda-api. Un tipo mal escrito acá se
         * escribiría igual (la columna es un string suelto) y ningún criterio lo leería nunca.
         */
        if (!in_array($tipo, BuyerTrackingEvent::TIPOS, true)) {
            throw new \Exception('ActividadTiendaHelper: "'.$tipo.'" no está en BuyerTrackingEvent::TIPOS.');
        }

        BuyerTrackingEvent::create(array_merge([
            'user_id'         => $this->user_id,
            'buyer_id'        => $buyer->id,
            'visitor_id'      => $visitor_id,
            'session_id'      => $this->session_de($buyer->id, $momento),
            'event_type'      => $tipo,
            'article_id'      => null,
            'category_id'     => null,
            'sub_category_id' => null,
            'search_term'     => null,
            'results_count'   => null,
            'quantity'        => null,
            'amount'          => null,
            'dwell_ms'        => null,
            'order_id'        => null,
            /*
             * 🔴 occurred_at es la columna que filtran los cuatro criterios, la purga y el rollup;
             * created_at (que Eloquent pone solo, con la fecha real de la corrida) es "cuándo llegó
             * el lote a la API". Fechar por created_at dejaría todos los eventos como ocurridos hoy.
             */
            'occurred_at'     => $momento,
        ], $extra));

        $this->eventos_por_tipo[$tipo]++;
    }

    /**
     * Un `session_id` por comprador y por día.
     *
     * @param int $buyer_id
     * @param \Carbon\Carbon $momento
     * @return string
     */
    protected function session_de($buyer_id, $momento)
    {
        $clave = $buyer_id.'-'.$momento->format('Y-m-d');

        if (!isset($this->sesiones[$clave])) {
            $this->sesiones[$clave] = $this->uuid_determinista();
        }

        return $this->sesiones[$clave];
    }

    /**
     * Un instante al azar dentro de los últimos `$dias`, nunca en el futuro.
     *
     * Se cuenta en minutos hacia atrás desde ahora y no se arma "día + hora al azar" porque esa
     * segunda forma puede caer más tarde que la hora actual y dejar eventos con fecha futura, que en
     * una pantalla de comportamiento se ven como un error de datos.
     *
     * @param int $dias
     * @return \Carbon\Carbon
     */
    protected function momento_en_ventana($dias)
    {
        return Carbon::now()->subMinutes(mt_rand(0, ($dias * 24 * 60) - 1));
    }

    /**
     * Los artículos que este comprador sigue.
     *
     * @param int $indice Posición del comprador.
     * @return array<int,\App\Models\Article>
     */
    protected function articulos_preferidos($indice)
    {
        $total = count($this->articulos);
        $preferidos = [];

        for ($k = 0; $k < self::ARTICULOS_PREFERIDOS_POR_BUYER; $k++) {
            $preferidos[] = $this->articulos[(($indice * self::ARTICULOS_PREFERIDOS_POR_BUYER) + $k) % $total];
        }

        return $preferidos;
    }

    /**
     * Artículos que ese cliente todavía no compró, empezando por los que ya viene mirando.
     *
     * Se prefieren los del set del comprador porque "lo miró, lo puso en el carrito y no lo compró"
     * es un recorrido coherente; si el cliente ya compró todo su set, se completa recorriendo el
     * catálogo desde un desplazamiento propio del comprador para no darles a todos lo mismo.
     *
     * @param int $client_id
     * @param array<int,\App\Models\Article> $preferidos
     * @param int $indice
     * @return array<int,\App\Models\Article>
     */
    protected function articulos_no_comprados($client_id, $preferidos, $indice)
    {
        $comprados = array_flip(array_map(
            'intval',
            DB::table('article_purchases')->where('client_id', (int) $client_id)->distinct()->pluck('article_id')->all()
        ));

        $libres = [];
        $elegidos = [];

        foreach ($preferidos as $articulo) {
            if (isset($comprados[(int) $articulo->id]) || isset($elegidos[(int) $articulo->id])) {
                continue;
            }

            $elegidos[(int) $articulo->id] = true;
            $libres[] = $articulo;
        }

        $total = count($this->articulos);

        for ($k = 0; $k < $total && count($libres) < self::ARTICULOS_PREFERIDOS_POR_BUYER; $k++) {
            $articulo = $this->articulos[(($indice * 7) + $k) % $total];

            if (isset($comprados[(int) $articulo->id]) || isset($elegidos[(int) $articulo->id])) {
                continue;
            }

            $elegidos[(int) $articulo->id] = true;
            $libres[] = $articulo;
        }

        return $libres;
    }

    /**
     * Los términos que busca este comprador.
     *
     * @param int $indice
     * @param int $cantidad
     * @return array<int,string>
     */
    protected function terminos_para($indice, $cantidad)
    {
        $lista = [];
        $matchean = self::TERMINOS_QUE_MATCHEAN;

        /*
         * El paso 5 es coprimo con los 12 términos a propósito: con un paso que divida al largo de
         * la lista (3, 4, 6) el punto de arranque se repite cada pocos compradores y toda la demo
         * termina buscando las mismas cuatro cosas.
         */
        for ($k = 0; $k < $cantidad; $k++) {
            $lista[] = $matchean[(($indice * 5) + $k) % count($matchean)];
        }

        // Uno de cada dos compradores busca además algo que el catálogo no tiene (ver TERMINOS_SIN_STOCK).
        if ($indice % 2 === 0 && !empty($lista)) {
            $sin_stock = self::TERMINOS_SIN_STOCK;
            $lista[count($lista) - 1] = $sin_stock[intdiv($indice, 2) % count($sin_stock)];
        }

        return $lista;
    }

    /**
     * Cuántos artículos del catálogo devolvería ese término.
     *
     * Se calcula contra el catálogo ya cargado en memoria y no con una consulta por término, y se
     * memoiza: el número tiene que ser el MISMO que va a encontrar después
     * `CriteriosDeOfertaService::articulos_que_matchean()`, si no la demo muestra "12 resultados" en
     * una búsqueda que no ofrece nada.
     *
     * `mb_stripos` y no `str_contains`: acá manda PHP 7.4, y además el criterio real hace un
     * `LIKE '%x%'` sobre una collation que no distingue mayúsculas, que es lo que esto imita.
     *
     * @param string $termino
     * @return int
     */
    protected function resultados_de($termino)
    {
        if (!isset($this->resultados_por_termino[$termino])) {
            $total = 0;

            foreach ($this->articulos as $articulo) {
                if (mb_stripos((string) $articulo->name, $termino) !== false) {
                    $total++;
                }
            }

            $this->resultados_por_termino[$termino] = $total;
        }

        return $this->resultados_por_termino[$termino];
    }

    /**
     * UUID v4 armado con `mt_rand`.
     *
     * No es `Str::uuid()` a propósito: esa usa bytes criptográficos y haría que dos corridas del
     * comando escribieran filas distintas. La semilla entera está construida sobre lo contrario
     * (`semilla.semilla_aleatoria` es fija justamente para poder comparar una corrida con otra), y
     * acá no hace falta que el identificador sea impredecible, solo que sea único y con la forma que
     * espera la columna `char(36)`.
     *
     * @return string
     */
    protected function uuid_determinista()
    {
        $hex = '';

        for ($i = 0; $i < 32; $i++) {
            $hex .= dechex(mt_rand(0, 15));
        }

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-'
            .dechex(mt_rand(8, 11)).substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    /**
     * @param array<int,int> $rango [mínimo, máximo]
     * @return int
     */
    protected function entre($rango)
    {
        return mt_rand($rango[0], $rango[1]);
    }

    /**
     * Cierra el resumen volcando los avisos acumulados.
     *
     * Los avisos van también al log porque este helper corre al final de una siembra de quince
     * minutos y en la demo corre adentro de un request, sin nadie mirando la consola.
     *
     * @param array<string,mixed> $resumen
     * @param string|null $motivo Aviso final, si la siembra terminó antes de tiempo.
     * @return array<string,mixed>
     */
    protected function cerrar($resumen, $motivo)
    {
        if (!is_null($motivo)) {
            $this->avisos[] = $motivo;
        }

        $resumen['avisos'] = $this->avisos;

        if (!empty($this->avisos)) {
            Log::warning('ActividadTiendaHelper: la siembra de actividad de tienda terminó con avisos.', [
                'user_id' => $this->user_id,
                'avisos'  => $this->avisos,
            ]);
        }

        return $resumen;
    }
}
