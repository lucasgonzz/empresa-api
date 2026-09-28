<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Services\Traits\GoogleSearchHelpers;

/**
 * ¿El código de barras de un artículo es REAL, el de fábrica del producto? (misión
 * imagenes-catalogo-completo, 27/9/2026).
 *
 * La asignación inteligente de imágenes busca primero por código de barras y recién después por
 * nombre (pedido de Lucas: "primero código de barras, solo si es REAL"). Buscar un código inventado
 * no es gratis ni inocuo: gasta una búsqueda paga y, peor, trae la foto de OTRO producto que por
 * casualidad tiene ese número, con lo que la IA tiene que descartarla (otra llamada paga) o, si se
 * equivoca, queda asignada una imagen que no corresponde. En los comercios hay muchísimos códigos
 * que pasan la validación de dígito verificador y no son de fábrica: el número interno del artículo
 * (BarCodeAutomaticoHelper lo pone como código), los códigos de balanza (prefijo 2), los rellenos
 * con ceros o con una escalera 1234567890128, y un mismo placeholder copiado en decenas de artículos.
 *
 * Por eso cada regla de abajo existe por un caso real, y cada una devuelve un motivo LEGIBLE: va
 * tal cual al diagnóstico del artículo en Alertas → Imágenes ("¿por qué no buscó por código?").
 *
 * Orden de las reglas: primero las que reconocen un código inventado por su forma (número interno,
 * todos iguales, escalera), porque dan un motivo más claro que "el dígito verificador no cierra";
 * después la validación GS1; después los prefijos de circulación restringida; y al final la que
 * pregunta a la base (repetido en 3 o más artículos), que es la única que cuesta una consulta.
 *
 * PHP 7.4: sin str_starts_with (se usa substr) ni match.
 */
class CodigoDeBarrasRealHelper
{
    use GoogleSearchHelpers;

    /**
     * Desde cuántos artículos activos del dueño con el MISMO código se lo considera un código de
     * relleno copiado. Dos artículos con el mismo código pueden ser una variante mal cargada o un
     * duplicado; tres ya es un patrón ("le pongo este código a todo lo que no tiene").
     */
    const MINIMO_DE_ARTICULOS_PARA_PLACEHOLDER = 3;

    /** Largo mínimo para mirar los patrones de "todos iguales" y "escalera" (más corto lo agarra el largo). */
    const LARGO_MINIMO_PARA_PATRONES = 6;

    /**
     * Evalúa el código de barras de un artículo.
     *
     * @param  mixed                $codigo   El código tal como está cargado (puede venir con espacios, guiones o puntos).
     * @param  \App\Models\Article  $article  El artículo dueño del código (para comparar con su id y con sus pares).
     * @return array {
     *     real:        bool,         true si se puede buscar por este código.
     *     normalizado: string,       el código sin espacios, guiones ni puntos (lo que se buscaría).
     *     motivo:      string|null,  por qué NO es real, en castellano, listo para mostrar.
     * }
     */
    public static function evaluar($codigo, Article $article)
    {
        return (new self())->evaluar_codigo($codigo, $article);
    }

