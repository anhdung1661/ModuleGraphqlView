<?php

namespace DungTNA\GraphQLDocumentation\Block\Tab;

use DungTNA\GraphQLDocumentation\Block\GraphQLDocs;
use Magento\Framework\View\Element\Template;

class Types extends Template
{
    protected $_template = 'DungTNA_GraphQLDocumentation::tab/types.phtml';
    private $schemaData = [];
    private $block;

    public function setSchemaData($data)
    {
        $this->schemaData = $data;
        return $this;
    }

    public function setBlock(GraphQLDocs $block)
    {
        $this->block = $block;
        return $this;
    }

    public function getItems()
    {
        return $this->schemaData;
    }

    public function getBlockHelper()
    {
        return $this->block;
    }
}
