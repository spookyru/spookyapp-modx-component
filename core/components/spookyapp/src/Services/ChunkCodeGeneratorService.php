<?php

declare(strict_types=1);

namespace SpookyApp\Services;

use MODX\Revolution\modX;
use SpookyApp\Model\SpookyAppChunk;

/**
 * ChunkCodeGeneratorService — генерация кодов вставки для чанков.
 *
 * ═══════════════════════════════════════════════════════════════
 * Генерирует три варианта кода вставки для сохранённого чанка:
 *   1. MODX-вызов сниппета: [[SpookyAppChunk? &id=`123`]]
 *   2. Telegram-карточка (HTML для Bot API)
 *   3. HTML-блок (из chunk_code в БД)
 *
 * Источник данных: строка из таблицы spookyapp_chunks:
 *   id, type, external_id, title, data (JSON), chunk_code, created_at, updated_at
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Services
 */
class ChunkCodeGeneratorService
{
    private modX $modx;

    private const TABLE = 'spookyapp_chunks';

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Public API                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Сгенерировать все форматы кода вставки.
     *
     * @param array $chunkRow Строка из таблицы spookyapp_chunks
     * @return array{modx: string, telegram: string, html: string}
     */
    public function generateEmbedCodes(array $chunkRow): array
    {
        return [
            'modx'     => $this->generateModxCall($chunkRow),
            'telegram' => $this->generateTelegramCard($chunkRow),
            'html'     => $this->generateHtml($chunkRow),
        ];
    }

    /**
     * Сгенерировать MODX-вызов сниппета.
     *
     * @param array $chunkRow
     * @return string  [[SpookyAppChunk? &id=`123` &format=`html`]]
     */
    public function generateModxCall(array $chunkRow): string
    {
        $id = (int)($chunkRow['id'] ?? 0);
        return "[[SpookyAppChunk? &id=`{$id}` &format=`html`]]";
    }

