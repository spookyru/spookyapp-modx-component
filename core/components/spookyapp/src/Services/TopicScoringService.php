<?php

declare(strict_types=1);

namespace SpookyApp\Services;

use MODX\Revolution\modX;
use MODX\Revolution\modResource;
use DateTimeImmutable;
use Throwable;

/**
 * Сервис оценки (scoring) тем для блога.
 *
 * Вычисляет комплексный score (0–100) для каждой темы на основе пяти критериев:
 * - Relevance: соответствие тематике блога (0–30)
 * - Freshness: свежесть материала (0–20)
 * - Popularity: популярность в источнике (0–25)
 * - Engagement: потенциал вовлеченности (0–15)
 * - Uniqueness: уникальность для блога (0–10)
 *
 * Поддерживает фильтрацию запрещённых тем (blacklist) и пакетную обработку.
 *
 * Структура входного массива $topic:
 * - title: string — заголовок
 * - description: string — описание/аннотация
 * - source: string — источник (reddit, news, и т.д.)
 * - published_at: string — дата публикации (ISO 8601 или timestamp)
 * - category: string — категория
 * - upvotes: int (optional) — голоса Reddit
 * - mention_count: int (optional) — упоминания в новостях
 * - has_image: bool (optional) — наличие изображения
 * - has_video: bool (optional) — наличие видео
 */
class TopicScoringService
{
    // ─── Веса критериев (сумма = 1.0) ────────────────────────────
    public const WEIGHT_RELEVANCE  = 0.30;
    public const WEIGHT_FRESHNESS  = 0.20;
    public const WEIGHT_POPULARITY = 0.25;
    public const WEIGHT_ENGAGEMENT = 0.15;
    public const WEIGHT_UNIQUENESS = 0.10;

    // ─── Максимальные баллы по каждому критерию ──────────────────
    private const MAX_RELEVANCE  = 30;
    private const MAX_FRESHNESS  = 20;
    private const MAX_POPULARITY = 25;
    private const MAX_ENGAGEMENT = 15;
    private const MAX_UNIQUENESS = 10;

    // ─── Баллы за совпадение ключевого слова ─────────────────────
    private const POINTS_PER_KEYWORD = 3;

    // ─── Ключевые слова по категориям ────────────────────────────
    private const RELEVANCE_KEYWORDS = [
        'IT' => [
            'programming', 'javascript', 'php', 'python', 'code', 'developer',
            'web', 'api', 'database', 'windows', 'linux', 'network',
            'typescript', 'react', 'vue', 'angular', 'node', 'docker',
            'kubernetes', 'devops', 'ci/cd', 'git', 'backend', 'frontend',
            'fullstack', 'framework', 'library', 'open source', 'github',
            'программирование', 'разработка', 'разработчик', 'код', 'сервер',
            'фреймворк', 'библиотека', 'базы данных',
        ],
        'Gadgets' => [
            'smartphone', 'gadget', 'laptop', 'device', 'iphone', 'samsung',
            'processor', 'gpu', 'tech', 'apple', 'google', 'pixel',
            'android', 'ios', 'wearable', 'tablet', 'monitor', 'ssd',
            'ram', 'cpu', 'nvidia', 'amd', 'intel', 'hardware',
            'смартфон', 'гаджет', 'ноутбук', 'устройство', 'процессор',
            'видеокарта', 'телефон', 'планшет',
        ],
        'Gaming' => [
            'game', 'gaming', 'steam', 'playstation', 'xbox', 'nintendo',
            'esports', 'fps', 'rpg', 'mmorpg', 'indie', 'unreal',
            'unity', 'gamedev', 'ps5', 'switch', 'pc gaming', 'gamer',
            'игра', 'игры', 'геймер', 'геймдев', 'киберспорт', 'стрим',
        ],
        'Entertainment' => [
            'movie', 'film', 'series', 'tv show', 'netflix', 'anime',
            'disney', 'hbo', 'streaming', 'cinema', 'trailer', 'review',
            'youtube', 'twitch', 'podcast', 'music', 'album',
            'фильм', 'сериал', 'кино', 'трейлер', 'обзор', 'аниме',
            'музыка', 'подкаст',
        ],
        'Sports' => [
            'football', 'soccer', 'biathlon', 'sport', 'championship',
            'tournament', 'league', 'nba', 'nfl', 'ufc', 'formula 1',
            'olympics', 'tennis', 'hockey', 'basketball',
            'футбол', 'спорт', 'чемпионат', 'турнир', 'лига', 'хоккей',
            'баскетбол', 'теннис', 'олимпиада', 'биатлон',
        ],
        'DIY' => [
            'garden', 'diy', 'home', 'improvement', 'craft', 'woodworking',
            'renovation', 'tools', 'handmade', 'tutorial', 'howto', 'maker',
            'дом', 'сад', 'ремонт', 'инструменты', 'своими руками',
            'мастерская', 'огород', 'самоделка',
        ],
    ];

