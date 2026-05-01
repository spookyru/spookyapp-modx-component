<?php
// filepath: core/components/spookyapp/src/Snippets/ProductSnippet.php

/**
 * SpookyApp — ProductSnippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Сниппет для вывода информации о товаре.
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

namespace SpookyApp\Snippets;

use SpookyApp\Services\API\ProductSearchService;

class ProductSnippet extends BaseChunkSnippet
{
    protected function getContentType(): string
    {
        return 'product';
    }

    protected function getDefaultTemplate(): string
    {
        return 'product';
    }

    protected function getServiceClass(): string
    {
        return ProductSearchService::class;
    }

    protected function getServiceMethod(): string
    {
        return 'getProduct';
    }

    /**
     * Подготовка placeholders из данных БД.
     */
    protected function prepareData(array $raw): array
    {
        // ── Базовые поля ────────────────────────────────────
        $data = [
            'title'         => $raw['title'] ?? ($raw['name'] ?? ''),
            'description'   => $raw['description'] ?? '',
            'poster_path'   => $raw['poster_path'] ?? ($raw['image'] ?? ''),
            'price'         => $raw['price'] ?? '',
            'old_price'     => $raw['old_price'] ?? '',
            'currency'      => $raw['currency'] ?? 'RUB',
            'brand'         => $raw['brand'] ?? '',
            'category'      => $raw['category'] ?? '',
            'source_url'    => $raw['source_url'] ?? ($raw['url'] ?? ''),
        ];

        // ── Наличие ─────────────────────────────────────────
        if (isset($raw['in_stock'])) {
            $data['in_stock'] = $raw['in_stock'] ? '1' : '0';
        } else {
            $data['in_stock'] = '1'; // По умолчанию — в наличии
        }

        // ── Рейтинг / отзывы ───────────────────────────────
        $data['rating']        = $raw['rating'] ?? '';
        $data['reviews_count'] = $raw['reviews_count'] ?? '';

        // ── Особенности (features) ──────────────────────────
        $data['features'] = '';
        $features = $raw['features'] ?? [];

        if (is_array($features) && !empty($features)) {
            $html = '<ul>';
            foreach ($features as $feature) {
                $text = is_array($feature)
                    ? ($feature['text'] ?? ($feature['name'] ?? ''))
                    : (string) $feature;
                if ($text) {
                    $html .= '<li>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</li>';
                }
            }
            $html .= '</ul>';
            $data['features'] = $html;
        } elseif (is_string($features) && !empty($features)) {
            $data['features'] = $features;
        }

        // ── Офферы (партнёрские ссылки) ─────────────────────
        $data['offers'] = '';
        $offers = $raw['offers'] ?? [];

        if (is_array($offers) && !empty($offers)) {
            $html = '';
            foreach ($offers as $offer) {
                $offerName  = htmlspecialchars($offer['name'] ?? ($offer['store'] ?? ''), ENT_QUOTES, 'UTF-8');
                $offerUrl   = $offer['url'] ?? ($offer['link'] ?? '#');
                $offerPrice = $offer['price'] ?? '';

                $html .= '<div class="spookyapp-card__offer-item">';
                $html .= '<a href="' . htmlspecialchars($offerUrl, ENT_QUOTES, 'UTF-8') . '" '
                    . 'target="_blank" rel="noopener noreferrer nofollow" '
                    . 'class="spookyapp-card__offer-link">';
                $html .= '<span class="spookyapp-card__offer-name">' . $offerName . '</span>';
                if ($offerPrice) {
                    $html .= ' <span class="spookyapp-card__offer-price">'
                        . htmlspecialchars($offerPrice, ENT_QUOTES, 'UTF-8')
                        . '</span>';
                }
                $html .= '</a>';
                $html .= '</div>';
            }
            $data['offers'] = $html;
        } elseif (is_string($offers) && !empty($offers)) {
            $data['offers'] = $offers;
        }

        return $data;
    }
}