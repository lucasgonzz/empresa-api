<?php

namespace Tests\Feature\Costeo;

use PHPUnit\Framework\TestCase;

/**
 * Grupo 379 · Prompt 04 — guardia de la suite `costeo-precios`.
 *
 * Los doce casos de {@see CascadaDePreciosTest} verifican que los cuatro caminos de precios de
 * `ArticleHelper::setFinalPrice()` decidan el IVA igual. Pero esos doce casos solo cubren los
 * caminos que YA CONOCEMOS: si mañana aparece un quinto camino con su propia lógica de IVA (o si
 * alguien le agrega una segunda llamada a `aplicar_iva()` a uno de los cinco puntos conocidos, o le
 * saca la única que tiene), los doce casos pueden seguir dando verde sin que nadie note que ese
 * camino nuevo quedó sin cobertura — exactamente el modo de falla que dejó a los prompts 01, 02 y
 * 03 de este grupo escondidos dos meses (los cuatro caminos salieron del mismo refactor de costeo
 * por condición fiscal del grupo 231, y tres se quedaron atrás sin que nada lo denunciara).
 *
 * Este test escanea, por tokenizador de PHP (no por texto/grep, que confundiría código comentado
 * con código vivo), los cuerpos de los cinco métodos donde hoy vive una llamada a `aplicar_iva()`
 * en el pipeline reachable desde `setFinalPrice()`, y exige que la cantidad en cada uno siga siendo
 * la conocida. Un cambio en cualquiera de estos números es información: puede ser un camino nuevo,
 * un bug (doble IVA), o un refactor legítimo que hay que reflejar acá a propósito.
 *
 * FIX (checker del prompt 04, 8/8/2026): la primera versión de este test sumaba los cinco números
 * esperados y comparaba contra la suma de los cinco encontrados -- una asercion TAUTOLOGICA (si los
 * cinco `assertSame()` individuales de arriba ya pasaron, la suma cierra sola) que no protegia nada
 * contra el caso que el docblock decía cubrir: un método NUEVO, en uno de estos mismos tres
 * archivos, con su propia llamada a `aplicar_iva()`. Ese método nuevo no está en ninguno de los
 * cinco casos, así que nunca se escanea, y la suma "tautológica" sigue dando 6 aunque el archivo
 * completo ahora tenga 7. Por eso se agrega una SEGUNDA medición, independiente de los cinco casos:
 * la cantidad de llamadas vivas a `aplicar_iva()` en cada uno de los TRES ARCHIVOS COMPLETOS (no
 * solo dentro de los métodos conocidos). Un método nuevo con IVA cambia ese total aunque los cinco
 * casos de arriba sigan intactos -- eso es lo que realmente cierra el hueco.
 *
 * Precedente del patrón (tokenizador en vez de reflexión, que acá no alcanza porque lo que hay que
 * contar son *llamadas dentro de un cuerpo de método*, no propiedades ni métodos declarados):
 * `tests/Unit/Pdf/PropiedadesDePdfInicializadasTest.php` (grupo 324).
 *
 * Extiende `PHPUnit\Framework\TestCase` directo, NO `Tests\TestCase`: solo lee archivos del
 * repositorio con `token_get_all()`, no necesita la aplicación levantada ni base de datos.
 *
 * @group costeo-precios
 */
class GuardiaCaminosDeIvaTest extends TestCase
{
    private const ARTICLE_HELPER = __DIR__.'/../../../app/Http/Controllers/Helpers/ArticleHelper.php';
    private const ARTICLE_PRICES_HELPER = __DIR__.'/../../../app/Http/Controllers/Helpers/article/ArticlePricesHelper.php';
    private const ARTICLE_PRICE_TYPE_MONEDA_HELPER = __DIR__.'/../../../app/Http/Controllers/Helpers/article/ArticlePriceTypeMonedaHelper.php';