    // ─── Blacklist запрещённых тем ───────────────────────────────
    private const BLACKLIST = [
        // English
        'politics', 'political', 'war', 'warfare', 'military', 'drug', 'drugs',
        'casino', 'gambling', 'betting', 'biden', 'trump', 'putin',
        'election', 'elections', 'terrorist', 'terrorism', 'extremism',
        'pornography', 'porn', 'nsfw', 'escort', 'prostitution',
        'genocide', 'nazi', 'fascism', 'supremacy', 'racist', 'racism',
        // Russian
        'политика', 'политический', 'война', 'военный', 'наркотик', 'наркотики',
        'казино', 'ставки', 'выборы', 'терроризм', 'террорист',
        'экстремизм', 'порнография', 'нацизм', 'фашизм', 'расизм',
        'геноцид', 'пропаганда',
    ];

    // ─── Порог схожести заголовков для uniqueness ────────────────
    private const UNIQUENESS_SIMILARITY_THRESHOLD = 60;

    private modX $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    // ═════════════════════════════════════════════════════════════
    // 1. Главный метод: итоговый score
    // ═════════════════════════════════════════════════════════════

    /**
     * Вычислить комплексный score темы (0–100).
     *
     * Формула: Σ(sub_score / max_sub_score * weight) * 100
     *
     * @param array $topic Данные темы
     * @return float Score от 0.0 до 100.0
     */
    public function calculateScore(array $topic): float
    {
        $relevance  = $this->scoreRelevance($topic);
        $freshness  = $this->scoreFreshness($topic);
        $popularity = $this->scorePopularity($topic);
        $engagement = $this->scoreEngagement($topic);
        $uniqueness = $this->scoreUniqueness($topic);

        // Нормализация каждого sub-score к диапазону 0–1, затем взвешенная сумма * 100
        $score = (
            ($relevance  / self::MAX_RELEVANCE)  * self::WEIGHT_RELEVANCE +
            ($freshness  / self::MAX_FRESHNESS)  * self::WEIGHT_FRESHNESS +
            ($popularity / self::MAX_POPULARITY) * self::WEIGHT_POPULARITY +
            ($engagement / self::MAX_ENGAGEMENT) * self::WEIGHT_ENGAGEMENT +
            ($uniqueness / self::MAX_UNIQUENESS) * self::WEIGHT_UNIQUENESS
        ) * 100;

        $score = round(min(max($score, 0.0), 100.0), 2);

        $title = mb_substr((string)($topic['title'] ?? ''), 0, 60);
        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            "[TopicScoring] Score={$score} | R={$relevance} F={$freshness} P={$popularity} E={$engagement} U={$uniqueness} | \"{$title}\""
        );

