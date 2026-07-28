<?php

namespace Tests;

use App\Models\Article;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Clase base comun de los tests de `empresa-api` (Grupo 242, Prompt 01).
 *
 * Sacada de `Tests\Feature\Compras\ComprasTestCase` (que hasta este prompt cargaba, ademas de la
 * logica propia de compras, los seguros de entorno y los helpers de fixture que cualquier suite
 * nueva necesita). Las suites de la Tanda 1 (tesoreria, reportes, IVA por condicion) extienden
 * esta clase directamente, sin copiar y pegar los guards.
 *
 * Usa `DatabaseTransactions` (NO `RefreshDatabase`): cada test corre dentro de una transaccion que
 * en teoria se revierte al terminar, para que los cambios de un test no contaminen el siguiente,
 * sin pagar el costo de reconstruir todas las migraciones en cada test.
 *
 * ⚠️ Ver `verificar_motor_innodb()` mas abajo: en este entorno `DatabaseTransactions` solo aisla
 * de verdad si las tablas de la base de testing son InnoDB. Con MyISAM (motor por defecto
 * historico de este WAMP) el `BEGIN`/`ROLLBACK` es un no-op silencioso.
 */
abstract class EmpresaTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * Cache estatica (una sola vez por proceso de PHPUnit, no por test) del resultado del guard de
     * InnoDB, para no pagar la query a `information_schema.tables` en cada uno de los ~200 tests
     * de la corrida. `null` = todavia no se corrio; `true`/`false` = resultado ya verificado.
     *
     * @var bool|null
     */
    protected static $motor_innodb_verificado = null;

    /**
     * setUp de cada test: corre los guards de entorno en orden (base de testing real, motor
     * InnoDB, fixture sembrado) y autentica al usuario de prueba antes de que arranque el test
     * concreto.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->verificar_base_de_testing();

        $this->verificar_motor_innodb();

        $this->verificar_fixture_sembrado();

        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->actingAs($user, 'web');
    }

    /**
     * Seguro: aborta la suite si la conexion activa no apunta a una base cuyo nombre contenga
     * "testing". Es la proteccion contra correr esta suite apuntando a la base de desarrollo por
     * un `.env.testing` mal configurado (o inexistente, cayendo al `.env` normal).
     *
     * @return void
     */
    protected function verificar_base_de_testing()
    {
        /** Nombre de la base realmente conectada (no lo que dice el .env, sino la conexion activa). */
        $database_name = (string) DB::connection()->getDatabaseName();

        if (strpos($database_name, 'testing') === false) {
            $this->fail(
                'SEGURO: la conexion activa apunta a la base "'.$database_name.'", que no contiene '.
                '"testing" en su nombre. Se aborta la suite para evitar correr tests sobre una base '.
                'que podria ser la de desarrollo. Revisa que exista .env.testing (copia de '.
                '.env.testing.example) con DB_DATABASE conteniendo "testing" (ej. empresa_testing).'
            );
        }
    }

    /**
     * Seguro (Grupo 242, Prompt 01): aborta la suite si alguna tabla de la base de testing
     * conectada no usa el motor InnoDB. Sin InnoDB, `DatabaseTransactions` no revierte nada de
     * verdad (`BEGIN`/`ROLLBACK` son no-ops sobre tablas MyISAM), y las suites acumulativas
     * (saldos de caja, estado de resultados, flujo de caja) empiezan a fallar por motivos falsos
     * porque el segundo test ya arranca con lo que dejo el primero.
     *
     * El resultado se cachea en `static::$motor_innodb_verificado` para pagar la query a
     * `information_schema.tables` una sola vez por proceso, no en cada uno de los ~200 tests.
     *
     * @return void
     */
    protected function verificar_motor_innodb()
    {
        // Si ya se verifico en este proceso, no se repite la query.
        if (static::$motor_innodb_verificado === true) {
            return;
        }

        /** Nombre de la base conectada, para filtrar information_schema.tables por esa base. */
        $base = DB::connection()->getDatabaseName();

        /** Cantidad de tablas de la base conectada cuyo motor NO es InnoDB. */
        $no_innodb = DB::table('information_schema.tables')
                        ->where('table_schema', $base)
                        ->where('engine', '<>', 'InnoDB')
                        ->count();

        if ($no_innodb > 0) {
            $this->fail(
                'SEGURO: '.$no_innodb.' tabla(s) de la base "'.$base.'" no usan el motor InnoDB. '.
                'En ese estado, `DatabaseTransactions` NO aisla nada entre tests (BEGIN/ROLLBACK '.
                'son no-ops sobre MyISAM), y las suites acumulativas (saldos, estado de resultados, '.
                'flujo de caja) van a fallar por motivos falsos. Fix: poner '.
                '`default-storage-engine=InnoDB` en el my.ini de WAMP, reiniciar el servicio de '.
                'MySQL, y volver a correr `php artisan migrate:fresh --env=testing` + el seeder de '.
                'testing (un `ALTER TABLE ... ENGINE=InnoDB` sobre las tablas existentes no alcanza: '.
                'el proximo `migrate:fresh` las vuelve a crear con el motor por defecto del servidor).'
            );
        }

        // Cachea el resultado positivo para el resto de los tests de este proceso.
        static::$motor_innodb_verificado = true;
    }

    /**
     * Seguro: aborta si el fixture de `TestingFerreteriaSeeder` no fue sembrado en la base de
     * testing conectada, con un mensaje que indica el comando exacto para sembrarlo.
     *
     * @return void
     */
    protected function verificar_fixture_sembrado()
    {
        if (is_null($this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA))) {
            $this->fail(
                'Falta el fixture de testing: no se encontro el articulo "'.
                TestingFerreteriaSeeder::ARTICULO_CENTINELA.'". Sembra la base de testing con: '.
                'php artisan migrate:fresh && php artisan db:seed --class="Database\\Seeders\\testing\\TestingFerreteriaSeeder"'
            );
        }
    }

    /**
     * Devuelve el Article del fixture por nombre (nunca resolver por id hardcodeado en un test).
     *
     * @param string $nombre
     * @return \App\Models\Article|null
     */
    protected function articulo($nombre)
    {
        return Article::where('name', $nombre)->first();
    }

    /**
     * Devuelve el Provider del fixture por nombre.
     *
     * @param string $nombre
     * @return \App\Models\Provider|null
     */
    protected function proveedor($nombre)
    {
        return Provider::where('name', $nombre)->first();
    }

    /**
     * Guarda una "foto" de los campos de un Article que los tests pueden mutar (costo, stock,
     * proveedor, costo_real, precio) antes de confirmar una operacion sobre el.
     *
     * @param \App\Models\Article $articulo
     * @return array<string,mixed>
     */
    protected function snapshot_articulo($articulo)
    {
        $articulo->refresh();

        // Hallazgo (Prompt 616): para un articulo con depositos asignados (`addresses()`,
        // belongsToMany con pivot `amount`), `articles.stock` es un valor DERIVADO — lo
        // recalcula `ArticleHelper::setArticleStockFromAddresses()` sumando el `amount` de cada
        // deposito — no la fuente de verdad. Forzar solo `articles.stock` a mano (sin tocar el
        // pivot del deposito) queda pisado apenas corre el siguiente movimiento de stock. Por eso
        // acá se guarda tambien el `amount` de cada deposito vigente, para poder restaurarlo.
        $articulo->load('addresses');

        $address_amounts = [];
        foreach ($articulo->addresses as $address) {
            $address_amounts[$address->id] = $address->pivot->amount;
        }

        return [
            'cost'             => $articulo->cost,
            'stock'            => $articulo->stock,
            'provider_id'      => $articulo->provider_id,
            'costo_real'       => $articulo->costo_real,
            'price'            => $articulo->price,
            'address_amounts'  => $address_amounts,
        ];
    }

    /**
     * Restaura en un Article los campos guardados por `snapshot_articulo()`. Escribe directo
     * (sin pasar por StockMovementController ni ArticleHelper::setFinalPrice) porque el objetivo
     * es dejar la fila EXACTAMENTE como estaba antes del test, no re-derivar nada. `timestamps`
     * en false para no ensuciar el `updated_at` del fixture en cada corrida (mismo criterio que
     * `NewProviderOrderHelper::update_article_provider()`).
     *
     * @param \App\Models\Article $articulo
     * @param array<string,mixed> $snapshot Valor devuelto por `snapshot_articulo()`.
     * @return void
     */
    protected function restaurar_articulo($articulo, $snapshot)
    {
        $articulo->refresh();

        $articulo->cost        = $snapshot['cost'];
        $articulo->stock       = $snapshot['stock'];
        $articulo->provider_id = $snapshot['provider_id'];
        $articulo->costo_real  = $snapshot['costo_real'];
        $articulo->price       = $snapshot['price'];
        $articulo->timestamps  = false;
        $articulo->save();

        // Restaura el `amount` de cada deposito (ver nota en snapshot_articulo): si el articulo
        // tiene depositos asignados, es la fuente de verdad real del stock, no la columna.
        foreach ($snapshot['address_amounts'] as $address_id => $amount) {
            $articulo->addresses()->updateExistingPivot($address_id, ['amount' => $amount]);
        }
    }
}
