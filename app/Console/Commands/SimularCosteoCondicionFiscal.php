<?php

namespace App\Console\Commands;

use App\Exports\SimulacionCosteoExport;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DesglosePrecioHelper;
use App\Models\Article;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Comando de SOLO LECTURA (grupo 231, prompt 04): simula como quedarian el costo real y el
 * precio final de los articulos de una cuenta si se activara la dinamica contable real
 * (`usar_condicion_fiscal_en_costeo = 1`), sin escribir absolutamente nada en la base.
 *
 * Genera un Excel de 3 hojas (Resumen, Detalle, Ejemplos) en storage/app/simulaciones/ para que
 * Lucas se lo pueda mandar al cliente antes de migrarlo a la nueva dinamica.
 *
 * IMPORTANTE: todo el recorrido corre dentro de una transaccion con rollback en un `finally`.
 * `ArticlePricesHelper::aplicar_precios_segun_listas_de_precios()` sigue escribiendo los pivots
 * de `price_types` con syncWithoutDetaching()/updateExistingPivot() sin mirar `$guardar_cambios`
 * (ver docblock de `ArticleHelper::setFinalPrice`), asi que sin la transaccion este comando "de
 * solo lectura" le dejaria los tipos de precio recalculados a una cuenta que sigue en legacy.
 */
class SimularCosteoCondicionFiscal extends Command
{
    /**
     * Nombre y firma del comando de consola.
     * `{user_id}`: cuenta a simular. `{--ejemplos=3}`: cantidad de articulos con calculo paso a
     * paso detallado en la hoja "Ejemplos" (default 3).
     *
     * @var string
     */
    protected $signature = 'costeo:simular {user_id} {--ejemplos=3}';

    /**
     * Descripcion del comando: aclara explicitamente que es de solo lectura.
     *
     * @var string
     */
    protected $description = 'Simula (SOLO LECTURA, no modifica nada) como quedarian el costo real y el precio final de los articulos de una cuenta si se activara la dinamica de costeo por condicion fiscal, y genera un Excel comparativo.';

