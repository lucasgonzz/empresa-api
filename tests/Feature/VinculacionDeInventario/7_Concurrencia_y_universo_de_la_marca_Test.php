<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\InventoryLinkage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CA15 (concurrencia: modelo leído antes → comando → intento de guardar el modelo viejo) y CA16
 * (universo global de la reversión: clases A, B, C1, C2 y X) del PLAN D2-M R3.1.
 *
 * En CA16, B1 emula `DuplicarRecetaHelper::duplicar_articulo_sin_historial` (`replicate()` y
 * `provider_article_id` en null: el método es privado) y B2 emula el `nullify_provider_article_id` de
 * la limpieza de inventario con el mismo efecto sobre la fila.
 *
 * @group vinculacion-iva-copia
 */
class Concurrencia_y_universo_de_la_marca_Test extends VinculacionDeInventarioTestCase
{
    /** @var bool Interruptor de los listeners de CA15c y CA15d (se registran una vez y se apagan). */
    private $listener_activo = false;

    /**
     * Fixture de CA15: salidas prendidas, fuente en 4828, copia con el estado pedido.
     *
     * @param  bool $con_marca
     * @return array [fuente, copia]
     */
    private function fixture_ca15($con_marca)
    {
        $this->escenario(['listas_de_precio' => 1]);
        $fuente = $this->crear_fuente();
        $copia = $this->copia_expuesta($fuente);

        if ($con_marca) {
            $copia = $this->fijar($copia, [
                'cost' => self::NETO, 'costo_real' => self::BRUTO, 'final_price' => self::FINAL, 'costo_neto_de_vinculacion' => 1,
            ]);
        }

        $this->prender_salidas($copia);

        return [$fuente, Article::find($copia->id)];
    }

    /**
     * Registra un listener de `retrieved` que, la primera vez que se lee la copia, la deja por DB en
     * el estado dado (emula a E1 o a la reversión corriendo entre la búsqueda y la escritura).
     *
     * @param  int   $copia_id
     * @param  array $columnas
     * @return void
     */
    private function intercalar_comando($copia_id, array $columnas)
    {
        $this->listener_activo = true;

        Article::retrieved(function ($modelo) use ($copia_id, $columnas) {
            if ($this->listener_activo && $modelo->id == $copia_id) {
                $this->listener_activo = false;
                DB::table('articles')->where('id', $copia_id)->update($columnas);
            }
        });
    }

    /**
     * @test
     */
    public function ca15a_el_cron_contra_e1_no_deja_marca_1_con_bruto_inflado()
    {
        list($fuente, $copia) = $this->fixture_ca15(false);
        $viejo = Article::find($copia->id);

        list($codigo, $salida) = $this->convertir([$copia->id]);
        $this->assertEquals(0, $codigo, $salida);

        $fuente = $this->mover_fuente($fuente, 6050.00);

        $this->escuchar_sentencias();
        $this->assertTrue($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo));
        $sentencias = $this->sentencias;

        $v = $this->valores($copia->id);
        $this->assertEquals(1, $v['marca']);
        $this->assertEqualsWithDelta(5000.0, $v['cost'], 0.0000005, 'no cost 6050 con marca 1');
        $this->assertEqualsWithDelta(6050.0, $v['costo_real'], 0.0000005);
        $this->assertEqualsWithDelta(6655.00, $v['final'], 0.0001, 'no 8052,55');

