<?php

namespace App\Services\TiendaNube;

use App\Models\Article;
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TiendaNubeProductImageService extends BaseTiendaNubeService
{
    public function sync_images_for_article(Article $article): void
    {
        $this->reconcile($article, true);
    }

    /** Simular no guarda marcadores ni hace pedidos de escritura a Tienda Nube. */
    public function reconcile(Article $article, bool $apply = false, callable $before = null, array $positions = [], $onlyImageId = null): array
    {
        if (!$article->tiendanube_product_id) {
            throw new \InvalidArgumentException('El artículo no tiene producto en Tienda Nube.');
        }
        $key = 'tn-img:'.sha1(DB::connection()->getDatabaseName().':'.$this->store_id.':'.$article->tiendanube_product_id);
        $locked = false;
        try {
            if ($apply) {
                $locked = (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS tomado', [$key])->tomado === 1;
                if (!$locked) {
                    throw new \RuntimeException('La sincronización de imágenes de este producto ya está en curso.');
                }
            }
            // Releer dentro del candado: un job puede traer la relación vieja en memoria.
            $images = $article->images()->orderBy('id')->get();
            $remote = $this->remote_images($article);
            $report = $this->inventory($article, $images, $remote);
            if ($before) {
                $before($report);
            }
            if (!$apply) {
                return $report;
            }
            if ($onlyImageId !== null && !$images->contains('id', $onlyImageId)) {
                throw new \RuntimeException('La foto ya no pertenece al artículo.');
            }
            foreach ($report['images'] as $row) {
                if ($onlyImageId !== null && (string) $row['image_id'] !== (string) $onlyImageId) { continue; }
                if ($row['action'] === 'ambigua' || $row['action'] === 'error') {
                    throw new \RuntimeException('Imagen '.$row['image_id'].': '.$row['reason']);
                }
            }
            foreach ($report['images'] as &$row) {
                if ($onlyImageId !== null && (string) $row['image_id'] !== (string) $onlyImageId) { continue; }
                $image = $images->firstWhere('id', $row['image_id']);
                $this->assert_lock_owned($key);
                if ($row['action'] === 'subir') {
                    // POST no es idempotente. Un resultado incierto se reconcilia en el próximo
                    // intento contra el remoto; repetir el POST a ciegas duplicaría la foto.
                    $payload = ['src' => $image->hosting_url];
                    if (isset($positions[$image->id])) {
                        $payload['position'] = max(1, (int) $positions[$image->id]);
                    }
                    $response = $this->http_without_throw_on_error_status()->post($this->endpoint($article), $payload);
                    if (!$response->successful()) {
                        throw new \RuntimeException('Tienda Nube rechazó la imagen '.$image->id.' (HTTP '.$response->status().').');
                    }
                    $data = $response->json();
                    $this->validate_remote_image($data, $article);
                    $remote = $this->remote_images($article);
                    if (!collect($remote)->contains(function ($item) use ($data) { return (string) $item['id'] === (string) $data['id']; })) {
                        throw new \RuntimeException('Tienda Nube no confirmó la imagen '.$image->id.' en el producto.');
                    }
                    $row['remote_id'] = $data['id'];
                }
                $this->assert_lock_owned($key);
                $image->tienda_nube_image_id = $row['remote_id'];
                // Lectura compatible de ambas columnas, sin dejar dos verdades diferentes.
                $image->tiendanube_image_id = $row['remote_id'];
                $image->save();
                $row['action'] = 'confirmada';
            }
            unset($row);
            $report['remote_after'] = $remote;
            return $report;
        } finally {
            if ($locked) {
                DB::select('SELECT RELEASE_LOCK(?)', [$key]);
            }
        }
    }

    public function upload_image_for_article(Article $article, Image $image): void
    {
        $this->reconcile($article, true, null, [], $image->id);
    }

    protected function endpoint(Article $article): string
    {
        return '/'.$this->store_id.'/products/'.$article->tiendanube_product_id.'/images';
    }

    public function remote_images(Article $article): array
    {
        $all = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->http_without_throw_on_error_status()->get($this->endpoint($article), ['page' => $page, 'per_page' => 100]);
            if (!$response->successful() || !is_array(json_decode($response->body()))) {
                throw new \RuntimeException('No se pudo consultar las imágenes remotas (HTTP '.$response->status().').');
            }
            $rows = $response->json();
            foreach ($rows as $row) {
                $this->validate_remote_image($row, $article);
                if (isset($all[(string) $row['id']])) {
                    throw new \RuntimeException('La lista remota repitió una imagen; el inventario no es confiable.');
                }
                $all[(string) $row['id']] = $row;
            }
            if (count($rows) < 100) {
                return array_values($all);
            }
        }
        throw new \RuntimeException('El inventario remoto excedió el límite seguro.');
    }

    protected function validate_remote_image($data, Article $article): void
    {
        if (!is_array($data) || empty($data['id']) || (!is_int($data['id']) && !is_string($data['id']))
            || !ctype_digit((string) $data['id']) || (int) $data['id'] < 1 || (int) $data['id'] > 2147483647
            || empty($data['src']) || !is_string($data['src']) || !filter_var($data['src'], FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($data['src'], PHP_URL_SCHEME)), ['http', 'https'], true)
            || empty($data['product_id']) || (!is_int($data['product_id']) && !is_string($data['product_id']))
            || (string) $data['product_id'] !== (string) $article->tiendanube_product_id) {
            throw new \RuntimeException('Respuesta de imagen remota inválida o no representable en el marcador local.');
        }
    }

    protected function assert_lock_owned(string $key): void
    {
        $row = DB::selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS propio', [$key]);
        if ((int) $row->propio !== 1) {
            throw new \RuntimeException('Se perdió el candado de imágenes; se frena antes de otra escritura.');
        }
    }

    protected function inventory(Article $article, $images, array $remote): array
    {
        $rows = [];
        $claimed = [];
        $remoteHashes = [];
        foreach ($images as $image) {
            $row = ['image_id' => $image->id, 'url' => $image->hosting_url,
                'before' => ['tienda_nube_image_id' => $image->tienda_nube_image_id, 'tiendanube_image_id' => $image->tiendanube_image_id],
                'action' => 'subir', 'remote_id' => null, 'reason' => 'Sin coincidencia remota'];
            try {
                $hash = $this->image_hash($image->hosting_url);
                $row['sha256'] = $hash;
                $ids = array_values(array_unique(array_filter([(string) $image->tienda_nube_image_id, (string) $image->tiendanube_image_id])));
                if (count($ids) > 1) {
                    $row['action'] = 'ambigua';
                    $row['reason'] = 'Los marcadores de la foto son discordantes; revisar antes de reconciliar.';
                    $rows[] = $row;
                    continue;
                }
                $matches = [];
                foreach ($remote as $item) {
                    if (in_array((string) $item['id'], $ids, true) || $item['src'] === $image->hosting_url) {
                        $matches[(string) $item['id']] = $item;
                    }
                }
                if (count($matches) === 0) {
                    foreach ($remote as $item) {
                        $id = (string) $item['id'];
                        if (!array_key_exists($id, $remoteHashes)) {
                            try {
                                $remoteHashes[$id] = $this->image_hash($item['src']);
                            } catch (\Exception $e) {
                                $remoteHashes[$id] = null;
                            }
                        }
                        if ($remoteHashes[$id] === $hash) {
                            $matches[$id] = $item;
                        }
                    }
                }
                if (count($matches) > 1) {
                    $row['action'] = 'ambigua';
                    $row['reason'] = 'Más de una imagen remota coincide con la foto o sus marcadores.';
                } elseif (count($matches) === 1) {
                    $match = reset($matches);
                    $row['remote_id'] = $match['id'];
                    if (isset($claimed[(string) $match['id']])) {
                        $row['action'] = 'ambigua';
                        $row['reason'] = 'Dos fotos locales apuntan a la misma foto remota.';
                    } else {
                        $claimed[(string) $match['id']] = true;
                        $row['action'] = 'vincular';
                        $row['reason'] = 'Foto confirmada en el producto remoto.';
                    }
                }
            } catch (\Exception $e) {
                $row['action'] = 'error';
                $row['reason'] = $e->getMessage();
            }
            $rows[] = $row;
        }
        $unknown = array_filter($remote, function ($item) use ($claimed) { return !isset($claimed[(string) $item['id']]); });
        foreach ($rows as &$row) {
            // TN transforma formatos y tamaños. Hash distinto NO demuestra ausencia.
            if ($row['action'] === 'subir' && count($unknown) > 0) {
                $row['action'] = 'ambigua';
                $row['reason'] = 'Hay imágenes remotas sin identificar; revisar antes de subir.';
            }
        }
        unset($row);
        return ['article_id' => $article->id, 'user_id' => $article->user_id, 'product_id' => $article->tiendanube_product_id,
            'article_before' => ['name' => $article->name, 'price' => $article->price, 'final_price' => $article->final_price, 'stock' => $article->stock],
            'store_id' => $this->store_id, 'images' => $rows, 'remote_before' => $remote];
    }

    protected function image_hash($url): string
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \RuntimeException('La foto no tiene una URL pública válida.');
        }
        // Nunca enviar el token de Tienda Nube al hosting de una foto.
        $response = Http::timeout(30)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
        $stream = $response->toPsrResponse()->getBody();
        try {
            $limit = 10 * 1024 * 1024;
            if (!$response->successful() || (int) $response->header('Content-Length') >= $limit) {
                throw new \RuntimeException('La URL de la foto no devuelve una imagen accesible de menos de 10 MB.');
            }
            $body = '';
            while (!$stream->eof() && strlen($body) < $limit) {
                $chunk = $stream->read(min(65536, $limit - strlen($body)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new \RuntimeException('La descarga de la foto quedó incompleta.');
                }
                $body .= $chunk;
            }
            if (strlen($body) >= $limit || @getimagesizefromstring($body) === false) {
                throw new \RuntimeException('La URL de la foto no devuelve una imagen válida de menos de 10 MB.');
            }
            return hash('sha256', $body);
        } finally {
            $stream->close();
        }
    }

    public function delete_image_from_article(Article $article, Image $image, callable $deleteLocal = null): void
    {
        $key = 'tn-img:'.sha1(DB::connection()->getDatabaseName().':'.$this->store_id.':'.$article->tiendanube_product_id);
        $locked = (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS tomado', [$key])->tomado === 1;
        if (!$locked) {
            throw new \RuntimeException('La sincronización de imágenes de este producto ya está en curso.');
        }
        try {
            $image->refresh();
            $ids = array_values(array_unique(array_filter([$image->tienda_nube_image_id, $image->tiendanube_image_id])));
            if (count($ids) === 0) {
                $this->assert_lock_owned($key);
                if ($deleteLocal) { $deleteLocal(); }
                return;
            }
            if (count($ids) > 1 || !$article->tiendanube_product_id) {
                throw new \RuntimeException('Marcadores de imagen ambiguos; revisar antes de eliminar.');
            }
            $remote = $this->remote_images($article);
            $exists = collect($remote)->contains(function ($item) use ($ids) { return (string) $item['id'] === (string) $ids[0]; });
            $this->assert_lock_owned($key);
            if (!$exists) {
                $image->tienda_nube_image_id = null;
                $image->tiendanube_image_id = null;
                $image->save();
                if ($deleteLocal) { $deleteLocal(); }
                return;
            }
            $response = $this->http_without_throw_on_error_status()->delete($this->endpoint($article).'/'.$ids[0]);
            if (!$response->successful() && $response->status() !== 404) {
                throw new \RuntimeException('Error al eliminar la imagen de Tienda Nube.');
            }
            $this->assert_lock_owned($key);
            $image->tienda_nube_image_id = null;
            $image->tiendanube_image_id = null;
            $image->save();
            if ($deleteLocal) { $deleteLocal(); }
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }
}
