<?php
// filepath: core/components/spookyapp/src/Snippets/TVShowSnippet.php

/**
 * SpookyApp — TVShowSnippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Сниппет для вывода информации о TV-сериале.
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

namespace SpookyApp\Snippets;

use SpookyApp\Services\API\TMDBService;

class TVShowSnippet extends BaseChunkSnippet
{
    protected function getContentType(): string
    {
        return 'tvshow';
    }

    protected function getDefaultTemplate(): string
    {
        return 'tvshow';
    }

    protected function getServiceClass(): string
    {
        return TMDBService::class;
    }

    protected function getServiceMethod(): string
    {
        return 'getTVShow';
    }

    /**
     * Подготовка placeholders из сырых данных TMDB / БД.
     */
    protected function prepareData(array $raw): array
    {
        $imgBase = 'https://image.tmdb.org/t/p/';

        // ── Базовые поля ────────────────────────────────────
        $data = [
            'title'               => $raw['name'] ?? ($raw['title'] ?? ''),
            'original_title'      => $raw['original_name'] ?? ($raw['original_title'] ?? ''),
            'tagline'             => $raw['tagline'] ?? '',
            'overview'            => $raw['overview'] ?? '',
            'first_air_date'      => $raw['first_air_date'] ?? '',
            'last_air_date'       => $raw['last_air_date'] ?? '',
            'number_of_seasons'   => $raw['number_of_seasons'] ?? '',
            'number_of_episodes'  => $raw['number_of_episodes'] ?? '',
            'status'              => $raw['status'] ?? '',
            'rating'              => $raw['vote_average'] ?? ($raw['rating'] ?? ''),
            'vote_count'          => $raw['vote_count'] ?? '',
            'imdb_id'             => $raw['external_ids']['imdb_id'] ?? ($raw['imdb_id'] ?? ''),
            'tmdb_id'             => $raw['id'] ?? ($raw['tmdb_id'] ?? ''),
            'homepage'            => $raw['homepage'] ?? '',
        ];

        // ── Длительность серии ──────────────────────────────
        $episodeRunTime = $raw['episode_run_time'] ?? [];
        if (is_array($episodeRunTime) && !empty($episodeRunTime)) {
            $data['episode_run_time'] = $episodeRunTime[0];
        } else {
            $data['episode_run_time'] = is_numeric($episodeRunTime) ? $episodeRunTime : '';
        }

        // ── Постер / Бэкдроп ───────────────────────────────
        if (!empty($raw['poster_path'])) {
            $poster = $raw['poster_path'];
            $data['poster_path'] = str_starts_with($poster, 'http')
                ? $poster
                : $imgBase . 'w500' . $poster;
        } else {
            $data['poster_path'] = $raw['poster_url'] ?? '';
        }

        if (!empty($raw['backdrop_path'])) {
            $backdrop = $raw['backdrop_path'];
            $data['backdrop_path'] = str_starts_with($backdrop, 'http')
                ? $backdrop
                : $imgBase . 'w1280' . $backdrop;
        } else {
            $data['backdrop_path'] = $raw['backdrop_url'] ?? '';
        }

        // ── Жанры ───────────────────────────────────────────
        if (!empty($raw['genres']) && is_array($raw['genres'])) {
            $data['genres'] = $this->pluckAndJoin($raw['genres'], 'name');
        } else {
            $data['genres'] = $raw['genres'] ?? '';
        }

        // ── Телеканалы / Платформы ──────────────────────────
        if (!empty($raw['networks']) && is_array($raw['networks'])) {
            $data['networks'] = $this->pluckAndJoin($raw['networks'], 'name');
        } else {
            $data['networks'] = $raw['networks'] ?? '';
        }

        // ── Создатели ───────────────────────────────────────
        if (!empty($raw['created_by']) && is_array($raw['created_by'])) {
            $data['created_by'] = $this->pluckAndJoin($raw['created_by'], 'name');
        } else {
            $data['created_by'] = $raw['created_by'] ?? '';
        }

        // ── Сезоны (HTML) ───────────────────────────────────
        $data['seasons'] = '';
        $seasons = $raw['seasons'] ?? [];

        if (is_array($seasons) && !empty($seasons)) {
            $seasonsHtml = '';
            foreach ($seasons as $season) {
                $sName     = htmlspecialchars($season['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $sEpisodes = $season['episode_count'] ?? 0;
                $sDate     = $season['air_date'] ?? '';
                $sPoster   = '';

                if (!empty($season['poster_path'])) {
                    $sp = $season['poster_path'];
                    $sPoster = str_starts_with($sp, 'http') ? $sp : $imgBase . 'w185' . $sp;
                }

                $seasonsHtml .= '<div class="spookyapp-card__season-item">';
                if ($sPoster) {
                    $seasonsHtml .= '<img src="' . $sPoster . '" alt="' . $sName . '" '
                        . 'loading="lazy" class="spookyapp-card__season-poster" />';
                }
                $seasonsHtml .= '<p class="spookyapp-card__season-name">' . $sName . '</p>';
                $seasonsHtml .= '<p class="spookyapp-card__season-info">'
                    . $sEpisodes . ' серий'
                    . ($sDate ? ' · ' . $sDate : '')
                    . '</p>';
                $seasonsHtml .= '</div>';
            }
            $data['seasons'] = $seasonsHtml;
        }

        // ── Актёры (топ 10) ─────────────────────────────────
        $data['cast'] = '';
        $castItems = $raw['credits']['cast'] ?? ($raw['cast'] ?? []);

        if (is_array($castItems) && !empty($castItems)) {
            $topCast = $this->limitArray($castItems, 10);
            $castHtml = '';

            foreach ($topCast as $actor) {
                $name      = htmlspecialchars($actor['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $character = htmlspecialchars($actor['character'] ?? '', ENT_QUOTES, 'UTF-8');
                $photo     = '';

                if (!empty($actor['profile_path'])) {
                    $pp = $actor['profile_path'];
                    $photo = str_starts_with($pp, 'http') ? $pp : $imgBase . 'w185' . $pp;
                }

                $castHtml .= '<div class="spookyapp-card__cast-item">';
                if ($photo) {
                    $castHtml .= '<img src="' . $photo . '" alt="' . $name . '" '
                        . 'loading="lazy" class="spookyapp-card__cast-photo" />';
                }
                $castHtml .= '<p class="spookyapp-card__cast-name">' . $name . '</p>';
                if ($character) {
                    $castHtml .= '<p class="spookyapp-card__cast-role">' . $character . '</p>';
                }
                $castHtml .= '</div>';
            }
            $data['cast'] = $castHtml;
        }

        // ── Аудио ───────────────────────────────────────────
        $data['audio_url'] = $raw['audio_url'] ?? '';

        return $data;
    }
}