    /**
     * Ejecuta la simulacion.
     *
     * @return int
     */
    public function handle()
    {
        // Cuenta a simular, tomada del argumento obligatorio.
        $user_id = $this->argument('user_id');

        // Cantidad de articulos que se van a detallar paso a paso en la hoja "Ejemplos".
        $cantidad_ejemplos = (int) $this->option('ejemplos');
        if ($cantidad_ejemplos < 0) {
            $cantidad_ejemplos = 0;
        }

        $user = User::find($user_id);

        if (is_null($user)) {
            $this->error('No existe ningun usuario con id '.$user_id);
            return 1;
        }

        // Si la cuenta ya esta migrada no tiene sentido simular lo que ya es la dinamica actual.
        if ($user->usar_condicion_fiscal_en_costeo) {
            $this->info('La cuenta '.$user->name.' (id '.$user->id.') ya esta migrada a la dinamica de costeo por condicion fiscal. No se genera ninguna simulacion.');
            return 0;
        }

        // Articulos de la cuenta con las relaciones que necesita el pipeline de precios
        // (mismo patron de recorrido que set_articles_prices.php).
        $articles = Article::where('user_id', $user->id)
                            ->with('iva')
                            ->with('provider')
                            ->with('article_discounts')
                            ->with('article_surchages')
                            ->with('price_types')
                            ->get();

        $this->info(count($articles).' articulos a simular para '.$user->name.' (id '.$user->id.')');

        // Estado ACTUAL de cada articulo, capturado tal como esta en la base, sin recalcular nada
        // (paso 4 de la especificacion). Sirve de punto de comparacion para el Excel.
        $estado_actual = [];
        foreach ($articles as $article) {
            $estado_actual[$article->id] = [
                'costo_real'    => $article->costo_real,
                'final_price'   => $article->final_price,
            ];
        }

        // Filas ya calculadas para la hoja "Detalle" (una por articulo).
        $detalle = [];

        // Filas ya calculadas para la hoja "Ejemplos" (calculo paso a paso, dos dinamicas).
        $ejemplos = [];

        // Descripciones paso a paso de la dinamica ACTUAL (legacy) para los articulos de muestra,
        // indexadas por id de articulo. Se llenan en el primer recorrido (antes de flippear la
        // bandera) y se leen en el segundo (ver comentario en el primer recorrido).
        $des_actual_por_articulo = [];

        DB::beginTransaction();

        try {

            $index = 0;
            foreach ($articles as $article) {

                $es_ejemplo = $index < $cantidad_ejemplos;

                // Para los articulos de muestra, se captura ADEMAS el calculo paso a paso de la
                // dinamica ACTUAL (todavia legacy, antes de tocar la bandera en memoria). Se hace
                // solo para los N de muestra: recalcular la descripcion completa de todos los
                // articulos seria un costo innecesario para lo que es solo un adjunto ilustrativo.
                // Se guarda en un array indexado por id de articulo (no en una variable suelta):
                // el segundo recorrido de mas abajo necesita poder recuperar esta descripcion
                // puntual por articulo, no la del ultimo item de ESTE bucle.
                if ($es_ejemplo) {
                    // solo_textos(): desde el 21/8/2026 setFinalPrice() devuelve el desglose como
                    // entradas estructuradas, y esto termina en una celda de Excel. Sin esto, el
                    // value binder de Laravel Excel json_encodea el array y la columna "Paso a paso"
                    // queda con {"tipo":"iva","clave":null,...} en vez del renglon.
                    $des_actual_por_articulo[$article->id] = DesglosePrecioHelper::solo_textos(
                        ArticleHelper::setFinalPrice($article, $user->id, $user, null, true, null, true)
                    );
                }

                $index++;
            }

            // Se activa la bandera SOLO en el objeto en memoria (nunca con save()): a partir de
            // aca, cualquier llamado a setFinalPrice() con este $user calcula con la dinamica
            // contable real (ver ArticlePricesHelper::iva_va_al_costo).
            $user->usar_condicion_fiscal_en_costeo = 1;

            $index = 0;
            foreach ($articles as $article) {

                $es_ejemplo = $index < $cantidad_ejemplos;

                // Costo base (no depende de la dinamica de costeo, es el dato tipeado/de compra).
                $costo_base = $article->cost;

                // Valores de la dinamica NUEVA (condicion fiscal real).
                ArticleHelper::setFinalPrice($article, $user->id, $user);

                $costo_real_nuevo  = $article->costo_real;
                $final_price_nuevo = $article->final_price;

                $costo_real_actual  = $estado_actual[$article->id]['costo_real'];
                $final_price_actual = $estado_actual[$article->id]['final_price'];

                $detalle[] = [
                    'codigo'                => $article->sku,
                    'nombre'                => $article->name,
                    'costo_base'            => $costo_base,
                    'costo_real_actual'     => $costo_real_actual,
                    'costo_real_nuevo'      => $costo_real_nuevo,
                    'variacion_costo'       => $this->variacion_porcentual($costo_real_actual, $costo_real_nuevo),
                    'final_price_actual'    => $final_price_actual,
                    'final_price_nuevo'     => $final_price_nuevo,
                    'variacion_precio'      => $this->variacion_porcentual($final_price_actual, $final_price_nuevo),
                ];

                if ($es_ejemplo) {

                    // Calculo paso a paso de la dinamica NUEVA, ya con la bandera activada en memoria.
                    // Mismo motivo que arriba: esto va a una celda de Excel, no a la pantalla.
                    $des_nuevo = DesglosePrecioHelper::solo_textos(
                        ArticleHelper::setFinalPrice($article, $user->id, $user, null, true, null, true)
                    );

                    $des_actual = isset($des_actual_por_articulo[$article->id]) ? $des_actual_por_articulo[$article->id] : null;

                    $ejemplos[] = [
                        'articulo'      => $article->name,
                        'des_actual'    => is_array($des_actual) ? $des_actual : [],
                        'des_nuevo'     => is_array($des_nuevo) ? $des_nuevo : [],
                    ];
                }

                $index++;
            }

        } finally {

            // El rollback tiene que correr siempre, aunque una excepcion corte el recorrido a
            // mitad de camino: este comando es de solo lectura y no puede dejar nada escrito.
            DB::rollBack();
        }

        // Arma las estadisticas de la hoja "Resumen" a partir de lo ya calculado en "Detalle".
        $resumen = $this->armar_resumen($user, $detalle);

        $datos = [
            'resumen'   => $resumen,
            'detalle'   => $detalle,
            'ejemplos'  => $ejemplos,
        ];

        // Ruta destino del Excel: storage/app/simulaciones/costeo_{user_id}_{fecha}.xlsx.
        $nombre_archivo = 'costeo_'.$user->id.'_'.Carbon::now()->format('d-m-Y_H-i-s').'.xlsx';
        $ruta_relativa  = 'simulaciones/'.$nombre_archivo;

        Excel::store(new SimulacionCosteoExport($datos), $ruta_relativa);

        $this->info('Simulacion generada en storage/app/'.$ruta_relativa);

        return 0;
    }

