<?php

namespace DungTNA\GraphQLDocumentation\Controller\Ajax;

use DungTNA\GraphQLDocumentation\Block\GraphQLDocs;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class LoadTab implements HttpGetActionInterface
{
    private $resultJsonFactory;
    private $request;
    private $graphQLDocs;
    private $layout;

    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        GraphQLDocs $graphQLDocs,
        \Magento\Framework\View\LayoutInterface $layout
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->request = $request;
        $this->graphQLDocs = $graphQLDocs;
        $this->layout = $layout;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $tab = $this->request->getParam('tab');

        if (!$tab || !in_array($tab, ['mutations', 'types', 'input-types', 'enum-types'])) {
            return $result->setData(['success' => false, 'error' => 'Invalid tab']);
        }

        try {
            $schemaData = $this->graphQLDocs->getSchemaData();
            $blockClass = $this->getBlockClass($tab);

            $html = $this->layout->createBlock($blockClass)
                    ->setSchemaData($schemaData[$this->getDataKey($tab)] ?? [])
                    ->setBlock($this->graphQLDocs)
                    ->toHtml();

            return $result->setData(['success' => true, 'html' => $html]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function getBlockClass($tab)
    {
        $map = [
                'mutations' => \DungTNA\GraphQLDocumentation\Block\Tab\Mutations::class,
                'types' => \DungTNA\GraphQLDocumentation\Block\Tab\Types::class,
                'input-types' => \DungTNA\GraphQLDocumentation\Block\Tab\InputTypes::class,
                'enum-types' => \DungTNA\GraphQLDocumentation\Block\Tab\EnumTypes::class,
        ];
        return $map[$tab];
    }

    private function getDataKey($tab)
    {
        return str_replace('-', '_', $tab);
    }
}
