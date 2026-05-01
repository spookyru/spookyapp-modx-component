<?php

namespace SpookyApp\Processors\Item;

use MODX\Revolution\Processors\Model\GetProcessor;
use SpookyApp\Model\SpookyAppItem;

class Get extends GetProcessor
{
    public $objectType = 'SpookyAppItem';
    public $classKey = SpookyAppItem::class;
    public $languageTopics = ['spookyapp:default'];
    //public $permission = 'view';


    /**
     * We doing special check of permission
     * because of our objects is not an instances of modAccessibleObject
     *
     * @return mixed
     */
    public function process()
    {
        if (!$this->checkPermissions()) {
            return $this->failure($this->modx->lexicon('access_denied'));
        }

        return parent::process();
    }
}

return 'SpookyApp\Processors\Item\\Get';
