<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Retención de los eventos crudos de comportamiento de compradores: borra lo que
 * tiene más de DIAS_RETENCION días de antigüedad
 * (misión tracking-buyers-tienda).
 *
 * `buyer_tracking_events` es la tabla de alto volumen del sistema —una fila por
 * vista de producto de cada visitante anónimo— y sin retención crece sin techo.
 * Lo que sobrevive al corte es el agregado de `buyer_tracking_daily`, que este
 * comando no toca nunca.
 *
 * El borrado va POR LOTES y no de un saque, y ese es el punto del comando. Un
 * DELETE de cientos de miles de filas mantiene el lock mientras dura y es
 * exactamente el mecanismo del `1205 Lock wait timeout` que ya tumbó ventas en
 * producción en este sistema. El bucle de lotes chicos deja huecos entre
 * sentencia y sentencia para que el resto de la aplicación siga escribiendo.
 *
 * Y purga TODOS los `user_id` que estén en la tabla, no sólo el de
 * `config('app.USER_ID')`. El motivo: el `user_id` que se escribe en cada fila
 * viene del `commerce_id` que manda el SPA de la tienda (`VUE_APP_COMMERCE_ID`),
 * que es OTRA variable de entorno, en OTRO repo, sin nada que la ate a la de acá.
 * Si alguna vez se escribe una fila con un user_id distinto —un env mal copiado
 * al desplegar, un cliente apuntando a la base equivocada—, una purga que filtra
 * por el user_id configurado no la borra NUNCA: la tabla de alto volumen crece
 * para siempre mientras el comando reporta alegremente "se borraron 0". Es la
 * clase de error de la medición que falla devolviendo un valor tranquilizador.
 */
