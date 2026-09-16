<?php

namespace App\Http\Controllers\Helpers\combo;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\Article;
use App\Models\Combo;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un combo, extraída de ComboController::store() en la misión agente-ia-mano-derecha
 * (16/9/2026) para que el asistente de IA pueda crear uno sin pasar por el controller.
 *
 * Es el mismo movimiento que ya se hizo con ExpenseHelper::crear() y
 * CurrentAcountPagoAltaHelper::registrar(), y por el mismo motivo: que no haya dos caminos para la
 * misma alta. Recibe un ARRAY y no un Request (ver el docblock de ExpenseHelper::crear()): lo que el
 * alta necesita queda a la vista en la firma en vez de escondido adentro.
 *
 * 🔴 CREAR Y VALIDAR SON DOS MÉTODOS SEPARADOS, Y NO ES UN DESCUIDO. `ComboController::store()` no
 * validaba NADA: hoy la pantalla acepta un combo sin artículos, con cantidades en cero o con el
 * precio vacío, y eso está en producción. Si `crear()` empezara a rechazarlo, la extracción cambiaría
 * el comportamiento observable de `POST api/combo`, que es justo lo que una extracción no puede
 * hacer. Entonces: `crear()` escribe exactamente lo que escribía el controller, y `validar()` —las
 * reglas que un combo dictado en palabras SÍ necesita— la llama el asistente antes de proponer y
 * antes de confirmar. La pantalla queda igual; el camino nuevo, protegido.
 */
class ComboAltaHelper {

    /**
     * Valida un combo dictado al asistente: al menos un artículo, cantidades enteras mayores a 0 y
     * todos los artículos del dueño.
     *
     * 🔴 Las cantidades van en `article_combo.amount`, que es un INTEGER no nulo
     * (2022_06_13_100710_create_article_combo_table.php:20). Una cantidad fraccionaria se guardaría
     * truncada sin que nada avise, así que se rechaza acá en vez de dejar un combo que dice otra cosa
     * que lo que la persona pidió.
     *
     * @param  array  $data  El mismo array que recibe crear().
     * @param  int  $user_id  Dueño de la cuenta.
     * @return string|null  El motivo, o null si está bien.
     */
    static function validar(array $data, $user_id) {

        $articles = isset($data['articles']) && is_array($data['articles']) ? $data['articles'] : [];

        if (!count($articles)) {

            return 'Un combo necesita al menos un artículo.';
        }

        $ids = [];

        foreach ($articles as $article) {

            $id = isset($article['id']) ? (int) $article['id'] : 0;

            if ($id <= 0) {

                return 'Cada artículo del combo tiene que ser uno del catálogo.';
            }

            $cantidad = isset($article['pivot']['amount']) ? $article['pivot']['amount'] : null;

            if (!is_numeric($cantidad) || (float) $cantidad <= 0) {

                return 'La cantidad de cada artículo del combo tiene que ser un número mayor a 0.';
            }

            if ((float) $cantidad != (int) $cantidad) {

                return 'Las cantidades de un combo van en unidades enteras.';
            }

            if (in_array($id, $ids, true)) {

                return 'Hay un artículo repetido en el combo: poné una sola vez cada uno, con su cantidad.';
            }

            $ids[] = $id;
        }

        $propios = Article::where('user_id', $user_id)->whereIn('id', $ids)->count();

        if ($propios !== count($ids)) {

            return 'Alguno de los artículos del combo no está entre los tuyos.';
        }

        return null;
    }

    /**
     * Crea el combo y le adjunta sus artículos con la cantidad de cada uno. Devuelve el Combo recién
     * creado (sin relaciones extra cargadas: el llamador decide qué necesita, ej.
     * `fullModel('Combo', $id)`).
     *
     * El `attach` va adentro de la misma transacción que el `create`: un combo sin sus artículos no
     * es un combo a medias, es un combo que al venderse no descuenta stock de nada
     * (Helpers\sale\ComboHelper::discount_articles_stock() recorre `$combo['articles']`).
     *
     * @param  array  $data  Claves: `name`, `cost`, `price`, `articles` (la lista tal como la manda
     *                       la pantalla: cada ítem con `id` y `pivot.amount`; puede ser `[]`).
     * @param  int  $user_id  Dueño de la cuenta (Controller::userId()).
     * @param  int|callable  $num  Correlativo del combo. Puede venir resuelto (int) o como callable
     *                             que se ejecuta ADENTRO de la transacción, por el mismo motivo que
     *                             en ExpenseHelper::crear(): `Controller::num()` toma lockForUpdate
     *                             y resolverlo antes de entrar liberaría el candado al toque.
     * @return \App\Models\Combo
     */
    static function crear(array $data, $user_id, $num) {

        /*
         * Una clave que no vino vale null, igual que `$request->clave` en el controller de origen: un
         * "Undefined index" acá sería un 500 nuevo donde antes había un combo con ese campo en null.
         */
        $valor = function ($clave) use ($data) {
            return array_key_exists($clave, $data) ? $data[$clave] : null;
        };

        $articles = is_array($valor('articles')) ? $valor('articles') : [];

        return DB::transaction(function () use ($valor, $user_id, $num, $articles) {

            $model = Combo::create([
                'num'     => is_callable($num) ? $num() : $num,
                'name'    => $valor('name'),
                'cost'    => $valor('cost'),
                'price'   => $valor('price'),
                'user_id' => $user_id,
            ]);

            GeneralHelper::attachModels($model, 'articles', $articles, ['amount']);

            return $model;
        });
    }
}
