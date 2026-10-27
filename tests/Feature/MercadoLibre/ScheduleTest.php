<?php

namespace Tests\Feature\MercadoLibre;

use App\Console\Kernel;
use App\Models\PlatformConnector;
use App\Models\SyncToMeliArticle;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Gates del schedule de Mercado Libre (revisión de merge de la misión mercado-libre-integracion,
 * 27/10/2026): `sync_to_meli_articles` exige además la cuenta conectada, como la consulta de
 * pedidos. Sin cuenta, el comando no tiene con qué trabajar y arrancaba un artisan por minuto para
 * nada.
 *
 * @group mercado-libre
 */
class ScheduleTest extends MercadoLibreTestCase
{
    /**
     * El evento del schedule cuyo comando contiene el fragmento.
     *
     * @param string $fragmento
     * @return \Illuminate\Console\Scheduling\Event
     */
    protected function evento($fragmento)
    {
        $schedule = new Schedule();

        $kernel = $this->app->make(Kernel::class);
        $metodo = new \ReflectionMethod($kernel, 'schedule');
        $metodo->setAccessible(true);
        $metodo->invoke($kernel, $schedule);

        foreach ($schedule->events() as $evento) {
            if (strpos((string) $evento->command, $fragmento) !== false) {
                return $evento;
            }
        }

        $this->fail('El schedule no programa "'.$fragmento.'".');
    }

    public function test_sync_de_articulos_con_pendientes_pero_sin_cuenta_conectada_no_arranca()
    {
        $article = $this->articulo_ml($this->categoria_local('MLA5232'));
        $this->sync_pendiente($article);

        $this->assertFalse($this->evento('sync_to_meli_articles')->filtersPass($this->app), 'Sin cuenta conectada no hay con qué sincronizar.');
    }

    public function test_sync_de_articulos_con_pendientes_y_cuenta_conectada_arranca()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria_local('MLA5232'));
        $this->sync_pendiente($article);

        $this->assertTrue($this->evento('sync_to_meli_articles')->filtersPass($this->app));
    }

    public function test_sync_de_articulos_con_la_conexion_cortada_no_arranca()
    {
        $this->conector_ml(['status' => PlatformConnector::STATUS_ERROR]);
        $article = $this->articulo_ml($this->categoria_local('MLA5232'));
        $this->sync_pendiente($article);

        $this->assertFalse($this->evento('sync_to_meli_articles')->filtersPass($this->app));
        $this->assertTrue(SyncToMeliArticle::where('article_id', $article->id)->exists());
    }
}
