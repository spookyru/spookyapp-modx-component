<?php
// filepath: core/components/spookyapp/src/Snippets/PersonSnippet.php

/**
 * SpookyApp — PersonSnippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Сниппет для вывода информации о персоне (актёр, режиссёр).
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

namespace SpookyApp\Snippets;

use SpookyApp\Services\API\TMDBService;

class PersonSnippet extends BaseChunkSnippet
{
    protected function getContentType(): string
    {
        return 'person';
    }

    protected function getDefaultTemplate(): string
    {
        return 'person';
    }

    protected function getServiceClass(): string
    {
        return TMDBService::class;
    }

    protected function getServiceMethod(): string
    {
        return 'getPerson';
    }

    /**
     * Подготовка placeholders из данных TMDB / БД.
     */
    protected function prepareData(array $raw): array
    {
        $imgBase = 'https://image.tmdb.org/t/p/';

        // ── Базовые поля ────────────────────────────────────
        $data = [
            'name'                  => $raw['name'] ?? '',
            'birthday'              => $raw['birthday'] ?? '',
            'deathday'              => $raw['deathday'] ?? '',
            'place_of_birth'        => $raw['place_of_birth'] ?? '',
            'biography'             => $raw['biography'] ?? '',
            'known_for_department'  => $raw['known_for_department'] ?? '',
            'popularity'            => $raw['popularity'] ?? '',
            'imdb_id'               => $raw['imdb_id'] ?? '',
            'tmdb_id'               => $raw['id'] ?? ($raw['tmdb_id'] ?? ''),
            'homepage'              => $raw['homepage'] ?? '',
            'wikipedia_url'         => $raw['wikipedia_url'] ?? '',
        ];

        // ── Фото ────────────────────────────────────────────
        if (!empty($raw['profile_path'])) {
            $pp = $raw['profile_path'];
            $data['poster_path'] = str_starts_with($pp, 'http')
                ? $pp
                : $imgBase . 'w500' . $pp;
        } else {
            $data['poster_path'] = $raw['poster_path'] ?? ($raw['photo'] ?? '');
        }

        // ── Другие имена ────────────────────────────────────
        if (!empty($raw['also_known_as']) && is_array($raw['also_known_as'])) {
            $data['also_known_as'] = implode(', ', $raw['also_known_as']);
        } else {
            $data['also_known_as'] = $raw['also_known_as'] ?? '';
        }

        // ── Возраст ─────────────────────────────────────────
        $data['age'] = '';
        if (!empty($data['birthday'])) {
            try {
                $birthDate = new \DateTime($data['birthday']);
                $refDate   = !empty($data['deathday'])
                    ? new \DateTime($data['deathday'])
                    : new \DateTime();

                $data['age'] = (string) $refDate->diff($birthDate)->y;
            } catch (\Exception $e) {
                // Не удалось рассчитать возраст — оставляем пустым
            }
        }

        // ── Фильмография (топ 10 по popularity) ─────────────
        $data['filmography'] = '';
        $credits = $raw['combined_credits']['cast']
            ?? ($raw['movie_credits']['cast']
                ?? ($raw['filmography'] ?? []));

        if (is_array($credits) && !empty($credits)) {
            // Сортируем по popularity (desc)
            usort($credits, function ($a, $b) {
                return ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0);
            });

            $topCredits = $this->limitArray($credits, 10);
            $html = '';

            foreach ($topCredits as $credit) {
                $cTitle     = htmlspecialchars($credit['title'] ?? ($credit['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $cCharacter = htmlspecialchars($credit['character'] ?? '', ENT_QUOTES, 'UTF-8');
                $cDate      = $credit['release_date'] ?? ($credit['first_air_date'] ?? '');
                $cYear      = $cDate ? substr($cDate, 0, 4) : '';
                $cType      = $credit['media_type'] ?? 'movie';

                $html .= '<div class="spookyapp-card__filmography-item">';
                $html .= '<span class="spookyapp-card__filmography-year">'
                    . ($cYear ?: '—')
                    . '</span> ';
                $html .= '<span class="spookyapp-card__filmography-title">' . $cTitle . '</span>';
                if ($cCharacter) {
                    $html .= ' <span class="spookyapp-card__filmography-role">— ' . $cCharacter . '</span>';
                }
                $html .= '</div>';
            }

            $data['filmography'] = $html;
        }

        // ── Аудио ───────────────────────────────────────────
        $data['audio_url'] = $raw['audio_url'] ?? '';

        return $data;
    }
}