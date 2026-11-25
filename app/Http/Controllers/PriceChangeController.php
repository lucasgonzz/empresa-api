<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\PriceChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PriceChangeController extends Controller
{

    function index($article_id) {
        $owner_id = UserHelper::userId();
        $article = \App\Models\Article::where('id', $article_id)->where('user_id', $owner_id)->firstOrFail();
        $usuario = UserHelper::user(false);
        $ver_costo = \App\Http\Controllers\Helpers\ProviderPriceOfferHelper::can_see_cost($usuario);
        $models = PriceChange::where('article_id', $article_id)
                            ->orderBy('created_at', 'DESC')
                            ->orderBy('id', 'DESC')
                            ->withAll()
                            ->get();
        $nombres = \App\Models\User::whereIn('id', $models->pluck('employee_id')->filter()->unique()->all())
            ->where(function ($q) use ($owner_id) {
                $q->where('id', $owner_id)->orWhere('owner_id', $owner_id);
            })->pluck('name', 'id');
        // Misión historial-precios-proveedor: la oferta de proveedor que originó cada cambio (UNA consulta).
        $ofertas_por_cambio = \App\Http\Controllers\Helpers\ProviderPriceOfferHelper::offers_by_price_change(
            $owner_id,
            $models->pluck('id')->all(),
            $ver_costo
        );
        // Si el negocio (dueño) trabaja con listas de precio. Se resuelve UNA vez, fuera del map,
        // para no consultar al dueño por cada fila del historial.
        $usa_listas = UserHelper::uses_listas_de_precio($usuario);
        $salida = $models->map(function ($model) use ($ver_costo, $nombres, $ofertas_por_cambio, $usa_listas) {
            $dato = $model->toArray();
            // Siempre un array (vacío si el cambio no vino de ninguna oferta).
            $dato['provider_offers'] = isset($ofertas_por_cambio[$model->id]) ? $ofertas_por_cambio[$model->id] : [];
            $dato['empleado_nombre'] = $nombres[$model->employee_id] ?? null;
            if (!$ver_costo) {
                // cost y price se ocultan siempre a quien no ve costos.
                unset($dato['cost'], $dato['price']);
                // En cuentas CON listas el precio general puede ser el costo sin margen, así que
                // final_price y precio_final_anterior también se ocultan: los precios públicos
                // están en price_types/cambios_listas. En cuentas SIN listas final_price es el
                // precio público de siempre (lo muestra la columna «Precio Final» de la SPA) y
                // se devuelve tal cual.
                if ($usa_listas) {
                    unset($dato['final_price'], $dato['precio_final_anterior']);
                }
                // Compatibilidad con la SPA anterior: una única lista Precio final
                // tiene una foto pública propia, independiente del costo general.
                $listas = $dato['price_types'] ?? [];
                if ($usa_listas && count($listas) === 1 && !empty($listas[0]['es_precio_final'])) {
                    $dato['final_price'] = $listas[0]['pivot']['final_price'];
                }
            }
            return $dato;
        });
        return response()->json(['models' => $salida], 200);
    }

    static function store($article, $auth_user_id = null) {

        if (\App\Services\HistorialPreciosService::activo()) {
            return;
        }

        if (is_null($auth_user_id)) {
            $auth_user_id = UserHelper::userId(false);
        }

        /*
         * Modo lote de la importación (PreciosEnLote, misión importacion-excel-motor-rapido,
         * 24/9/2026): se registra el cambio y volcar() inserta price_changes y
         * price_change_price_type en bloque al final del lote, con las mismas columnas y valores
         * que el create() + attach() de abajo. Fuera del modo lote, todo sigue igual.
         */
        if (PreciosEnLote::esta_activo()) {
            PreciosEnLote::registrar_cambio_de_precio($article, $auth_user_id);
            return;
        }

        $price_change = PriceChange::create([
            'article_id'    => $article->id,
            'cost'          => $article->cost,
            'price'         => $article->price,
            'final_price'   => $article->final_price,
            'employee_id'   => $auth_user_id,
        ]);

        if (count($article->price_types) >= 1) {

            foreach ($article->price_types as $article_price_type) {

                $price_change->price_types()->attach($article_price_type->id, [
                    'final_price'   => $article_price_type->pivot->final_price,
                ]);
            }
        }

    }
}
