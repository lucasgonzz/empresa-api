<?php

namespace Tests\Feature\ArticuloSlug;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ArticleSlugBackfillHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Backfill de slugs RÁPIDO (misión completar-slugs-rapido, 27/11/2026).
 *
 * `articles:completar-slugs --aplicar` hacía un SELECT por candidato de slug y un UPDATE por artículo
 * (en Servian, ~1 artículo por segundo). La nueva implementación carga los slugs tomados una vez por
 * dueño, calcula en memoria y escribe en lote, pero tiene que dar EXACTAMENTE los mismos slugs que la
 * regla secuencial del ABM (`ArticleHelper::slug`). Estos tests fijan:
 *   - equivalencia con una implementación de referencia secuencial (que usa `ArticleHelper::slug`);
 *   - cantidad de consultas acotada aunque haya cientos de artículos con el mismo nombre;
 *   - modo reporte sin escribir, empleados/otros dueños intactos y `updated_at` sin mover.
 *
 * Los nombres empiezan con `ZZ ` para no chocar con el fixture; los dueños son propios de cada test.
 *
 * IMPORTANTE: PHP 7.4 estricto, sin sintaxis de PHP 8 (ver .mision/RESTRICCIONES.md).
 */
class CompletarSlugsRapidoTest extends EmpresaTestCase
{
    /**
     * Fecha vieja de `updated_at` con la que se siembra todo.
     *
     * @var string
     */
    const UPDATED_AT_VIEJO = '2020-01-02 03:04:05';

