<?php

namespace SpookyApp\Processors\Item;

use SpookyApp\Model\SpookyAppItem;
use MODX\Revolution\Processors\Processor;

class Remove extends Processor
{
    public $objectType = 'SpookyAppItem';
    public $classKey = SpookyAppItem::class;
    public $languageTopics = ['spookyapp'];
    //public $permission = 'remove';


    /**
     * @return array|string
     */
    public function process()
    {
        if (!$this->checkPermissions()) {
            return $this->failure($this->modx->lexicon('access_denied'));
        }

        $ids = json_decode($this->getProperty('ids'), true);
        if (empty($ids)) {
            return $this->failure($this->modx->lexicon('spookyapp_item_err_ns'));
        }

        foreach ($ids as $id) {
            /** @var SpookyAppItem $object */
            if (!$object = $this->modx->getObject($this->classKey, $id)) {
                return $this->failure($this->modx->lexicon('spookyapp_item_err_nf'));
            }

            $object->remove();
        }

        return $this->success();
    }
}

return 'SpookyApp\Processors\Item\\Remove';
