<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Models\Sale;
use App\Models\User;
use App\Services\Mostrador\RecolectorBase;
use App\Services\Mostrador\RecolectorDia;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "¿CUÁNTO VENDÍ?" — EL RESUMEN DE VENTAS DEL ASISTENTE, CON EL MISMO CONJUNTO QUE RENDIMIENTO.
 *
 * Misión asistente-omnisciente (21/9/2026). Es la primera de las cuatro preguntas que fallaron:
 * "¿cuánto vendí la semana pasada?". `consultar_articulos_mas_vendidos` daba unidades por artículo
 * y `consultar_datos` paginaba filas, y el asistente terminaba sumando veinte ventas a mano.
 *
 * 🔴 EL CONJUNTO DE VENTAS ES EL DE RENDIMIENTO: RecolectorDia::ventas_del_periodo(), que delega en
 * consulta_ventas() —ventas reales, terminadas, en pesos, con el criterio de fecha del comercio
 * (Sale::fechaDeReportePorPedido) y la segunda puerta de `terminada_at`—. Si el número de acá y el
 * del reporte de Rendimiento difirieran, el dueño no tendría cómo saber cuál es el bueno. Los
 * totales (cantidad, total, ticket, a cuenta corriente, devoluciones) replican el criterio de
 * RecolectorDia::ventas() y ::devoluciones(); el test los compara contra recolectar() del mismo día.
 *
 * Las agrupaciones (día, semana, mes, sucursal, vendedor, método de pago, cliente, artículo, rubro,
 * proveedor, facturada) corren en SQL sobre ese mismo builder, con tope de 100 grupos y
 * `grupos_encontrados`.
 *
 * Misión asistente-ventas-y-fotos (21/9/2026): se suman `agrupar_por = facturada` —el comprobante
 * de ARCA, con el criterio canónico del sistema y la doble regla de la consolidación, ver
 * expresion_facturada()— y el parámetro `metodo_de_pago`, que ACOTA el conjunto en vez de solo
 * agruparlo, para poder cruzar "cuánto le vendí con tarjeta a Fulano". Y se escriben las tres
 * aclaraciones que el modelo venía inventando: qué es el "vendedor", qué cae en "Sin sucursal" y
 * por qué la cuenta corriente es un grupo único (ver nota_de_la_agrupacion()).
 *
 * ⚠️ DÓLARES. RecolectorDia solo sabe de pesos, así que para `moneda = dolares` el criterio de
 * fecha se replica acá (consulta_en_dolares()) con las mismas dos puertas. Las devoluciones y la
 * cuenta corriente en dólares no están resueltas en ese camino y viajan en null, dicho en la
 * respuesta.
 */
class ResumenDeVentasIaHelper
{
    /** Techo del rango en días: un año y pico, que es lo más que una pregunta razonable pide. */
    const TOPE_DIAS = 400;

    /** Tope de grupos por respuesta. */
    const TOPE_DE_GRUPOS = 100;

    /** Id del método de pago "Efectivo", el default histórico de una venta sin método cargado. */
    const METODO_PAGO_DEFAULT_ID = RecolectorDia::METODO_PAGO_DEFAULT_ID;

    /**
     * Agrupaciones válidas.
     *
     * 🔴 `facturada` VA AL FINAL Y LO NUEVO SE AGREGA ATRÁS: este array se interpola tal cual en el
     * `enum` de la definición de la tool, que es el prefijo que cachea AsistenteIaService::
     * con_cache_control(). Reordenarlo tira el caché de todas las conversaciones.
     */
    const AGRUPACIONES = ['dia', 'semana', 'mes', 'sucursal', 'vendedor', 'metodo_de_pago', 'cliente', 'articulo', 'rubro', 'proveedor', 'facturada'];

    /** Etiqueta del grupo de las ventas con comprobante de ARCA (ver expresion_facturada()). */
    const ETIQUETA_FACTURADA = 'Facturada ante ARCA';

    /** Etiqueta del grupo de las que no lo tienen. */
    const ETIQUETA_SIN_FACTURAR = 'Sin facturar';

    /** Etiqueta del grupo único de lo vendido a cuenta corriente (ver por_metodo_de_pago()). */
    const METODO_CUENTA_CORRIENTE = 'Cuenta corriente';

