<?php

namespace Tests\Feature;

use App\Database\Connectors\RetryingMySqlConnector;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión reintento-conexion-mysql (15/9/2026) — a diferencia de RetryingMySqlConnectorTest (que
 * prueba la lógica de reintento aislada, sin ninguna base), esto confirma el enganche real: que
 * AppServiceProvider efectivamente registró el conector para que Laravel lo use, y que una
 * aplicación booteada de verdad sigue pudiendo conectarse y consultar la base como siempre —
 * el cambio no puede romper la conexión normal, que es lo que usa cada request de cada cliente.
 */
class RetryingMySqlConnectorBindingTest extends EmpresaTestCase
{
    public function test_el_conector_de_mysql_registrado_es_el_nuestro()
    {
        $conector = $this->app->make('db.connector.mysql');

        $this->assertInstanceOf(RetryingMySqlConnector::class, $conector);
    }

    public function test_una_consulta_real_sigue_funcionando_con_el_conector_nuevo()
    {
        // No es la lógica de reintento (para eso está RetryingMySqlConnectorTest): es la
        // confirmación de que reemplazar el conector no rompió la conexión de verdad que usa
        // toda la suite y, en producción, cada request de cada cliente.
        $resultado = DB::select('select 1 as uno');

        $this->assertSame(1, (int) $resultado[0]->uno);
    }
}
