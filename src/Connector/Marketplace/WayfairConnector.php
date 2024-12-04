<?php

namespace App\Connector\Marketplace;

use Pimcore\Model\DataObject\VariantProduct;
use App\Utils\Utility;
use Symfony\Component\HttpClient\HttpClient;

class WayfairConnector extends MarketplaceConnectorAbstract
{
    private static $apiUrl = [
        'oauth' => 'https://sso.auth.wayfair.com/oauth/token',
        'orders' => 'https://sandbox.api.wayfair.com/v1/graphql',
    ];
    public static $marketplaceType = 'Wayfair';
    public static $expires_in;

    public function prepareToken()
    {
        try {
            $response = $this->httpClient->request('POST', static::$apiUrl['oauth'],[
                'headers' => [
                    'content-type' => 'application/json'
                ],
                'json' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->marketplace->getWayfairClientId(),
                    'client_secret' => $this->marketplace->getWayfairSecretKey(),
                    'audience' => 'https://sandbox.api.wayfair.com/'
                ]
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to get token: ' . $response->getContent(false));
            }
            $data = $response->toArray();
            static::$expires_in = time() + $data['expires_in'];
            $this->marketplace->setWayfairAccessToken($data['access_token']);
            $this->marketplace->save();
        } catch(\Exception $e) {
            echo 'Error: ' . $e->getMessage();
        }
    }

    public function download($forceDownload = false)
    {
        if (!isset(static::$expires_in) || time() >= static::$expires_in) {
            $this->prepareToken();
        }
        echo "Token is valid. Proceeding with download...\n";
        //$this->acceptDropshipOrdersSandbox();
        //$this->testEndpoint();
        //$this->getDropshipOrdersSandbox();  
        //$this->sendShipmentSandbox();
        $this->saveInventorySandbox();
    }

