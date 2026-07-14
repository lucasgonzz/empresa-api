<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sintetiza las descripciones de tienda de un articulo a partir de la evidencia recolectada
 * por ProductInfoLookupService. NO busca informacion por su cuenta: solo trabaja con lo que
 * se le entrega.
 *
 * Contrato duro: Claude no puede afirmar nada que no este en la evidencia. Si la evidencia no
 * identifica el producto de forma inequivoca, devuelve found=false y no se genera nada.
 */
class ArticleDescriptionAiService
{
    /** @var string Modelo de Claude a utilizar. */
    const CLAUDE_MODEL = 'claude-sonnet-4-5';

    /** @var int Tokens maximos de la respuesta. */
    const MAX_TOKENS = 1500;

    /** @var array Orden de menor a mayor confianza (para calcular el minimo). */
    const CONFIDENCE_ORDER = ['low', 'medium', 'high'];

    /**
     * Genera las secciones de descripcion para un articulo.
     *
     * @param  Article $article
     * @param  array   $lookup  Salida de ProductInfoLookupService::lookup()
     * @return array {
     *     found:      bool,
     *     sections:   array   [{title, content}]
     *     confidence: string|null  'high' | 'medium' | 'low'
     *     reason:     string|null  motivo cuando found=false (para el log y el modal)
     *     sources:    array   [{source, url, title}]  trazabilidad, se guarda en ai_sources
     * }
     *
     * @throws \RuntimeException  Si ANTHROPIC_API_KEY no esta configurada en el entorno.
     */
    public function generate(Article $article, array $lookup): array
    {
        /*
         * Sin evidencia (ProductInfoLookupService no encontro nada), no hay nada que sintetizar.
         * No se llama a Claude: no tiene sentido pedirle que describa un producto sin datos.
         */
        if (!$lookup['found'] || empty($lookup['evidence'])) {
            return $this->not_found('No se encontro informacion sobre este producto en ninguna fuente.');
        }

        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            throw new \RuntimeException('La clave ANTHROPIC_API_KEY no esta configurada en el entorno.');
        }

        $system_prompt = $this->build_system_prompt();
        $user_prompt   = $this->build_user_prompt($article, $lookup);