    /**
     * EL RESUMEN.
     *
     * @param  int          $owner_id     Dueño. Nunca Auth: corre en un job sin sesión.
     * @param  string       $desde        AAAA-MM-DD (inclusive).
     * @param  string       $hasta        AAAA-MM-DD (inclusive).
     * @param  string|null  $agrupar_por  Una de AGRUPACIONES, o null para los totales solos.
     * @param  string       $moneda       'pesos' (default, el conjunto de Rendimiento) o 'dolares'.
     * @param  string|null  $metodo_de_pago  Nombre del método de pago que ACOTA el conjunto, o null.
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function resumen(int $owner_id, $desde, $hasta, $agrupar_por = null, $moneda = 'pesos', $metodo_de_pago = null): array
    {
        $owner = User::find($owner_id);

        if (is_null($owner)) {
            return ['error' => 'No se encontro el negocio.'];
        }

        $dia_desde = CatalogoDeDatosIaHelper::dia_de($desde);
        $dia_hasta = CatalogoDeDatosIaHelper::dia_de($hasta);

        if (is_null($dia_desde) || is_null($dia_hasta)) {
            return ['error' => 'Necesito `desde` y `hasta` en formato AAAA-MM-DD (los dos, inclusive). Para un solo dia manda la misma fecha en los dos.'];
        }

        if ($dia_desde > $dia_hasta) {
            return ['error' => '`desde` (' . $dia_desde . ') es posterior a `hasta` (' . $dia_hasta . ').'];
        }

        $inicio = Carbon::parse($dia_desde)->startOfDay();
        $fin = Carbon::parse($dia_hasta)->startOfDay();

        if ($inicio->diffInDays($fin) + 1 > self::TOPE_DIAS) {
            return ['error' => 'El rango pedido tiene mas de ' . self::TOPE_DIAS . ' dias. Acotalo o pedilo en dos partes.'];
        }

        $moneda = strtolower(trim((string) $moneda));

        if ($moneda === '') {
            $moneda = 'pesos';
        }

        if ($moneda !== 'pesos' && $moneda !== 'dolares') {
            return ['error' => 'La moneda "' . $moneda . '" no existe: va pesos o dolares.'];
        }

        $agrupar_por = is_null($agrupar_por) ? '' : strtolower(trim((string) $agrupar_por));

        if ($agrupar_por !== '' && ! in_array($agrupar_por, self::AGRUPACIONES, true)) {
            return ['error' => 'No se puede agrupar por "' . $agrupar_por . '". Las opciones son: ' . implode(', ', self::AGRUPACIONES) . '.'];
        }

        $recolector = new RecolectorDia();

        $ventas = $moneda === 'pesos'
            ? $recolector->ventas_del_periodo($owner, $inicio, $fin)
            : self::consulta_en_dolares($owner, $inicio, $fin);

        // Cuántas quedaron afuera por estar en la otra moneda: que el modelo no diga "vendiste X"
        // sin saber que hay ventas en dólares que no están en ese X.
        $otra = $moneda === 'pesos'
            ? self::consulta_en_dolares($owner, $inicio, $fin)
            : $recolector->ventas_del_periodo($owner, $inicio, $fin);

        $filtro = self::filtro_de_metodo_de_pago($metodo_de_pago);

        if (isset($filtro['error'])) {

            return ['error' => $filtro['error']];
        }

        /*
         * El filtro por método de pago se aplica ANTES que nada, sobre los dos conjuntos: así los
         * totales, las agrupaciones y el conteo de la otra moneda hablan todos del mismo recorte.
         * Aplicarlo después dejaría un total filtrado con grupos sin filtrar, que es la clase de
         * respuesta que nadie puede detectar mirándola.
         */
        if (! is_null($filtro['metodo'])) {
            $ventas = self::acotar_por_metodo_de_pago($ventas, $filtro);
            $otra = self::acotar_por_metodo_de_pago($otra, $filtro);
        }

        $totales = self::totales($owner, $ventas, $inicio, $fin, $moneda);

        $respuesta = [
            'desde'   => $dia_desde,
            'hasta'   => $dia_hasta,
            'moneda'  => $moneda,
            'criterio' => 'El mismo conjunto de ventas que el reporte de Rendimiento: ventas reales (sin consolidaciones AFIP), terminadas, no borradas, en ' . $moneda . ', por la fecha ' . (Sale::fechaDeReportePorPedido($owner) ? 'de pedido (fecha de entrega o de carga)' : 'de carga (o de terminada)') . '.',
        ];

        $respuesta = array_merge($respuesta, $totales);

        $respuesta['ventas_en_otra_moneda'] = [
            'cantidad' => (int) (clone $otra)->count(),
            'moneda'   => $moneda === 'pesos' ? 'dolares' : 'pesos',
        ];

        if ($moneda === 'dolares') {
            $respuesta['aviso'] = 'En dolares no se calculan devoluciones ni lo que fue a cuenta corriente: esos dos van en null.';
        }

        if (! is_null($filtro['metodo'])) {
            $respuesta['filtrado_por_metodo_de_pago'] = $filtro['etiqueta'];

            /*
             * 🔴 LOS IMPORTES SIGUEN SIENDO EL TOTAL DE CADA VENTA, NO LA PARTE PAGADA CON ESE
             * MÉTODO. El filtro elige VENTAS que tocaron el método; una venta pagada mitad en
             * efectivo y mitad con tarjeta entra entera en las dos. Para repartir la plata entre
             * métodos está agrupar_por = metodo_de_pago, que sí prorratea por el pivot. Sin este
             * aviso el modelo suma los dos filtros y le informa al dueño más plata de la que vendió.
             */
            $respuesta['aviso_del_filtro'] = 'Son las ventas que TOCARON ese metodo de pago, y cada importe es el total de la venta, no la parte pagada con ese metodo: una venta pagada con dos metodos entra entera en los dos. Para repartir la plata entre metodos va agrupar_por = metodo_de_pago.';

            // devoluciones se calcula sobre el período entero y no sobre el conjunto, así que con un
            // filtro encima sería un número de otro universo: va en null y se dice por qué.
            $respuesta['devoluciones'] = null;
            $respuesta['aviso_de_devoluciones'] = 'Con el filtro por metodo de pago las devoluciones no se pueden acotar: van en null.';
        }

        if ($agrupar_por !== '') {
            $grupos = self::agrupar($owner, $ventas, $agrupar_por, $totales);

            $respuesta['agrupado_por'] = $agrupar_por;
            $respuesta['grupos_encontrados'] = $grupos['encontrados'];
            $respuesta['grupos_en_esta_lista'] = count($grupos['grupos']);
            $respuesta['grupos'] = $grupos['grupos'];

            if (isset($grupos['nota'])) {
                $respuesta['nota'] = $grupos['nota'];
            }
        }