class PurgarBuyerTracking extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'tracking:purgar-buyers {--dias= : Días de retención; por defecto DIAS_RETENCION}';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Borra por lotes los eventos crudos de tracking de compradores más viejos que la retención';

    /**
     * Días de retención de los eventos crudos.
     *
     * 90 es decisión de Lucas del 15/8/2026, tomada antes de construir: es la
     * ventana en la que el comportamiento reciente todavía sirve para el motor de
     * ofertas. Más atrás la pregunta que se hace el ERP es de tendencia, y esa la
     * contesta el agregado diario, que no se purga.
     *
     * @var int
     */
    const DIAS_RETENCION = 90;

    /**
     * Filas por sentencia DELETE. Chico a propósito: es el tamaño del lock que se
     * toma por vez.
     *
     * @var int
     */
    const FILAS_POR_LOTE = 5000;

    /**
     * Tope de vueltas del bucle de borrado, para que no pueda quedar girando para
     * siempre si algo reinserta filas viejas mientras purga. FILAS_POR_LOTE x
     * MAX_VUELTAS = 5 millones de filas por corrida; lo que sobre se lleva la
     * corrida de mañana.
     *
     * @var int
     */
    const MAX_VUELTAS = 1000;

    /**
     * Tope de la opción --dias: 10 años, mucho más que cualquier retención que
     * alguien vaya a querer y lejos del borde donde la aritmética de fechas se
     * rompe.
     *
     * El borde, medido: `--dias=99999999999999999999` pasa ctype_digit sin problema
     * y castea a PHP_INT_MAX. `now()->subDays(PHP_INT_MAX)` NO tira una excepción
     * —sería lo cómodo—: desborda y devuelve una fecha del año 2343668, o sea un
     * límite de retención en el FUTURO. Ese valor se lo come después el DELETE y
     * MySQL lo rechaza con `SQLSTATE[22007] 1292 Incorrect datetime value`, así que
     * el comando se cae con una QueryException sin atrapar en el medio de la purga.
     * El tope corta antes de todo eso, y con un mensaje que dice qué pasó.
     *
     * @var int
     */
    const MAX_DIAS = 3650;

    /**
     * Ejecuta la purga sobre el dueño de la instancia (config app.USER_ID).
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        /*
         * Guarda barata primero, como las tres guardas ordenadas de
         * DemoEventoEmitter::emitir(). Es la compatibilidad hacia atrás del
         * contrato con tienda-api: hay instancias con el código nuevo y todavía
         * sin las tablas, y el cron corre igual todas las noches.
         */
        if (!Schema::hasTable('buyer_tracking_events')) {
            return 0;
        }

        $user = $this->resolver_comercio();

        if (!$user) {
            $this->info('tracking:purgar-buyers — no hay comercio configurado (app.USER_ID), no se purga nada.');
            return 0;
        }

        /*
         * Doble gate: el Kernel ya filtra por extensión, pero el comando se puede
         * correr a mano y la extensión es lo que se le vendió (o no) al cliente.
         */
        if (!UserHelper::hasExtencion('tracking_buyers', $user)) {
            $this->info('tracking:purgar-buyers — el comercio no tiene la extensión tracking_buyers, no se purga nada.');
            return 0;
        }

        $dias = $this->resolver_dias();

        if (is_null($dias)) {
            $this->error(
                'tracking:purgar-buyers — la opción --dias tiene que ser un entero entre 1 y '
                . self::MAX_DIAS . ', no se purga nada.'
            );
            return 1;
        }

        $limite = now()->subDays($dias);

        $borradas = 0;

        /*
         * Se recorren los user_id que REALMENTE están en la tabla, y se purga cada
         * uno con el mismo bucle de lotes. Así se sigue entrando por
         * bte_user_ocurrido_index (user_id, occurred_at) —que es el motivo por el
         * que el DELETE fija el user_id, ver purgar_por_lotes()— y además no queda
         * ninguna fila afuera por tener un user_id que nadie esperaba.
         */
        foreach ($this->user_ids_presentes() as $user_id) {

            if ((int) $user_id !== (int) $user->id) {
                /*
                 * No es un caso normal: significa que se escribieron eventos con un
                 * commerce_id que no es el de esta instancia. Se purgan igual (para
                 * eso está el recorrido), pero se avisa, porque lo que hay atrás es
                 * una configuración mal puesta en algún despliegue.
                 */
                Log::warning('tracking:purgar-buyers: hay eventos con un user_id que no es el de la instancia. Se purgan igual, pero revisar el commerce_id que manda el SPA de la tienda.', [
                    'user_id_en_la_tabla'  => (int) $user_id,
                    'user_id_configurado'  => (int) $user->id,
                ]);
            }

            $borradas += $this->purgar_por_lotes($user_id, $limite);
        }

        $this->info(
            'tracking:purgar-buyers — se borraron ' . $borradas . ' eventos anteriores a '
            . $limite->format('Y-m-d H:i:s') . ' (retención de ' . $dias . ' días).'
        );

        return 0;
    }

    /**
     * Los `user_id` distintos presentes hoy en la tabla.
     *
     * Es barato aunque la tabla sea enorme: el SELECT DISTINCT lo resuelve
     * bte_user_ocurrido_index sin tocar los datos, y en una base de cliente el
     * resultado tiene casi siempre un solo valor.
     *
     * @return array<int, int>
     */
    protected function user_ids_presentes()
    {
        return DB::table('buyer_tracking_events')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all();
    }

    /**
     * Días de retención efectivos: los de --dias si vino, la constante si no vino.
     *
     * Un --dias que no sea un entero positivo y razonable CORTA el comando, no cae
     * en silencio a la constante. El operador que escribió `--dias=7` con un typo
     * quiso purgar a 7 días; si el comando purga a 90 y dice que todo salió bien, se
     * queda creyendo que hizo algo que no hizo. Es el mismo criterio que --fecha en
     * `tracking:agregar-buyers`, que corta con exit 1 ante un formato inválido: dos
     * comandos hermanos no pueden tratar una opción mal escrita de dos maneras
     * distintas.
     *
     * Casos que corta: 0 (borraría todo el histórico), negativos, decimales, texto,
     * y cualquier valor por encima de MAX_DIAS (ver el porqué medido en esa
     * constante).
     *
     * @return int|null Días de retención, o null si --dias vino inválido
     */
    protected function resolver_dias()
    {
        $opcion = $this->option('dias');

        if (is_null($opcion)) {
            return self::DIAS_RETENCION;
        }

        if (!ctype_digit((string) $opcion)) {
            return null;
        }

        $dias = (int) $opcion;

        if ($dias <= 0 || $dias > self::MAX_DIAS) {
            return null;
        }

        return $dias;
    }

    /**
     * Bucle de borrado por lotes. Corta cuando un DELETE no borró nada (no queda
     * nada viejo) o cuando se agotaron las vueltas.
     *
     * @param int $user_id
     * @param \Carbon\Carbon $limite Eventos con occurred_at anterior a esto se borran
     * @return int Cantidad total de filas borradas
     */
    protected function purgar_por_lotes($user_id, $limite)
    {
        $borradas = 0;

        for ($vuelta = 0; $vuelta < self::MAX_VUELTAS; $vuelta++) {

            /*
             * El where por user_id no es decorativo: es lo que le permite a MySQL
             * entrar por bte_user_ocurrido_index (user_id, occurred_at). Sin el
             * user_id fijo, el rango sobre occurred_at no tiene prefijo por donde
             * empezar y el DELETE degrada a full scan.
             *
             * Por eso el comando NO borra con un solo DELETE global: recorre los
             * user_id presentes (ver user_ids_presentes()) y llama acá una vez por
             * cada uno. Se queda con el índice Y no deja filas inmortales, que es lo
             * que pasaba cuando el único user_id posible era el de la config.
             */
            $lote = DB::table('buyer_tracking_events')
                ->where('user_id', $user_id)
                ->where('occurred_at', '<', $limite)
                ->limit(self::FILAS_POR_LOTE)
                ->delete();

            $borradas += $lote;

            if ($lote < self::FILAS_POR_LOTE) {
                break;
            }
        }

        return $borradas;
    }

    /**
     * Dueño de la instancia con sus extensiones cargadas (mismo criterio que el
     * resolve del Kernel).
     *
     * @return User|null
     */
    protected function resolver_comercio()
    {
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            return null;
        }

        return User::with('extencions')->find($user_id);
    }
}
