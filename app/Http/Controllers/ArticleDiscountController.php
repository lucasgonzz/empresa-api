<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\ArticleDiscount;
use Illuminate\Http\Request;

class ArticleDiscountController extends Controller
{

    public function index() {
        $models = ArticleDiscount::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticleDiscount::create([
            'article_id'            => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'percentage'            => $request->percentage,
            'amount'                => $request->amount,
            'show_in_online'        => $request->show_in_online,
            // Naturaleza contable del descuento (Prompt 260). Nullable, sin UI todavía.
            'tipo'                  => $request->tipo,
            // Quién lo creó: este endpoint es el formulario de la ficha del artículo, así que
            // siempre lo cargó una persona. Ninguna propagación toca un descuento manual.
            'origen'                => ArticleDiscount::ORIGEN_MANUAL,
        ]);
        if (!is_null($request->model_id)) {
            ArticleHelper::setFinalPrice($model->article);
            $this->sendAddModelNotification('article', $model->article_id, false);
        }
        return response()->json(['model' => $this->fullModel('ArticleDiscount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticleDiscount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticleDiscount::find($id);

        /**
         * Mision descuentos-proveedor-propagar (4/9/2026): si una persona le cambia el porcentaje a
         * un descuento que habia puesto el sistema copiandolo del proveedor (o sea, uno tagueado con
         * `provider_id`), queda marcado como editado a mano.
         *
         * 🔴 La marca decide si una propagacion posterior lo pisa o lo respeta. Se pone ACA y no se
         * deduce despues comparando numeros: este es el unico momento en que se sabe con certeza
         * que la mano fue de una persona. Deducirlo por comparacion no funciona —lo demostro un test
         * en rojo— porque al borrar un descuento del proveedor su porcentaje desaparece de toda
         * referencia y los articulos que lo tenian copiado pasarian por editados.
         *
         * Solo cuenta si el porcentaje REALMENTE cambia: guardar el formulario sin tocar el numero
         * no convierte en "editado a mano" un descuento que el sistema sigue gobernando.
         *
         * Los descuentos manuales del usuario (`provider_id` null) no necesitan la marca: ninguna
         * propagacion los toca nunca.
         */
        if (!is_null($model->provider_id)
            && (
                ArticleProviderDiscountHelper::normalizar_porcentaje($model->percentage)
                    !== ArticleProviderDiscountHelper::normalizar_porcentaje($request->percentage)
                /*
                 * 🔴 El MONTO tambien cuenta como edicion, y mirarlo no es simetria decorativa.
                 * El formulario expone Porcentaje y Monto como dos inputs independientes: alguien
                 * puede agregarle un monto a un descuento SIN tocar el porcentaje. Mirando solo el
                 * porcentaje esa edicion no quedaba marcada, y la propagacion siguiente borraba el
                 * monto tipeado a mano sin contarlo entre los editados ni ofrecer el tilde.
                 *
                 * Ese monto era inerte en el precio —`aplicar_descuentos` usa el porcentaje si lo
                 * hay y solo resta el monto cuando no hay porcentaje—, asi que no movia plata; pero
                 * era un dato de una persona y se perdia en silencio.
                 */
                || ArticleProviderDiscountHelper::normalizar_porcentaje($model->amount)
                    !== ArticleProviderDiscountHelper::normalizar_porcentaje($request->amount)
            )) {
            $model->editado_a_mano = 1;
        }

        $model->percentage                = $request->percentage;
        $model->amount                    = $request->amount;
        $model->show_in_online            = $request->show_in_online;
        // Naturaleza contable del descuento (Prompt 260). Sin UI todavía: solo se pisa si el
        // request lo manda explícitamente, para no perder el backfill 'otro' en updates existentes.
        if (!is_null($request->tipo)) {
            $model->tipo = $request->tipo;
        }
        $model->save();
        ArticleHelper::setFinalPrice($model->article);
        $this->sendAddModelNotification('article', $model->article_id, false);
        return response()->json(['model' => $this->fullModel('ArticleDiscount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticleDiscount::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ArticleDiscount', $model->id);
        return response(null);
    }
}