    /**
     * Crea un dueño nuevo (owner_id null).
     *
     * @param string $etiqueta
     * @return \App\Models\User
     */
    protected function crear_dueno($etiqueta)
    {
        return User::forceCreate([
            'name'         => 'Comercio ' . $etiqueta,
            'company_name' => 'ZZ Comercio ' . $etiqueta,
            'email'        => 'rapido-' . $etiqueta . '-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Siembra artículos de golpe con escritura directa (rápido, sin observers).
     *
     * @param int $user_id
     * @param array $filas Lista de ['name' => string, 'slug' => string|null, 'deleted' => bool].
     * @return array Ids insertados en el orden recibido.
     */
    protected function sembrar_lote($user_id, array $filas)
    {
        $ids = [];

        foreach (array_chunk($filas, 500) as $tanda) {
            $registros = [];
            foreach ($tanda as $fila) {
                $registros[] = [
                    'user_id'    => $user_id,
                    'name'       => $fila['name'],
                    'slug'       => $fila['slug'],
                    'status'     => 'active',
                    'iva_id'     => 2,
                    'created_at' => self::UPDATED_AT_VIEJO,
                    'updated_at' => self::UPDATED_AT_VIEJO,
                    'deleted_at' => !empty($fila['deleted']) ? self::UPDATED_AT_VIEJO : null,
                ];
            }
            DB::table('articles')->insert($registros);
        }

        // Los ids se releen por dueño y orden de alta (el insert múltiple los asigna correlativos).
        $ids = DB::table('articles')->where('user_id', $user_id)->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * Foto de los artículos de un dueño: id => slug.
     *
     * @param int $user_id
     * @return array
     */
    protected function foto_slugs($user_id)
    {
        return DB::table('articles')->where('user_id', $user_id)->orderBy('id')->pluck('slug', 'id')->all();
    }

    /**
     * Implementación de REFERENCIA: lo que hacía el backfill secuencial. Recorre por id los artículos
     * vivos sin slug, calcula con `ArticleHelper::slug` (contra la base) y escribe uno por uno.
     *
     * @param int $user_id
     * @return void
     */
    protected function backfill_secuencial_de_referencia($user_id)
    {
        $pendientes = Article::where('user_id', $user_id)
                        ->where(function ($query) {
                            $query->whereNull('slug')->orWhere('slug', '');
                        })
                        ->orderBy('id')
                        ->get();

        foreach ($pendientes as $articulo) {
            if (\Illuminate\Support\Str::slug((string) $articulo->name) === '') {
                continue;
            }
            $slug = ArticleHelper::slug($articulo->name, $articulo->id, $user_id);
            DB::table('articles')->where('id', $articulo->id)->update(['slug' => $slug]);
        }
    }

    /**
     * CA1: para un set mezclado (nombres repetidos, con y sin slug previo, borrados con slug igual,
     * nombres sin slug posible, mayúsculas, huecos de sufijos y dos dueños) el resultado del helper
     * es idéntico al de la implementación secuencial de referencia.
     *
     * @return void
     */
    public function test_el_lote_da_los_mismos_slugs_que_el_secuencial()
    {
        $a = $this->crear_dueno('A');
        $b = $this->crear_dueno('B');

        $filas = [];
        // Ya tiene slug propio y deja un hueco: el -1 está libre, el -2 tomado.
        $filas[] = ['name' => 'ZZ Reel Flash', 'slug' => 'zz-reel-flash'];
        $filas[] = ['name' => 'ZZ Reel Flash', 'slug' => 'zz-reel-flash-2'];
        // Borrado con un slug que ya no cuenta como tomado.
        $filas[] = ['name' => 'ZZ Reel Flash', 'slug' => 'zz-reel-flash-1', 'deleted' => true];
        // Slug previo con mayúsculas (la base compara sin distinguirlas).
        $filas[] = ['name' => 'ZZ Mixto', 'slug' => 'ZZ-Mixto'];
        // Un nombre cuyo slug es el candidato con sufijo de otro.
        $filas[] = ['name' => 'ZZ Reel Flash 3', 'slug' => null];
        // Pendientes: muchos con el mismo nombre, NULL y vacío mezclados.
        for ($i = 0; $i < 12; $i++) {
            $filas[] = ['name' => 'ZZ Reel Flash', 'slug' => ($i % 2 === 0 ? null : '')];
        }
        for ($i = 0; $i < 4; $i++) {
            $filas[] = ['name' => 'zz mixto', 'slug' => null];
        }
        // Sin slug posible y un borrado pendiente: no se tocan.
        $filas[] = ['name' => '***', 'slug' => null];
        $filas[] = ['name' => '', 'slug' => ''];
        $filas[] = ['name' => 'ZZ Borrado', 'slug' => null, 'deleted' => true];
        $filas[] = ['name' => 'ZZ Unico', 'slug' => null];

        $this->sembrar_lote($a->id, $filas);
        $this->sembrar_lote($b->id, $filas);

        $original_a = $this->foto_slugs($a->id);
        $original_b = $this->foto_slugs($b->id);

        // Referencia sobre el dueño A.
        $this->backfill_secuencial_de_referencia($a->id);
        $esperado_a = $this->foto_slugs($a->id);

        // El dueño B se completa con el helper (sólo B: A ya quedó completo y no hace falta tocarlo).
        $resultado = ArticleSlugBackfillHelper::procesar(true, $b->id);
        $obtenido_b = $this->foto_slugs($b->id);

        // Los ids difieren entre dueños: se compara por posición (mismo orden de alta).
        $this->assertSame(array_values($esperado_a), array_values($obtenido_b), 'El lote tiene que dar los mismos slugs que el secuencial.');
        $this->assertNotSame(array_values($original_b), array_values($obtenido_b), 'El helper tiene que haber completado algo.');
        $this->assertSame(2, $resultado['sin_nombre'], 'Dos nombres sin slug posible.');
        $this->assertSame(count(array_filter($esperado_a)) - count(array_filter($original_a)), $resultado['completados']);
    }

    /**
     * CA2: con 300 artículos del mismo nombre la cantidad de consultas no crece con la cantidad de
     * artículos (antes: más de un SELECT por candidato, O(n²)).
     *
     * @return void
     */
    public function test_300_articulos_con_el_mismo_nombre_usan_pocas_consultas()
    {
        $a = $this->crear_dueno('A');

        $filas = [];
        for ($i = 0; $i < 300; $i++) {
            $filas[] = ['name' => 'ZZ Repetido', 'slug' => null];
        }
        $this->sembrar_lote($a->id, $filas);

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $resultado = ArticleSlugBackfillHelper::procesar(true, $a->id);
        $consultas = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertSame(300, $resultado['completados']);
        $this->assertLessThanOrEqual(30, $consultas, 'Consultas usadas: ' . $consultas);

        // Y el resultado es el esperado: base, -1 ... -299.
        $slugs = array_values($this->foto_slugs($a->id));
        $this->assertSame('zz-repetido', $slugs[0]);
        $this->assertSame('zz-repetido-1', $slugs[1]);
        $this->assertSame('zz-repetido-299', $slugs[299]);
    }

    /**
     * CA2: con 1000 artículos distintos la cantidad de consultas también es acotada.
     *
     * @return void
     */
    public function test_1000_articulos_distintos_usan_pocas_consultas()
    {
        $a = $this->crear_dueno('A');

        $filas = [];
        for ($i = 0; $i < 1000; $i++) {
            $filas[] = ['name' => 'ZZ Distinto ' . $i, 'slug' => null];
        }
        $this->sembrar_lote($a->id, $filas);

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $resultado = ArticleSlugBackfillHelper::procesar(true, $a->id);
        $consultas = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertSame(1000, $resultado['completados']);
        $this->assertLessThanOrEqual(40, $consultas, 'Consultas usadas: ' . $consultas);
        $this->assertSame(0, DB::table('articles')->where('user_id', $a->id)->where(function ($q) {
            $q->whereNull('slug')->orWhere('slug', '');
        })->count());
    }

    /**
     * CA3: el modo reporte no escribe, la segunda corrida no hace nada, `updated_at` no se mueve y
     * un artículo cargado a nombre de un empleado (o de otro dueño) no se toca.
     *
     * @return void
     */
    public function test_reporte_idempotencia_updated_at_y_otros_usuarios()
    {
        $a = $this->crear_dueno('A');
        $otro = $this->crear_dueno('B');
        $empleado = User::forceCreate([
            'name'         => 'Empleado',
            'company_name' => $a->company_name,
            'email'        => 'rapido-emp-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $a->id,
        ]);

        $filas = [];
        for ($i = 0; $i < 25; $i++) {
            $filas[] = ['name' => 'ZZ Dos ' . ($i % 3), 'slug' => null];
        }
        $this->sembrar_lote($a->id, $filas);
        $this->sembrar_lote($otro->id, [['name' => 'ZZ Ajeno', 'slug' => null]]);
        $this->sembrar_lote($empleado->id, [['name' => 'ZZ Del empleado', 'slug' => null]]);

        // Reporte: cuenta y no escribe.
        $reporte = ArticleSlugBackfillHelper::procesar(false, $a->id);
        $this->assertSame(25, $reporte['pendientes']);
        $this->assertSame(0, $reporte['completados']);
        $this->assertCount(5, $reporte['ejemplos']);
        $this->assertSame(25, DB::table('articles')->where('user_id', $a->id)->whereNull('slug')->count());

        // Aplicar sólo sobre A.
        $aplicado = ArticleSlugBackfillHelper::procesar(true, $a->id);
        $this->assertSame(25, $aplicado['completados']);
        $this->assertSame(0, DB::table('articles')->where('user_id', $a->id)->whereNull('slug')->count());
        $this->assertSame(0, DB::table('articles')->where('user_id', $a->id)->where('updated_at', '!=', self::UPDATED_AT_VIEJO)->count(), 'No se mueve updated_at.');
        $this->assertSame(1, DB::table('articles')->where('user_id', $otro->id)->whereNull('slug')->count(), 'El otro dueño no se toca.');
        $this->assertSame(1, DB::table('articles')->where('user_id', $empleado->id)->whereNull('slug')->count(), 'El empleado no es dueño: no se recorre.');

        // Idempotencia.
        $segunda = ArticleSlugBackfillHelper::procesar(true, $a->id);
        $this->assertSame(0, $segunda['pendientes']);
        $this->assertSame(0, $segunda['completados']);
    }
}
