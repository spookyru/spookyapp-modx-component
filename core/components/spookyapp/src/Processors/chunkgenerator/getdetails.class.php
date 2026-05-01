<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;

/**
 * SpookyAppChunkGeneratorGetDetailsProcessor — получение детальной информации.
 *
 * ═══════════════════════════════════════════════════════════════
 * Получает ПОЛНУЮ информацию по ID из соответствующего API-сервиса.
 * Используется после того, как пользователь выбрал конкретный элемент
 * из результатов поиска (search processor).
 *
 * Параметры:
 *   - type (string):    Тип контента
 *     movie|tv|person|game|device|product|
 *     match|tournament|team|league|biathlon_schedule|biathlon_results
 *   - id (string|int):  ID элемента в API
 *   - options (array):  Что загружать дополнительно:
 *     cast, crew, screenshots, similar, reviews, offers, etc.
 *   - language (string): Язык (default 'ru')
 *
 * Возвращает:
 *   - success: true/false
 *   - data: полная информация
 *   - type: тип контента
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorGetDetailsProcessor extends Processor
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var array<string> Допустимые типы контента */
    private const VALID_TYPES = [
        'movie', 'tv', 'person',
        'game',
        'device',
        'product',
        'match', 'tournament',
        'team', 'league',
        'biathlon_schedule', 'biathlon_results',
        'flashsport',
        'sportapi',
        'github',
    ];

    /** @var string Класс модуля */
    public $classKey = 'SpookyAppChunkGeneratorGetDetails';

    /** @var string Лексикон */
    public $languageTopics = ['spookyapp:chunkgenerator'];

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Initialize                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Инициализация процессора.
     *
     * @return bool|string true при успехе, строка с ошибкой
     */
    public function initialize(): bool|string
    {
        $autoload = MODX_CORE_PATH . 'components/spookyapp/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $type = trim((string)$this->getProperty('type', ''));
        if (empty($type)) {
            return $this->modx->lexicon('spookyapp.chunkgenerator.err_type_required')
                ?: 'Parameter "type" is required';
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            return ($this->modx->lexicon('spookyapp.chunkgenerator.err_type_invalid')
                ?: 'Invalid type. Allowed: ') . implode(', ', self::VALID_TYPES);
        }

        $id = $this->getProperty('id', '');
        if ($id === '' || $id === null) {
            return $this->modx->lexicon('spookyapp.chunkgenerator.err_id_required')
                ?: 'Parameter "id" is required';
        }

        return parent::initialize();
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Process                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Основная логика процессора.
     *
     * @return array Результат выполнения
     */
    public function process(): array
    {
        $type     = trim((string)$this->getProperty('type'));
        $id       = $this->getProperty('id');
        $options  = $this->getProperty('options', []);
        $language = trim((string)$this->getProperty('language', 'ru'));

        if (is_string($options)) {
            $options = json_decode($options, true) ?: [];
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ChunkGenerator:GetDetails] type=' . $type
            . ' id=' . $id
            . ' options=' . json_encode($options)
        );

        try {
            // ── Если передан chunk_id — грузим из БД ─────────────
            $chunkId = (int)$this->getProperty('chunk_id', 0);
            if ($chunkId > 0) {
                $this->modx->addPackage(
                    'SpookyApp\\Model',
                    MODX_CORE_PATH . 'components/spookyapp/src/Model/'
                );
                $savedChunk = $this->modx->getObject(\SpookyApp\Model\SpookyAppChunk::class, $chunkId);
                if ($savedChunk) {
                    $savedData = json_decode((string)$savedChunk->get('data'), true);
                    if (!empty($savedData)) {
                        return $this->success('', [
                            'type'   => $type,
                            'id'     => $id,
                            'data'   => $savedData,
                            'source' => 'db',
                        ]);
                    }
                }
            }

            // ── Fallback: lookup по external_id + type ─────────────
            if ($chunkId <= 0) {
                $this->modx->addPackage(
                    'SpookyApp\\Model',
                    MODX_CORE_PATH . 'components/spookyapp/src/Model/'
                );
                $savedChunk = $this->modx->getObject(\SpookyApp\Model\SpookyAppChunk::class, [
                    'external_id' => (string)$id,
                    'type'        => $type,
                ]);
                if ($savedChunk) {
                    $savedData = json_decode((string)$savedChunk->get('data'), true);
                    if (!empty($savedData)) {
                        return $this->success('', [
                            'type'   => $type,
                            'id'     => $id,
                            'data'   => $savedData,
                            'source' => 'db',
                        ]);
                    }
                }
            }

            $data = match ($type) {
                'movie'              => $this->getMovieDetails((int)$id, $options, $language),
                'tv'                 => $this->getTVDetails((int)$id, $options, $language),
                'person'             => $this->getPersonDetails((int)$id, $options, $language),
                'game'               => $this->getGameDetails((int)$id, $options),
                'device'             => $this->getDeviceDetails((string)$id, trim((string)$this->getProperty('source', 'rapidapi')), trim((string)$this->getProperty('title', ''))),
                'product'            => $this->getProductDetails((string)$id, $options, $language),
                'match'              => $this->getMatchDetails((string)$id, $language),
                'tournament'         => $this->getTournamentDetails((string)$id, $language),
                'team'               => $this->getTeamDetails((int)$id),
                'league'             => $this->getLeagueStandings((int)$id, $options),
                'biathlon_schedule'  => $this->getBiathlonSchedule((string)$id),
                'biathlon_results'   => $this->getBiathlonResults((string)$id),
                'flashsport'         => $this->getFlashSportDetails((string)$id, $language),
                'sportapi'           => $this->getSportApiDetails((int)$id),
                'github'             => $this->getGitHubDetails((string)$id),
                default              => throw new \InvalidArgumentException('Unknown type: ' . $type),
            };

            if (empty($data)) {
                return $this->failure(
                    $this->modx->lexicon('spookyapp.chunkgenerator.err_not_found')
                        ?: 'Item not found'
                );
            }

            return $this->success('', [
                'type' => $type,
                'id'   => $id,
                'data' => $data,
            ]);

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ChunkGenerator:GetDetails] Error: ' . $e->getMessage()
            );
            return $this->failure(
                $this->modx->lexicon('spookyapp.chunkgenerator.err_details_failed')
                    ?: 'Failed to get details: ' . $e->getMessage()
            );
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Movie / TV / Person (TMDB)                    ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Детали фильма.
     *
     * Загружает: основную информацию, актёров, съёмочную группу,
     * скриншоты, похожие фильмы (по options).
     *
     * @param int    $id       TMDB Movie ID
     * @param array  $options  Опции: cast, crew, screenshots, similar
     * @param string $language Язык
     *
     * @return array
     */
    private function getMovieDetails(int $id, array $options, string $language): array
    {
        $service = $this->getTMDBService();
        $lang = $language . '-' . strtoupper($language);

        // ── Основная информация ──────────────────────────────
        $movie = $service->getMovieDetails($id, $lang);
        if (empty($movie)) {
            return [];
        }

        $result = [
            'id'             => $movie['id'] ?? $id,
            'title'          => $movie['title'] ?? '',
            'original_title' => $movie['original_title'] ?? '',
            'tagline'        => $movie['tagline'] ?? '',
            'overview'       => $movie['overview'] ?? '',
            'release_date'   => $movie['release_date'] ?? '',
            'runtime'        => $movie['runtime'] ?? 0,
            'budget'         => $movie['budget'] ?? 0,
            'revenue'        => $movie['revenue'] ?? 0,
            'rating'         => $movie['vote_average'] ?? 0,
            'vote_count'     => $movie['vote_count'] ?? 0,
            'poster'         => !empty($movie['poster_path'])
                ? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] : null,
            'backdrop'       => !empty($movie['backdrop_path'])
                ? 'https://image.tmdb.org/t/p/w780' . $movie['backdrop_path'] : null,
            'genres'         => array_map(fn($g) => $g['name'] ?? '', $movie['genres'] ?? []),
            'countries'      => array_map(
                fn($c) => $c['name'] ?? '',
                $movie['production_countries'] ?? []
            ),
            'imdb_id'              => $movie['imdb_id'] ?? null,
            'homepage'             => $movie['homepage'] ?? null,
            'status'               => $movie['status'] ?? '',
            'original_language'    => $movie['original_language'] ?? '',
            'production_companies' => array_map(function (array $c): array {
                return [
                    'name'      => $c['name'] ?? '',
                    'logo_path' => !empty($c['logo_path'])
                        ? 'https://image.tmdb.org/t/p/w185' . $c['logo_path'] : null,
                ];
            }, $movie['production_companies'] ?? []),
        ];

        // ── Актёры и съёмочная группа ────────────────────────
        $includeCast = in_array('cast', $options, true) || empty($options);
        $includeCrew = in_array('crew', $options, true) || empty($options);

        if ($includeCast || $includeCrew) {
            $credits = $service->getMovieCredits($id);

            if ($includeCast) {
                $result['cast'] = array_slice(
                    array_map(function (array $person): array {
                        return [
                            'id'        => $person['id'] ?? 0,
                            'name'      => $person['name'] ?? '',
                            'character' => $person['character'] ?? '',
                            'photo'     => !empty($person['profile_path'])
                                ? 'https://image.tmdb.org/t/p/w185' . $person['profile_path']
                                : null,
                            'order'     => $person['order'] ?? 999,
                        ];
                    }, $credits['cast'] ?? []),
                    0, 20
                );
            }

            if ($includeCrew) {
                $crew = $credits['crew'] ?? [];
                usort($crew, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
                $result['crew'] = array_map(function (array $person): array {
                    return [
                        'id'            => $person['id'] ?? 0,
                        'name'          => $person['name'] ?? '',
                        'original_name' => $person['original_name'] ?? '',
                        'job'           => $person['job'] ?? '',
                        'department'    => $person['department'] ?? '',
                        'popularity'    => round((float)($person['popularity'] ?? 0), 1),
                        'photo'         => !empty($person['profile_path'])
                            ? 'https://image.tmdb.org/t/p/w185' . $person['profile_path']
                            : null,
                    ];
                }, $crew);
            }
        }

        // ── Скриншоты ────────────────────────────────────────
        // TODO: Добавить метод getMovieImages() в TMDBService
        // if (in_array('screenshots', $options, true)) {
        //     $images = $service->getMovieImages($id);
        //     $result['screenshots'] = array_slice(
        //         array_map(
        //             fn($img) => 'https://image.tmdb.org/t/p/w780' . ($img['file_path'] ?? ''),
        //             $images['backdrops'] ?? []
        //         ),
        //         0, 10
        //     );
        // }

        // ── Похожие фильмы ──────────────────────────────────
        // TODO: Добавить метод getSimilarMovies() в TMDBService
        // if (in_array('similar', $options, true)) {
        //     $similar = $service->getSimilarMovies($id, 1, $lang);
        //     $result['similar'] = array_slice(
        //         array_map(function (array $item): array {
        //             return [
        //                 'id'     => $item['id'] ?? 0,
        //                 'title'  => $item['title'] ?? '',
        //                 'year'   => substr($item['release_date'] ?? '', 0, 4),
        //                 'poster' => !empty($item['poster_path'])
        //                     ? 'https://image.tmdb.org/t/p/w185' . $item['poster_path']
        //                     : null,
        //                 'rating' => $item['vote_average'] ?? 0,
        //             ];
        //         }, $similar['results'] ?? []),
        //         0, 10
        //     );
        // }

        return $result;
    }

    /**
     * Детали сериала.
     *
     * @param int    $id       TMDB TV ID
     * @param array  $options  Опции: cast, crew, screenshots, similar, seasons
     * @param string $language Язык
     *
     * @return array
     */
    private function getTVDetails(int $id, array $options, string $language): array
    {
        $service = $this->getTMDBService();
        $lang = $language . '-' . strtoupper($language);

        $tv = $service->getTVShowDetails($id, $lang);
        if (empty($tv)) {
            return [];
        }

        $result = [
            'id'               => $tv['id'] ?? $id,
            'title'            => $tv['name'] ?? '',
            'original_title'   => $tv['original_name'] ?? '',
            'tagline'          => $tv['tagline'] ?? '',
            'overview'         => $tv['overview'] ?? '',
            'first_air_date'   => $tv['first_air_date'] ?? '',
            'last_air_date'    => $tv['last_air_date'] ?? '',
            'status'           => $tv['status'] ?? '',
            'number_of_seasons'  => $tv['number_of_seasons'] ?? 0,
            'number_of_episodes' => $tv['number_of_episodes'] ?? 0,
            'episode_run_time'   => $tv['episode_run_time'][0] ?? 0,
            'rating'           => $tv['vote_average'] ?? 0,
            'vote_count'       => $tv['vote_count'] ?? 0,
            'poster'           => !empty($tv['poster_path'])
                ? 'https://image.tmdb.org/t/p/w500' . $tv['poster_path'] : null,
            'backdrop'         => !empty($tv['backdrop_path'])
                ? 'https://image.tmdb.org/t/p/w780' . $tv['backdrop_path'] : null,
            'genres'               => array_map(fn($g) => $g['name'] ?? '', $tv['genres'] ?? []),
            'networks'             => array_map(function (array $n): array {
                return [
                    'name'      => $n['name'] ?? '',
                    'logo_path' => !empty($n['logo_path'])
                        ? 'https://image.tmdb.org/t/p/w185' . $n['logo_path'] : null,
                ];
            }, $tv['networks'] ?? []),
            'homepage'             => $tv['homepage'] ?? null,
            'original_language'    => $tv['original_language'] ?? '',
            'type'                 => $tv['type'] ?? '',
            'origin_country'       => $tv['origin_country'] ?? [],
            'production_companies' => array_map(function (array $c): array {
                return [
                    'name'      => $c['name'] ?? '',
                    'logo_path' => !empty($c['logo_path'])
                        ? 'https://image.tmdb.org/t/p/w185' . $c['logo_path'] : null,
                ];
            }, $tv['production_companies'] ?? []),
            'created_by'           => $tv['created_by'] ?? [],
            'last_episode'         => !empty($tv['last_episode_to_air']) ? sprintf(
                'S%02dE%02d — %s (%s)',
                $tv['last_episode_to_air']['season_number'] ?? 0,
                $tv['last_episode_to_air']['episode_number'] ?? 0,
                $tv['last_episode_to_air']['name'] ?? ''
            ) : null,
            'last_episode_to_air'  => !empty($tv['last_episode_to_air']) ? [
                'air_date'       => $tv['last_episode_to_air']['air_date'] ?? '',
                'episode_number' => $tv['last_episode_to_air']['episode_number'] ?? 0,
                'runtime'        => $tv['last_episode_to_air']['runtime'] ?? null,
                'season_number'  => $tv['last_episode_to_air']['season_number'] ?? 0,
                'name'           => $tv['last_episode_to_air']['name'] ?? '',
            ] : null,
            'next_episode'         => !empty($tv['next_episode_to_air']) ? sprintf(
                'S%02dE%02d — %s (%s)',
                $tv['next_episode_to_air']['season_number'] ?? 0,
                $tv['next_episode_to_air']['episode_number'] ?? 0,
                $tv['next_episode_to_air']['name'] ?? ''
            ) : null,
            'next_episode_to_air'  => !empty($tv['next_episode_to_air']) ? [
                'air_date'       => $tv['next_episode_to_air']['air_date'] ?? '',
                'episode_number' => $tv['next_episode_to_air']['episode_number'] ?? 0,
                'runtime'        => $tv['next_episode_to_air']['runtime'] ?? null,
                'season_number'  => $tv['next_episode_to_air']['season_number'] ?? 0,
                'name'           => $tv['next_episode_to_air']['name'] ?? '',
            ] : null,
        ];

        // ── Сезоны ───────────────────────────────────────────
        if (in_array('seasons', $options, true) || empty($options)) {
            $result['seasons'] = array_map(function (array $season): array {
                return [
                    'season_number' => $season['season_number'] ?? 0,
                    'name'          => $season['name'] ?? '',
                    'episode_count' => $season['episode_count'] ?? 0,
                    'air_date'      => $season['air_date'] ?? '',
                    'overview'      => $season['overview'] ?? '',
                    'poster'        => !empty($season['poster_path'])
                        ? 'https://image.tmdb.org/t/p/w185' . $season['poster_path']
                        : null,
                ];
            }, $tv['seasons'] ?? []);
        }

        // ── Актёры и съёмочная группа ────────────────────────────────
        if (in_array('cast', $options, true) || empty($options)) {
            $credits = $service->getTVShowCredits($id);
            $result['cast'] = array_slice(
                array_map(function (array $person): array {
                    return [
                        'id'            => $person['id'] ?? 0,
                        'name'          => $person['name'] ?? '',
                        'original_name' => $person['original_name'] ?? '',
                        'character'     => $person['character'] ?? '',
                        'popularity'    => round((float)($person['popularity'] ?? 0), 1),
                        'photo'         => !empty($person['profile_path'])
                            ? 'https://image.tmdb.org/t/p/w185' . $person['profile_path']
                            : null,
                    ];
                }, $credits['cast'] ?? []),
                0, 30
            );
            $tvCrew = $credits['crew'] ?? [];
            usort($tvCrew, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
            $result['crew'] = array_map(function (array $person): array {
                return [
                    'id'            => $person['id'] ?? 0,
                    'name'          => $person['name'] ?? '',
                    'original_name' => $person['original_name'] ?? '',
                    'job'           => $person['job'] ?? '',
                    'department'    => $person['department'] ?? '',
                    'popularity'    => round((float)($person['popularity'] ?? 0), 1),
                    'photo'         => !empty($person['profile_path'])
                        ? 'https://image.tmdb.org/t/p/w185' . $person['profile_path']
                        : null,
                ];
            }, $tvCrew);
        }

        // ── Похожие ─────────────────────────────────────────
        // TODO: Добавить метод getSimilarTVShows() в TMDBService
        // if (in_array('similar', $options, true)) {
        //     $similar = $service->getSimilarTVShows($id, 1, $lang);
        //     $result['similar'] = array_slice(
        //         array_map(function (array $item): array {
        //             return [
        //                 'id'     => $item['id'] ?? 0,
        //                 'title'  => $item['name'] ?? '',
        //                 'year'   => substr($item['first_air_date'] ?? '', 0, 4),
        //                 'poster' => !empty($item['poster_path'])
        //                     ? 'https://image.tmdb.org/t/p/w185' . $item['poster_path']
        //                     : null,
        //                 'rating' => $item['vote_average'] ?? 0,
        //             ];
        //         }, $similar['results'] ?? []),
        //         0, 10
        //     );
        // }

        return $result;
    }

    /**
     * Детали актёра/персоны.
     *
     * Последовательно обращается к 4 эндпойнтам TMDB с паузой 3с между
     * реальными HTTP-запросами (пауза пропускается, если данные уже в кеше):
     *
     * 1. GET /person/{id}                    — базовые данные
     * 2. GET /person/{id}/external_ids       — соцсети + Wikidata → Wikipedia
     * 3. GET /person/{id}/combined_credits   — фильмография (фильмы + сериалы)
     *    Если combined пуст → fallback:
     *      GET /person/{id}/movie_credits
     *      GET /person/{id}/tv_credits
     * 4. GET /person/{id}/translations       — биография EN
     *
     * @param int    $id       TMDB Person ID
     * @param array  $options  Опции: movies, tv, images
     * @param string $language Язык (напр. 'ru')
     *
     * @return array
     */
    private function getPersonDetails(int $id, array $options, string $language): array
    {
        $service     = $this->getTMDBService();
        $cacheHelper = new \SpookyApp\Services\Cache\CacheService($this->modx);
        $lang        = $language . '-' . strtoupper($language); // 'ru-RU'
        $pfx         = 'spookyapp/tmdb/person/' . $id . '/';

        // didHttp: true = предыдущий шаг сделал HTTP-запрос → нужна пауза 3с
        $didHttp = false;

        /**
         * Проверяет кеш перед вызовом сервиса.
         * Если предыдущий шаг был HTTP-запросом → sleep(3) перед следующим.
         * Обновляет $didHttp на основе наличия данных в кеше.
         */
        $step = function(string $cacheKey) use ($cacheHelper, &$didHttp): void {
            if ($didHttp) {
                $this->modx->log(modX::LOG_LEVEL_INFO,
                    '[GetDetails:Person] Pause 3s before next TMDB request...');
                sleep(3);
            }
            // Если ключа нет в кеше → следующий вызов сервиса сделает HTTP-запрос
            $didHttp = ($cacheHelper->get($cacheKey) === null);
        };

        // ── Scaffold: минимальный набор до загрузки с API ─────────────────────
        $this->modx->log(modX::LOG_LEVEL_ERROR,
            '[GetDetails:Person] START id=' . $id
            . ' lang=' . $lang
            . ' options=' . json_encode($options));

        $result = ['id' => $id, 'name' => '', 'type' => 'person'];

        // ══════════════════════════════════════════════════════════════════════
        // ШАГ 1: Основные данные персоны  GET /person/{id}
        // ══════════════════════════════════════════════════════════════════════
        $step($pfx . $lang);
        $person = $service->getPersonDetails($id, $lang);

        if (empty($person)) {
            $this->modx->log(modX::LOG_LEVEL_WARN,
                '[GetDetails:Person] Step 1 EMPTY for id=' . $id);
            return [];
        }

        $result = array_merge($result, [
            'id'                   => $person['id'] ?? $id,
            'name'                 => $person['name'] ?? '',
            'biography'            => $person['biography'] ?? '',
            'biography_en'         => '', // заполнится на шаге 4
            'birthday'             => $person['birthday'] ?? '',
            'deathday'             => $person['deathday'] ?? null,
            'place_of_birth'       => $person['place_of_birth'] ?? '',
            'gender'               => $person['gender'] ?? 0,
            'popularity'           => $person['popularity'] ?? 0,
            'photo'                => !empty($person['profile_path'])
                ? 'https://image.tmdb.org/t/p/w500' . $person['profile_path'] : null,
            'imdb_id'              => $person['imdb_id'] ?? null,
            'homepage'             => $person['homepage'] ?? null,
            'known_for_department' => $person['known_for_department'] ?? '',
            'also_known_as'        => $person['also_known_as'] ?? [],
        ]);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[GetDetails:Person] Step 1 OK: "' . $result['name'] . '"');

        // ══════════════════════════════════════════════════════════════════════
        // ШАГ 2: Внешние идентификаторы  GET /person/{id}/external_ids
        // ══════════════════════════════════════════════════════════════════════
        $step($pfx . 'external_ids/ru');
        $extIds = $service->getPersonExternalIds($id, 'ru');

        $result['wikidata_id']   = $extIds['wikidata_id']   ?? null;
        $result['wikipedia_url'] = $extIds['wikipedia_url'] ?? null;
        $result['facebook_id']   = $extIds['facebook_id']   ?? null;
        $result['instagram_id']  = $extIds['instagram_id']  ?? null;
        $result['twitter_id']    = $extIds['twitter_id']    ?? null;
        $result['tiktok_id']     = $extIds['tiktok_id']     ?? null;
        $result['youtube_id']    = $extIds['youtube_id']    ?? null;
        // Fallback: imdb_id из external_ids, если /person его не вернул
        if (empty($result['imdb_id']) && !empty($extIds['imdb_id'])) {
            $result['imdb_id'] = $extIds['imdb_id'];
        }

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[GetDetails:Person] Step 2 OK: wikidata=' . ($result['wikidata_id'] ?? 'none')
            . ' wikipedia=' . (empty($result['wikipedia_url']) ? 'none' : 'yes'));

        // ══════════════════════════════════════════════════════════════════════
        // ШАГ 3: Фильмография  GET /person/{id}/combined_credits
        //         Fallback → movie_credits + tv_credits (если combined пуст)
        // ══════════════════════════════════════════════════════════════════════
        // Credits are always fetched for persons.
        // The 'options' array filters what the UI shows; it should not gate API calls.
        // Previously, options=["images"] caused includeCredits=FALSE — this was the bug.
        $includeCredits = true;

        $this->modx->log(modX::LOG_LEVEL_ERROR,
            '[GetDetails:Person] Step 3 check: includeCredits=' . ($includeCredits ? 'TRUE' : 'FALSE')
            . ' (options=' . json_encode($options) . ')');

        if ($includeCredits) {
            $step($pfx . 'combined_credits/' . $lang);
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[GetDetails:Person] Step 3: calling getPersonCombinedCredits id=' . $id . ' lang=' . $lang);
            $combined = $service->getPersonCombinedCredits($id, $lang);
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[GetDetails:Person] Step 3 result: cast=' . count($combined['cast'])
                . ' crew=' . count($combined['crew']));

            if (!empty($combined['cast']) || !empty($combined['crew'])) {
                // ── combined_credits успешен ─────────────────────────────────
                $this->modx->log(modX::LOG_LEVEL_INFO,
                    '[GetDetails:Person] Step 3 OK combined: cast='
                    . count($combined['cast']) . ' crew=' . count($combined['crew']));

                $castAll = $combined['cast'];
                usort($castAll, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));

                $result['movie_credits'] = array_slice(
                    array_map(fn($item) => [
                        'id'        => (int)($item['id'] ?? 0),
                        'title'     => $item['title'] ?? '',
                        'character' => $item['character'] ?? '',
                        'year'      => substr($item['date'] ?? '', 0, 4),
                        'poster'    => !empty($item['poster_path'])
                            ? 'https://image.tmdb.org/t/p/w185' . $item['poster_path'] : null,
                        'rating'    => round((float)($item['vote_average'] ?? 0), 1),
                    ], array_values(array_filter(
                        $castAll,
                        fn($i) => ($i['media_type'] ?? '') === 'movie'
                    ))),
                    0, 30
                );

                $result['tv_credits'] = array_slice(
                    array_map(fn($item) => [
                        'id'            => (int)($item['id'] ?? 0),
                        'title'         => $item['title'] ?? '',
                        'character'     => $item['character'] ?? '',
                        'year'          => substr($item['date'] ?? '', 0, 4),
                        'poster'        => !empty($item['poster_path'])
                            ? 'https://image.tmdb.org/t/p/w185' . $item['poster_path'] : null,
                        'episode_count' => (int)($item['episode_count'] ?? 0),
                    ], array_values(array_filter(
                        $castAll,
                        fn($i) => ($i['media_type'] ?? '') === 'tv'
                    ))),
                    0, 20
                );

            } else {
                // ── Fallback: отдельные запросы movie_credits + tv_credits ────
                $this->modx->log(modX::LOG_LEVEL_WARN,
                    '[GetDetails:Person] combined_credits EMPTY → fallback to separate endpoints');

                // Фильмы
                $step($pfx . 'movie_credits/' . $lang);
                $movieRaw  = $service->getPersonMovieCredits($id, $lang);
                $movieCast = $movieRaw['cast'] ?? [];
                usort($movieCast, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
                $result['movie_credits'] = array_slice(
                    array_map(fn($item) => [
                        'id'        => (int)($item['id'] ?? 0),
                        'title'     => $item['title'] ?? '',
                        'character' => $item['character'] ?? '',
                        'year'      => substr($item['release_date'] ?? '', 0, 4),
                        'poster'    => !empty($item['poster_path'])
                            ? 'https://image.tmdb.org/t/p/w185' . $item['poster_path'] : null,
                        'rating'    => round((float)($item['vote_average'] ?? 0), 1),
                    ], $movieCast),
                    0, 30
                );

                // Сериалы
                $step($pfx . 'tv_credits/' . $lang);
                $tvRaw  = $service->getPersonTVCredits($id, $lang);
                $tvCast = $tvRaw['cast'] ?? [];
                usort($tvCast, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
                $result['tv_credits'] = array_slice(
                    array_map(fn($item) => [
                        'id'            => (int)($item['id'] ?? 0),
                        'title'         => $item['name'] ?? '',
                        'character'     => $item['character'] ?? '',
                        'year'          => substr($item['first_air_date'] ?? '', 0, 4),
                        'poster'        => !empty($item['poster_path'])
                            ? 'https://image.tmdb.org/t/p/w185' . $item['poster_path'] : null,
                        'episode_count' => (int)($item['episode_count'] ?? 0),
                    ], $tvCast),
                    0, 20
                );

                $this->modx->log(modX::LOG_LEVEL_INFO,
                    '[GetDetails:Person] Fallback OK: movies='
                    . count($result['movie_credits'])
                    . ' tv=' . count($result['tv_credits']));
            }
        }

        $this->modx->log(modX::LOG_LEVEL_ERROR,
            '[GetDetails:Person] After step 3: movie_credits=' . count($result['movie_credits'] ?? [])
            . ' tv_credits=' . count($result['tv_credits'] ?? []));

        // ══════════════════════════════════════════════════════════════════════
        // ШАГ 4: Переводы биографии  GET /person/{id}/translations
        // ══════════════════════════════════════════════════════════════════════
        $step($pfx . 'translations');
        $translations = $service->getPersonTranslations($id);

        // Предпочитаем EN; если пусто — берём первый доступный язык
        $result['biography_en'] = $translations['en']
            ?? (count($translations) > 0 ? reset($translations) : '');

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[GetDetails:Person] Step 4 OK: biography_en='
            . mb_strlen($result['biography_en']) . ' chars'
            . ' | langs available: ' . implode(', ', array_keys($translations)));

        $this->modx->log(modX::LOG_LEVEL_ERROR,
            '[GetDetails:Person] RETURN: keys=' . implode(',', array_keys($result))
            . ' | movie_credits=' . count($result['movie_credits'] ?? [])
            . ' | tv_credits=' . count($result['tv_credits'] ?? []));

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Game (RAWG)                                   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Детали игры.
     *
     * @param int   $id      RAWG Game ID
     * @param array $options Опции: screenshots, similar, stores
     *
     * @return array
     */
    private function getGameDetails(int $id, array $options): array
    {
        $service = $this->getGamesService();

        $game = $service->getGameDetailsByRAWG($id);
        if (empty($game)) {
            return [];
        }

        $result = [
            'id'               => $game['id'] ?? $id,
            'title'            => $game['name'] ?? '',
            'description'      => $game['description_raw'] ?? $game['description'] ?? '',
            'released'         => $game['released'] ?? '',
            'rating'           => $game['rating'] ?? 0,
            'ratings_count'    => $game['ratings_count'] ?? 0,
            'metacritic'       => $game['metacritic'] ?? null,
            'playtime'         => $game['playtime'] ?? 0,
            'poster'           => $game['background_image'] ?? null,
            'website'          => $game['website'] ?? null,
            'genres'           => array_map(fn($g) => $g['name'] ?? '', $game['genres'] ?? []),
            'platforms'        => array_map(
                fn($p) => $p['platform']['name'] ?? '',
                $game['platforms'] ?? []
            ),
            'pc_requirements'  => (function () use ($game): ?array {
                foreach ($game['platforms'] ?? [] as $p) {
                    if (($p['platform']['slug'] ?? '') === 'pc' && !empty($p['requirements'])) {
                        return $p['requirements'];
                    }
                }
                return null;
            })(),
            'developers'       => array_map(fn($d) => $d['name'] ?? '', $game['developers'] ?? []),
            'publishers'       => array_map(fn($p) => $p['name'] ?? '', $game['publishers'] ?? []),
            'esrb_rating'      => $game['esrb_rating']['name'] ?? null,
        ];

        // ── Скриншоты ────────────────────────────────────────
        if (in_array('screenshots', $options, true) || empty($options)) {
            $screenshots = $service->getGameScreenshots($id);
            $result['screenshots'] = array_slice(
                array_map(fn($s) => $s['image'] ?? '', $screenshots['results'] ?? []),
                0, 10
            );
        }

        // ── Похожие ─────────────────────────────────────────
        // TODO: Добавить метод getGameSeries() в GamesAPIService
        // if (in_array('similar', $options, true)) {
        //     $similar = $service->getGameSeries($id);
        //     $result['similar'] = array_slice(
        //         array_map(function (array $item): array {
        //             return [
        //                 'id'     => $item['id'] ?? 0,
        //                 'title'  => $item['name'] ?? '',
        //                 'poster' => $item['background_image'] ?? null,
        //                 'rating' => $item['rating'] ?? 0,
        //             ];
        //         }, $similar['results'] ?? []),
        //         0, 10
        //     );
        // }

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Device (Mobile Devices API)                   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Детали мобильного устройства.
     *
     * @param string $id     Phone slug (RapidAPI) или Device ID (MobileApi.dev)
     * @param string $source 'rapidapi' | 'mobileapi'
     * @param string $title  Название из грида (для fallback-поиска)
     *
     * @return array
     */
    /**
     * Загрузить пользовательские правки спецификаций из SpookyAppCache.
     * Возвращает {Section: {Key: "override value"}} или [].
     */
    private function loadDeviceSpecsOverrides(string $externalId): array
    {
        $prefix   = $this->modx->getOption('table_prefix', null, 'sitespk_');
        $table    = $prefix . 'spookyapp_cache';
        $cacheKey = 'device_specs_overrides::' . $externalId;
        $sql      = "SELECT `data` FROM `{$table}` WHERE `cache_key` = :key LIMIT 1";
        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bindValue(':key', $cacheKey, \PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || empty($row['data'])) {
                return [];
            }
            $data = json_decode($row['data'], true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Применить пользовательские правки поверх specifications из API.
     * Модифицирует массив $specifications in-place.
     */
    private function applyDeviceSpecsOverrides(array &$specifications, array $overrides): void
    {
        if (empty($overrides)) {
            return;
        }
        foreach ($specifications as &$section) {
            if (!is_array($section) || empty($section['specs'])) {
                continue;
            }
            $stitle = trim($section['title'] ?? '');
            if (!isset($overrides[$stitle]) || !is_array($overrides[$stitle])) {
                continue;
            }
            $sectionOverrides = $overrides[$stitle];
            foreach ($section['specs'] as &$spec) {
                $k = trim($spec['key'] ?? '');
                if ($k !== '' && isset($sectionOverrides[$k])) {
                    $spec['val'] = $sectionOverrides[$k];
                }
            }
            unset($spec);
        }
        unset($section);
    }

    private function getDeviceDetails(string $id, string $source = 'rapidapi', string $title = ''): array
    {
        $service = $this->getDevicesService();

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ChunkGenerator:GetDetails] getDeviceDetails id=' . $id . ' source=' . $source . ' title="' . $title . '"'
        );

        // ── MobileApi.dev ─────────────────────────────────────
        if ($source === 'mobileapi') {
            // Try direct ID lookup first (works when id is a numeric MobileApi device id)
            if (!empty($id)) {
                $result = $service->getDeviceByIdMobileApi($id);
                if (!empty($result) && ($result['success'] ?? false) && !empty($result['device'])) {
                    return $this->buildMobileApiDeviceData($id, $result['device'], $result['specs'] ?? []);
                }
                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    '[ChunkGenerator:GetDetails] MobileApi direct lookup failed for id=' . $id . ', trying search fallback'
                );
            }

            // Fallback: search by title and return best match
            $searchQuery = !empty($title) ? $title : $id;
            if (!empty($searchQuery)) {
                $searchResult = $service->searchDevices($searchQuery, 1);
                $devices = $searchResult['devices'] ?? [];
                if (!empty($devices)) {
                    // Prefer an exact id or name match; otherwise take first result
                    $best = $devices[0];
                    foreach ($devices as $dev) {
                        if (
                            (string)($dev['id'] ?? '') === $id
                            || mb_strtolower($dev['full_name'] ?? '') === mb_strtolower($title)
                        ) {
                            $best = $dev;
                            break;
                        }
                    }
                    $this->modx->log(
                        modX::LOG_LEVEL_INFO,
                        '[ChunkGenerator:GetDetails] MobileApi fallback search found: ' . ($best['full_name'] ?? '?')
                    );
                    // For search results we don't have nested specs — pass empty
                    return $this->buildMobileApiDeviceData($id, $best, []);
                }
            }

            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[ChunkGenerator:GetDetails] MobileApi: no device found for id=' . $id . ' title="' . $title . '"'
            );
            return [];
        }

        // ── RapidAPI (default) ────────────────────────────────
        $device = $service->getPhoneDetailsBySlug($id);
        if (empty($device) || !($device['success'] ?? false)) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[ChunkGenerator:GetDetails] getPhoneDetailsBySlug failed for id=' . $id
                . ' error=' . ($device['error'] ?? 'unknown')
            );
            return [];
        }

        // name not returned by API — use title from search grid as fallback
        $name = !empty($device['name']) ? $device['name'] : $title;

        $specifications = $device['specifications'] ?? [];
        $overrides = $this->loadDeviceSpecsOverrides($id);
        $this->applyDeviceSpecsOverrides($specifications, $overrides);

        return [
            'id'              => $id,
            'title'           => $name,
            'brand'           => $device['brand'] ?? '',
            'image'           => $device['image'] ?? null,
            'release_date'    => $device['release_date'] ?? '',
            'os'              => $device['os'] ?? '',
            'chipset'         => $device['chipset'] ?? '',
            'display'         => $device['display'] ?? '',
            'camera'          => $device['camera'] ?? '',
            'battery'         => $device['battery'] ?? '',
            'ram'             => $device['ram'] ?? '',
            'storage'         => $device['storage'] ?? '',
            'specifications'  => $specifications,
            'specs_flat'      => $device['specs_flat'] ?? [],
            'specs_overrides' => $overrides,
        ];
    }

    /**
     * Построить массив данных устройства из нормализованной записи MobileApi.dev.
     *
     * @param string $id Исходный ID запроса
     * @param array  $d  Нормализованное устройство (normalizeDevice)
     * @return array
     */
    private function buildMobileApiDeviceData(string $id, array $d, array $specs = []): array
    {
        $overrides = $this->loadDeviceSpecsOverrides($id);
        $this->applyDeviceSpecsOverrides($specs, $overrides);

        return [
            'id'              => !empty($d['id']) ? (string)$d['id'] : $id,
            'title'           => $d['full_name'] ?? $d['name'] ?? '',
            'brand'           => $d['brand'] ?? '',
            'image'           => !empty($d['image_url']) ? $d['image_url'] : null,
            'release_date'    => $d['announced_date'] ?? '',
            'os'              => $d['os'] ?? '',
            'chipset'         => $d['chipset'] ?? '',
            'display'         => $d['display'] ?? '',
            'camera'          => $d['camera'] ?? '',
            'battery'         => $d['battery'] ?? '',
            'ram'             => $d['ram'] ?? '',
            'storage'         => $d['storage'] ?? '',
            'detail_url'      => $d['detail_url'] ?? '',
            'specifications'  => $specs,
            'specs_flat'      => [],
            'specs_overrides' => $overrides,
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Product (Real-Time Product Search)            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Детали товара.
     *
     * Маршрутизация по source:
     *   - source=amazon → AmazonProductService
     *   - иначе         → ProductSearchService
     *
     * @param string $id       Product ID или Amazon ASIN
     * @param array  $options  Опции: offers
     * @param string $language Язык
     *
     * @return array
     */
    private function getProductDetails(string $id, array $options, string $language): array
    {
        $source  = trim((string)$this->getProperty('source', ''));
        $country = strtoupper(trim((string)$this->getProperty('country', 'US'))) ?: 'US';

        // ── Amazon ───────────────────────────────────────────
        if ($source === 'amazon') {
            $service = $this->getAmazonService();
            $product = $service->getProductDetails($id, $country);
            if (empty($product)) {
                return [];
            }
            // product_attributes is already a string from AmazonProductService
            // offers are already populated in $product['offers']
            return $product;
        }

        // ── Real-Time Product Search ─────────────────────────
        $service = $this->getProductSearchService();

        $product = $service->getProductDetails($id, 'us', $language);
        if (empty($product)) {
            return [];
        }

        $result = $product;

        // ── product_attributes → читаемый текст ──────────────
        if (!empty($result['product_attributes']) && is_array($result['product_attributes'])) {
            $parts = [];
            foreach ($result['product_attributes'] as $k => $v) {
                $parts[] = '<b>' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '</b>: ' . $v;
            }
            $result['product_attributes'] = implode('. ', $parts);
        } elseif (!is_string($result['product_attributes'] ?? null)) {
            $result['product_attributes'] = '';
        }

        // ── typical_price_range → строка ─────────────────────
        if (!empty($result['typical_price_range']) && is_array($result['typical_price_range'])) {
            $low  = (string)($result['typical_price_range']['low']  ?? '');
            $high = (string)($result['typical_price_range']['high'] ?? '');
            $result['typical_price_range'] = ($low === $high || $high === '')
                ? $low
                : $low . ' — ' . $high;
        } elseif (!is_string($result['typical_price_range'] ?? null)) {
            $result['typical_price_range'] = '';
        }

        // ── Офферы ───────────────────────────────────────────
        if (in_array('offers', $options, true)) {
            $offers = $service->getProductOffers($id, [
                'country'  => 'us',
                'language' => $language,
            ]);
            $result['offers'] = $offers['offers'] ?? [];
        }

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Sports (FlashLive)                            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Детали FlashLive: матч, таблица или расписание.
     *
     * source param = "mode|sport_id|day", e.g. "match|1|0" or "standings|4|0".
     * Для match: id = EVENT_ID
     * Для standings/schedule: id = "STAGE_ID:SEASON_ID"
     *
     * @param string $id       Event ID или encoded "stageId:seasonId"
     * @param string $language Язык
     *
     * @return array
     */
    private function getFlashSportDetails(string $id, string $language): array
    {
        $service = $this->getSportsService();

        $localeMap = [
            'ru' => 'ru_RU', 'en' => 'en_US', 'de' => 'de_DE',
            'fr' => 'fr_FR', 'es' => 'es_ES', 'it' => 'it_IT',
            'pt' => 'pt_PT', 'nl' => 'nl_NL', 'tr' => 'tr_TR', 'pl' => 'pl_PL',
        ];
        $locale = $localeMap[$language] ?? 'ru_RU';

        // source = "mode|sport_id|day" (sport_id and day currently unused in details)
        $parts  = explode('|', (string)$this->getProperty('source', 'match|1|0'), 3);
        $mode   = trim($parts[0] ?? 'match') ?: 'match';

        if ($mode === 'match') {
            return $service->getFullMatchData($id, $locale);
        }

        // For standings and schedule, id is encoded as "stageId:seasonId"
        $idParts  = explode(':', $id, 2);
        $stageId  = $idParts[0] ?? $id;
        $seasonId = $idParts[1] ?? '';

        if ($mode === 'standings') {
            return $service->getTournamentStandingsData($stageId, $seasonId, $locale);
        }

        // schedule: return both upcoming fixtures and recent results
        $fixtures = $service->getTournamentScheduleData($stageId, $locale, 'fixtures');
        $results  = $service->getTournamentScheduleData($stageId, $locale, 'results');
        return ['fixtures' => $fixtures, 'results' => $results];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: SportAPI7                                     ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Детали матча SportAPI7 по event ID.
     *
     * @param int $id Event ID из поисковых результатов SportAPI7
     *
     * @return array
     */
    private function getSportApiDetails(int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        $service = $this->getSportApi7Service();
        return $service->getEventDetails($id);
    }

    /**
     * Детали матча.
     *
     * @param string $id       Event ID
     * @param string $language Язык
     *
     * @return array
     */
    private function getMatchDetails(string $id, string $language): array
    {
        $service = $this->getSportsService();
        $locale = $language . '_INT';

        return $service->getMatchDetails($id, $locale);
    }

    /**
     * Календарь турнира.
     *
     * @param string $id       Tournament ID
     * @param string $language Язык
     *
     * @return array
     */
    private function getTournamentDetails(string $id, string $language): array
    {
        $service = $this->getSportsService();
        $locale = $language . '_INT';

        return $service->getTournamentCalendar($id, $locale);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Football (API-Football)                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Детали футбольного клуба.
     *
     * @param int $id Team ID
     *
     * @return array
     */
    private function getTeamDetails(int $id): array
    {
        $service = $this->getFootballService();

        return $service->getTeamDetails($id);
    }

    /**
     * Турнирная таблица лиги.
     *
     * @param int   $id      League ID
     * @param array $options Опции: season (int)
     *
     * @return array
     */
    private function getLeagueStandings(int $id, array $options): array
    {
        $service = $this->getFootballService();
        $season = (int)($options['season'] ?? (int)date('Y'));

        return $service->getLeagueStandings($id, $season);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Biathlon (IBU)                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Расписание сезона биатлона.
     *
     * @param string $seasonId Season ID (e.g. "2425")
     *
     * @return array
     */
    private function getBiathlonSchedule(string $seasonId): array
    {
        $service = $this->getBiathlonService();

        return $service->getEventSchedule($seasonId);
    }

    /**
     * Результаты этапа биатлона.
     *
     * @param string $eventId Event/Race ID
     *
     * @return array
     */
    private function getBiathlonResults(string $eventId): array
    {
        $service = $this->getBiathlonService();

        return $service->getEventResults($eventId);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Service Factories                             ║
    // ╚═════════════════════════════════════════════════════════╝

    private function getTMDBService(): \SpookyApp\Services\API\TMDBService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\TMDBService($this->modx, $cache);
    }

    private function getGamesService(): \SpookyApp\Services\API\GamesAPIService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\GamesAPIService($this->modx, $cache);
    }

    private function getDevicesService(): \SpookyApp\Services\API\MobileDevicesAPIService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\MobileDevicesAPIService($this->modx, $cache);
    }

    private function getProductSearchService(): \SpookyApp\Services\API\ProductSearchService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\ProductSearchService($this->modx, $cache);
    }

    private function getAmazonService(): \SpookyApp\Services\API\AmazonProductService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\AmazonProductService($this->modx, $cache);
    }

    private function getSportsService(): \SpookyApp\Services\API\SportsAPIService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\SportsAPIService($this->modx, $cache);
    }

    private function getFootballService(): \SpookyApp\Services\API\FootballAPIService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\FootballAPIService($this->modx, $cache);
    }

    private function getBiathlonService(): \SpookyApp\Services\API\BiathlonIBUService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\BiathlonIBUService($this->modx, $cache);
    }

    private function getSportApi7Service(): \SpookyApp\Services\API\SportAPI7Service
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\SportAPI7Service($this->modx, $cache);
    }

    /**
     * Детали GitHub репозитория по numeric ID или «owner/repo».
     *
     * @param string $id GitHub repo ID (числовой) или «owner/repo»
     * @return array
     */
    private function getGitHubDetails(string $id): array
    {
        $id = trim($id);
        if (empty($id)) {
            return [];
        }
        $service = $this->getGitHubService();
        return $service->getRepositoryById($id);
    }

    private function getGitHubService(): \SpookyApp\Services\API\GitHubService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);
        return new \SpookyApp\Services\API\GitHubService($this->modx, $cache);
    }
}

return 'SpookyAppChunkGeneratorGetDetailsProcessor';