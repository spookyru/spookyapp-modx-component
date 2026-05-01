<?php
// filepath: core/components/spookyapp/src/Snippets/MovieSnippet.php

/**
 * SpookyApp — MovieSnippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Сниппет для вывода информации о фильме.
 * Получает данные из TMDB API или из таблицы spookyapp_chunks,
 * подготавливает placeholders и рендерит movie.chunk.tpl.
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

namespace SpookyApp\Snippets;

use SpookyApp\Services\API\TMDBService;

class MovieSnippet extends BaseChunkSnippet
{
    protected function getContentType(): string
    {
        return 'movie';
    }

    protected function getDefaultTemplate(): string
    {
        return 'movie';
    }

    protected function getServiceClass(): string
    {
        return TMDBService::class;
    }

    protected function getServiceMethod(): string
    {
        return 'getMovie';
    }

    /**
     * Подготовка placeholders из сырых данных TMDB / БД.
     *
     * @param  array $raw
     * @return array
     */
    protected function prepareData(array $raw): array
    {
        // ── Базовые поля ────────────────────────────────────
        $data = [
            'title'          => $raw['title'] ?? '',
            'original_title' => $raw['original_title'] ?? '',
            'tagline'        => $raw['tagline'] ?? '',
            'overview'       => $raw['overview'] ?? '',
            'release_date'   => $raw['release_date'] ?? '',
            'runtime'        => $raw['runtime'] ?? '',
            'status'         => $raw['status'] ?? '',
            'budget'         => $this->formatMoney($raw['budget'] ?? 0),
            'revenue'        => $this->formatMoney($raw['revenue'] ?? 0),
            'rating'         => $raw['vote_average'] ?? ($raw['rating'] ?? ''),
            'vote_count'     => $raw['vote_count'] ?? '',
            'imdb_id'        => $raw['imdb_id'] ?? '',
            'tmdb_id'        => $raw['id'] ?? ($raw['tmdb_id'] ?? ''),
            'homepage'       => $raw['homepage'] ?? '',
        ];

        // ── Постер и бэкдроп ────────────────────────────────
        $imgBase = 'https://image.tmdb.org/t/p/';
        if (!empty($raw['poster_path'])) {
            $poster = $raw['poster_path'];
            $data['poster_path'] = (str_starts_with($poster, 'http'))
                ? $poster
                : $imgBase . 'w500' . $poster;
        } else {
            $data['poster_path'] = $raw['poster_url'] ?? '';
        }

        if (!empty($raw['backdrop_path'])) {
            $backdrop = $raw['backdrop_path'];
            $data['backdrop_path'] = (str_starts_with($backdrop, 'http'))
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

        // ── Страны ──────────────────────────────────────────
        if (!empty($raw['production_countries']) && is_array($raw['production_countries'])) {
            $data['countries'] = $this->pluckAndJoin($raw['production_countries'], 'name');
        } else {
            $data['countries'] = $raw['countries'] ?? '';
        }

        // ── Съёмочная группа ────────────────────────────────
        $data['director'] = '';
        $data['writer']   = '';

        if (!empty($raw['credits']['crew']) && is_array($raw['credits']['crew'])) {
            $directors = [];
            $writers   = [];

            foreach ($raw['credits']['crew'] as $member) {
                $job = $member['job'] ?? '';
                if ($job === 'Director') {
                    $directors[] = $member['name'] ?? '';
                }
                if (in_array($job, ['Writer', 'Screenplay', 'Story'])) {
                    $writers[] = $member['name'] ?? '';
                }
            }

            $data['director'] = implode(', ', array_unique(array_filter($directors)));
            $data['writer']   = implode(', ', array_unique(array_filter($writers)));
        } elseif (!empty($raw['director'])) {
            $data['director'] = $raw['director'];
            $data['writer']   = $raw['writer'] ?? '';
        }

        // ── Актёры (топ 10) ─────────────────────────────────
        $data['cast'] = '';
        $castItems = $raw['credits']['cast']
            ?? ($raw['cast'] ?? []);

        if (is_array($castItems) && !empty($castItems)) {
            $topCast = $this->limitArray($castItems, 10);
            $castHtml = '';

            foreach ($topCast as $actor) {
                $name      = htmlspecialchars($actor['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $character = htmlspecialchars($actor['character'] ?? '', ENT_QUOTES, 'UTF-8');
                $photo     = '';

                if (!empty($actor['profile_path'])) {
                    $profilePath = $actor['profile_path'];
                    $photo = (str_starts_with($profilePath, 'http'))
                        ? $profilePath
                        : $imgBase . 'w185' . $profilePath;
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

    /**
     * Форматировать сумму денег.
     */
    private function formatMoney($amount): string
    {
        $amount = (int) $amount;
        if ($amount <= 0) {
            return '';
        }
        return number_format($amount, 0, '.', ',');
    }
}