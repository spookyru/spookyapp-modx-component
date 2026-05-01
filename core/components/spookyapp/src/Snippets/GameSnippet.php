<?php
// filepath: core/components/spookyapp/src/Snippets/GameSnippet.php

/**
 * SpookyApp — GameSnippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Сниппет для вывода информации об игре (RAWG API).
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

namespace SpookyApp\Snippets;

use SpookyApp\Services\API\GamesAPIService;

class GameSnippet extends BaseChunkSnippet
{
    protected function getContentType(): string
    {
        return 'game';
    }

    protected function getDefaultTemplate(): string
    {
        return 'game';
    }

    protected function getServiceClass(): string
    {
        return GamesAPIService::class;
    }

    protected function getServiceMethod(): string
    {
        return 'getGame';
    }

    /**
     * Подготовка placeholders из данных RAWG / БД.
     */
    protected function prepareData(array $raw): array
    {
        // ── Базовые поля ────────────────────────────────────
        $data = [
            'title'       => $raw['name'] ?? ($raw['title'] ?? ''),
            'description' => $raw['description'] ?? '',
            'released'    => $raw['released'] ?? '',
            'rating'      => $raw['rating'] ?? '',
            'metacritic'  => $raw['metacritic'] ?? '',
            'playtime'    => $raw['playtime'] ?? '',
            'rawg_id'     => $raw['id'] ?? ($raw['rawg_id'] ?? ''),
            'website'     => $raw['website'] ?? '',
        ];

        // ── Изображения ─────────────────────────────────────
        $data['poster_path']   = $raw['background_image'] ?? ($raw['poster_path'] ?? '');
        $data['backdrop_path'] = $raw['background_image_additional'] ?? ($raw['backdrop_path'] ?? '');

        // ── ESRB ────────────────────────────────────────────
        if (!empty($raw['esrb_rating']) && is_array($raw['esrb_rating'])) {
            $data['esrb_rating'] = $raw['esrb_rating']['name'] ?? '';
        } else {
            $data['esrb_rating'] = $raw['esrb_rating'] ?? '';
        }

        // ── Платформы ───────────────────────────────────────
        if (!empty($raw['platforms']) && is_array($raw['platforms'])) {
            $platforms = [];
            foreach ($raw['platforms'] as $p) {
                $platforms[] = $p['platform']['name'] ?? ($p['name'] ?? '');
            }
            $data['platforms'] = implode(', ', array_filter($platforms));
        } elseif (!empty($raw['parent_platforms']) && is_array($raw['parent_platforms'])) {
            $platforms = [];
            foreach ($raw['parent_platforms'] as $p) {
                $platforms[] = $p['platform']['name'] ?? '';
            }
            $data['platforms'] = implode(', ', array_filter($platforms));
        } else {
            $data['platforms'] = $raw['platforms'] ?? '';
        }

        // ── Жанры ───────────────────────────────────────────
        if (!empty($raw['genres']) && is_array($raw['genres'])) {
            $data['genres'] = $this->pluckAndJoin($raw['genres'], 'name');
        } else {
            $data['genres'] = $raw['genres'] ?? '';
        }

        // ── Разработчики / Издатели ─────────────────────────
        if (!empty($raw['developers']) && is_array($raw['developers'])) {
            $data['developers'] = $this->pluckAndJoin($raw['developers'], 'name');
        } else {
            $data['developers'] = $raw['developers'] ?? '';
        }

        if (!empty($raw['publishers']) && is_array($raw['publishers'])) {
            $data['publishers'] = $this->pluckAndJoin($raw['publishers'], 'name');
        } else {
            $data['publishers'] = $raw['publishers'] ?? '';
        }

        // ── Магазины (Steam, Epic) ──────────────────────────
        $data['steam_url'] = '';
        $data['epic_url']  = '';

        if (!empty($raw['stores']) && is_array($raw['stores'])) {
            foreach ($raw['stores'] as $store) {
                $storeSlug = $store['store']['slug'] ?? '';
                $storeUrl  = $store['url'] ?? '';
                if ($storeSlug === 'steam' && !empty($storeUrl)) {
                    $data['steam_url'] = $storeUrl;
                }
                if ($storeSlug === 'epic-games' && !empty($storeUrl)) {
                    $data['epic_url'] = $storeUrl;
                }
            }
        }

        // Поля из БД (если сохранены напрямую)
        if (empty($data['steam_url'])) {
            $data['steam_url'] = $raw['steam_url'] ?? '';
        }
        if (empty($data['epic_url'])) {
            $data['epic_url'] = $raw['epic_url'] ?? '';
        }

        // ── Скриншоты (HTML) ────────────────────────────────
        $data['screenshots'] = '';
        $screenshots = $raw['screenshots'] ?? ($raw['short_screenshots'] ?? []);

        if (is_array($screenshots) && !empty($screenshots)) {
            $html = '';
            $limit = $this->limitArray($screenshots, 8);
            foreach ($limit as $ss) {
                $url = $ss['image'] ?? ($ss['url'] ?? '');
                if ($url) {
                    $html .= '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
                        . 'alt="Screenshot" loading="lazy" />';
                }
            }
            $data['screenshots'] = $html;
        }

        // ── Системные требования ────────────────────────────
        $data['requirements_min'] = '';
        $data['requirements_rec'] = '';

        if (!empty($raw['platforms']) && is_array($raw['platforms'])) {
            foreach ($raw['platforms'] as $p) {
                $platformSlug = $p['platform']['slug'] ?? '';
                if ($platformSlug === 'pc') {
                    $reqs = $p['requirements'] ?? [];
                    if (!empty($reqs['minimum'])) {
                        $data['requirements_min'] = $reqs['minimum'];
                    }
                    if (!empty($reqs['recommended'])) {
                        $data['requirements_rec'] = $reqs['recommended'];
                    }
                    break;
                }
            }
        }

        // Данные из БД
        if (empty($data['requirements_min'])) {
            $data['requirements_min'] = $raw['requirements_min'] ?? '';
        }
        if (empty($data['requirements_rec'])) {
            $data['requirements_rec'] = $raw['requirements_rec'] ?? '';
        }

        // ── Аудио ───────────────────────────────────────────
        $data['audio_url'] = $raw['audio_url'] ?? '';

        return $data;
    }
}