<?php

declare(strict_types=1);

use MODX\Revolution\modX;
use MODX\Revolution\modResource;
use MODX\Revolution\Processors\Processor;
use SpookyApp\Model\SpookyAppTopic;

/**
 * Сохранение одной темы как MODX-ресурса (черновик).
 *
 * POST connector.php?action=topicfinder/savetoresource
 *
 * Параметры:
 *   - topic_id    (int, required)  — ID темы из spookyapp_topics
 *   - parent_id   (int, optional)  — родительский ресурс (default: 0)
 *   - template_id (int, optional)  — ID шаблона (default: 0)
 *   - published   (bool, optional) — опубликовать (default: false)
 *
 * @package SpookyApp
 */
class SpookyAppTopicFinderSaveToResourceProcessor extends Processor
{
    public function initialize(): bool
    {
        $this->modx->addPackage(
            'SpookyApp\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/',
            null,
            'SpookyApp\\Model\\'
        );
        return parent::initialize();
    }

    public function process(): mixed
    {
        $topicId    = (int) $this->getProperty('topic_id', 0);
        $parentId   = (int) $this->getProperty('parent_id', 0);
        $templateId = (int) $this->getProperty('template_id', 0);
        $published  = (bool) $this->getProperty('published', false);

        if ($topicId <= 0) {
            return $this->failure('topic_id is required');
        }

        /** @var SpookyAppTopic|null $topic */
        $topic = $this->modx->getObject(SpookyAppTopic::class, $topicId);
        if (!$topic) {
            return $this->failure("Topic #{$topicId} not found");
        }

        // Проверяем родителя
        if ($parentId > 0) {
            $parent = $this->modx->getObject(modResource::class, $parentId);
            if (!$parent) {
                return $this->failure("Parent resource #{$parentId} not found");
            }
        }

        // Создаём ресурс
        $title   = $topic->get('title') ?: 'Untitled Topic';
        $content = $topic->get('description') ?: $topic->get('content') ?: '';

        /** @var modResource $resource */
        $resource = $this->modx->newObject(modResource::class);
        $resource->set('pagetitle', $title);
        $resource->set('content', $content);
        $resource->set('parent', $parentId);
        $resource->set('template', $templateId);
        $resource->set('published', $published ? 1 : 0);
        $resource->set('hidemenu', 0);
        $resource->set('isfolder', 0);
        $resource->set('richtext', 1);
        $resource->set('alias', $this->modx->filterPathSegment($title));

        if (!$resource->save()) {
            return $this->failure('Failed to save resource');
        }

        // Обновляем статус темы
        $topic->set('status', 'saved');
        $topic->set('resource_id', $resource->get('id'));
        $topic->save();

        $this->modx->log(modX::LOG_LEVEL_INFO,
            "[SpookyApp:SaveToResource] Topic #{$topicId} → Resource #{$resource->get('id')}"
        );

        return $this->success('Resource created', [
            'topic_id'    => $topicId,
            'resource_id' => $resource->get('id'),
            'pagetitle'   => $title,
        ]);
    }
}

return SpookyAppTopicFinderSaveToResourceProcessor::class;

