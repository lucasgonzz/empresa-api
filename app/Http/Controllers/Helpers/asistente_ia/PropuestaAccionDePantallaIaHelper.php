<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionDePantallaIaHelper as Ejecutor;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Las tres herramientas de acciones de pantalla del asistente (misión asistente-mcp, 22/9/2026,
 * constructor B): leer lo que una pantalla ve (GET, en el acto), proponer una acción (POST/PUT, con
 * tarjeta) y proponer un borrado (DELETE, con tarjeta SIEMPRE).
 *
 * Tienen la misma forma que las demás propuestas: validan contra el catálogo
 * (CatalogoDeAccionesDePantallaIaHelper), devuelven `faltan` / `error` como respuesta de negocio
 * (RespuestaDeCargaIa) y arman la tarjeta con AccionesIaHelper::crear() guardando en `datos`
 * exactamente lo que EjecutorAccionDePantallaIaHelper necesita para llamar a la pantalla.
 *
 * Lo que valida cada una, y por qué:
 *
 *   - La ruta tiene que estar en el catálogo con ESE método. Si existe pero está excluida, el error
 *     lleva el motivo de la exclusión: el modelo tiene que poder contarle a la persona por qué no
 *     ("esa acción manda mensajes a terceros"), no inventarlo.
 *   - La extensión, sobre el dueño (la misma pregunta que `check_extencion_empresa`).
 *   - 🔴 La persona tiene que ser el dueño o un administrador (PermisosIaHelper::es_admin). El chat
 *     ya es solo del dueño (solo_el_dueno_ia), pero una acción de pantalla puede ser CUALQUIER
 *     ruta y no hay un permiso por slug que espejar como en las demás propuestas: el espejo de la
 *     pantalla acá es "puede todo lo que puede el dueño".
 *   - Ningún {param} obligatorio puede faltar, y la `descripcion` es obligatoria: es el título de
 *     la tarjeta, lo único que la persona lee antes de confirmar.
 *   - 🔴 Los ids de la ruta tienen que ser del dueño (EjecutorAccionDePantallaIaHelper::
 *     verificar_tenencia()): un id ajeno o inexistente contesta `error` en el acto, sin tarjeta.
 *   - 🔴 Lo que está en Catalogo::SIEMPRE_CONFIRMAN (hoy AFIP) deja tarjeta en los tres modos: la
 *     respuesta lleva `requiere_confirmacion`, que quizas_auto_confirmar() respeta.
 *
 * 🔴 El GET corre en el acto SIN tarjeta, así que tiene que autenticar a la persona igual que la
 * confirmación por texto (ver autenticado_como()).
 */
class PropuestaAccionDePantallaIaHelper
{
    /** Cuántas claves del cuerpo se muestran como renglones de la tarjeta. */
    const MAX_RENGLONES_DEL_CUERPO = 12;

    /** Largo máximo de un valor en un renglón (más allá se corta con "…"). */
    const LARGO_VALOR = 120;

    /** Largo máximo del título de la tarjeta. */
    const LARGO_TITULO = 160;

    /** El aviso fijo de toda tarjeta de borrado por pantalla. */
    const AVISO_BORRADO = 'Esto borra un registro por la pantalla. Si el sistema no lo manda a la papelera, no se deshace.';

    const MENSAJE_METODO_DE_ACCION = 'El método tiene que ser POST o PUT. Para borrar está proponer_borrado_por_pantalla y para leer, consultar_por_pantalla.';

    const MENSAJE_SIN_PERSONA = 'No pude identificar tu usuario para ejecutar la acción.';

    // -------------------------------------------------------------------------------------------
    // Lectura: consultar_por_pantalla
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta consultar_por_pantalla: llama a una ruta GET del catálogo en el acto y devuelve
     * lo que la pantalla ve.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $ruta  La ruta del catálogo, con sus {param} (o ya con valores).
     * @param  array  $parametros  Los valores de los {param}, por nombre.
     * @param  array  $consulta  La query string de la pantalla (filtros, per_page...).
     * @return array
     */
    public static function consultar(ContextoDeCargaIa $contexto, $ruta, array $parametros, array $consulta)
    {
        $ruta = trim((string) $ruta);

        if ($ruta === '') {

            return RespuestaDeCargaIa::faltan(['la ruta de la acción, como la devuelve que_acciones_de_pantalla_hay']);
        }

        // Una query string pegada a la ruta ("api/x?desde=...") se lee como parte de `consulta`.
        $consulta = array_merge(self::query_de($ruta), $consulta);

        $persona = $contexto->persona;

        if (is_null($persona)) {

            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_PERSONA);
        }

