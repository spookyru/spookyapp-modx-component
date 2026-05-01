<?php

declare(strict_types=1);

namespace SpookyApp\Processors\TopicFinder;

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;
use SpookyApp\Services\API\NewsAPIService;
use SpookyApp\Services\Cache\CacheService;
use Throwable;

/**
 * Процессор поиска в Real-Time News по теме.
 *
 * Параметры:
 *   - query (string): Поисковый запрос (обязательно)
 *   - language (string): Язык результатов (default: ru)
 *   - limit (int): Максимум результатов (default: 10, max: 50)
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class SearchNews extends Processor
{
    public $languageTopics = ['spookyapp:default'];

    public function initialize(): bool|string
    {
        $autoload = $this->modx->getOption('core_path') . 'components/spookyapp/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $query = trim((string)$this->getProperty('query', ''));
        if (empty($query)) {
            return 'Parameter "query" is required';
        }

        return parent::initialize();
    }

    public function process(): array
    {
        try {
            $query    = trim((string)$this->getProperty('query', ''));
            $language = trim((string)$this->getProperty('language', 'ru'));
            $limit    = min(50, max(1, (int)$this->getProperty('limit', 10)));

            $cache = new CacheService($this->modx);
            $newsService = new NewsAPIService($this->modx, $cache);

            $results = $newsService->getRealTimeBySearch($query, $language, 'US', '7d', $limit);

            if (empty($results)) {
                return $this->success('No news found for query: ' . $query, [
                    'results' => [],
                    'total'   => 0,
                    'query'   => $query,
                ]);
            }

            return $this->success('Found ' . count($results) . ' news articles', [
                'results' => $results,
                'total'   => count($results),
                'query'   => $query,
            ]);
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[SpookyApp:SearchNews] ' . $e->getMessage());
            return $this->failure('News search error: ' . $e->getMessage());
        }
    }
}

return SearchNews::class;

