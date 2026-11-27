<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\article\DestacadoDeArticuloHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;

/**
 * Destacar (o quitar el destacado de) UN artículo de la tienda online, con tarjeta de confirmación.
 *
 * Misión asistente-diario-20261121 (M1). El botón de la estrella del Listado llama a
 * `GET api/article/set-featured/{id}`, una ruta GET con efectos que el catálogo de acciones de pantalla
 * bloquea a propósito (CatalogoDeAccionesDePantallaIaHelper::EXCLUIDAS): desde el chat una consulta no
 * puede escribir. Esta herramienta le da al asistente el mismo efecto, pero por una tarjeta que la persona
 * confirma. La cuenta del orden es la de la pantalla: vive en DestacadoDeArticuloHelper y la usan las dos.
 *
 * 🔴 Reglas:
 *  - Mismo permiso que editar un artículo (`article.update`), revalidado al confirmar.
 *  - El artículo es del dueño de la conversación (se busca siempre filtrado por `user_id`).
 *  - Si el artículo ya está en el estado pedido NO se arma tarjeta: se le dice a la persona.
 *  - Ambigüedad o nombre inexistente: se pregunta o se corta, nunca se elige «el primero».
 *  - Al confirmar se vuelve a leer el artículo: si entre la propuesta y el sí alguien lo destacó (o le quitó
 *    el destacado) desde la pantalla, no se invierte a ciegas, se dice que ya estaba.
 *  - Nunca se auto-confirma (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES): cambia lo que se ve en la tienda
 *    y el artículo se infiere de un nombre dicho de memoria.
 *
 * PHP 7.4: sin match, sin propiedades tipadas, sin str_contains, sin nullsafe, sin argumentos nombrados.
 */
class PropuestaDestacadoArticuloIaHelper
{
    /** El mismo permiso que editar un artículo desde el Listado. */
    const PERMISO = 'article.update';

    /** Cuántos candidatos se ofrecen cuando el nombre es ambiguo. */
    const TOPE_CANDIDATOS = 8;

    const TITULO_DESTACAR = 'Destacar un artículo en la tienda';

    const TITULO_QUITAR = 'Quitar un artículo de los destacados';

    /**
     * Herramienta proponer_destacado_articulo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  articulo (nombre o código) o articulo_id, destacado (true/false), reemplaza_a
     * @return array  La respuesta de la herramienta.
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('destacados de la tienda'));
        }

        $destacado = self::como_booleano(EntradaDeCargaIa::valor($input, 'destacado'));

        if (is_null($destacado)) {

            return RespuestaDeCargaIa::faltan(['si hay que destacar el artículo o quitarle el destacado']);
        }

        $articulo = self::resolver_articulo($contexto, $input);

        if (is_array($articulo)) {

            return $articulo;
        }

        $nombre = trim((string) $articulo->name);

        // Ya está como se pidió: se le dice a la persona, sin tarjeta que confirmar.
        if (DestacadoDeArticuloHelper::esta_destacado($articulo) === $destacado) {

            return [
                'ok'            => true,
                'sin_tarjeta'   => true,
                'ya_estaba'     => true,
                'articulo'      => $nombre,
                'destacado'     => $destacado,
                'texto'         => $destacado
                    ? 'El artículo «' . $nombre . '» ya está destacado en la tienda. No hay nada para cambiar.'
                    : 'El artículo «' . $nombre . '» ya no estaba entre los destacados. No hay nada para cambiar.',
                'como_contarlo' => 'Decile a la persona que ya estaba así y que no armaste ninguna tarjeta.',
            ];
        }

        $renglones = [
            ['etiqueta' => 'Artículo', 'valor' => $nombre],
            ['etiqueta' => 'Destacado en la tienda', 'valor' => $destacado ? 'No → Sí (queda al final de los destacados)' : 'Sí → No'],
        ];

        $aviso = '';

        if ($destacado && !(int) $articulo->online) {

            $aviso = 'Este artículo no está publicado en la tienda: queda marcado como destacado, pero no se va a ver hasta que se publique.';

            $renglones[] = ['etiqueta' => 'Atención', 'valor' => $aviso];
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_DESTACADO_ARTICULO,
            'destacado_articulo:' . (int) $articulo->id,
            [
                'articulo_id' => (int) $articulo->id,
                'nombre'      => $nombre,
                'destacado'   => $destacado,
            ],
            ['titulo' => $destacado ? self::TITULO_DESTACAR : self::TITULO_QUITAR, 'renglones' => $renglones, 'aviso' => $aviso],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            ($destacado ? 'Destacar «' : 'Quitar de los destacados «') . $nombre . '».',
            ['articulo' => $nombre, 'destacado' => $destacado]
        );
    }

    /**
     * Aplica el cambio al confirmar la tarjeta (adentro de la transacción de EjecutorAccionesIaHelper).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('destacados de la tienda'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $articulo_id = isset($datos['articulo_id']) ? (int) $datos['articulo_id'] : 0;

        if ($articulo_id <= 0 || !array_key_exists('destacado', $datos) || !is_bool($datos['destacado'])) {

            throw new AccionIaException(422, 'Esta tarjeta no tiene ningún destacado para aplicar. Pedímelo de nuevo.');
        }

        $destacado = $datos['destacado'];

        // Del dueño de la conversación y bloqueado: dos confirmaciones a la vez no lo destacan dos veces.
        $articulo = Article::where('user_id', $contexto->owner_id)->where('id', $articulo_id)->lockForUpdate()->first();

        if (is_null($articulo)) {

            throw new AccionIaException(422, 'Ese artículo ya no existe. No se cambió nada.');
        }

        $nombre = trim((string) $articulo->name);

        $cambio = DestacadoDeArticuloHelper::fijar($articulo, $destacado, $contexto->owner_id);

        if (!$cambio) {

            return [
                'texto' => $destacado
                    ? '«' . $nombre . '» ya estaba destacado en la tienda: no cambié nada.'
                    : '«' . $nombre . '» ya no estaba entre los destacados: no cambié nada.',
                'ruta'  => ['name' => 'article', 'params' => new \stdClass(), 'texto' => 'Ver el listado'],
            ];
        }

        return [
            'texto' => $destacado
                ? 'Listo: «' . $nombre . '» quedó destacado en la tienda.'
                : 'Listo: «' . $nombre . '» ya no está entre los destacados de la tienda.',
            'ruta'  => ['name' => 'article', 'params' => new \stdClass(), 'texto' => 'Ver el listado'],
        ];
    }

    /**
     * Lo que dijo la modelo en `destacado` como booleano estricto, o null si no es claro.
     *
     * @param  mixed  $valor
     * @return bool|null
     */
    protected static function como_booleano($valor)
    {
        if (is_bool($valor)) {

            return $valor;
        }

        if (is_int($valor) && ($valor === 0 || $valor === 1)) {

            return $valor === 1;
        }

        if (is_string($valor)) {

            $texto = mb_strtolower(trim($valor));

            if (in_array($texto, ['true', '1', 'si', 'sí'], true)) {

                return true;
            }

            if (in_array($texto, ['false', '0', 'no'], true)) {

                return false;
            }
        }

        return null;
    }

