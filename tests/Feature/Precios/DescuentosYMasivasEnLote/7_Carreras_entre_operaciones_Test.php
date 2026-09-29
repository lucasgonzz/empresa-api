<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Mision `recalculo-precios-motor-rapido`, seguimiento del 29/9/2026 — dos operaciones sobre los
 * descuentos del MISMO proveedor que se cruzan (bloqueante del chequeo independiente).
 *
 * 🔴 LA CARRERA. La sincronizacion y la propagacion arman un plan (que borrar, que crear) y
 * despues escriben. Si entre el plan de una y su escritura corre la otra entera —la propagacion en
 * segundo plano contra una propagacion sincronica al volver a guardar el proveedor, o una
 * sincronizacion manual contra otra—, la escritura vieja borraba por ids que ya no existian (no
 * borraba nada) e insertaba OTRA copia de la ficha: 15, 5, 15, 5 y el precio un 19 % mas bajo, sin
 * ningun error. Ahora cada tanda se revalida con candado contra lo que vio su plan y el articulo que
 * otro proceso toco despues se saltea entero (ArticleProviderDiscountHelper::revalidar_contra_el_plan()).
 *
 * Se reproduce la intercalacion EXACTA del chequeo, en un solo proceso: plan de B -> A corre entera
 * (con sus transacciones y su commit) -> B escribe con el plan viejo. Lo que tiene que quedar es lo
 * mismo que deja A sola: ni un descuento, ni un precio, ni un price_change de mas.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Carreras_entre_operaciones_Test extends DescuentosYMasivasEnLoteTestCase
{
    /** @var array|null Lo que devolvio la operacion que escribio con el plan viejo. */
    private $resultado_de_la_segunda = null;

    /** @var bool Si la operacion del medio llego a correr (el callback traga excepciones). */
    private $corrio_la_del_medio = false;

    /** @var \PDO|null Una segunda conexion real, independiente de Laravel (ver abrir_segunda_conexion()). */
    private $segunda = null;

    /** @var array Filas que confirmo la segunda conexion, para borrarlas al final: [tabla => [ids]]. */
    private $confirmadas = [];

    /**
     * Primero se revierte la transaccion del test (que tiene tomado el candado del proveedor hasta ese
     * momento), y despues la segunda conexion borra lo que confirmo.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_null($this->segunda)) {

            foreach ($this->confirmadas as $tabla => $ids) {

                if (count($ids) > 0) {
                    $marcas = implode(', ', array_fill(0, count($ids), '?'));
                    $this->segunda->prepare('DELETE FROM `' . $tabla . '` WHERE `id` IN (' . $marcas . ')')->execute($ids);
                }
            }

            $this->segunda = null;
            $this->confirmadas = [];
        }
    }

    /**
     * Comercio con la preferencia prendida y un proveedor con la ficha en 15 % + 5 %.
     *
     * @return array ['dueno', 'provider']
     */
    private function comercio_con_ficha()
    {
        $dueno = $this->crear_dueno(['aplicar_descuentos_proveedor_al_asignar' => 1]);

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);

        $this->descuento_de_la_ficha($provider, 15, 'Bonif general');
        $this->descuento_de_la_ficha($provider, 5, 'Pronto pago');

        return ['dueno' => $dueno, 'provider' => $provider];
    }

    /**
     * Cuantas filas de la ficha tiene cada articulo, sacado de la foto de descuentos.
     *
     * @param  array $foto_de_descuentos  [article_id => [filas]]
     * @return array [article_id => cantidad]
     */
    private function de_la_ficha_por_articulo(array $foto_de_descuentos)
    {
        $cantidades = [];

        foreach ($foto_de_descuentos as $article_id => $filas) {

            $cantidades[$article_id] = count(array_filter($filas, function ($fila) {
                return $fila['origen'] === 'ficha_proveedor';
            }));
        }

        return $cantidades;
    }

    /**
     * 🔴 Propagacion contra propagacion: B planifica, A propaga entera, B escribe con su plan viejo.
     * Tiene que quedar lo mismo que con A sola, y B no cuenta ningun articulo como actualizado.
     *
     * @test
     */
    public function una_propagacion_que_escribe_con_un_plan_viejo_no_duplica_los_descuentos()
    {
        $c = $this->comercio_con_ficha();

        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $article = $this->crear_articulo($c['dueno'], ['cost' => 1000 + $i * 11.5, 'provider_id' => $c['provider']->id]);
            $this->copia_de_la_ficha($article, $c['provider'], 10);
            $ids[] = $article->id;
        }

        $this->calentar($ids, $c['dueno']->id);

        $provider_id = $c['provider']->id;
        $dueno_id    = $c['dueno']->id;

        $this->resultado_de_la_segunda = null;

        $r = $this->comparar_caminos_de(
            $ids,
            function () use ($provider_id) {
                /* A sola. */
                return ArticleProviderDiscountHelper::propagar_a_articulos(Provider::find($provider_id));
            },
            function () use ($provider_id, $dueno_id) {

                /* El plan de B, armado antes de que A escriba. */
                $plan_de_b = ArticleProviderDiscountHelper::planificar_propagacion(Provider::find($provider_id), false, User::find($dueno_id));

                /* A, entera. */
                $resultado_de_a = ArticleProviderDiscountHelper::propagar_a_articulos(Provider::find($provider_id));

                /* B escribe con el plan viejo. */
                $this->resultado_de_la_segunda = ArticleProviderDiscountHelper::aplicar_plan_de_propagacion(Provider::find($provider_id), $plan_de_b);

                return $resultado_de_a;
            },
            'Propagacion contra propagacion'
        );

        $this->assertSame(['actualizados' => 3, 'respetados' => 0], $r['nuevo'], 'Precondicion: A actualizo los tres.');
        $this->assertSame(0, $this->resultado_de_la_segunda['actualizados'], 'B no toca ni cuenta a los que A ya habia actualizado.');

        foreach ($this->de_la_ficha_por_articulo($r['foto']['descuentos']) as $article_id => $cantidad) {
            $this->assertSame(2, $cantidad, 'El articulo ' . $article_id . ' quedo con los descuentos de la ficha duplicados.');
        }
    }

    /**
     * 🔴 Sincronizacion "todos" contra sincronizacion, con articulos SIN descuentos (el plan los vio
     * vacios: no hay nada que barrer, solo se inserta) y articulos desactualizados. La segunda
     * sincronizacion se cuela entre el escaneo de la primera y su escritura: se la corre desde el
     * aviso de avance inicial, que llega justo despues de armar los grupos y antes de la primera
     * tanda.
     *
     * @test
     */
    public function una_sincronizacion_que_escribe_con_un_escaneo_viejo_no_duplica_los_descuentos()
    {
        $c = $this->comercio_con_ficha();

        $ids = [];

        for ($i = 1; $i <= 2; $i++) {
            $ids[] = $this->crear_articulo($c['dueno'], ['cost' => 800 + $i * 7.25, 'provider_id' => $c['provider']->id])->id;
        }

        for ($i = 1; $i <= 2; $i++) {
            $article = $this->crear_articulo($c['dueno'], ['cost' => 1500 + $i * 3.5, 'provider_id' => $c['provider']->id]);
            $this->copia_de_la_ficha($article, $c['provider'], 10);
            $ids[] = $article->id;
        }

        $this->calentar($ids, $c['dueno']->id);

        $provider_id = $c['provider']->id;

        $this->resultado_de_la_segunda = null;
        $this->corrio_la_del_medio = false;

        $r = $this->comparar_caminos_de(
            $ids,
            function () use ($provider_id) {
                /* A sola. */
                return ArticleProviderDiscountHelper::sincronizar_a_articulos(Provider::find($provider_id), ArticleProviderDiscountHelper::ALCANCE_TODOS);
            },
            function () use ($provider_id) {

                $resultado_de_a = null;

                $this->resultado_de_la_segunda = ArticleProviderDiscountHelper::sincronizar_a_articulos(
                    Provider::find($provider_id),
                    ArticleProviderDiscountHelper::ALCANCE_TODOS,
                    false,
                    ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR,
                    function ($procesados, $total) use ($provider_id, &$resultado_de_a) {

                        /* Una sola vez: entre el escaneo de B y su primera tanda, A corre entera. */
                        if ($this->corrio_la_del_medio) {
                            return;
                        }

                        $resultado_de_a = ArticleProviderDiscountHelper::sincronizar_a_articulos(
                            Provider::find($provider_id),
                            ArticleProviderDiscountHelper::ALCANCE_TODOS
                        );

                        $this->corrio_la_del_medio = true;
                    }
                );

                return $resultado_de_a;
            },
            'Sincronizacion contra sincronizacion'
        );

        $this->assertTrue($this->corrio_la_del_medio, 'Precondicion: la sincronizacion del medio corrio de verdad.');
        $this->assertSame(2, $r['nuevo']['creados'], 'Precondicion: A creo los descuentos de los dos que no tenian.');
        $this->assertSame(2, $r['nuevo']['actualizados'], 'Precondicion: A actualizo los dos desactualizados.');

        $this->assertSame(0, $this->resultado_de_la_segunda['creados'], 'B no le crea otra copia a los que A ya les creo.');
        $this->assertSame(0, $this->resultado_de_la_segunda['actualizados'], 'B no rehace a los que A ya actualizo.');

        foreach ($this->de_la_ficha_por_articulo($r['foto']['descuentos']) as $article_id => $cantidad) {
            $this->assertSame(2, $cantidad, 'El articulo ' . $article_id . ' quedo con los descuentos de la ficha duplicados.');
        }
    }

    /* ------------------------------------------------------------------------------------------
     * El candado del proveedor (chequeo independiente del 29/9/2026)
     * ---------------------------------------------------------------------------------------- */

    /**
     * La forma: al abrir la transaccion de cada tanda, lo PRIMERO es el candado de la fila del
     * proveedor (`select ... from providers ... for update`), y recien despues la relectura con
     * candado de los descuentos. Ese orden es el que serializa a dos operaciones en bloque del mismo
     * proveedor: la segunda espera en el proveedor, sin haber tomado nada, a que la primera confirme.
     *
     * @test
     */
    public function cada_tanda_empieza_tomando_el_candado_del_proveedor()
    {
        config(['app.SINCRONIZAR_DESCUENTOS_PROVEEDOR_LOTE' => 2]);

        $c = $this->comercio_con_ficha();

        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->crear_articulo($c['dueno'], ['cost' => 900 + $i, 'provider_id' => $c['provider']->id])->id;
        }

        $this->calentar($ids, $c['dueno']->id);

        $secuencia = [];

        Event::listen(TransactionBeginning::class, function () use (&$secuencia) {
            $secuencia[] = 'BEGIN';
        });

        DB::listen(function ($query) use (&$secuencia) {
            $secuencia[] = $query->sql;
        });

        ArticleProviderDiscountHelper::sincronizar_a_articulos(Provider::find($c['provider']->id), ArticleProviderDiscountHelper::ALCANCE_TODOS);

        /* Las relecturas con candado de los descuentos: una por tanda (3 articulos en tandas de 2). */
        $relecturas = [];

        foreach ($secuencia as $i => $sql) {
            if ($sql !== 'BEGIN' && preg_match('/^select .* from `article_discounts` where `article_id` in \(.* for update$/i', $sql)) {
                $relecturas[] = $i;
            }
        }

        $this->assertCount(2, $relecturas, 'Una relectura con candado por tanda: ' . implode("\n", $secuencia));

        foreach ($relecturas as $i) {

            $this->assertMatchesRegularExpression(
                '/^select .* from `providers` where `id` = \? limit 1 for update$/i',
                $i > 0 ? $secuencia[$i - 1] : '',
                'Antes de releer los descuentos, la tanda tiene que tomar el candado del proveedor.'
            );

            $this->assertSame(
                'BEGIN',
                $i > 1 ? $secuencia[$i - 2] : null,
                'El candado del proveedor tiene que ser la PRIMERA sentencia de la transaccion de la tanda.'
            );
        }
    }

    /**
     * El comportamiento, con DOS conexiones reales: mientras una tanda de descuentos del proveedor
     * esta abierta, otra operacion del mismo proveedor no puede tomar el candado del proveedor (con
     * que empieza cada tanda), o sea que no puede revalidar ni escribir hasta que la primera
     * confirme.
     *
     * La segunda conexion es un PDO fuera de Laravel, con un tope de espera de candado de 1 segundo:
     * el proveedor lo confirma ella misma (los datos del test viven sin confirmar en la transaccion de
     * DatabaseTransactions y ninguna otra conexion los veria). Se intenta tomar el candado justo
     * cuando la tanda relee los descuentos.
     *
     * 🔴 El limite, igual que en el test del candado del motor: PHP corre en un solo hilo, asi que la
     * otra conexion no puede esperar de verdad a que la tanda termine; lo que se prueba es que en ese
     * momento NO PUEDE entrar. Que en produccion entra despues y revalida contra lo confirmado (READ
     * COMMITTED) es lo que ya prueban los tests de carrera de arriba con la intercalacion completa.
     *
     * @test
     */
    public function otra_operacion_del_mismo_proveedor_no_entra_mientras_la_tanda_esta_abierta()
    {
        $this->abrir_segunda_conexion();

        $dueno = $this->crear_dueno(['aplicar_descuentos_proveedor_al_asignar' => 1]);

        $ahora = date('Y-m-d H:i:s');

        /* El proveedor, CONFIRMADO por la segunda conexion (providers no tiene FK a users). */
        $provider_id = $this->confirmar('providers', [
            'name'            => 'zz Proveedor dos conexiones ' . uniqid(),
            'user_id'         => $dueno->id,
            'percentage_gain' => 30,
            'created_at'      => $ahora,
            'updated_at'      => $ahora,
        ]);

        $provider = Provider::find($provider_id);

        $this->assertNotNull($provider, 'Precondicion: la conexion del test ve el proveedor confirmado.');

        $this->descuento_de_la_ficha($provider, 15, 'Bonif general');

        $ids = [];

        for ($i = 1; $i <= 2; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 700 + $i, 'provider_id' => $provider_id])->id;
        }

        $this->calentar($ids, $dueno->id);

        $disparado = false;
        $resultado = null;

        $segunda = $this->segunda;

        DB::listen(function ($query) use (&$disparado, &$resultado, $segunda, $provider_id) {

            if ($disparado || !preg_match('/^select .* from `article_discounts` where `article_id` in \(.* for update$/i', $query->sql)) {
                return;
            }

            $disparado = true;

            try {
                $segunda->prepare('SELECT `id` FROM `providers` WHERE `id` = ? FOR UPDATE')->execute([$provider_id]);
                $resultado = 'tomo_el_candado';
            } catch (\PDOException $e) {
                $resultado = (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1205) ? 'bloqueada' : 'error: ' . $e->getMessage();
            }
        });

        ArticleProviderDiscountHelper::sincronizar_a_articulos(Provider::find($provider_id), ArticleProviderDiscountHelper::ALCANCE_TODOS);

        $this->assertTrue($disparado, 'La tanda no llego a releer los descuentos: el test no probo nada.');

        $this->assertSame(
            'bloqueada',
            $resultado,
            'Otra operacion del mismo proveedor pudo tomar el candado del proveedor con una tanda abierta: '.
            'en READ COMMITTED las dos revalidarian "vacio" y los descuentos quedarian duplicados.'
        );
    }

    /**
     * Abre la segunda conexion con la misma base que el test, en autocommit, con un tope de espera de
     * candado de 1 segundo en su sesion (mismo armado que el test del candado del motor).
     *
     * @return void
     */
    private function abrir_segunda_conexion()
    {
        $config = config('database.connections.' . config('database.default'));

        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . DB::connection()->getDatabaseName() . ';charset=utf8mb4';

        $this->segunda = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $this->segunda->exec('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /**
     * Inserta y CONFIRMA una fila con la segunda conexion (autocommit) y la anota para borrarla.
     *
     * @param  string $tabla
     * @param  array  $fila
     * @return int
     */
    private function confirmar($tabla, array $fila)
    {
        $columnas = array_keys($fila);

        $sql = 'INSERT INTO `' . $tabla . '` (`' . implode('`, `', $columnas) . '`) VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')';

        $this->segunda->prepare($sql)->execute(array_values($fila));

        $id = (int) $this->segunda->lastInsertId();

        $this->confirmadas[$tabla][] = $id;

        return $id;
    }
}