        return $respuesta;
    }

    /**
     * Los totales del período: cantidad, total, ticket promedio, unidades (suma de
     * `article_sale.amount` del mismo conjunto), a cuenta corriente y devoluciones.
     *
     * `a_cuenta_corriente` replica RecolectorDia::ventas(): ventas con cliente, sin
     * `omitir_en_cuenta_corriente` y con su movimiento de cuenta corriente creado. `devoluciones`
     * reusa RecolectorDia::devoluciones() tal cual (ver recolector_expuesto()).
     *
     * @param  User    $owner
     * @param  \Illuminate\Database\Eloquent\Builder  $ventas
     * @param  Carbon  $inicio
     * @param  Carbon  $fin
     * @param  string  $moneda
     * @return array<string, mixed>
     */
    protected static function totales(User $owner, $ventas, Carbon $inicio, Carbon $fin, string $moneda): array
    {
        $fila = (clone $ventas)
            ->toBase()
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(sales.total), 0) as total')
            ->first();

        $cantidad = (int) $fila->cantidad;
        $total = round((float) $fila->total, 2);

        $unidades = (float) DB::table('article_sale')
            ->whereIn('sale_id', (clone $ventas)->select('sales.id'))
            ->selectRaw('COALESCE(SUM(amount), 0) as unidades')
            ->value('unidades');

        $a_cuenta_corriente = null;
        $devoluciones = null;

        if ($moneda === 'pesos') {
            $a_cuenta_corriente = round((float) self::ventas_a_cuenta_corriente($ventas)
                ->toBase()
                ->selectRaw('COALESCE(SUM(sales.total), 0) as total')
                ->value('total'), 2);

            $devoluciones = self::recolector_expuesto()->devoluciones_del_periodo($owner, $inicio, $fin->copy()->endOfDay());
            $devoluciones = round((float) $devoluciones['total'], 2);
        }

        return [
            'cantidad_de_ventas' => $cantidad,
            'total'              => $total,
            'ticket_promedio'    => $cantidad > 0 ? round($total / $cantidad, 2) : null,
            'unidades'           => round($unidades, 2),
            'a_cuenta_corriente' => $a_cuenta_corriente,
            'devoluciones'       => $devoluciones,
        ];
    }

    /**
     * Las ventas del conjunto que fueron a la cuenta corriente del cliente (mismo criterio que
     * RecolectorDia::ventas() y PerformanceHelper): con cliente, sin omitir y con el movimiento de
     * cuenta corriente creado.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $ventas
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function ventas_a_cuenta_corriente($ventas)
    {
        return (clone $ventas)
            ->whereNotNull('sales.client_id')
            ->where(function ($q) {
                $q->whereNull('sales.omitir_en_cuenta_corriente')->orWhere('sales.omitir_en_cuenta_corriente', 0);
            })
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('current_acounts')
                    ->whereColumn('current_acounts.sale_id', 'sales.id');
            });
    }

    /**
     * Las ventas de mostrador: las que NO fueron a cuenta corriente (el complemento exacto de
     * ventas_a_cuenta_corriente()).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $ventas
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function ventas_de_mostrador($ventas)
    {
        return (clone $ventas)->where(function ($q) {
            $q->whereNull('sales.client_id')
                ->orWhere(function ($omitida) {
                    $omitida->whereNotNull('sales.omitir_en_cuenta_corriente')->where('sales.omitir_en_cuenta_corriente', '<>', 0);
                })
                ->orWhereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('current_acounts')
                        ->whereColumn('current_acounts.sale_id', 'sales.id');
                });
        });
    }

    /**
     * El método de pago de la CABECERA de una venta, con el default histórico: una venta sin método
     * cargado se lee como Efectivo. Es la misma expresión que usa por_metodo_de_pago() para las
     * ventas sin pivot, extraída para que el filtro (C3) y la agrupación no puedan divergir.
     *
     * @return string
     */
    protected static function expresion_de_metodo_de_cabecera(): string
    {
        return 'CASE WHEN COALESCE(sales.current_acount_payment_method_id, 0) > 0 THEN sales.current_acount_payment_method_id ELSE ' . (int) self::METODO_PAGO_DEFAULT_ID . ' END';
    }

    /**
     * RESUELVE EL `metodo_de_pago` QUE PIDIÓ EL MODELO, por nombre.
     *
     * Misión asistente-ventas-y-fotos (21/9/2026, C3). Hasta hoy el método de pago solo se podía
     * AGRUPAR: para "cuánto le vendí con tarjeta a Fulano" el modelo tenía que pedir dos resúmenes y
     * cruzarlos a mano, que es justo lo que no sabe hacer. Con este parámetro el método ACOTA el
     * conjunto y se combina con cualquier `agrupar_por`.
     *
     * ⚠️ `current_acount_payment_methods` es una tabla GLOBAL, sin `user_id` (así la lee la pantalla,
     * ver OpcionesDeCargaIaHelper::metodos_de_pago()). Por eso la búsqueda por nombre no se scopea
     * por comercio: lo que sí es de cada comercio son las VENTAS, y eso ya lo filtra el conjunto.
     *
     * 🔴 "Cuenta corriente" NO es una fila de esa tabla: es el grupo único con el que
     * por_metodo_de_pago() representa lo vendido a cuenta corriente, donde en el momento de la venta
     * no hay método de pago sino una deuda. Se acepta igual como filtro, porque es exactamente lo
     * que el dueño pregunta ("cuánto vendí fiado"), y se resuelve contra el mismo conjunto que usa
     * la agrupación.
     *
     * @param  string|null  $nombre
     * @return array<string, mixed>  ['metodo' => null|'cuenta_corriente'|'mostrador', 'metodo_id' => ?int, 'etiqueta' => ?string] o ['error' => ...]
     */
    protected static function filtro_de_metodo_de_pago($nombre): array
    {
        $vacio = ['metodo' => null, 'metodo_id' => null, 'etiqueta' => null];

        $texto = is_null($nombre) ? '' : trim((string) $nombre);

        if ($texto === '') {

            return $vacio;
        }

        $plano = mb_strtolower($texto);

        if (strpos($plano, 'cuenta corriente') !== false || strpos($plano, 'cta corriente') !== false || $plano === 'fiado') {

            return ['metodo' => 'cuenta_corriente', 'metodo_id' => null, 'etiqueta' => self::METODO_CUENTA_CORRIENTE];
        }

        // Los comodines del LIKE se escapan: un nombre con "%" buscaría cualquier cosa.
        $escapado = addcslashes($texto, '%_\\');

        $candidatos = DB::table('current_acount_payment_methods')
            ->where('name', 'LIKE', '%' . $escapado . '%')
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($candidatos->count() > 1) {
            // Un nombre exacto desempata: "Efectivo" no tiene por qué chocar con "Efectivo dólar".
            $exactos = $candidatos->filter(function ($fila) use ($plano) {
                return mb_strtolower(trim((string) $fila->name)) === $plano;
            })->values();

            if ($exactos->count() === 1) {
                $candidatos = $exactos;
            }
        }

        if ($candidatos->count() === 0) {
            $todos = DB::table('current_acount_payment_methods')->orderBy('id')->pluck('name')->all();

            return ['error' => 'No hay ningun metodo de pago que se llame "' . $texto . '". Los que existen son: ' . implode(', ', $todos) . '. Tambien podes filtrar por "' . self::METODO_CUENTA_CORRIENTE . '".'];
        }

        if ($candidatos->count() > 1) {
            $nombres = [];

            foreach ($candidatos as $fila) {
                $nombres[] = (string) $fila->name;
            }

            return ['error' => 'Hay mas de un metodo de pago que encaja con "' . $texto . '": ' . implode(', ', $nombres) . '. Preguntale a la persona cual y volve a llamar con el nombre exacto.'];
        }

        $metodo = $candidatos->first();

        return ['metodo' => 'mostrador', 'metodo_id' => (int) $metodo->id, 'etiqueta' => (string) $metodo->name];
    }

    /**
     * ACOTA EL CONJUNTO A LAS VENTAS QUE TOCARON ESE MÉTODO DE PAGO.
     *
     * Espeja por_metodo_de_pago(): una venta de mostrador entra si tiene una fila del pivot
     * `current_acount_payment_method_sale` con ese método, o —si no tiene ninguna fila de pivot— si
     * el método de su cabecera es ese (con el default Efectivo). Lo vendido a cuenta corriente no
     * pasa por ninguna de las dos: es su propio grupo, y su filtro es el mismo predicado que usa la
     * agrupación.
     *
     * 🔴 LO QUE ESTO NO HACE, Y ESTÁ DICHO EN LA RESPUESTA: no prorratea. Devuelve ventas enteras,
     * así que una venta pagada mitad en efectivo y mitad con tarjeta aparece completa en los dos
     * filtros. Quien quiera repartir la plata usa agrupar_por = metodo_de_pago, que sí suma por el
     * pivot.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $ventas
     * @param  array  $filtro  El que devolvió filtro_de_metodo_de_pago().
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function acotar_por_metodo_de_pago($ventas, array $filtro)
    {
        if ($filtro['metodo'] === 'cuenta_corriente') {

            return self::ventas_a_cuenta_corriente($ventas);
        }

        $metodo_id = (int) $filtro['metodo_id'];
        $expresion_metodo = self::expresion_de_metodo_de_cabecera();

        return self::ventas_de_mostrador($ventas)->where(function ($q) use ($metodo_id, $expresion_metodo) {
            $q->whereExists(function ($pivot) use ($metodo_id) {
                $pivot->selectRaw('1')
                    ->from('current_acount_payment_method_sale')
                    ->whereColumn('current_acount_payment_method_sale.sale_id', 'sales.id')
                    ->where('current_acount_payment_method_sale.current_acount_payment_method_id', $metodo_id);
            })->orWhere(function ($sin_pivot) use ($metodo_id, $expresion_metodo) {
                $sin_pivot->whereNotExists(function ($pivot) {
                    $pivot->selectRaw('1')
                        ->from('current_acount_payment_method_sale')
                        ->whereColumn('current_acount_payment_method_sale.sale_id', 'sales.id');
                })->whereRaw($expresion_metodo . ' = ?', [$metodo_id]);
            });
        });
    }

    /**
     * Un RecolectorDia con `devoluciones()` expuesta.
     *
     * RecolectorDia::devoluciones() es protected y tiene la resolución de moneda de una nota de
     * crédito (cuenta → nota → pesos) que nadie quiere escribir dos veces. Exponerla acá con una
     * subclase anónima reusa el método SIN cambiarle la firma ni la visibilidad en RecolectorDia,
     * que es lo que pedía el plan (la única modificación a esa clase es ventas_del_periodo()).
     *
     * @return RecolectorDia
     */
    protected static function recolector_expuesto()
    {
        return new class extends RecolectorDia {
            /**
             * @param User $owner
             * @param Carbon $inicio
             * @param Carbon $fin
             * @return array{total: float, costos: float}
             */
            public function devoluciones_del_periodo(User $owner, Carbon $inicio, Carbon $fin): array
            {
                return $this->devoluciones($owner, $inicio, $fin);
            }
        };
    }

    /**
     * El conjunto de ventas EN DÓLARES del período, con el mismo criterio de fecha que
     * RecolectorDia::consulta_ventas() (Sale::scopeEnRangoDeFechas por el criterio del comercio, y
     * en el camino apagado la segunda puerta de `terminada_at`). Es la única réplica de ese
     * criterio fuera de RecolectorDia, y existe porque consulta_ventas() fija pesos adentro.
     *
     * @param  User    $owner
     * @param  Carbon  $desde
     * @param  Carbon  $hasta
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function consulta_en_dolares(User $owner, Carbon $desde, Carbon $hasta)
    {
        $query = Sale::query()
            ->where('sales.user_id', $owner->id)
            ->soloVentasReales()
            ->where('sales.terminada', 1)
            ->where('sales.moneda_id', 2);

        if (Sale::fechaDeReportePorPedido($owner)) {
            return $query->enRangoDeFechas($desde, $hasta, $owner);
        }

        $inicio = $desde->copy()->startOfDay()->format('Y-m-d H:i:s');
        $fin_exclusivo = $hasta->copy()->startOfDay()->addDay()->format('Y-m-d H:i:s');

        return $query->where(function ($q) use ($desde, $hasta, $owner, $inicio, $fin_exclusivo) {
            $q->where(function ($q2) use ($desde, $hasta, $owner) {
                $q2->enRangoDeFechas($desde, $hasta, $owner);
            })->orWhere(function ($q2) use ($inicio, $fin_exclusivo) {
                $q2->where('sales.terminada_at', '>=', $inicio)
                    ->where('sales.terminada_at', '<', $fin_exclusivo);
            });
        });
    }

    /**
     * La expresión de fecha por la que se agrupan día/semana/mes: la misma que usa el scope
     * Sale::scopeEnRangoDeFechas para decidir qué ventas entran (fecha de pedido con la
     * preferencia prendida, `created_at` apagada).
     *
     * ⚠️ En el camino apagado una venta puede haber entrado al conjunto por `terminada_at`: su día
     * de grupo sigue siendo el de `created_at`, así que puede caer fuera del rango pedido. Es el
     * mismo desfasaje que ya tiene Rendimiento; no se inventa una tercera fecha acá.
     *
     * @param  User  $owner
     * @return string
     */
    protected static function expresion_de_fecha(User $owner): string
    {
        return Sale::fechaDeReportePorPedido($owner) ? Sale::EXPRESION_FECHA_DE_PEDIDO : 'sales.created_at';
    }

    /**
     * Las agrupaciones. Cada grupo: `etiqueta`, `cantidad` (ventas, o unidades cuando se agrupa
     * por artículo, rubro o proveedor) y `total`, ordenados por total descendente y topeados.
     *
     * @param  User    $owner
     * @param  \Illuminate\Database\Eloquent\Builder  $ventas
     * @param  string  $agrupar_por
     * @param  array   $totales
     * @return array<string, mixed>  ['grupos' => [...], 'encontrados' => n, 'nota' => ?]
     */
    protected static function agrupar(User $owner, $ventas, string $agrupar_por, array $totales): array
    {
        if ($agrupar_por === 'metodo_de_pago') {
            return self::por_metodo_de_pago($ventas, $totales);
        }

        if (in_array($agrupar_por, ['articulo', 'rubro', 'proveedor'], true)) {
            return self::por_articulo($owner, $ventas, $agrupar_por);
        }

        $fecha = self::expresion_de_fecha($owner);

        $expresiones = [
            'dia'       => 'DATE(' . $fecha . ')',
            'semana'    => 'YEARWEEK(' . $fecha . ', 3)',
            'mes'       => "DATE_FORMAT(" . $fecha . ", '%Y-%m')",
            'sucursal'  => 'COALESCE(sales.address_id, 0)',
            'vendedor'  => 'COALESCE(sales.employee_id, 0)',
            'cliente'   => 'COALESCE(sales.client_id, 0)',
            'facturada' => self::expresion_facturada(),
        ];

        $expresion = $expresiones[$agrupar_por];

        $filas = (clone $ventas)
            ->toBase()
            ->selectRaw($expresion . ' as grupo, COUNT(*) as cantidad, COALESCE(SUM(sales.total), 0) as total')
            ->groupByRaw($expresion)
            ->orderByRaw('total DESC, grupo ASC')
            ->get();

        $nombres = self::nombres_para($owner, $agrupar_por, $filas);

        $grupos = [];

        foreach ($filas as $fila) {
            $etiqueta = self::etiqueta_del_grupo($owner, $agrupar_por, $fila->grupo, $nombres);

            /*
             * Vendedor: replica nombre_de_empleado() de RecolectorBase — un employee_id que no es
             * de la cuenta cuenta como el dueño, así que dos grupos pueden caer en la misma
             * etiqueta y se funden.
             */
            if (isset($grupos[$etiqueta])) {
                $grupos[$etiqueta]['cantidad'] += (int) $fila->cantidad;
                $grupos[$etiqueta]['total'] += (float) $fila->total;
                continue;
            }

            $grupos[$etiqueta] = [
                'etiqueta' => $etiqueta,
                'cantidad' => (int) $fila->cantidad,
                'total'    => (float) $fila->total,
            ];
        }

        $resultado = self::ordenar_y_topear(array_values($grupos));

        $nota = self::nota_de_la_agrupacion($agrupar_por);

        if (! is_null($nota)) {
            $resultado['nota'] = $nota;
        }

        return $resultado;
    }

    /**
     * LO QUE UNA AGRUPACIÓN NO DICE Y EL MODELO IBA A ASUMIR. Tres etiquetas mienten si se leen
     * literales, y las tres se aclaran acá en vez de cambiar la columna que agrupa:
     *
     * - `vendedor` agrupa por `sales.employee_id`, que es QUIEN CARGÓ la venta en el sistema, no el
     *   vendedor comisionista (`sales.seller_id`, que es otra tabla y otro reporte). En un comercio
     *   donde carga siempre el mismo usuario, "ventas por vendedor" da un solo grupo con el 100% y
     *   el dueño lo lee como que vende una sola persona. 🔴 Decisión de Lucas del 21/9/2026: la
     *   columna SE MANTIENE —es la que la pantalla de Rendimiento usa— y lo que se corrige es lo que
     *   se dice de ella.
     *
     * - `sucursal` agrupa por `sales.address_id`, que es NULLABLE: toda venta cargada sin sucursal
     *   cae en "Sin sucursal", y en un negocio de una sola sucursal eso puede ser el 100% de las
     *   ventas. Sin el aviso se lee como un dato faltante o como una sucursal borrada.
     *
     * - `facturada` mira el comprobante de ARCA, no el cobro: una venta facturada puede estar impaga
     *   y una cobrada puede no estar facturada.
     *
     * @param  string  $agrupar_por
     * @return string|null
     */
    protected static function nota_de_la_agrupacion(string $agrupar_por)
    {
        if ($agrupar_por === 'vendedor') {

            return 'vendedor es el USUARIO QUE CARGO la venta en el sistema (sales.employee_id), no el vendedor comisionista: son dos cosas distintas y esta es la que usa el reporte de Rendimiento. Si todas las ventas las carga la misma persona vas a ver un solo grupo, y eso no significa que venda una sola persona.';
        }

        if ($agrupar_por === 'sucursal') {

            return 'la sucursal de una venta es opcional: todo lo que se cargo sin sucursal cae en "Sin sucursal", y en un negocio de una sola sucursal eso puede ser el 100%. No lo leas como un dato perdido ni como una sucursal borrada.';
        }

        if ($agrupar_por === 'facturada') {

            return 'facturada es si la venta tiene comprobante de ARCA con CAE, no si se cobro: una venta facturada puede estar impaga y una cobrada puede no estar facturada. Una venta incluida en una consolidacion AFIP cuenta como facturada aunque el comprobante lo tenga la venta que la agrupa.';
        }

        return null;
    }

    /**
     * ¿ESTA VENTA TIENE COMPROBANTE DE ARCA? Devuelve la expresión SQL que da 1 o 0.
     *
     * 🔴 EL CRITERIO ES `afip_tickets.cae` NO VACÍO, NUNCA `sales.total_facturado`.
     * `total_facturado` es un decimal ACUMULATIVO: `AfipWsHelper::update_sale_total_facturado()` le
     * suma al facturar y `AfipNotaCreditoHelper` le resta al anular, así que una venta facturada y
     * después anulada queda en `0` —igual que una que nunca se facturó— y una facturada dos veces
     * queda en el doble. Contar con esa columna da dos grupos plausibles y falsos. El criterio
     * canónico del sistema es el de `AfipTicketController::problemas_al_facturar()`: `cae` nulo o
     * vacío = no facturada, aunque `resultado` diga 'A'.
     *
     * 🔴 Y LA CONSOLIDACIÓN AFIP TIENE DOBLE REGLA. Una venta consolidada NO tiene ticket propio: su
     * comprobante lo emitió la venta CONTENEDORA, y la relación es `consolidacion_facturacion_id`.
     * Mirar solo el ticket propio las contaría a todas como "sin facturar", que es exactamente el
     * error que el comerciante no puede detectar —son ventas reales, facturadas de verdad—. Por eso
     * el segundo EXISTS. (Las contenedoras ya están fuera del conjunto por `soloVentasReales()`, que
     * es lo correcto para los montos: su plata ya está en las ventas que agrupan.)
     *
     * Los tickets borrados no cuentan: `AfipTicket` usa SoftDeletes y `problemas_al_facturar()` los
     * excluye solo, por el modelo. Acá la query es cruda, así que el `deleted_at` va escrito.
     *
     * @return string
     */
    protected static function expresion_facturada(): string
    {
        $con_cae = 'afip_tickets.deleted_at IS NULL AND afip_tickets.cae IS NOT NULL AND afip_tickets.cae <> \'\'';

        $propio = 'EXISTS (SELECT 1 FROM afip_tickets WHERE afip_tickets.sale_id = sales.id AND ' . $con_cae . ')';

        $de_la_consolidacion = 'EXISTS (SELECT 1 FROM afip_tickets WHERE afip_tickets.sale_id = sales.consolidacion_facturacion_id AND ' . $con_cae . ')';

        return 'CASE WHEN (' . $propio . ' OR ' . $de_la_consolidacion . ') THEN 1 ELSE 0 END';
    }

    /**
     * Los nombres que hacen falta para etiquetar una agrupación, en una consulta.
     *
     * @param  User    $owner
     * @param  string  $agrupar_por
     * @param  mixed   $filas
     * @return array<int, string>
     */
    protected static function nombres_para(User $owner, string $agrupar_por, $filas): array
    {
        if ($agrupar_por === 'sucursal') {
            return DB::table('addresses')->where('user_id', $owner->id)->pluck('street', 'id')->map(function ($v) {
                return (string) $v;
            })->all();
        }

        if ($agrupar_por === 'vendedor') {
            $mapa = [(int) $owner->id => (string) $owner->name];

            foreach (DB::table('users')->where('owner_id', $owner->id)->get(['id', 'name']) as $empleado) {
                $mapa[(int) $empleado->id] = (string) $empleado->name;
            }

            return $mapa;
        }

        if ($agrupar_por === 'cliente') {
            $ids = [];

            foreach ($filas as $fila) {
                if ((int) $fila->grupo > 0) {
                    $ids[] = (int) $fila->grupo;
                }
            }

            if (empty($ids)) {
                return [];
            }

            // Con borrados: el cliente pudo borrarse después de la venta, y la venta sigue siendo real.
            return DB::table('clients')->whereIn('id', $ids)->pluck('name', 'id')->map(function ($v) {
                return (string) $v;
            })->all();
        }

        return [];
    }

    /**
     * @param  User    $owner
     * @param  string  $agrupar_por
     * @param  mixed   $grupo
     * @param  array   $nombres
     * @return string
     */
    protected static function etiqueta_del_grupo(User $owner, string $agrupar_por, $grupo, array $nombres): string
    {
        if ($agrupar_por === 'dia') {
            $dia = Carbon::parse((string) $grupo);

            return $dia->format('Y-m-d') . ' (' . RecolectorBase::DIAS_SEMANA[$dia->dayOfWeek] . ')';
        }

        if ($agrupar_por === 'semana') {
            $texto = (string) $grupo;

            return substr($texto, 0, 4) . '-S' . substr($texto, 4);
        }

        if ($agrupar_por === 'mes') {
            return (string) $grupo;
        }

        $id = (int) $grupo;

        if ($agrupar_por === 'facturada') {

            return $id === 1 ? self::ETIQUETA_FACTURADA : self::ETIQUETA_SIN_FACTURAR;
        }

        if ($agrupar_por === 'sucursal') {
            return $id > 0 && isset($nombres[$id]) ? $nombres[$id] : 'Sin sucursal';
        }

        if ($agrupar_por === 'vendedor') {
            return $id > 0 && isset($nombres[$id]) ? $nombres[$id] : (string) ($owner->name ?: 'Dueño');
        }

        // cliente
        if ($id <= 0) {
            return 'Sin cliente (mostrador)';
        }

        return isset($nombres[$id]) ? $nombres[$id] : 'cliente borrado';
    }

    /**
     * Por método de pago, replicando RecolectorDia::por_metodo_pago(): las ventas de mostrador
     * reparten su plata por el pivot `current_acount_payment_method_sale` (monto menos descuento) y,
     * sin pivot, van enteras al método de la cabecera (Efectivo si no hay); lo vendido a cuenta
     * corriente entra como el grupo "Cuenta corriente". `cantidad` es cuántas ventas tocaron ese
     * método (una venta con dos métodos cuenta en los dos).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $ventas
     * @param  array  $totales
     * @return array<string, mixed>
     */
    protected static function por_metodo_de_pago($ventas, array $totales): array
    {
        $mostrador = self::ventas_de_mostrador($ventas);

        $con_pivot = DB::table('current_acount_payment_method_sale')
            ->whereIn('sale_id', (clone $mostrador)->select('sales.id'))
            ->groupBy('current_acount_payment_method_id')
            ->selectRaw('current_acount_payment_method_id as metodo_id, COUNT(DISTINCT sale_id) as cantidad, COALESCE(SUM(amount - COALESCE(discount_amount, 0)), 0) as total')
            ->get();

        $expresion_metodo = self::expresion_de_metodo_de_cabecera();

        $sin_pivot = (clone $mostrador)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('current_acount_payment_method_sale')
                    ->whereColumn('current_acount_payment_method_sale.sale_id', 'sales.id');
            })
            ->toBase()
            ->selectRaw($expresion_metodo . ' as metodo_id, COUNT(*) as cantidad, COALESCE(SUM(sales.total), 0) as total')
            ->groupByRaw($expresion_metodo)
            ->get();

        $totales_por_metodo = [];

        foreach ([$con_pivot, $sin_pivot] as $lote) {
            foreach ($lote as $fila) {
                $metodo_id = (int) $fila->metodo_id;

                if (! isset($totales_por_metodo[$metodo_id])) {
                    $totales_por_metodo[$metodo_id] = ['cantidad' => 0, 'total' => 0.0];
                }

                $totales_por_metodo[$metodo_id]['cantidad'] += (int) $fila->cantidad;
                $totales_por_metodo[$metodo_id]['total'] += (float) $fila->total;
            }
        }

        $nombres = [];

        if (! empty($totales_por_metodo)) {
            $nombres = DB::table('current_acount_payment_methods')
                ->whereIn('id', array_keys($totales_por_metodo))
                ->pluck('name', 'id')
                ->map(function ($v) {
                    return (string) $v;
                })
                ->all();
        }

        $grupos = [];

        foreach ($totales_por_metodo as $metodo_id => $suma) {
            $grupos[] = [
                'etiqueta' => isset($nombres[$metodo_id]) ? $nombres[$metodo_id] : 'Método #' . $metodo_id,
                'cantidad' => $suma['cantidad'],
                'total'    => $suma['total'],
            ];
        }

        if (! is_null($totales['a_cuenta_corriente']) && $totales['a_cuenta_corriente'] > 0) {
            $grupos[] = [
                'etiqueta' => self::METODO_CUENTA_CORRIENTE,
                'cantidad' => (int) self::ventas_a_cuenta_corriente($ventas)->count(),
                'total'    => (float) $totales['a_cuenta_corriente'],
            ];
        }

        $resultado = self::ordenar_y_topear($grupos);

        /*
         * 🔴 EL LÍMITE QUE EL MODELO IBA A OMITIR, Y ES PLATA. Lo vendido a cuenta corriente entra
         * como UN grupo y no se desglosa por cómo se cobró después: en el momento de la venta no hay
         * método de pago, hay una deuda, y el puente cobro→venta no existe (decisión de Lucas,
         * 21/9/2026: queda así). Sin decirlo, el asistente contesta "cobraste X en efectivo" cuando
         * buena parte de lo cobrado en efectivo entró como pago de cuenta corriente y está en el
         * otro grupo.
         */
        $resultado['nota'] = 'el grupo "' . self::METODO_CUENTA_CORRIENTE . '" es lo VENDIDO a cuenta corriente y no se desglosa por como se cobro despues: en el momento de la venta no hay metodo de pago, hay una deuda. Y una venta pagada con dos metodos cuenta en los dos grupos, con la parte que le toca a cada uno.';

        return $resultado;
    }

    /**
     * Por artículo, rubro o proveedor: los renglones (`article_sale`) de las ventas del conjunto,
     * agrupados por el artículo o por su rubro / proveedor de HOY. `cantidad` son unidades y
     * `total` es unidades × precio del renglón con su descuento de renglón: los descuentos y
     * recargos globales de la venta no se prorratean, así que la suma de los grupos puede no dar
     * el total del período — se dice en `nota`.
     *
     * @param  User    $owner
     * @param  \Illuminate\Database\Eloquent\Builder  $ventas
     * @param  string  $agrupar_por
     * @return array<string, mixed>
     */
    protected static function por_articulo(User $owner, $ventas, string $agrupar_por): array
    {
        $columnas = [
            'articulo'  => 'article_sale.article_id',
            'rubro'     => 'COALESCE(articles.category_id, 0)',
            'proveedor' => 'COALESCE(articles.provider_id, 0)',
        ];

        $expresion = $columnas[$agrupar_por];

        $filas = DB::table('article_sale')
            ->join('articles', 'articles.id', '=', 'article_sale.article_id')
            ->whereIn('article_sale.sale_id', (clone $ventas)->select('sales.id'))
            ->selectRaw($expresion . ' as grupo, COALESCE(SUM(article_sale.amount), 0) as cantidad, COALESCE(SUM(article_sale.amount * COALESCE(article_sale.price, 0) * (1 - COALESCE(article_sale.discount, 0) / 100)), 0) as total')
            ->groupByRaw($expresion)
            ->orderByRaw('total DESC, grupo ASC')
            ->get();

        $ids = [];

        foreach ($filas as $fila) {
            if ((int) $fila->grupo > 0) {
                $ids[] = (int) $fila->grupo;
            }
        }

        $tablas = ['articulo' => 'articles', 'rubro' => 'categories', 'proveedor' => 'providers'];
        $sin = ['articulo' => 'articulo borrado', 'rubro' => 'Sin rubro', 'proveedor' => 'Sin proveedor'];

        $nombres = empty($ids) ? [] : DB::table($tablas[$agrupar_por])->whereIn('id', $ids)->pluck('name', 'id')->map(function ($v) {
            return (string) $v;
        })->all();

        $grupos = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->grupo;

            $grupos[] = [
                'etiqueta' => $id > 0 && isset($nombres[$id]) ? $nombres[$id] : $sin[$agrupar_por],
                'cantidad' => (float) $fila->cantidad,
                'total'    => (float) $fila->total,
            ];
        }

        $resultado = self::ordenar_y_topear($grupos);

        $resultado['nota'] = 'cantidad son unidades. total es unidades por precio del renglon (con su descuento de renglon); los descuentos o recargos globales de cada venta no se prorratean, asi que la suma de los grupos puede no coincidir con el total del periodo.';

        return $resultado;
    }

    /**
     * Ordena por total descendente (desempate por etiqueta), redondea y aplica el tope.
     *
     * @param  array  $grupos
     * @return array<string, mixed>
     */
    protected static function ordenar_y_topear(array $grupos): array
    {
        usort($grupos, function ($a, $b) {
            if ($a['total'] == $b['total']) {
                return strcmp($a['etiqueta'], $b['etiqueta']);
            }

            return $b['total'] <=> $a['total'];
        });

        $lista = [];

        foreach (array_slice($grupos, 0, self::TOPE_DE_GRUPOS) as $grupo) {
            $grupo['total'] = round((float) $grupo['total'], 2);
            $lista[] = $grupo;
        }

        return ['grupos' => $lista, 'encontrados' => count($grupos)];
    }
}