    public function testEndpoint()
    {
        $response = $this->httpClient->request('GET', 'https://sandbox.api.wayfair.com/v1/demo/clock',[
            'headers' => [
                'Authorization' => 'Bearer ' . $this->marketplace->getWayfairAccessToken(),
                'Content-Type' => 'application/json'
            ]
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Failed to test endpoint: ' . $response->getContent(false));
        }
        print_r($response->getContent());
    }

    public function saveInventorySandbox()
    {
        $query = <<<GRAPHQL
        mutation inventory(\$inventory: [inventoryInput]!) {
            inventory {
                save(
                    inventory: \$inventory,
                    feed_kind: TRUE_UP
                ) {
                    handle,
                    submittedAt,
                    errors {
                        key,
                        message
                    }
                }
            }
        }
        GRAPHQL;
        
        $variables = [
            "inventory" => [
                [
                    "supplierId" => 194115,
                    "supplierPartNumber" => "1234567001",
                    "quantityOnHand" => 5,
                    "quantityBackordered" => 10,
                    "quantityOnOrder" => 2,
                    "itemNextAvailabilityDate" => "2024-12-03T00:00:00+00:00", 
                    "discontinued" => false,
                    "productNameAndOptions" => "My Awesome Product",
                ],
                [
                    "supplierId" => 194115,
                    "supplierPartNumber" => "2S2CLRMTLAK3STRLB",
                    "quantityOnHand" => 5,
                    "quantityBackordered" => 10,
                    "quantityOnOrder" => 2,
                    "itemNextAvailabilityDate" => "2024-12-03T00:00:00+00:00", 
                    "discontinued" => false,
                    "productNameAndOptions" => "My Awesome Product",
                ],
                [
                    "supplierId" => 194115,
                    "supplierPartNumber" => "2S4CMASHLLHTBLAB",
                    "quantityOnHand" => 5,
                    "quantityBackordered" => 10,
                    "quantityOnOrder" => 2,
                    "itemNextAvailabilityDate" => "2024-12-03T00:00:00+00:00", 
                    "discontinued" => false,
                    "productNameAndOptions" => "My Awesome Product",
                ],
                [
                    "supplierId" => 194115,
                    "supplierPartNumber" => "2SIZEWLLMNTDESK",
                    "quantityOnHand" => 5,
                    "quantityBackordered" => 10,
                    "quantityOnOrder" => 2,
                    "itemNextAvailabilityDate" => "2024-12-03T00:00:00+00:00", 
                    "discontinued" => false,
                    "productNameAndOptions" => "My Awesome Product",
                ],
                [
                    "supplierId" => 194115,
                    "supplierPartNumber" => "4KULKUFICINGOLDS70",
                    "quantityOnHand" => 5,
                    "quantityBackordered" => 10,
                    "quantityOnOrder" => 2,
                    "itemNextAvailabilityDate" => "2024-12-03T00:00:00+00:00", 
                    "discontinued" => false,
                    "productNameAndOptions" => "My Awesome Product",
                ],
                
            ]
        ];


        $response = $this->httpClient->request('POST',static::$apiUrl['orders'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->marketplace->getWayfairAccessToken(),
                'Content-Type' => 'application/json'
            ],
            'json' => ['query' => $query,
            'variables' => $variables]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Failed to get orders: ' . $response->getContent(false));
        }
        print_r($response->getContent());
    }

    public function sendShipmentSandbox()
    {
        $query =  $query = <<<GRAPHQL
        mutation shipment(\$notice: ShipNoticeInput!) {
            purchaseOrders {
                shipment(notice: \$notice) {
                    handle,
                    submittedAt,
                    errors {
                        key,
                        message
                    }
                }
            }
        }
        GRAPHQL;
        $variables = [
            'notice' => [
                'poNumber' => 'TEST_23082207',
                'supplierId' => 194115,
                'packageCount' => 1,
                'weight' => 184,
                'volume' => 22986.958176,
                'carrierCode' => 'FDEG',
                'shipSpeed' => 'GROUND',
                'trackingNumber' => '210123456789',
                'shipDate' => '2024-12-03 08:53:33.000000 +00:00',
                'sourceAddress' => [
                    'name' => 'John Smith',
                    'streetAddress1' => '123 Test Street',
                    'streetAddress2' => '# 2',
                    'city' => 'Boston',
                    'state' => 'MA',
                    'postalCode' => '02116',
                    'country' => 'US',
                ],
                'destinationAddress' => [
                    'name' => 'John Smith',
                    'streetAddress1' => '123 Test Street',
                    'streetAddress2' => '# 2',
                    'city' => 'Boston',
                    'state' => 'MA',
                    'postalCode' => '02116',
                    'country' => 'USA',
                ],
                'largeParcelShipments' => [
                    [
                        'partNumber' => '4KULKUFICINGOLDS70',
                        'packages' => [
                            [
                                'code' => [
                                    'type' => 'TRACKING_NUMBER',
                                    'value' => '210123456781',
                                ],
                                'weight' => 150,
                            ],
                        ],
                    ]
                ]
            ],
        ];
        $response = $this->httpClient->request('POST',static::$apiUrl['orders'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->marketplace->getWayfairAccessToken(),
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'query' => $query,
                'variables' => $variables
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Failed to get orders: ' . $response->getContent(false));
        }
        print_r($response->getContent());
    }

    public function acceptDropshipOrdersSandbox()
    {
        $query = <<<GRAPHQL
        mutation acceptOrder(\$poNumber: String!, \$shipSpeed: ShipSpeed!, \$lineItems: [AcceptedLineItemInput!]!) {
            purchaseOrders {
                accept(
                    poNumber: \$poNumber,
                    shipSpeed: \$shipSpeed,
                    lineItems: \$lineItems
                ) {
                    handle,
                    submittedAt,
                    errors {
                        key,
                        message
                    }
                }
            }
        }
        GRAPHQL;
        $variables = [
            'poNumber' => 'TEST_23082207',
            'shipSpeed' => 'GROUND',
            'lineItems' => [
                [
                    'partNumber' => '4KULKUFICINGOLDS70',
                    'quantity' => 1,
                    'unitPrice' => 9.99,
                    'estimatedShipDate' => '2024-12-05 08:53:33.000000 +00:00',
                ]
            ],
        ];
        $response = $this->httpClient->request('POST',static::$apiUrl['orders'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->marketplace->getWayfairAccessToken(),
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'query' => $query,
                'variables' => $variables
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Failed to get orders: ' . $response->getContent(false));
        }
        print_r($response->getContent());
    }

    public function getDropshipOrdersSandbox()
    {
        $query = <<<GRAPHQL
        query getDropshipPurchaseOrders {
            getDropshipPurchaseOrders(
                limit: 10,
                hasResponse: true,
                sortOrder: DESC
            ) {
                poNumber,
                poDate,
                estimatedShipDate,
                customerName,
                customerAddress1,
                customerAddress2,
                customerCity,
                customerState,
                customerPostalCode,
                orderType,
                shippingInfo {
                    shipSpeed,
                    carrierCode
                },
                packingSlipUrl,
                warehouse {
                    id,
                    name
                },
                products {
                    partNumber,
                    quantity,
                    price,
                    event {
                        startDate,
                        endDate
                    }
                }
            }
        }
        GRAPHQL;
        $response = $this->httpClient->request('POST',static::$apiUrl['orders'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->marketplace->getWayfairAccessToken(),
                'Content-Type' => 'application/json'
            ],
            'json' => ['query' => $query]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Failed to get orders: ' . $response->getContent(false));
        }
        print_r($response->getContent());
    }
    

    public function import($updateFlag, $importFlag)
    {
       
    }

    public function downloadOrders()
    {
        
    }
    
    public function downloadInventory()
    {

    }
   
}