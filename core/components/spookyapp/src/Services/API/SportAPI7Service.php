<?php

declare(strict_types=1);

namespace SpookyApp\Services\API;

use MODX\Revolution\modX;
use SpookyApp\Services\Cache\CacheService;

/**
 * SportAPI7Service — клиент для SportAPI7 (sportapi7.p.rapidapi.com).
 *
 * ═══════════════════════════════════════════════════════════════
 * Методы:
 *   - searchEventsByDate()   — все матчи вида спорта за дату
 *   - getEventDetails()      — детали одного матча по event ID
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Services\API
 */
class SportAPI7Service extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    private const BASE_URL      = 'https://sportapi7.p.rapidapi.com';
    private const RAPIDAPI_HOST = 'sportapi7.p.rapidapi.com';
    private const CACHE_PREFIX  = 'spookyapp/sportapi7/';
    private const SETTING_KEY   = 'spookyapp.sportapi7_api_key';

    /** @var int Кеш расписания событий (30 мин — счёт может поменяться) */
    private const TTL_EVENTS = 1800;

    /** @var int Кеш деталей завершённого матча (24 ч) */
    private const TTL_DETAILS_DONE = 86400;

    /** @var int Кеш деталей живого матча (60 с) */
    private const TTL_DETAILS_LIVE = 60;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Properties                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    private string $apiKey;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constructor                                            ║
    // ╚═════════════════════════════════════════════════════════╝

    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->apiKey = (string)$this->modx->getOption(self::SETTING_KEY, null, '');
        if (empty($this->apiKey)) {
            $this->apiKey = (string)$this->modx->getOption('spookyapp.rapidapi_key', null, '');
        }

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[SportAPI7Service] Initialized. Key: ' . (empty($this->apiKey) ? 'MISSING' : 'OK')
        );
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Public: Search                                         ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить все матчи вида спорта за дату и отфильтровать по запросу.
     *
     * Endpoint: GET /api/v1/sport/{sport}/scheduled-events/{date}
     *
     * Возвращаемая структура (нормализована для общего grid):
     * [
     *   'id'      => string  (event id)
     *   'title'   => string  (homeTeam — awayTeam)
     *   'year'    => string  (season year, e.g. "24/25")
     *   'overview'=> string  (tournament | round | status | score | date)
     *   'poster'  => null
     * ]
     *
     * @param string $sport  Slug: football, ice-hockey, volleyball, esports, olympics
     * @param string $date   YYYY-MM-DD
     * @param string $query  Фильтр: название команды или турнира (пустой = все)
     *
     * @return array<int, array{id:string, title:string, year:string, overview:string, poster:null}>
     */
    public function searchEventsByDate(string $sport, string $date, string $query): array
    {
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $sport = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($sport)));

        $cacheKey = self::CACHE_PREFIX . 'events/' . $sport . '_' . $date;
        $all      = $this->getCache($cacheKey);

        if ($all === null) {
            $raw = $this->request('/api/v1/sport/' . $sport . '/scheduled-events/' . $date);
            $all = $raw['events'] ?? [];
            if (!empty($all)) {
                $this->setCache($cacheKey, $all, self::TTL_EVENTS);
            }
        }

        $needle  = mb_strtolower(trim($query));
        $results = [];

        foreach ($all as $event) {
            $homeName = $this->getName($event['homeTeam'] ?? []);
            $awayName = $this->getName($event['awayTeam'] ?? []);
            $tourName = $this->getName($event['tournament'] ?? []);
            $uniqName = $this->getName($event['tournament']['uniqueTournament'] ?? []);

            if (
                $needle === ''
                || str_contains(mb_strtolower($homeName), $needle)
                || str_contains(mb_strtolower($awayName), $needle)
                || str_contains(mb_strtolower($tourName), $needle)
                || str_contains(mb_strtolower($uniqName), $needle)
            ) {
                $results[] = $this->normalizeEvent($event);
            }
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportAPI7Service] searchEventsByDate: sport=' . $sport
            . ' date=' . $date . ' query="' . $query . '" → ' . count($results) . ' events'
        );

        return $results;
    }

    /**
     * Получить полные детали события по ID.
     *
     * Endpoint: GET /api/v1/event/{id}
     * + GET /api/v1/event/{id}/incidents
     *
     * @param int $eventId
     *
     * @return array
     */
    public function getEventDetails(int $eventId): array
    {
        $cacheKey = self::CACHE_PREFIX . 'detail/' . $eventId;
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            // Invalidate old cache missing incidents or title fields
            if (!array_key_exists('incidents', $cached) || empty($cached['title'])) {
                $this->cache->delete($cacheKey);
            } else {
                return $cached;
            }
        }

        $raw = $this->request('/api/v1/event/' . $eventId);
        if ($raw === null || empty($raw['event'])) {
            return [];
        }

        $event  = $raw['event'];
        $result = $this->buildDetailData($event);

        // Fetch incidents (goals, cards, subs)
        $incRaw = $this->request('/api/v1/event/' . $eventId . '/incidents');
        if ($incRaw !== null && !empty($incRaw['incidents'])) {
            $homeId = (int)($event['homeTeam']['id'] ?? 0);
            $awayId = (int)($event['awayTeam']['id'] ?? 0);
            $result['incidents'] = $this->buildIncidentsData($incRaw['incidents'], $homeId, $awayId);
        } else {
            $result['incidents'] = [];
        }

        // Finished matches cached longer
        $status = strtolower($event['status']['type'] ?? '');
        $ttl    = ($status === 'finished') ? self::TTL_DETAILS_DONE : self::TTL_DETAILS_LIVE;
        $this->setCache($cacheKey, $result, $ttl);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportAPI7Service] getEventDetails: id=' . $eventId . ' status=' . $status
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Data Helpers                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выбрать русское или оригинальное название из объекта с fieldTranslations.
     *
     * @param array $obj
     *
     * @return string
     */
    private function getName(array $obj): string
    {
        return $obj['fieldTranslations']['nameTranslation']['ru']
            ?? $obj['name']
            ?? '';
    }

    /**
     * Получить краткое имя команды (русское, shortName или name).
     *
     * @param array $team
     *
     * @return string
     */
    private function getTeamName(array $team): string
    {
        return $team['fieldTranslations']['nameTranslation']['ru']
            ?? $team['name']
            ?? '';
    }

    /**
     * Нормализовать event-объект для поискового grid.
     *
     * @param array $event
     *
     * @return array{id:string, title:string, year:string, overview:string, poster:null}
     */
    private function normalizeEvent(array $event): array
    {
        $home = $this->getTeamName($event['homeTeam'] ?? []);
        $away = $this->getTeamName($event['awayTeam'] ?? []);

        $homeCur = $event['homeScore']['current'] ?? null;
        $awayCur = $event['awayScore']['current'] ?? null;
        $score   = ($homeCur !== null && $awayCur !== null) ? ($homeCur . ':' . $awayCur) : null;

        $tourName   = $this->getName($event['tournament'] ?? []);
        $seasonYear = (string)($event['season']['year'] ?? '');
        $status     = $event['status']['description'] ?? '';
        $round      = $event['roundInfo']['name'] ?? (isset($event['roundInfo']['round']) ? ('Round ' . $event['roundInfo']['round']) : null);

        $ts        = $event['startTimestamp'] ?? null;
        $dateLabel = $ts ? date('d.m.Y H:i', (int)$ts) : '';

        return [
            'id'      => (string)($event['id'] ?? ''),
            'title'   => $home . ' — ' . $away,
            'year'    => $seasonYear,
            'overview' => implode(' | ', array_filter([
                $tourName,
                $round    ?: null,
                $status   ?: null,
                $score    ?: null,
                $dateLabel ?: null,
            ])),
            'poster'  => null,
        ];
    }

    /**
     * Сформировать детальный массив для getdetails.
     *
     * @param array $event
     *
     * @return array
     */
    private function buildDetailData(array $event): array
    {
        $home = $event['homeTeam'] ?? [];
        $away = $event['awayTeam'] ?? [];
        $hs   = $event['homeScore'] ?? [];
        $as   = $event['awayScore'] ?? [];
        $tour = $event['tournament'] ?? [];
        $uniq = $tour['uniqueTournament'] ?? [];

        $ts = $event['startTimestamp'] ?? null;

        return [
            'id'          => (int)($event['id'] ?? 0),
            'title'       => $this->getTeamName($home) . ' — ' . $this->getTeamName($away),
            'slug'        => (string)($event['slug'] ?? ''),
            'date'        => $ts ? date('d.m.Y H:i', (int)$ts) : '',
            'status'      => $event['status']['description'] ?? '',
            'status_type' => $event['status']['type'] ?? '',
            'round'       => $event['roundInfo']['name'] ?? ('Round ' . ($event['roundInfo']['round'] ?? '?')),

            'tournament' => [
                'id'       => (int)($uniq['id'] ?? 0),
                'name'     => $this->getName($tour),
                'name_ru'  => $tour['fieldTranslations']['nameTranslation']['ru'] ?? $this->getName($tour),
                'slug'     => $tour['slug'] ?? '',
                'category' => $this->getName($tour['category'] ?? []),
                'country'  => $tour['category']['country']['name'] ?? '',
            ],

            'season' => [
                'name' => $event['season']['name'] ?? '',
                'year' => $event['season']['year'] ?? '',
                'id'   => (int)($event['season']['id'] ?? 0),
            ],

            'home_team' => [
                'id'       => (int)($home['id'] ?? 0),
                'name'     => $this->getTeamName($home),
                'slug'     => $home['slug'] ?? '',
                'short'    => $home['shortName'] ?? '',
                'country'  => $home['country']['name'] ?? '',
                'colors'   => $home['teamColors'] ?? [],
            ],

            'away_team' => [
                'id'       => (int)($away['id'] ?? 0),
                'name'     => $this->getTeamName($away),
                'slug'     => $away['slug'] ?? '',
                'short'    => $away['shortName'] ?? '',
                'country'  => $away['country']['name'] ?? '',
                'colors'   => $away['teamColors'] ?? [],
            ],

            'score' => [
                'home'     => $hs['current'] ?? null,
                'away'     => $as['current'] ?? null,
                'period1'  => [($hs['period1'] ?? null), ($as['period1'] ?? null)],
                'period2'  => [($hs['period2'] ?? null), ($as['period2'] ?? null)],
            ],

            'venue'   => $event['venue']['name'] ?? '',
            'referee' => $event['referee']['name'] ?? '',

            'unique_tournament_id' => (int)($uniq['id'] ?? 0),
        ];
    }

    /**
     * Разобрать список инцидентов матча.
     *
     * Возвращает структуру:
     * [
     *   'goals'         => [{time, addedTime, player, assist, homeScore, awayScore, isHome, goalType}, ...]
     *   'cards'         => [{time, player, reason, class, isHome}, ...]
     *   'substitutions' => [{time, playerIn, playerOut, isHome}, ...]
     *   'timeline'      => [{type, time, addedTime, isHome, label, detail, score}, ...]
     * ]
     *
     * @param array $incidents  Массив incidents из API
     * @param int   $homeTeamId ID домашней команды (для определения принадлежности)
     * @param int   $awayTeamId ID гостевой команды
     *
     * @return array
     */
    private function buildIncidentsData(array $incidents, int $homeTeamId, int $awayTeamId): array
    {
        $goals         = [];
        $cards         = [];
        $substitutions = [];
        $timeline      = [];

        foreach ($incidents as $inc) {
            $type     = $inc['incidentType'] ?? '';
            $isHome   = (bool)($inc['isHome'] ?? false);
            $time     = (int)($inc['time'] ?? 0);
            $addedTime = isset($inc['addedTime']) && $inc['addedTime'] !== 999 ? (int)$inc['addedTime'] : 0;
            $timeLabel = $time . ($addedTime > 0 ? '+' . $addedTime : '') . "'";

            if ($type === 'goal') {
                $player  = $this->getPlayerShortName($inc['player'] ?? []);
                $assist  = $this->getPlayerShortName($inc['assist1'] ?? []);
                $hScore  = $inc['homeScore'] ?? null;
                $aScore  = $inc['awayScore'] ?? null;
                $score   = ($hScore !== null && $aScore !== null) ? ($hScore . ':' . $aScore) : '';
                $class   = $inc['incidentClass'] ?? 'regular';
                $goalType = $inc['goalType'] ?? '';

                $entry = [
                    'time'       => $time,
                    'addedTime'  => $addedTime,
                    'time_label' => $timeLabel,
                    'player'     => $player,
                    'assist'     => $assist,
                    'score'      => $score,
                    'isHome'     => $isHome,
                    'class'      => $class,
                    'goalType'   => $goalType,
                ];
                $goals[] = $entry;

                $label = ($isHome ? '⚽ ' : '') . $player
                    . ($assist ? ' (п. ' . $assist . ')' : '')
                    . ($score ? ' ' . $score : '');
                $timeline[] = ['type' => 'goal', 'time' => $time, 'addedTime' => $addedTime,
                    'time_label' => $timeLabel, 'isHome' => $isHome, 'label' => $label, 'score' => $score];

            } elseif ($type === 'card') {
                $player  = $this->getPlayerShortName($inc['player'] ?? []);
                $reason  = $inc['reason'] ?? '';
                $class   = $inc['incidentClass'] ?? 'yellow'; // yellow, yellowRed, red

                $entry = [
                    'time'       => $time,
                    'addedTime'  => $addedTime,
                    'time_label' => $timeLabel,
                    'player'     => $player,
                    'reason'     => $reason,
                    'class'      => $class,
                    'isHome'     => $isHome,
                ];
                $cards[] = $entry;

                $icon = match ($class) {
                    'yellowRed' => '🟥🟨',
                    'red'       => '🟥',
                    default     => '🟨',
                };
                $timeline[] = ['type' => 'card', 'time' => $time, 'addedTime' => $addedTime,
                    'time_label' => $timeLabel, 'isHome' => $isHome,
                    'label' => $icon . ' ' . $player . ($reason ? ' (' . $reason . ')' : '')];

            } elseif ($type === 'substitution') {
                $playerIn  = $this->getPlayerShortName($inc['playerIn'] ?? []);
                $playerOut = $this->getPlayerShortName($inc['playerOut'] ?? []);

                $entry = [
                    'time'       => $time,
                    'addedTime'  => $addedTime,
                    'time_label' => $timeLabel,
                    'playerIn'   => $playerIn,
                    'playerOut'  => $playerOut,
                    'isHome'     => $isHome,
                ];
                $substitutions[] = $entry;

                $timeline[] = ['type' => 'sub', 'time' => $time, 'addedTime' => $addedTime,
                    'time_label' => $timeLabel, 'isHome' => $isHome,
                    'label' => '🔄 ↑' . $playerIn . ' ↓' . $playerOut];
            }
            // period and injuryTime incidents are skipped — just noise
        }

        // Sort timeline by time ascending
        usort($timeline, fn($a, $b) => $a['time'] <=> $b['time'] ?: $a['addedTime'] <=> $b['addedTime']);

        return [
            'goals'         => $goals,
            'cards'         => $cards,
            'substitutions' => $substitutions,
            'timeline'      => $timeline,
        ];
    }

    /**
     * Получить краткое имя игрока (shortName из fieldTranslations.ru или shortName или name).
     *
     * @param array $player
     * @return string
     */
    private function getPlayerShortName(array $player): string
    {
        if (empty($player)) {
            return '';
        }
        return $player['fieldTranslations']['nameTranslation']['ru']
            ?? $player['shortName']
            ?? $player['name']
            ?? '';
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: HTTP + Cache                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить GET-запрос к SportAPI7.
     *
     * @param string $path Путь (e.g. /api/v1/sport/football/scheduled-events/2025-04-15)
     *
     * @return array|null
     */
    private function request(string $path): ?array
    {
        $result = $this->httpGet(
            self::BASE_URL . $path,
            [
                'x-rapidapi-key: '  . $this->apiKey,
                'x-rapidapi-host: ' . self::RAPIDAPI_HOST,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            20
        );

        if (!$result['success']) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[SportAPI7Service] HTTP error for ' . $path . ': ' . ($result['error'] ?? '')
            );
            return null;
        }

        return is_array($result['data']) ? $result['data'] : null;
    }

    private function getCache(string $key): ?array
    {
        $data = $this->cache->get($key);
        return is_array($data) ? $data : null;
    }

    private function setCache(string $key, array $data, int $ttl): void
    {
        $this->cache->set($key, $data, $ttl);
    }
}