        return $score;
    }

    // ═════════════════════════════════════════════════════════════
    // 2. Relevance — соответствие тематике блога (0–30)
    // ═════════════════════════════════════════════════════════════

    /**
     * Оценить соответствие темы тематике блога.
     *
     * За каждое совпадение ключевого слова в title + description — +3 балла.
     * Максимум 30 баллов.
     *
     * @param array $topic Данные темы
     * @return float Score от 0.0 до 30.0
     */
    public function scoreRelevance(array $topic): float
    {
        $title = mb_strtolower(trim((string)($topic['title'] ?? '')));
        $description = mb_strtolower(trim((string)($topic['description'] ?? '')));
        $text = $title . ' ' . $description;

        $totalPoints = 0;
        $matchedKeywords = [];

        foreach (self::RELEVANCE_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                $keyword = mb_strtolower($keyword);
                if (mb_strpos($text, $keyword) !== false) {
                    $totalPoints += self::POINTS_PER_KEYWORD;
                    $matchedKeywords[] = $keyword;

                    // Не считаем одно и то же ключевое слово дважды
                    if ($totalPoints >= self::MAX_RELEVANCE) {
                        break 2;
                    }
                }
            }
        }

        $score = (float)min($totalPoints, self::MAX_RELEVANCE);

        if (!empty($matchedKeywords)) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[TopicScoring] Relevance={$score}: keywords=[" . implode(', ', array_slice($matchedKeywords, 0, 5)) . ']'
            );
        }

        return $score;
    }

    // ═════════════════════════════════════════════════════════════
    // 3. Freshness — свежесть (0–20)
    // ═════════════════════════════════════════════════════════════

    /**
     * Оценить свежесть темы.
     *
     * - < 6 часов:  20 баллов
     * - < 24 часов: 15 баллов
     * - < 48 часов: 10 баллов
     * - < 7 дней:   5 баллов
     * - > 7 дней:   0 баллов
     *
     * @param array $topic Данные темы
     * @return float Score от 0.0 до 20.0
     */
    public function scoreFreshness(array $topic): float
    {
        $publishedAt = $topic['published_at'] ?? '';

        if (empty($publishedAt)) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicScoring] Freshness=0: дата публикации отсутствует');
            return 0.0;
        }

        $publishedTimestamp = $this->parseTimestamp($publishedAt);
        if ($publishedTimestamp === null) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, "[TopicScoring] Freshness=0: не удалось распарсить дату: {$publishedAt}");
            return 0.0;
        }

        $now = time();
        $ageHours = ($now - $publishedTimestamp) / 3600;

        if ($ageHours < 0) {
            // Дата в будущем — считаем максимально свежей
            $score = (float)self::MAX_FRESHNESS;
        } elseif ($ageHours < 6) {
            $score = 20.0;
        } elseif ($ageHours < 24) {
            $score = 15.0;
        } elseif ($ageHours < 48) {
            $score = 10.0;
        } elseif ($ageHours < 168) { // 7 * 24
            $score = 5.0;
        } else {
            $score = 0.0;
        }

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            "[TopicScoring] Freshness={$score}: age=" . round($ageHours, 1) . 'h'
        );

        return $score;
    }

    // ═════════════════════════════════════════════════════════════
    // 4. Popularity — популярность (0–25)
    // ═════════════════════════════════════════════════════════════

    /**
     * Оценить популярность темы в источнике.
     *
     * - Reddit: upvotes / 1000 * 25 (max 25)
     * - News: mention_count / 10 * 25 (max 25)
     * - Если данных нет: 10 (средний балл)
     *
     * @param array $topic Данные темы
     * @return float Score от 0.0 до 25.0
     */
    public function scorePopularity(array $topic): float
    {
        $upvotes = isset($topic['upvotes']) ? (int)$topic['upvotes'] : null;
        $mentionCount = isset($topic['mention_count']) ? (int)$topic['mention_count'] : null;
        $source = mb_strtolower(trim((string)($topic['source'] ?? '')));

        $score = 10.0; // default средний балл

        if ($upvotes !== null && $upvotes > 0) {
            // Reddit-стиль: upvotes / 1000 * 25
            $score = min(($upvotes / 1000) * self::MAX_POPULARITY, (float)self::MAX_POPULARITY);
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[TopicScoring] Popularity={$score}: upvotes={$upvotes}"
            );
        } elseif ($mentionCount !== null && $mentionCount > 0) {
            // Новости: mention_count / 10 * 25
            $score = min(($mentionCount / 10) * self::MAX_POPULARITY, (float)self::MAX_POPULARITY);
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[TopicScoring] Popularity={$score}: mentions={$mentionCount}"
            );
        } else {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[TopicScoring] Popularity={$score}: нет данных, используется средний балл"
            );
        }

        return round($score, 2);
    }

    // ═════════════════════════════════════════════════════════════
    // 5. Engagement — потенциал вовлеченности (0–15)
    // ═════════════════════════════════════════════════════════════

    /**
     * Оценить потенциал вовлеченности аудитории.
     *
     * - Есть изображение: +5
     * - Есть видео: +5
     * - Описание > 200 символов: +5
     *
     * @param array $topic Данные темы
     * @return float Score от 0.0 до 15.0
     */
    public function scoreEngagement(array $topic): float
    {
        $score = 0.0;
        $reasons = [];

        $hasImage = !empty($topic['has_image']) || !empty($topic['thumbnail'] ?? '');
        $hasVideo = !empty($topic['has_video']);
        $description = trim((string)($topic['description'] ?? ''));
        $hasLongDescription = mb_strlen($description) > 200;

        if ($hasImage) {
            $score += 5.0;
            $reasons[] = 'image';
        }

        if ($hasVideo) {
            $score += 5.0;
            $reasons[] = 'video';
        }

        if ($hasLongDescription) {
            $score += 5.0;
            $reasons[] = 'long_desc(' . mb_strlen($description) . ')';
        }

        $score = min($score, (float)self::MAX_ENGAGEMENT);

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            "[TopicScoring] Engagement={$score}: " . (empty($reasons) ? 'none' : implode(', ', $reasons))
        );

        return $score;
    }

    // ═════════════════════════════════════════════════════════════
    // 6. Uniqueness — уникальность для блога (0–10)
    // ═════════════════════════════════════════════════════════════

    /**
     * Оценить уникальность темы для блога.
     *
     * Проверяет наличие похожих опубликованных статей в modResource.
     * - Нет похожих: 10 баллов
     * - Есть похожие: 0 баллов
     *
     * @param array $topic Данные темы
     * @return float Score: 0.0 или 10.0
     */
    public function scoreUniqueness(array $topic): float
    {
        $title = trim((string)($topic['title'] ?? ''));

        if (empty($title)) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicScoring] Uniqueness=10: пустой заголовок, считаем уникальным');
            return (float)self::MAX_UNIQUENESS;
        }

        try {
            $hasSimilar = $this->findSimilarResources($title);

            if ($hasSimilar) {
                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    '[TopicScoring] Uniqueness=0: найдена похожая статья для "' . mb_substr($title, 0, 50) . '"'
                );
                return 0.0;
            }

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[TopicScoring] Uniqueness=10: похожих статей не найдено'
            );

            return (float)self::MAX_UNIQUENESS;
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[TopicScoring] Uniqueness: ошибка при проверке: {$e->getMessage()}"
            );
            // При ошибке — считаем уникальным (benefit of the doubt)
            return (float)self::MAX_UNIQUENESS;
        }
    }

    // ═════════════════════════════════════════════════════════════
    // 7. Blacklist — фильтрация запрещённых тем
    // ═════════════════════════════════════════════════════════════

    /**
     * Проверить тему на запрещённый контент.
     *
     * @param array $topic Данные темы
     * @return bool true — тема разрешена, false — запрещена
     */
    public function filterBlacklisted(array $topic): bool
    {
        $title = mb_strtolower(trim((string)($topic['title'] ?? '')));
        $description = mb_strtolower(trim((string)($topic['description'] ?? '')));
        $text = $title . ' ' . $description;

        foreach (self::BLACKLIST as $banned) {
            $banned = mb_strtolower($banned);

            // Проверяем как целое слово (word boundary для латиницы, простой поиск для кириллицы)
            if ($this->containsWord($text, $banned)) {
                $this->modx->log(
                    modX::LOG_LEVEL_INFO,
                    "[TopicScoring] Blacklisted: слово '{$banned}' найдено в \"" . mb_substr($title, 0, 60) . '"'
                );
                return false;
            }
        }

        return true;
    }

    // ═════════════════════════════════════════════════════════════
    // 8. Пакетная обработка
    // ═════════════════════════════════════════════════════════════

    /**
     * Обработать массив тем: фильтрация + scoring + сортировка.
     *
     * Порядок:
     * 1. Фильтрация запрещённых тем (blacklist)
     * 2. Вычисление score для каждой темы
     * 3. Добавление поля 'score' в каждый элемент
     * 4. Сортировка по score (DESC)
     *
     * @param array<int, array> $topics Массив тем
     * @return array<int, array> Обработанный и отсортированный массив
     */
    public function batchScore(array $topics): array
    {
        $totalInput = count($topics);

        if (empty($topics)) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicScoring] batchScore: пустой массив тем');
            return [];
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[TopicScoring] batchScore: начало обработки {$totalInput} тем"
        );

        // 1. Фильтрация blacklist
        $filtered = array_filter($topics, function (array $topic): bool {
            return $this->filterBlacklisted($topic);
        });

        $blacklistedCount = $totalInput - count($filtered);

        // 2. Scoring
        $scored = array_map(function (array $topic): array {
            $topic['score'] = $this->calculateScore($topic);
            return $topic;
        }, $filtered);

        // 3. Сортировка по score DESC
        usort($scored, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        // Реиндексация
        $result = array_values($scored);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[TopicScoring] batchScore: {$totalInput} → " . count($result)
            . " тем (blacklisted: {$blacklistedCount})"
            . (count($result) > 0 ? ', top score: ' . ($result[0]['score'] ?? 0) : '')
        );

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные вспомогательные методы
    // ═════════════════════════════════════════════════════════════

    /**
     * Найти похожие опубликованные ресурсы по заголовку.
     *
     * Ищет среди опубликованных modResource по LIKE-совпадению
     * и дополнительно проверяет через similar_text().
     *
     * @param string $title Заголовок для поиска
     * @return bool true если найдена похожая статья
     */
    private function findSimilarResources(string $title): bool
    {
        // Извлекаем ключевые слова из заголовка (3+ символов)
        $words = preg_split('/[\s\-_,.:;!?()]+/u', mb_strtolower($title));
        $words = array_filter($words ?? [], function (string $word): bool {
            return mb_strlen($word) >= 4;
        });

        if (empty($words)) {
            return false;
        }

        // Берём до 3 самых длинных слов для LIKE-поиска
        usort($words, function (string $a, string $b): int {
            return mb_strlen($b) <=> mb_strlen($a);
        });
        $searchWords = array_slice($words, 0, 3);

        $query = $this->modx->newQuery(modResource::class);
        $query->where([
            'published' => 1,
            'deleted'   => 0,
        ]);

        // Добавляем OR-условия по pagetitle для каждого ключевого слова
        $conditions = [];
        foreach ($searchWords as $word) {
            $conditions[] = [
                'pagetitle:LIKE' => "%{$word}%",
            ];
        }

        if (!empty($conditions)) {
            $query->where($conditions, \xPDO\xPDO::LOG_LEVEL_DEBUG);
        }

        $query->limit(20);
        $query->select($this->modx->getSelectColumns(modResource::class, '', '', ['id', 'pagetitle']));

        $resources = $this->modx->getIterator(modResource::class, $query);

        $normalizedTitle = $this->normalizeForComparison($title);

        foreach ($resources as $resource) {
            $existingTitle = $resource->get('pagetitle');
            $normalizedExisting = $this->normalizeForComparison((string)$existingTitle);

            $similarity = 0.0;
            similar_text($normalizedTitle, $normalizedExisting, $similarity);

            if ($similarity >= self::UNIQUENESS_SIMILARITY_THRESHOLD) {
                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    "[TopicScoring] Найдена похожая статья (id={$resource->get('id')}, similarity={$similarity}%): \"{$existingTitle}\""
                );
                return true;
            }
        }

        return false;
    }

    /**
     * Нормализовать строку для сравнения.
     *
     * @param string $text Исходный текст
     * @return string Нормализованный текст
     */
    private function normalizeForComparison(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Проверить наличие слова в тексте (word boundary).
     *
     * Для латиницы использует \b, для кириллицы — простой mb_strpos.
     *
     * @param string $text Текст для поиска
     * @param string $word Слово для проверки
     * @return bool
     */
    private function containsWord(string $text, string $word): bool
    {
        // Для слов из ASCII-символов используем word boundaries
        if (preg_match('/^[a-z0-9]+$/i', $word)) {
            return (bool)preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $text);
        }

        // Для кириллицы — поиск подстроки с проверкой на границы слов
        $pos = mb_strpos($text, $word);
        if ($pos === false) {
            return false;
        }

        // Проверяем, что перед и после слова — граница (пробел, начало/конец, пунктуация)
        $before = $pos > 0 ? mb_substr($text, $pos - 1, 1) : ' ';
        $after = mb_substr($text, $pos + mb_strlen($word), 1);
        if ($after === false || $after === '') {
            $after = ' ';
        }

        $isBoundaryBefore = preg_match('/[\s\p{P}]/u', $before);
        $isBoundaryAfter = preg_match('/[\s\p{P}]/u', $after);

        return (bool)$isBoundaryBefore && (bool)$isBoundaryAfter;
    }

    /**
     * Распарсить строку даты в Unix timestamp.
     *
     * Поддерживает ISO 8601, Unix timestamp, и другие распространённые форматы.
     *
     * @param string $dateString Строка даты
     * @return int|null Unix timestamp или null
     */
    private function parseTimestamp(string $dateString): ?int
    {
        if (empty($dateString)) {
            return null;
        }

        // Если уже число — считаем timestamp
        if (is_numeric($dateString)) {
            return (int)$dateString;
        }

        try {
            $dt = new DateTimeImmutable($dateString);
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[TopicScoring] parseTimestamp: не удалось распарсить '{$dateString}': {$e->getMessage()}"
            );
            return null;
        }
    }
}