    /**
     * Cuerpo de evaluar() (instancia, para poder usar los métodos protegidos del trait GS1).
     *
     * @param  mixed                $codigo
     * @param  \App\Models\Article  $article
     * @return array
     */
    protected function evaluar_codigo($codigo, Article $article)
    {
        $crudo = trim((string) $codigo);

        // Mismo criterio de normalización que la búsqueda por código del asistente: debajo de las
        // barras los dígitos vienen agrupados ("7 791234 567898") y así los tipea la gente.
        $normalizado = (string) preg_replace('/[\s\-\.]+/', '', $this->normalize_bar_code($crudo));

        // Regla 1a: sin código no hay nada que buscar.
        if ($normalizado === '') {
            return $this->no_real($normalizado, 'El artículo no tiene código de barras cargado.');
        }

        // Regla 1b: un código de barras de producto (GTIN) son solo números. "ABC-123" es un
        // código interno o del proveedor.
        if (!preg_match('/^\d+$/', $normalizado)) {
            return $this->no_real($normalizado, 'El código '.$crudo.' tiene letras o símbolos: no es un código de barras de producto, que son solo números.');
        }

        // Regla 2: el número interno del artículo. BarCodeAutomaticoHelper le pone el id como código
        // a los artículos sin código (extensión codigos_de_barra_por_defecto) y
        // ArticleVariantGeneratorHelper usa '0'.id: se compara sin los ceros de adelante, que cubre
        // el id pelado, '0'.id y cualquier relleno con ceros del id.
        if ((int) $article->id > 0 && ltrim($normalizado, '0') === (string) (int) $article->id) {
            return $this->no_real($normalizado, 'El código '.$normalizado.' es el número interno del artículo, no un código de barras real.');
        }

        // Regla 4a: todos los dígitos iguales (0000000000000, 4444444444444). Varios pasan el dígito
        // verificador y son el relleno más común después del id.
        if (strlen($normalizado) >= self::LARGO_MINIMO_PARA_PATRONES && preg_match('/^(\d)\1+$/', $normalizado)) {
            return $this->no_real($normalizado, 'El código '.$normalizado.' repite siempre el mismo número: es un código de relleno, no uno real.');
        }

        // Regla 4b: una escalera (1234567890128, 0123456789012, 9876543210...). Se mira el código
        // entero y también sin el último dígito, porque el verificador rompe la escalera al final.
        if ($this->es_escalera($normalizado)) {
            return $this->no_real($normalizado, 'El código '.$normalizado.' es una escalera de números (1, 2, 3...): es un código inventado, no uno real.');
        }

        // Regla 1c: largo de un GTIN (EAN-8, UPC-A, EAN-13, GTIN-14).
        $largo = strlen($normalizado);

        if (!in_array($largo, [8, 12, 13, 14], true)) {
            return $this->no_real($normalizado, 'El código '.$normalizado.' tiene '.$largo.' dígitos; un código de barras de producto tiene 8, 12, 13 o 14.');
        }

        // Regla 1d: dígito verificador GS1 (el mismo validador de la búsqueda automática de siempre).
        if (!$this->is_valid_product_bar_code($normalizado)) {
            return $this->no_real($normalizado, 'El último dígito del código '.$normalizado.' no cierra la cuenta de control de los códigos de barras: está mal cargado o es inventado.');
        }

        // Regla 3: prefijos de circulación restringida (códigos que asigna cada comercio o que
        // identifican cupones), que ningún fabricante usa para un producto.
        $motivo_prefijo = $this->motivo_por_prefijo($normalizado);

        if (!is_null($motivo_prefijo)) {
            return $this->no_real($normalizado, $motivo_prefijo);
        }

        // Regla 5: el mismo código en 3 o más artículos activos del dueño es un placeholder copiado
        // ("7790000000000 a todo lo que no tiene código"): buscarlo trae la foto de un producto que
        // no es ninguno de ellos.
        $repetidos = $this->articulos_con_el_codigo($crudo, $normalizado, $article);

        if ($repetidos >= self::MINIMO_DE_ARTICULOS_PARA_PLACEHOLDER) {
            return $this->no_real($normalizado, 'El código '.$normalizado.' lo tienen '.$repetidos.' artículos del comercio: es un código copiado de relleno, no el de este producto.');
        }

        return [
            'real'        => true,
            'normalizado' => $normalizado,
            'motivo'      => null,
        ];
    }