    /**
     * El artículo del dueño que nombró la persona, o la respuesta que pide desambiguar / corta.
     *
     * `articulo_id` directo, o `articulo` por nombre o código. Una ambigüedad pregunta con los candidatos
     * por su nombre; nunca se toma «el primero». Se filtra siempre por `user_id`: un id de otro negocio no
     * existe. No se filtra por `status` (se puede destacar uno pausado).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @return \App\Models\Article|array
     */
    protected static function resolver_articulo(ContextoDeCargaIa $contexto, array $input)
    {
        $base = Article::query()->where('user_id', $contexto->owner_id);

        $articulo_id = EntradaDeCargaIa::valor($input, 'articulo_id');

        if (!EntradaDeCargaIa::vacio($articulo_id) && is_numeric($articulo_id) && (int) $articulo_id > 0) {

            $articulo = (clone $base)->where('id', (int) $articulo_id)->first();

            if (is_null($articulo)) {

                return RespuestaDeCargaIa::error('Ese artículo no existe entre los tuyos. Buscalo por nombre.');
            }

            return $articulo;
        }

        $texto = EntradaDeCargaIa::texto($input, 'articulo');

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(['qué artículo hay que destacar o sacar de los destacados']);
        }

        $normalizado = ConsultasSistemaIaHelper::normalize_text($texto);

        $candidatos = (clone $base)->where(function ($sub) use ($texto) {

            BusquedaPorPalabrasIaHelper::filtrar($sub, $texto, ['name', 'bar_code', 'provider_code']);
        });

        $candidatos = BusquedaPorPalabrasIaHelper::ordenar_por_frase($candidatos, $texto, ['name', 'bar_code', 'provider_code'])
            ->orderBy('name')
            ->limit(self::TOPE_CANDIDATOS + 1)
            ->get();

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error('No encontré ningún artículo que se llame "' . $texto . '" ni con ese código. Probá con otra palabra o con el código.');
        }

        // Lo que tiene la frase entera manda; si nada la tiene, un único candidato entra solo si es seguro.
        $con_la_frase = BusquedaPorPalabrasIaHelper::por_frase($candidatos, $texto, ['name', 'bar_code', 'provider_code']);

        if (count($con_la_frase)) {

            $candidatos = collect($con_la_frase);
        }

        if (count($candidatos) === 1
            && (count($con_la_frase) || BusquedaPorPalabrasIaHelper::es_seguro((string) $candidatos[0]->name, $texto))) {

            return $candidatos[0];
        }

        // El nombre o el código escrito completo gana sobre los parciales.
        foreach ($candidatos as $candidato) {

            if (ConsultasSistemaIaHelper::normalize_text((string) $candidato->name) === $normalizado
                || (trim((string) $candidato->bar_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->bar_code) === $normalizado)
                || (trim((string) $candidato->provider_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->provider_code) === $normalizado)) {

                return $candidato;
            }
        }

        $opciones = [];

        foreach ($candidatos->take(self::TOPE_CANDIDATOS) as $candidato) {

            $opciones[] = ['articulo_id' => (int) $candidato->id, 'nombre' => trim((string) $candidato->name)];
        }

        return RespuestaDeCargaIa::faltan(['cuál de estos artículos hay que destacar o sacar de los destacados'], ['articulos' => $opciones]);
    }
}
