<?php
// app/code/DungTNA/GraphQLDocumentation/Block/Tab/ResponseAndCode.php

namespace DungTNA\GraphQLDocumentation\Block\Tab;

use DungTNA\GraphQLDocumentation\Block\GraphQLDocs;
use Magento\Framework\View\Element\Template;

class ResponseAndCode extends Template
{
    protected $_template = 'DungTNA_GraphQLDocumentation::tab/response_and_code.phtml';

    private $name;
    private $data;
    private $type;
    private $block;

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    public function getBlock()
    {
        return $this->block;
    }

    public function setBlock(GraphQLDocs $block)
    {
        $this->block = $block;
        return $this;
    }
}
