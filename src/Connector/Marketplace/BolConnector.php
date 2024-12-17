<?php

namespace App\Connector\Marketplace;

use Doctrine\DBAL\Exception;
use Pimcore\Db;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\Element\DuplicateFullPathException;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Pimcore\Model\DataObject\VariantProduct;
use App\Utils\Utility;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class BolConnector extends MarketplaceConnectorAbstract
{
    private static array $apiUrl = [
        'loginTokenUrl' => "https://login.bol.com/token?grant_type=client_credentials",
        'offerExportUrl' => "/retailer/offers/export/",
        'processStatusUrl' => "/shared/process-status/",
        'productsUrl' => "/retailer/products/",
        'catalogProductsUrl' => "/retailer/content/catalog-products/",
        'commissionUrl' => "/retailer/commission/",
        'orders' => "/retailer/orders/",
        "offers" => "/retailer/offers/"
    ];
    public static string $marketplaceType = 'Bol.com';

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws \Exception
     */
    protected function prepareToken(): void
    {
        if (!Utility::checkJwtTokenValidity($this->marketplace->getBolJwtToken())) {
            $response = $this->httpClient->request('POST', static::$apiUrl['loginTokenUrl'], [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("{$this->marketplace->getBolClientId()}:{$this->marketplace->getBolSecret()}"),
                    'Accept' => 'application/json'
                ]
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to get JWT token from Bol.com');
            }
            $decodedResponse = json_decode($response->getContent(), true);
            $this->marketplace->setBolJwtToken($decodedResponse['access_token']);
            $this->marketplace->save();
        } 
        $this->httpClient = ScopingHttpClient::forBaseUri($this->httpClient, 'https://api.bol.com/', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->marketplace->getBolJwtToken(),
                'Accept' => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json'
            ],
        ]);
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws \Exception
     */
    protected function requestOfferReport()
    {
        $this->prepareToken();
        $response = $this->httpClient->request('POST', static::$apiUrl['offerExportUrl'], ['json' => ['format' => 'CSV']]);
        if ($response->getStatusCode() !== 202) {
            throw new \Exception('Failed to get offer report from Bol.com');
        }
        $decodedResponse = json_decode($response->getContent(), true);
        if ($decodedResponse['status'] !== 'SUCCESS' && $decodedResponse['status'] !== 'PENDING') {
            throw new \Exception('Failed to get offer report from Bol.com');
        }
        return $decodedResponse;
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws \Exception
     */
    protected function reportStatus($decodedResponse)
    {
        $this->prepareToken();
        $status = $decodedResponse['status'] === 'SUCCESS';
        $statusUrl = $decodedResponse['links'][0]['href'] ?? static::$apiUrl['processStatusUrl'] . ($decodedResponse['processStatusId'] ?? '');

        while (!$status) {
            echo "  Waiting for report...\n";
            sleep(5);
            $response = $this->httpClient->request('GET', $statusUrl);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to get offer report from Bol.com');
            }
            $decodedResponse = json_decode($response->getContent(), true);
            $status = match ($decodedResponse['status'] ?? '') {
                'SUCCESS' => true,
                'PENDING' => false,
                default => throw new \Exception('Failed to get offer report from Bol.com: ' . $response->getContent()),
            };
        }
        if (empty($decodedResponse['entityId'])) {
            throw new \Exception('Failed to get offer report from Bol.com.');
        }
        return $decodedResponse['entityId'];
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws TransportExceptionInterface
     * @throws \Exception
     */
    public function downloadOfferReport($forceDownload = false): string
    {
        $this->prepareToken();
        $report =  $this->getFromCacheRaw('OFFERS_EXPORT_REPORT.csv');
        if (!$report || $forceDownload) {
            echo "Requesting offer report from Bol.com\n";
            $entityId = $this->reportStatus($this->requestOfferReport());
            $response = $this->httpClient->request('GET', static::$apiUrl['offerExportUrl'] . $entityId, ['headers' => ['Accept' => 'application/vnd.retailer.v10+csv']]);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to get offer report from Bol.com:'.$response->getContent());
            }
            $report = $response->getContent();
            $this->putToCacheRaw('OFFERS_EXPORT_REPORT.csv', $report);
        } else {
            echo "Using cached report\n";
        }
        return $report;
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     */
    protected function downloadExtra($apiEndPoint, $type, $parameter, $query = [])
    {
        $this->prepareToken();
        $response = $this->httpClient->request($type, $apiEndPoint . $parameter, ['query' => $query]);
        if ($response->getStatusCode() !== 200) {
            echo "Failed to {$type} {$apiEndPoint}{$parameter}: {$response->getContent()}\n";
            return null;
        }
        echo "{$apiEndPoint}{$parameter} ";
        usleep(200000);
        return json_decode($response->getContent(), true);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function getListings($report): void
    {
        $rows = array_map('str_getcsv', explode("\n", trim($report)));
        $headers = array_shift($rows);
        $this->listings = [];
        $totalCount = count($rows);
        $index = 0;
        foreach ($rows as $row) {
            $index++;
            if (count($row) === count($headers)) {
                $rowData = array_combine($headers, $row);
                $ean = $rowData['ean'];
                echo "($index/$totalCount) Downloading $ean ";
                $this->listings[$ean] = $rowData;
                $this->listings[$ean]['catalog'] = $this->downloadExtra(static::$apiUrl['catalogProductsUrl'], 'GET', $ean);
                $this->listings[$ean]['assets'] = $this->downloadExtra(static::$apiUrl['productsUrl'], 'GET', "$ean/assets", ['usage' => 'IMAGE']);
                $this->listings[$ean]['placement'] = $this->downloadExtra(static::$apiUrl['productsUrl'], 'GET', "$ean/placement");
                $this->listings[$ean]['commission'] = $this->downloadExtra(static::$apiUrl['commissionUrl'], 'GET', $ean, ['condition' => 'NEW', 'unit-price' => $rowData['bundlePricesPrice']]);
                $this->listings[$ean]['product-ids'] = $this->downloadExtra(static::$apiUrl['productsUrl'], 'GET', "$ean/product-ids");
                $this->putToCache("EAN_{$ean}.json", $this->listings[$ean]);
                echo "OK\n";
            }
        }
    }

    protected function getAttribute($listing, $id): string
    {
        $retval = '';
        if (!is_array($id)) {
            $id = [$id];
        }
        if (!empty($listing['catalog']['attributes']) && is_array($listing['catalog']['attributes'])) {
            foreach ($listing['catalog']['attributes'] as $attribute) {
                if (in_array($attribute['id'], $id, true)) {
                    $retval .= " " . ($attribute['values'][0]['value'] ?? '');
                    $key = array_search($attribute['id'], $id);
                    if ($key !== false) {
                        unset($id[$key]);
                    }
                    if (empty($id)) {
                        return trim($retval);
                    }
                }
            }
        }
        return trim($retval);
    }

    /**
     * @throws DuplicateFullPathException
     */
    protected function getFolder($listing): ?Folder
    {
        $folder = Utility::checkSetPath(
            Utility::sanitizeVariable($this->marketplace->getKey(), 190),
            Utility::checkSetPath('Pazaryerleri')
        );
        if (!empty($listing['placement']['categories'][0]['categoryName'])) {
            $folder = Utility::checkSetPath(
                Utility::sanitizeVariable($listing['placement']['categories'][0]['categoryName'], 190),
                $folder
            );
            $subcategory = $listing['placement']['categories'][0]['subCategories'][0] ?? [];
            while (!empty($subcategory)) {
                if (!empty($subcategory['name'])) {
                    $folder = Utility::checkSetPath(
                        Utility::sanitizeVariable($subcategory['name'], 190),
                        $folder
                    );
                }
                $subcategory = $subcategory['subCategories'][0] ?? [];
            }
        }
        $family = $this->getAttribute($listing, ['Family Name']);
        if (!empty($family)) {
            $folder = Utility::checkSetPath(
                Utility::sanitizeVariable($family, 190),
                $folder
            );
        }
        return $folder;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function download($forceDownload = false): void
    {   
        if (!$forceDownload && $this->getListingsFromCache()) {
            echo "Using cached listings\n";
            return;
        }
        $this->getListings(
            $this->downloadOfferReport($forceDownload)
        );
        $this->putListingsToCache();
    }

    /**
     * @throws DuplicateFullPathException
     * @throws \Exception
     */
    public function import($updateFlag, $importFlag): void
    {
        if (empty($this->listings)) {
            echo "Nothing to import\n";
        }
        $total = count($this->listings);
        $index = 0;
        foreach($this->listings as $listing) {
            $index++;
            echo "($index/$total) Importing ".($listing['product-ids']['bolProductId'] ?? '')." ...";
            VariantProduct::addUpdateVariant(
                variant: [
                    'imageUrl' => '',
                    'urlLink' => $this->getUrlLink($listing['placement']['url'] ?? ''),
                    'salePrice' => $listing['bundlePricesPrice'] ?? '0.00',
                    'saleCurrency' => 'EUR',
                    'title' => $this->getAttribute($listing, ['Title']),
                    'attributes' => $this->getAttribute($listing, ['Dropdown Size HxWxL', 'Colour']),
                    'quantity' => $listing['correctedStock'] ?? 0,
                    'uniqueMarketplaceId' => $listing['product-ids']['bolProductId'] ?? '',
                    'apiResponseJson' => json_encode($listing),
                    'published' => $listing['catalog']['published'] ?? false,
                    'ean' => $listing['ean'],
                ],
                importFlag: $importFlag,
                updateFlag: $updateFlag,
                marketplace: $this->marketplace,
                parent: $this->getFolder($listing),
            );
            echo "OK\n";
        }
    }

    /**
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws Exception
     * @throws TransportExceptionInterface
     */
    public function downloadOrders(): void
    {
        $this->prepareToken();
        $db = Db::get();
        $now = strtotime('now');
        $lastUpdatedAt = $db->fetchOne(
            "SELECT COALESCE(DATE_FORMAT(MAX(json_extract(json, '$.orderPlacedDateTime')), '%Y-%m-%d'), DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 3 MONTH), '%Y-%m-%d')) 
             FROM iwa_marketplace_orders 
             WHERE marketplace_id = ?",
            [$this->marketplace->getId()]
        );
        if ($lastUpdatedAt) {
            $lastUpdatedAtTimestamp = strtotime($lastUpdatedAt);
            $threeMonthsAgo = strtotime('-3 months', $now);
            $startDate = max($lastUpdatedAtTimestamp, $threeMonthsAgo); 
        } else {
            $startDate = strtotime('-3 months');
        }
        $endDate = min(strtotime('+1 day', $startDate), $now);
        do {
            $page = 1;
            echo "Page $page for date  " . date('Y-m-d', $startDate) . " - " . date('Y-m-d', $endDate) . "\n";
            do {
                $params = ['status' => 'ALL', 'page' => $page, 'fulfilment-method' => 'ALL','latest-change-date'=>date('Y-m-d', $startDate)];
                $response = $this->httpClient->request("GET", static::$apiUrl['orders'], ['query' => $params]);
                if ($response->getStatusCode() !== 200) {
                    echo "Failed to download orders: " . $response->getContent() . "\n";
                    return;
                }
                $data = $response->toArray();
                $orders = $data['orders'] ?? [];
                foreach ($orders as  &$order) {
                    foreach ($order['orderItems'] as  &$orderItem) {
                        $productDetailResponse = $this->httpClient->request("GET", static::$apiUrl['productsUrl'].'/'.$orderItem['ean'].'/product-ids');
                        if ($productDetailResponse->getStatusCode() !== 200) {
                            echo "Failed to download product detail: " . $productDetailResponse->getContent() . "\n";
                            continue;
                        }
                        $productDetail = $productDetailResponse->toArray();
                        $bolProductId = $productDetail['bolProductId'] ?? '';
                        $orderItem['bolProductId'] = $bolProductId;
                        usleep(1500000);
                    }

                    $orderId = $order['orderId'];
                    $orderDetailResponse = $this->httpClient->request("GET", static::$apiUrl['orders'].'/'.$orderId);
                    if ($orderDetailResponse->getStatusCode() !== 200) {
                        echo "Failed to download order detail: " . $orderDetailResponse->getContent() . "\n";
                        continue;
                    }

                    $orderDetail = $orderDetailResponse->toArray();          
                    foreach ($orderDetail['orderItems'] as &$orderItem) {
                        $ean = $orderItem['product']['ean'];
                        foreach ($order['orderItems'] as $item) {
                            if ($item['ean'] === $ean) {
                                $orderItem['product']['bolProductId'] = $item['bolProductId']; 
                                break; 
                            }
                        }
                    }
                    $order['orderDetail'] = $orderDetail; 
                    $db->beginTransaction();
                    try {
                        $db->executeStatement(
                            "INSERT INTO iwa_marketplace_orders (marketplace_id, order_id, json) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE json = VALUES(json)",
                            [
                                $this->marketplace->getId(),
                                $order['orderId'],
                                json_encode($order)
                            ]
                        );
                        echo "Inserting order: " . $order['orderId'] . "\n";
                        $db->commit();
                    }
                    catch (\Exception $e) {
                        $db->rollBack();
                        echo "Error: " . $e->getMessage() . "\n";
                    }
                    usleep(50000);
                } 
                $page++;
                usleep(3000000);
            } while(count($orders) == 50);
            $startDate = $endDate;
            $endDate = min(strtotime('+1 day', $startDate), $now);
            if ($startDate >= $now) {
                break;
            }
            $this->prepareToken();
        } while ($startDate < strtotime('now'));
        unset($order); 
    }

    public function downloadInventory()
    {
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws Exception
     */
    public function setInventory(VariantProduct $listing, int $targetValue, $sku = null, $country = null, $locationId = null): void
    {
        $this->prepareToken();
        if ($targetValue > 999) {
            echo "Bol.com does not support inventory values greater than 999\n";
            return;
        }
        $offerId = json_decode($listing->jsonRead('apiResponseJson'), true)['offerId'];
        if (empty($offerId)) {
            echo "Failed to get inventory item id for {$listing->getKey()}\n";
            return;
        }
        $json = ['amount' => $targetValue, 'managedByRetailer' => true];
        $response = $this->httpClient->request("PUT", static::$apiUrl['offers'] . $offerId . '/stock', ['json' => $json]);
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 202) {
            echo "Error: $statusCode\n";
            return;
        }
        $data = $response->toArray();
        echo "Inventory set\n";
        $date = date('YmdHis');
        $filename = "SETINVENTORY_{$offerId}_$date.json";
        $this->putToCache($filename, ['request'=>$json, 'response'=>$data]);
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws Exception
     * @throws \Exception
     */
    public function setPrice(VariantProduct $listing, string $targetPrice, $targetCurrency = null, $sku = null, $country = null): void
    {
        $this->prepareToken();
        if (!$listing instanceof VariantProduct) {
            echo "Listing is not a VariantProduct\n";
            return;
        }
        if ($targetPrice === null) {
            echo "Error: Price cannot be null\n";
            return;
        }
        if ($targetCurrency === null) {
            $targetCurrency = $listing->getSaleCurrency();
        }
        $finalPrice = $this->convertCurrency($targetPrice, $targetCurrency, $listing->getSaleCurrency());
        if ($finalPrice === null) {
            echo "Error: Currency conversion failed\n";
            return;
        }
        $offerId = json_decode($listing->jsonRead('apiResponseJson'), true)['offerId'];
        if (empty($offerId)) {
            echo "Failed to get inventory item id for {$listing->getKey()}\n";
            return;
        }
        $response = $this->httpClient->request("PUT", static::$apiUrl['offers'] . $offerId . '/price', ['json' => ['pricing' => ['bundlePrices' => [['unitPrice' => $finalPrice, 'quantity' => 1]]]]]);
        print_r($response->getContent());
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 202) {
            echo "Error: $statusCode\n";
            return;
        }
        $data = $response->toArray();
        echo "Price set\n";
        $date = date('Y-m-d-H-i-s');
        $filename = "{$offerId}-$date.json";  
        Utility::setCustomCache($filename, PIMCORE_PROJECT_ROOT. "/tmp/marketplaces/".urlencode($this->marketplace->getKey()) . '/SetPrice', json_encode($data));
    } 

}