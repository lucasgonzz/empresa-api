<?php

namespace App\Mail;

/**
 * Contenido estructurado para correos transaccionales ComercioCity.
 *
 * Recibe un único array asociativo; cada clave corresponde a una parte del mail:
 *
 * - subject (string)      Asunto del correo
 * - title (string)      Titular principal del cuerpo
 * - paragraphs (string[]) Párrafos de texto, en orden
 * - detail_lines (array)  Lista de filas "etiqueta: valor". Cada ítem: label, value, opcional bold_label (bool)
 * - links (array)         Enlaces en el cuerpo. Cada ítem: text, url
 * - closing (string|null) Texto de cierre (p. ej. agradecimiento), opcional
 * - preheader (string|null) Texto de previsualización junto al asunto, opcional
 * - footer_links (array)  Íconos del pie. Cada ítem: img_url, link_url. Si no se envía, se usa defaultFooterLinks()
 */
class ComercioCityMailPayload
{
    /** @var string */
    public $subject;

    /** @var string */
    public $title;

    /** @var string[] */
    public $paragraphs;

    /**
     * Líneas tipo "Etiqueta: valor" (p. ej. detalle de pago).
     *
     * @var array<int, array<string, mixed>>
     */
    public $detail_lines;

    /**
     * Enlaces mostrados en el cuerpo (texto + URL absoluta o relativa al sitio).
     *
     * @var array<int, array<string, string>>
     */
    public $links;

    /** @var string|null */
    public $closing;

    /** @var string|null Texto oculto junto al asunto en muchos clientes */
    public $preheader;

    /**
     * Enlaces del pie con imagen clickeable. Cada ítem: img_url (absoluta), link_url (destino).
     *
     * @var array<int, array<string, string>>
     */
    public $footer_links;

    /**
     * Valores por defecto del pie: editá img_url y link_url acá o pasá "footer_links" en el constructor.
     *
     * @return array<int, array<string, string>>
     */
    public static function defaultFooterLinks()
    {
        return [
            [
                'img_url' => 'https://api.comerciocity.com/public/storage/www.png',
                'link_url' => 'https://comerciocity.com',
            ],
            [
                'img_url' => 'https://api.comerciocity.com/public/storage/instagram.png',
                'link_url' => 'https://www.instagram.com/comerciocity_com',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data Claves: subject, title, paragraphs?, detail_lines?, links?, closing?, preheader?, footer_links?
     */
    public function __construct(array $data)
    {
        $this->subject = isset($data['subject']) ? (string) $data['subject'] : '';
        $this->title = isset($data['title']) ? (string) $data['title'] : '';
        $this->paragraphs = isset($data['paragraphs']) && is_array($data['paragraphs'])
            ? $data['paragraphs']
            : [];
        $this->detail_lines = isset($data['detail_lines']) && is_array($data['detail_lines'])
            ? $data['detail_lines']
            : [];
        $this->links = isset($data['links']) && is_array($data['links'])
            ? $data['links']
            : [];
        $this->closing = array_key_exists('closing', $data) ? $data['closing'] : null;
        $this->preheader = array_key_exists('preheader', $data) ? $data['preheader'] : null;

        if (isset($data['footer_links']) && is_array($data['footer_links'])) {
            $this->footer_links = self::normalizeFooterLinks($data['footer_links']);
        } else {
            $this->footer_links = self::normalizeFooterLinks(self::defaultFooterLinks());
        }
    }

    /**
     * @param array<int, mixed> $links
     *
     * @return array<int, array<string, string>>
     */
    private static function normalizeFooterLinks(array $links)
    {
        $out = [];
        foreach ($links as $row) {
            if (!is_array($row)) {
                continue;
            }
            $imgUrl = isset($row['img_url']) ? trim((string) $row['img_url']) : '';
            $linkUrl = isset($row['link_url']) ? trim((string) $row['link_url']) : '';
            if ($imgUrl === '' || $linkUrl === '') {
                continue;
            }
            $out[] = [
                'img_url' => $imgUrl,
                'link_url' => $linkUrl,
            ];
        }

        return $out;
    }
}
