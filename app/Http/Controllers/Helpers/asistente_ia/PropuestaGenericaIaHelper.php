<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Las tres propuestas genéricas del asistente —alta, edición y baja de cualquier entidad del
 * catálogo de escritura— con la misma forma que las demás (misión asistente-omnisciente, 21/9/2026,
 * bloque B): validan contra CatalogoDeEscrituraIaHelper, arman la tarjeta con los datos EXACTOS
 * que van a ir al controller y guardan en `datos` lo que EjecutorGenericoIaHelper necesita para
 * llamar a la pantalla al confirmar.
 *
 * Lo que valida cada una, y por qué:
 *
 *   - Cada clave de `datos` / `cambios` tiene que existir en el catálogo de la entidad y estar
 *     habilitada para esa operación. Una clave desconocida CORTA con la lista de campos válidos
 *     (nunca se ignora: una carga con un campo descartado en silencio llega a la persona como una
 *     carga completa).
 *   - Los valores se coercionan por tipo (number, checkbox, date AAAA-MM-DD) y una relación se
 *     acepta por nombre (se resuelve contra su tabla, filtrando por dueño si tiene user_id) o por
 *     id si otra herramienta lo devolvió. 0 resultados o 2+ vuelven como `faltan`.
 *   - El alta exige los obligatorios (los NOT NULL sin default más `name`) y avisa —no rechaza— si
 *     ya hay una fila del dueño con ese nombre.
 *   - La edición y la baja ubican el registro por id (Y dueño) o por nombre (`LIKE` por dueño;
 *     con varias coincidencias, la exacta gana; si no, `faltan` con las opciones). La edición guarda
 *     `referencia_updated_at`: si alguien edita el registro entre la tarjeta y el clic, confirmar
 *     da 409 y la tarjeta queda vencida.
 *
 * 🔴 Ninguna de las tres se auto-confirma con el dueño en "cauteloso" ni en "resuelto": no están en
 * HerramientasDeCarga::AUTO_CONFIRMABLES.
 *
 * ⚠️ Con el dueño en "directo" (misión asistente-capacidades-y-hilos, 22/9/2026) el ALTA y la
 * EDICIÓN sí se ejecutan solas: entran en AUTO_CONFIRMABLES_DIRECTO y sus `case` pasan por
 * quizas_auto_confirmar(), que es quien mira el modo. 🔴 LA BAJA NO, EN NINGÚN MODO: 31 de las 40
 * entidades de este catálogo no usan SoftDeletes, así que un borrado no se deshace. Está en
 * NUNCA_AUTO_CONFIRMABLES y su `case` sigue sin pasar por la puerta.
 */
class PropuestaGenericaIaHelper
{
    /** Cuántas opciones se ofrecen cuando un nombre es ambiguo. */
    const MAX_OPCIONES = 10;

    /** Cuántos renglones de identificación lleva la tarjeta de una baja. */
    const MAX_RENGLONES_DE_BAJA = 5;

    /**
     * Qué hacer si la MARCA que llegó no existe (misión asistente-arreglos-segunda-prueba,
     * 19/10/2026, N6). En la segunda prueba contra demo3 el dueño pidió el alta de una «lampara de
     * filamento led philips»: la marca Philips no existía y resolver_relacion() devolvía un `faltan`
     * pelado, sin decir que se podía crear, así que el artículo 49 quedó sin marca (con la 3M del
     * 16/10 el modelo sí la había creado). Mismo molde que PropuestaGastoIaHelper::SI_NO_ESTA: la
     * respuesta dice cómo seguir, y la segunda llamada trae `opciones.reenvia_igual` para no perder
     * la foto, la descripción, la categoría, el precio ni el stock.
     *
     * Las dos ramas (en el acto / tarjeta) existen porque proponer_alta de una marca se ejecuta en el
     * acto solo con la confianza en "directo"; en los otros modos queda una tarjeta y la marca todavía
     * no existe cuando el modelo vuelve a llamar (ver alta_de_marca_pendiente()).
     *
     * ⚠️ Vale para el alta y para la edición: en la edición no hay extras que perder y el modelo vuelve
     * a mandar los mismos cambios; en el alta de un artículo, lo de `opciones.reenvia_igual`.
     */
    const SI_NO_ESTA_LA_MARCA = 'Si la marca que nombró la persona, o que se lee en la foto o en el nombre del producto, no está en la lista, '
        . 'creala vos con proponer_alta (entidad brand, con su nombre), sin preguntarle si la creás. Si se crea en el acto (la respuesta '
        . 'trae el resultado), volvé a llamar en este mismo mensaje con esa marca en brand_id y EXACTAMENTE lo mismo que habías mandado '
        . '(en el alta de un artículo, los datos y extras de opciones.reenvia_igual: la foto, la descripción, la categoría, el precio y el '
        . 'stock, todo). Si quedó una tarjeta para confirmar, no vuelvas a llamar todavía: decile que confirme la marca y, cuando la '
        . 'confirme, armá la carga con todo lo que pidió. Si la marca no la dijo la persona ni se lee en la foto o en el nombre, no la '
        . 'inventes: volvé a llamar sin brand_id.';

    /**
     * Qué hacer si la marca que llegó se PARECE a una o más del negocio pero ninguna se llama igual
     * (revisión independiente, 19/10/2026; ver resolver_marca()): primero se pregunta si es una de
     * ésas, y recién si la persona dice que no, se crea la que dijo.
     */
    const SI_NO_ES_NINGUNA_LA_MARCA = 'Ofrecele estas marcas por su nombre y preguntale si es una de ellas. Si te dice que no es ninguna, '
        . 'creala con proponer_alta (entidad brand, con el nombre que dijo) y volvé a llamar con ese nombre en brand_id.';

    /**
     * Cuántas marcas del dueño se traen para comparar normalizado (resolver_marca(),
     * marca_con_el_mismo_nombre()). Un negocio tiene decenas o cientos; el techo es para que una base
     * rara no traiga cien mil filas en una herramienta.
     */
    const TOPE_DE_MARCAS = 5000;