    /**
     * Los cinco puntos conocidos, con la cantidad de llamadas a `aplicar_iva()` que tiene HOY cada
     * uno (medido con este mismo escáner sobre la rama `refractor` después de los prompts 01/02/03
     * de este grupo, 8/8/2026):
     *
     * - `ArticleHelper::setFinalPrice()` → 2: la rama `price_from_cost_mas_iva` (prompt 01, línea
     *   ~351) y el bloque común de IVA de venta (línea ~502, guardado por `$iva_ya_aplicado`).
     * - `ArticleHelper::aplicar_descuentos_e_iva()` → 1: Capa 1, el IVA que se suma al costo real
     *   cuando `iva_va_al_costo()` da true (Monotributista / cuenta legacy).
     * - `ArticlePricesHelper::aplicar_precios_segun_listas_de_precios()` → 1: camino 2 (listas).
     * - `ArticlePricesHelper::aplicar_precios_segun_listas_de_precios_y_categorias()` → 1: camino 4
     *   (listas por categoría, prompt 03).
     * - `ArticlePriceTypeMonedaHelper::calcular_precios_por_price_type_y_moneda()` → 1: camino 3
     *   (listas por moneda, prompt 02).
     *
     *   ⚠️ Ese método se llamaba `aplicar_precios_por_price_type_y_moneda()` hasta el 30/8/2026.
     *   Ese nombre lo tiene ahora un envoltorio de tres líneas —llama al cálculo y después espeja
     *   el precio en pesos en la pivot `article_price_type`, que es lo que lee la tienda— y el
     *   cuerpo con toda la lógica pasó a `calcular_...`. **No cambió ninguna llamada de IVA**: la
     *   única sigue estando donde estaba, dentro del cálculo. Lo que se corrigió acá es a qué
     *   método apunta la guardia, porque si no vigilaba el envoltorio vacío y contaba 0 — o sea,
     *   dejaba de vigilar el camino real sin que nada lo dijera.
     *
     * @var array<int, array{archivo: string, metodo: string, esperado: int}>
     */
    private const CASOS = [
        ['archivo' => self::ARTICLE_HELPER, 'metodo' => 'setFinalPrice', 'esperado' => 2],
        ['archivo' => self::ARTICLE_HELPER, 'metodo' => 'aplicar_descuentos_e_iva', 'esperado' => 1],
        ['archivo' => self::ARTICLE_PRICES_HELPER, 'metodo' => 'aplicar_precios_segun_listas_de_precios', 'esperado' => 1],
        ['archivo' => self::ARTICLE_PRICES_HELPER, 'metodo' => 'aplicar_precios_segun_listas_de_precios_y_categorias', 'esperado' => 1],
        ['archivo' => self::ARTICLE_PRICE_TYPE_MONEDA_HELPER, 'metodo' => 'calcular_precios_por_price_type_y_moneda', 'esperado' => 1],
    ];

    /**
     * Cantidad de llamadas VIVAS a `aplicar_iva()` en cada archivo COMPLETO (no solo dentro de los
     * cinco métodos de self::CASOS) — medido con este mismo escáner, 8/8/2026. Ojo:
     * `ArticlePriceTypeMonedaHelper.php` da 3, no 1: incluye las 2 llamadas vivas de
     * `VIEJO_aplicar_precios_por_price_type_y_moneda()` (código muerto, sin llamadores, que el
     * prompt 02 de este grupo dejó a propósito sin borrar como documentación de cómo se hacía
     * antes). Si alguien borra esa función muerta, este número baja a 1 y hay que actualizarlo
     * ACA — es una baja legítima, no una regresión.
     *
     * @var array<string,int>
     */
    private const TOTAL_POR_ARCHIVO = [
        self::ARTICLE_HELPER                    => 3,
        self::ARTICLE_PRICES_HELPER              => 2,
        self::ARTICLE_PRICE_TYPE_MONEDA_HELPER   => 3,
    ];

    /** @test */
    public function la_cantidad_de_llamadas_a_aplicar_iva_en_el_pipeline_es_la_conocida()
    {
        $total_real = 0;

        foreach (self::CASOS as $caso) {
            $tokens = token_get_all(file_get_contents($caso['archivo']));
            $range = $this->method_body_range($tokens, $caso['metodo']);

            $this->assertNotNull(
                $range,
                'No se encontro el metodo "'.$caso['metodo'].'" en '.basename($caso['archivo']).'. '.
                'Si se renombro, actualizar este test; si se borro, la cascada de precios perdio un '.
                'camino conocido y hay que entender por que antes de tocar este archivo.'
            );

            $encontradas = $this->contar_llamadas_aplicar_iva($tokens, $range[0], $range[1]);

            $this->assertSame(
                $caso['esperado'],
                $encontradas,
                'la cantidad de llamadas a aplicar_iva() dentro de '.$caso['metodo'].'() cambio '.
                '(esperaba '.$caso['esperado'].', encontro '.$encontradas.'). Si agregaste o sacaste '.
                'una llamada de IVA a proposito, actualizar el numero esperado ACA (self::CASOS), no '.
                'solo el codigo -- ese es el punto de esta guardia: que un cambio en la cantidad de '.
                'llamadas de IVA de un camino conocido no pase desapercibido.'
            );

            $total_real += $encontradas;
        }

        // Guardia REAL contra un metodo NUEVO (en alguno de estos tres archivos) con su propia
        // logica de IVA: a diferencia de sumar los cinco esperados de arriba (que es tautologico --
        // un metodo nuevo no esta en self::CASOS y nunca lo escanea ese loop), esto cuenta
        // aplicar_iva() en el ARCHIVO COMPLETO, method_body_range() aparte. Un metodo nuevo con IVA
        // cambia este numero aunque los cinco casos de arriba sigan intactos.
        foreach (self::TOTAL_POR_ARCHIVO as $archivo => $esperado_archivo) {
            $tokens = token_get_all(file_get_contents($archivo));
            $encontradas_archivo = $this->contar_llamadas_aplicar_iva($tokens, 0, count($tokens) - 1);

            $this->assertSame(
                $esperado_archivo,
                $encontradas_archivo,
                'la cantidad TOTAL de llamadas a aplicar_iva() en todo '.basename($archivo).' '.
                '(no solo dentro de los metodos conocidos de self::CASOS) cambio -- esperaba '.
                $esperado_archivo.', encontro '.$encontradas_archivo.'. Si esto sube y ningun caso '.
                'de self::CASOS lo explica, hay un metodo NUEVO en este archivo con su propia '.
                'llamada a aplicar_iva() -- exactamente el quinto camino sin cobertura que esta '.
                'guardia existe para denunciar. Agregale su fila a self::CASOS (y, si corresponde, '.
                'un caso a la tabla de doce de CascadaDePreciosTest) antes de actualizar este numero.'
            );
        }

        // El modo de falla mas peligroso de un escaner asi es que se rompa en silencio y devuelva
        // cero en todos lados: los doce casos de CascadaDePreciosTest seguirian verdes, PERO esta
        // guardia tambien, sin proteger nada. Nunca puede llegar a cero caminos descubiertos.
        $this->assertGreaterThan(
            0,
            $total_real,
            'el escaner no encontro NINGUNA llamada a aplicar_iva() en los cinco metodos conocidos. '.
            'Esto no significa que el pipeline se quedo sin IVA en ningun lado -- significa que el '.
            'escaner (o los nombres de metodo/archivo de self::CASOS) esta roto.'
        );
    }

