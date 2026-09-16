<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\combo\ComboAltaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;

/**
 * Combo propuesto por el asistente de IA (misión agente-ia-mano-derecha, §D1 del plan): validación al
 * proponer, tarjeta, datos de ejecución y ejecución al confirmar.
 *
 * Se ejecuta por ComboAltaHelper::crear(), el MISMO camino que `POST api/combo` del modal de Combos
 * del Listado: una sola forma de dar de alta un combo con sus artículos y sus cantidades.
 *
 * 🔴 LA VALIDACIÓN LA PONE ESTE CAMINO, NO LA PANTALLA. `ComboController::store()` no validaba nada
 * —acepta un combo sin artículos, con cantidades en cero o con el precio vacío— y eso está en
 * producción: hacerlo validar ahora cambiaría el comportamiento de un endpoint que no es de esta
 * misión. Por eso ComboAltaHelper::validar() es un método aparte y lo llama SOLO el asistente, dos
 * veces: al proponer y otra vez al confirmar (un artículo se puede borrar en el medio).
 */
class PropuestaComboIaHelper {

    /**
     * Herramienta proponer_combo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  nombre*, articulos*, precio*, costo, reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            return RespuestaDeCargaIa::error($corte);
        }

        $nombre = EntradaDeCargaIa::texto($input, 'nombre');
        $precio = EntradaDeCargaIa::valor($input, 'precio');
        $articulos = EntradaDeCargaIa::valor($input, 'articulos');
        $articulos = is_array($articulos) ? $articulos : [];

        $faltan = [];

        if ($nombre === '') {

            $faltan[] = 'cómo se va a llamar el combo';
        }

        if (!count($articulos)) {

            $faltan[] = 'qué artículos lleva el combo y cuántos de cada uno';
        }

        if (EntradaDeCargaIa::vacio($precio)) {

            $faltan[] = 'a qué precio se vende el combo';
        }

        if (count($faltan)) {

            /*
             * Sin `opciones`: el catálogo de un comercio puede tener decenas de miles de artículos y
             * no entra en una lista. La IA los busca con consultar_stock_de_articulos, que es una
             * herramienta de lectura y está siempre disponible. Mismo criterio que proponer_pago con
             * el cliente.
             */
            return RespuestaDeCargaIa::faltan($faltan);
        }

        $precio = EntradaDeCargaIa::monto_positivo($precio, 'El precio del combo tiene que ser un número mayor a 0.');

        if (is_array($precio)) {

            return $precio;
        }

        $costo = null;
        $costo_pedido = EntradaDeCargaIa::valor($input, 'costo');

        if (!EntradaDeCargaIa::vacio($costo_pedido)) {

            if (!is_numeric($costo_pedido) || (float) $costo_pedido < 0) {

                return RespuestaDeCargaIa::error('El costo del combo tiene que ser un número mayor o igual a 0.');
            }

            $costo = round((float) $costo_pedido, 2);
        }

        $articles = self::articles_para_el_alta($articulos);

        $datos = [
            'name'     => $nombre,
            'cost'     => $costo,
            'price'    => $precio,
            'articles' => $articles,
        ];

        $motivo = ComboAltaHelper::validar($datos, $contexto->owner_id);

        if (!is_null($motivo)) {

            return RespuestaDeCargaIa::error($motivo);
        }

        $nombres = self::nombres_de_articulos($contexto, $articles);

        $renglones = [
            ['etiqueta' => 'Nombre', 'valor' => $nombre],
        ];

        foreach ($articles as $article) {

            $id = (int) $article['id'];

            $renglones[] = [
                'etiqueta' => 'Artículo',
                'valor'    => (isset($nombres[$id]) ? $nombres[$id] : 'Artículo').' × '.(int) $article['pivot']['amount'],
            ];
        }

        $renglones[] = ['etiqueta' => 'Precio', 'valor' => FormatoIaHelper::monto($precio)];

        if (!is_null($costo)) {

            $renglones[] = ['etiqueta' => 'Costo', 'valor' => FormatoIaHelper::monto($costo)];
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_COMBO,
            self::clave($nombre),
            $datos,
            ['titulo' => 'Combo', 'renglones' => $renglones, 'aviso' => null],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $cantidad = count($articles);

        $resumen = 'Combo '.$nombre.' · '.$cantidad.' '.($cantidad === 1 ? 'artículo' : 'artículos').' · '.FormatoIaHelper::monto($precio);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    /**
     * Identidad de un combo para el reemplazo: su nombre. Una corrección del precio o de los
     * artículos del MISMO combo reemplaza la tarjeta; un combo con otro nombre no la pisa.
     *
     * @param  string  $nombre
     * @return string
     */
    static function clave($nombre) {

        return 'combo:'.mb_strtolower(trim((string) $nombre));
    }

    /**
     * Crea el combo de la tarjeta. Corre adentro de la transacción de EjecutorAccionesIaHelper,
     * autenticado como la persona que confirma.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion) {

        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            throw new AccionIaException(422, $corte);
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        /*
         * 🔴 SE VUELVE A VALIDAR CON LOS DATOS DE HOY. Entre que la tarjeta se armó y la persona
         * toca Confirmar pueden pasar hasta 24 horas (AiMessageAction::HORAS_VENCIMIENTO), y en el
         * medio alguien pudo borrar uno de los artículos. Sin esto, el combo quedaría creado
         * apuntando a un artículo que ya no está.
         */
        $motivo = ComboAltaHelper::validar($datos, $contexto->owner_id);

        if (!is_null($motivo)) {

            throw new AccionIaException(422, $motivo.' Pedímelo de nuevo.');
        }

        $owner_id = $contexto->owner_id;

        /*
         * El correlativo va como closure para que num() corra ADENTRO de la transacción del helper y
         * su lockForUpdate se sostenga hasta el commit. Se le pasa el dueño explícito como
         * `$prop_value` para que num() NO resuelva el usuario por Auth: acá el dueño sale de la
         * conversación, no de la sesión.
         *
         * 🔴 `$datos` NO lleva `online`, y es una decisión, no un olvido (misión
         * combos-y-rangos-de-precio, 16/9/2026): el combo que arma el asistente nace APAGADO y el
         * dueño lo publica desde el ABM si quiere. Publicar en la tienda expone precio y receta a
         * los compradores — es una decisión comercial, no la consecuencia de haber pedido un combo
         * por chat. El motivo largo está en ComboAltaHelper::crear(), donde se normaliza la columna.
         */
        $combo = ComboAltaHelper::crear($datos, $owner_id, function () use ($owner_id) {

            $ct = new Controller();

            return $ct->num('combos', null, 'user_id', $owner_id);
        });

        return [
            'texto' => 'Combo '.$combo->name.' creado',
            'ruta'  => [
                // La pantalla de combos es un modal del Listado de artículos, no una ruta propia:
                // el botón lleva ahí (src/components/listado/components/combos/ModalButton.vue).
                'name'   => 'article',
                'params' => new \stdClass(),
                'texto'  => 'Ver en el Listado',
            ],
        ];
    }

    /**
     * El motivo por el que esta persona no puede armar combos, o null si puede. Los dos gates: la
     * extensión del comercio y el permiso de la persona, en ese orden (el módulo primero: sin
     * módulo, el permiso no significa nada).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return string|null
     */
    protected static function verificar_acceso(ContextoDeCargaIa $contexto) {

        if (!PermisosIaHelper::tiene_extencion($contexto->owner, PermisosIaHelper::EXTENCION_COMBOS)) {

            return PermisosIaHelper::mensaje_sin_extencion('Combos');
        }

        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMBOS)) {

            return PermisosIaHelper::mensaje_sin_permiso('combos');
        }

        return null;
    }

    /**
     * Pasa `articulos` de la herramienta ([{articulo_id, cantidad}]) a la forma que espera
     * ComboAltaHelper::crear(), que es la MISMA que manda la pantalla: cada ítem con `id` y
     * `pivot.amount` (GeneralHelper::attachModels lee el pivot por ahí).
     *
     * No valida: de eso se ocupa ComboAltaHelper::validar() sobre el array ya armado, así que el
     * asistente y la pantalla miran exactamente los mismos datos.
     *
     * @param  array  $articulos
     * @return array
     */
    protected static function articles_para_el_alta(array $articulos) {

        $articles = [];

        foreach ($articulos as $articulo) {

            if (!is_array($articulo)) {

                continue;
            }

            $id = isset($articulo['articulo_id']) ? (int) $articulo['articulo_id'] : 0;
            $cantidad = isset($articulo['cantidad']) ? $articulo['cantidad'] : null;

            $articles[] = [
                'id'    => $id,
                'pivot' => ['amount' => $cantidad],
            ];
        }

        return $articles;
    }

    /**
     * Nombres de los artículos del combo, indexados por id, para la tarjeta. Una sola consulta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $articles
     * @return array<int,string>
     */
    protected static function nombres_de_articulos(ContextoDeCargaIa $contexto, array $articles) {

        $ids = [];

        foreach ($articles as $article) {

            $ids[] = (int) $article['id'];
        }

        $nombres = [];

        if (!count($ids)) {

            return $nombres;
        }

        foreach (Article::where('user_id', $contexto->owner_id)->whereIn('id', $ids)->get(['id', 'name']) as $articulo) {

            $nombres[(int) $articulo->id] = (string) $articulo->name;
        }

        return $nombres;
    }
}