    // -------------------------------------------------------------------------------------------
    // Alta
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_alta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  mixed  $entidad
     * @param  array  $datos  [campo => valor], con las claves de que_puedo_cargar.
     * @param  mixed  $reemplaza_a
     * @param  array  $extras  La foto y la descripción de un artículo (AltaDeArticuloConFotoIaHelper::separar).
     * @return array
     */
    public static function proponer_alta(ContextoDeCargaIa $contexto, AiMessage $mensaje, $entidad, array $datos, $reemplaza_a = null, array $extras = [])
    {
        $declaracion = self::entidad_para($contexto, $entidad, Catalogo::OP_ALTA);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        /*
         * Misión asistente-fotos-barras-y-compras (24/9/2026): la foto y la descripción viajan en la
         * MISMA tarjeta del alta, pero sólo un artículo las tiene. En otra entidad se corta en vez de
         * ignorarlas: una tarjeta que se calla una parte del pedido llega a la persona como completa.
         */
        if (count($extras) && $declaracion['entidad'] !== AltaDeArticuloConFotoIaHelper::ENTIDAD) {

            return RespuestaDeCargaIa::error(MargenesPorListaIaHelper::NO_SE_CARGO.'la foto, la descripción, el margen por lista y el stock inicial sólo se cargan en el alta de un artículo. Volvé a llamar sin eso.');
        }

        /*
         * 🔴 Correcciones del 24/9/2026: una CORRECCIÓN del alta de un artículo ("sí, pero cambiale
         * el nombre") hereda la foto y la descripción de la tarjeta que reemplaza, si el modelo no
         * las vuelve a mandar. En la prueba real el modelo inventó un imagen_id (310) y reescribió la
         * descripción inventando una frase, porque la línea de historial no trae el id y recorta los
         * renglones. Ver AltaDeArticuloConFotoIaHelper::heredar().
         */
        if ($declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD) {

            $extras = AltaDeArticuloConFotoIaHelper::heredar($contexto, $reemplaza_a, $extras);
        }

        /*
         * Misión asistente-altas-y-conversacion (16/10/2026, D5): la subcategoría de un artículo se
         * resuelve DENTRO de su categoría, así que sale de `datos` antes de validar y se resuelve
         * después, con la categoría ya resuelta (ver categoria_del_alta_de_articulo()). El "sin
         * categoría" a propósito se normaliza a null para que validar_campos() lo tome como vacío.
         */
        $subcategoria_pedida = null;

        $datos_recibidos = $datos;

        if ($declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD) {

            list($datos, $subcategoria_pedida) = self::separar_categoria_del_articulo($declaracion, $datos);
        }

        $validado = self::validar_campos($contexto, $declaracion, Catalogo::OP_ALTA, $datos);

        if (RespuestaDeCargaIa::es_negativa($validado)) {

            /*
             * 🔴 LA MARCA QUE NO EXISTE, EN EL ALTA DE UN ARTÍCULO, VUELVE CON EL REENVÍO (misión
             * asistente-arreglos-segunda-prueba, 19/10/2026, N6). resolver_relacion() ya le dice al
             * modelo que la cree (SI_NO_ESTA_LA_MARCA), pero no tiene los extras: si el modelo vuelve
             * a llamar armando la llamada de memoria, se le cae la foto, la descripción o el stock y
             * el alta sale sin ellos sin que nadie lo note (lo mismo que fijó el test 72 con la
             * categoría). Por eso acá, que sí tiene la llamada entera, se suma `opciones.reenvia_igual`
             * sin la marca (es lo único que cambia) y con la categoría y la subcategoría tal como
             * vinieron.
             */
            if ($declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD && self::es_la_marca_que_no_esta($validado)) {

                return self::con_reenvio(
                    $validado,
                    self::llamada_para_reenviar($declaracion, $datos_recibidos, $extras, $reemplaza_a, ['brand_id'])
                );
            }

            return $validado;
        }

        /*
         * 🔴 Misión alta-por-agente-margen-y-stock (29/9/2026, demo3, artículo 17320): con listas de
         * precio, los campos que la ficha esconde —el margen suelto (`percentage_gain`, por la columna
         * o por su alias "margen de ganancia"), el precio manual y "aplica el margen del proveedor"—
         * NO mueven ningún precio de lista. Si los MANDA EL MODELO, se rechaza con un error que dice
         * que no se cargó nada, que el margen va por lista y que pregunte cuál. Se mira lo que mandó
         * él (antes de combinar con la tarjeta reemplazada y sin los defaults de la pantalla): ver
         * MargenesPorListaIaHelper::guarda_de_campos_escondidos().
         */
        $es_articulo = $declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD;

        if ($es_articulo) {

            $escondido = MargenesPorListaIaHelper::guarda_de_campos_escondidos($contexto, $validado['payload']);

            if (!is_null($escondido)) {

                return $escondido;
            }
        }

        $del_modelo = $validado;

        $validado = self::con_los_campos_de_la_tarjeta_reemplazada($contexto, $declaracion, $reemplaza_a, $validado);

        /*
         * Y si alguno de esos campos llegó SOLO por herencia de una tarjeta propuesta antes del
         * deploy (el modelo no lo mandó), se descarta y la tarjeta lo avisa: cortar con "no lo
         * mandes" sería pedirle que deje de mandar algo que no mandó (ronda de correcciones del
         * 29/9/2026). Ver MargenesPorListaIaHelper::sacar_campos_escondidos_heredados().
         */
        $aviso_de_lo_heredado = null;

        if ($es_articulo) {

            list($validado, $aviso_de_lo_heredado) = MargenesPorListaIaHelper::sacar_campos_escondidos_heredados($contexto, $del_modelo, $validado);
        }

        $faltan = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if ($campo['obligatorio'] && in_array(Catalogo::OP_ALTA, $campo['operaciones'], true) && self::vacio(isset($validado['payload'][$columna]) ? $validado['payload'][$columna] : null)) {

                $faltan[] = $campo['etiqueta'];
            }
        }

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan);
        }

        /*
         * 🔴 Misión asistente-altas-y-conversacion (16/10/2026, D5, demo3 S10 y S11): un artículo de
         * un negocio con categorías no se arma SIN categoría porque el modelo se la olvidó. Va antes
         * de los extras a propósito: un error acá no deja fotos anotadas ni nada escrito, y en
         * "directo" no hay tarjeta que auto-confirmar. Ver categoria_del_alta_de_articulo().
         */
        $aviso_de_la_categoria = null;

        if ($es_articulo) {

            $con_categoria = self::categoria_del_alta_de_articulo(
                $contexto,
                $validado,
                $subcategoria_pedida,
                self::llamada_para_reenviar($declaracion, $datos_recibidos, $extras, $reemplaza_a)
            );

            if (RespuestaDeCargaIa::es_negativa($con_categoria)) {

                return $con_categoria;
            }

            $validado = $con_categoria['validado'];
            $aviso_de_la_categoria = $con_categoria['aviso'];
        }

        $payload = self::payload_de_alta($declaracion, $validado['payload']);

        $nombre = self::nombre_pedido($declaracion, $validado['payload']);

        /*
         * 🔴 UNA MARCA QUE YA EXISTE NO SE CREA DE NUEVO (revisión independiente de la misión
         * asistente-arreglos-segunda-prueba, 19/10/2026). Con SI_NO_ESTA_LA_MARCA el modelo crea marcas
         * solo; si el nombre que manda ya está en el negocio (comparado sin mayúsculas, tildes ni
         * espacios de más), crearla dejaría dos «Philips» y los artículos repartidos entre las dos. Es
         * un error para el modelo (prefijo NO_SE_ARMO) con el id de la existente, para que la use.
         * Para las demás entidades sigue el aviso de "ya hay uno con ese nombre" de abajo.
         */
        if ($declaracion['entidad'] === 'brand' && !is_null($nombre)) {

            $existente = self::marca_con_el_mismo_nombre($contexto, $nombre);

            if (!is_null($existente)) {

                return RespuestaDeCargaIa::error(
                    CategoriasDelNegocioIaHelper::NO_SE_ARMO.'ya existe la marca "'.$existente->name.'" (brand_id '.(int) $existente->id.'). '
                    .'No la crees de nuevo: en la carga que la necesitaba usá brand_id '.(int) $existente->id.'.'
                );
            }
        }

        $aviso = null;

        if (!is_null($nombre) && self::hay_otra_con_ese_nombre($contexto, $declaracion, $nombre)) {

            $aviso = 'Ya hay '.($declaracion['genero'] === 'f' ? 'una ' : 'un ').$declaracion['singular'].' con ese nombre.';
        }

        if (!is_null($declaracion['aviso_de_alta'])) {

            $aviso = is_null($aviso) ? $declaracion['aviso_de_alta'] : $aviso.' '.$declaracion['aviso_de_alta'];
        }

        if (!is_null($aviso_de_lo_heredado)) {

            $aviso = is_null($aviso) ? $aviso_de_lo_heredado : $aviso.' '.$aviso_de_lo_heredado;
        }

        if (!is_null($aviso_de_la_categoria)) {

            $aviso = is_null($aviso) ? $aviso_de_la_categoria : $aviso.' '.$aviso_de_la_categoria;
        }

        $renglones = self::renglones($declaracion, $validado['pedidos'], $validado['nombres']);

        $renglones = self::costo_en_dolares_en_los_renglones($declaracion, $validado['payload'], $renglones);

        $imagen_url = null;

        $datos_de_la_tarjeta = [
            'entidad'   => $declaracion['entidad'],
            'operacion' => Catalogo::OP_ALTA,
            'payload'   => $payload,
            'pedidos'   => $validado['pedidos'],
        ];

        /*
         * Los extras van en `datos.extras` y NO en el payload: la pantalla de artículos no los recibe
         * en su store(). Los ejecuta AltaDeArticuloConFotoIaHelper::completar() después del alta.
         */
        if (count($extras)) {

            $resueltos = AltaDeArticuloConFotoIaHelper::resolver(
                $contexto,
                $mensaje,
                $extras,
                isset($validado['payload']['unidades_individuales']) ? $validado['payload']['unidades_individuales'] : null
            );

            if (RespuestaDeCargaIa::es_negativa($resueltos)) {

                return $resueltos;
            }

            $datos_de_la_tarjeta['extras'] = $resueltos['extras'];

            // Márgenes heredados de listas que ya no existen: se descartaron y la tarjeta lo dice.
            if (!empty($resueltos['aviso'])) {

                $aviso_de_lo_heredado = is_null($aviso_de_lo_heredado) ? $resueltos['aviso'] : $aviso_de_lo_heredado.' '.$resueltos['aviso'];

                $aviso = is_null($aviso) ? $resueltos['aviso'] : $aviso.' '.$resueltos['aviso'];
            }

            foreach ($resueltos['renglones'] as $renglon) {

                $renglones[] = $renglon;
            }

            /*
             * La miniatura de la foto en la tarjeta del panel (`presentacion.imagen_url`, la clave
             * opcional que AccionCard.vue ya pinta arriba de los renglones): el dueño ve QUÉ foto
             * queda publicada antes de confirmar, sea la que mandó o la encontrada en internet.
             */
            if (!is_null($resueltos['imagen_url'])) {

                $imagen_url = $resueltos['imagen_url'];
            }

            if (!empty($resueltos['extras']['imagen_id'])) {

                $aviso_de_la_foto = 'La foto también se publica en la tienda online del negocio.';

                $aviso = is_null($aviso) ? $aviso_de_la_foto : $aviso.' '.$aviso_de_la_foto;
            }
        }

        /*
         * 🔴 Con listas de precio, la tarjeta del alta de un artículo dice SIEMPRE con qué margen
         * queda cada lista que la persona no nombró (misión alta-por-agente-margen-y-stock,
         * 29/9/2026): aunque no venga ningún margen. En demo3 las dos listas quedaron en 0 % y la
         * tarjeta no lo mostraba en ningún lado. El mismo texto viaja en la respuesta (`aviso`) para
         * que el modelo se lo cuente a la persona con los renglones.
         */
        $aviso_de_las_listas = null;

        if ($declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD) {

            $margenes_de_la_tarjeta = isset($datos_de_la_tarjeta['extras'][MargenesPorListaIaHelper::CLAVE]) && is_array($datos_de_la_tarjeta['extras'][MargenesPorListaIaHelper::CLAVE])
                ? $datos_de_la_tarjeta['extras'][MargenesPorListaIaHelper::CLAVE]
                : [];

            $aviso_de_las_listas = MargenesPorListaIaHelper::aviso_del_alta($contexto, $margenes_de_la_tarjeta);

            if (!is_null($aviso_de_las_listas)) {

                $aviso = is_null($aviso) ? $aviso_de_las_listas : $aviso.' '.$aviso_de_las_listas;
            }
        }

        $presentacion = [
            'titulo'    => Catalogo::titulo($declaracion['entidad'], Catalogo::OP_ALTA),
            'renglones' => $renglones,
            'aviso'     => $aviso,
        ];

        if (!is_null($imagen_url)) {

            $presentacion['imagen_url'] = $imagen_url;

            /*
             * La referencia de la foto, para que AiMessageAction::toArray() rearme la URL en el
             * request de la SPA: esta propuesta corre adentro del job, donde url() sale de APP_URL
             * (ver FotosDelMensajeIaHelper::url). `imagen_url` queda como respaldo.
             */
            if (!empty($resueltos['extras']['imagen_id'])) {

                $imagen = AiMessageImagen::find((int) $resueltos['extras']['imagen_id']);

                if (!is_null($imagen)) {

                    $presentacion['imagen_mensaje'] = ['ai_message_id' => (int) $imagen->ai_message_id, 'orden' => (int) $imagen->orden];
                }
            }
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_ALTA,
            self::clave_de_alta($declaracion, $nombre, $validado['pedidos']),
            $datos_de_la_tarjeta,
            $presentacion,
            $reemplaza_a
        );

        $resumen = Catalogo::titulo($declaracion['entidad'], Catalogo::OP_ALTA).(is_null($nombre) ? '' : ' '.$nombre).self::resumen_de_renglones($renglones, $nombre);

        // El aviso viaja también al modelo cuando habla de listas, de algo que se descartó o de la subcategoría que se sacó.
        $avisa_al_modelo = !is_null($aviso_de_las_listas) || !is_null($aviso_de_lo_heredado) || !is_null($aviso_de_la_categoria);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, $avisa_al_modelo ? ['aviso' => $aviso] : []);
    }

    /**
     * Una CORRECCIÓN de un alta hereda los campos de la tarjeta que reemplaza: lo que el modelo
     * vuelve a mandar pisa, lo que no manda se conserva.
     *
     * Misión asistente-deepseek-pro-razona (24/9/2026): en la prueba real con DeepSeek, ante "sí,
     * pero cambiale el nombre a Cera Nic Mate" el modelo mandó sólo `{"name": ...}` y la tarjeta
     * nueva perdió el código de barras de la anterior — sin que la respuesta lo dijera. Los extras
     * (foto y descripción) ya se heredaban (AltaDeArticuloConFotoIaHelper::heredar); esto es lo mismo
     * para los campos.
     *
     * Se combina DESPUÉS de validar porque los dos lados vienen con nombres de columna (`pedidos`):
     * combinar el crudo del modelo ("nombre") con el guardado ("name") dejaría dos claves para el
     * mismo campo. El combinado se vuelve a validar entero; si esa validación falla, queda lo que
     * mandó el modelo (nunca peor que antes).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  mixed  $reemplaza_a
     * @param  array  $validado  Lo que devolvió validar_campos() con lo que mandó el modelo.
     * @return array
     */
    protected static function con_los_campos_de_la_tarjeta_reemplazada(ContextoDeCargaIa $contexto, array $declaracion, $reemplaza_a, array $validado)
    {
        $reemplaza_a = is_numeric($reemplaza_a) ? (int) $reemplaza_a : 0;

        if ($reemplaza_a <= 0 || !isset($validado['pedidos']) || !is_array($validado['pedidos'])) {

            return $validado;
        }

        /*
         * Sólo de una tarjeta que se está corrigiendo (todavía propuesta, o recién reemplazada en una
         * cadena de correcciones): nunca de una ya confirmada, cancelada o vencida (chequeo
         * adversarial, 24/9/2026).
         */
        $anterior = AiMessageAction::where('id', $reemplaza_a)
                                    ->where('ai_conversation_id', $contexto->conversation->id)
                                    ->where('tipo', AiMessageAction::TIPO_ALTA)
                                    ->whereIn('estado', [AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::ESTADO_REEMPLAZADA])
                                    ->first();

        if (is_null($anterior)
            || !is_array($anterior->datos)
            || (isset($anterior->datos['entidad']) && $anterior->datos['entidad'] !== $declaracion['entidad'])
            || !isset($anterior->datos['pedidos'])
            || !is_array($anterior->datos['pedidos'])) {

            return $validado;
        }

        $combinados = array_merge($anterior->datos['pedidos'], $validado['pedidos']);

        if ($combinados == $validado['pedidos']) {

            return $validado;
        }

        $revalidado = self::validar_campos($contexto, $declaracion, Catalogo::OP_ALTA, $combinados);

        return RespuestaDeCargaIa::es_negativa($revalidado) ? $validado : $revalidado;
    }

    // -------------------------------------------------------------------------------------------
    // Categoría y subcategoría de un artículo (misión asistente-altas-y-conversacion, D5)
    // -------------------------------------------------------------------------------------------

    /**
     * Separa la subcategoría de lo que mandó el modelo para un artículo y normaliza el "sin
     * categoría" a propósito.
     *
     * La subcategoría sale porque resolver_relacion() la buscaría por nombre entre TODAS las del
     * negocio, sin mirar de qué categoría cuelga ("Accesorios" puede estar en Baño y en Cocina), y la
     * categoría todavía no está resuelta cuando le toca. Se resuelve después, con
     * CategoriasDelNegocioIaHelper::resolver_subcategoria(). Un "sin subcategoría" explícito ("", 0,
     * "ninguna") se deja como null (validar_campos() lo toma como vacío); un null crudo se SACA, como
     * si no hubiera venido; y una lista se deja como está para que validar_campos() la rechace con su
     * mensaje de siempre.
     *
     * @param  array  $declaracion
     * @param  array  $valores  `datos` del alta o `cambios` de la edición.
     * @return array  [los valores sin la subcategoría, la subcategoría pedida o null]
     */
    protected static function separar_categoria_del_articulo(array $declaracion, array $valores): array
    {
        $alias = self::alias_de_campos($declaracion);

        $subcategoria = null;

        foreach ($valores as $clave => $valor) {

            $clave_normalizada = self::normalizar_clave((string) $clave);

            $columna = isset($alias[$clave_normalizada]) ? $alias[$clave_normalizada] : null;

            if ($columna !== 'category_id' && $columna !== 'sub_category_id') {

                continue;
            }

            if (is_array($valor) || is_object($valor)) {

                continue;
            }

            // 🔴 null es "no vino" (chequeo independiente, 16/10/2026): no esquiva la guarda del alta.
            if (is_null($valor)) {

                unset($valores[$clave]);

                continue;
            }

            if (CategoriasDelNegocioIaHelper::es_sin_categoria($valor)) {

                $valores[$clave] = null;

                continue;
            }

            if ($columna === 'sub_category_id') {

                $subcategoria = $valor;

                unset($valores[$clave]);
            }
        }

        return [$valores, $subcategoria];
    }

    /**
     * La categoría y la subcategoría del alta de un artículo, ya validada (y combinada con la tarjeta
     * que corrige, si la hay):
     *
     *  - Con subcategoría pedida: se resuelve DENTRO de la categoría (error con las subcategorías de
     *    esa categoría si no es de ella) o, si no vino categoría, se deduce de la subcategoría.
     *  - Una subcategoría heredada de la tarjeta corregida que no es de la categoría nueva se saca, y
     *    el aviso lo dice; con la categoría vaciada a propósito, también.
     *  - 🔴 Sin `category_id` (no vino; null cuenta como que no vino) en un negocio con categorías:
     *    NO se arma la tarjeta. El error empieza con "No se armó ninguna tarjeta" y trae el árbol en
     *    `opciones.categorias`: el prompt le dice al modelo que ese error es para él, que elija y
     *    vuelva a llamar en el mismo mensaje. En un negocio sin categorías, todo como antes.
     *  - 🔴 Con una categoría que TIENE subcategorías y sin `sub_category_id` (chequeo independiente,
     *    16/10/2026): tampoco se arma; el error trae las subcategorías DE ESA categoría. El martillo de
     *    demo3 quedó con la categoría y sin la subcategoría que existía. `sub_category_id` vacío
     *    explícito ("") es "sin subcategoría" y se acepta.
     *  - 🔴 Todo error de acá trae `opciones.reenvia_igual`: la llamada que llegó (datos y extras, sin
     *    la categoría ni la subcategoría). Si el modelo vuelve a llamar sin la foto, la descripción o
     *    el stock inicial que había mandado, el alta sale sin ellos y nadie lo nota.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $validado
     * @param  mixed  $subcategoria_pedida
     * @param  array  $reenvia_igual  llamada_para_reenviar().
     * @return array  ['validado' => ..., 'aviso' => string|null] o la respuesta negativa.
     */
    protected static function categoria_del_alta_de_articulo(ContextoDeCargaIa $contexto, array $validado, $subcategoria_pedida, array $reenvia_igual = [])
    {
        $payload = $validado['payload'];
        $aviso = null;

        $vino_categoria = array_key_exists('category_id', $payload);
        $category_id = $vino_categoria && !self::vacio($payload['category_id']) ? (int) $payload['category_id'] : null;

        if (!is_null($subcategoria_pedida)) {

            $sub = CategoriasDelNegocioIaHelper::resolver_subcategoria($contexto->owner_id, $category_id, $subcategoria_pedida);

            if (RespuestaDeCargaIa::es_negativa($sub)) {

                return self::con_reenvio($sub, $reenvia_igual);
            }

            $validado = self::con_campo($validado, 'sub_category_id', $sub['sub_category_id'], $sub['subcategoria']);

            if (is_null($category_id) && !is_null($sub['category_id'])) {

                $validado = self::con_campo($validado, 'category_id', $sub['category_id'], $sub['categoria']);
            }

        } elseif (isset($payload['sub_category_id']) && !self::vacio($payload['sub_category_id'])) {

            // Una subcategoría que llegó por herencia de la tarjeta que se corrige.
            $sub_id = (int) $payload['sub_category_id'];

            if (is_null($category_id) && !$vino_categoria) {

                $de = CategoriasDelNegocioIaHelper::categoria_de_la_subcategoria($contexto->owner_id, $sub_id);

                if (!is_null($de)) {

                    $validado = self::con_campo($validado, 'category_id', $de['id'], $de['nombre']);
                }

            } elseif (is_null($category_id) || !CategoriasDelNegocioIaHelper::es_de_la_categoria($sub_id, $category_id)) {

                $nombre_sub = CategoriasDelNegocioIaHelper::nombre_de_subcategoria($sub_id);

                $validado = self::sin_campo($validado, 'sub_category_id');

                $aviso = 'La subcategoría "'.(is_null($nombre_sub) ? '#'.$sub_id : $nombre_sub).'" no es de la categoría que queda: el artículo se carga sin subcategoría.';
            }
        }

        $volve_a_llamar = 'volvé a llamar a proponer_alta en este mismo mensaje con EXACTAMENTE los mismos datos y extras de opciones.reenvia_igual (la foto, la descripción, el código, el stock: todo), sumando ';

        if (!array_key_exists('category_id', $validado['payload']) && CategoriasDelNegocioIaHelper::tiene_categorias($contexto->owner_id)) {

            $arbol = CategoriasDelNegocioIaHelper::arbol($contexto->owner_id);

            return RespuestaDeCargaIa::error(
                CategoriasDelNegocioIaHelper::NO_SE_ARMO.'falta la categoría del artículo y el negocio trabaja con categorías. '
                .'Elegí de opciones.categorias la categoría que corresponde a este producto (y una subcategoría DE ESA categoría, si alguna encaja) y '
                .$volve_a_llamar.'category_id y sub_category_id por nombre. '
                .'Si la persona pidió que quede sin categoría, mandá category_id vacío ("").'
                .(is_null($arbol['aviso']) ? '' : ' '.$arbol['aviso']),
                ['categorias' => $arbol['categorias'], 'reenvia_igual' => $reenvia_igual]
            );
        }

        $categoria_final = isset($validado['payload']['category_id']) && !self::vacio($validado['payload']['category_id'])
            ? (int) $validado['payload']['category_id']
            : null;

        if (!is_null($categoria_final) && !array_key_exists('sub_category_id', $validado['payload'])) {

            $subcategorias = CategoriasDelNegocioIaHelper::subcategorias_de($contexto->owner_id, $categoria_final);

            if (count($subcategorias)) {

                $nombre = CategoriasDelNegocioIaHelper::nombre_de_categoria($contexto->owner_id, $categoria_final);

                return RespuestaDeCargaIa::error(
                    CategoriasDelNegocioIaHelper::NO_SE_ARMO.'falta la subcategoría: la categoría "'.$nombre.'" tiene subcategorías. '
                    .'Elegí de opciones.sub_category_id la que corresponde a este producto y '
                    .$volve_a_llamar.'category_id "'.$nombre.'" y esa sub_category_id. '
                    .'Si ninguna encaja, mandá sub_category_id vacío ("").',
                    ['sub_category_id' => $subcategorias, 'reenvia_igual' => $reenvia_igual]
                );
            }
        }

        return ['validado' => $validado, 'aviso' => $aviso];
    }

    /**
     * La llamada a proponer_alta tal como llegó, para que el modelo la reenvíe igual después de un
     * "No se armó ninguna tarjeta" de la categoría: `entidad`, `datos` sin la categoría ni la
     * subcategoría, los extras con sus claves de herramienta (sin las marcas internas de "quitar") y
     * `reemplaza_a` si vino.
     *
     * Desde el 19/10/2026 (misión asistente-arreglos-segunda-prueba, N6) también la usa la marca que
     * no existe: ahí lo que el modelo tiene que volver a mandar es la MARCA, así que se sacan solo las
     * columnas de `$columnas_que_faltan` (['brand_id']) y la categoría y la subcategoría viajan como
     * vinieron. Por nombre de columna, no de clave: la marca puede haber llegado como "marca".
     *
     * @param  array  $declaracion
     * @param  array  $datos  Los `datos` como llegaron (antes de separar la subcategoría).
     * @param  array  $extras  Los extras ya separados (y heredados, si es una corrección).
     * @param  mixed  $reemplaza_a
     * @param  array  $columnas_que_faltan  Las columnas que el modelo tiene que volver a elegir y por
     *                                      eso no se reenvían (por defecto, la categoría y la subcategoría).
     * @return array
     */
    protected static function llamada_para_reenviar(array $declaracion, array $datos, array $extras, $reemplaza_a, array $columnas_que_faltan = ['category_id', 'sub_category_id']): array
    {
        $alias = self::alias_de_campos($declaracion);

        foreach ($datos as $clave => $valor) {

            $clave_normalizada = self::normalizar_clave((string) $clave);

            $columna = isset($alias[$clave_normalizada]) ? $alias[$clave_normalizada] : null;

            if (!is_null($columna) && in_array($columna, $columnas_que_faltan, true)) {

                unset($datos[$clave]);
            }
        }

        $llamada = ['entidad' => $declaracion['entidad'], 'datos' => (object) $datos];

        foreach (AltaDeArticuloConFotoIaHelper::claves() as $clave) {

            if (array_key_exists($clave, $extras)) {

                $llamada[$clave] = $extras[$clave];
            }
        }

        if (is_numeric($reemplaza_a) && (int) $reemplaza_a > 0) {

            $llamada['reemplaza_a'] = (int) $reemplaza_a;
        }

        return $llamada;
    }

    /**
     * Una respuesta negativa con `opciones.reenvia_igual` sumado (si hay qué reenviar).
     *
     * @param  array  $respuesta
     * @param  array  $reenvia_igual
     * @return array
     */
    protected static function con_reenvio(array $respuesta, array $reenvia_igual): array
    {
        if (!count($reenvia_igual)) {

            return $respuesta;
        }

        $opciones = isset($respuesta['opciones']) ? json_decode(json_encode($respuesta['opciones']), true) : [];

        $opciones = is_array($opciones) ? $opciones : [];

        $opciones['reenvia_igual'] = $reenvia_igual;

        $respuesta['opciones'] = $opciones;

        if (isset($respuesta['error']) && is_string($respuesta['error'])) {

            $respuesta['error'] .= ' Al volver a llamar, mandá EXACTAMENTE los mismos datos y extras de opciones.reenvia_igual (la foto, la descripción, el código, el stock: todo).';
        }

        return $respuesta;
    }

    /**
     * La categoría y la subcategoría de la edición de un artículo:
     *
     *  - Con subcategoría pedida: si la edición cambia la categoría, se resuelve dentro de la nueva;
     *    si la vacía a propósito, se deduce de la subcategoría; si no la toca, se busca primero en la
     *    categoría actual del artículo y, si no está ahí, entre todas (y la categoría cambia a la de
     *    la subcategoría, con su renglón en la tarjeta).
     *  - Si cambia la categoría y la subcategoría actual no cuelga de la nueva (o la categoría queda
     *    vacía), la subcategoría se limpia y el aviso lo dice.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $validado
     * @param  mixed  $subcategoria_pedida
     * @param  object  $fila  El artículo como está.
     * @return array  ['validado' => ..., 'aviso' => string|null] o la respuesta negativa.
     */
    protected static function categoria_de_la_edicion_de_articulo(ContextoDeCargaIa $contexto, array $validado, $subcategoria_pedida, $fila)
    {
        $payload = $validado['payload'];
        $aviso = null;

        $cambia_la_categoria = array_key_exists('category_id', $payload);
        $categoria_nueva = $cambia_la_categoria && !self::vacio($payload['category_id']) ? (int) $payload['category_id'] : null;
        $categoria_actual = isset($fila->category_id) && !self::vacio($fila->category_id) ? (int) $fila->category_id : null;
        $sub_actual = isset($fila->sub_category_id) && !self::vacio($fila->sub_category_id) ? (int) $fila->sub_category_id : null;

        if (!is_null($subcategoria_pedida)) {

            if ($cambia_la_categoria) {

                $sub = CategoriasDelNegocioIaHelper::resolver_subcategoria($contexto->owner_id, $categoria_nueva, $subcategoria_pedida);

            } else {

                $sub = CategoriasDelNegocioIaHelper::resolver_subcategoria($contexto->owner_id, $categoria_actual, $subcategoria_pedida, true);
            }

            if (RespuestaDeCargaIa::es_negativa($sub)) {

                return $sub;
            }

            $validado = self::con_campo($validado, 'sub_category_id', $sub['sub_category_id'], $sub['subcategoria']);

            $categoria_que_queda = $cambia_la_categoria ? $categoria_nueva : $categoria_actual;

            if (!is_null($sub['category_id']) && $sub['category_id'] !== $categoria_que_queda) {

                $validado = self::con_campo($validado, 'category_id', $sub['category_id'], $sub['categoria']);
            }

            return ['validado' => $validado, 'aviso' => $aviso];
        }

        if ($cambia_la_categoria && !array_key_exists('sub_category_id', $payload) && !is_null($sub_actual)
            && (is_null($categoria_nueva) || !CategoriasDelNegocioIaHelper::es_de_la_categoria($sub_actual, $categoria_nueva))) {

            $nombre_sub = CategoriasDelNegocioIaHelper::nombre_de_subcategoria($sub_actual);

            $validado = self::con_campo($validado, 'sub_category_id', null, null);

            $aviso = 'La subcategoría "'.(is_null($nombre_sub) ? '#'.$sub_actual : $nombre_sub).'" no es de la categoría nueva: se le saca la subcategoría.';
        }

        return ['validado' => $validado, 'aviso' => $aviso];
    }

    /**
     * Pone un campo resuelto en lo validado (payload, pedidos y el nombre para el renglón).
     *
     * @param  array  $validado
     * @param  string  $columna
     * @param  mixed  $valor
     * @param  string|null  $nombre
     * @return array
     */
    protected static function con_campo(array $validado, string $columna, $valor, $nombre): array
    {
        $validado['payload'][$columna] = $valor;
        $validado['pedidos'][$columna] = $valor;

        if (!isset($validado['nombres']) || !is_array($validado['nombres'])) {

            $validado['nombres'] = [];
        }

        if (is_null($nombre)) {

            unset($validado['nombres'][$columna]);

        } else {

            $validado['nombres'][$columna] = $nombre;
        }

        return $validado;
    }

    /**
     * Saca un campo de lo validado.
     *
     * @param  array  $validado
     * @param  string  $columna
     * @return array
     */
    protected static function sin_campo(array $validado, string $columna): array
    {
        unset($validado['payload'][$columna], $validado['pedidos'][$columna]);

        if (isset($validado['nombres']) && is_array($validado['nombres'])) {

            unset($validado['nombres'][$columna]);
        }

        return $validado;
    }

    // -------------------------------------------------------------------------------------------
    // Edición
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_edicion.
     *
     * Misión alta-por-agente-margen-y-stock (29/9/2026): en un artículo de un negocio con listas de
     * precio, `$margenes_por_lista` cambia el margen de cada lista nombrada ("cambiale el margen de
     * la general a 35"). Con eso `cambios` puede venir vacío. La tarjeta muestra "antes → después"
     * por lista, y al confirmar EjecutorGenericoIaHelper lo manda como el `price_types` de la ficha.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  mixed  $entidad
     * @param  mixed  $registro  Id (si otra herramienta lo devolvió), nombre, o número para ventas y gastos.
     * @param  array  $cambios  [campo => valor nuevo]
     * @param  mixed  $reemplaza_a
     * @param  mixed  $margenes_por_lista  Solo artículo: [{lista, margen}], o null si no vino.
     * @return array
     */
    public static function proponer_edicion(ContextoDeCargaIa $contexto, AiMessage $mensaje, $entidad, $registro, array $cambios, $reemplaza_a = null, $margenes_por_lista = null)
    {
        $declaracion = self::entidad_para($contexto, $entidad, Catalogo::OP_EDICION);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        $es_articulo = $declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD;

        /*
         * El modelo a veces mete margenes_por_lista adentro de `cambios` (como hace con los extras
         * del alta): en un artículo se saca de ahí, porque validar_campos() lo rechazaría como "el
         * campo no existe", que es verdad para la pantalla pero no para esta tarjeta.
         */
        if ($es_articulo && array_key_exists(MargenesPorListaIaHelper::CLAVE, $cambios)) {

            if (is_null($margenes_por_lista)) {

                $margenes_por_lista = $cambios[MargenesPorListaIaHelper::CLAVE];
            }

            unset($cambios[MargenesPorListaIaHelper::CLAVE]);
        }

        /*
         * 🔴 VACÍO ES "NO VINO", para cualquier entidad (ronda de correcciones del 29/9/2026): null,
         * "", [] y el texto "[]". Un modelo que manda `margenes_por_lista: []` al cambiar el teléfono
         * de un cliente no está pidiendo nada de listas, y antes eso cortaba con "sólo para el margen
         * de un artículo" (o con "no trabaja con listas" en un artículo sin listas). La única lectura
         * distinta: con reemplaza_a en un artículo, el vacío EXPLÍCITO saca los márgenes heredados.
         */
        $vacio_explicito = !is_null($margenes_por_lista) && MargenesPorListaIaHelper::vino_vacio($margenes_por_lista);

        if (MargenesPorListaIaHelper::vino_vacio($margenes_por_lista)) {

            $margenes_por_lista = null;
        }

        $vinieron_margenes = !is_null($margenes_por_lista);

        if ($vinieron_margenes && !$es_articulo) {

            return RespuestaDeCargaIa::error(MargenesPorListaIaHelper::NO_SE_CARGO.'margenes_por_lista es sólo para el margen de un artículo en cada lista de precio. Volvé a llamar sin eso.');
        }

        $fila = self::ubicar($contexto, $declaracion, $registro);

        if (RespuestaDeCargaIa::es_negativa($fila)) {

            return $fila;
        }

        /*
         * 🔴 UNA CORRECCIÓN DE UNA EDICIÓN CON MÁRGENES LOS SUMA, NO LOS PISA (ronda de correcciones
         * del 29/9/2026): mismo criterio que el alta. Solo de una edición del MISMO artículo que siga
         * viva (propuesta o recién reemplazada); lo nuevo gana por lista y `[]` explícito los saca.
         */
        if ($es_articulo && !$vacio_explicito) {

            $heredados = self::margenes_de_la_edicion_reemplazada($contexto, $reemplaza_a, (int) $fila->id);

            if (count($heredados)) {

                $margenes_por_lista = MargenesPorListaIaHelper::mezclar_con_heredados($margenes_por_lista, $heredados);

                $vinieron_margenes = !is_null($margenes_por_lista);
            }
        }

        if (!count($cambios) && !$vinieron_margenes) {

            return RespuestaDeCargaIa::error('No mandaste ningún cambio. Decime qué campo cambia y a qué valor.');
        }

        // La subcategoría se resuelve con la categoría que queda (misma regla que el alta, D5).
        $subcategoria_pedida = null;

        if ($es_articulo) {

            list($cambios, $subcategoria_pedida) = self::separar_categoria_del_articulo($declaracion, $cambios);
        }

        $validado = self::validar_campos($contexto, $declaracion, Catalogo::OP_EDICION, $cambios);

        if (RespuestaDeCargaIa::es_negativa($validado)) {

            return $validado;
        }

        /*
         * 🔴 La misma guarda que el alta (misión alta-por-agente-margen-y-stock, 29/9/2026): con
         * listas, "cambiale el margen a 35" mandado como percentage_gain (o un precio manual, o
         * "aplica el margen del proveedor") cambiaría una columna que no mueve ningún precio de
         * lista, y la tarjeta diría "Margen de ganancia: 0 → 35 %" sobre un cambio que la persona no
         * va a ver en ningún precio. Ver MargenesPorListaIaHelper::guarda_de_campos_escondidos().
         */
        if ($es_articulo) {

            $escondido = MargenesPorListaIaHelper::guarda_de_campos_escondidos($contexto, $validado['payload']);

            if (!is_null($escondido)) {

                return $escondido;
            }
        }

        /*
         * Misión asistente-altas-y-conversacion (16/10/2026, D5, demo3 S11): la categoría y la
         * subcategoría de un artículo quedan coherentes. El martillo quedó con la categoría nueva y la
         * subcategoría vacía aunque existía "Herramientas Manuales"; y al revés, cambiar la categoría
         * dejaba colgada una subcategoría de otra. Ver categoria_de_la_edicion_de_articulo().
         */
        $aviso_de_la_categoria = null;

        if ($es_articulo) {

            $coherente = self::categoria_de_la_edicion_de_articulo($contexto, $validado, $subcategoria_pedida, $fila);

            if (RespuestaDeCargaIa::es_negativa($coherente)) {

                return $coherente;
            }

            $validado = $coherente['validado'];
            $aviso_de_la_categoria = $coherente['aviso'];
        }

        $nombre_actual = Catalogo::nombre_de_fila($declaracion['entidad'], $fila);

        $renglones = [];
        $cambios_reales = [];
        $pedidos = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (!array_key_exists($columna, $validado['payload'])) {

                continue;
            }

            $antes = isset($fila->{$columna}) ? $fila->{$columna} : null;
            $despues = $validado['payload'][$columna];

            if (self::iguales($campo, $antes, $despues)) {

                continue;
            }

            $cambios_reales[$columna] = $despues;
            $pedidos[$columna] = $validado['pedidos'][$columna];

            $renglones[] = [
                'etiqueta' => Str::ucfirst($campo['etiqueta']),
                'valor'    => Catalogo::valor_legible($campo, $antes).' → '.Catalogo::valor_legible($campo, $despues),
            ];
        }

        /*
         * El margen por lista: solo las listas que cambian de verdad van a la tarjeta (y al
         * payload). Una lista con precio fijo en el pivote cuenta como cambio: pasa a margen.
         */
        $margenes_que_cambian = [];

        /** El aviso de la tarjeta de edición (hoy: márgenes heredados de listas borradas), o null. */
        $aviso_de_la_edicion = null;

        if ($vinieron_margenes) {

            $descartados = [];

            $margenes = MargenesPorListaIaHelper::resolver($contexto, $margenes_por_lista, $descartados);

            // Lo heredado de una lista que ya no existe se descartó: la tarjeta lo dice.
            $aviso_de_la_edicion = MargenesPorListaIaHelper::aviso_de_descartados($descartados);

            if (RespuestaDeCargaIa::es_negativa($margenes)) {

                return $margenes;
            }

            // Vacío porque TODO lo heredado era de listas borradas: no es un error, lo dice el aviso.
            if (!count($margenes) && !count($descartados)) {

                return RespuestaDeCargaIa::error(MargenesPorListaIaHelper::NO_SE_CARGO.'margenes_por_lista vino vacío: decime qué lista cambia y a qué margen.');
            }

            $de_la_edicion = MargenesPorListaIaHelper::cambios_de_edicion($contexto, (int) $fila->id, $margenes);

            $margenes_que_cambian = $de_la_edicion['cambian'];

            foreach ($de_la_edicion['renglones'] as $renglon) {

                $renglones[] = $renglon;
            }
        }

        if (!count($cambios_reales) && !count($margenes_que_cambian)) {

            return RespuestaDeCargaIa::error(Str::ucfirst($declaracion['singular']).' "'.$nombre_actual.'" ya está así: no hay nada distinto para cambiar.');
        }

        // La subcategoría que se saca porque no es de la categoría nueva: la tarjeta y el modelo lo dicen.
        if (!is_null($aviso_de_la_categoria)) {

            $aviso_de_la_edicion = is_null($aviso_de_la_edicion) ? $aviso_de_la_categoria : $aviso_de_la_edicion.' '.$aviso_de_la_categoria;
        }

        // El updated_at del registro al armar la tarjeta: si alguien lo edita en el medio, confirmar da 409 (vencida).
        $referencia = isset($fila->updated_at) && !is_null($fila->updated_at) ? (string) $fila->updated_at : null;

        $datos_de_la_tarjeta = [
            'entidad'   => $declaracion['entidad'],
            'operacion' => Catalogo::OP_EDICION,
            'id'        => (int) $fila->id,
            'cambios'   => $cambios_reales,
            'pedidos'   => $pedidos,
        ];

        // Solo cuando los hay: una tarjeta de edición sin márgenes queda exactamente como antes.
        if (count($margenes_que_cambian)) {

            $datos_de_la_tarjeta[MargenesPorListaIaHelper::CLAVE] = $margenes_que_cambian;
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_EDICION,
            'edicion:'.$declaracion['entidad'].':'.(int) $fila->id,
            $datos_de_la_tarjeta,
            [
                'titulo'    => Catalogo::titulo($declaracion['entidad'], Catalogo::OP_EDICION, $nombre_actual),
                'renglones' => $renglones,
                'aviso'     => $aviso_de_la_edicion,
            ],
            $reemplaza_a,
            $referencia
        );

        $resumen = Catalogo::titulo($declaracion['entidad'], Catalogo::OP_EDICION, $nombre_actual).self::resumen_de_renglones($renglones, null);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, is_null($aviso_de_la_edicion) ? [] : ['aviso' => $aviso_de_la_edicion]);
    }

    /**
     * Los márgenes por lista de la EDICIÓN que esta corrige, ya resueltos, o []. Solo de una
     * tarjeta de edición viva (propuesta o recién reemplazada), de esta conversación y del MISMO
     * artículo: nunca de una confirmada, cancelada o vencida, ni de otro artículo (mismo criterio
     * que con_los_campos_de_la_tarjeta_reemplazada()).
     *
     * 🔴 SIN `reemplaza_a` TAMBIÉN (última ronda de correcciones, 29/9/2026). La tarjeta de edición
     * tiene clave `edicion:article:<id>` y AccionesIaHelper::crear() reemplaza SOLA a la propuesta
     * viva con la misma clave; el prompt y esquema_de_reemplazo() le dicen al modelo que en ese caso
     * no hace falta `reemplaza_a`. Entonces "cambiale la minorista a 35" y después "y la mayorista a
     * 20 también" llegaba sin reemplaza_a y la tarjeta nueva pisaba a la anterior perdiendo la
     * minorista. Sin reemplaza_a se hereda de EXACTAMENTE la que crear() va a reemplazar: la
     * propuesta viva de esta conversación con la misma clave.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $reemplaza_a
     * @param  int  $article_id
     * @return array
     */
    protected static function margenes_de_la_edicion_reemplazada(ContextoDeCargaIa $contexto, $reemplaza_a, int $article_id): array
    {
        $reemplaza_a = is_numeric($reemplaza_a) ? (int) $reemplaza_a : 0;

        if ($reemplaza_a > 0) {

            $anterior = AiMessageAction::where('id', $reemplaza_a)
                                        ->where('ai_conversation_id', $contexto->conversation->id)
                                        ->where('tipo', AiMessageAction::TIPO_EDICION)
                                        ->whereIn('estado', [AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::ESTADO_REEMPLAZADA])
                                        ->first();

        } else {

            // La misma clave que arma proponer_edicion() y el mismo filtro que crear(): la propuesta viva.
            $anterior = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
                                        ->where('tipo', AiMessageAction::TIPO_EDICION)
                                        ->where('clave', 'edicion:'.AltaDeArticuloConFotoIaHelper::ENTIDAD.':'.$article_id)
                                        ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                        ->orderBy('id', 'DESC')
                                        ->first();
        }

        if (is_null($anterior) || !is_array($anterior->datos)) {

            return [];
        }

        $datos = $anterior->datos;

        if (!isset($datos['entidad'], $datos['id']) || $datos['entidad'] !== AltaDeArticuloConFotoIaHelper::ENTIDAD || (int) $datos['id'] !== $article_id) {

            return [];
        }

        return isset($datos[MargenesPorListaIaHelper::CLAVE]) && is_array($datos[MargenesPorListaIaHelper::CLAVE])
            ? $datos[MargenesPorListaIaHelper::CLAVE]
            : [];
    }

    // -------------------------------------------------------------------------------------------
    // Baja
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_baja.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  mixed  $entidad
     * @param  mixed  $registro
     * @param  mixed  $reemplaza_a
     * @return array
     */
    public static function proponer_baja(ContextoDeCargaIa $contexto, AiMessage $mensaje, $entidad, $registro, $reemplaza_a = null)
    {
        $declaracion = self::entidad_para($contexto, $entidad, Catalogo::OP_BAJA);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        $fila = self::ubicar($contexto, $declaracion, $registro);

        if (RespuestaDeCargaIa::es_negativa($fila)) {

            return $fila;
        }

        $nombre = Catalogo::nombre_de_fila($declaracion['entidad'], $fila);

        /*
         * El aviso de una baja lo arma el catálogo: el texto fijo de la entidad, más si la baja es
         * DEFINITIVA (el modelo no usa SoftDeletes), más cuántas filas del dueño van a quedar
         * apuntando a un registro que ya no existe. Ver Catalogo::aviso_de_baja(): es el arreglo
         * del 🔴 4 del chequeo adversarial, donde borrar una lista de precios dejaba 30 clientes
         * con un `price_type_id` inexistente mientras la tarjeta lo contaba como algo reversible.
         */
        $aviso = Catalogo::aviso_de_baja($declaracion, $fila, $contexto->owner_id);

        if ($aviso === '') {

            $aviso = 'Se borra del sistema. Lo que dependa de esto puede dejar de verse.';
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_BAJA,
            'baja:'.$declaracion['entidad'].':'.(int) $fila->id,
            [
                'entidad'   => $declaracion['entidad'],
                'operacion' => Catalogo::OP_BAJA,
                'id'        => (int) $fila->id,
                'nombre'    => $nombre,
            ],
            [
                'titulo'    => Catalogo::titulo($declaracion['entidad'], Catalogo::OP_BAJA, $nombre),
                'renglones' => self::renglones_de_baja($declaracion, $fila),
                'aviso'     => $aviso,
            ],
            $reemplaza_a
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, Catalogo::titulo($declaracion['entidad'], Catalogo::OP_BAJA, $nombre), [
            'aviso' => $aviso,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Permisos y entidad
    // -------------------------------------------------------------------------------------------

    /**
     * La declaración de la entidad si existe, admite la operación, el comercio tiene su extensión
     * y la persona tiene permiso; si no, la respuesta negativa.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $entidad
     * @param  string  $operacion
     * @return array
     */
    public static function entidad_para(ContextoDeCargaIa $contexto, $entidad, string $operacion)
    {
        $entidad = is_scalar($entidad) ? trim((string) $entidad) : '';

        if ($entidad === '') {

            return RespuestaDeCargaIa::faltan(['qué entidad es (mirá que_puedo_cargar)']);
        }

        $declaracion = Catalogo::declaracion($entidad);

        if (is_null($declaracion)) {

            return RespuestaDeCargaIa::error(
                'La entidad "'.$entidad.'" no se puede cargar por acá.',
                ['entidades' => array_keys(Catalogo::entidades())]
            );
        }

        if (!isset($declaracion['operaciones'][$operacion])) {

            return RespuestaDeCargaIa::error(
                Str::ucfirst($declaracion['etiqueta']).': '.self::verbo($operacion).' no se puede por acá'.self::porque_no($declaracion['entidad'], $operacion).'.'
            );
        }

        if (!is_null($declaracion['extension']) && !PermisosIaHelper::tiene_extencion($contexto->owner, $declaracion['extension'])) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_extencion($declaracion['etiqueta']));
        }

        if (!self::puede($contexto->persona, $declaracion['entidad'], $operacion)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso($declaracion['etiqueta']));
        }

        return $declaracion;
    }

    /**
     * true si la persona puede hacer la operación sobre la entidad: dueño o admin_access siempre;
     * si no, el permiso de la pantalla (`entidad.store` para crear —el mismo formulario edita, así
     * que también vale para editar—, `entidad.update` si existiera, `entidad.delete` para borrar).
     *
     * @param  \App\Models\User|null  $persona
     * @param  string  $entidad
     * @param  string  $operacion
     * @return bool
     */
    public static function puede($persona, string $entidad, string $operacion): bool
    {
        if (PermisosIaHelper::es_admin($persona)) {

            return true;
        }

        switch ($operacion) {

            case Catalogo::OP_ALTA:
                return PermisosIaHelper::puede($persona, $entidad.'.store');

            case Catalogo::OP_EDICION:
                return PermisosIaHelper::puede($persona, $entidad.'.update') || PermisosIaHelper::puede($persona, $entidad.'.store');

            case Catalogo::OP_BAJA:
                return PermisosIaHelper::puede($persona, $entidad.'.delete');
        }

        return false;
    }

    // -------------------------------------------------------------------------------------------
    // Ubicar un registro
    // -------------------------------------------------------------------------------------------

    /**
     * El registro del dueño que nombra `$registro`, o la respuesta negativa.
     *
     *   - Un número es el id si la entidad tiene nombre (los ids los devuelven otras herramientas);
     *     si la entidad se identifica por número (ventas, gastos), es ese número.
     *   - Un texto se busca con LIKE en la columna de nombre, por dueño. Con varias coincidencias
     *     gana la exacta; si no hay exacta, `faltan` con las opciones.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  mixed  $registro
     * @return object|array
     */
    public static function ubicar(ContextoDeCargaIa $contexto, array $declaracion, $registro)
    {
        $etiqueta = $declaracion['singular'];

        if (is_null($registro) || (is_string($registro) && trim($registro) === '')) {

            return RespuestaDeCargaIa::faltan(['qué '.$etiqueta.' es']);
        }

        if (is_array($registro)) {

            return RespuestaDeCargaIa::error('El registro tiene que venir como texto o como número.');
        }

        $registro = is_string($registro) ? trim($registro) : $registro;

        if (is_numeric($registro) && (string) (int) $registro === (string) $registro) {

            $numero = (int) $registro;

            if (is_null($declaracion['columna_nombre']) && !is_null($declaracion['columna_numero'])) {

                $fila = self::consulta_del_dueno($contexto, $declaracion)->where($declaracion['columna_numero'], $numero)->first();

                if (is_null($fila)) {

                    return RespuestaDeCargaIa::error('No encontré '.($declaracion['genero'] === 'f' ? 'la ' : 'el ').$etiqueta.' N° '.$numero.' entre '.($declaracion['genero'] === 'f' ? 'las tuyas' : 'los tuyos').'.');
                }

                return self::verificar_fila($declaracion, $fila);
            }

            $fila = self::fila_del_dueno($contexto->owner_id, $declaracion, $numero);

            if (is_null($fila)) {

                return RespuestaDeCargaIa::error('No encontré '.($declaracion['genero'] === 'f' ? 'esa ' : 'ese ').$etiqueta.' entre '.($declaracion['genero'] === 'f' ? 'las tuyas' : 'los tuyos').'.');
            }

            return self::verificar_fila($declaracion, $fila);
        }

        if (is_null($declaracion['columna_nombre'])) {

            return RespuestaDeCargaIa::error(
                Str::ucfirst($declaracion['etiqueta']).' se ubican por su número, no por un texto: decime el número '.($declaracion['genero'] === 'f' ? 'de la ' : 'del ').$etiqueta.'.'
            );
        }

        $texto = (string) $registro;

        $columna = $declaracion['columna_nombre'];

        /*
         * Misión asistente-altas-y-conversacion (chequeo independiente, 16/10/2026, D6): la frase
         * entera O cada palabra del nombre ("ducha gloa" encuentra "DUCHA DE MANO METAL GLOA"), con
         * lo que tiene la frase primero. Solo la columna del nombre: ver BusquedaPorPalabrasIaHelper.
         */
        $consulta = BusquedaPorPalabrasIaHelper::filtrar(self::consulta_del_dueno($contexto, $declaracion), $texto, [$columna]);

        $candidatas = BusquedaPorPalabrasIaHelper::ordenar_por_frase($consulta, $texto, [$columna])
                            ->orderBy($columna)
                            ->limit(self::MAX_OPCIONES + 1)
                            ->get();

        if (!count($candidatas)) {

            return RespuestaDeCargaIa::error('No encontré '.($declaracion['genero'] === 'f' ? 'ninguna ' : 'ningún ').$etiqueta.' que se llame como "'.$texto.'".');
        }

        $con_la_frase = BusquedaPorPalabrasIaHelper::por_frase($candidatas, $texto, [$columna]);

        /*
         * 🔴 Lo que encaja SOLO por palabras nunca se toma sin preguntar, aunque sea uno: en una
         * edición o una baja, elegir mal es grave (y "pinza 10" encaja por palabras con "pinza 100").
         * Vuelve como `faltan` con las opciones, para que la persona diga cuál.
         */
        if (!count($con_la_frase)) {

            return RespuestaDeCargaIa::faltan(
                ['cuál de '.($declaracion['genero'] === 'f' ? 'estas ' : 'estos ').$declaracion['etiqueta'].' es: ninguno se llama exactamente "'.$texto.'", estos tienen esas palabras en el nombre'],
                [$declaracion['entidad'] => self::opciones_de_relacion($candidatas, $columna)]
            );
        }

        // Con la frase, lo de siempre: solo entre los que la tienen.
        $candidatas = $con_la_frase;

        if (count($candidatas) === 1) {

            return self::verificar_fila($declaracion, $candidatas[0]);
        }

        $exactas = [];

        foreach ($candidatas as $candidata) {

            if (mb_strtolower(trim((string) $candidata->{$columna})) === mb_strtolower($texto)) {

                $exactas[] = $candidata;
            }
        }

        if (count($exactas) === 1) {

            return self::verificar_fila($declaracion, $exactas[0]);
        }

        $opciones = [];

        foreach ($candidatas as $i => $candidata) {

            if ($i >= self::MAX_OPCIONES) {

                break;
            }

            $opciones[] = ['id' => (int) $candidata->id, 'nombre' => (string) $candidata->{$columna}];
        }

        return RespuestaDeCargaIa::faltan(
            ['cuál de '.($declaracion['genero'] === 'f' ? 'estas ' : 'estos ').$declaracion['etiqueta'].' es (hay '.count($candidatas).' que encajan con "'.$texto.'")'],
            [$declaracion['entidad'] => $opciones]
        );
    }

    /**
     * La fila de la entidad con ese id SI es del dueño (y no está borrada), o null. Es la guarda
     * de tenencia que los controllers de recurso no hacen (`Model::find($id)` pelado).
     *
     * @param  int  $owner_id
     * @param  array  $declaracion
     * @param  int  $id
     * @return object|null
     */
    public static function fila_del_dueno(int $owner_id, array $declaracion, int $id)
    {
        if ($id <= 0) {

            return null;
        }

        $consulta = DB::table($declaracion['tabla'])
                        ->where('id', $id)
                        ->where('user_id', $owner_id);

        if ($declaracion['tiene_deleted_at']) {

            $consulta->whereNull('deleted_at');
        }

        return $consulta->first();
    }

    /**
     * Guardas propias de algunas entidades sobre la fila ubicada: hoy, una venta contenedora de
     * consolidación de facturación no se anula por acá.
     *
     * @param  array  $declaracion
     * @param  object  $fila
     * @return object|array
     */
    protected static function verificar_fila(array $declaracion, $fila)
    {
        if ($declaracion['entidad'] === 'sale' && !empty($fila->is_consolidacion_facturacion)) {

            return RespuestaDeCargaIa::error('La venta N° '.$fila->num.' es una consolidación de facturación: se maneja desde Comprobantes, no se anula por acá.');
        }

        return $fila;
    }

    /**
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta_del_dueno(ContextoDeCargaIa $contexto, array $declaracion)
    {
        $consulta = DB::table($declaracion['tabla'])->where('user_id', $contexto->owner_id);

        if ($declaracion['tiene_deleted_at']) {

            $consulta->whereNull('deleted_at');
        }

        return $consulta;
    }

    // -------------------------------------------------------------------------------------------
    // Validación de campos
    // -------------------------------------------------------------------------------------------

    /**
     * Valida y coerciona los campos que mandó el modelo para una operación.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  string  $operacion
     * @param  array  $valores  [clave => valor], clave = columna, etiqueta o nombre de la relación.
     * @return array  ['payload' => [columna => valor para el controller], 'pedidos' => [columna => valor normalizado],
     *                 'nombres' => [columna => nombre de la relación]] o la respuesta negativa.
     */
    public static function validar_campos(ContextoDeCargaIa $contexto, array $declaracion, string $operacion, array $valores)
    {
        $payload = [];
        $pedidos = [];
        $nombres = [];

        $alias = self::alias_de_campos($declaracion);

        foreach ($valores as $clave => $valor) {

            $clave_normalizada = self::normalizar_clave((string) $clave);

            if ($clave_normalizada === 'reemplaza_a') {

                continue;
            }

            if (!isset($alias[$clave_normalizada])) {

                return RespuestaDeCargaIa::error(
                    'El campo "'.$clave.'" no existe para '.$declaracion['etiqueta'].'. Los campos válidos son: '.implode(', ', array_keys($declaracion['campos'])).'.',
                    ['campos' => self::campos_para_ofrecer($declaracion)]
                );
            }

            $columna = $alias[$clave_normalizada];

            $campo = $declaracion['campos'][$columna];

            if (!in_array($operacion, $campo['operaciones'], true)) {

                $verbo = $operacion === Catalogo::OP_ALTA ? 'al crear' : 'al editar';
                $otro = $operacion === Catalogo::OP_ALTA ? 'editándol'.($declaracion['genero'] === 'f' ? 'a' : 'o').' después' : 'solo al crear';

                return RespuestaDeCargaIa::error(
                    'El campo "'.$campo['etiqueta'].'" no se carga '.$verbo.' '.($declaracion['genero'] === 'f' ? 'una ' : 'un ').$declaracion['singular'].' desde la pantalla ('.$otro.').'
                );
            }

            $resultado = self::coercionar($contexto, $declaracion, $campo, $valor);

            if (RespuestaDeCargaIa::es_negativa($resultado)) {

                return $resultado;
            }

            $payload[$columna] = $resultado['valor'];
            $pedidos[$columna] = $resultado['valor'];

            if (isset($resultado['nombre'])) {

                $nombres[$columna] = $resultado['nombre'];
            }
        }

        return ['payload' => $payload, 'pedidos' => $pedidos, 'nombres' => $nombres];
    }

    /**
     * Un valor coercionado al tipo del campo: ['valor' => ..., 'nombre' => ...] o la respuesta negativa.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  array  $campo
     * @param  mixed  $valor
     * @return array
     */
    protected static function coercionar(ContextoDeCargaIa $contexto, array $declaracion, array $campo, $valor)
    {
        $etiqueta = $campo['etiqueta'];

        if (is_array($valor) || is_object($valor)) {

            return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" tiene que ser un valor simple, no una lista.');
        }

        if (self::vacio($valor)) {

            if ($campo['obligatorio']) {

                return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" no puede quedar vacío.');
            }

            return ['valor' => null];
        }

        if (!is_null($campo['relacion'])) {

            return self::resolver_relacion($contexto, $declaracion, $campo, $valor);
        }

        switch ($campo['tipo']) {

            case 'number':
                $numero = self::a_numero($valor);

                if (is_null($numero)) {

                    return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" tiene que ser un número.');
                }

                return ['valor' => $numero];

            case 'checkbox':
                $booleano = self::a_booleano($valor);

                if (is_null($booleano)) {

                    return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" es sí o no.');
                }

                return ['valor' => $booleano ? 1 : 0];

            case 'date':
                $fecha = self::a_fecha($valor);

                if (is_null($fecha)) {

                    return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" tiene que venir como AAAA-MM-DD.');
                }

                return ['valor' => $fecha];
        }

        return ['valor' => trim((string) $valor)];
    }

    /**
     * Resuelve una relación por id (si vino un número y existe para el dueño) o por nombre.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  array  $campo
     * @param  mixed  $valor
     * @return array
     */
    protected static function resolver_relacion(ContextoDeCargaIa $contexto, array $declaracion, array $campo, $valor)
    {
        $relacion = $campo['relacion'];
        $etiqueta = $campo['etiqueta'];

        if ($relacion['tabla'] === 'monedas') {

            $moneda_id = EntradaDeCargaIa::moneda($contexto, $valor);

            if (is_array($moneda_id)) {

                return $moneda_id;
            }

            return ['valor' => $moneda_id, 'nombre' => Catalogo::MONEDAS[$moneda_id]];
        }

        $consulta_base = function () use ($contexto, $relacion) {

            $consulta = DB::table($relacion['tabla']);

            if ($relacion['tabla'] === 'users') {

                // Los empleados del dueño, y el dueño mismo.
                $consulta->where(function ($q) use ($contexto) {
                    $q->where('owner_id', $contexto->owner_id)->orWhere('id', $contexto->owner_id);
                });

            } elseif (self::tabla_tiene($relacion['tabla'], 'user_id')) {

                $consulta->where('user_id', $contexto->owner_id);
            }

            if (self::tabla_tiene($relacion['tabla'], 'deleted_at')) {

                $consulta->whereNull('deleted_at');
            }

            return $consulta;
        };

        $texto = is_string($valor) ? trim($valor) : $valor;

        // Un número es un id que devolvió otra herramienta (o el número que la persona dijo, para
        // relaciones que se identifican así, como la alícuota de IVA).
        if (is_numeric($texto)) {

            $fila = $consulta_base()->where('id', (int) $texto)->first();

            if (!is_null($fila)) {

                return ['valor' => (int) $fila->id, 'nombre' => (string) $fila->{$relacion['campo']}];
            }
        }

        $texto = (string) $texto;

        /*
         * 🔴 LAS MARCAS SE RESUELVEN APARTE (misión asistente-arreglos-segunda-prueba, 19/10/2026, N6 y
         * la revisión independiente): ver resolver_marca(). Solo `brands`: las demás relaciones (un
         * proveedor, una localidad, un vendedor) siguen con el LIKE y la pregunta de siempre, porque
         * crear uno de esos por un nombre mal escrito deja un registro que nadie pidió.
         */
        if ($relacion['tabla'] === 'brands') {

            return self::resolver_marca($contexto, $campo, $etiqueta, $texto, $consulta_base);
        }

        $candidatas = $consulta_base()
                        ->where($relacion['campo'], 'LIKE', '%'.self::escapar_like($texto).'%')
                        ->orderBy($relacion['campo'])
                        ->limit(self::MAX_OPCIONES + 1)
                        ->get();

        if (!count($candidatas)) {

            $opciones = [$campo['columna'] => self::opciones_de_relacion($consulta_base()->orderBy($relacion['campo'])->limit(self::MAX_OPCIONES)->get(), $relacion['campo'])];

            return RespuestaDeCargaIa::faltan(
                ['qué '.$etiqueta.' es: no encontré ninguna que se llame como "'.$texto.'"'],
                $opciones
            );
        }

        if (count($candidatas) === 1) {

            return ['valor' => (int) $candidatas[0]->id, 'nombre' => (string) $candidatas[0]->{$relacion['campo']}];
        }

        $exactas = [];

        foreach ($candidatas as $candidata) {

            if (mb_strtolower(trim((string) $candidata->{$relacion['campo']})) === mb_strtolower($texto)) {

                $exactas[] = $candidata;
            }
        }

        if (count($exactas) === 1) {

            return ['valor' => (int) $exactas[0]->id, 'nombre' => (string) $exactas[0]->{$relacion['campo']}];
        }

        return RespuestaDeCargaIa::faltan(
            ['cuál '.$etiqueta.' es (hay '.count($candidatas).' que encajan con "'.$texto.'")'],
            [$campo['columna'] => self::opciones_de_relacion($candidatas, $relacion['campo'])]
        );
    }

    /**
     * La marca de una carga, resuelta por nombre CONTRA LAS MARCAS DEL DUEÑO (revisión independiente
     * de la misión asistente-arreglos-segunda-prueba, 19/10/2026). Hasta la revisión iba por el LIKE
     * genérico, que con UNA sola coincidencia parcial la asignaba sola: «ZZAtlas» terminaba en
     * «ZZAtlas Copco». Y como desde N6 una marca que no está se manda a crear, había que distinguir
     * bien "no está" de "se parece a una que está". El orden:
     *
     *   1. IGUAL sin importar mayúsculas, tildes ni espacios de más (normalizar_nombre()): una sola →
     *      se asigna; varias (el negocio tiene «3M» y «3m») → `faltan` con esas.
     *   2. PARECIDAS: marcas que contienen lo pedido («ZZAtlas» → «ZZAtlas Copco») o que lo pedido
     *      contiene como palabras enteras («ZZPhilips LED» → «ZZPhilips»). → `faltan` ofreciéndolas,
     *      SIN `si_no_esta`: primero se pregunta si es una de ésas; `si_no_es_ninguna` dice qué hacer
     *      si la persona contesta que no.
     *   3. Ninguna → marca_que_no_esta() (crearla, o esperar la tarjeta que ya la propuso).
     *
     * 🔴 Siempre por `$consulta_base`, que filtra por el dueño: la base puede ser compartida entre
     * varios negocios y la marca de otro no existe para éste. Se traen todas (hasta TOPE_DE_MARCAS)
     * para comparar normalizado en PHP: el LIKE no ve los espacios de más ni el "al revés".
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $campo  La declaración del campo (`brand_id`).
     * @param  string  $etiqueta  "marca".
     * @param  string  $texto  El nombre que llegó.
     * @param  callable  $consulta_base  La consulta de `brands` del dueño (resolver_relacion()).
     * @return array  ['valor' => id, 'nombre' => ...] o la respuesta negativa.
     */
    protected static function resolver_marca(ContextoDeCargaIa $contexto, array $campo, $etiqueta, $texto, callable $consulta_base)
    {
        $columna_nombre = $campo['relacion']['campo'];

        $buscado = self::normalizar_nombre($texto);

        /* Para la comparación EXACTA: sin signos ni espacios (ver clave_de_marca()). */
        $clave_buscada = self::clave_de_marca($texto);

        $marcas = $consulta_base()->orderBy($columna_nombre)->limit(self::TOPE_DE_MARCAS)->get(['id', $columna_nombre])->all();

        $exactas = [];
        $parecidas = [];

        foreach ($marcas as $marca) {

            $nombre = self::normalizar_nombre((string) $marca->{$columna_nombre});

            if ($nombre === '' || $buscado === '') {

                continue;
            }

            if ($clave_buscada !== '' && self::clave_de_marca((string) $marca->{$columna_nombre}) === $clave_buscada) {

                $exactas[] = $marca;

                continue;
            }

            /*
             * Las dos direcciones, por PALABRA completa (segunda revisión independiente): con un pedazo
             * de palabra «lg» se parecía a «BelgicaP85».
             */
            $la_contiene = preg_match('/(^| )'.preg_quote($buscado, '/').'( |$)/u', $nombre) === 1;

            $esta_adentro = preg_match('/(^| )'.preg_quote($nombre, '/').'( |$)/u', $buscado) === 1;

            if ($la_contiene || $esta_adentro) {

                $parecidas[] = $marca;
            }
        }

        if (count($exactas) === 1) {

            return ['valor' => (int) $exactas[0]->id, 'nombre' => (string) $exactas[0]->{$columna_nombre}];
        }

        if (count($exactas) > 1) {

            return RespuestaDeCargaIa::faltan(
                ['cuál '.$etiqueta.' es (hay '.count($exactas).' que se llaman "'.$texto.'")'],
                [$campo['columna'] => self::opciones_de_relacion($exactas, $columna_nombre)]
            );
        }

        /*
         * 🔴 LA MARCA YA PROPUESTA EN UNA TARJETA SE MIRA ANTES QUE LAS PARECIDAS (segunda revisión
         * independiente, 19/10/2026). En "cauteloso", con «ZZPhilips» cargada y el dueño pidiendo
         * «ZZPhilips LED»: parecida → el dueño dice que no → el modelo propone la marca nueva (queda
         * tarjeta) → reenvía el alta → acá volvía a salir «hay una parecida» con si_no_es_ninguna, y el
         * modelo proponía la marca otra vez: un loop hasta el tope de herramientas. Si lo pedido ya
         * está en una tarjeta de alta de marca sin confirmar, va el mismo error que en
         * marca_que_no_esta(): que la persona la confirme primero.
         */
        if (self::alta_de_marca_pendiente($contexto, $texto)) {

            return self::error_de_marca_pendiente($texto);
        }

        $opciones = [$campo['columna'] => self::opciones_de_relacion(array_slice($marcas, 0, self::MAX_OPCIONES), $columna_nombre)];

        /*
         * Un NÚMERO que no resolvió como id del dueño (resolver_relacion() ya lo buscó) ni como nombre
         * exacto: es el id de una marca de otro negocio de la base compartida, o uno inventado. Mandar a
         * crear la marca «305» sería peor que preguntar (segunda revisión independiente): se pide el
         * nombre, sin si_no_esta.
         */
        if (preg_match('/^\d+$/', trim((string) $texto)) === 1) {

            return RespuestaDeCargaIa::faltan(
                ['qué '.$etiqueta.' es, por su nombre: la '.$etiqueta.' N° '.trim((string) $texto).' no existe en este negocio'],
                $opciones
            );
        }

        if (count($parecidas)) {

            /* Con tope (segunda revisión independiente): se ofrecen las primeras MAX_OPCIONES y el texto lo dice. */
            $cuantas = count($parecidas);

            $parecidas = array_slice($parecidas, 0, self::MAX_OPCIONES);

            return RespuestaDeCargaIa::faltan(
                ['cuál '.$etiqueta.' es: ninguna se llama "'.$texto.'", pero hay '
                    .($cuantas === 1 ? 'una parecida' : $cuantas.' parecidas')
                    .($cuantas > self::MAX_OPCIONES ? ' (te paso las primeras '.self::MAX_OPCIONES.')' : '')],
                [
                    $campo['columna']   => self::opciones_de_relacion($parecidas, $columna_nombre),
                    'si_no_es_ninguna'  => self::SI_NO_ES_NINGUNA_LA_MARCA,
                ]
            );
        }

        return self::marca_que_no_esta($contexto, $etiqueta, $texto, $opciones);
    }

    /**
     * La marca del dueño que se llama igual que `$nombre` (sin mayúsculas, tildes ni espacios de
     * más), o null. La usa proponer_alta() para no crear una marca que ya existe. Filtra por el dueño:
     * la base puede ser compartida.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @return object|null  La fila de `brands` (id, name).
     */
    protected static function marca_con_el_mismo_nombre(ContextoDeCargaIa $contexto, $nombre)
    {
        $buscado = self::clave_de_marca($nombre);

        if ($buscado === '') {

            return null;
        }

        $marcas = DB::table('brands')
                    ->where('user_id', $contexto->owner_id)
                    ->orderBy('id')
                    ->limit(self::TOPE_DE_MARCAS)
                    ->get(['id', 'name']);

        foreach ($marcas as $marca) {

            if (self::clave_de_marca((string) $marca->name) === $buscado) {

                return $marca;
            }
        }

        return null;
    }

    /**
     * Un nombre para comparar: sin tildes (Str::ascii), en minúsculas y con los espacios colapsados.
     * «  ZZÁrbol   Copco » y «zzarbol copco» son el mismo. Es la forma POR PALABRAS, para las
     * parecidas; la igualdad se mide con clave_de_marca().
     *
     * @param  string  $nombre
     * @return string
     */
    protected static function normalizar_nombre($nombre)
    {
        $limpio = Str::lower(Str::ascii(trim((string) $nombre)));

        /*
         * Los signos cuentan como separadores de palabra («ZZAtlas-Copco» tiene las palabras zzatlas y
         * copco): las parecidas se miden por palabra completa (segunda revisión independiente).
         */
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $limpio));
    }

    /**
     * La clave con la que dos marcas son LA MISMA: sin tildes, en minúsculas y sin ningún signo ni
     * espacio (solo letras y dígitos). Segunda revisión independiente (19/10/2026): en "directo" se
     * crearon «ZZPhilips.», «zz 3m» y «ZZ-3M» con «ZZPhilips» y «ZZ3M» cargadas, porque la igualdad
     * todavía miraba la puntuación y los espacios internos. La usan la coincidencia exacta de
     * resolver_marca(), marca_con_el_mismo_nombre() y alta_de_marca_pendiente().
     *
     * @param  string  $nombre
     * @return string  Vacío si el nombre no tiene ni una letra ni un dígito.
     */
    protected static function clave_de_marca($nombre)
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(trim((string) $nombre))));
    }

    /**
     * La respuesta para una marca que no existe (ver SI_NO_ESTA_LA_MARCA y el 🔴 de
     * resolver_relacion()):
     *
     *  - Lo normal: el mismo `faltan` de siempre (con las marcas que hay en `opciones.brand_id`) más
     *    `opciones.si_no_esta`, que le dice al modelo que la cree con proponer_alta de brand y vuelva
     *    a llamar. El reenvío con la foto y los demás extras lo suma proponer_alta(), que es quien
     *    tiene la llamada entera.
     *  - 🔴 Si esa marca YA ESTÁ PROPUESTA en una tarjeta de esta conversación que todavía no se
     *    confirmó: un error que dice que la persona la confirme primero, SIN `si_no_esta`. Con la
     *    confianza en "cauteloso" o "resuelto" el alta de la marca queda como tarjeta, así que en el
     *    mismo mensaje la marca sigue sin existir; si la respuesta volviera a decir "creala", el
     *    modelo propondría la marca otra vez y daría vueltas hasta el tope de herramientas.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $etiqueta  "marca".
     * @param  string  $texto  El nombre que llegó.
     * @param  array  $opciones  [columna => marcas que hay], como el `faltan` genérico.
     * @return array
     */
    protected static function marca_que_no_esta(ContextoDeCargaIa $contexto, $etiqueta, $texto, array $opciones)
    {
        if (self::alta_de_marca_pendiente($contexto, $texto)) {

            return self::error_de_marca_pendiente($texto);
        }

        $opciones['si_no_esta'] = self::SI_NO_ESTA_LA_MARCA;

        return RespuestaDeCargaIa::faltan(
            ['qué '.$etiqueta.' es: no encontré ninguna que se llame como "'.$texto.'"'],
            $opciones
        );
    }

    /**
     * El error para una marca que todavía no existe porque quedó en una tarjeta sin confirmar: un
     * solo texto para los dos lugares que lo devuelven (resolver_marca(), antes de ofrecer parecidas,
     * y marca_que_no_esta()). Sin `si_no_esta` ni `si_no_es_ninguna`: lo que corta el loop es que no
     * haya nada que invite a proponer la marca de nuevo.
     *
     * @param  string  $texto  El nombre que llegó.
     * @return array
     */
    protected static function error_de_marca_pendiente($texto)
    {
        return RespuestaDeCargaIa::error(
            'La marca "'.$texto.'" todavía no existe: quedó en una tarjeta para que la persona la confirme. '
            .'No la vuelvas a proponer ni vuelvas a llamar en este mensaje: decile que confirme la marca y, cuando la confirme, '
            .'armá esta carga con todo lo que pidió (la foto, la descripción, la categoría, el precio y el stock).'
        );
    }

    /**
     * true si en esta conversación hay una tarjeta de alta de MARCA con ese nombre todavía propuesta
     * (sin confirmar, sin cancelar y sin vencer). Se compara con clave_de_marca() —sin mayúsculas,
     * tildes, signos ni espacios— contra lo que va a crear la tarjeta (`datos.payload.name`), la misma
     * igualdad que el resto de las marcas. Ver marca_que_no_esta().
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @return bool
     */
    protected static function alta_de_marca_pendiente(ContextoDeCargaIa $contexto, $nombre)
    {
        if (is_null($contexto->conversation)) {

            return false;
        }

        $buscado = self::clave_de_marca($nombre);

        if ($buscado === '') {

            return false;
        }

        $propuestas = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
                                        ->where('tipo', AiMessageAction::TIPO_ALTA)
                                        ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                        ->where('created_at', '>=', now()->subHours(AiMessageAction::HORAS_VENCIMIENTO))
                                        ->orderBy('id', 'DESC')
                                        ->limit(self::MAX_OPCIONES)
                                        ->get();

        foreach ($propuestas as $propuesta) {

            $datos = is_array($propuesta->datos) ? $propuesta->datos : [];

            if (!isset($datos['entidad']) || $datos['entidad'] !== 'brand' || !isset($datos['payload']['name'])) {

                continue;
            }

            if (self::clave_de_marca((string) $datos['payload']['name']) === $buscado) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si la respuesta negativa es la de una marca que no existe (la que trae SI_NO_ESTA_LA_MARCA
     * en `opciones.si_no_esta`). La usa proponer_alta() para sumarle el reenvío.
     *
     * @param  array  $respuesta
     * @return bool
     */
    protected static function es_la_marca_que_no_esta(array $respuesta)
    {
        $opciones = isset($respuesta['opciones']) && is_array($respuesta['opciones']) ? $respuesta['opciones'] : [];

        return isset($opciones['si_no_esta']) && $opciones['si_no_esta'] === self::SI_NO_ESTA_LA_MARCA;
    }

    /**
     * @param  iterable  $filas
     * @param  string  $campo_nombre
     * @return array<int, array{id: int, nombre: string}>
     */
    protected static function opciones_de_relacion($filas, string $campo_nombre): array
    {
        $opciones = [];

        foreach ($filas as $i => $fila) {

            if ($i >= self::MAX_OPCIONES) {

                break;
            }

            $opciones[] = ['id' => (int) $fila->id, 'nombre' => (string) $fila->{$campo_nombre}];
        }

        return $opciones;
    }

    // -------------------------------------------------------------------------------------------
    // Payload, renglones y claves
    // -------------------------------------------------------------------------------------------

    /**
     * El payload del alta: lo pedido, más los sí/no que la pantalla manda siempre (con el valor
     * con el que nace el formulario si está curado, si no el default de la columna, si no 0), más
     * las claves de pantalla (`price_types: []`...).
     *
     * @param  array  $declaracion
     * @param  array  $pedidos
     * @return array
     */
    public static function payload_de_alta(array $declaracion, array $pedidos): array
    {
        $payload = $pedidos;

        $defaults = isset($declaracion['defaults_de_pantalla']) ? $declaracion['defaults_de_pantalla'] : [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (array_key_exists($columna, $payload) || !in_array(Catalogo::OP_ALTA, $campo['operaciones'], true)) {

                continue;
            }

            if (array_key_exists($columna, $defaults)) {

                $payload[$columna] = $defaults[$columna];

            } elseif ($campo['tipo'] === 'checkbox') {

                $payload[$columna] = is_null($campo['default']) ? 0 : (int) $campo['default'];
            }
        }

        foreach ($declaracion['claves_de_pantalla'] as $clave => $valor) {

            if (!array_key_exists($clave, $payload)) {

                $payload[$clave] = $valor;
            }
        }

        return $payload;
    }

    /**
     * Un renglón por campo pedido, en el orden del catálogo.
     *
     * @param  array  $declaracion
     * @param  array  $pedidos
     * @param  array  $nombres
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    protected static function renglones(array $declaracion, array $pedidos, array $nombres): array
    {
        $renglones = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (!array_key_exists($columna, $pedidos)) {

                continue;
            }

            $valor = isset($nombres[$columna]) ? $nombres[$columna] : Catalogo::valor_legible($campo, $pedidos[$columna]);

            $renglones[] = ['etiqueta' => Str::ucfirst($campo['etiqueta']), 'valor' => $valor];
        }

        return $renglones;
    }

    /**
     * Con `cost_in_dollars` prendido, el renglón del costo se escribe en dólares ("US$ 10") y no en
     * pesos ("$ 10").
     *
     * Misión asistente-fotos-barras-y-compras (24/9/2026): valor_legible() formatea `cost` como
     * plata en pesos porque no ve los otros campos del pedido. Con la marca de dólares, la tarjeta
     * decía "Costo: $ 10" para un costo de diez DÓLARES, y el dueño confirmaba leyendo un precio que
     * no era el que se iba a guardar.
     *
     * @param  array  $declaracion
     * @param  array  $payload  El payload validado de la propuesta.
     * @param  array  $renglones
     * @return array
     */
    protected static function costo_en_dolares_en_los_renglones(array $declaracion, array $payload, array $renglones): array
    {
        if ($declaracion['entidad'] !== 'article'
            || !isset($payload['cost_in_dollars'], $payload['cost'], $declaracion['campos']['cost'])
            || !filter_var($payload['cost_in_dollars'], FILTER_VALIDATE_BOOLEAN)) {

            return $renglones;
        }

        $etiqueta_del_costo = Str::ucfirst($declaracion['campos']['cost']['etiqueta']);

        foreach ($renglones as $indice => $renglon) {

            if ($renglon['etiqueta'] === $etiqueta_del_costo) {

                $renglones[$indice]['valor'] = 'US$ ' . number_format((float) $payload['cost'], 2, ',', '.');
            }
        }

        return $renglones;
    }

    /**
     * Los renglones que identifican lo que se va a borrar: el nombre y hasta cuatro datos más de la
     * fila (fecha, total, cliente...) para que la persona reconozca el registro.
     *
     * @param  array  $declaracion
     * @param  object  $fila
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    protected static function renglones_de_baja(array $declaracion, $fila): array
    {
        $renglones = [];

        $renglones[] = ['etiqueta' => Str::ucfirst($declaracion['singular']), 'valor' => Catalogo::nombre_de_fila($declaracion['entidad'], $fila)];

        // Una venta o un gasto no declaran campos (no se editan por acá): se describen a mano.
        $fijos = [
            'sale'    => ['created_at' => 'Fecha', 'total' => 'Total', 'client_id' => 'Cliente'],
            'expense' => ['created_at' => 'Fecha', 'amount' => 'Importe', 'expense_concept_id' => 'Subcategoría'],
            'pending' => ['fecha_realizacion' => 'Fecha', 'notas' => 'Notas'],
        ];

        if (isset($fijos[$declaracion['entidad']])) {

            foreach ($fijos[$declaracion['entidad']] as $columna => $etiqueta) {

                if (!isset($fila->{$columna}) || self::vacio($fila->{$columna})) {

                    continue;
                }

                $campo = [
                    'columna'  => $columna,
                    'tipo'     => in_array($columna, ['created_at', 'fecha_realizacion'], true) ? 'date' : (in_array($columna, ['total', 'amount'], true) ? 'number' : 'text'),
                    'etiqueta' => $etiqueta,
                    'relacion' => null,
                ];

                if ($columna === 'client_id') {

                    $campo['relacion'] = ['tabla' => 'clients', 'campo' => 'name'];
                }

                if ($columna === 'expense_concept_id') {

                    $campo['relacion'] = ['tabla' => 'expense_concepts', 'campo' => 'name'];
                }

                $renglones[] = ['etiqueta' => $etiqueta, 'valor' => Catalogo::valor_legible($campo, $fila->{$columna})];
            }

            return $renglones;
        }

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (count($renglones) >= self::MAX_RENGLONES_DE_BAJA) {

                break;
            }

            if ($columna === $declaracion['columna_nombre'] || !isset($fila->{$columna}) || self::vacio($fila->{$columna})) {

                continue;
            }

            $renglones[] = ['etiqueta' => Str::ucfirst($campo['etiqueta']), 'valor' => Catalogo::valor_legible($campo, $fila->{$columna})];
        }

        return $renglones;
    }

    /**
     * "· Teléfono: 351... · CUIT: ..." para el resumen que lee la IA (sin repetir el nombre).
     *
     * @param  array  $renglones
     * @param  string|null  $nombre
     * @return string
     */
    protected static function resumen_de_renglones(array $renglones, $nombre): string
    {
        $partes = [];

        foreach ($renglones as $renglon) {

            if (!is_null($nombre) && $renglon['valor'] === $nombre) {

                continue;
            }

            $partes[] = $renglon['etiqueta'].': '.$renglon['valor'];
        }

        return count($partes) ? ' · '.implode(' · ', $partes) : '';
    }

    /**
     * Identidad de un alta para el reemplazo: la misma entidad con el mismo nombre es la misma
     * carga corregida; sin nombre, la huella de lo pedido.
     *
     * @param  array  $declaracion
     * @param  string|null  $nombre
     * @param  array  $pedidos
     * @return string
     */
    protected static function clave_de_alta(array $declaracion, $nombre, array $pedidos): string
    {
        $identidad = is_null($nombre)
            ? substr(md5(json_encode($pedidos)), 0, 12)
            : mb_strtolower(trim($nombre));

        return mb_substr('alta:'.$declaracion['entidad'].':'.$identidad, 0, 100);
    }

    /**
     * El nombre que se pidió para la fila nueva (la columna de nombre de la entidad), o null.
     *
     * @param  array  $declaracion
     * @param  array  $payload
     * @return string|null
     */
    protected static function nombre_pedido(array $declaracion, array $payload)
    {
        $columna = $declaracion['columna_nombre'];

        if (is_null($columna) || !isset($payload[$columna]) || self::vacio($payload[$columna])) {

            return null;
        }

        return trim((string) $payload[$columna]);
    }

    /**
     * true si el dueño ya tiene una fila con ese nombre (sin distinguir mayúsculas).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  string  $nombre
     * @return bool
     */
    protected static function hay_otra_con_ese_nombre(ContextoDeCargaIa $contexto, array $declaracion, string $nombre): bool
    {
        return self::consulta_del_dueno($contexto, $declaracion)
                    ->whereRaw('LOWER('.$declaracion['columna_nombre'].') = ?', [mb_strtolower($nombre)])
                    ->exists();
    }

    /**
     * Las claves con las que se acepta cada campo: la columna, su etiqueta (con y sin acentos) y,
     * para una relación, el nombre sin `_id`.
     *
     * @param  array  $declaracion
     * @return array<string, string>  alias normalizado => columna
     */
    protected static function alias_de_campos(array $declaracion): array
    {
        $alias = [];

        // Primero los alias derivados, después las columnas: una columna real siempre gana.
        foreach ($declaracion['campos'] as $columna => $campo) {

            $alias[self::normalizar_clave($campo['etiqueta'])] = $columna;

            if (Str::endsWith($columna, '_id')) {

                $alias[self::normalizar_clave(substr($columna, 0, -3))] = $columna;
            }
        }

        // "nombre" siempre es la columna de nombre de la entidad (street en una sucursal).
        if (!is_null($declaracion['columna_nombre']) && isset($declaracion['campos'][$declaracion['columna_nombre']])) {

            $alias['nombre'] = $declaracion['columna_nombre'];
            $alias['name'] = $declaracion['columna_nombre'];
        }

        foreach ($declaracion['campos'] as $columna => $campo) {

            $alias[self::normalizar_clave($columna)] = $columna;
        }

        return $alias;
    }

    /**
     * Minúsculas, sin acentos, guiones y espacios como guión bajo.
     *
     * @param  string  $clave
     * @return string
     */
    protected static function normalizar_clave(string $clave): string
    {
        $clave = mb_strtolower(trim($clave));

        $clave = strtr($clave, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return preg_replace('/[\s\-]+/', '_', $clave);
    }

    /**
     * Los campos de la entidad para ofrecerlos en una respuesta negativa.
     *
     * @param  array  $declaracion
     * @return array<int, array{campo: string, etiqueta: string, tipo: string}>
     */
    protected static function campos_para_ofrecer(array $declaracion): array
    {
        $campos = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            $campos[] = ['campo' => $columna, 'etiqueta' => $campo['etiqueta'], 'tipo' => $campo['tipo']];
        }

        return $campos;
    }

    // -------------------------------------------------------------------------------------------
    // Coerción y comparación
    // -------------------------------------------------------------------------------------------

    /**
     * true si el valor guardado y el pedido son lo mismo para el tipo del campo.
     *
     * @param  array  $campo
     * @param  mixed  $antes
     * @param  mixed  $despues
     * @return bool
     */
    public static function iguales(array $campo, $antes, $despues): bool
    {
        if (self::vacio($antes) && self::vacio($despues)) {

            return true;
        }

        if (self::vacio($antes) || self::vacio($despues)) {

            return false;
        }

        if (!is_null($campo['relacion'])) {

            return (int) $antes === (int) $despues;
        }

        switch ($campo['tipo']) {

            case 'number':
                return abs((float) $antes - (float) $despues) < 0.0001;

            case 'checkbox':
                return filter_var($antes, FILTER_VALIDATE_BOOLEAN) === filter_var($despues, FILTER_VALIDATE_BOOLEAN);

            case 'date':
                return substr((string) $antes, 0, 10) === substr((string) $despues, 0, 10);
        }

        return trim((string) $antes) === trim((string) $despues);
    }

    /**
     * true si el valor no trae nada.
     *
     * @param  mixed  $valor
     * @return bool
     */
    public static function vacio($valor): bool
    {
        return is_null($valor) || (is_string($valor) && trim($valor) === '');
    }

    /**
     * Un número a partir de lo que mandó el modelo ("1500", 1500, "1.500,50", "1500.5"), o null.
     *
     * @param  mixed  $valor
     * @return float|int|null
     */
    protected static function a_numero($valor)
    {
        if (is_int($valor) || is_float($valor)) {

            return $valor;
        }

        if (is_bool($valor) || !is_string($valor)) {

            return null;
        }

        $texto = trim($valor);

        // "1.500,50" → "1500.50"; "1500,5" → "1500.5".
        if (strpos($texto, ',') !== false) {

            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        if (!is_numeric($texto)) {

            return null;
        }

        return strpos($texto, '.') === false ? (int) $texto : (float) $texto;
    }

    /**
     * Un sí/no a partir de lo que mandó el modelo, o null si no se entiende.
     *
     * @param  mixed  $valor
     * @return bool|null
     */
    protected static function a_booleano($valor)
    {
        if (is_bool($valor)) {

            return $valor;
        }

        if (is_int($valor) || is_float($valor)) {

            return (float) $valor !== 0.0;
        }

        $texto = mb_strtolower(trim((string) $valor));

        if (in_array($texto, ['1', 'si', 'sí', 'true', 'verdadero', 'yes'], true)) {

            return true;
        }

        if (in_array($texto, ['0', 'no', 'false', 'falso'], true)) {

            return false;
        }

        return null;
    }

    /**
     * Una fecha AAAA-MM-DD (o AAAA-MM-DD HH:MM:SS) validada, o null.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected static function a_fecha($valor)
    {
        $texto = trim((string) $valor);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2}(:\d{2})?)?$/', $texto, $m)) {

            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {

            return null;
        }

        return $texto;
    }

    /**
     * true si la tabla tiene esa columna (cacheado por proceso: se pregunta por cada relación que
     * se resuelve).
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return bool
     */
    protected static function tabla_tiene(string $tabla, string $columna): bool
    {
        static $cache = [];

        $clave = $tabla.'.'.$columna;

        if (!isset($cache[$clave])) {

            $cache[$clave] = Schema::hasColumn($tabla, $columna);
        }

        return $cache[$clave];
    }

    /**
     * Escapa los comodines de LIKE en un texto de búsqueda.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function escapar_like(string $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
    }

    /**
     * @param  string  $operacion
     * @return string
     */
    protected static function verbo(string $operacion): string
    {
        switch ($operacion) {

            case Catalogo::OP_ALTA:
                return 'crear';

            case Catalogo::OP_EDICION:
                return 'editar';
        }

        return 'borrar';
    }

    /**
     * El motivo por el que una operación no está para una entidad que sí está en el catálogo.
     *
     * @param  string  $entidad
     * @param  string  $operacion
     * @return string
     */
    protected static function porque_no(string $entidad, string $operacion): string
    {
        if ($entidad === 'sale' && $operacion === Catalogo::OP_ALTA) {

            return ' (para vender está proponer_venta)';
        }

        if ($entidad === 'sale') {

            return ' (una venta no se edita desde el chat)';
        }

        if ($entidad === 'expense') {

            return ' (para cargar un gasto está proponer_gasto)';
        }

        if ($entidad === 'pending') {

            return ' (las tareas se crean y cambian con proponer_tarea y proponer_cambios_en_tarea)';
        }

        return '';
    }
}
