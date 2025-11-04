<?php

namespace DungTNA\GraphQLDocumentation\Block;

use Exception;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class GraphQLDocs extends Template
{
    protected $curl;
    protected $jsonHelper;
    protected $storeManager;
    protected $schemaData;

    public function __construct(
        Context $context,
        Curl $curl,
        JsonHelper $jsonHelper,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->curl = $curl;
        $this->jsonHelper = $jsonHelper;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    public function getGraphQLEndpointUrl()
    {
        return $this->getBaseUrl() . 'graphql';
    }

    public function getBaseUrl()
    {
        return $this->storeManager->getStore()->getBaseUrl();
    }

    public function hasSchemaErrors()
    {
        $data = $this->getSchemaData();
        return isset($data['error']);
    }

    public function getSchemaData()
    {
        if ($this->schemaData === null) {
            $this->schemaData = $this->fetchSchemaViaIntrospection();
        }
        return $this->schemaData;
    }

    private function fetchSchemaViaIntrospection()
    {
        try {
            $introspectionQuery = $this->getCompleteIntrospectionQuery();
            $response = $this->executeGraphQLQuery($introspectionQuery);
            return $this->processGraphQLResponse($response);
        } catch (Exception $e) {
            return $this->createErrorResponse('Failed to load GraphQL schema: ' . $e->getMessage());
        }
    }

    private function getCompleteIntrospectionQuery()
    {
        return '{__schema{queryType{name}mutationType{name}types{kind name description fields(includeDeprecated:true){name description args{name description type{kind name ofType{kind name ofType{kind name}}}defaultValue}type{kind name ofType{kind name ofType{kind name}}}isDeprecated deprecationReason}inputFields{name description type{kind name ofType{kind name ofType{kind name}}}defaultValue}enumValues(includeDeprecated:true){name description isDeprecated deprecationReason}}}}';
    }

    private function executeGraphQLQuery($query)
    {
        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . 'graphql';
        $payload = json_encode(['query' => $query]);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON encode error: ' . json_last_error_msg());
        }
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nUser-Agent: Magento-GraphQL-Docs/1.0\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 30
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new Exception('HTTP request failed: ' . ($error['message'] ?? 'Unknown error'));
        }
        $httpCode = $this->getHttpStatusCode($http_response_header ?? []);
        if ($httpCode >= 400) {
            throw new Exception('HTTP ' . $httpCode . ' - Server returned error response');
        }
        return $response;
    }

    private function getHttpStatusCode($headers)
    {
        if (isset($headers[0]) && preg_match('/HTTP\/[0-9\.]+\s+([0-9]+)/', $headers[0], $matches)) {
            return (int)$matches[1];
        }
        return 200;
    }

    private function processGraphQLResponse($response)
    {
        if (is_string($response)) {
            if (strpos($response, '<!DOCTYPE html') !== false || strpos($response, '<html') !== false) {
                return $this->createErrorResponse('GraphQL endpoint returned HTML page instead of JSON. Please check if GraphQL module is enabled and URL is correct.');
            }
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $response = $decoded;
            } else {
                return $this->createErrorResponse('Invalid JSON response: ' . json_last_error_msg() . '. Response: ' . substr(
                    $response,
                    0,
                    200
                ));
            }
        }
        if (isset($response['errors'])) {
            $errorMessages = [];
            foreach ($response['errors'] as $error) {
                $errorMessages[] = $error['message'] ?? 'Unknown error';
            }
            return $this->createErrorResponse('GraphQL Errors: ' . implode(', ', $errorMessages));
        }
        if (isset($response['data']['__schema'])) {
            return $this->processCompleteIntrospectionResponse($response['data']['__schema']);
        }
        return $this->createErrorResponse('No GraphQL schema data found in response. Please check if GraphQL is properly configured.');
    }

    private function createErrorResponse($message)
    {
        return [
            'queries' => [],
            'mutations' => [],
            'types' => [],
            'input_types' => [],
            'enum_types' => [],
            'error' => $message,
            'total_queries' => 0,
            'total_mutations' => 0,
            'total_types' => 0,
            'total_input_types' => 0,
            'total_enum_types' => 0
        ];
    }

    private function processCompleteIntrospectionResponse($schema)
    {
        $queries = [];
        $mutations = [];
        $types = [];
        $inputTypes = [];
        $enumTypes = [];
        if (!isset($schema['types']) || !is_array($schema['types'])) {
            return $this->createErrorResponse('No types found in GraphQL schema');
        }
        foreach ($schema['types'] as $type) {
            if (!isset($type['kind'])) {
                continue;
            }
            $typeName = $type['name'] ?? '';
            switch ($type['kind']) {
                case 'OBJECT':
                    if ($typeName === 'Query') {
                        $queries = $this->processFields($type['fields'] ?? []);
                    } elseif ($typeName === 'Mutation') {
                        $mutations = $this->processFields($type['fields'] ?? []);
                    } elseif (!$this->isInternalType($typeName)) {
                        $types[$typeName] = $this->processFields($type['fields'] ?? []);
                    }
                    break;
                case 'INPUT_OBJECT':
                    if (!$this->isInternalType($typeName)) {
                        $inputTypes[$typeName] = $this->processInputFields($type['inputFields'] ?? []);
                    }
                    break;
                case 'ENUM':
                    if (!$this->isInternalType($typeName)) {
                        $enumTypes[$typeName] = $this->processEnumValues($type['enumValues'] ?? []);
                    }
                    break;
                case 'SCALAR':
                    if (!$this->isBuiltInScalar($typeName)) {
                        $types[$typeName] = [
                            'kind' => 'SCALAR',
                            'description' => $type['description'] ?? 'Custom scalar type'
                        ];
                    }
                    break;
            }
        }
        return [
            'queries' => $queries,
            'mutations' => $mutations,
            'types' => $types,
            'input_types' => $inputTypes,
            'enum_types' => $enumTypes,
            'total_queries' => count($queries),
            'total_mutations' => count($mutations),
            'total_types' => count($types),
            'total_input_types' => count($inputTypes),
            'total_enum_types' => count($enumTypes),
            'error' => null
        ];
    }

    private function processFields($fields)
    {
        $result = [];
        if (!is_array($fields)) {
            return $result;
        }
        foreach ($fields as $field) {
            if (!isset($field['name'])) {
                continue;
            }
            $fieldName = $field['name'];
            $description = $field['description'] ?? 'No description available';
            if ($description === 'No description available') {
                $description = $this->getCustomResolverDescription($fieldName);
            }
            $result[$fieldName] = [
                'name' => $fieldName,
                'description' => $description,
                'type' => $this->resolveType($field['type'] ?? []),
                'args' => $this->processArgs($field['args'] ?? []),
                'is_deprecated' => $field['isDeprecated'] ?? false,
                'deprecation_reason' => $field['deprecationReason'] ?? null,
                'is_custom_resolver' => $this->isCustomResolver($fieldName, $description)
            ];
        }
        return $result;
    }

    private function getCustomResolverDescription($fieldName)
    {
        $descriptions = [
            'adminChangeEnableCategory' => 'Enable or disable a category (Admin functionality)',
            'changeEnableCategory' => 'Enable or disable a category',
            'updateCategory' => 'Update category information',
            'createCategory' => 'Create a new category',
            'deleteCategory' => 'Delete a category',
            'adminUpdateProduct' => 'Update product (Admin functionality)',
            'createCustomer' => 'Create a new customer account',
            'updateCustomer' => 'Update customer information',
            'deleteCustomer' => 'Delete customer account',
            'createCart' => 'Create a new shopping cart',
            'updateCart' => 'Update shopping cart items',
            'placeOrder' => 'Place an order',
            'applyCoupon' => 'Apply coupon code to cart',
            'removeCoupon' => 'Remove coupon from cart'
        ];
        return $descriptions[$fieldName] ?? $this->generateDescriptionFromName($fieldName);
    }

    private function generateDescriptionFromName($fieldName)
    {
        $words = preg_split('/(?=[A-Z])/', $fieldName);
        $words = array_filter($words);
        $processedWords = [];
        foreach ($words as $word) {
            $lowerWord = strtolower($word);
            switch ($lowerWord) {
                case 'admin':
                    $processedWords[] = '(Admin)';
                    break;
                case 'change':
                case 'update':
                    $processedWords[] = 'Update';
                    break;
                case 'create':
                    $processedWords[] = 'Create';
                    break;
                case 'delete':
                    $processedWords[] = 'Delete';
                    break;
                case 'enable':
                    $processedWords[] = 'enable';
                    break;
                case 'disable':
                    $processedWords[] = 'disable';
                    break;
                default:
                    $processedWords[] = ucfirst($lowerWord);
            }
        }
        $description = implode(' ', $processedWords);
        if (stripos($fieldName, 'category') !== false) {
            $description .= ' for categories';
        } elseif (stripos($fieldName, 'product') !== false) {
            $description .= ' for products';
        } elseif (stripos($fieldName, 'customer') !== false) {
            $description .= ' for customers';
        } elseif (stripos($fieldName, 'cart') !== false) {
            $description .= ' for shopping cart';
        } elseif (stripos($fieldName, 'order') !== false) {
            $description .= ' for orders';
        }
        return $description;
    }

    private function resolveType($typeInfo)
    {
        $type = $typeInfo;
        $nullable = true;
        $isList = false;
        while (isset($type['ofType'])) {
            if ($type['kind'] === 'NON_NULL') {
                $nullable = false;
            } elseif ($type['kind'] === 'LIST') {
                $isList = true;
            }
            $type = $type['ofType'];
        }
        $typeName = $type['name'] ?? 'Unknown';
        if ($isList) {
            $typeName = '[' . $typeName . ']';
        }
        if (!$nullable) {
            $typeName .= '!';
        }
        return $typeName;
    }

    private function processArgs($args)
    {
        $result = [];
        if (!is_array($args)) {
            return $result;
        }
        foreach ($args as $arg) {
            if (!isset($arg['name'])) {
                continue;
            }
            $argName = $arg['name'];
            $description = $arg['description'] ?? 'No description';
            if ($description === 'No description') {
                $description = $this->getArgumentDescription($argName);
            }
            $result[$argName] = [
                'name' => $argName,
                'description' => $description,
                'type' => $this->resolveType($arg['type']),
                'default_value' => $arg['defaultValue'],
                'is_required' => $this->isTypeRequired($arg['type'])
            ];
        }
        return $result;
    }

    private function getArgumentDescription($argName)
    {
        $descriptions = [
            'input' => 'Input data for the operation',
            'id' => 'Unique identifier',
            'sku' => 'Product SKU',
            'email' => 'Email address',
            'filter' => 'Filter criteria',
            'sort' => 'Sort order',
            'pageSize' => 'Number of items per page',
            'currentPage' => 'Current page number',
            'search' => 'Search term',
            'categoryId' => 'Category ID',
            'productId' => 'Product ID',
            'customerId' => 'Customer ID',
            'cartId' => 'Shopping cart ID',
            'orderId' => 'Order ID'
        ];
        return $descriptions[$argName] ?? ucfirst(str_replace('_', ' ', $argName));
    }

    private function isTypeRequired($typeInfo)
    {
        $type = $typeInfo;
        while (isset($type['ofType'])) {
            if ($type['kind'] === 'NON_NULL') {
                return true;
            }
            $type = $type['ofType'];
        }
        return false;
    }

    private function isCustomResolver($fieldName, $description)
    {
        $customKeywords = ['change', 'update', 'create', 'delete', 'admin', 'custom'];
        foreach ($customKeywords as $keyword) {
            if (stripos($fieldName, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isInternalType($typeName)
    {
        $internalTypes = [
            '__Schema',
            '__Type',
            '__TypeKind',
            '__Field',
            '__InputValue',
            '__EnumValue',
            '__Directive',
            '__DirectiveLocation',
            'String',
            'Int',
            'Float',
            'Boolean',
            'ID',
            'Query',
            'Mutation'
        ];
        return in_array($typeName, $internalTypes) || strpos($typeName, '__') === 0;
    }

    private function processInputFields($inputFields)
    {
        $result = [];
        if (!is_array($inputFields)) {
            return $result;
        }
        foreach ($inputFields as $field) {
            if (!isset($field['name'])) {
                continue;
            }
            $result[$field['name']] = [
                'name' => $field['name'],
                'description' => $field['description'] ?? 'No description available',
                'type' => $this->resolveType($field['type'] ?? []),
                'default_value' => $field['defaultValue'],
                'is_required' => $this->isTypeRequired($field['type'] ?? [])
            ];
        }
        return $result;
    }

    private function processEnumValues($enumValues)
    {
        $result = [];
        if (!is_array($enumValues)) {
            return $result;
        }
        foreach ($enumValues as $value) {
            if (!isset($value['name'])) {
                continue;
            }
            $result[$value['name']] = [
                'name' => $value['name'],
                'description' => $value['description'] ?? 'No description available',
                'is_deprecated' => $value['isDeprecated'] ?? false,
                'deprecation_reason' => $value['deprecationReason'] ?? null
            ];
        }
        return $result;
    }

    private function isBuiltInScalar($typeName)
    {
        $builtInScalars = ['String', 'Int', 'Float', 'Boolean', 'ID'];
        return in_array($typeName, $builtInScalars);
    }

    public function getSchemaError()
    {
        $data = $this->getSchemaData();
        return $data['error'] ?? null;
    }

    public function getInputTypeStructure($typeName)
    {
        $schemaData = $this->getSchemaData();
        return $schemaData['input_types'][$typeName] ?? null;
    }

    public function getEnumTypeStructure($typeName)
    {
        $schemaData = $this->getSchemaData();
        return $schemaData['enum_types'][$typeName] ?? null;
    }

    public function isInputType($typeName)
    {
        $schemaData = $this->getSchemaData();
        return isset($schemaData['input_types'][$typeName]);
    }

    public function isEnumType($typeName)
    {
        $schemaData = $this->getSchemaData();
        return isset($schemaData['enum_types'][$typeName]);
    }

    public function generateCurlCommand($queryName, $queryType, $query = [])
    {
        $baseUrl = $this->getBaseUrlWithoutSSL();
        $graphqlUrl = $baseUrl . 'graphql';
        $args = $query['args'];
        $graphqlQuery = $this->generateSampleGraphQLQuery($queryName, $queryType, $args);
        $variables = $this->generateVariablesExample($queryName, $queryType, $args);
        $payload = ['query' => $graphqlQuery];
        if ($variables) {
            $payload['variables'] = json_decode($variables, true);
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // Xây dựng curl command với headers
        $curlCommand = "curl -X POST \\\n  '" . $graphqlUrl . "' \\\n  -H 'Content-Type: application/json' \\\n  -H 'Accept: application/json'";

        // Thêm headers dựa trên queryName
        if (stripos($queryName, 'admin') !== false) {
            $curlCommand .= " \\\n  -H 'Authorization: YOUR_ADMIN_TOKEN_HERE'";
        } elseif (stripos($queryName, 'cashier') !== false || stripos(
            $queryName,
            'pos'
        ) !== false || stripos($queryName, 'cashierapp') !== false) {
            $curlCommand .= " \\\n  -H 'pos-token: YOUR_POS_TOKEN_HERE'";
        } elseif (stripos($queryName, 'portal') !== false) {
            $curlCommand .= " \\\n  -H 'principal-token: YOUR_PRINCIPAL_TOKEN_HERE'";
        }

        $curlCommand .= " \\\n  -d '" . $payloadJson . "'";

        return $curlCommand;
    }

    private function getBaseUrlWithoutSSL()
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        if (strpos($baseUrl, 'https') === 0) {
            $baseUrl = str_replace('https', 'http', $baseUrl);
        }
        return $baseUrl;
    }

    public function generateSampleGraphQLQuery($queryName, $queryType, $args = [])
    {
        $queryArgs = '';
        $variables = [];
        $returnType = $this->getReturnType($queryName, $queryType);
        $queryFields = $this->getSampleFields($queryName, $returnType);

        if (!empty($args)) {
            $argStrings = [];
            foreach ($args as $argName => $arg) {
                $exampleValue = $this->getExampleValue($arg['type']);
                $argStrings[] = $argName . ': $' . $argName;
                $variables[$argName] = [
                    'value' => $exampleValue,
                    'type' => $arg['type'] . ($arg['is_required'] == true ? '!' : '')
                ];
            }
            $queryArgs = '(' . implode(', ', $argStrings) . ')';
        }

        if ($queryType === 'query') {
            return $this->buildCompleteQuery($queryName, $queryArgs, $queryFields, $variables, $returnType);
        } else {
            return $this->buildCompleteMutation($queryName, $queryArgs, $queryFields, $variables, $returnType);
        }
    }

    private function getReturnType($operationName, $operationType)
    {
        $schemaData = $this->getSchemaData();

        if ($operationType === 'query') {
            $queries = $schemaData['queries'] ?? [];
            if (isset($queries[$operationName]['type'])) {
                return $queries[$operationName]['type'];
            }
        } elseif ($operationType === 'mutation') {
            $mutations = $schemaData['mutations'] ?? [];
            if (isset($mutations[$operationName]['type'])) {
                return $mutations[$operationName]['type'];
            }
        } elseif ($operationType === 'types') {
            return $operationName;
        }

        return null;
    }

    private function getSampleFields($queryName, $returnType = null)
    {
        // Nếu có return type, sử dụng schema data để tạo fields
        if ($returnType && $this->isKnownType($returnType)) {
            return $this->generateFieldsFromType($returnType);
        }

        // Fallback cho các query phổ biến
        $fieldMap = [
            'products' => "items {\n      id\n      name\n      sku\n      price {\n        regularPrice {\n          amount {\n            value\n            currency\n          }\n        }\n      }\n    }\n    total_count\n    page_info {\n      total_pages\n      current_page\n    }",
            'product' => "id\n    name\n    sku\n    description {\n      html\n    }\n    price {\n      regularPrice {\n        amount {\n          value\n          currency\n        }\n      }\n    }\n    media_gallery {\n      url\n      label\n    }",
            'categories' => "items {\n      id\n      name\n      url_key\n      image\n      description\n      children {\n        id\n        name\n      }\n    }\n    total_count",
            'category' => "id\n    name\n    description\n    image\n    url_key\n    products {\n      items {\n        id\n        name\n        sku\n        price {\n          regularPrice {\n            amount {\n              value\n              currency\n            }\n          }\n        }\n      }\n    }",
            'customers' => "items {\n      id\n      firstname\n      lastname\n      email\n      created_at\n    }\n    total_count",
            'customer' => "id\n    firstname\n    lastname\n    email\n    created_at\n    addresses {\n      id\n      firstname\n      lastname\n      street\n      city\n      telephone\n      postcode\n      country_code\n    }",
            'cart' => "id\n    items {\n      id\n      product {\n        name\n        sku\n        price {\n          regularPrice {\n            amount {\n              value\n              currency\n            }\n          }\n        }\n      }\n      quantity\n      prices {\n        price {\n          value\n          currency\n        }\n        row_total {\n          value\n          currency\n        }\n      }\n    }\n    total_quantity\n    prices {\n      grand_total {\n        value\n        currency\n      }\n      subtotal_excluding_tax {\n        value\n        currency\n      }\n    }",
            'cmsPage' => "id\n    title\n    content\n    content_heading\n    meta_title\n    meta_description\n    url_key",
            'cmsBlocks' => "items {\n      id\n      title\n      content\n      identifier\n    }",
            'storeConfig' => "id\n    base_currency_code\n    default_display_currency_code\n    timezone\n    weight_unit\n    base_media_url\n    secure_base_media_url"
        ];

        return $fieldMap[strtolower($queryName)] ?? $this->generateDefaultFields($returnType);
    }

    private function isKnownType($typeName)
    {
        $schemaData = $this->getSchemaData();
        $cleanType = str_replace(['!', '[', ']'], '', $typeName);
        return isset($schemaData['types'][$cleanType]) ||
            isset($schemaData['input_types'][$cleanType]) ||
            isset($schemaData['enum_types'][$cleanType]);
    }

    private function generateFieldsFromType($typeName)
    {
        $schemaData = $this->getSchemaData();
        $cleanType = str_replace(['!', '[', ']'], '', $typeName);

        if (isset($schemaData['types'][$cleanType])) {
            return $this->buildFieldsRecursive($schemaData['types'][$cleanType], 1);
        }

        return $this->generateDefaultFields($typeName);
    }

    private function buildFieldsRecursive($typeFields, $depth = 1, $maxDepth = 5) // Tăng từ 3 lên 5
    {
        if ($depth > $maxDepth) {
            return '';
        }

        $indent = str_repeat('  ', $depth);
        $fields = [];

        foreach ($typeFields as $fieldName => $field) {
            $fieldType = $field['type'] ?? '';
            $fieldDescription = $field['description'] ?? '';

            // Giảm bớt hạn chế ở depth sâu
            if ($depth >= $maxDepth && $this->isComplexType($fieldType)) {
                // Thay vì bỏ qua, có thể trả về field đơn giản
                $fields[] = $indent . $fieldName;
                continue;
            }

            $fieldLine = $fieldName;

            // Nếu là object type và chưa đạt max depth, đệ quy
            if ($this->isObjectType($fieldType) && $depth < $maxDepth) {
                $nestedType = $this->getCleanTypeName($fieldType);
                $nestedFields = $this->getNestedFieldsForType($nestedType, $depth + 1, $maxDepth);
                if (!empty($nestedFields)) {
                    $fieldLine .= " {\n" . $nestedFields . "\n" . $indent . "}";
                }
            }

            $fields[] = $indent . $fieldLine;
        }

        return implode("\n", $fields);
    }

    private function isComplexType($typeString)
    {
        $complexTypes = ['ProductInterface', 'CategoryTree', 'Customer', 'Cart', 'Order'];
        $cleanType = $this->getCleanTypeName($typeString);

        return in_array($cleanType, $complexTypes);
    }

    private function getCleanTypeName($typeString)
    {
        return str_replace(['!', '[', ']'], '', $typeString);
    }

    private function isObjectType($typeString)
    {
        $cleanType = $this->getCleanTypeName($typeString);
        $schemaData = $this->getSchemaData();

        return isset($schemaData['types'][$cleanType]) &&
            !$this->isScalarType($cleanType);
    }

    private function isScalarType($typeName)
    {
        $scalarTypes = ['String', 'Int', 'Float', 'Boolean', 'ID'];
        return in_array($typeName, $scalarTypes);
    }

    private function getNestedFieldsForType($typeName, $depth)
    {
        $schemaData = $this->getSchemaData();

        if (isset($schemaData['types'][$typeName])) {
            return $this->buildFieldsRecursive($schemaData['types'][$typeName], $depth);
        }

        // Fallback cho các type phổ biến
        $commonTypeFields = [
            'ProductInterface' => "id\n    name\n    sku\n    price {\n      regularPrice {\n        amount {\n          value\n          currency\n        }\n      }\n    }",
            'CategoryTree' => "id\n    name\n    url_key\n    description",
            'Customer' => "id\n    firstname\n    lastname\n    email",
            'Cart' => "id\n    total_quantity\n    prices {\n      grand_total {\n        value\n        currency\n      }\n    }",
            'Money' => "value\n    currency",
            'PriceRange' => "minimum_price {\n      regular_price {\n        value\n        currency\n      }\n    }\n    maximum_price {\n      regular_price {\n        value\n        currency\n      }\n    }"
        ];

        return $commonTypeFields[$typeName] ?? "id\n    __typename";
    }

    private function generateDefaultFields($typeName)
    {
        $defaultFields = [
            'id',
            '__typename'
        ];

        // Thêm các field phổ biến dựa trên type name
        if (stripos($typeName, 'product') !== false) {
            $defaultFields = array_merge(
                $defaultFields,
                ['name', 'sku', 'price { regularPrice { amount { value currency } } }']
            );
        } elseif (stripos($typeName, 'category') !== false) {
            $defaultFields = array_merge($defaultFields, ['name', 'url_key', 'description']);
        } elseif (stripos($typeName, 'customer') !== false) {
            $defaultFields = array_merge($defaultFields, ['firstname', 'lastname', 'email']);
        } elseif (stripos($typeName, 'cart') !== false) {
            $defaultFields = array_merge(
                $defaultFields,
                ['total_quantity', 'prices { grand_total { value currency } }']
            );
        }

        return implode("\n    ", $defaultFields);
    }

    public function getExampleValue($typeString)
    {
        if (strpos($typeString, 'String') !== false && strpos($typeString, '[') !== false) {
            return "['item1', 'item2']";
        }
        if (strpos($typeString, 'Int') !== false && strpos($typeString, '[') !== false) {
            return "[987, 123]";
        }
        if (strpos($typeString, 'String') !== false || strpos($typeString, 'ID') !== false) {
            return '"example_value"';
        }
        if (strpos($typeString, 'Int') !== false) {
            return '123';
        }
        if (strpos($typeString, 'Float') !== false) {
            return '123.45';
        }
        if (strpos($typeString, 'Boolean') !== false) {
            return 'true';
        }
        return $typeString;
    }

    private function buildCompleteQuery($queryName, $queryArgs, $queryFields, $variables, $returnType = null)
    {
        $fragments = $this->getFragmentsForQuery($queryName, $returnType);
        $query = "query " . ucfirst($queryName) . "Query";

        if (!empty($variables)) {
            $query .= "(\n" . $this->buildVariables($variables) . "\n)";
        }

        $query .= " {\n  " . $queryName . $queryArgs . " {\n    " . $queryFields . "\n  }\n}";

        if (!empty($fragments)) {
            $query .= "\n\n" . $fragments;
        }

        return $query;
    }

    private function getFragmentsForQuery($queryName, $returnType = null)
    {
        // Sử dụng return type để tạo fragment phù hợp
        if ($returnType) {
            $cleanType = $this->getCleanTypeName($returnType);
            if ($this->shouldCreateFragment($cleanType)) {
                return $this->generateFragmentForType($cleanType);
            }
        }

        // Fallback cho các query cụ thể
        $fragmentMap = [
            'products' => $this->generateFragmentForType('Products'),
            'product' => $this->generateFragmentForType('ProductInterface'),
            'categories' => $this->generateFragmentForType('CategoryResult'),
            'category' => $this->generateFragmentForType('CategoryTree'),
            'customer' => $this->generateFragmentForType('Customer'),
            'cart' => $this->generateFragmentForType('Cart')
        ];

        return $fragmentMap[strtolower($queryName)] ?? '';
    }

    private function shouldCreateFragment($typeName)
    {
        $complexTypes = ['ProductInterface', 'CategoryTree', 'Customer', 'Cart', 'Order'];
        return in_array($typeName, $complexTypes);
    }

    private function generateFragmentForType($typeName)
    {
        $schemaData = $this->getSchemaData();

        if (isset($schemaData['types'][$typeName])) {
            $fields = $this->buildFieldsRecursive($schemaData['types'][$typeName], 2, 2);
            if (!empty($fields)) {
                return "fragment " . $typeName . "Fragment on " . $typeName . " {\n" . $fields . "\n}";
            }
        }

        return $this->getDefaultFragment($typeName);
    }

    private function getDefaultFragment($operationName)
    {
        if (stripos($operationName, 'cart') !== false) {
            return "fragment CartFragment on Cart {\n  id\n  items {\n    id\n    product {\n      name\n      sku\n    }\n    quantity\n  }\n  total_quantity\n}";
        } elseif (stripos($operationName, 'customer') !== false) {
            return "fragment CustomerFragment on Customer {\n  id\n  firstname\n  lastname\n  email\n}";
        } elseif (stripos($operationName, 'product') !== false) {
            return "fragment ProductFragment on ProductInterface {\n  id\n  name\n  sku\n  price {\n    regularPrice {\n      amount {\n        value\n        currency\n      }\n    }\n  }\n}";
        }
        return "";
    }

    private function buildVariables($variables)
    {
        $variableStrings = [];
        foreach ($variables as $varName => $varArgs) {
            $type = $this->getVariableType($varName, $varArgs);
            $variableStrings[] = "  $" . $varName . ": " . $type;
        }
        return implode("\n", $variableStrings);
    }

    private function getVariableType($varName, $varArgs)
    {
        $exampleValue = is_array($varArgs['value']) ? implode(',', $varArgs['value']) : $varArgs['value'];
        $typeMap = [
            'input' => $exampleValue,
            'cartItems' => "[$exampleValue]",
            'filter' => $exampleValue,
            'sort' => $exampleValue,
        ];
        return $typeMap[$varName] ?? $varArgs['type'];
    }

    private function buildCompleteMutation(
        $mutationName,
        $mutationArgs,
        $mutationFields,
        $variables,
        $returnType = null
    ) {
        $fragments = $this->getFragmentsForMutation($mutationName, $returnType);
        $mutation = "mutation " . ucfirst($mutationName) . "Mutation";

        if (!empty($variables)) {
            $mutation .= "(\n" . $this->buildVariables($variables) . "\n)";
        }

        $mutation .= " {\n  " . $mutationName . $mutationArgs . " {\n    " . $mutationFields . "\n  }\n}";

        if (!empty($fragments)) {
            $mutation .= "\n\n" . $fragments;
        }

        return $mutation;
    }

    private function getFragmentsForMutation($mutationName)
    {
        $fragmentMap = [
            'addBundleProductsToCart' => "fragment CartFragment on Cart {\n  id\n  items {\n    id\n    product {\n      id\n      name\n      sku\n    }\n    quantity\n    ... on BundleCartItem {\n      bundle_options {\n        id\n        label\n        values {\n          id\n          label\n          price\n          quantity\n        }\n      }\n    }\n  }\n  prices {\n    grand_total {\n      value\n      currency\n    }\n  }\n}",
            'addSimpleProductsToCart' => "fragment CartFragment on Cart {\n  id\n  items {\n    id\n    product {\n      id\n      name\n      sku\n    }\n    quantity\n    prices {\n      price {\n        value\n        currency\n      }\n    }\n  }\n}",
            'createEmptyCart' => "fragment CartFragment on Cart {\n  id\n  total_quantity\n}",
            'createCustomer' => "fragment CustomerOutputFragment on CustomerOutput {\n  customer {\n    id\n    firstname\n    lastname\n    email\n  }\n}",
            'placeOrder' => "fragment OrderFragment on PlaceOrderOutput {\n  order {\n    order_number\n    id\n    grand_total\n    status\n  }\n}"
        ];
        return $fragmentMap[$mutationName] ?? $this->getDefaultFragment($mutationName);
    }

    public function generateVariablesExample($queryName, $queryType, $args = [])
    {
        $variables = [];
        foreach ($args as $argName => $arg) {
            $variables[$argName] = $this->getExampleValueForVariable($argName, $arg['type']);
        }
        if (empty($variables)) {
            return null;
        }
        return json_encode($variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function getExampleValueForVariable($argName, $typeString)
    {
        $typeMap = [
            'input' => $typeString,
            'cartId' => 'your_cart_id',
            'cartItems' => [['data' => ['quantity' => 1, 'sku' => 'simple-product-sku']]],
            'productId' => 1,
            'quantity' => 1,
            'sku' => 'product-sku',
            'email' => 'customer@example.com',
            'password' => 'password123',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'filter' => $typeString,
            'sort' => $typeString,
            'pageSize' => 20,
            'currentPage' => 1,
            'search' => 'ABC'
        ];
        if (isset($typeMap[$argName])) {
            return $typeMap[$argName];
        }
        if (strpos($typeString, 'String') !== false) {
            return 'example_value';
        } elseif (strpos($typeString, 'Int') !== false) {
            return 123;
        } elseif (strpos($typeString, 'Float') !== false) {
            return 123.45;
        } elseif (strpos($typeString, 'Boolean') !== false) {
            return true;
        } elseif (strpos($typeString, '[') !== false) {
            return ['item1', 'item2'];
        }
        return $typeString;
    }

    public function generatePhpCode($queryName, $queryType, $args = [])
    {
        $baseUrl = $this->getBaseUrl();
        $graphqlUrl = $baseUrl . 'graphql';
        $graphqlQuery = $this->generateSampleGraphQLQuery($queryName, $queryType, $args);
        $variables = $this->generateVariablesExample($queryName, $queryType, $args);
        $phpCode = "<?php\n\n" . '$' . "url = '" . $graphqlUrl . "';\n" . '$' . "data = [\n    'query' => `" . $graphqlQuery . "`\n";
        if ($variables) {
            $phpCode .= "    'variables' => " . str_replace("\n", "\n    ", $variables) . "\n";
        }
        $phpCode .= "];\n\n" . '$' . "ch = curl_init();\ncurl_setopt(" . '$' . "ch, CURLOPT_URL, " . '$' . "url);\ncurl_setopt(" . '$' . "ch, CURLOPT_RETURNTRANSFER, true);\ncurl_setopt(" . '$' . "ch, CURLOPT_POST, true);\ncurl_setopt(" . '$' . "ch, CURLOPT_POSTFIELDS, json_encode(" . '$' . "data));\ncurl_setopt(" . '$' . "ch, CURLOPT_HTTPHEADER, [\n    'Content-Type: application/json',\n    'Accept: application/json'\n]);\n\n" . '$' . "response = curl_exec(" . '$' . "ch);\ncurl_close(" . '$' . "ch);\n\necho " . '$' . "response;\n";
        return $phpCode;
    }

    public function generateJavascriptCode($queryName, $queryType, $args = [])
    {
        $baseUrl = $this->getBaseUrl();
        $graphqlUrl = $baseUrl . 'graphql';
        $graphqlQuery = $this->generateSampleGraphQLQuery($queryName, $queryType, $args);
        $variables = $this->generateVariablesExample($queryName, $queryType, $args);
        $jsCode = "const query = `" . $graphqlQuery . "`;\n\n";
        if ($variables) {
            $jsCode .= "const variables = " . $variables . ";\n\n";
        }
        $jsCode .= "fetch('" . $graphqlUrl . "', {\n  method: 'POST',\n  headers: {\n    'Content-Type': 'application/json',\n    'Accept': 'application/json'\n  },\n  body: JSON.stringify({\n    query,\n";
        if ($variables) {
            $jsCode .= "    variables\n";
        }
        $jsCode .= "  })\n})\n.then(response => response.json())\n.then(data => console.log(data))\n.catch(error => console.error('Error:', error));";
        return $jsCode;
    }

    public function generateSampleResponse($operationName, $operationType, $operationData)
    {
        $returnType = $this->getReturnType($operationName, $operationType);

        if (!$returnType) {
            return json_encode([
                'data' => [
                    $operationName => null
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $sampleData = $this->generateSampleDataForType($returnType, $operationName);

        $response = [
            'data' => [
                $operationName => $sampleData
            ]
        ];

        return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function generateSampleDataForType($typeString, $context = '', $depth = 0)
    {
        if ($depth > 5) {
            return null;
        }

        $cleanType = $this->getCleanTypeName($typeString);
        $isList = strpos($typeString, '[') !== false;
        $schemaData = $this->getSchemaData();

        if ($isList) {
            return [
                $this->generateSampleDataForType($cleanType, $context, $depth + 1),
                $this->generateSampleDataForType($cleanType, $context, $depth + 1)
            ];
        }

        // Xử lý các type đặc biệt dựa trên context
        if (isset($schemaData['types'][$cleanType])) {
            return $this->generateObjectSample($schemaData['types'][$cleanType], $context, $depth + 1);
        }

        return $this->generateScalarSample($cleanType, $context);
    }

    private function generateObjectSample($typeFields, $context, $depth)
    {
        $sample = [];

        foreach ($typeFields as $fieldName => $field) {
            if ($depth > 5 && $this->isComplexField($fieldName)) {
                continue;
            }

            $fieldType = $field['type'] ?? '';
            $cleanFieldType = $this->getCleanTypeName($fieldType);

            if ($depth < 5 && $this->isObjectType($fieldType)) {
                $sample[$fieldName] = $this->generateSampleDataForType($fieldType, $context, $depth + 1);
            } else {
                $sample[$fieldName] = $this->generateScalarSample($cleanFieldType, $fieldName);
            }
        }

        return $sample;
    }

    private function isComplexField($fieldName)
    {
        $complexFields = ['items', 'products', 'categories', 'addresses', 'bundle_options'];
        return in_array($fieldName, $complexFields);
    }

    private function generateScalarSample($typeName, $fieldName = '')
    {
        // Tạo giá trị mẫu phù hợp với field name
        switch ($typeName) {
            case 'String':
            case 'ID':
                if (stripos($fieldName, 'id') !== false) {
                    return '1';
                } elseif (stripos($fieldName, 'email') !== false) {
                    return 'customer@example.com';
                } elseif (stripos($fieldName, 'name') !== false) {
                    return 'Sample ' . ucfirst($fieldName);
                } elseif (stripos($fieldName, 'url') !== false) {
                    return 'sample-url-key';
                } elseif (stripos($fieldName, 'sku') !== false) {
                    return 'sample-sku';
                }
                return 'sample_value';
            case 'Int':
                if (stripos($fieldName, 'quantity') !== false) {
                    return 2;
                } elseif (stripos($fieldName, 'page') !== false) {
                    return 1;
                }
                return 123;
            case 'Float':
                if (stripos($fieldName, 'price') !== false || stripos($fieldName, 'amount') !== false) {
                    return 29.99;
                }
                return 123.45;
            case 'Boolean':
                return true;
            default:
                return $typeName;
        }
    }

    public function testGraphQLEndpoint()
    {
        try {
            $testQuery = '{ __schema { queryType { name } } }';
            $response = $this->executeGraphQLQuery($testQuery);
            $processed = $this->processGraphQLResponse($response);
            return !isset($processed['error']);
        } catch (Exception $e) {
            return false;
        }
    }

    public function getResponseFieldsDescription($operationName, $operationType, $operationData)
    {
        $returnType = $this->getReturnType($operationName, $operationType);

        if (!$returnType) {
            return '<p>No response fields information available.</p>';
        }

        $html = '<div class="response-fields-list">';
        $html .= $this->generateFieldsDescription($returnType, $operationName);
        $html .= '</div>';

        return $html;
    }

    private function generateFieldsDescription($typeString, $context = '', $depth = 0)
    {
        if ($depth > 2) {
            return '';
        }

        $cleanType = $this->getCleanTypeName($typeString);
        $schemaData = $this->getSchemaData();

        if (!isset($schemaData['types'][$cleanType])) {
            return '<p>Type information not available.</p>';
        }

        $html = '<ul class="fields-tree">';

        foreach ($schemaData['types'][$cleanType] as $fieldName => $field) {
            $fieldType = $field['type'] ?? '';
            $fieldDescription = $field['description'] ?? 'No description available';
            $isRequired = strpos($fieldType, '!') !== false;
            $isList = strpos($fieldType, '[') !== false;

            $html .= '<li class="field-item">';
            $html .= '<div class="field-info">';
            $html .= '<span class="field-name">' . $fieldName . '</span>';
            $html .= '<code class="field-type">' . $fieldType . '</code>';
            if ($isRequired) {
                $html .= '<span class="required-badge">required</span>';
            }
            if ($isList) {
                $html .= '<span class="list-badge">array</span>';
            }
            $html .= '</div>';
            $html .= '<div class="field-description">' . $fieldDescription . '</div>';

            if ($depth < 2 && $this->isObjectType($fieldType)) {
                $html .= $this->generateFieldsDescription($fieldType, $context, $depth + 1);
            }

            $html .= '</li>';
        }

        $html .= '</ul>';
        return $html;
    }

    private function cleanTypeForVariables($typeString)
    {
        $cleanType = str_replace('!', '', $typeString);
        if (strpos($cleanType, '[') !== false) {
            $cleanType = preg_replace('/\[([^]]+)\]/', '[$1]', $cleanType);
        }
        return $cleanType;
    }

}