        $this->assertFalse($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo), 'segunda llamada');
        $this->assertEqualsWithDelta(5000.0, (float) $viejo->cost, 0.0000005, 'el objeto del llamador queda con lo guardado');

        $this->assert_candado($sentencias, $copia->id);

        list($codigo, $salida) = $this->comando(['--verificar' => true, '--ids' => $this->ids_en_archivo([$copia->id])]);
        $this->assertEquals(0, $codigo, $salida);
    }

    /**
     * @test
     */
    public function ca15b_el_cron_contra_la_reversion_no_deja_marca_0_con_neto()
    {
        list($fuente, $copia) = $this->fixture_ca15(true);
        $viejo = Article::find($copia->id);

        DB::table('inventory_linkages')->update(['copia_en_costo_neto' => 0]);
        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--aplicar' => true]);
        $this->assertEquals(0, $codigo, $salida);
        $this->assertEqualsWithDelta(4828.0, $this->valores($copia->id)['cost'], 0.0000005);

        $fuente = $this->mover_fuente($fuente, 6050.00);
        $this->assertTrue($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo));

        $v = $this->valores($copia->id);
        $this->assertEquals(0, $v['marca']);
        $this->assertEqualsWithDelta(6050.0, $v['cost'], 0.0000005, 'no cost 5000 con marca 0');
        $this->assertEqualsWithDelta(7320.50, $v['costo_real'], 0.005);
        $this->assertEqualsWithDelta(8052.55, $v['final'], 0.0001);

        $this->assertFalse($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo), 'segunda llamada');

        list($codigo, $salida) = $this->comando(['--verificar' => true, '--ids' => $this->ids_en_archivo([$copia->id])]);
        $this->assertEquals(0, $codigo, $salida);
    }

    /**
     * @test
     */
    public function ca15c_check_article_contra_e1_decide_con_la_marca_releida()
    {
        list($fuente, $copia) = $this->fixture_ca15(false);
        $fuente = $this->mover_fuente($fuente, 6050.00);

        $this->intercalar_comando($copia->id, ['cost' => self::NETO, 'costo_neto_de_vinculacion' => 1]);

        $this->escuchar_sentencias();
        $this->helper()->checkArticle($fuente);
        $sentencias = $this->sentencias;

        $v = $this->valores($copia->id);
        $this->assertEquals(1, $v['marca']);
        $this->assertEqualsWithDelta(5000.0, $v['cost'], 0.0000005, 'hoy da cost 6050');
        $this->assertEqualsWithDelta(6655.00, $v['final'], 0.0001, 'hoy da 8052,55');

        $this->assert_candado($sentencias, $copia->id);
    }

    /**
     * @test
     */
    public function ca15d_check_article_contra_la_reversion_nunca_deja_marca_0_con_neto()
    {
        list($fuente, $copia) = $this->fixture_ca15(true);
        $fuente = $this->mover_fuente($fuente, 6050.00);

        $this->intercalar_comando($copia->id, ['cost' => 4828, 'costo_neto_de_vinculacion' => 0]);

        $this->helper()->checkArticle($fuente);

        $v = $this->valores($copia->id);
        $this->assertEquals(0, $v['marca']);
        $this->assertEqualsWithDelta(6050.0, $v['cost'], 0.0000005, 'el bruto que escribe el código de hoy');
        $this->assertEqualsWithDelta(8052.55, $v['final'], 0.0001);
    }

    /**
     * CA15e — el primer `for update` de la copia es la relectura por id del helper, antes del
     * `for update` del historial y del `update`, en una transacción. Sin escribir, 0 `for update`.
     *
     * @param  array $sentencias
     * @param  int   $copia_id
     * @return void
     */
    private function assert_candado(array $sentencias, $copia_id)
    {
        $primero = null;
        $indice_update = null;
        $indice_historial = null;

        foreach ($sentencias as $i => $s) {
            $es_for_update = strpos($s['sql'], 'for update') !== false && strpos($s['sql'], 'from `articles`') !== false;
            $con_la_copia = in_array($copia_id, $s['bindings']);

            if ($es_for_update && $con_la_copia && is_null($primero)) {
                $primero = $i;
                $this->assertFalse(strpos($s['sql'], ' in (') !== false, 'la relectura es por id, no el whereIn del historial');
                $this->assertGreaterThanOrEqual(1, $s['nivel'], 'dentro de una transacción');
            }

            if ($es_for_update && strpos($s['sql'], ' in (') !== false && is_null($indice_historial)) {
                $indice_historial = $i;
            }

            // El primer update de la copia DESPUÉS del candado (en CA15c el listener emula a E1 con un
            // update anterior, que no es del helper).
            if (!is_null($primero) && $i > $primero && strpos($s['sql'], 'update `articles`') === 0 && $con_la_copia && is_null($indice_update)) {
                $indice_update = $i;
            }
        }

        $this->assertNotNull($primero, 'hay una relectura con for update de la copia');
        $this->assertNotNull($indice_update, 'hay un update de la copia después del candado');
        $this->assertLessThan($indice_update, $primero, 'el candado va antes del update');

        if (!is_null($indice_historial)) {
            $this->assertLessThan($indice_historial, $primero, 'el candado va antes del for update del historial');
        }
    }

    /**
     * CA15e, segunda parte: un sync que no escribe (caché sin cambios) no toma ningún `for update`.
     *
     * @test
     */
    public function ca15e_un_sync_sin_cambios_no_toma_candado()
    {
        list($fuente, $copia) = $this->fixture_ca15(true);

        $this->escuchar_sentencias();
        $this->assertFalse($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, Article::find($fuente->id), Article::find($copia->id)));

        foreach ($this->sentencias as $s) {
            $this->assertFalse(strpos($s['sql'], 'for update') !== false, 'sin for update: ' . $s['sql']);
        }
    }

    /**
     * CA15f — reintento seguro: una excepción en `Article::saving` durante el primer intento deja
     * todo igual (rollback) y una segunda llamada deja los valores de CA15a con UN solo price_changes
     * y UNA fila en cada sync_to_*.
     *
     * @test
     */
    public function ca15f_una_excepcion_en_el_primer_intento_no_deja_rastro_y_el_reintento_escribe_una_vez()
    {
        list($fuente, $copia) = $this->fixture_ca15(false);
        $viejo = Article::find($copia->id);
        list($codigo, $salida) = $this->convertir([$copia->id]);
        $this->assertEquals(0, $codigo, $salida);
        $fuente = $this->mover_fuente($fuente, 6050.00);

        $lanzar = true;
        Article::saving(function ($modelo) use (&$lanzar, $copia) {
            if ($lanzar && $modelo->id == $copia->id) {
                $lanzar = false;
                throw new \RuntimeException('falla simulada en el primer intento');
            }
        });

        $antes = $this->foto_efectos([$copia->id]);

        try {
            $this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo);
            $this->fail('la excepción tiene que propagarse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('falla simulada', $e->getMessage());
        }

        $this->assertEquals($antes, $this->foto_efectos([$copia->id]), 'fila, price_changes y sync_to_* iguales');

        $this->assertTrue($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo));
        $despues = $this->foto_efectos([$copia->id]);

        $this->assertEquals($antes['price_changes'] + 1, $despues['price_changes'], 'un solo price_changes');
        $this->assertEquals($antes['sync_to_t_n_articles'] + 1, $despues['sync_to_t_n_articles'], 'una sola fila en sync_to_t_n_articles');
        $this->assertEquals($antes['sync_to_meli_articles'] + 1, $despues['sync_to_meli_articles'], 'una sola fila en sync_to_meli_articles');
        $this->assertEqualsWithDelta(6655.00, $this->valores($copia->id)['final'], 0.0001);
    }

    /**
     * CA15g — un escritor que no es el helper (una masiva con un modelo viejo) pisa la copia después
     * de E1: `--verificar` termina con código 1, la lista como "marca 1 / inflada" y no escribe.
     *
     * @test
     */
    public function ca15g_verificar_detecta_al_escritor_fuera_del_helper()
    {
        list($fuente, $copia) = $this->fixture_ca15(false);
        $viejo = Article::find($copia->id);

        list($codigo, $salida) = $this->convertir([$copia->id]);
        $this->assertEquals(0, $codigo, $salida);

        $viejo->costo_real = 5841.88;
        $viejo->final_price = 6426.07;
        $viejo->save();

        $antes = $this->foto_efectos([$copia->id]);

        list($codigo, $salida) = $this->comando(['--verificar' => true, '--ids' => $this->ids_en_archivo([$copia->id])]);

        $this->assertEquals(1, $codigo, $salida);
        $this->assertStringContainsString('marca 1 / inflada', $salida);
        $this->assertStringContainsString((string) $copia->id, $salida);
        $this->assertEquals($antes, $this->foto_efectos([$copia->id]), 'no escribe nada');
    }

    /* ------------------------------------------------------------------------------------------
     * CA16 — universo global de la reversión
     * ---------------------------------------------------------------------------------------- */

    /**
     * Agrega un cliente (usuario, Provider hacia el proveedor, Client y vinculación) a un proveedor.
     *
     * @param  \App\Models\User $proveedor
     * @return array [usuario cliente, Client, InventoryLinkage]
     */
    private function agregar_cliente($proveedor)
    {
        $usuario = $this->crear_dueno([
            'aplicar_iva_al_costo' => 1, 'usar_condicion_fiscal_en_costeo' => 0, 'condicion_iva_precios' => User::CONDICION_RRII,
        ]);

        $this->crear_proveedor($usuario, ['name' => 'zz proveedor ' . $proveedor->id, 'percentage_gain' => 10, 'comercio_city_user_id' => $proveedor->id]);

        $cliente = Client::create(['user_id' => $proveedor->id, 'name' => 'zz cliente ' . $usuario->id, 'comercio_city_user_id' => $usuario->id]);
        $vinculacion = $this->crear_vinculacion($cliente, true);

        $this->actingAs(User::find($this->fenix->id), 'web');

        return [$usuario, $cliente, $vinculacion];
    }

    /**
     * Arma las 11 filas con marca 1 de CA16. Devuelve un mapa nombre → id de artículo.
     *
     * @return array
     */
    private function universo()
    {
        $this->escenario();                       // P1 = Fénix, cliente 1 = Galván (vinculación base)
        $p1 = $this->fenix;
        $v_a = $this->vinculacion;

        $p2 = $this->crear_dueno(['aplicar_iva_al_costo' => 1, 'usar_condicion_fiscal_en_costeo' => 0, 'condicion_iva_precios' => User::CONDICION_RRII]);
        list($u3, $cliente3, $v3) = $this->agregar_cliente($p2);
        list($u2, $cliente2, $v2) = $this->agregar_cliente($p1);   // destino del cambio de client_id
        list($u4, $cliente4, $v4) = $this->agregar_cliente($p1);   // C1a
        list($u5, $cliente5, $v5) = $this->agregar_cliente($p1);   // C1b (se borra la vinculación)
        list($u6, $cliente6, $v6) = $this->agregar_cliente($p1);   // C1c (cliente a la papelera)

        // El interruptor de v2 se apaga: sólo es el destino del cambio de cliente.
        DB::table('inventory_linkages')->where('id', $v2->id)->update(['copia_en_costo_neto' => 0]);

        $mapa = [];
        $ayuda = function ($vinculacion, $proveedor_id) {
            return new InventoryLinkageHelper($vinculacion, $proveedor_id);
        };

        $fuente = function ($usuario) {
            $articulo = $this->crear_articulo($usuario, ['name' => 'zz Fuente ' . uniqid()]);
            $this->fijar_precio_de_fuente($articulo->id, 4828.00);

            return Article::find($articulo->id);
        };

        // La copia se crea por UNA vinculación (ensure_…): checkArticle recorrería todas las del proveedor.
        $copiar = function ($vinculacion, $proveedor, $origen) use ($ayuda) {
            return $ayuda($vinculacion, $proveedor->id)->ensure_client_article_for_linkage($vinculacion, $origen);
        };

        $f_a1 = $fuente($p1);
        $mapa['A1'] = $copiar($v_a, $p1, $f_a1)->id;

        $f_a2 = $fuente($p2);
        $mapa['A2'] = $copiar($v3, $p2, $f_a2)->id;

        $f_a3 = $fuente($p1);
        $a3 = $copiar($v_a, $p1, $f_a3);
        $mapa['A3'] = $a3->id;

        $f_b2 = $fuente($p1);
        $mapa['B2'] = $copiar($v_a, $p1, $f_b2)->id;

        $f_c2 = $fuente($p1);
        $mapa['C2'] = $copiar($v_a, $p1, $f_c2)->id;

        $f_xa = $fuente($p1);
        $mapa['Xa'] = $copiar($v_a, $p1, $f_xa)->id;

        $f_c1a = $fuente($p1);
        $mapa['C1a'] = $copiar($v4, $p1, $f_c1a)->id;

        $f_c1b = $fuente($p1);
        $mapa['C1b'] = $copiar($v5, $p1, $f_c1b)->id;

        $f_xb = $fuente($p1);
        $mapa['Xb'] = $copiar($v5, $p1, $f_xb)->id;

        $f_c1c = $fuente($p1);
        $mapa['C1c'] = $copiar($v6, $p1, $f_c1c)->id;

        // B1: duplicado de A1 (replicate + provider_article_id null).
        $nuevo = Article::find($mapa['A1'])->replicate();
        $nuevo->provider_article_id = null;
        $nuevo->bar_code = null;
        $nuevo->slug = 'zz-duplicado-' . uniqid();
        $nuevo->save();
        $mapa['B1'] = $nuevo->id;

        // B2: limpieza de inventario (provider_article_id anulado).
        DB::table('articles')->where('id', $mapa['B2'])->update(['provider_article_id' => null]);

        // C2: la fuente se borró físicamente.
        DB::table('articles')->where('id', $f_c2->id)->delete();

        // A3 en la papelera.
        $a3->delete();

        // Xa: desincronizada (la fuente cambió y no se recalculó). Xb: C1 con costo_real ≠ fuente.final_price.
        $this->fijar_precio_de_fuente($f_xa->id, 6050.00);
        DB::table('articles')->where('id', $mapa['Xb'])->update(['costo_real' => 3000.00]);

        // C1a: cambia el client_id de la vinculación al cliente 2 (PUT del controlador).
        $this->putJson('api/inventory-linkage/' . $v4->id, [
            'client_id' => $cliente2->id, 'inventory_linkage_scope_id' => $v4->inventory_linkage_scope_id,
            'use_categories' => 0, 'categories' => [],
        ])->assertStatus(200);

        // C1b / Xb: se borra la vinculación v5. C1c: el cliente va a la papelera.
        $this->deleteJson('api/inventory-linkage/' . $v5->id)->assertStatus(200);
        $cliente6->delete();

        $mapa['_cliente1'] = $this->cliente_de_fenix->id;
        $mapa['_v4'] = $v4->id;
        $mapa['_cliente4'] = $cliente4->id;
        $mapa['_f_xa'] = $f_xa->id;
        $mapa['_f_a1'] = $f_a1->id;
        $mapa['_f_c1a'] = $f_c1a->id;

        return $mapa;
    }

    private function ids_del_universo(array $mapa)
    {
        $ids = [];
        foreach ($mapa as $nombre => $id) {
            if ($nombre[0] !== '_') {
                $ids[$nombre] = $id;
            }
        }

        return $ids;
    }

    /**
     * @test
     */
    public function ca16_el_universo_global_se_revierte_por_clase_y_el_conteo_llega_a_cero()
    {
        $mapa = $this->universo();
        $ids = $this->ids_del_universo($mapa);

        // Todos los interruptores apagados antes de revertir.
        DB::table('inventory_linkages')->update(['copia_en_costo_neto' => 0]);

        $this->assertEquals(11, DB::table('articles')->where('costo_neto_de_vinculacion', 1)->count(), 'las 11 filas con marca 1');

        $suma_antes = (float) DB::table('articles')->whereIn('id', $ids)->sum('final_price');
        $filas_antes = [];
        foreach ($ids as $nombre => $id) {
            $filas_antes[$nombre] = (array) DB::table('articles')->where('id', $id)->first();
        }

        // (a) simulación: nada cambia; salida por clase y conteo global 11.
        $efectos = $this->foto_efectos(array_values($ids));
        list($codigo, $salida) = $this->comando(['--revertir-todas' => true]);
        $this->assertEquals($efectos, $this->foto_efectos(array_values($ids)));
        foreach (['Clase A: 3', 'Clase B: 2', 'Clase C1: 3', 'Clase C2: 1', 'Clase X: 2', 'Conteo global de marca 1: 11'] as $linea) {
            $this->assertStringContainsString($linea, $salida);
        }

        // (b) --aplicar.
        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--aplicar' => true]);

        foreach (['A1', 'A2', 'A3', 'C1a', 'C1b', 'C1c'] as $nombre) {
            $fila = (array) DB::table('articles')->where('id', $ids[$nombre])->first();
            $this->assertEqualsWithDelta(4828.0, (float) $fila['cost'], 0.0000005, $nombre . ': cost = fuente.final_price');
            $this->assertEquals(0, (int) $fila['costo_neto_de_vinculacion'], $nombre . ': marca 0');
            foreach (self::PRECIO as $columna) {
                $this->assertEquals($filas_antes[$nombre][$columna], $fila[$columna], $nombre . ': ' . $columna);
            }
        }

        foreach (['B1', 'B2', 'C2'] as $nombre) {
            $fila = (array) DB::table('articles')->where('id', $ids[$nombre])->first();
            $this->assertEquals(0, (int) $fila['costo_neto_de_vinculacion'], $nombre . ': marca 0');
            $esperada = $filas_antes[$nombre];
            $esperada['costo_neto_de_vinculacion'] = 0;
            $this->assertEquals($esperada, $fila, $nombre . ': el resto de la fila igual');
        }

        foreach (['Xa', 'Xb'] as $nombre) {
            $this->assertEquals($filas_antes[$nombre], (array) DB::table('articles')->where('id', $ids[$nombre])->first(), $nombre . ' sin tocar');
        }

        $this->assertStringContainsString('Excluida id=' . $ids['Xa'] . ' clase=A', $salida);
        $this->assertStringContainsString('Excluida id=' . $ids['Xb'] . ' clase=C1', $salida);
        $this->assertStringContainsString('Conteo global de marca 1: 2', $salida);
        $this->assertEquals(1, $codigo);

        // B1 y B2: un setFinalPrice posterior da 5310,80 (artículo común con costo neto y aplicar_iva 1).
        foreach (['B1', 'B2'] as $nombre) {
            \App\Http\Controllers\Helpers\ArticleHelper::setFinalPrice(Article::find($ids[$nombre]), Article::find($ids[$nombre])->user_id);
            $this->assertEqualsWithDelta(self::FINAL, $this->valores($ids[$nombre])['final'], 0.0001, $nombre);
        }

        // (f) la suma de final_price de las filas del universo, salvo B1/B2 recalculadas, no cambia.
        $sin_b = array_values(array_diff_key($ids, ['B1' => 1, 'B2' => 1]));
        $suma_b = (float) DB::table('articles')->whereIn('id', $sin_b)->sum('final_price');
        $suma_b_antes = 0.0;
        foreach (array_diff_key($filas_antes, ['B1' => 1, 'B2' => 1]) as $fila) {
            $suma_b_antes += (float) $fila['final_price'];
        }
        $this->assertEqualsWithDelta($suma_b_antes, $suma_b, 0.0001);

        // (c) se resuelven Xa y Xb y se vuelve a correr: conteo 0 y código 0.
        $this->mover_fuente(Article::find($mapa['_f_xa']), 4828.00);
        DB::table('articles')->where('id', $ids['Xb'])->update(['costo_real' => 4828.00]);

        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--aplicar' => true]);
        $this->assertEquals(0, $codigo, $salida);
        $this->assertStringContainsString('Conteo global de marca 1: 0', $salida);
        $this->assertEquals(0, DB::table('articles')->where('costo_neto_de_vinculacion', 1)->count());

        // (g) --verificar: conteo 0 y 0 interruptores.
        list($codigo, $salida) = $this->comando(['--verificar' => true]);
        $this->assertStringContainsString('Conteo global de marca 1: 0', $salida);
        $this->assertStringContainsString('Interruptores prendidos: 0', $salida);
    }

    /**
     * CA16d — C1a, después de devolver la vinculación al cliente de origen, `sync_…` con la fuente sin
     * cambios devuelve false (como una copia expuesta de hoy).
     *
     * @test
     */
    public function ca16d_c1a_devuelta_a_su_cliente_no_se_reescribe()
    {
        $mapa = $this->universo();
        DB::table('inventory_linkages')->update(['copia_en_costo_neto' => 0]);

        $this->comando(['--revertir-todas' => true, '--aplicar' => true]);

        $this->putJson('api/inventory-linkage/' . $mapa['_v4'], [
            'client_id' => $mapa['_cliente4'], 'inventory_linkage_scope_id' => InventoryLinkage::find($mapa['_v4'])->inventory_linkage_scope_id,
            'use_categories' => 0, 'categories' => [],
        ])->assertStatus(200);

        $vinculacion = InventoryLinkage::find($mapa['_v4']);
        $copia = Article::find($mapa['C1a']);

        $this->assertFalse((new InventoryLinkageHelper($vinculacion, $this->fenix->id))
            ->sync_client_article_price_and_bar_code($vinculacion, Article::find($mapa['_f_c1a']), $copia));
    }

    /**
     * CA16e — con `--cliente` y sin `--aplicar` lista sólo las del cliente; con `--aplicar` se niega.
     *
     * @test
     */
    public function ca16e_cliente_filtra_la_simulacion_y_se_niega_con_aplicar()
    {
        $mapa = $this->universo();
        DB::table('inventory_linkages')->update(['copia_en_costo_neto' => 0]);

        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--cliente' => $this->galvan->id]);
        $this->assertStringContainsString('Conteo global de marca 1: 11', $salida);

        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--cliente' => $this->galvan->id, '--aplicar' => true]);
        $this->assertNotEquals(0, $codigo);
        $this->assertEquals(11, DB::table('articles')->where('costo_neto_de_vinculacion', 1)->count(), 'no escribió');
    }
}
