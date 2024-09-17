<?php

namespace App\Calculator;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\VariantProduct;
use Pimcore\Model\DataObject\ClassDefinition\Layout\DynamicTextLabelInterface;

class JsonRenderer implements DynamicTextLabelInterface
{
    public function renderLayoutText(string $data, ?Concrete $object, array $params): string
    {
        if (!$object instanceof VariantProduct) {
            return '';
        }

        $db = \Pimcore\Db::get();
        $response = ($object->getMarketplace()->getMarketplaceType() === 'Amazon')
            ? $db->fetchOne('SELECT json_data FROM iwa_json_store WHERE field_name = ?', [$object->getUniqueMarketplaceId()]) 
            : $db->fetchOne('SELECT json_data FROM iwa_json_store WHERE object_id = ? AND field_name = ?', [$object->getId(), $data]);

        if (empty($response)) {
            return '';
        }
        $response = json_decode($response, true);
        if (isset($response['body_html'])) {
            $response['body_html'] = "NOT DISPLAYED IN IWAPIM";
        }
        if (isset($response['product_description'])) {
            $response['product_description'] = "NOT DISPLAYED IN IWAPIM";
        }

        $response = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return "<pre>$response</pre>";
    }
}