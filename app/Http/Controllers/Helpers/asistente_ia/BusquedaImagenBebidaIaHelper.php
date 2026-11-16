<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\GeocoderCounter;
use App\Models\PruebaConfig;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Services\ArticleImageValidationService;
use App\Services\ImageAssignment\CandidateImageProcessor;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Busca antes del alta: conserva una imagen de conversación, nunca crea ni modifica artículos. */
class BusquedaImagenBebidaIaHelper
{
    public static function buscar($owner_id, AiConversation $conversation, array $input, AiMessage $mensaje = null): array
    {
        $c = ContextoDeCargaIa::de_la_conversacion($conversation);
        if ((int) $owner_id !== $c->owner_id || !AltaBebidasIaHelper::activa($c->owner)
            || !PermisosIaHelper::puede($c->persona, 'article.store') || !$mensaje
            || (int) $mensaje->ai_conversation_id !== (int) $conversation->id) {
            return ['error' => 'El alta completa de bebidas no está habilitada para tu usuario en este negocio.'];
        }
        $nombre = trim((string) ($input['nombre'] ?? ''));
        $identificacion = trim((string) ($input['identificacion'] ?? ''));
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 200 || mb_strlen($identificacion) > 300) {
            return ['error' => 'Necesito el nombre real de la bebida y su identificación, sin inventar códigos de barras.'];
        }
        $consulta = trim($nombre.' '.$identificacion);
        $palabras = preg_split('/[^a-z0-9]+/', \Illuminate\Support\Str::ascii(mb_strtolower($consulta)), -1, PREG_SPLIT_NO_EMPTY);
        $especificas = array_diff($palabras, ['vino', 'vinos', 'cerveza', 'cervezas', 'bebida', 'bebidas', 'tinto', 'blanco', 'rosado', 'espumante',
            'botella', 'botellas', 'caja', 'cajas', 'licor', 'whisky', 'gin', 'vodka', 'agua', 'gaseosa', 'ron', 'tequila', 'ml', 'litro', 'litros']);
        $especificas = array_filter($especificas, function ($palabra) { return strlen($palabra) >= 3 && !is_numeric($palabra); });
        if (!count($especificas)) return ['found' => false, 'motivo' => 'Necesito identificar la bebida concreta (nombre y bodega o variedad); no voy a usar una foto genérica.'];
        $clave = 'alta_bebida_busqueda:'.$c->owner_id.':'.$conversation->id.':'.sha1($consulta);
        if ($previa = Cache::get($clave)) return $previa;
        try {
            $encontradas = self::buscar_con_cupo($c, $consulta);
            if (empty($encontradas['ok'])) return ['found' => false, 'motivo' => $encontradas['error'] ?? 'No pude completar la búsqueda de imágenes.'];
            // La descarga existente aplica límites de tamaño, SSRF y redirecciones.
            $preparadas = app(CandidateImageProcessor::class)->preparar(array_slice($encontradas['resultados'], 0, 8));
            $candidatas = [];
            foreach ($preparadas['listas'] as $lista) {
                if (count($candidatas) >= 4) break;
                // P6 (16/11/2026, V13): una candidata cuyo título dice OTRO color u otra cepa se descarta antes de evaluarla.
                $origen = $preparadas['candidatas'][$lista['clave']] ?? [];
                if (self::contradice_la_bebida($consulta, trim(($origen['titulo'] ?? '').' '.($origen['pagina'] ?? '')))) continue;
                $lista['indice'] = $lista['clave'];
                $candidatas[] = $lista;
            }
            if (!count($candidatas)) {
                return ['found' => false, 'motivo' => 'Las fotos que encontré eran de otra variedad o de otro color de la bebida. Informalo y proponé el alta sin foto, o pedí una foto del producto.'];
            }
            $articulo = new Article(['name' => $consulta, 'user_id' => $c->owner_id]);
            // evaluar_candidatas rechaza ante duda: una foto dudosa o sin evaluar nunca se ofrece como exacta.
            $evaluacion = app(ArticleImageValidationService::class)->evaluar_candidatas($candidatas, $articulo, $c->owner_id);
            foreach ($candidatas as $candidata) {
                $veredicto = $evaluacion['resultados'][$candidata['indice']] ?? [];
                if (($veredicto['es_el_producto'] ?? '') !== 'si' || ($veredicto['confianza'] ?? '') !== 'high' || !empty($veredicto['problemas'])) continue;
                $fila = AsistenteImagenHelper::guardar_binario($mensaje, $candidata['binario'], $c->owner_id);
                if (!$fila) continue;
                $fuente = $preparadas['candidatas'][$candidata['clave']]['pagina'] ?? '';
                $resultado = ['found' => true, 'nombre' => $nombre, 'imagen_id' => (int) $fila->id, 'fuente' => $fuente,
                    'siguiente' => 'No se asignó a ningún artículo. La foto de internet NO va dentro del alta: proponé el alta y, cuando la persona la confirme, proponé esta foto aparte con proponer_foto_articulo (imagen_id), que la muestra y espera su propio sí.'];
                Cache::put('alta_bebida_imagen:'.$fila->id, ['owner_id' => $c->owner_id, 'conversation_id' => $conversation->id, 'nombre' => $nombre], now()->addHours(24));
                Cache::put($clave, $resultado, now()->addMinutes(30));
                return $resultado;
            }
        } catch (\Throwable $e) {
            return ['found' => false, 'motivo' => 'No pude verificar una imagen exacta en este momento. No se asignó ninguna foto ni se inventó un código.'];
        }
        return ['found' => false, 'motivo' => 'No encontré una imagen exacta verificada. Informalo y proponé el alta sin foto, o pedí una foto del producto.'];
    }

    /*
     * 🔴 P6 (misión asistente-mejoras-4-dias, 16/11/2026, V13). Truvari, conv 19: al Malbec le tocó la foto
     * de un vino BLANCO. Lo que se puede saber del nombre se mira antes de gastar la evaluación: si la
     * bebida pedida tiene color (dicho, o deducido de su cepa) y el título o la página de la candidata
     * nombra OTRO color u otra cepa de otro color, o si se pidió una cepa y la candidata nombra otra y no
     * la pedida, se descarta. Sin título ni página no se descarta nada (lo decide la evaluación de siempre).
     */

    /** Las cepas, de la más larga a la más corta (así «cabernet sauvignon» no se lee como «sauvignon»), con su color. */
    const CEPAS = [
        'cabernet sauvignon' => 'tinto', 'cabernet franc' => 'tinto', 'sauvignon blanc' => 'blanco', 'pinot noir' => 'tinto',
        'pinot grigio' => 'blanco', 'petit verdot' => 'tinto', 'tempranillo' => 'tinto', 'chardonnay' => 'blanco',
        'sangiovese' => 'tinto', 'torrontes' => 'blanco', 'semillon' => 'blanco', 'viognier' => 'blanco', 'riesling' => 'blanco',
        'malbec' => 'tinto', 'merlot' => 'tinto', 'bonarda' => 'tinto', 'syrah' => 'tinto', 'shiraz' => 'tinto', 'tannat' => 'tinto',
        'chenin' => 'blanco', 'moscatel' => 'blanco',
    ];

    /** Las palabras de color y a qué color van. */
    const COLORES = [
        'tinto' => 'tinto', 'red' => 'tinto', 'rouge' => 'tinto', 'blanco' => 'blanco', 'white' => 'blanco', 'blanc' => 'blanco',
        'rosado' => 'rosado', 'rose' => 'rosado',
    ];

    /**
     * true si el texto de una candidata contradice el color o la cepa de lo pedido. Pública para los tests.
     *
     * @param  string  $pedido  Nombre e identificación de la bebida.
     * @param  string  $candidata  Título y página de la foto encontrada.
     * @return bool
     */
    public static function contradice_la_bebida($pedido, $candidata): bool
    {
        if (trim((string) $candidata) === '') return false;
        list($color_pedido, $cepas_pedidas) = self::color_y_cepas($pedido);
        list($color_otro, $cepas_otras, $colores_otros) = self::color_y_cepas($candidata, true);
        // Otra cepa y no la pedida («Kaiken Cabernet» para un «Malbec»).
        if (count($cepas_pedidas) && count($cepas_otras) && !count(array_intersect($cepas_pedidas, $cepas_otras))) return true;
        // Otro color, dicho o por su cepa.
        if (!is_null($color_pedido) && count($colores_otros) && !in_array($color_pedido, $colores_otros, true)) return true;
        return false;
    }

    /**
     * El color y las cepas que nombra un texto. Con $todos, además, todos los colores que nombra.
     *
     * @return array
     */
    protected static function color_y_cepas($texto, $todos = false): array
    {
        $texto = ' '.preg_replace('/[^a-z0-9]+/', ' ', \Illuminate\Support\Str::ascii(mb_strtolower((string) $texto))).' ';
        $cepas = [];
        $colores = [];
        foreach (self::CEPAS as $cepa => $color) {
            if (strpos($texto, ' '.$cepa.' ') !== false) {
                $cepas[] = $cepa;
                $colores[] = $color;
                $texto = str_replace(' '.$cepa.' ', ' ', $texto);
            }
        }
        foreach (self::COLORES as $palabra => $color) {
            if (strpos($texto, ' '.$palabra.' ') !== false) $colores[] = $color;
        }
        $colores = array_values(array_unique($colores));
        $color = count($colores) === 1 ? $colores[0] : null;
        return $todos ? [$color, $cepas, $colores] : [$color, $cepas];
    }

    protected static function buscar_con_cupo(ContextoDeCargaIa $c, string $consulta): array
    {
        // El proveedor Google no consume cuota por sí mismo: el motor de asignaciones lo hace.
        // Para un artículo que todavía no existe reservamos acá, con el mismo cupo del dueño.
        $reserva = DB::transaction(function () use ($c) {
            \App\Models\User::whereKey($c->owner_id)->lockForUpdate()->firstOrFail();
            if ((int) ImagenesAutomaticasHelper::cuota_de($c->owner)['disponibles'] <= 0) return null;
            if (PruebaHelper::activa($c->owner_id)) {
                $prueba = PruebaConfig::where('owner_id', $c->owner_id)->lockForUpdate()->firstOrFail();
                $prueba->increment('imagenes_usadas');
            }
            $contador = GeocoderCounter::where('user_id', $c->owner_id)->whereDate('created_at', today())->lockForUpdate()->first();
            if (!$contador) $contador = GeocoderCounter::create(['user_id' => $c->owner_id, 'counter' => 0]);
            $contador->increment('counter');
            return $contador->id;
        });
        if (is_null($reserva)) return ['ok' => false, 'error' => 'Se agotó el cupo de búsquedas de imágenes del negocio. Podés cargar una foto propia.'];
        try {
            $resultado = ImageSearchProviderFactory::para($c->owner)->buscar($consulta);
        } catch (\Throwable $e) {
            GeocoderCounter::whereKey($reserva)->where('counter', '>', 0)->decrement('counter');
            throw $e;
        }
        // Una búsqueda válida sin coincidencias sí consume cupo; un error de conexión no.
        if (empty($resultado['ok'])) GeocoderCounter::whereKey($reserva)->where('counter', '>', 0)->decrement('counter');
        return $resultado;
    }
}