    /**
     * Motivo si el código es de circulación restringida por su prefijo GS1; null si no.
     *
     * Para comparar prefijos el código se lleva a 13 dígitos: un UPC-A (12) es un EAN-13 con un 0
     * adelante, y un GTIN-14 es el GTIN-13 del producto con un dígito indicador de empaque adelante
     * (el verificador del final cambia, pero los prefijos no). El GTIN-8 va aparte: sus prefijos
     * reservados son otros.
     *
     * @param  string $normalizado  Solo dígitos, largo 8/12/13/14, verificador ya validado.
     * @return string|null
     */
    protected function motivo_por_prefijo($normalizado)
    {
        $largo = strlen($normalizado);

        if ($largo === 8) {
            // GS1: los EAN-8 que empiezan con 0 o con 2 son "Restricted Circulation Numbers": los
            // asigna cada comercio para uso interno, no identifican un producto de fábrica.
            $primero = substr($normalizado, 0, 1);

            if ($primero === '0' || $primero === '2') {
                return 'El código '.$normalizado.' es un código corto de uso interno de un comercio (los de 8 dígitos que empiezan con '.$primero.'), no el de fábrica.';
            }

            return null;
        }

        if ($largo === 14) {
            // Indicador 9 = artículo de medida variable (se vende por peso o por largo): el código
            // cambia con cada pesada y no identifica una foto de producto.
            if (substr($normalizado, 0, 1) === '9') {
                return 'El código '.$normalizado.' es de un producto de peso o medida variable (empieza con 9): no identifica un producto de fábrica.';
            }

            $trece = substr($normalizado, 1);
        } elseif ($largo === 12) {
            $trece = '0'.$normalizado;
        } else {
            $trece = $normalizado;
        }

        // Siete ceros adelante: un número interno completado con ceros hasta 13 dígitos. GS1 reserva
        // ese rango para "envolver" EAN-8 y en los comercios casi siempre es un relleno.
        if (substr($trece, 0, 7) === '0000000') {
            return 'El código '.$normalizado.' empieza con siete ceros: es un número interno completado con ceros, no un código de fábrica.';
        }

        // 02 y 2x: circulación restringida. En Argentina son los códigos de balanza (fiambres,
        // carnes, verdura) y los que genera cada comercio: cambian con el peso y no son de fábrica.
        if (substr($trece, 0, 2) === '02' || substr($trece, 0, 1) === '2') {
            return 'El código '.$normalizado.' es de circulación interna (balanza o uso del propio comercio): no es el código de fábrica del producto.';
        }

        // 04: UPC de uso interno de un comercio.
        if (substr($trece, 0, 2) === '04') {
            return 'El código '.$normalizado.' es de uso interno de un comercio (prefijo 04): no es el código de fábrica del producto.';
        }

        // 05 (cupones de GS1 EE.UU.), 98 (recibos de devolución y cupones) y 99 (cupones).
        if (substr($trece, 0, 2) === '05' || substr($trece, 0, 2) === '98' || substr($trece, 0, 2) === '99') {
            return 'El código '.$normalizado.' es de un cupón o un recibo, no de un producto.';
        }

        return null;
    }

    /**
     * ¿Es una escalera de dígitos consecutivos (subiendo o bajando, dando la vuelta del 9 al 0)?
     * Se mira el código entero y el código sin su último dígito (el verificador suele romperla).
     *
     * @param  string $digitos
     * @return bool
     */
    protected function es_escalera($digitos)
    {
        foreach ([$digitos, substr($digitos, 0, -1)] as $tramo) {
            $largo = strlen($tramo);

            if ($largo < self::LARGO_MINIMO_PARA_PATRONES) {
                continue;
            }

            $sube = true;
            $baja = true;

            for ($i = 1; $i < $largo; $i++) {
                $anterior = (int) $tramo[$i - 1];
                $actual   = (int) $tramo[$i];

                if ($actual !== ($anterior + 1) % 10) {
                    $sube = false;
                }

                if ($actual !== ($anterior + 9) % 10) {
                    $baja = false;
                }
            }

            if ($sube || $baja) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuántos artículos ACTIVOS del dueño (sin borrar) tienen este mismo código, contando al propio.
     * Se busca por el valor tal como está cargado y por el normalizado: el mismo relleno puede
     * estar escrito con y sin espacios.
     *
     * @param  string               $crudo
     * @param  string               $normalizado
     * @param  \App\Models\Article  $article
     * @return int
     */
    protected function articulos_con_el_codigo($crudo, $normalizado, Article $article)
    {
        if (is_null($article->user_id)) {
            return 0;
        }

        return (int) Article::where('user_id', (int) $article->user_id)
            ->where('status', 'active')
            ->whereIn('bar_code', array_values(array_unique([$crudo, $normalizado])))
            ->count();
    }

    /**
     * Resultado de un código que NO es real.
     *
     * @param  string $normalizado
     * @param  string $motivo
     * @return array
     */
    protected function no_real($normalizado, $motivo)
    {
        return [
            'real'        => false,
            'normalizado' => (string) $normalizado,
            'motivo'      => $motivo,
        ];
    }
}
