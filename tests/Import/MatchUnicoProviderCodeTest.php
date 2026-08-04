<?php

namespace Tests\Import;

use App\Models\ImportConflict;

/**
 * Regresión del bug real del 30/7/2026 (grupo 285, prompt 01): con
 * permitir_provider_code_repetido = true, un provider_code que matchea un ÚNICO
 * artículo se procesaba como si matcheara varios. ArticleIndexCache::find_with_index()
 * devolvía siempre una Collection en esa rama (tuviera uno o veinte elementos) y
 * ProcessRow::son_varios_articulos() trataba cualquier Collection como "son varios":
 * el sku/bar_code pendiente de la fila se descartaba con un import_conflict FALSO de
 * tipo identificador_sin_asignar, en vez de asignarse al único artículo matcheado.
 *
 * Este archivo prueba las dos puntas a propósito, no solo el fix: que el match único
 * ahora asigna el identificador sin conflicto (lo que estaba roto), Y que el match
 * múltiple real sigue descartando el identificador y registrando el conflicto (el
 * invariante de Lucas, prompt 08 grupo 265, que no se está cuestionando acá).
 *
 * Fixture propio: 08_match_unico_provider_code.xlsx, dos filas contra el escenario
 * sembrado por ImportTestSeeder — PC-100 (existe 1 vez, artículo A1) y PC-DUP
 * (existe 2 veces, artículos A3 y A4) —, cada una con un sku nuevo que no matchea
 * nada por sí mismo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class MatchUnicoProviderCodeTest extends ImportTestCase
{
    const ARCHIVO = '08_match_unico_provider_code.xlsx';

    /**
     * F2 del fixture: PC-100 matchea UN SOLO artículo (A1). El sku pendiente
     * (SKU-MATCH-UNICO, que no matchea nada por sí mismo) tiene que quedar
     * asignado a A1, y no debe registrarse ningún conflicto identificador_sin_asignar.
     *
     * @group import
     * @test
     */
    public function match_unico_asigna_el_sku_pendiente_sin_conflicto()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
        ]);

        $this->assertSame(
            'SKU-MATCH-UNICO',
            $this->recargar('A1')->sku,
            'A1 (único match de PC-100) tiene que quedar con el sku de la fila'
        );

        /*
         * El fixture tiene DOS filas (F2 el match único de este test, F3 el match múltiple
         * del otro test): $import ya incluye el conflicto real de F3, así que
         * conflictos($import, 'identificador_sin_asignar') sobre el total del import daría
         * 1 aunque F2 esté perfecto. Se escopea por el valor descartado (columna `valor` de
         * ImportConflict) para verificar puntualmente que el sku de ESTA fila (F2) nunca
         * quedó en ningún conflicto.
         */
        $this->assertSame(
            0,
            ImportConflict::where('import_history_id', $import->id)
                ->where('tipo', 'identificador_sin_asignar')
                ->where('valor', 'SKU-MATCH-UNICO')
                ->count(),
            'Un match único NO es una ambigüedad: el sku de F2 no tiene que aparecer en ningún conflicto'
        );
    }

    /**
     * F3 del fixture: PC-DUP matchea DOS artículos (A3 y A4). El sku pendiente
     * (SKU-MATCH-MULTIPLE) tiene que descartarse en los dos, y SÍ tiene que quedar
     * registrado el conflicto identificador_sin_asignar — este es el invariante de
     * Lucas que el fix de este prompt no puede romper.
     *
     * @group import
     * @test
     */
    public function match_multiple_descarta_el_sku_y_registra_el_conflicto()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
        ]);

        $this->assertSame(
            'SKU-003',
            $this->recargar('A3')->sku,
            'A3 (uno de los dos matches de PC-DUP) NO tiene que recibir el sku de la fila'
        );

        $this->assertSame(
            'SKU-004',
            $this->recargar('A4')->sku,
            'A4 (el otro match de PC-DUP) NO tiene que recibir el sku de la fila'
        );

        $this->assertSame(
            1,
            $this->conflictos($import, 'identificador_sin_asignar'),
            'El match múltiple real sí tiene que registrar el conflicto (un solo identificador pendiente: el sku). '
            . 'Este total es exactamente 1 en todo el import porque F2 (el otro test) no aporta ninguno.'
        );

        $this->assertSame(
            1,
            ImportConflict::where('import_history_id', $import->id)
                ->where('tipo', 'identificador_sin_asignar')
                ->where('valor', 'SKU-MATCH-MULTIPLE')
                ->count(),
            'El conflicto tiene que ser puntualmente el del sku de F3, no cualquier otro'
        );
    }
}