        if (!PermisosIaHelper::es_admin($persona)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('acciones de pantalla'));
        }

        $declaracion = self::declaracion_para($contexto, 'GET', $ruta, $parametros);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        try {

            $llamada = self::autenticado_como($persona, function () use ($contexto, $ruta, $parametros, $consulta) {

                return Ejecutor::llamar($contexto, 'GET', $ruta, $parametros, $consulta);
            });

        } catch (AccionIaException $e) {

            return RespuestaDeCargaIa::error($e->getMessage());
        }

        return [
            'ok'        => true,
            'metodo'    => 'GET',
            'ruta'      => $llamada['uri'],
            'status'    => $llamada['status'],
            'respuesta' => $llamada['cuerpo'],
            'recortado' => $llamada['recortado'],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Escritura: proponer_accion_de_pantalla y proponer_borrado_por_pantalla
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_accion_de_pantalla: la tarjeta de una acción POST o PUT.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  string  $metodo  POST | PUT (PATCH se pliega en PUT).
     * @param  string  $ruta
     * @param  array  $parametros
     * @param  array  $cuerpo
     * @param  string  $descripcion  Qué hace la acción, en una línea: el título de la tarjeta.
     * @param  mixed  $reemplaza_a
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, $metodo, $ruta, array $parametros, array $cuerpo, $descripcion, $reemplaza_a = null)
    {
        $metodo = Catalogo::normalizar_metodo($metodo);

        if (!in_array($metodo, ['POST', 'PUT'], true)) {

            return RespuestaDeCargaIa::error(self::MENSAJE_METODO_DE_ACCION);
        }

        return self::armar_tarjeta($contexto, $mensaje, $metodo, $ruta, $parametros, $cuerpo, $descripcion, $reemplaza_a, AiMessageAction::TIPO_ACCION_PANTALLA, null);
    }

    /**
     * Herramienta proponer_borrado_por_pantalla: la tarjeta de un DELETE, con su aviso. Su `case`
     * en HerramientasDeCarga NO pasa por quizas_auto_confirmar() y su tipo está en
     * NUNCA_AUTO_CONFIRMABLES: deja tarjeta en los tres modos.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  string  $ruta
     * @param  array  $parametros
     * @param  string  $descripcion
     * @param  mixed  $reemplaza_a
     * @return array
     */
    public static function proponer_borrado(ContextoDeCargaIa $contexto, AiMessage $mensaje, $ruta, array $parametros, $descripcion, $reemplaza_a = null)
    {
        // El aviso de la tarjeta viaja también en la respuesta (armar_tarjeta lo suma como `aviso`).
        return self::armar_tarjeta($contexto, $mensaje, 'DELETE', $ruta, $parametros, [], $descripcion, $reemplaza_a, AiMessageAction::TIPO_BORRADO_PANTALLA, self::AVISO_BORRADO);
    }

    /**
     * Lo común a las dos propuestas: las validaciones, la tarjeta y la respuesta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  string  $metodo  Ya normalizado.
     * @param  string  $ruta
     * @param  array  $parametros
     * @param  array  $cuerpo
     * @param  string  $descripcion
     * @param  mixed  $reemplaza_a
     * @param  string  $tipo  AiMessageAction::TIPO_ACCION_PANTALLA | TIPO_BORRADO_PANTALLA
     * @param  string|null  $aviso
     * @return array
     */
    protected static function armar_tarjeta(ContextoDeCargaIa $contexto, AiMessage $mensaje, $metodo, $ruta, array $parametros, array $cuerpo, $descripcion, $reemplaza_a, $tipo, $aviso)
    {
        $ruta = trim((string) $ruta);
        $descripcion = trim((string) $descripcion);

        $faltan = [];

        if ($ruta === '') {

            $faltan[] = 'la ruta de la acción, como la devuelve que_acciones_de_pantalla_hay';
        }

        if ($descripcion === '') {

            $faltan[] = 'la descripción en una línea de qué hace esta acción (es lo que la persona lee en la tarjeta)';
        }

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan);
        }

        if (is_null($contexto->persona)) {

            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_PERSONA);
        }

        if (!PermisosIaHelper::es_admin($contexto->persona)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('acciones de pantalla'));
        }

        $declaracion = self::declaracion_para($contexto, $metodo, $ruta, $parametros);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        $uri_concreta = Catalogo::uri_concreta($declaracion['ruta'], $parametros);

        $accion_legible = $metodo.' '.$uri_concreta;

        /*
         * 🔴 Lo que SIEMPRE se confirma (Catalogo::SIEMPRE_CONFIRMAN, hoy todo lo de AFIP): la
         * respuesta lleva `requiere_confirmacion`, que es lo que quizas_auto_confirmar() respeta
         * antes de mirar el modo del dueño, y la tarjeta lo dice en su aviso. Así una factura no
         * sale sola ni con la confianza en "directo", sin que HerramientasDeCarga sepa de AFIP.
         */
        $extra = [];

        if (!empty($declaracion['siempre_confirma'])) {

            $texto_confirmacion = 'Esta acción siempre se confirma, también con la confianza en "directo": '.$declaracion['motivo_confirmacion'].'.';

            $aviso = is_null($aviso) ? $texto_confirmacion : $aviso.' '.$texto_confirmacion;

            $extra['requiere_confirmacion'] = true;
            $extra['motivo_confirmacion'] = $texto_confirmacion;
        }

        if (!is_null($aviso)) {

            $extra['aviso'] = $aviso;
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            $tipo,
            'pantalla:'.md5($metodo.'|'.$uri_concreta.'|'.json_encode($cuerpo)),
            [
                'metodo'     => $metodo,
                'ruta'       => $declaracion['ruta'],
                'parametros' => $parametros,
                'cuerpo'     => $cuerpo,
            ],
            [
                'titulo'    => mb_substr($descripcion, 0, self::LARGO_TITULO),
                'renglones' => self::renglones($accion_legible, $cuerpo),
                'aviso'     => $aviso,
            ],
            $reemplaza_a
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, $descripcion.' ('.$accion_legible.')', $extra);
    }

    // -------------------------------------------------------------------------------------------
    // Validaciones compartidas
    // -------------------------------------------------------------------------------------------

    /**
     * La fila del catálogo para la acción pedida, o la respuesta negativa si no se puede pedir: le
     * faltan {param}, no está en el catálogo (con el motivo si está excluida), o exige una
     * extensión que el dueño no tiene.
     *
     * La extensión se chequea acá, al proponer, y también en el ejecutor al confirmar: el error
     * tiene que salir en el acto para que el modelo lo cuente, no recién cuando la persona toca
     * Confirmar.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $metodo  Ya normalizado.
     * @param  string  $ruta
     * @param  array  $parametros
     * @return array  La declaración, o RespuestaDeCargaIa::faltan() / ::error().
     */
    protected static function declaracion_para(ContextoDeCargaIa $contexto, $metodo, $ruta, array $parametros)
    {
        $faltan = Catalogo::parametros_que_faltan(Catalogo::normalizar_ruta($ruta), $parametros);

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan(array_map(function ($nombre) {
                return 'el valor de {'.$nombre.'} de la ruta (va en parametros)';
            }, $faltan));
        }

        $declaracion = Catalogo::resolver_ruta($metodo, Catalogo::uri_concreta($ruta, $parametros));

        if (is_null($declaracion)) {

            $motivo = Catalogo::motivo_de_exclusion($metodo, $ruta);

            if (is_null($motivo)) {

                $motivo = Catalogo::motivo_de_exclusion($metodo, Catalogo::uri_concreta($ruta, $parametros));
            }

            if (!is_null($motivo)) {

                return RespuestaDeCargaIa::error(Ejecutor::MENSAJE_NO_DISPONIBLE.' Motivo: '.$motivo.'.');
            }

            return RespuestaDeCargaIa::error(Ejecutor::MENSAJE_NO_DISPONIBLE.' No hay ninguna ruta '.$metodo.' '.Catalogo::normalizar_ruta($ruta).' en el sistema (o existe con otro método): buscala con que_acciones_de_pantalla_hay y pasá la ruta tal cual.');
        }

        if (!is_null($declaracion['extension']) && !PermisosIaHelper::tiene_extencion($contexto->owner, $declaracion['extension'])) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_extencion(str_replace('_', ' ', $declaracion['extension'])));
        }

        /*
         * La tenencia de los ids de la ruta, también al proponer: un id ajeno o inexistente se
         * rechaza en el acto y no deja tarjeta. El ejecutor la vuelve a mirar al confirmar.
         */
        try {

            Ejecutor::verificar_tenencia($contexto, $declaracion['ruta'], $parametros);

        } catch (AccionIaException $e) {

            return RespuestaDeCargaIa::error($e->getMessage());
        }

        return $declaracion;
    }

    // -------------------------------------------------------------------------------------------
    // Presentación
    // -------------------------------------------------------------------------------------------

    /**
     * Los renglones de la tarjeta: la acción ("PUT api/caja/12/cerrar") y hasta
     * MAX_RENGLONES_DEL_CUERPO claves del cuerpo con su valor.
     *
     * @param  string  $accion_legible
     * @param  array  $cuerpo
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    protected static function renglones($accion_legible, array $cuerpo): array
    {
        $renglones = [['etiqueta' => 'Acción', 'valor' => $accion_legible]];

        $mostrados = 0;

        foreach ($cuerpo as $clave => $valor) {

            if ($mostrados >= self::MAX_RENGLONES_DEL_CUERPO) {

                $renglones[] = ['etiqueta' => '…', 'valor' => 'y '.(count($cuerpo) - $mostrados).' claves más'];

                break;
            }

            $renglones[] = ['etiqueta' => (string) $clave, 'valor' => self::valor_legible($valor)];

            $mostrados++;
        }

        return $renglones;
    }

    /**
     * Un valor del cuerpo como texto corto: los escalares tal cual (sí/no para los booleanos), los
     * arrays y objetos como JSON recortado.
     *
     * @param  mixed  $valor
     * @return string
     */
    protected static function valor_legible($valor): string
    {
        if (is_null($valor)) {

            return '(vacío)';
        }

        if (is_bool($valor)) {

            return $valor ? 'sí' : 'no';
        }

        $texto = is_scalar($valor) ? (string) $valor : (string) json_encode($valor, JSON_UNESCAPED_UNICODE);

        if (mb_strlen($texto) > self::LARGO_VALOR) {

            return mb_substr($texto, 0, self::LARGO_VALOR - 1).'…';
        }

        return $texto;
    }

    /**
     * La query string pegada a una ruta ("api/x?desde=2026-01-01"), como array; [] si no trae.
     *
     * @param  string  $ruta
     * @return array
     */
    protected static function query_de($ruta): array
    {
        // El `?` de un {param?} opcional va seguido de `}` y no es una query string.
        if (!preg_match('/\?(?!\})(.*)$/', (string) $ruta, $m)) {

            return [];
        }

        $query = [];

        parse_str($m[1], $query);

        return is_array($query) ? $query : [];
    }

    // -------------------------------------------------------------------------------------------
    // Autenticación
    // -------------------------------------------------------------------------------------------

    /**
     * Corre la llamada con la persona autenticada y deja la autenticación como estaba.
     *
     * Es una copia de ConfirmacionPorTextoIaHelper::autenticado_como(), que ahí es privado, y está
     * acá por el mismo motivo que allá: consultar_por_pantalla corre adentro de
     * ResponderMensajeChatIaJob, sin request y sin sesión, y los controllers de pantalla leen la
     * persona de Auth (`$this->userId()`, UserHelper::user()). Sin esto, `Auth::id()` en el job es
     * null y el ejecutor corta con MENSAJE_SIN_AUTENTICAR.
     *
     * El `finally` es la parte que importa: si la llamada lanza, el worker sigue vivo y atiende el
     * próximo job — con el usuario de este dueño todavía puesto, si no se lo saca. En un worker
     * compartido eso es el peor error posible: una consulta del próximo cliente firmada por la
     * persona equivocada.
     *
     * @param  \App\Models\User  $persona
     * @param  callable  $accion
     * @return mixed
     */
    protected static function autenticado_como($persona, $accion)
    {
        $previo = null;

        try {

            $previo = Auth::user();

        } catch (\Throwable $e) {

            /* Sin sesión de la que leer (el caso normal en un worker): no había nadie. */
            Log::info('PropuestaAccionDePantallaIaHelper: no se pudo leer el usuario previo -- '.$e->getMessage());
        }

        Auth::setUser($persona);

        try {

            return call_user_func($accion);

        } finally {

            if (is_null($previo)) {

                Auth::forgetGuards();

            } else {

                Auth::setUser($previo);
            }
        }
    }
}