        try {
            /* Mismo patron de cliente HTTP que AiExcelAnalyzer::build_anthropic_http_client(). */
            $response = $this->build_anthropic_http_client($api_key)
                ->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => self::CLAUDE_MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'system'     => $system_prompt,
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => $user_prompt,
                        ],
                    ],
                ]);
        } catch (\Exception $e) {
            Log::info('[DescripcionesIA] Error de conexion con Anthropic.', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);
            return $this->not_found('No se pudo contactar al servicio de IA.');
        }

        if (!$response->successful()) {
            $body = $response->json();
            $api_error = isset($body['error']['message'])
                ? $body['error']['message']
                : 'HTTP '.$response->status();

            Log::info('[DescripcionesIA] Anthropic respondio con error.', [
                'article_id' => $article->id,
                'error'      => $api_error,
            ]);

            return $this->not_found('El servicio de IA devolvio un error.');
        }

        $parsed = $this->parse_response($response->json());

        if ($parsed === null) {
            Log::info('[DescripcionesIA] No se pudo parsear la respuesta de Claude.', [
                'article_id' => $article->id,
            ]);
            return $this->not_found('La respuesta de la IA no pudo interpretarse.');
        }

        if (!$parsed['found'] || empty($parsed['sections'])) {
            $reason = isset($parsed['reason']) && $parsed['reason'] !== ''
                ? $parsed['reason']
                : 'La informacion encontrada no permite describir este producto con certeza.';

            Log::info('[DescripcionesIA] Claude no pudo generar descripcion con la evidencia dada.', [
                'article_id' => $article->id,
                'reason'     => $reason,
            ]);

            return $this->not_found($reason);
        }

        /*
         * Confianza final = la MENOR entre el techo de la fuente y la que declaro Claude.
         * Claude solo puede BAJAR la confianza, nunca subirla por encima de lo que la
         * fuente permite. Sin este anclaje, un modelo se autocalifica "alta" casi siempre.
         */
        $confidence = $this->min_confidence(
            $lookup['max_confidence'],
            isset($parsed['confidence']) ? $parsed['confidence'] : 'low'
        );

        return [
            'found'      => true,
            'sections'   => $parsed['sections'],
            'confidence' => $confidence,
            'reason'     => null,
            'sources'    => $this->build_sources($lookup),
        ];
    }

    /**
     * Resultado negativo uniforme.
     *
     * @param  string $reason
     * @return array
     */
    protected function not_found(string $reason): array
    {
        return [
            'found'      => false,
            'sections'   => [],
            'confidence' => null,
            'reason'     => $reason,
            'sources'    => [],
        ];
    }

    /**
     * Devuelve la MENOR de dos confianzas segun CONFIDENCE_ORDER.
     *
     * @param  string|null $a
     * @param  string|null $b
     * @return string
     */
    protected function min_confidence($a, $b): string
    {
        $index_a = array_search($a, self::CONFIDENCE_ORDER, true);
        $index_b = array_search($b, self::CONFIDENCE_ORDER, true);

        /* Valor desconocido o ausente: se trata como 'low' (indice 0), nunca se asume lo mejor. */
        if ($index_a === false) {
            $index_a = 0;
        }

        if ($index_b === false) {
            $index_b = 0;
        }

        return self::CONFIDENCE_ORDER[min($index_a, $index_b)];
    }

    /**
     * Fuentes usadas, para guardar en descriptions.ai_sources (auditoria).
     *
     * @param  array $lookup
     * @return array
     */
    protected function build_sources(array $lookup): array
    {
        $sources = [];

        foreach ($lookup['evidence'] as $item) {
            $sources[] = [
                'source' => isset($item['source']) ? $item['source'] : $lookup['source'],
                'url'    => isset($item['url'])    ? $item['url']    : '',
                'title'  => isset($item['title'])  ? $item['title']  : '',
            ];
        }

        return $sources;
    }

    /**
     * System prompt. Las reglas anti-invencion son el nucleo de la funcionalidad:
     * una spec inventada sobre un articulo de ferreteria (voltaje, rosca, medida, material)
     * le miente al comprador y le genera un reclamo real al cliente.
     *
     * @return string
     */
    protected function build_system_prompt(): string
    {
        return implode("\n", [
            'Sos un redactor de fichas de producto para el ecommerce de un comercio argentino',
            '(ferreterias, distribuidoras, mayoristas). Escribis en espanol rioplatense, en un',
            'tono claro y sobrio, sin exageraciones publicitarias.',
            '',
            'REGLAS ABSOLUTAS (violarlas es peor que no generar nada):',
            '',
            '1. Solo podes afirmar lo que aparece EXPLICITAMENTE en la evidencia que se te entrega.',
            '   No completes datos de memoria ni por inferencia sobre lo que "suele" tener un',
            '   producto de esa categoria.',
            '',
            '2. Prohibido inventar especificaciones tecnicas. Si la evidencia no dice el material,',
            '   la medida, el voltaje, la potencia, la rosca, el peso o la composicion, esos datos',
            '   NO se mencionan. Se omite la seccion entera antes que rellenarla.',
            '',
            '3. Prohibido el relleno de marketing generico. Frases como "excelente calidad", "ideal',
            '   para el hogar", "la mejor opcion del mercado" o "producto de primera linea" no',
            '   aportan nada y estan prohibidas. Si sacando el relleno no queda contenido, entonces',
            '   no hay contenido.',
            '',
            '4. Si la evidencia no identifica el producto de forma inequivoca (resultados que hablan',
            '   de productos distintos, informacion demasiado vaga, o nada que se corresponda con el',
            '   articulo), devolves found=false. NO es un fracaso: es la respuesta correcta.',
            '',
            '5. La confianza que declares debe reflejar cuan bien la evidencia identifica ESTE',
            '   producto en particular:',
            '   - "high": la evidencia identifica el producto exacto (marca + modelo/medida) y las',
            '     afirmaciones estan respaldadas de forma directa.',
            '   - "medium": la evidencia identifica el producto con razonable certeza, pero algun',
            '     detalle se apoya en una sola fuente.',
            '   - "low": la evidencia es generica, ambigua, o describe una familia de productos mas',
            '     que este articulo puntual.',
            '',
            'FORMATO DE SALIDA:',
            'Respondes UNICAMENTE con un objeto JSON valido, sin texto antes ni despues, sin',
            'backticks, sin markdown. Estructura exacta:',
            '',
            '{',
            '  "found": true,',
            '  "confidence": "high",',
            '  "sections": [',
            '    {"title": "Descripcion del producto", "content": "..."},',
            '    {"title": "Caracteristicas", "content": "..."}',
            '  ]',
            '}',
            '',
            'o bien, cuando no se puede describir el producto:',
            '',
            '{"found": false, "reason": "motivo breve en espanol"}',
            '',
            'Sobre las secciones:',
            '- Entre 1 y 3 secciones. Menos es mejor que mas.',
            '- "title" es un encabezado corto (ej: "Descripcion del producto", "Caracteristicas",',
            '  "Uso recomendado"). "content" es texto plano, sin HTML ni markdown.',
            '- La primera seccion siempre describe que ES el producto, en 2 a 4 oraciones.',
            '- Solo agregas una seccion de caracteristicas si tenes datos concretos y respaldados',
            '  para poner ahi.',
        ]);
    }

    /**
     * User prompt: articulo + evidencia recolectada.
     *
     * @param  Article $article
     * @param  array   $lookup
     * @return string
     */
    protected function build_user_prompt(Article $article, array $lookup): string
    {
        $lines = [];

        $lines[] = 'ARTICULO DEL SISTEMA (lo que el comercio tiene cargado):';
        $lines[] = '- Nombre: '.($article->name ?: '(sin nombre)');
        $lines[] = '- Codigo de barras: '.($article->bar_code ?: '(sin codigo de barras)');

        if ($article->descripcion) {
            $lines[] = '- Descripcion interna cargada por el comercio: '.$article->descripcion;
        }

        if ($article->brand && $article->brand->name) {
            $lines[] = '- Marca cargada en el sistema: '.$article->brand->name;
        }

        $lines[] = '';
        $lines[] = 'COMO SE OBTUVO LA EVIDENCIA: '.$this->describe_source($lookup['source']);
        $lines[] = 'BUSQUEDA REALIZADA: '.$lookup['query'];
        $lines[] = '';

        if (!empty($lookup['structured'])) {
            $lines[] = 'DATOS ESTRUCTURADOS DE LA BASE DE CODIGOS DE BARRA (alta confiabilidad):';
            foreach ($lookup['structured'] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $lines[] = '- '.$key.': '.$value;
                }
            }
            $lines[] = '';
        }

        $lines[] = 'EVIDENCIA ENCONTRADA:';
        $lines[] = '';

        $index = 1;
        foreach ($lookup['evidence'] as $item) {
            $lines[] = 'Resultado '.$index.':';
            $lines[] = '  Titulo: '.$item['title'];

            if (isset($item['snippet']) && $item['snippet'] !== '') {
                $lines[] = '  Texto: '.$item['snippet'];
            }

            if (isset($item['url']) && $item['url'] !== '') {
                $lines[] = '  URL: '.$item['url'];
            }

            $lines[] = '';
            $index++;
        }

        $lines[] = 'Generá la ficha de producto respetando las REGLAS ABSOLUTAS. Recorda: si la';
        $lines[] = 'evidencia de arriba no identifica este producto con certeza, devolve found=false.';

        return implode("\n", $lines);
    }

    /**
     * Descripcion legible de la fuente, para que Claude sepa cuanto pesa la evidencia.
     *
     * @param  string|null $source
     * @return string
     */
    protected function describe_source($source): string
    {
        if ($source === 'barcode_api') {
            return 'Consulta directa a una base de datos de codigos de barra por el GTIN del articulo (match exacto).';
        }

        if ($source === 'google_bar_code') {
            return 'Busqueda web del codigo de barras del articulo.';
        }

        return 'Busqueda web del nombre del articulo (fuente mas debil: el nombre puede ser generico o propio del comercio).';
    }

    /**
     * Parsea el JSON que devuelve Claude. Tolera backticks o texto alrededor.
     *
     * @param  array $body Respuesta completa de la API de Anthropic.
     * @return array|null
     */
    protected function parse_response(array $body)
    {
        if (!isset($body['content']) || !is_array($body['content'])) {
            return null;
        }

        $text = '';
        foreach ($body['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                $text .= $block['text'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        /* Sacar backticks/markdown por si el modelo los agrega igual. */
        $text = preg_replace('/^```(?:json)?/i', '', trim($text));
        $text = preg_replace('/```$/', '', trim($text));
        $text = trim($text);

        /* Quedarse con el primer objeto JSON balanceado del texto. */
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($text, $start, $end - $start + 1);

        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['found'])) {
            return null;
        }

        if (!$decoded['found']) {
            return [
                'found'  => false,
                'reason' => isset($decoded['reason']) ? (string) $decoded['reason'] : '',
            ];
        }

        if (!isset($decoded['sections']) || !is_array($decoded['sections'])) {
            return null;
        }

        $sections = [];

        foreach ($decoded['sections'] as $section) {
            if (!isset($section['title']) || !isset($section['content'])) {
                continue;
            }

            $title   = trim((string) $section['title']);
            $content = trim((string) $section['content']);

            if ($title === '' || $content === '') {
                continue;
            }

            $sections[] = [
                'title'   => $title,
                'content' => $content,
            ];
        }

        if (empty($sections)) {
            return null;
        }

        return [
            'found'      => true,
            'confidence' => isset($decoded['confidence']) ? (string) $decoded['confidence'] : 'low',
            'sections'   => $sections,
        ];
    }

    /**
     * Cliente HTTP hacia Anthropic con headers y TLS del entorno.
     * Mismo patron que AiExcelAnalyzer::build_anthropic_http_client().
     *
     * @param  string $api_key
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_anthropic_http_client(string $api_key)
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ]);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
