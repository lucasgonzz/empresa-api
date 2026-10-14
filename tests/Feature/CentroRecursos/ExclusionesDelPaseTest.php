<?php

namespace Tests\Feature\CentroRecursos;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\CentroRecursosPaseHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as AccionesDePantalla;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Models\CentroRecursosPase;
use Tests\Feature\AuditoriaDeCambios\AuditoriaTestCase;

/**
 * El pase del centro de recursos queda afuera de las tres puertas que se abren solas para una tabla
 * o una ruta nueva (misión centro-de-recursos-clientes, 13/10/2026):
 *
 *  1. La auditoría (`config/audit_log.php`, modelos_excluidos): audita todo modelo salvo lo excluido.
 *     Un pase es efímero y sensible, como VersionSessionTransfer.
 *  2. La lectura del asistente y del MCP (`EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS`): toda tabla con
 *     `user_id` entra sola al catálogo.
 *  3. Las acciones de pantalla del asistente (`CatalogoDeAccionesDePantallaIaHelper::EXCLUIDAS`):
 *     toda ruta bajo auth:sanctum entra sola. Sin la exclusión, el asistente podía emitir un pase y
 *     leer la URL con el pase en claro.
 *
 * Los recorridos generales de cada lista (ExclusionesBienEscritasTest, Esquema_de_datos_Test::
 * ninguna_tabla_excluida_entra_y_todas_existen) ya verifican que cada entrada exista y tenga motivo;
 * acá se verifica el EFECTO sobre el pase, con un control donde hace falta.
 *
 * Usa el molde de AuditoriaTestCase (ModelosExcluidosTest) para contar filas de auditoría.
 *
 * @group centro-recursos
 */
class ExclusionesDelPaseTest extends AuditoriaTestCase
{
    /**
     * Crear y canjear un pase no deja filas de auditoría. Control: sacando SOLO esta clase de la
     * lista, crear un pase sí deja su fila, o sea que lo que la deja afuera es la exclusión (y no que
     * el listener no ande).
     *
     * @return void
     */
    public function test_el_pase_no_deja_filas_de_auditoria_y_sin_la_exclusion_si()
    {
        $this->assertArrayHasKey(CentroRecursosPase::class, config('audit_log.modelos_excluidos'));

        // La instancia es la de este comercio (si no, el canje lo rechaza por ser de otro dueño).
        config(['app.USER_ID' => $this->dueno->id]);

        $pase = CentroRecursosPaseHelper::crear($this->dueno, 'T1.29');
        $this->assertNotNull(CentroRecursosPaseHelper::canjear($pase));

        $this->assertCount(0, $this->filas(CentroRecursosPase::class), 'El pase está excluido: no puede dejar filas.');

        // Control: la misma lista sin el pase.
        $excluidos = config('audit_log.modelos_excluidos');
        unset($excluidos[CentroRecursosPase::class]);
        config(['audit_log.modelos_excluidos' => $excluidos]);

        $otro = CentroRecursosPaseHelper::crear($this->dueno);

        $this->assertCount(1, $this->filas(CentroRecursosPase::class, 'created'), 'Sin la exclusión el pase se audita: si no, el test no prueba nada.');

        // Y aun auditado, el hash no quedaría a la vista (va en $hidden): segunda red.
        $fila = $this->filas(CentroRecursosPase::class, 'created')->first();
        $this->assertStringNotContainsString(hash('sha256', $otro), (string) $fila->new_values);
    }

    /**
     * La tabla de pases no entra al catálogo de lectura del asistente ni del MCP.
     *
     * @return void
     */
    public function test_la_tabla_de_pases_no_entra_al_catalogo_de_lectura_del_asistente()
    {
        // El catálogo se deriva del esquema de ESTA base, no de un caché de otra corrida.
        EsquemaDeDatosIaHelper::olvidar();

        $this->assertArrayHasKey('centro_recursos_pases', EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS);
        $this->assertFalse(EsquemaDeDatosIaHelper::existe('centro_recursos_pase'));
        $this->assertNotContains('centro_recursos_pase', CatalogoDeDatosIaHelper::entidades());
        $this->assertNotContains('centro_recursos_pases', array_column(EsquemaDeDatosIaHelper::catalogo(), 'tabla'));
    }

    /**
     * Emitir un pase no es una acción de pantalla del asistente, y la exclusión dice por qué.
     *
     * @return void
     */
    public function test_emitir_un_pase_no_es_una_accion_de_pantalla_del_asistente()
    {
        AccionesDePantalla::olvidar();

        $this->assertNull(AccionesDePantalla::declaracion('POST', 'api/centro-recursos/pase'));
        $this->assertStringContainsString('centro de recursos', (string) AccionesDePantalla::motivo_de_exclusion('POST', 'api/centro-recursos/pase'));

        $rutas = array_column(AccionesDePantalla::todas(), 'ruta');

        $this->assertNotContains('api/centro-recursos/pase', $rutas);
        $this->assertNotContains('api/admin-sync/recursos/canjear-pase', $rutas, 'El canje es del admin, nunca del asistente.');
    }
}
