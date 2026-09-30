<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Provider;
use App\Services\AuditLog\AuditContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Test 7 del plan: si el registro de auditoría falla, la operación de negocio termina bien y sale
 * un warning (misión auditoria-de-cambios, 30/9/2026).
 *
 * El caso real es un cliente a mitad de un upgrade: el código nuevo ya está, pero la migración de
 * `audit_logs` todavía no corrió. La tabla no existe, y el sistema tiene que seguir vendiendo.
 * Se reproduce apuntando la tabla de la auditoría a una que no existe (`audit_log.tabla`) y NO
 * borrando la real: un DROP/ALTER hace commit implícito en MySQL y rompería el aislamiento del
 * resto de la suite.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class FalloDelRegistroNoRompeElNegocioTest extends AuditoriaTestCase
{
    /**
     * Excepción que tiene que tirar el INSERT de auditoría (null = ninguna).
     *
     * @var \Illuminate\Database\QueryException|null
     */
    protected $excepcion_forzada = null;

    /**
     * Si el callback de `beforeExecuting()` ya se registró en esta conexión.
     *
     * @var bool
     */
    protected $callback_registrado = false;

    /**
     * Con la tabla ausente, guardar un modelo funciona igual y se avisa UNA sola vez.
     *
     * @return void
     */
    public function test_con_la_tabla_ausente_la_operacion_termina_bien_y_sale_un_solo_warning()
    {
        config(['audit_log.tabla' => 'audit_logs_que_no_existe']);

        Log::spy();

        $primero = Provider::create(['name' => 'ZZ Proveedor sin auditoria 1', 'user_id' => $this->dueno->id]);
        $segundo = Provider::create(['name' => 'ZZ Proveedor sin auditoria 2', 'user_id' => $this->dueno->id]);

        $primero->update(['name' => 'ZZ Proveedor sin auditoria 1 editado']);

        // El negocio no se enteró: los tres guardados quedaron.
        $this->assertSame(1, Provider::where('name', 'ZZ Proveedor sin auditoria 1 editado')->count());
        $this->assertSame(1, Provider::where('name', 'ZZ Proveedor sin auditoria 2')->count());

        // Y hubo un solo aviso, no uno por fila.
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos((string) $mensaje, 'AuditLogRecorder') !== false;
            })
            ->once();

        // Y tras el error de "tabla inexistente" el proceso deja de insistir un rato (no paga un
        // INSERT fallido por cada fila). Queda comprobado de forma observable en el test siguiente.
        $this->assertGreaterThan(time(), AuditContext::esperar_hasta());
    }

    /**
     * Cuando la tabla vuelve a existir (reinicio del contexto = proceso nuevo), se audita de nuevo.
     *
     * @return void
     */
    public function test_al_volver_la_tabla_se_retoma_la_auditoria()
    {
        config(['audit_log.tabla' => 'audit_logs_que_no_existe']);

        Log::spy();

        Provider::create(['name' => 'ZZ Proveedor antes', 'user_id' => $this->dueno->id]);

        config(['audit_log.tabla' => 'audit_logs']);

        // Todavía dentro de la espera: no escribe.
        Provider::create(['name' => 'ZZ Proveedor durante', 'user_id' => $this->dueno->id]);
        $this->assertSame(0, $this->filas()->count());

        // Pasada la espera (un worker de cola vive días), retoma sola.
        AuditContext::fijar_espera(time() - 1);

        Provider::create(['name' => 'ZZ Proveedor despues', 'user_id' => $this->dueno->id]);
        $this->assertSame(1, $this->filas(Provider::class, 'created')->count());
    }

    /**
     * El warning NO lleva los valores de la fila: el mensaje de una QueryException trae el SQL con
     * sus bindings (nombres, DNI, precios) y terminaría en laravel.log en claro.
     *
     * @return void
     */
    public function test_el_warning_de_falla_no_lleva_ningun_valor_de_la_fila()
    {
        config(['audit_log.tabla' => 'audit_logs_que_no_existe']);

        $mensaje_logueado = null;

        Log::spy();

        Provider::create([
            'name'    => 'ZZ Valor Secreto Unico 7731',
            'cuit'    => '20-98765432-1',
            'user_id' => $this->dueno->id,
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) use (&$mensaje_logueado) {
                $mensaje_logueado = (string) $mensaje;
                return strpos($mensaje_logueado, 'AuditLogRecorder') !== false;
            })
            ->once();

        $this->assertNotNull($mensaje_logueado);
        $this->assertStringContainsString('QueryException', $mensaje_logueado, 'Se loguea la clase de la excepción.');
        $this->assertStringNotContainsString('ZZ Valor Secreto Unico 7731', $mensaje_logueado, 'El nombre de la fila no puede estar en el log.');
        $this->assertStringNotContainsString('98765432', $mensaje_logueado, 'El CUIT de la fila no puede estar en el log.');
        $this->assertStringNotContainsString('insert into', $mensaje_logueado, 'El SQL con sus bindings no puede estar en el log.');
    }

    /**
     * Arma una QueryException como la que tira el driver, con el código de MySQL que se pide.
     *
     * @param int $codigo_mysql 1213 (deadlock), 1205 (lock wait), 1146 (tabla inexistente)...
     * @param string $texto
     * @return \Illuminate\Database\QueryException
     */
    protected function excepcion_de_mysql($codigo_mysql, $texto)
    {
        $pdo = new \PDOException('SQLSTATE[HY000]: ' . $codigo_mysql . ' ' . $texto);
        $pdo->errorInfo = ['HY000', $codigo_mysql, $texto];

        return new QueryException('insert into `audit_logs` (...) values (?)', ['valor'], $pdo);
    }

    /**
     * Hace que el INSERT de auditoría falle con la excepción dada.
     *
     * @param \Illuminate\Database\QueryException $excepcion
     * @return void
     */
    protected function forzar_falla_del_insert_de_auditoria($excepcion)
    {
        // El callback se registra UNA vez y lee la excepción vigente: `beforeExecuting()` no tiene
        // forma de quitar un callback, y registrar uno por caso dejaría vivo al anterior.
        $this->excepcion_forzada = $excepcion;

        if (!$this->callback_registrado) {

            $this->callback_registrado = true;

            DB::connection()->beforeExecuting(function ($consulta) {
                if (!is_null($this->excepcion_forzada) && strpos($consulta, 'insert into `audit_logs`') !== false) {
                    throw $this->excepcion_forzada;
                }
            });
        }
    }

    /**
     * Un deadlock (1213) y un lock wait timeout (1205) SE RELANZAN: el deadlock ya deshizo la
     * transacción del negocio y tragarlo impediría el reintento de `DB::transaction($cb, N)`.
     *
     * @return void
     */
    public function test_un_deadlock_o_un_lock_wait_de_la_auditoria_se_relanzan()
    {
        foreach ([[1213, 'Deadlock found when trying to get lock; try restarting transaction'], [1205, 'Lock wait timeout exceeded; try restarting transaction']] as $caso) {

            AuditContext::reiniciar();

            $this->forzar_falla_del_insert_de_auditoria($this->excepcion_de_mysql($caso[0], $caso[1]));

            $atrapada = null;

            try {
                Provider::create(['name' => 'ZZ Proveedor deadlock ' . $caso[0], 'user_id' => $this->dueno->id]);
            } catch (QueryException $e) {
                $atrapada = $e;
            }

            $this->assertNotNull($atrapada, 'El error ' . $caso[0] . ' tiene que llegar hasta el negocio.');
            $this->assertSame($caso[0], $atrapada->errorInfo[1]);
        }
    }

    /**
     * Cualquier otro error de la auditoría (acá, 1062 clave duplicada) se traga y el negocio sigue.
     *
     * @return void
     */
    public function test_cualquier_otro_error_de_la_auditoria_se_traga()
    {
        Log::spy();

        $this->forzar_falla_del_insert_de_auditoria($this->excepcion_de_mysql(1062, 'Duplicate entry'));

        $proveedor = Provider::create(['name' => 'ZZ Proveedor otro error', 'user_id' => $this->dueno->id]);

        $this->assertNotNull(Provider::find($proveedor->id), 'El negocio siguió.');
    }
}
