<?php

namespace SpookyApp\Processors\Item;

use MODX\Revolution\Processors\Model\CreateProcessor;
use SpookyApp\Model\SpookyAppItem;

class Create extends CreateProcessor
{
    public $objectType = 'SpookyAppItem';
    public $classKey = SpookyAppItem::class;
    public $languageTopics = ['spookyapp'];
    //public $permission = 'create';


    /**
     * @return bool
     */
    public function beforeSet()
    {
        $name = trim($this->getProperty('name'));
        if (empty($name)) {
            $this->modx->error->addField('name', $this->modx->lexicon('spookyapp_item_err_name'));
        } elseif ($this->modx->getCount($this->classKey, ['name' => $name])) {
            $this->modx->error->addField('name', $this->modx->lexicon('spookyapp_item_err_ae'));
        }

        return parent::beforeSet();
    }
}

return 'SpookyApp\Processors\Item\\Create';
