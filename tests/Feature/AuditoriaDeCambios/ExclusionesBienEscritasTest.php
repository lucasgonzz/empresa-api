<?php

namespace Tests\Feature\AuditoriaDeCambios;

use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * Test 11 del plan: detección de la clase de error "exclusión que no excluye" (misión
 * auditoria-de-cambios, 30/9/2026).
 *
 * Un nombre de clase mal escrito en `config/audit_log.php` no rompe nada: simplemente no excluye lo
 * que se quería excluir (o no silencia el job que se quería silenciar), y nadie se entera hasta que
 * la tabla crece o una operación masiva deja miles de filas. Este test es el que lo denuncia.
 *
 * Comprueba, contra el código real y sin tocar la base:
 *  - todo modelo excluido existe, es un modelo de Eloquent y tiene su motivo;
 *  - ninguno de los modelos que NUNCA se pueden excluir (dinero, stock, datos maestros) está en la
 *    lista de excluidos;
 *  - todo job de `jobs_masivos`, todo modelo de `registro_de_operaciones` y de `solo_creacion`
 *    existe;
 *  - `ProcessDeleteModelsJob` no está silenciado.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class ExclusionesBienEscritasTest extends TestCase
{
    /**
     * Verifica que una clase exista con EXACTAMENTE ese nombre (mayúsculas incluidas: en Windows el
     * autoload es insensible a mayúsculas y aceptaría un nombre mal escrito).
     *
     * @param string $clase
     * @param string $donde
     * @return void
     */
    protected function assertClaseExiste($clase, $donde)
    {
        $this->assertTrue(class_exists($clase), $donde . ': la clase ' . $clase . ' no existe.');

        $this->assertSame(
            ltrim($clase, '\\'),
            (new \ReflectionClass($clase))->getName(),
            $donde . ': la clase ' . $clase . ' está escrita con otras mayúsculas.'
        );
    }

    /**
     * @return void
     */
    public function test_todo_modelo_excluido_existe_es_un_modelo_y_tiene_motivo()
    {
        $excluidos = config('audit_log.modelos_excluidos');

        $this->assertNotEmpty($excluidos);

        foreach ($excluidos as $clase => $motivo) {

            $this->assertClaseExiste($clase, 'modelos_excluidos');

            $this->assertTrue(
                is_subclass_of($clase, Model::class),
                'modelos_excluidos: ' . $clase . ' no es un modelo de Eloquent.'
            );

            $this->assertIsString($motivo, 'modelos_excluidos: el motivo de ' . $clase . ' no es un texto.');
            $this->assertNotSame('', trim($motivo), 'modelos_excluidos: ' . $clase . ' no tiene motivo.');
        }

        // La auditoría siempre se excluye a sí misma (recursión).
        $this->assertArrayHasKey(\App\Models\AuditLog::class, $excluidos);
    }

    /**
     * @return void
     */
    public function test_ningun_modelo_no_excluible_esta_excluido()
    {
        $excluidos = config('audit_log.modelos_excluidos');
        $no_excluibles = config('audit_log.modelos_no_excluibles');

        $this->assertNotEmpty($no_excluibles);

        foreach ($no_excluibles as $clase) {

            $this->assertClaseExiste($clase, 'modelos_no_excluibles');

            $this->assertArrayNotHasKey(
                $clase,
                $excluidos,
                $clase . ' es información del negocio que tiene que poder auditarse: no se puede excluir.'
            );
        }

        // Los que pide el plan, nombrados a mano: si alguien borrara uno de la lista de
        // no excluibles para poder excluirlo, este test también lo frena.
        foreach (['Article', 'Category', 'SubCategory', 'Sale', 'Client', 'Provider', 'ProviderOrder', 'Budget',
                  'CurrentAcount', 'Caja', 'MovimientoCaja', 'Cheque', 'Expense', 'PriceType', 'Employee', 'User',
                  'Permission', 'PermissionEmpresa', 'StockMovement', 'Payment', 'PaymentMethod', 'AfipInformation'] as $nombre) {

            $clase = 'App\\Models\\' . $nombre;

            $this->assertContains($clase, $no_excluibles, $nombre . ' salió de la lista de modelos no excluibles.');
            $this->assertArrayNotHasKey($clase, $excluidos, $nombre . ' está excluido.');
        }
    }

    /**
     * @return void
     */
    public function test_los_jobs_masivos_y_los_modelos_de_operacion_existen()
    {
        $jobs = config('audit_log.jobs_masivos');

        $this->assertNotEmpty($jobs);

        foreach ($jobs as $clase => $motivo) {

            $this->assertClaseExiste($clase, 'jobs_masivos');
            $this->assertNotSame('', trim((string) $motivo), 'jobs_masivos: ' . $clase . ' no tiene motivo.');
        }

        // Borrar es irreversible: el detalle de qué se borró importa más que el volumen.
        $this->assertArrayNotHasKey(\App\Jobs\ProcessDeleteModelsJob::class, $jobs);

        foreach (['registro_de_operaciones', 'solo_creacion'] as $lista) {

            foreach (config('audit_log.' . $lista) as $clase) {

                $this->assertClaseExiste($clase, $lista);
                $this->assertTrue(is_subclass_of($clase, Model::class), $lista . ': ' . $clase . ' no es un modelo.');
            }
        }

        // Ningún modelo de operación puede estar excluido: sería silenciar la operación entera.
        foreach (config('audit_log.registro_de_operaciones') as $clase) {
            $this->assertArrayNotHasKey($clase, config('audit_log.modelos_excluidos'), $clase . ' es la fila de operación: no se puede excluir.');
        }
    }

    /**
     * Los campos sensibles del config están en minúscula (el listener compara en minúscula) y los
     * sufijos empiezan con guion bajo (si no, `token` se llevaría puesto `tokens_entrada`).
     *
     * @return void
     */
    public function test_la_lista_de_sensibles_esta_bien_formada()
    {
        foreach (config('audit_log.campos_sensibles') as $campo) {
            $this->assertSame(strtolower($campo), $campo, 'campos_sensibles: ' . $campo . ' tiene que ir en minúscula.');
        }

        foreach (config('audit_log.sufijos_sensibles') as $sufijo) {
            $this->assertSame('_', substr($sufijo, 0, 1), 'sufijos_sensibles: ' . $sufijo . ' tiene que empezar con guion bajo.');
        }

        // Lo mínimo que nunca puede faltar.
        foreach (['password', 'remember_token', 'api_key', 'secret', 'token'] as $campo) {
            $this->assertContains($campo, config('audit_log.campos_sensibles'));
        }
    }

    /**
     * `modelos_sin_tope` (plata y stock): las clases existen, son modelos y NINGUNA está excluida
     * (sería una contradicción: "nunca se omite" y "nunca se audita").
     *
     * @return void
     */
    public function test_los_modelos_sin_tope_existen_y_no_estan_excluidos()
    {
        $sin_tope = config('audit_log.modelos_sin_tope');

        $this->assertNotEmpty($sin_tope);

        foreach ($sin_tope as $clase) {

            $this->assertClaseExiste($clase, 'modelos_sin_tope');
            $this->assertTrue(is_subclass_of($clase, Model::class), 'modelos_sin_tope: ' . $clase . ' no es un modelo.');
            $this->assertArrayNotHasKey($clase, config('audit_log.modelos_excluidos'), $clase . ' está excluido y también sin tope.');
        }

        foreach (['Sale', 'CurrentAcount', 'MovimientoCaja', 'Payment', 'Caja', 'AperturaCaja', 'Cheque', 'Expense',
                  'ProviderOrder', 'Budget', 'StockMovement', 'ArticlePurchase'] as $nombre) {
            $this->assertContains('App\\Models\\' . $nombre, $sin_tope, $nombre . ' salió de modelos_sin_tope.');
        }
    }

    /**
     * Las columnas que se ignoran o se ocultan están declaradas con su nombre exacto.
     *
     * @return void
     */
    public function test_las_columnas_nuevas_estan_en_el_config()
    {
        $this->assertContains('verification_code', config('audit_log.campos_sensibles'));
        $this->assertContains('last_message_at', config('audit_log.campos_ignorados'));
        $this->assertContains('last_inbound_at', config('audit_log.campos_ignorados'));
        $this->assertSame(5000, config('audit_log.max_filas_por_lote'));
    }
}
