<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cabecera de una corrida del motor de ofertas personalizadas por cliente
 * (ver App\Services\OfertasClientes\OfertaSugeridaService). Mismo rol que
 * PurchaseSuggestion cumple para las sugerencias de compra a proveedores,
 * adaptado al dominio de marketing.
 *
 * Estados (status):
 * - 'pendiente': la corrida está en curso (chunks sin terminar).
 * - 'terminado': todas las líneas calculadas y priorizadas; usable en la vista.
 * - 'error': la corrida falló (ver error_mensaje). Sin este estado, una corrida
 *   caída quedaba 'pendiente' para siempre.
 *
 * origen_generacion: 'manual' (la pidió el usuario desde la pantalla) o
 * 'automatica' (la creó ofertas:generar según users.ofertas_periodicidad).
 *
 * resumen_ia_estado: null (no se pidió — p.ej. sin ANTHROPIC_API_KEY),
 * 'pendiente', 'listo' o 'error' (ver resumen_ia_error). Una falla del resumen
 * NUNCA cambia el status de la sugerencia: son dos contratos independientes.
 *
 * total_clientes / total_lineas / total_clientes_excluidos_por_deuda son
 * informativos y se llenan al cerrar. El tercero cuenta a los que quedaron
 * afuera por tener ventas sin cobrar: al mal pagador no se le ofrece nada
 * (decisión de Lucas del 15/8/2026), y el número es lo que le muestra al
 * comerciante que el sistema tuvo criterio.
 *
 * exclusiones_por_motivo es la otra mitad de esa misma pregunta, del lado de los
 * ARTÍCULOS: mapa motivo => cantidad de líneas candidatas que el techo descartó
 * (sin costo cargado, sin precio, margen no positivo, etc.). Informativo, se
 * llena al calcular cada lote. Es la diferencia entre "no encontré nada" y
 * "miré 800 artículos y 200 no tienen el costo cargado".
 *
 * El detalle columna por columna está en el docblock de la migración
 * 2026_08_17_100000_create_offer_suggestions_table.php.
 */
class OfferSuggestion extends Model
{
    protected $guarded = [];

    /**
     * 🔴 exclusiones_por_motivo viaja como ARRAY, no como string JSON. La columna es text (ver la
     * migración 2026_08_17_100500) y el cast es lo que hace que la SPA reciba un objeto en el JSON de
     * show()/index() y no una cadena que tendría que parsear a mano en la vista. Sin el cast, el
     * v-for del detalle recorre los caracteres del string.
     */
    protected $casts = [
        'exclusiones_por_motivo' => 'array',
    ];

    /**
     * 🔴 El desglose YA EXPLICADO viaja en la respuesta de todos los endpoints que serializan la
     * corrida. Se appendea acá y no se arma en la vista porque el texto de cada motivo tiene que
     * salir de UN solo lugar (OfertaSugeridaService::texto_de_exclusion()): el mismo que lee la
     * pantalla del detalle es el que lee la IA en su bloque de datos, y dos redacciones distintas del
     * mismo motivo terminan siendo dos explicaciones distintas del mismo número. No hace ninguna
     * consulta: lee la columna que ya vino con el modelo.
     */
    protected $appends = ['exclusiones_explicadas'];

    /**
     * Los motivos de exclusión de artículos, ya en criollo y ordenados de mayor a menor: el que más
     * pesa es el primero que el comerciante tiene que ir a arreglar.
     *
     * @return array Lista de ['motivo' => string, 'texto' => string, 'cantidad' => int]
     */
    public function getExclusionesExplicadasAttribute()
    {
        $exclusiones = $this->exclusiones_por_motivo;

        if (!is_array($exclusiones) || empty($exclusiones)) {
            return [];
        }

        arsort($exclusiones);
        $explicadas = [];

        foreach ($exclusiones as $motivo => $cantidad) {
            $explicadas[] = [
                'motivo'   => $motivo,
                'texto'    => \App\Services\OfertasClientes\OfertaSugeridaService::texto_de_exclusion($motivo),
                'cantidad' => (int) $cantidad,
            ];
        }

        return $explicadas;
    }

    function scopeWithAll($q) {
        // withCount y no with: el listado necesita la columna "líneas" (una
        // subquery), no las miles de filas de cada corrida.
        $q->withCount('lines');
        return $q;
    }

    /**
     * Las sugerencias concretas (cliente × artículo) de esta corrida.
     */
    public function lines()
    {
        return $this->hasMany(OfferSuggestionLine::class);
    }
}
