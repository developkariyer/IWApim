<?php

namespace App\Calculator;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\ClassDefinition\CalculatorClassInterface;
use Pimcore\Model\DataObject\Data\CalculatedValue;
use Pimcore\Db;
use Pimcore\Model\DataObject\ShopifyVariant;
use Pimcore\Model\DataObject\TrendyolVariant;

class OrdersCalculator implements CalculatorClassInterface
{
    public function compute(Concrete $object, CalculatedValue $context): string
    {
        $returnValue = match ($context->getFieldname()) {
            'totalOrders' => $this->totalOrders($object),
            'last30Orders' => $this->last30Orders($object),
            default => '',
        };
        return str_pad($returnValue, 7, '0', STR_PAD_LEFT);
    }

    public function totalOrders(Concrete $object): string
    {
        $db = Db::get();
        $variantId = (string) $object->getUniqueMarketplaceId();
        $marketplace = $object->getMarketplace();
        $marketplaceType = $marketplace->getMarketPlaceType();
        if ($marketplaceType === 'Trendyol') {
            $variantId = (string) $object->json_decode($variantProduct->jsonRead('apiResponseJson'), true)["productCode"];
        }
        if (!$variantId) {
            return '';
        }
        $result = $db->fetchOne("SELECT sum(quantity) FROM `iwa_marketplace_orders_line_items` WHERE variant_id = ?", [$variantId]);
        return $result + 0;
        /*if ($object instanceof ShopifyVariant) {
            $shopifyId = (string) $object->getShopifyId();
            $result = $db->fetchOne("SELECT sum(quantity) FROM `iwa_shopify_orders_line_items` WHERE variant_id = ? GROUP BY variant_id", [$shopifyId]);
            return $result + 0;
        }*/
    }

    public function last30Orders(Concrete $object): string
    {
        $db = Db::get();
        $variantId = (string) $object->getUniqueMarketplaceId();
        $result = $db->fetchOne("SELECT sum(quantity) FROM `iwa_marketplace_orders_line_items` WHERE variant_id = ? AND (created_at >= NOW() - INTERVAL 30 DAY)", [$variantId]);
        return $result + 0;
       /* if ($object instanceof ShopifyVariant) {
            $shopifyId = (string) $object->getShopifyId();
            $result = $db->fetchOne("SELECT sum(quantity) FROM `iwa_shopify_orders_line_items` WHERE variant_id = ? AND (created_at >= NOW() - INTERVAL 30 DAY) GROUP BY variant_id", [$shopifyId]);
            return $result + 0;
        }*/
        return '';
    }

    public function getCalculatedValueForEditMode(Concrete $object, CalculatedValue $context): string
    {
        return $this->compute($object, $context);
    }

}
