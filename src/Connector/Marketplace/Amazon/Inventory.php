<?php

namespace App\Connector\Marketplace\Amazon;

use App\Connector\Marketplace\Amazon\Constants as AmazonConstants;
use App\Connector\Marketplace\Amazon\Connector as AmazonConnector;
use App\Utils\Utility;

class Inventory
{
    public $amazonConnector;
    public $rateLimit = 0;

    public function __construct(AmazonConnector $amazonConnector) 
    {
        $this->amazonConnector = $amazonConnector;
    }

    public function downloadInventory()
    {
        $inventoryApi = $this->amazonConnector->amazonSellerConnector->fbaInventoryV1();
        foreach ($this->amazonConnector->getMarketplace()->getFbaRegions() ?? [] as $country) {
            $nextToken = null;
            $summary = [];
            do {
                try {
                    $response = $inventoryApi->getInventorySummaries(
                        granularityType: 'Marketplace', 
                        granularityId: AmazonConstants::amazonMerchant[$country]['id'],
                        details: true,
                        nextToken: $nextToken,
                        marketplaceIds: [AmazonConstants::amazonMerchant[$country]['id']],
                    );
                    $responseJson = $response->json();
                    $summary = array_merge($summary, $responseJson['payload']['inventorySummaries'] ?? []);
                    $nextToken = $responseJson['pagination']['nextToken'] ?? null;
                    echo "+";
                } catch (\Exception $e) {
                    $this->rateLimit++;
                    echo "-{$this->rateLimit}";
                }
                sleep($this->rateLimit);
            } while ($nextToken);
            Utility::setCustomCache(
                "{$country}_inventory.json",
                PIMCORE_PROJECT_ROOT . "/tmp/marketplaces/AmazonInventory", 
                json_encode($summary, JSON_PRETTY_PRINT)
            );   
        }
    }
}