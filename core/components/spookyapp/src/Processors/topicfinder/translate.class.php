<?php

declare(strict_types=1);

use MODX\Revolution\modX;
use MODX\Revolution\Processors\Processor;
use SpookyApp\Model\SpookyAppTopic;
use SpookyApp\Services\API\YandexAPIService;
use SpookyApp\Services\Cache\CacheService;

/**
 * Процессор перевода темы (title + description) через Яндекс Переводчик.
 *
 * POST connector.php?action=topicfinder/translate
 *
 * Параметры:
 *   - id          (int, required)  — ID темы из spookyapp_topics
 *   - source_lang (string, optional) — язык источника (default: 'en')
 *   - target_lang (string, optional) — целевой язык (default: 'ru')
 *
 * @package SpookyApp
 */
class SpookyAppTopicFinderTranslateProcessor extends Processor
{
    public function initialize(): bool|string
    {
        $autoload = $this->modx->getOption('core_path') . 'components/spookyapp/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $this->modx->addPackage(
            'SpookyApp\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/',
            null,
            'SpookyApp\\Model\\'
        );

        $id = (int) $this->getProperty('id', 0);
        if ($id <= 0) {
            return 'Parameter "id" is required';
        }

        return parent::initialize();
    }

    public function process(): mixed
    {
        $id         = (int)   $this->getProperty('id', 0);
        $sourceLang = (string) $this->getProperty('source_lang', 'en');
        $targetLang = (string) $this->getProperty('target_lang', 'ru');

        try {
            /** @var SpookyAppTopic|null $topic */
            $topic = $this->modx->getObject(SpookyAppTopic::class, $id);
            if (!$topic) {
                return $this->failure("Topic #{$id} not found");
            }

            $title       = trim((string) $topic->get('title'));
            $description = trim((string) $topic->get('description'));

            // Collect non-empty texts for batch translate
            $texts = array_filter([$title, $description], fn(string $t) => $t !== '');

            if (empty($texts)) {
                return $this->failure('Topic has no title or description to translate');
            }

            $cache   = new CacheService($this->modx);
            $yandex  = new YandexAPIService($this->modx, $cache);

            $translated = $yandex->translate(array_values($texts), $sourceLang, $targetLang);

            if (!is_array($translated)) {
                return $this->failure('Translation service returned unexpected result');
            }

            // Map translated values back
            $idx = 0;
            $newTitle       = $title;
            $newDescription = $description;

            if ($title !== '') {
                $newTitle = $translated[$idx] ?? $title;
                $idx++;
            }
            if ($description !== '') {
                $newDescription = $translated[$idx] ?? $description;
            }

            // Persist to DB
            $topic->set('title', $newTitle);
            $topic->set('description', $newDescription);
            $topic->save();

            $this->modx->log(modX::LOG_LEVEL_INFO,
                "[SpookyApp:Translate] Topic #{$id} translated {$sourceLang}→{$targetLang}"
            );

            return $this->success('Translated', [
                'id'          => $id,
                'title'       => $newTitle,
                'description' => $newDescription,
            ]);

        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[SpookyApp:Translate] ' . $e->getMessage()
            );
            return $this->failure('Translation error: ' . $e->getMessage());
        }
    }
}

return SpookyAppTopicFinderTranslateProcessor::class;
