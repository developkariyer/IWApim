<?php

namespace App\Connector\Marketplace;

use Pimcore\Model\DataObject\VariantProduct;
use App\Utils\Utility;

class EtsyConnector extends MarketplaceConnectorAbstract
{
    public static $marketplaceType = 'Etsy';

    public function download($forceDownload = false)
    {
        $filename = 'tmp/'.urlencode($this->marketplace->getShopId()).'.json';
        $jsonData = (file_exists($filename)) ? json_decode(file_get_contents($filename), true) : [];
        $this->listings = $jsonData['listings'] ?? [];
        return count($this->listings);
    }

    public function downloadOrders()
    {
        $db = \Pimcore\Db::get();
        $filename = 'tmp/'.urlencode($this->marketplace->getShopId()).'.json';
        $jsonData = (file_exists($filename)) ? json_decode(file_get_contents($filename), true) : [];
        $orders = $jsonData['orders'] ?? [];
        if (empty($orders)) {
            return;
        }
        $db->beginTransaction();
        try {
            foreach ($orders as $order) {
                $db->executeStatement(
                    "INSERT INTO iwa_marketplace_orders (marketplace_id, order_id, json) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE json = VALUES(json)",
                    [
                        $this->marketplace->getId(),
                        $order['receipt_id'],
                        json_encode($order)
                    ]
                );
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    public function downloadInventory()
    {
    }

    protected function getAttributes($listing) {
        if (!empty($listing['property_values'])) {
            return implode(
                ' ',
                array_map(function($element) {
                        $values = implode('-', array_map(function($value) {
                            return str_replace(' ', '', $value);
                        }, $element['values']));
                        return $values;
                    }, $listing['property_values'])
            );
        }
        return '';
    }

    protected function getSalePrice($listing, $type='exists') {
        if (!empty($listing['offerings']) && !empty($listing['offerings'][0]['price'])) {
            return match ($type) {
                'price' => bcdiv((string) ($listing['offerings'][0]['price']['amount'] ?? '0'), '100', 4),
                'currency'=> $listing['offerings'][0]['price']['currency_code'] ?? '', 
                'exists' => true,
            };
        }
        return '';
    }

    public function import($updateFlag, $importFlag)
    {
        if (empty($this->listings)) {
            echo "Nothing to import\n";
        }
        $marketplaceFolder = Utility::checkSetPath(
            Utility::sanitizeVariable($this->marketplace->getKey(), 190),
            Utility::checkSetPath('Pazaryerleri')
        );
        echo "Unpublishing current listings...";
        $variantProducts = $this->marketplace->getVariantProducts();
        foreach ($variantProducts as $variantProduct) {
            if ($variantProduct->isPublished()) {
                $variantProduct->setPublished(false);
                $variantProduct->save();
                echo "+";
            } else {
                echo ".";
            }
        }
        echo "Done.\n";
        $total = count($this->listings);
        $index = 0;
        foreach ($this->listings as $mainListing) {
            echo "($index/$total) Processing Listing {$mainListing['listing_id']}:{$mainListing['title']} ...";
            $parent = Utility::checkSetPath(
                Utility::sanitizeVariable($mainListing['shop_section_id'] ?? 'Tasnif-Edilmemiş'),
                $marketplaceFolder
            );
            if (!empty($mainListing['title'])) {
                $parent = Utility::checkSetPath(
                    Utility::sanitizeVariable($mainListing['title']),
                    $parent
                );    
            }
            $parentResponseJson = $mainListing;
            if (isset($parentResponseJson['inventory'])) {
                unset($parentResponseJson['inventory']);
            }
            foreach ($mainListing['inventory'] as $listing) {
                VariantProduct::addUpdateVariant(
                    variant: [
                        'imageUrl' => null,
                        'urlLink' => $this->getUrlLink($mainListing['url'] ?? ''),
                        'salePrice' => $this->getSalePrice($listing, 'price'),
                        'saleCurrency' => $this->getSalePrice($listing, 'currency'),
                        'attributes' => $this->getAttributes($listing),
                        'title' => ($mainListing['title'] ?? '').($this->getAttributes($listing)),
                        'uniqueMarketplaceId' => $listing['product_id'] ?? '',
                        'apiResponseJson' => json_encode($listing, JSON_PRETTY_PRINT),
                        'parentResponseJson' => json_encode($parentResponseJson, JSON_PRETTY_PRINT),
                        'published' => !((bool) $listing['is_deleted'] ?? false),
                        'sku' => $listing['sku'] ?? '',
                    ],
                    importFlag: $importFlag,
                    updateFlag: $updateFlag,
                    marketplace: $this->marketplace,
                    parent: $parent
                );
                echo "v";
            }
            echo "OK\n";
            $index++;
        }
    }
}
