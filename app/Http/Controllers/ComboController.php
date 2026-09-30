<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\combo\ComboAltaHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoEsquemaHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\Combo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComboController extends Controller
{

    public function index() {
        $models = Combo::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * 🔴 El alta en sí (el create, el attach de los artículos con su cantidad y la transacción que
     * los envuelve) vive en ComboAltaHelper::crear() desde la misión agente-ia-mano-derecha
     * (16/9/2026), porque el asistente de IA también da de alta combos y no puede pasar por acá.
     * Acá queda lo que es del HTTP: leer el request y armar la respuesta. El payload y las
     * respuestas de `POST api/combo` no cambiaron, y este camino sigue SIN validar nada — la
     * validación del helper (ComboAltaHelper::validar()) la llama el asistente, no la pantalla:
     * ver el docblock del helper. (La única excepción es el descuento del combo calculado, que se
     * rechaza con 422: ver más abajo.)
     *
     * 🔴 `online` VA EN EL only(), Y NO ES UN CAMPO MÁS (misión combos-y-rangos-de-precio,
     * 16/9/2026). Lo que el only() no nombra no llega al helper, y olvidarlo acá no rompe nada a
     * la vista: el alta responde 201, el combo queda creado, y lo único que pasa es que nace
     * apagado aunque el dueño haya tildado "Mostrar en la tienda". Se publica recién si lo reabre
     * y lo vuelve a guardar, porque update() sí lo escribe — o sea, editar anda y crear no, sin un
     * error en ningún lado y sin que ningún test se ponga rojo. Si mañana el ABM suma otra columna,
     * va en esta lista en el mismo diff.
     *
     * 🔴 LO MISMO PASA CON `calcular_desde_articulos`, `descuento_tipo` Y `descuento_valor` (misión
     * combos-calculados, 30/9/2026), y con la misma cara: un combo creado con el check "Calcular en
     * base a los artículos" tildado que nace con el check apagado y con el costo y el precio que
     * tipeó la persona, respondiendo 201. Están en el only() por ese motivo, y hay un test por cada
     * una de las dos puntas (el endpoint y el helper).
     *
     * @param Request $request name, cost, price, articles, online, calcular_desde_articulos,
     *                         descuento_tipo, descuento_valor, childrens
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {

        $rechazo = $this->rechazo_del_descuento($request->descuento_tipo, $request->descuento_valor);

        if (!is_null($rechazo)) {
            return response()->json(['message' => $rechazo], 422);
        }

        // El correlativo va como closure para que num() corra ADENTRO de la transacción del helper
        // y su lockForUpdate se sostenga hasta el commit (ver el docblock de ComboAltaHelper::crear()).
        $model = ComboAltaHelper::crear($request->only(['name', 'cost', 'price', 'articles', 'online', 'calcular_desde_articulos', 'descuento_tipo', 'descuento_valor']), $this->userId(), function () {
            return $this->num('combos');
        });

        // Las fotos que se subieron ANTES de que el combo existiera (con un temporal_id) se
        // enganchan ahora al combo recién creado. Mismo mecanismo que Article y PromocionVinoteca.
        $this->updateRelationsCreated('combo', $model->id, $request->childrens);

        return response()->json(['model' => $this->fullModel('Combo', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Combo', $id)], 200);
    }

    /**
     * 🔴 LOS CAMPOS NUEVOS SOLO SE ESCRIBEN SI LA CLAVE VIENE EN EL REQUEST (`$request->has()`), y no
     * es un detalle: un empresa-spa VIEJO (sin el check en el ABM) no manda `calcular_desde_articulos`
     * ni el descuento, y con una asignación pelada (`= $request->calcular_desde_articulos`) cada vez
     * que alguien editara el nombre de un combo calculado desde un SPA sin actualizar, el combo
     * volvería a ser manual y perdería su descuento, sin un error en ningún lado. `online` se escribe
     * siempre porque su ausencia significa "apagado" (la dirección segura); acá la ausencia significa
     * "no me toques lo que ya tengo".
     *
     * Con el combo calculado, el `cost` y el `price` del request se IGNORAN: los escribe el servidor
     * (ComboCalculadoHelper::guardar()) DESPUÉS de reemplazar los artículos, que es lo que puede
     * haber cambiado la cuenta. Un SPA viejo que reenvíe los números que vio en pantalla no los pisa.
     */
    public function update(Request $request, $id) {

        $model = Combo::find($id);

        $disponible = ComboCalculadoEsquemaHelper::disponible();

        if ($disponible && ($request->has('descuento_tipo') || $request->has('descuento_valor'))) {

            $rechazo = $this->rechazo_del_descuento(
                $request->has('descuento_tipo') ? $request->descuento_tipo : $model->descuento_tipo,
                $request->has('descuento_valor') ? $request->descuento_valor : $model->descuento_valor
            );

            if (!is_null($rechazo)) {
                return response()->json(['message' => $rechazo], 422);
            }
        }

        DB::transaction(function () use ($request, $model, $disponible) {

            $model->name                = $request->name;

            /* ¿El combo queda calculado después de este guardado? La clave del request manda; sin ella, lo que ya tenía. */
            $calculado = $disponible && (
                $request->has('calcular_desde_articulos')
                    ? (bool) $request->calcular_desde_articulos
                    : (bool) $model->calcular_desde_articulos
            );

            if (!$calculado) {
                $model->cost            = $request->cost;
                $model->price           = $request->price;
            }

            /*
                El interruptor que publica el combo en el ecommerce. Se normaliza a 1/0 y no se asigna
                pelado: `combos.online` es NOT NULL con default 0, y una asignación de null la rompe en
                MySQL estricto. Además una empresa-spa vieja (sin el check en el ABM) no manda la clave,
                y con esta forma el combo queda apagado, que es la dirección segura.

                🔴 El MISMO criterio —la misma truthiness de PHP— corre en el alta, que desde el
                refactor de agente-ia-mano-derecha vive en ComboAltaHelper::crear(). Si alguna vez hay
                que cambiarlo, se cambia en los dos lados o crear y editar empiezan a decir cosas
                distintas sobre el mismo combo.
            */
            $model->online              = $request->online ? 1 : 0;

            if ($disponible) {

                if ($request->has('calcular_desde_articulos')) {
                    $model->calcular_desde_articulos = $request->calcular_desde_articulos ? 1 : 0;
                }

                if ($request->has('descuento_tipo') || $request->has('descuento_valor')) {

                    $descuento = ComboCalculadoHelper::normalizar_descuento(
                        $request->has('descuento_tipo') ? $request->descuento_tipo : $model->descuento_tipo,
                        $request->has('descuento_valor') ? $request->descuento_valor : $model->descuento_valor
                    );

                    $model->descuento_tipo  = $descuento['descuento_tipo'];
                    $model->descuento_valor = $descuento['descuento_valor'];
                }
            }

            $model->save();

            GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount']);

            if ($disponible) {

                if ($calculado) {

                    // Los artículos ya están reemplazados: recién ahora la cuenta es la de verdad.
                    ComboCalculadoHelper::guardar($model);

                } else {

                    /*
                     * Un combo que deja de ser calculado conserva las filas de `combo_price_type` de
                     * cuando lo era, y una tienda nueva las prefiere sobre `combos.price`: mostraría
                     * un precio por lista que ya no es el del combo. Se borran.
                     */
                    ComboCalculadoHelper::limpiar_precios_por_lista($model->id);
                }
            }
        });

        $this->updateRelationsCreated('combo', $model->id, $request->childrens);

        return response()->json(['model' => $this->fullModel('Combo', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Combo::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Combo', $model->id);
        return response(null);
    }

    /**
     * El motivo por el que el descuento del request no se puede guardar, o null si está bien (o si
     * la base todavía no tiene las columnas: en la ventana de un deploy el descuento no existe y no
     * hay nada que rechazar).
     *
     * Es la única validación de este controlador, y a propósito: un descuento inválido que se
     * guardara "arreglado" (un 150 % que pasa a "sin descuento") dejaría a la persona creyendo que
     * el combo tiene un descuento que el cálculo no aplica.
     *
     * @param  mixed  $tipo
     * @param  mixed  $valor
     * @return string|null
     */
    protected function rechazo_del_descuento($tipo, $valor) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return null;
        }

        return ComboCalculadoHelper::validar_descuento($tipo, $valor);
    }
}