    /**
     * Calcula la variacion porcentual entre un valor actual y uno nuevo.
     * Devuelve null si el valor actual es null o 0 (no hay base valida sobre la que calcular un
     * porcentaje), para no dividir por cero.
     *
     * @param float|null $actual
     * @param float|null $nuevo
     * @return float|null
     */
    protected function variacion_porcentual($actual, $nuevo) {
        if (is_null($actual) || (float) $actual == 0) {
            return null;
        }
        if (is_null($nuevo)) {
            return null;
        }
        return (((float) $nuevo - (float) $actual) / (float) $actual) * 100;
    }

    /**
     * Arma el array de estadisticas para la hoja "Resumen" a partir de las filas ya calculadas de
     * "Detalle". Separa a proposito "cambia el costo real" de "cambia el precio final": son dos
     * conversaciones distintas con el cliente.
     *
     * @param \App\Models\User $user
     * @param array $detalle
     * @return array
     */
    protected function armar_resumen($user, $detalle) {

        // Umbral (en pesos) para considerar que un valor "cambio" de verdad, y no es solo ruido de
        // redondeo de centavos.
        $epsilon = 0.01;

        $cantidad_articulos = count($detalle);

        $cambian_costo          = 0;
        $variaciones_costo      = [];

        $cambian_precio         = 0;
        $variaciones_precio     = [];
        $no_cambian_precio      = 0;

        foreach ($detalle as $fila) {

            $diff_costo = abs((float) $fila['costo_real_nuevo'] - (float) $fila['costo_real_actual']);
            if ($diff_costo > $epsilon && !is_null($fila['variacion_costo'])) {
                $cambian_costo++;
                $variaciones_costo[] = abs($fila['variacion_costo']);
            }

            $diff_precio = abs((float) $fila['final_price_nuevo'] - (float) $fila['final_price_actual']);
            if ($diff_precio > $epsilon && !is_null($fila['variacion_precio'])) {
                $cambian_precio++;
                $variaciones_precio[] = abs($fila['variacion_precio']);
            } else {
                $no_cambian_precio++;
            }
        }

        return [
            'nombre_cuenta'                     => $user->name,
            'condicion_fiscal'                  => $user->es_monotributista() ? 'Monotributista' : 'Responsable Inscripto',
            'cantidad_articulos'                => $cantidad_articulos,
            'cambian_costo'                      => $cambian_costo,
            'variacion_promedio_costo'          => count($variaciones_costo) ? array_sum($variaciones_costo) / count($variaciones_costo) : 0,
            'variacion_maxima_costo'             => count($variaciones_costo) ? max($variaciones_costo) : 0,
            'cambian_precio'                     => $cambian_precio,
            'variacion_promedio_precio'         => count($variaciones_precio) ? array_sum($variaciones_precio) / count($variaciones_precio) : 0,
            'variacion_maxima_precio'            => count($variaciones_precio) ? max($variaciones_precio) : 0,
            'no_cambian_precio'                  => $no_cambian_precio,
        ];
    }
}