    /**
     * Сгенерировать Telegram-карточку (Telegram HTML mode).
     *
     * @param array $chunkRow
     * @return string HTML для Telegram Bot API (parse_mode=HTML)
     */
    public function generateTelegramCard(array $chunkRow): string
    {
        $type    = (string)($chunkRow['type'] ?? '');
        $chunkId = (int)($chunkRow['id'] ?? 0);

        // Декодируем JSON с данными
        $data = [];
        if (!empty($chunkRow['data'])) {
            $decoded = json_decode((string)$chunkRow['data'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Fallback: title из строки таблицы
        if (empty($data['title']) && !empty($chunkRow['title'])) {
            $data['title'] = $chunkRow['title'];
        }

        switch ($type) {
            case 'movie':
                return $this->buildMovieCard($data, $chunkId, 'movie');
            case 'tv':
                return $this->buildTvCard($data, $chunkId);
            case 'person':
                return $this->buildPersonCard($data, $chunkId);
            case 'game':
                return $this->buildGameCard($data, $chunkId);
            case 'device':
                return $this->buildDeviceCard($data, $chunkId);
            case 'product':
                return $this->buildProductCard($data, $chunkId);
            case 'football':
            case 'biathlon':
            case 'flashsport':
                return $this->buildSportCard($data, $chunkId, $type);
            case 'github':
                return $this->buildGithubCard($data, $chunkId);
            default:
                return $this->buildGenericCard($data, $chunkId, $type);
        }
    }

    /**
     * Вернуть HTML-код чанка из БД.
     *
     * @param array $chunkRow
     * @return string
     */
    public function generateHtml(array $chunkRow): string
    {
        return (string)($chunkRow['chunk_code'] ?? '');
    }

    /**
     * Получить строку чанка из БД по id через xPDO.
     *
     * @param int $id DB id
     * @return array|null
     */
    public function fetchById(int $id): ?array
    {
        // Регистрируем пакет (идемпотентно; prefix берётся из конфига MODX)
        $this->modx->addPackage(
            'SpookyApp\\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/Model/'
        );

        /** @var SpookyAppChunk|null $obj */
        $obj = $this->modx->getObject(SpookyAppChunk::class, $id);

        return $obj ? $obj->toArray() : null;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Telegram Card Builders                                 ║
    // ╚═════════════════════════════════════════════════════════╝

    private function buildMovieCard(array $data, int $chunkId, string $tmdbType = 'movie'): string
    {
        $title    = $this->esc($data['title'] ?? 'Untitled');
        $orig     = $this->esc($data['original_title'] ?? '');
        $year     = $this->extractYear($data['release_date'] ?? '');
        $overview = $this->excerpt($data['overview'] ?? '', 220);
        $rating   = isset($data['rating']) ? round((float)$data['rating'], 1) : '';
        $genres   = $this->joinList($data['genres'] ?? []);
        $extId    = $data['id'] ?? $data['tmdb_id'] ?? '';
        $tmdbUrl  = $extId ? 'https://www.themoviedb.org/' . $tmdbType . '/' . (int)$extId : '';
        $hashtags = $this->buildHashtags(
            array_merge(['кино', 'фильм'], $this->genreHashtags($data['genres'] ?? []))
        );

        $ageRating = $this->esc((string)($data['age_rating'] ?? ''));

        $lines   = [];
        $lines[] = "🎬 <b>{$title}" . ($year ? " ({$year})" : '') . '</b>';
        if ($orig && $orig !== ($data['title'] ?? '')) {
            $lines[] = "<i>{$orig}</i>";
        }
        $lines[] = '';
        if ($overview) {
            $lines[] = $overview;
        }
        $lines[] = '';
        $meta = [];
        if ($rating)    $meta[] = "⭐ {$rating}";
        if ($genres)    $meta[] = "🎭 {$genres}";
        if ($ageRating) $meta[] = "🔞 {$ageRating}";
        if ($meta)    $lines[] = implode(' | ', $meta);
        if (!empty($data['runtime'])) {
            $lines[] = '⏱ ' . $this->esc((string)$data['runtime']) . ' мин';
        }
        if ($tmdbUrl) {
            $lines[] = '🌐 <a href="' . $tmdbUrl . '">TMDB</a>';
        }
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildTvCard(array $data, int $chunkId): string
    {
        $title    = $this->esc($data['title'] ?? 'Untitled');
        $orig     = $this->esc($data['original_title'] ?? '');
        $year     = $this->extractYear($data['first_air_date'] ?? '');
        $overview = $this->excerpt($data['overview'] ?? '', 220);
        $rating   = isset($data['rating']) ? round((float)$data['rating'], 1) : '';
        $genres   = $this->joinList($data['genres'] ?? []);
        $seasons  = $data['number_of_seasons'] ?? '';
        $status   = $this->esc($data['status'] ?? '');
        $extId    = $data['id'] ?? '';
        $tmdbUrl  = $extId ? 'https://www.themoviedb.org/tv/' . (int)$extId : '';
        $hashtags = $this->buildHashtags(
            array_merge(['сериал', 'tvshow'], $this->genreHashtags($data['genres'] ?? []))
        );

        $ageRating = $this->esc((string)($data['age_rating'] ?? ''));

        $lines   = [];
        $lines[] = "📺 <b>{$title}" . ($year ? " ({$year})" : '') . '</b>';
        if ($orig && $orig !== ($data['title'] ?? '')) {
            $lines[] = "<i>{$orig}</i>";
        }
        $lines[] = '';
        if ($overview) {
            $lines[] = $overview;
        }
        $lines[] = '';
        $meta = [];
        if ($rating)    $meta[] = "⭐ {$rating}";
        if ($genres)    $meta[] = "🎭 {$genres}";
        if ($ageRating) $meta[] = "🔞 {$ageRating}";
        if ($meta)   $lines[] = implode(' | ', $meta);
        if ($seasons) {
            $lines[] = '📋 Сезонов: ' . (int)$seasons;
        }
        if ($status) {
            $lines[] = "📌 Статус: {$status}";
        }
        if ($tmdbUrl) {
            $lines[] = '🌐 <a href="' . $tmdbUrl . '">TMDB</a>';
        }
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildPersonCard(array $data, int $chunkId): string
    {
        $name      = $this->esc($data['name'] ?? $data['title'] ?? 'Unknown');
        $bio       = $this->excerpt($data['biography'] ?? '', 280);
        $birthday  = $this->esc($data['birthday'] ?? '');
        $place     = $this->esc($data['place_of_birth'] ?? '');
        $known     = $this->esc($data['known_for_department'] ?? '');
        $extId     = $data['id'] ?? '';
        $tmdbUrl   = $extId ? 'https://www.themoviedb.org/person/' . (int)$extId : '';
        $wikiUrl   = $data['wikipedia_url'] ?? '';
        $imdbId    = $data['imdb_id'] ?? '';
        $fbId      = $data['facebook_id'] ?? '';
        $instaId   = $data['instagram_id'] ?? '';
        $twitterId = $data['twitter_id'] ?? '';
        $tiktokId  = $data['tiktok_id'] ?? '';
        $youtubeId = $data['youtube_id'] ?? '';
        $hashtags  = $this->buildHashtags(['персона', 'актер', 'кино']);

        $lines   = [];
        $lines[] = "👤 <b>{$name}</b>";
        if ($known) {
            $lines[] = "<i>{$known}</i>";
        }
        $lines[] = '';
        if ($bio) {
            $lines[] = $bio;
        }
        $lines[] = '';
        if ($birthday) {
            $lines[] = "🎂 {$birthday}";
        }
        if ($place) {
            $lines[] = "🌍 {$place}";
        }
        $lines[] = '';

        // ── Ссылки ─────────────────────────────────────────
        if ($wikiUrl) {
            $lines[] = '📖 <a href="' . htmlspecialchars($wikiUrl, ENT_QUOTES) . '">Wikipedia</a>';
        }
        if ($imdbId) {
            $lines[] = '🎬 <a href="https://www.imdb.com/name/' . htmlspecialchars($imdbId, ENT_QUOTES) . '/">IMDb</a>';
        }
        if ($tmdbUrl) {
            $lines[] = '🌐 <a href="' . $tmdbUrl . '">TMDB</a>';
        }

        // ── Соцсети ────────────────────────────────────────
        $social = [];
        if ($fbId)      $social[] = '<a href="https://facebook.com/' . htmlspecialchars($fbId, ENT_QUOTES) . '">Facebook</a>';
        if ($instaId)   $social[] = '<a href="https://instagram.com/' . htmlspecialchars($instaId, ENT_QUOTES) . '">Instagram</a>';
        if ($twitterId) $social[] = '<a href="https://twitter.com/' . htmlspecialchars($twitterId, ENT_QUOTES) . '">Twitter/X</a>';
        if ($tiktokId)  $social[] = '<a href="https://tiktok.com/@' . htmlspecialchars($tiktokId, ENT_QUOTES) . '">TikTok</a>';
        if ($youtubeId) $social[] = '<a href="https://youtube.com/@' . htmlspecialchars($youtubeId, ENT_QUOTES) . '">YouTube</a>';
        if ($social) {
            $lines[] = '🔗 ' . implode(' · ', $social);
        }

        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildGameCard(array $data, int $chunkId): string
    {
        $title     = $this->esc($data['title'] ?? $data['name'] ?? 'Untitled');
        $desc      = $this->excerpt($data['description'] ?? $data['description_raw'] ?? '', 220);
        $released  = $this->esc($data['released'] ?? '');
        $rating    = isset($data['rating']) ? round((float)$data['rating'], 1) : '';
        $genres    = $this->joinList($data['genres'] ?? []);
        $platforms = $this->joinList($data['platforms'] ?? [], 4);
        $website   = $this->esc($data['website'] ?? '');
        $extId     = $data['id'] ?? $data['slug'] ?? '';
        $rawgUrl   = $extId ? 'https://rawg.io/games/' . urlencode((string)$extId) : '';
        $hashtags  = $this->buildHashtags(
            array_merge(['игра', 'game'], $this->genreHashtags($data['genres'] ?? []))
        );

        $lines   = [];
        $lines[] = "🎮 <b>{$title}</b>";
        $lines[] = '';
        if ($desc) {
            $lines[] = $desc;
        }
        $lines[] = '';
        $meta = [];
        if ($rating)    $meta[] = "⭐ {$rating}";
        if ($genres)    $meta[] = "🕹 {$genres}";
        if ($meta)      $lines[] = implode(' | ', $meta);
        if ($platforms) {
            $lines[] = "💻 {$platforms}";
        }
        if ($released) {
            $lines[] = "📅 {$released}";
        }
        if ($rawgUrl) {
            $lines[] = '🌐 <a href="' . $rawgUrl . '">RAWG</a>';
        } elseif ($website) {
            $lines[] = '🌐 <a href="' . $website . '">' . $website . '</a>';
        }
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildDeviceCard(array $data, int $chunkId): string
    {
        $name        = $this->esc($data['title'] ?? $data['name'] ?? 'Unknown');
        $brand       = $this->esc($data['brand'] ?? '');
        $os          = $this->esc($data['os'] ?? '');
        $display     = $this->esc($data['display'] ?? '');
        $camera      = $this->esc($data['camera'] ?? '');
        $battery     = $this->esc($data['battery'] ?? '');
        $ram         = $this->esc($data['ram'] ?? '');
        $storage     = $this->esc($data['storage'] ?? '');
        $releaseDate = $this->esc($data['release_date'] ?? '');
        $hashtags    = $this->buildHashtags(
            ['смартфон', 'телефон', mb_strtolower($brand ?: 'mobile')]
        );

        $lines   = [];
        $lines[] = "📱 <b>{$name}</b>";
        if ($brand) {
            $lines[] = "🏷 {$brand}";
        }
        $lines[] = '';
        if ($os)          $lines[] = "• <b>OS:</b> {$os}";
        if ($display)     $lines[] = "• <b>Дисплей:</b> {$display}";
        if ($camera)      $lines[] = "• <b>Камера:</b> {$camera}";
        if ($battery)     $lines[] = "• <b>Батарея:</b> {$battery}";
        if ($ram)         $lines[] = "• <b>RAM:</b> {$ram}";
        if ($storage)     $lines[] = "• <b>Память:</b> {$storage}";
        if ($releaseDate) $lines[] = "• <b>Дата выхода:</b> {$releaseDate}";
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildProductCard(array $data, int $chunkId): string
    {
        $title    = $this->esc($data['title'] ?? $data['name'] ?? 'Product');
        $brand    = $this->esc($data['brand'] ?? '');
        $price    = $this->esc($data['price'] ?? '');
        $desc     = $this->excerpt($data['description'] ?? '', 200);
        $rating   = isset($data['rating']) ? round((float)$data['rating'], 1) : '';
        $hashtags = $this->buildHashtags(['товар', 'продукт', 'шопинг']);

        $lines   = [];
        $lines[] = "🛍 <b>{$title}</b>";
        if ($brand) {
            $lines[] = "🏷 {$brand}";
        }
        $lines[] = '';
        if ($desc) {
            $lines[] = $desc;
        }
        $lines[] = '';
        $meta = [];
        if ($price)  $meta[] = "💰 {$price}";
        if ($rating) $meta[] = "⭐ {$rating}";
        if ($meta)   $lines[] = implode(' | ', $meta);
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildSportCard(array $data, int $chunkId, string $type): string
    {
        $emoji    = $type === 'biathlon' ? '🎿' : '⚽';
        $title    = $this->esc($data['title'] ?? $data['name'] ?? 'Event');
        $desc     = $this->excerpt($data['overview'] ?? $data['description'] ?? '', 220);
        $date     = $this->esc($data['date'] ?? $data['start_date'] ?? $data['event_date'] ?? '');
        $hashtags = $this->buildHashtags(['спорт', $type]);

        $lines   = [];
        $lines[] = "{$emoji} <b>{$title}</b>";
        $lines[] = '';
        if ($desc) {
            $lines[] = $desc;
        }
        $lines[] = '';
        if ($date) {
            $lines[] = "📅 {$date}";
        }
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildGithubCard(array $data, int $chunkId): string
    {
        $name     = $this->esc($data['full_name'] ?? $data['title'] ?? $data['name'] ?? 'repo');
        $desc     = $this->excerpt($data['description'] ?? $data['overview'] ?? '', 200);
        $stars    = $data['stars'] ?? $data['stargazers_count'] ?? '';
        $lang     = $this->esc($data['language'] ?? '');
        $url      = $data['html_url'] ?? $data['url'] ?? '';
        $hashtags = $this->buildHashtags(
            ['github', 'opensource', mb_strtolower($lang ?: 'code')]
        );

        $lines   = [];
        $lines[] = "💻 <b>{$name}</b>";
        $lines[] = '';
        if ($desc) {
            $lines[] = $desc;
        }
        $lines[] = '';
        $meta = [];
        if ($stars) $meta[] = '⭐ ' . $this->esc((string)$stars);
        if ($lang)  $meta[] = "🔧 {$lang}";
        if ($meta)  $lines[] = implode(' | ', $meta);
        if ($url) {
            $lines[] = '🔗 <a href="' . $this->esc($url) . '">GitHub</a>';
        }
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    private function buildGenericCard(array $data, int $chunkId, string $type): string
    {
        $title    = $this->esc($data['title'] ?? $data['name'] ?? 'Item');
        $desc     = $this->excerpt($data['overview'] ?? $data['description'] ?? '', 220);
        $hashtags = $this->buildHashtags([$type, 'spookyapp']);

        $lines   = [];
        $lines[] = "📌 <b>{$title}</b>";
        $lines[] = '';
        if ($desc) {
            $lines[] = $desc;
        }
        $lines[] = '';
        $lines[] = $hashtags;
        $lines[] = '';
        $lines[] = "<code>#spookyapp:{$chunkId}</code>";

        return implode("\n", $lines);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Helpers                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Экранировать HTML-сущности для Telegram HTML mode.
     */
    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Обрезать текст до limit символов с сохранением целых предложений.
     */
    private function excerpt(string $text, int $limit): string
    {
        $text = strip_tags($text);
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return $this->esc($text);
        }

        $short    = mb_substr($text, 0, $limit);
        $lastDot  = mb_strrpos($short, '.');
        $lastStop = max(
            mb_strrpos($short, '!'),
            mb_strrpos($short, '?')
        );
        $boundary = max($lastDot ?: 0, $lastStop ?: 0);

        if ($boundary > (int)($limit * 0.5)) {
            $short = mb_substr($text, 0, $boundary + 1);
        } else {
            $short = rtrim($short) . '…';
        }

        return $this->esc($short);
    }

    /**
     * Извлечь год из даты формата YYYY-MM-DD.
     */
    private function extractYear(string $date): string
    {
        return strlen($date) >= 4 ? substr($date, 0, 4) : '';
    }

    /**
     * Собрать строку из массива элементов (name/title/string).
     */
    private function joinList(array $items, int $limit = 5): string
    {
        $names = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $names[] = is_string($item) ? $item : ($item['name'] ?? $item['title'] ?? '');
        }

        return $this->esc(implode(', ', array_filter($names)));
    }

    /**
     * Собрать хэштеги из массива названий жанров.
     */
    private function genreHashtags(array $genres): array
    {
        $tags = [];
        foreach (array_slice($genres, 0, 3) as $g) {
            $name = is_string($g) ? $g : ($g['name'] ?? '');
            if ($name) {
                $tag = preg_replace('/[^a-zA-Zа-яёА-ЯЁ0-9]/u', '', mb_strtolower($name));
                if ($tag) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * Сформировать строку хэштегов.
     */
    private function buildHashtags(array $tags): string
    {
        $result = [];
        foreach (array_filter($tags) as $tag) {
            $result[] = '#' . preg_replace('/\s+/', '', (string)$tag);
        }

        return implode(' ', $result);
    }
}