    /**
     * Indices [inicio, fin] (ambos inclusive) de los tokens '{' y '}' que delimitan el cuerpo del
     * primer metodo con nombre $methodName. null si no existe o no tiene cuerpo (interfaz/abstracto).
     * Adaptado de PropiedadesDePdfInicializadasTest::method_body_range() (grupo 324).
     *
     * @return array{0:int,1:int}|null
     */
    private function method_body_range(array $tokens, string $methodName): ?array
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }

            $j = $this->next_significant($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING
                || $tokens[$j][1] !== $methodName) {
                continue;
            }

            // Saltear la lista de parametros para llegar a la '{' de apertura del cuerpo sin
            // confundirla con un '{' de un default de parametro (ej. un array()).
            $depth = 0;
            $bodyStart = null;
            for ($p = $j; $p < $count; $p++) {
                $t = $tokens[$p];
                if ($t === '(') { $depth++; }
                elseif ($t === ')') { $depth--; }
                elseif ($depth === 0 && $t === '{') { $bodyStart = $p; break; }
                elseif ($depth === 0 && $t === ';') { return null; }
            }
            if ($bodyStart === null) { return null; }

            $braceDepth = 0;
            for ($p = $bodyStart; $p < $count; $p++) {
                if ($tokens[$p] === '{') { $braceDepth++; }
                elseif ($tokens[$p] === '}') {
                    $braceDepth--;
                    if ($braceDepth === 0) { return [$bodyStart, $p]; }
                }
            }
            return null;
        }

        return null;
    }

    /**
     * Cuenta, dentro de [$start, $end], las llamadas reales (no comentadas, no la propia
     * declaracion) a `aplicar_iva(`. token_get_all() ya excluye por si solo el texto de un `// ...`
     * o `/* ... *\/` del stream de tokens de codigo (queda adentro de UN solo token T_COMMENT), asi
     * que una llamada comentada nunca se cuenta -- a diferencia de un grep de texto plano.
     */
    private function contar_llamadas_aplicar_iva(array $tokens, int $start, int $end): int
    {
        $count = 0;

        for ($i = $start; $i <= $end; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'aplicar_iva') {
                continue;
            }

            $next = $this->next_significant($tokens, $i + 1);
            if ($next === null || $tokens[$next] !== '(') {
                continue;
            }

            // Descarta la propia declaracion ("function aplicar_iva(" / "static function
            // aplicar_iva("): el token significativo inmediatamente anterior seria T_FUNCTION.
            $prev = $this->prev_significant($tokens, $i - 1);
            if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /** Indice del proximo token que no sea whitespace ni comentario, o null si no hay. */
    private function next_significant(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], $skip, true)) { continue; }
            return $i;
        }
        return null;
    }

    /** Indice del token anterior que no sea whitespace ni comentario, o null si no hay. */
    private function prev_significant(array $tokens, int $from): ?int
    {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        for ($i = $from; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], $skip, true)) { continue; }
            return $i;
        }
        return null;
    }
}
