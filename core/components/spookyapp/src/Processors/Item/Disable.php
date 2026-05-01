<?php

namespace SpookyApp\Processors\Item;

use SpookyApp\Model\SpookyAppItem;
use MODX\Revolution\Processors\Processor;

class Disable extends Processor
{
    public $objectType = 'SpookyAppItem';
    public $classKey = SpookyAppItem::class;
    public $languageTopics = ['spookyapp'];
    //public $permission = 'save';


    /**
     * @return array|string
     */
    public function process()
    {
        if (!$this->checkPermissions()) {
            return $this->failure($this->modx->lexicon('access_denied'));
        }

        $ids = $this->modx->fromJSON($this->getProperty('ids'));
        if (empty($ids)) {
            return $this->failure($this->modx->lexicon('spookyapp_item_err_ns'));
        }

        foreach ($ids as $id) {
            /** @var SpookyAppItem $object */
            if (!$object = $this->modx->getObject($this->classKey, $id)) {
                return $this->failure($this->modx->lexicon('spookyapp_item_err_nf'));
            }

            $object->set('active', false);
            $object->save();
        }

        return $this->success();
    }
}

return 'SpookyApp\Processors\Item\\Disable';
