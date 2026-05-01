<?php

declare(strict_types=1);

namespace SpookyApp\Processors\TopicFinder;

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;
use SpookyApp\Services\API\YandexAPIService;
use SpookyApp\Services\Cache\CacheService;
use PDO;
use Throwable;

/**
 * Процессор генерации AI-идей для статей через Yandex GPT.
 *
 * Параметры:
 *   - count (int): Кол-во идей (default: 10, min: 5, max: 20)
 *   - categories (string): JSON-массив категорий интересов
 *   - use_trends (bool): Учитывать текущие тренды из БД (default: true)
 *   - save (bool): Сохранить идеи в spookyapp_topics (default: true)
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class GenerateIdeas extends Processor
{
    public $languageTopics = ['spookyapp:default'];

    public function initialize(): bool|string
    {
        $autoload = $this->modx->getOption('core_path') . 'components/spookyapp/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        return parent::initialize();
    }

    public function process(): array
    {
        try {
            $count      = min(20, max(5, (int)$this->getProperty('count', 10)));
            $categories = $this->getProperty('categories', '');
            $useTrends  = (bool)$this->getProperty('use_trends', true);
            $save       = (bool)$this->getProperty('save', true);

            if (is_string($categories) && !empty($categories)) {
                $categories = json_decode($categories, true) ?: [];
            }
            if (!is_array($categories)) {
                $categories = [];
            }

            $cache = new CacheService($this->modx);
            $yandex = new YandexAPIService($this->modx, $cache);

            // Gather existing trends for context
            $trends = [];
            if ($useTrends) {
                $trends = $this->getRecentTrends();
            }

            $blogTheme = $this->modx->getOption(
                'spookyapp.blog_theme',
                null,
                'IT, гаджеты, программирование, обзоры, кино, футбол, урбанистика'
            );

            if (!empty($categories)) {
                $blogTheme .= '. Категории интересов: ' . implode(', ', $categories);
            }

            $ideas = $yandex->generateTopicIdeas($trends, $blogTheme);

            if (empty($ideas)) {
                return $this->failure('Failed to generate ideas. Check Yandex API configuration.');
            }

            // Limit to requested count
            $ideas = array_slice($ideas, 0, $count);

            $savedCount = 0;
            $results = [];

            foreach ($ideas as $i => $idea) {
                $title = is_array($idea) ? ($idea['title'] ?? $idea[0] ?? '') : (string)$idea;
                $description = is_array($idea) ? ($idea['description'] ?? '') : '';

                $item = [
                    'title'       => $title,
                    'description' => $description,
                    'source'      => 'ai',
                    'category'    => is_array($idea) ? ($idea['category'] ?? 'General') : 'General',
                    'score'       => 50.0,
                ];

                if ($save && !empty($title)) {
                    $this->saveIdea($item);
                    $savedCount++;
                }

                $results[] = $item;
            }

            return $this->success('Generated ' . count($results) . ' ideas, saved ' . $savedCount, [
                'ideas'   => $results,
                'total'   => count($results),
                'saved'   => $savedCount,
            ]);
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[SpookyApp:GenerateIdeas] ' . $e->getMessage());
            return $this->failure('Error: ' . $e->getMessage());
        }
    }

    /**
     * Get recent trending topic titles for context.
     */
    private function getRecentTrends(): array
    {
        $tableName = $this->modx->getOption('table_prefix') . 'spookyapp_topics';

        $sql = "SELECT `title` FROM `{$tableName}` ORDER BY `score` DESC, `created_at` DESC LIMIT 20";
        $stmt = $this->modx->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Save generated idea to spookyapp_topics.
     */
    private function saveIdea(array $item): void
    {
        $tableName = $this->modx->getOption('table_prefix') . 'spookyapp_topics';
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO `{$tableName}` (`source`, `title`, `url`, `description`, `category`, `published_at`, `score`, `metadata`, `cached_at`)
                VALUES (:source, :title, '', :description, :category, :published_at, :score, :metadata, :cached_at)";

        $stmt = $this->modx->prepare($sql);
        $stmt->execute([
            ':source'       => 'ai',
            ':title'        => $item['title'],
            ':description'  => $item['description'] ?? '',
            ':category'     => $item['category'] ?? 'General',
            ':published_at' => $now,
            ':score'        => $item['score'] ?? 50.0,
            ':metadata'     => json_encode(['generated_by' => 'yandex_gpt', 'generated_at' => $now]),
            ':cached_at'    => $now,
        ]);
    }
}

return GenerateIdeas::class;

