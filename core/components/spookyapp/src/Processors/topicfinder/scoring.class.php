<?php

declare(strict_types=1);

namespace SpookyApp\Processors\TopicFinder;

use MODX\Revolution\Processors\Processor;
use SpookyApp\Services\TopicScoringService;
use PDO;
use Throwable;

/**
 * Процессор пересчёта скоринга тем.
 *
 * Параметры:
 *   - ids (string): JSON-массив ID тем для пересчёта (пусто = все)
 *   - source (string): Фильтр по источнику (пусто = все)
 *   - prediction (bool): Показать прогноз трендов (Reddit rising) (default: false)
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class Scoring extends Processor
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
            $idsRaw    = $this->getProperty('ids', '');
            $source    = trim((string)$this->getProperty('source', ''));
            $prediction = (bool)$this->getProperty('prediction', false);

            $scoringService = new TopicScoringService($this->modx);

            $tableName = $this->modx->getOption('table_prefix') . 'spookyapp_topics';

            // Build query
            $where = [];
            $params = [];

            if (!empty($idsRaw)) {
                $ids = json_decode($idsRaw, true);
                if (is_array($ids) && !empty($ids)) {
                    $placeholders = [];
                    foreach ($ids as $i => $id) {
                        $key = ':id' . $i;
                        $placeholders[] = $key;
                        $params[$key] = (int)$id;
                    }
                    $where[] = 'id IN (' . implode(',', $placeholders) . ')';
                }
            }

            if (!empty($source)) {
                $where[] = '`source` = :source';
                $params[':source'] = $source;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $sql = "SELECT * FROM `{$tableName}` {$whereClause} ORDER BY `score` DESC LIMIT 200";
            $stmt = $this->modx->prepare($sql);
            $stmt->execute($params);
            $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $updated = 0;
            $results = [];

            foreach ($topics as $topic) {
                if (!empty($topic['metadata']) && is_string($topic['metadata'])) {
                    $topic['metadata'] = json_decode($topic['metadata'], true) ?: [];
                }

                $newScore = $scoringService->calculateScore($topic);
                $oldScore = (float)($topic['score'] ?? 0);

                // Update in DB if score changed
                if (abs($newScore - $oldScore) > 0.1) {
                    $updateSql = "UPDATE `{$tableName}` SET `score` = :score WHERE `id` = :id";
                    $updateStmt = $this->modx->prepare($updateSql);
                    $updateStmt->execute([':score' => $newScore, ':id' => $topic['id']]);
                    $updated++;
                }

                $results[] = [
                    'id'        => $topic['id'],
                    'title'     => $topic['title'] ?? '',
                    'source'    => $topic['source'] ?? '',
                    'old_score' => round($oldScore, 1),
                    'new_score' => round($newScore, 1),
                    'delta'     => round($newScore - $oldScore, 1),
                ];
            }

            // Sort by new_score DESC
            usort($results, fn($a, $b) => $b['new_score'] <=> $a['new_score']);

            return $this->success('Scoring recalculated: ' . $updated . ' topics updated', [
                'total'   => count($results),
                'updated' => $updated,
                'results' => $results,
            ]);
        } catch (Throwable $e) {
            $this->modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR,
                '[SpookyApp:Scoring] ' . $e->getMessage());
            return $this->failure('Scoring error: ' . $e->getMessage());
        }
    }
}

return Scoring::class;

