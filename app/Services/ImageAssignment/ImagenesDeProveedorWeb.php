<?php

namespace App\Services\ImageAssignment;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Services\ProductFacts\Gtin;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Ramsey\Uuid\Uuid;

/**
 * Las fotos que la tarea de soporte trae de la web de un proveedor (misión importaciones-de-martin, 11/11/2026).
 *
 * La tarea cruza los productos del sitio con los artículos del dueño y manda, de a tandas de hasta 25, la foto
 * de cada uno con el tipo de coincidencia:
 *   - `exacto`: el código de la web es el del artículo (GTIN con dígito verificador == `bar_code`, o sku/mpn ==
 *     `provider_code` o un código del pivot con el proveedor). Se ASIGNA sola, como una asignada del motor: la
 *     fila de `images`, la marca para Tienda Nube y el item `asignada` con su `image_id` (así el dueño la puede
 *     «Quitar» desde Alertas → Imágenes, que es el deshacer).
 *   - `dudosa`: todo lo demás. Queda `a_revisar` con la candidata `imgcand_*.webp`, para que el dueño la apruebe.
 *
 * 🔴 Reglas que no dependen de lo que diga la tarea:
 *   - Solo artículos del dueño (`user_id`) y activos. Uno ajeno es «no encontrado».
 *   - Nunca se pisa una foto: un artículo que ya tiene imagen se saltea, sea exacta o dudosa.
 *   - Una `exacto` se vuelve a comprobar acá: si el código no coincide literal con el del artículo, o lo comparte
 *     con otro artículo activo del dueño, NO se asigna: baja a revisar.
 *   - Una dudosa no se suma si el artículo ya tiene otra imagen esperando revisión (en cualquier asignación).
 *   - Una exacta que se asigna rechaza las `a_revisar` que el artículo tenía de antes.
 *   - Idempotente por (ticket, fuente, artículo): la asignación es una por ticket y fuente, y un artículo que ya
 *     tiene item en ella se saltea (reanudar una tanda no duplica nada).
 *   - La foto se normaliza como la del motor (CandidateImageProcessor::guardar_final: cuadrada, ≤1000 px, webp).
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class ImagenesDeProveedorWeb
{
    const MAXIMO_DE_ITEMS = 25;
    const MAXIMO_DE_BYTES = 4194304;
    const LADO_MINIMO = 200;
    const TIPOS = ['exacto', 'dudosa'];

    /** De dónde vienen las fotos: la web del proveedor o su catálogo en PDF (adjunto del ticket). */
    const TIPOS_DE_FUENTE = ['web', 'pdf'];

    /** @var bool La tanda en curso viene de un catálogo en PDF. */
    protected $es_pdf = false;

    /** @var \App\Services\ImageAssignment\CandidateImageProcessor */
    protected $procesador;

    public function __construct(CandidateImageProcessor $procesador = null)
    {
        $this->procesador = is_null($procesador) ? new CandidateImageProcessor() : $procesador;
    }

    /**
     * Procesa una tanda. Cada item va por su lado: uno que falla no frena a los demás.
     *
     * @param  int    $owner_id
     * @param  int    $support_ticket_id
     * @param  string $fuente        dominio del sitio, ya normalizado
     * @param  array  $items         [{article_id, tipo, imagen: UploadedFile|null, url_origen, codigo_web}]
     * @param  bool   $ultima_tanda  deja el aviso por WhatsApp de la asignación (una vez)
     * @param  string $tipo_fuente   web | pdf (con pdf, `fuente` es `adjunto-<id>.pdf` y el origen es `proveedor_pdf`)
     * @return array
     */
    public function procesar(int $owner_id, int $support_ticket_id, string $fuente, array $items, bool $ultima_tanda = false, string $tipo_fuente = 'web'): array
    {
        $this->es_pdf = $tipo_fuente === 'pdf';
        $run = $this->asignacion($owner_id, $support_ticket_id, $fuente);
        $asignadas = 0;
        $a_revisar = 0;
        $salteadas = [];
        $a_revision_por_codigo = [];

        foreach ($items as $item) {
            $article_id = (int) $item['article_id'];
            try {
                $resultado = $this->procesar_item($run, $owner_id, $fuente, $item);
            } catch (\Throwable $error) {
                Log::warning('[ImagenesProveedorWeb] No se pudo procesar una imagen.', [
                    'run_id' => $run->id, 'article_id' => $article_id, 'error' => $error->getMessage(),
                ]);
                $resultado = ['salteada' => 'error_interno'];
            }
            if (isset($resultado['salteada'])) {
                $salteadas[] = ['article_id' => $article_id, 'motivo' => $resultado['salteada']];
                continue;
            }
            if ($resultado['estado'] === ImageAssignmentItem::STATUS_ASIGNADA) {
                $asignadas++;
            } else {
                $a_revisar++;
                if (!empty($resultado['degradada'])) {
                    $a_revision_por_codigo[] = $article_id;
                }
            }
        }

        $total = ImageAssignmentItem::where('run_id', $run->id)->count();
        $run->fill([
            'total_articulos' => $total,
            'procesados' => $total,
            'last_progress_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);
        $run->save();

        $conteos = ImageAssignmentRunHelper::conteos_de([$run->id])[$run->id];
        $aviso = $ultima_tanda ? $this->avisar($run, $support_ticket_id, $fuente, $conteos) : false;

        return [
            'run_id' => (int) $run->id,
            'run_uuid' => (string) $run->uuid,
            'asignadas' => $asignadas,
            'a_revisar' => $a_revisar,
            'salteadas' => $salteadas,
            'exactas_a_revisar' => $a_revision_por_codigo,
            'totales' => $conteos,
            'aviso_dejado' => $aviso,
        ];
    }

    /**
     * La asignación de este ticket y esta fuente: se crea la primera vez y se reusa en las tandas siguientes.
     */
    protected function asignacion(int $owner_id, int $support_ticket_id, string $fuente): ImageAssignmentRun
    {
        return DB::transaction(function () use ($owner_id, $support_ticket_id, $fuente) {
            DB::table('users')->where('id', $owner_id)->lockForUpdate()->first(['id']);
            $existente = ImageAssignmentRun::where('user_id', $owner_id)
                ->where('origen', $this->origen())
                ->where('support_ticket_id', $support_ticket_id)
                ->where('fuente', $fuente)
                ->first();
            if (!is_null($existente)) {
                return $existente;
            }
            $ahora = Carbon::now();

            return ImageAssignmentRun::create([
                'user_id' => $owner_id,
                'auth_user_id' => null,
                'uuid' => (string) Str::uuid(),
                'origen' => $this->origen(),
                'proveedor' => $this->es_pdf ? 'pdf' : 'web',
                'status' => ImageAssignmentRun::STATUS_TERMINADA,
                'motivo_estado' => 'Fotos ' . $this->de_donde($fuente) . '.',
                'total_articulos' => 0,
                'procesados' => 0,
                'aplica_tope_diario' => false,
                'support_ticket_id' => $support_ticket_id,
                'fuente' => $fuente,
                'started_at' => $ahora,
                'finished_at' => $ahora,
                'last_progress_at' => $ahora,
            ]);
        });
    }

    /**
     * @return array ['salteada' => motivo] | ['estado' => asignada|a_revisar, 'degradada' => bool]
     */
    protected function procesar_item(ImageAssignmentRun $run, int $owner_id, string $fuente, array $item): array
    {
        $article = Article::where('user_id', $owner_id)->find((int) $item['article_id']);
        if (is_null($article)) {
            return ['salteada' => 'articulo_no_encontrado'];
        }
        if ($article->status !== 'active') {
            return ['salteada' => 'articulo_inactivo'];
        }
        if (ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $article->id)->exists()) {
            return ['salteada' => 'ya_procesado'];
        }
        if ($article->images()->exists()) {
            return ['salteada' => 'ya_tiene_imagen'];
        }

        $binario = $this->leer_imagen(isset($item['imagen']) ? $item['imagen'] : null, $motivo);
        if (is_null($binario)) {
            return ['salteada' => $motivo];
        }

        $codigo_web = isset($item['codigo_web']) ? trim((string) $item['codigo_web']) : '';
        $criterio = $item['tipo'] === 'exacto' ? $this->criterio_exacto($article, $codigo_web, $owner_id) : null;
        $degradada = $item['tipo'] === 'exacto' && is_null($criterio);

        if (is_null($criterio) && ImageAssignmentItem::where('user_id', $owner_id)->where('article_id', $article->id)
            ->where('status', ImageAssignmentItem::STATUS_A_REVISAR)->exists()) {
            return ['salteada' => 'ya_tiene_a_revisar'];
        }

        $medidas = @getimagesizefromstring($binario);
        $meta = [
            'ancho' => (int) $medidas[0],
            'alto' => (int) $medidas[1],
            'fondo_blanco' => null,
            'fondo_blanco_ratio' => null,
            'dominio' => $fuente,
            'pagina' => $this->url(isset($item['url_origen']) ? $item['url_origen'] : null),
            'url_origen' => $this->url(isset($item['url_origen']) ? $item['url_origen'] : null),
            'codigo_web' => $codigo_web === '' ? null : mb_substr($codigo_web, 0, 100),
            'tipo_coincidencia' => (string) $item['tipo'],
            'ia' => ['veredicto' => 'sin_evaluar', 'confianza' => null, 'problemas' => [], 'motivo' => null],
            'avisos' => [],
        ];
        $this->medir_fondo($binario, $meta);

        $archivo = (is_null($criterio) ? ImageAssignmentItem::PREFIJO_CANDIDATA : '') . (string) Str::uuid() . '.webp';
        $guardada = $this->procesador->guardar_final($binario, $archivo);
        $meta['lado_final'] = $guardada['lado'];

        if (is_null($criterio)) {
            $meta['avisos'] = $degradada
                ? ['El código ' . ($this->es_pdf ? 'del catálogo' : 'de la web') . ' no coincide exacto con el del artículo (o lo comparte otro artículo)']
                : ['Coincidencia por nombre o código parecido ' . ($this->es_pdf ? 'en el catálogo en PDF' : 'en la web') . ' del proveedor'];

            try {
                $this->crear_item($run, $article, [
                    'status' => ImageAssignmentItem::STATUS_A_REVISAR,
                    'motivo' => 'confianza_media',
                    'motivo_detalle' => 'Esta foto es ' . $this->de_donde($fuente) . ', pero la coincidencia con el artículo no es exacta: revisala antes de aprobarla.',
                    'criterio_usado' => null,
                    'imagen_url' => $guardada['url'],
                    'imagen_archivo' => $guardada['archivo'],
                    'imagen_meta' => $meta,
                ]);
            } catch (\Throwable $error) {
                $this->borrar_archivo($guardada['archivo']);
                throw $error;
            }

            return ['estado' => ImageAssignmentItem::STATUS_A_REVISAR, 'degradada' => $degradada];
        }

        $asignada = false;
        try {
            $asignada = DB::transaction(function () use ($run, $article, $owner_id, $guardada, $meta, $criterio, $fuente) {
                $bloqueado = Article::where('user_id', $owner_id)->whereKey($article->id)->lockForUpdate()->first();
                if (is_null($bloqueado) || $bloqueado->images()->exists()) {
                    return false;
                }
                $imagen = Image::create([
                    'hosting_url' => $guardada['url'],
                    'imageable_id' => $bloqueado->id,
                    'imageable_type' => 'article',
                ]);
                // Igual que una asignada por el motor: sin apagar timestamps, para el sync incremental del front.
                $bloqueado->needs_sync_with_tn = true;
                $bloqueado->save();
                TiendaNubeSyncArticleService::add_article_to_sync($bloqueado);

                $this->crear_item($run, $bloqueado, [
                    'status' => ImageAssignmentItem::STATUS_ASIGNADA,
                    'motivo' => null,
                    'motivo_detalle' => 'Se asignó la foto ' . $this->de_donde($fuente) . ': el '
                        . ($criterio === 'codigo_de_barras' ? 'código de barras' : 'código de proveedor') . ' coincide exacto.',
                    'criterio_usado' => $criterio,
                    'imagen_url' => $guardada['url'],
                    'imagen_archivo' => $guardada['archivo'],
                    'imagen_meta' => $meta,
                    'image_id' => (int) $imagen->id,
                ]);

                return true;
            });
        } finally {
            if (!$asignada) {
                $this->borrar_archivo($guardada['archivo']);
            }
        }

        if (!$asignada) {
            return ['salteada' => 'ya_tiene_imagen'];
        }

        $this->rechazar_anteriores($owner_id, (int) $article->id, $run->id, $fuente);

        return ['estado' => ImageAssignmentItem::STATUS_ASIGNADA, 'degradada' => false];
    }

    /**
     * El criterio por el que una `exacto` se puede asignar sola, o null si no se puede comprobar.
     *
     * GTIN: el código de la web tiene que ser un GTIN válido (dígito verificador, sin prefijos internos ni de
     * balanza: ProductFacts\Gtin, el mismo que usa la búsqueda de medidas) e igual al `bar_code` del artículo.
     * Código de proveedor: igualdad LITERAL (sin pasar a mayúsculas ni sacar separadores) con el `provider_code`
     * o con un código del pivot. En los dos casos el código no puede repetirse en otro artículo activo del dueño.
     */
    protected function criterio_exacto(Article $article, string $codigo, int $owner_id)
    {
        if ($codigo === '') {
            return null;
        }

        $gtin = Gtin::canonical($codigo);
        $del_articulo = Gtin::canonical(preg_replace('/\D+/', '', (string) $article->bar_code));
        if (!is_null($gtin) && $gtin === $del_articulo) {
            $iguales = Article::where('user_id', $owner_id)->where('status', 'active')
                ->whereIn('bar_code', Gtin::equivalents($gtin))->count();

            return $iguales === 1 ? 'codigo_de_barras' : null;
        }

        $codigos = DB::table('article_provider')->where('article_id', $article->id)->pluck('provider_code')->all();
        $codigos[] = $article->provider_code;
        $coincide = false;
        foreach ($codigos as $propio) {
            if (!is_null($propio) && trim((string) $propio) !== '' && trim((string) $propio) === $codigo) {
                $coincide = true;
            }
        }
        if (!$coincide) {
            return null;
        }

        $por_columna = Article::where('user_id', $owner_id)->where('status', 'active')->where('provider_code', $codigo)->pluck('id')->all();
        $por_pivot = DB::table('article_provider')->join('articles', 'articles.id', '=', 'article_provider.article_id')
            ->where('articles.user_id', $owner_id)->where('articles.status', 'active')->whereNull('articles.deleted_at')
            ->where('article_provider.provider_code', $codigo)->pluck('articles.id')->all();
        $distintos = array_unique(array_map('intval', array_merge($por_columna, $por_pivot)));

        return count($distintos) === 1 ? 'codigo_de_proveedor' : null;
    }

    protected function crear_item(ImageAssignmentRun $run, Article $article, array $datos): ImageAssignmentItem
    {
        $orden = (int) ImageAssignmentItem::where('run_id', $run->id)->max('orden') + 1;

        return ImageAssignmentItem::create($datos + [
            'run_id' => (int) $run->id,
            'user_id' => (int) $run->user_id,
            'article_id' => (int) $article->id,
            'article_name' => is_null($article->name) ? null : mb_substr((string) $article->name, 0, 255),
            'article_bar_code' => is_null($article->bar_code) ? null : mb_substr((string) $article->bar_code, 0, 100),
            'orden' => $orden,
            'busquedas' => 0,
            'validaciones_ia' => 0,
            'procesado_at' => Carbon::now(),
        ]);
    }

    /**
     * Las imágenes que el artículo tenía esperando revisión en otras asignaciones ya no hacen falta: se rechazan
     * con el camino de siempre (que borra la candidata) y se dice por qué.
     */
    protected function rechazar_anteriores(int $owner_id, int $article_id, int $run_id, string $fuente): void
    {
        $anteriores = ImageAssignmentItem::where('user_id', $owner_id)->where('article_id', $article_id)
            ->where('run_id', '!=', $run_id)->where('status', ImageAssignmentItem::STATUS_A_REVISAR)->pluck('id');
        foreach ($anteriores as $item_id) {
            $respuesta = ImageAssignmentRunHelper::rechazar($owner_id, (int) $item_id, null);
            if ((int) $respuesta['status'] === 200) {
                ImageAssignmentItem::whereKey($item_id)->update([
                    'motivo_detalle' => 'Se reemplazó por la foto exacta ' . $this->de_donde($fuente) . '.',
                ]);
            }
        }
    }

    /**
     * El contenido de la imagen subida, o null con el motivo.
     *
     * @param  mixed       $archivo
     * @param  string|null $motivo  (salida)
     * @return string|null
     */
    protected function leer_imagen($archivo, &$motivo)
    {
        if (!$archivo instanceof UploadedFile || !$archivo->isValid()) {
            $motivo = 'imagen_invalida';
            return null;
        }
        if ((int) $archivo->getSize() > self::MAXIMO_DE_BYTES) {
            $motivo = 'imagen_muy_pesada';
            return null;
        }
        $binario = (string) file_get_contents($archivo->getRealPath());
        $medidas = @getimagesizefromstring($binario);
        if (!is_array($medidas) || empty($medidas[0]) || empty($medidas[1])) {
            $motivo = 'imagen_invalida';
            return null;
        }
        if (min((int) $medidas[0], (int) $medidas[1]) < self::LADO_MINIMO) {
            $motivo = 'imagen_chica';
            return null;
        }
        if ((int) $medidas[0] * (int) $medidas[1] > CandidateImageProcessor::MAX_MEGAPIXELES * 1000000) {
            $motivo = 'imagen_muy_grande';
            return null;
        }

        return $binario;
    }

    protected function medir_fondo(string $binario, array &$meta): void
    {
        try {
            $imagen = (new ImageManager())->make($binario);
            $proporcion = $this->procesador->medir_fondo_blanco($imagen->getCore());
            $imagen->destroy();
            $meta['fondo_blanco'] = $proporcion >= CandidateImageProcessor::PROPORCION_FONDO_BLANCO;
            $meta['fondo_blanco_ratio'] = $proporcion;
        } catch (\Throwable $error) {
            // Sin medida de fondo la imagen sirve igual.
        }
    }

    /**
     * El aviso por WhatsApp de la asignación, por el camino de las importaciones desde soporte (`importacion_lista`
     * con el ticket y sin link). Uno por asignación; sin nada asignado ni para revisar, no hay nada que avisar.
     */
    protected function avisar(ImageAssignmentRun $run, int $support_ticket_id, string $fuente, array $conteos): bool
    {
        $asignadas = (int) $conteos['asignadas'];
        $a_revisar = (int) $conteos['a_revisar'];
        if ($asignadas + $a_revisar === 0) {
            return false;
        }
        $texto = $asignadas > 0
            ? 'Te cargué ' . $asignadas . ($asignadas === 1 ? ' foto' : ' fotos') . ' ' . $this->de_tu_proveedor($fuente) . ' en tus artículos.'
            : 'Revisé ' . ($this->es_pdf ? 'el catálogo en PDF de tu proveedor' : 'la web de tu proveedor (' . mb_substr($fuente, 0, 120) . ')') . ' y no encontré fotos con el código exacto de tus artículos.';
        if ($a_revisar > 0) {
            $texto .= ' Te dejé ' . $a_revisar . ($a_revisar === 1 ? ' foto' : ' fotos')
                . ' que parecen ser de tus artículos para que las revises y apruebes en Alertas → Catálogo → Imágenes.';
        }
        if ($asignadas > 0) {
            $texto .= ' Si alguna no te convence, la podés quitar desde ahí mismo.';
        }

        AiAvisoDelAsistente::firstOrCreate([
            'uuid' => Uuid::uuid5($run->uuid, $this->es_pdf ? 'proveedor-pdf' : 'proveedor-web')->toString(),
            'owner_id' => (int) $run->user_id,
            'tipo' => AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA,
        ], [
            'support_ticket_id' => $support_ticket_id,
            'modo_soporte' => true,
            'texto' => $texto,
        ]);

        return true;
    }

    protected function origen(): string
    {
        return $this->es_pdf ? ImageAssignmentRun::ORIGEN_PROVEEDOR_PDF : ImageAssignmentRun::ORIGEN_PROVEEDOR_WEB;
    }

    /** «de la web del proveedor (dominio)» o «del catálogo en PDF del proveedor», para los textos del detalle. */
    protected function de_donde(string $fuente): string
    {
        return $this->es_pdf ? 'del catálogo en PDF del proveedor' : 'de la web del proveedor (' . mb_substr($fuente, 0, 120) . ')';
    }

    /** Lo mismo, en la voz del aviso al dueño. */
    protected function de_tu_proveedor(string $fuente): string
    {
        return $this->es_pdf ? 'del catálogo en PDF de tu proveedor' : 'de la web de tu proveedor (' . mb_substr($fuente, 0, 120) . ')';
    }

    protected function url($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '' || !preg_match('#^https?://#i', $valor)) {
            return null;
        }

        return mb_substr($valor, 0, 500);
    }

    protected function borrar_archivo($archivo): void
    {
        try {
            Storage::disk('public')->delete($archivo);
        } catch (\Throwable $error) {
            Log::warning('[ImagenesProveedorWeb] No se pudo borrar un archivo sin usar: ' . $archivo);
        }
    }
}
