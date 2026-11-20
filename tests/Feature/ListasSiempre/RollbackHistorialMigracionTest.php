<?php

namespace Tests\Feature\ListasSiempre;

use App\Models\Article;
use App\Models\User;
use App\Models\PriceType;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Services\AuditLog\AuditContext;
use App\Services\HistorialPreciosService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Base compartida solamente por las tres regresiones nuevas; sin DatabaseTransactions. */
abstract class RollbackIntegracionTestCase extends TestCase
{
    protected $salida;

    protected function setUp(): void
    {
        // El productor único habilita esta corrida recién cuando libera lane147.
        if (getenv('ROLLBACK_SQL_GATE') !== 'lane147-liberado'
            || getenv('DB_DATABASE') !== 'empresa_testing_rollback_20261119') {
            $this->markTestSkipped('PENDIENTETESTS: requiere lane147 liberado y DB propia.');
        }
        parent::setUp();
        $this->assertSame('empresa_testing_rollback_20261119', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::connection()->transactionLevel());
        AuditContext::reiniciar();
        PriceTypeHelper::olvidar_precio_final();
        $this->salida = storage_path('framework/testing/rollback-'.bin2hex(random_bytes(8)));
        // Los datos confirmados permanecen en la DB descartable del ejecutor: no borrar auditoría.
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            AuditContext::reiniciar();
            PriceTypeHelper::olvidar_precio_final();
            parent::tearDown();
        }
    }

    protected function nuevo_dueno()
    {
        return User::create([
            'name' => 'Regresión rollback', 'company_name' => 'Regresión rollback',
            'email' => 'rollback-'.bin2hex(random_bytes(8)).'@test.local',
            'password' => 'fixture-sin-login', 'status' => 'commerce',
            'listas_de_precio' => 0, 'dollar' => 1000,
        ])->fresh();
    }

    protected function nuevo_articulo($dueno, array $datos = [])
    {
        // Releer defaults persistidos ANTES del cálculo, nunca cambiar la expectativa comercial.
        return Article::create(array_merge([
            'name' => 'Regresión rollback', 'user_id' => $dueno->id,
            'status' => 'active', 'cost' => 100, 'price' => 123.45,
        ], $datos))->fresh();
    }

    protected function foto($tabla, $columna, array $ids)
    {
        return json_decode(json_encode(DB::table($tabla)->whereIn($columna, $ids)->orderBy('id')->get()), true);
    }

    protected function migrar_y_leer($comando, $dueno)
    {
        $codigo = Artisan::call($comando, [
            'user_id' => $dueno->id, '--aplicar' => true, '--salida' => $this->salida,
        ]);
        $this->assertSame(0, $codigo, Artisan::output());
        $archivos = glob($this->salida.'/*/reversion.sql');
        $this->assertCount(1, $archivos);
        return file_get_contents($archivos[0]);
    }

    // Contrato nuevo propuesto: UUID declarado por el respaldo, no deducido de los logs.
    protected function uuid_respaldo($sql)
    {
        $cantidad = preg_match_all('/^-- Respaldo UUID: ([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\r?$/mi', $sql, $m);
        $this->assertSame(1, $cantidad, 'PENDIENTE contrato nuevo: encabezado Respaldo UUID único.');
        return $m[1][0];
    }

    protected function bloques_reversion($sql, $exigir_nuevo = false)
    {
        $partes = preg_split('/\n(?=-- Artículo |-- Cuenta|-- Marca de sucursal principal|-- Sucursal principal creada)/', $sql);
        $encabezado = array_shift($partes);
        $this->assertSame('', trim(preg_replace('/^--[^\n]*$/m', '', $encabezado)), 'SQL fuera de bloques reconocidos.');
        $legacy = strpos($encabezado, '-- Orden del archivo: primero el bloque de ') !== false
            && strpos($encabezado, '-- Para revertir: correr los bloques de artículo y RECIÉN DESPUÉS el de ') !== false
            && strpos($encabezado, '-- Respaldo UUID:') === false;
        if ($exigir_nuevo || !$legacy) {
            $this->uuid_respaldo($encabezado);
            $legacy = false;
        }
        $articulos = []; $cuenta = []; $orden = [];
        foreach ($partes as $parte) {
            if (strpos($parte, '-- Artículo ') === 0) {
                $this->assertSame([], $legacy ? [] : $cuenta, 'Formato nuevo: artículos ANTES de cuenta/sucursal en el archivo físico.');
                $articulos[] = $parte;
            } else {
                $this->assertSame(1, preg_match('/^-- (Cuenta|Marca de sucursal principal|Sucursal principal creada)/', $parte));
                $cuenta[] = $parte;
            }
            if (!$legacy) {
                $limpio = trim(preg_replace('/^--[^\n]*$/m', '', $parte));
                $this->assertSame(1, preg_match('/^START TRANSACTION\s*;[\s\S]*\bCOMMIT\s*;$/i', $limpio), 'Cada bloque NUEVO debe traer su transacción completa.');
                $this->assertSame(1, preg_match_all('/\bSTART\s+TRANSACTION\b/i', $limpio));
                $this->assertSame(1, preg_match_all('/\bCOMMIT\b/i', $limpio));
                $this->assertSame(0, preg_match('/^\s*(BEGIN|ROLLBACK|SAVEPOINT|SET\s+AUTOCOMMIT|CREATE|ALTER|DROP|TRUNCATE)\b/im', $limpio), 'Sin commits implícitos ni transacciones alternativas.');
            }
            $orden[] = $parte;
        }
        $this->assertNotEmpty($articulos);
        $this->assertCount(1, $cuenta);
        // Sólo el legado explícitamente identificado se ejecuta siguiendo SU encabezado.
        // El formato nuevo se ejecuta en orden físico, sin arreglarle el respaldo al producto.
        return $legacy ? array_merge($articulos, $cuenta) : $orden;
    }

    protected function ejecutar_reversion($sql, $exigir_nuevo = false)
    {
        $bloques = $this->bloques_reversion($sql, $exigir_nuevo);
        $this->assertSame(0, DB::connection()->transactionLevel());
        $pdo = DB::connection()->getPdo();
        $this->assertFalse($pdo->inTransaction(), 'No envolver el respaldo en una transacción del fixture.');
        try {
            foreach ($bloques as $bloque) {
                $pdo->exec($bloque);
                $this->assertFalse($pdo->inTransaction(), 'Cada bloque entregado debe terminar confirmado.');
            }
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    protected function foto_comercial($dueno_id, array $ids, array $variantes = [])
    {
        // Recalcular también IDs nuevos: un insert residual no puede quedar fuera de la foto.
        $ids = array_values(array_unique(array_merge($ids, DB::table('articles')->where('user_id', $dueno_id)->pluck('id')->all())));
        $variantes = array_values(array_unique(array_merge($variantes, DB::table('article_variants')->whereIn('article_id', $ids)->pluck('id')->all())));
        $usuarios = DB::table('users')->where('id', $dueno_id)->orWhere('owner_id', $dueno_id)->pluck('id')->all();
        return [
            'usuarios' => $this->foto('users', 'id', $usuarios),
            'direcciones' => $this->foto('addresses', 'user_id', [$dueno_id]),
            'articulos' => $this->foto('articles', 'id', $ids),
            'variantes' => $this->foto('article_variants', 'id', $variantes),
            'filas' => $this->foto('address_article', 'article_id', $ids),
            'filas_variantes' => $this->foto('address_article_variant', 'article_variant_id', $variantes),
            'movimientos' => $this->foto('stock_movements', 'article_id', $ids),
        ];
    }

}

class RollbackHistorialMigracionTest extends RollbackIntegracionTestCase
{
    public function test_respaldo_nuevo_entrega_orden_fisico_y_transacciones_completas()
    {
        $dueno = $this->nuevo_dueno();
        $this->nuevo_articulo($dueno, ['stock' => 12]);
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        // El legado sirve para diagnosticar P1/P2, nunca acredita el contrato nuevo.
        $this->bloques_reversion($sql, true);
    }

    public function test_reversion_no_deja_detalles_huerfanos_ni_borra_historial_ajeno()
    {
        $dueno = $this->nuevo_dueno();
        $articulo = $this->nuevo_articulo($dueno);
        ArticleHelper::setFinalPrice($articulo, null, $dueno);
        $lista = PriceType::create(['name' => 'Histórica', 'user_id' => $dueno->id, 'percentage' => 0, 'position' => 1]);
        HistorialPreciosService::ejecutar([$articulo->id], null, 'fixture_historial_previo', function () use ($articulo, $lista) {
            $articulo->price_types()->attach($lista->id, ['final_price' => 123.45]);
        });
        $centinela = $this->nuevo_articulo($this->nuevo_dueno());
        HistorialPreciosService::ejecutar([$centinela->id], null, 'fixture_owner_centinela', function () use ($centinela) {
            DB::table('articles')->where('id', $centinela->id)->update(['final_price' => 777]);
        });
        $antes = $this->foto('price_changes', 'article_id', [$articulo->id, $centinela->id]);
        $this->assertNotEmpty($antes, 'El historial previo debe ser real.');
        $detalles = $this->foto('price_change_price_type', 'price_change_id', array_column($antes, 'id'));
        $max_detalle = (int) DB::table('price_change_price_type')->max('id');
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        $creados = DB::table('price_changes')->where('article_id', $articulo->id)
            ->whereNotIn('id', array_column($antes, 'id'))->pluck('id')->all();
        $this->assertNotEmpty($creados, 'La migración debe generar historial posterior al callback.');
        $this->assertGreaterThan(0, DB::table('price_change_price_type')->whereIn('price_change_id', $creados)->count());
        $this->ejecutar_reversion($sql);
        $this->assertSame($antes, $this->foto('price_changes', 'article_id', [$articulo->id, $centinela->id]));
        $this->assertSame($detalles, $this->foto('price_change_price_type', 'price_change_id', array_column($antes, 'id')));
        $this->assertSame(0, DB::table('price_change_price_type')->whereIn('price_change_id', $creados)->count(), 'P1: no dejar detalles del historial revertido.');
        $this->assertSame(0, DB::table('price_change_price_type as d')->leftJoin('price_changes as p', 'p.id', '=', 'd.price_change_id')
            ->where('d.id', '>', $max_detalle)->whereNull('p.id')->count(), 'Cero huérfanos NUEVOS; no sanear ajenos.');
    }
}
