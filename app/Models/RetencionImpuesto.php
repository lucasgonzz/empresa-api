<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un impuesto de retención que el comercio dio de alta por su cuenta (misión
 * retenciones-abm-impuestos, 8/10/2026), además de los tres de siempre.
 *
 * 🔴 LOS TRES DE SIEMPRE NO ESTÁN EN ESTA TABLA. Ganancias, IVA e Ingresos Brutos siguen siendo las
 * constantes de `RetencionSufrida::IMPUESTOS`: la Posición Fiscal los resta contra su saldo y eso
 * no puede depender de que una fila exista en la base de cada comercio. Los de esta tabla son
 * INFORMATIVOS: tienen su renglón propio en la Posición Fiscal y no restan contra nada.
 *
 * El certificado referencia al impuesto por texto: `retenciones_sufridas.impuesto = 'imp_<id>'`.
 * Se resuelve por id, así que renombrar un impuesto no rompe los certificados ya cargados.
 */
class RetencionImpuesto extends Model
{
    protected $table = 'retencion_impuestos';

    protected $guarded = [];

    /** Prefijo con el que el impuesto se guarda en `retenciones_sufridas.impuesto`. */
    const PREFIJO = 'imp_';

    /**
     * Nombres con los que el comercio conoce a los tres impuestos de siempre, ya normalizados por
     * normalizar_nombre(): un impuesto nuevo no puede llamarse igual que ninguno, porque en el
     * selector del cobro se verían dos "Ganancias" y no habría forma de saber cuál es cuál.
     *
     * @var array<int,string>
     */
    const NOMBRES_RESERVADOS = ['ganancias', 'iva', 'iibb', 'ingresosbrutos'];

    function scopeWithAll($q) {

    }

    /**
     * El valor con el que un impuesto nuevo viaja en el cobro y se guarda en el certificado.
     *
     * @param  int $id
     * @return string
     */
    static function clave($id) {

        return self::PREFIJO.((int) $id);
    }

    /**
     * El id de un impuesto nuevo a partir de su clave (`imp_7` → 7). 0 si lo que llegó no es una
     * clave de impuesto nuevo. Estricto: solo `imp_` y dígitos, sin espacios ni signos.
     *
     * @param  mixed $clave
     * @return int
     */
    static function id_de_clave($clave) {

        if (!is_string($clave)) {

            return 0;
        }

        if (preg_match('/\Aimp_([0-9]+)\z/', strtolower(trim($clave)), $coincidencias) !== 1) {

            return 0;
        }

        return (int) $coincidencias[1];
    }

    /**
     * Un nombre en la forma en que se compara: sin mayúsculas, sin ningún espacio y sin tildes.
     * "Ingresos  Brutos" y "ingresos brutos" son el mismo nombre; "Retención" y "retencion" también.
     *
     * @param  string|null $nombre
     * @return string
     */
    static function normalizar_nombre($nombre) {

        $nombre = mb_strtolower((string) $nombre, 'UTF-8');

        $nombre = strtr($nombre, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);

        return preg_replace('/\s+/u', '', $nombre);
    }
}
