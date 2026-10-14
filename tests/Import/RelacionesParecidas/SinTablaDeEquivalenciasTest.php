<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\EquivalenciasResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un cliente al que todavía no le corrió la migración de import_equivalencias tiene que poder
 * seguir importando (misión importacion-relaciones-parecidas, tanda 2): leer, recordar y olvidar
 * equivalencias tolera que la tabla no esté.
 *
 * Cómo se simula la tabla ausente: con una subclase que apunta la consulta de equivalencias a una
 * tabla que no existe (una QueryException de verdad, "Table doesn't exist"). No se borra ni se
 * renombra la tabla real porque eso es DDL: en MySQL hace commit implícito y se llevaría puesta la
 * transacción de los demás tests.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class SinTablaDeEquivalenciasTest extends TestCase
{
    /**
     * @param  array $equivalencias
     * @return EquivalenciasResolver
     */
    protected function resolver_sin_tabla(array $equivalencias = [])
    {
        return new class(900, $equivalencias) extends EquivalenciasResolver {
            protected static function consulta_equivalencias()
            {
                return DB::table('import_equivalencias_que_no_existe');
            }
        };
    }

    public function test_sin_la_tabla_se_resuelve_igual_sin_recordadas()
    {
        $resolver = $this->resolver_sin_tabla([
            ['tipo' => 'categoria', 'valor' => 'Del norte',  'accion' => 'crear'],
            ['tipo' => 'categoria', 'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pinturería'],
        ]);

        $resolver->cargar_indice('categoria', [(object) ['id' => 5, 'name' => 'DEL NORTE']]);

        $proximo = 100;
        $crear = function ($nombre) use (&$proximo) {
            return $proximo++;
        };

        $this->assertNull($resolver->recordada('categoria', 0, 'DEL NORTE'));

        /* Sin decisión: exacto. */
        $this->assertSame(5, $resolver->resolver('categoria', 'DEL NORTE', 0, null, $crear));

        /* crear (que intenta OLVIDAR la recordada) y unir (que intenta RECORDAR) no tiran. */
        $this->assertSame(100, $resolver->resolver('categoria', 'Del norte', 0, null, $crear));
        $this->assertSame(101, $resolver->resolver('categoria', 'Pintureria', 0, null, $crear));
        $this->assertSame(101, $resolver->resolver('categoria', 'Pinturería', 0, null, $crear));

        /* recordar_decisiones() (lo que corre en el controller) tampoco. */
        $resolver->recordar_decisiones();
    }

    public function test_el_chequeo_lee_cero_recordadas_sin_la_tabla()
    {
        $resolver = $this->resolver_sin_tabla();

        $this->assertSame([], $resolver::recordadas_del_tipo(900, 'categoria'));
    }
}
