<?php

namespace App\Command;

use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Folder;
//use App\Model\DataObject\Marketplace;
use App\Model\DataObject\Marketplace\Listing;
use App\Model\DataObject\VariantProduct;
use Pimcore\Model\DataObject\VariantProduct\Listing as VariantListing; 
use Pimcore\Model\DataObject\Category;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Pimcore\Model\DataObject\Marketplace;
use App\Utils\Utility;
use Exception;


#[AsCommand(
    name: 'app:wisersell',
    description: 'connect wisersell api'
)]

class WisersellCommand extends AbstractCommand
{
    private $wisersellListings = [];
    protected $wisersellProducts = [];
    private $iwapimListings = [];
    private static $apiServer = '';
    private static $email = '';
    private static $password = '';
    private static $apiUrl = [
        'productSearch' => 'product/search',
        'category' => 'category',
        'product'=> 'product',
        'store' => 'store',
        'listingSearch' => 'listing/search',
        'listing' => 'listing/'
    ];
    private $httpClient = null;
    protected $categoryList = [];
    protected $wisersellToken = null;
    protected $storeList = [];

    protected function configure() 
    {
        $this
            ->addOption('dev',null, InputOption::VALUE_NONE, 'Development mode')
            ->addOption('prod',null, InputOption::VALUE_NONE, 'Production mode')
            ->addOption('category', null, InputOption::VALUE_NONE, 'Category add wisersell')
            ->addOption('product', null, InputOption::VALUE_NONE, 'Product add wisersell')
            ->addOption('download', null, InputOption::VALUE_NONE, 'Force download of wisersell products')
            ->addOption('store', null, InputOption::VALUE_NONE, 'List all stores')
            ->addOption('relation', null, InputOption::VALUE_NONE, 'Sync relations')
            ->addOption('code', null, InputOption::VALUE_NONE, 'Sync code')
            ->addOption('calculatecode', null, InputOption::VALUE_NONE, 'Calculate code')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->httpClient = HttpClient::create();
        $forceDownload = $input->getOption('download', false);

        if ($input->getOption('dev')) {
            static::$apiServer = 'https://dev2.wisersell.com/restapi/';
            static::$email = $_ENV['WISERSELL_DEV_USER'];
            static::$password = $_ENV['WISERSELL_DEV_PASSWORD'];
        }
        if ($input->getOption('prod')) {
            static::$apiServer = 'https://www.wisersell.com/restapi/';
            static::$email = $_ENV['WISERSELL_PROD_USER'];
            static::$password = $_ENV['WISERSELL_PROD_PASSWORD'];
        }

        if ($input->getOption('category')) {
            $this->syncCategories();
        }
        if($input->getOption('product')) {
            $this->syncProducts($forceDownload);
        }
        if($input->getOption('store')) {
            $this->syncStores();
        }
        if($input->getOption('relation')) {
            $this->syncRelations();
        }
        if($input->getOption('code')) {
            $this->syncCode();
        }
        if($input->getOption('calculatecode')) {
            $this->calculateWisersellCode();
        }
        return Command::SUCCESS;
    }

    protected function syncStores()
    {
        $this->storeList = [];
        $response = $this->request('store', 'GET', '');
        foreach ($response->toArray() as $store) {
            echo "Processing {$store['name']} {$store['id']}... ";
            $marketplace = match ($store['source']['name']) {
                'Etsy' => Marketplace::findByField('shopId', $store['shopId'] ),
                'Amazon' => Marketplace::findByField('merchantId', $store['shopId'] ),
                'Trendyol' => Marketplace::findByField('trendyolSellerId', $store['shopId'] ),
                'Shopify' => Marketplace::findByField('shopId', $store['shopId'] ),
                default => null
            };
            if ($marketplace instanceof Marketplace) {
                $marketplace->setWisersellStoreId($store['id']);
                $marketplace->save();
                $this->storeList[] = $marketplace;
                echo "Store {$store['name']} ({$store['id']}) updated in PIM\n";
            } else {
                echo "Store {$store['name']} ({$store['id']}) not found in PIM\n";
            }
        }
    }

    protected function calculateWisersellCode()
    {
        $variantObject = new VariantListing();
        $pageSize = 50;
        $offset = 0;
        $variantObject->setLimit($pageSize);
        $variantObject->setUnpublished(false);
        while (true) {
            $variantObject->setOffset($offset);
            $results = $variantObject->load();
            if (empty($results)) {
                break;
            }
            echo "Offset: " . $offset . "\n";
            $offset += $pageSize;
            foreach ($results as $object) {
                echo "uniqueMarketplaceId: " . $object->getUniqueMarketplaceId() . "\n"; 
                $marketplaceObject = $object->getMarketplace();
                $marketplaceType = $marketplaceObject->getMarketplaceType();
                $storeId = $marketplaceObject->getWisersellStoreId();
                if ($storeId === null) {
                    continue; 
                }
                $storeProductId = match ($marketplaceType) {
                    'Etsy' => json_decode($object->jsonRead('apiResponseJson'), true)["product_id"],
                    'Amazon' =>  json_decode($object->jsonRead('apiResponseJson'), true)["asin"],
                    'Shopify' => json_decode($object->jsonRead('apiResponseJson'), true)["product_id"],  
                    'Trendyol' => json_decode($object->jsonRead('apiResponseJson'), true)["productCode"],
                };
                if (!$storeProductId) {
                    echo "Store product id not found for variant product: " .$object->getId();
                    continue;
                }
                $variantCode = match ($marketplaceType) {
                    'Etsy' => json_decode($object->jsonRead('parentResponseJson'), true) ["listing_id"],
                    'Shopify' => json_decode($object->jsonRead('apiResponseJson'), true)["id"],  
                    'Trendyol' => json_decode($object->jsonRead('apiResponseJson'), true)["platformListingId"],
                };
                if (!$variantCode && $marketplaceType !== 'Amazon') {
                    echo "Variant code not found for variant product: " .$object->getId();
                    continue;
                }
                $data = "";
                if($marketplaceType !== 'Amazon') {
                    $data = "{$storeId}_{$storeProductId}_{$variantCode}";
                }
                else {
                    $data = "{$storeId}_{$storeProductId}";
                }
                $hash = hash('sha1', $data);
                $object->setCalculatedWisersellCode($hash);
                $object->save();
            }
        }
    }

    protected function searchAndUpdateVariantProducts($responseArray)
    {
        $filePath = PIMCORE_PROJECT_ROOT . '/tmp/wisersell_error_listings.json';
        $wisersellListingsError = [];
        foreach ($responseArray['rows'] as $row) {
            $variantProduct = VariantProduct::findOneByField('calculatedWisersellCode', $row['code'],null,true);
            echo "\nProcessing {$row['code']}... \n";
            if ($variantProduct instanceof VariantProduct) {
                echo "\nFound in PIM... \n";
                echo $variantProduct->getId();
                $mainProduct = $variantProduct->getMainProduct();
                if (!$mainProduct) {
                    echo "Main product not found for variant product: \n";
                    $variantProduct->setMainProduct($mainProduct);
                    $variantProduct->save();
                    echo "\n Variant Product: {$variantProduct->getId()} Connected Main Product: {$mainProduct->getId()} \n";
                }
                $variantProduct->setWisersellVariantCode($row['code']);
                $variantProduct->save();
                echo "\nUpdated in PIM... \n";
            }
            else {
                echo "\nNot found in PIM... \n";
                $wisersellListingsError[] = $row;
            }
        }
        if (!empty($wisersellListingsError)) {
            file_put_contents($filePath, json_encode($wisersellListingsError, JSON_PRETTY_PRINT), FILE_APPEND);
            echo "\nErrors appended to JSON file.\n";
        }
    }

    protected function syncCode()
    {
        $response = $this->request('store','GET','');   
        foreach ($response->toArray() as $store) {
            echo "Processing {$store['name']} {$store['id']}...  \n";
            $marketplace = match ($store['source']['name']) {
                'Etsy' => Marketplace::findByField('shopId', $store['shopId'] ),
                'Amazon' => Marketplace::findByField('merchantId', $store['shopId'] ),
                'Trendyol' => Marketplace::findByField('trendyolSellerId', $store['shopId'] ),
                'Shopify' => Marketplace::findByField('shopId', $store['shopId'] ),
                default => null
            };
            if ($marketplace instanceof Marketplace) {
                $pageSize = 100;
                $page = 0;
                do {
                    $searchData = [  
                        "shopIds" => [$store['shopId']],
                        "page" => $page,
                        "pageSize" => $pageSize
                    ];
                    $response = $this->request(self::$apiUrl['listingSearch'], 'POST','', $searchData);
                    print_r($response->getContent()."\n");
                    $responseArray = $response->toArray();
                    $this->searchAndUpdateVariantProducts($responseArray);
                    $page++;
                    echo "Loaded ".($page*$pageSize)." listing from Wisersell\n";
                } while (count($responseArray['rows']) == $pageSize);
            }
        }
    }

    protected function syncRelations()
    {
        if(empty($this->storeList)) {
            $this->syncStores();
        }
        $listingBucket = [];
        foreach ($this->storeList as $marketplace) {
            foreach ($marketplace->getVariantProductIds() as $id) {
                echo "Processing {$id}... ";
                $variantProduct = VariantProduct::getById($id);
                if (!$variantProduct instanceof VariantProduct || $variantProduct->getWisersellVariantCode() !== null ) {
                    continue;
                }
                $marketplaceType = $marketplace->getMarketPlaceType();
                $mainProduct = $variantProduct->getMainProduct();
                if (!$mainProduct) {
                    echo "Main product not found for variant product: " .$id;
                    continue;
                }
                $productId = $mainProduct[0]->getWisersellId();
                $storeProductId = match ($marketplaceType) {
                    'Etsy' => json_decode($variantProduct->jsonRead('apiResponseJson'), true)["product_id"],
                    'Amazon' =>  json_decode($variantProduct->jsonRead('apiResponseJson'), true)["asin"],
                    'Shopify' => json_decode($variantProduct->jsonRead('apiResponseJson'), true)["product_id"],  
                    'Trendyol' => json_decode($variantProduct->jsonRead('apiResponseJson'), true)["productCode"],
                };
                if (!$storeProductId) {
                    echo "Store product id not found for variant product: " .$id;
                    continue;
                }
                $shopId = match ($marketplaceType) {
                    'Etsy' => $marketplace->getShopId(),
                    'Amazon' => $marketplace->getMerchantId(),
                    'Shopify' => $marketplace->getShopId(),  
                    'Trendyol' => $marketplace->getTrendyolSellerId(),
                };
                if (!$shopId) {
                    echo "Shop id not found for variant product: " .$id;
                    continue;
                }
                $variantCode = match ($marketplaceType) {
                    'Etsy' => json_decode($variantProduct->jsonRead('parentResponseJson'), true) ["listing_id"],
                    'Amazon' => null,
                    'Shopify' => json_decode($variantProduct->jsonRead('apiResponseJson'), true)["id"],  
                    'Trendyol' => json_decode($variantProduct->jsonRead('apiResponseJson'), true)["platformListingId"],
                };
                if (!$variantCode && $marketplaceType !== 'Amazon') {
                    echo "Variant code not found for variant product: " .$id;
                    continue;
                }
                $listingData = [
                        "storeproductid" =>(string) $storeProductId,
                        "productId" =>(int) $productId,
                        "shopId" => $shopId,
                        "variantCode" => (string)$variantCode,
                        "variantStr" => (string)$variantCode
                ];
                $listingBucket[] = $listingData;
                if (count($listingBucket) >= 100) {
                    $this->addListingBucketToWisersell($listingBucket);
                    $listingBucket = [];
                }
            }
            if (count($listingBucket) > 0) {
                $this->addListingBucketToWisersell($listingBucket);
                $listingBucket = []; 
            }
        }
    }

    protected function addListingBucketToWisersell($listingBucket)
    {
        $response = $this->request(self::$apiUrl['listing'], 'POST','', $listingBucket);
        $responseContent = $response->getContent();  
        $responseArray = json_decode($responseContent, true); 
        if ($response->getStatusCode() === 200) {
            if (!empty($responseArray['completed'])) {
                foreach ($responseArray['completed'] as $response) {
                    $variantProduct = VariantProduct::findOneByField('calculatedWisersellCode', $response['code']);
                    if (!$variantProduct instanceof VariantProduct) {
                        echo "Variant product not found for code: " . $response['code'];
                        continue;
                    }
                    $variantProduct->setWisersellVariantCode($response['code']);
                    $variantProduct->save();
                }
            }
        }
    }

    protected function syncCategories()
    {
        echo "Syncing Categories...\n";
        $wisersellCategories = $this->getWisersellCategories();
        $pimCategories = $this->getPimCategories();
        foreach ($wisersellCategories as $wisersellCategory) {
            echo "Processing {$wisersellCategory['name']}... ";
            if (isset($pimCategories[$wisersellCategory['name']])) {
                $pimCategory = $pimCategories[$wisersellCategory['name']];
                if ($pimCategory->getWisersellCategoryId() != $wisersellCategory['id']) {
                    $pimCategory->setWisersellCategoryId($wisersellCategory['id']);
                    echo "Updated PIM... ";
                    $pimCategory->save();
                }
                unset($pimCategories[$wisersellCategory['name']]);
                echo "Done\n";
                continue;
            } 
            echo "Adding to PIM... ";
            $category = new Category();
            $category->setKey($wisersellCategory['name']);
            $category->setParent(Utility::checkSetPath('Kategoriler', Utility::checkSetPath('Ayarlar')));
            $category->setCategory($wisersellCategory['name']);
            $category->setWisersellCategoryId($wisersellCategory['id']);
            $category->save();
            echo "Done\n";
        }
        foreach ($pimCategories as $pimCategory) {
            echo "Adding to {$pimCategory->getCategory()} to Wisersell... ";
            $response = $this->addCategoryToWisersell($pimCategory->getCategory());
            if (isset($response[0]['id'])) {
                $pimCategory->setWisersellCategoryId($response[0]['id']);
                $pimCategory->save();
            } else {
                echo "Failed to add category to Wisersell: " . json_encode($response) . "\n";
            }
            echo "Done\n";
        }
    }

    protected function loadWisersellProducts($forceDownload = false)
    {
        $this->wisersellProducts = json_decode(Utility::getCustomCache('wisersell_products.json', PIMCORE_PROJECT_ROOT . '/tmp'), true);
        if (!(empty($this->wisersellProducts) || $forceDownload)) {
            echo "Loaded Wisersell Products from cache\n";
            return;
        }
        $wisersellProducts = [];
        $pageSize = 100;
        $page = 0;
        do {
            $response = $this->getWisersellProduct([
                "page" => $page,
                "pageSize" => $pageSize
            ]);
            $wisersellProducts = array_merge($wisersellProducts, $response['rows']);
            $page++;
            echo "Loaded ".($page*$pageSize)." products from Wisersell\n";
        } while (count($response['rows']) == $pageSize);
        $this->wisersellProducts = [];
        foreach ($wisersellProducts as $product) {
            $this->wisersellProducts[$product['id']] = $product;
        }
        Utility::setCustomCache('wisersell_products.json', PIMCORE_PROJECT_ROOT . '/tmp', json_encode($this->wisersellProducts));
        echo "Loaded ".count($this->wisersellProducts)." products from Wisersell\n";
    }

    protected function searchIwaskuInWisersellProducts($iwasku) {
        foreach ($this->wisersellProducts as $product) {
            if ($product['code'] === $iwasku) {
                return $product['id'];
            }
        }
        return null;
    }

    protected function compareUpdateWisersellProduct($id, $product)
    {
        $wisersellProduct = $this->wisersellProducts[$id];
        $updateWisersell = false;
        $updatePim = false;

        $updateField = function ($productField, $setMethod, $wisersellKey) use ($product, &$wisersellProduct, &$updateWisersell, &$updatePim) {
            $productValue = $product->getInheritedField($productField);
            if ($productValue != $wisersellProduct[$wisersellKey]) {
                if ($productValue > 0) {
                    $wisersellProduct[$wisersellKey] = $productValue;
                    echo "{$productField}< ";
                    $updateWisersell = true;
                } else {
                    $product->$setMethod($wisersellProduct[$wisersellKey]);
                    echo "{$productField}> ";
                    $updatePim = true;
                }
            }
        };

        if ($product->getInheritedField('name') !== $wisersellProduct['name']) {
            $wisersellProduct['name'] = $product->getInheritedField('name');
            echo "name ";
            $updateWisersell = true;
        }

        $updateField('packageWeight', 'setPackageWeight', 'weight');
        $updateField('packageDimension1', 'setPackageDimension1', 'width');
        $updateField('packageDimension2', 'setPackageDimension2', 'length');
        $updateField('packageDimension3', 'setPackageDimension3', 'height');

        $size = $wisersellProduct['extradata']['Size'] ?? '';
        $color = $wisersellProduct['extradata']['Color'] ?? '';

        if ($product->getVariationSize() !== $size) {
            $wisersellProduct['extradata']['Size'] = $product->getVariationSize();
            echo "size ";
            $updateWisersell = true;
        }
        if ($product->getVariationColor() !== $color) {
            $wisersellProduct['extradata']['Color'] = $product->getVariationColor();
            echo "color ";
            $updateWisersell = true;
        }
        if ($updateWisersell) {
            echo "Updating Wisersell... ";
            $this->request(self::$apiUrl['product'], 'PUT', "/{$id}", $wisersellProduct);
        }
        if ($updatePim) {
            echo "Updating PIM... ";
            $product->save();
        }
    }

    protected function syncProducts($forceDownload = false)
    {
        $this->syncCategories();
        $this->loadWisersellProducts($forceDownload);
        echo "Syncing Products...\n";
        $pageSize = 50;
        $offset = 0;
        $productBucket = [];
        $subProductBucket = [];
        $listingObject = new Product\Listing();
        $listingObject->setUnpublished(false);
        $listingObject->setCondition("iwasku IS NOT NULL AND iwasku != ''");
        $listingObject->setLimit($pageSize);
        while (true) {
            $listingObject->setOffset($offset);
            $products = $listingObject->load();
            if (empty($products)) {
                break;
            }
            $offset += $pageSize;
            foreach ($products as $product) {
                if ($product->level() != 1) {
                    continue;
                }
                echo "Processing {$product->getIwasku()}... ";
                if ($id = $this->searchIwaskuInWisersellProducts($product->getIwasku())) {
                    echo "Found in Wisersell, comparing... ";
                    $this->compareUpdateWisersellProduct($id, $product);
                    unset($this->wisersellProducts[$id]);
                    echo "Done\n";
                    continue;
                }
                if (count($product->getBundleProducts())) {
                    $subProductBucket[] = $product;
                    echo "Added to subProductBucket\n";
                } else {
                    $productBucket[$product->getIwasku()] = $product;
                    echo "Added to productBucket (".count($productBucket).")\n";
                }
                if (count($productBucket) >= 100) {
                    $this->addProductBucketToWisersell($productBucket);
                    $productBucket = [];
                }
            }
            echo "\nProcessed {$offset}\n";
        }
        if (!empty($productBucket)) {
            $this->addProductBucketToWisersell($productBucket);
        }
        if (!empty($this->wisersellProducts)) {
            $this->addWisersellErrorProductsToPim();
        }
    }

    protected function addWisersellErrorProductsToPim()
    {
        foreach ($this->wisersellProducts as $wisersellProduct) {
            echo "Adding Wisersell Product {$wisersellProduct['name']} to PIM ERROR... ";
            $product = Product::findByField('wisersellId', $wisersellProduct['id']);
            if (!$product instanceof Product) {
                echo "New ";
                $product = new Product();
            }
            $product->setParent(Utility::checkSetPath("WISERSELL ERROR",Utility::checkSetPath('Ürünler'))); // Wisersell Error Product!!!!
            //$product->setParent(Folder::getById(242818)); // Wisersell Error Product!!!!
            $product->setPublished(false);
            $product->setKey($wisersellProduct['id']);
            $product->setDescription(json_encode($wisersellProduct, JSON_PRETTY_PRINT));
            $product->setWisersellJson(json_encode($wisersellProduct));
            $product->setWisersellId($wisersellProduct['id']);
            $product->save();
            echo $product->getId();
            echo " Done\n";
        }
    }

    protected function addProductBucketToWisersell($productBucket)
    {
        $this->getPimCategories();
        $productData = [];
        foreach ($productBucket as $product) {
            $category = $this->categoryList[$product->getInheritedField('productCategory')] ?? $this->categoryList['Diğer'];
            $productData[] = [
                "name" => $product->getInheritedField('name'),
                "code" => $product->getIwasku(),
                "categoryId" => $category->getWisersellCategoryId(),
                "weight" => $product->getInheritedField("packageWeight"),
                "width" => $product->getInheritedField("packageDimension1"),
                "length" => $product->getInheritedField("packageDimension2"),
                "height" => $product->getInheritedField("packageDimension3"),
                "extradata" => [
                    "Size" => $product->getVariationSize(),
                    "Color" => $product->getVariationColor()
                ],
                "subproducts" => []
            ];
        }
        $result = $this->addProduct($productData);
        foreach ($result as $response) {
            if (isset($response['id']) && isset($productBucket[$response['code']])) {
                $productBucket[$response['code']]->setWisersellId($response['id']);
                $productBucket[$response['code']]->setWisersellJson(json_encode($response));
                $productBucket[$response['code']]->save();
            }
        }
        echo "Added ".count($result)." products to Wisersell\n";
    }

    protected function prepareToken()
    {
        if (!empty($this->wisersellToken) && Utility::checkJwtTokenValidity($this->wisersellToken)) {
            return;
        }
        $token = $this->getAccessToken();
        $this->wisersellToken = $token;
        $this->httpClient = ScopingHttpClient::forBaseUri($this->httpClient, static::$apiServer, [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]); 
    }

    protected function getAccessToken()
    {
        $token = json_decode(Utility::getCustomCache('wisersell_access_token.json', PIMCORE_PROJECT_ROOT . '/tmp'), true);
        if (Utility::checkJwtTokenValidity($token['token'] ?? '')) {
            echo "Token valid\n";
            return $token['token'];
        }
        echo "Token file not found or empty or expired. Fetching new token...\n";
        return $this->fetchToken();
    }

    protected function fetchToken()
    {
        //$url = "https://dev2.wisersell.com/restapi/token"; 
        $client = HttpClient::create();
        $response = $client->request('POST', static::$apiServer.'token', [
            'json' => [
                "email" => static::$email,
                "password" => static::$password
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new Exception("Failed to get bearer token. HTTP Status Code: {$response->getContent()}");
        }
        $result = $response->toArray(); 
        if (empty($result['token'])) {
            throw new Exception("Failed to get bearer token. Response: " . json_encode($result));
        }
        Utility::setCustomCache('wisersell_access_token.json', PIMCORE_PROJECT_ROOT . '/tmp', json_encode($result));
        echo "New token saved to file.\n";
        return $result['token'];
    }

    protected function addProduct($data)
    {
        $result = $this->request(self::$apiUrl['product'], 'POST', '', $data);
        return $result->toArray();
    }

    protected function request($apiEndPoint, $type, $parameter, $json = [])
    {
        $this->prepareToken();
        $response = $this->httpClient->request($type, $apiEndPoint . $parameter, ['json' => $json]);
        if ($response->getStatusCode() !== 200) {
            echo "Failed to {$type} {$apiEndPoint}{$parameter}:".$response->getContent()."\n";
            return null;
        }
        usleep(2000000);
        return $response;
    }

    protected function getPimCategories()
    {
        $listingObject = new Category\Listing();
        $listingObject->setUnpublished(true);
        $categories = $listingObject->load();
        $this->categoryList = [];
        foreach ($categories as $category) {
            $this->categoryList[$category->getCategory()] = $category;
        }
        return $this->categoryList;
    }

    protected function getWisersellCategories()
    {
        $result = $this->request(self::$apiUrl['category'], 'GET', '');
        return $result->toArray(); // array of ['id', 'name']
    }

    protected function addCategoryToWisersell($category)
    {
        $result = $this->request(self::$apiUrl['category'], 'POST', '', [['name' => $category]]);
        return $result->toArray();
    }

    protected function getWisersellProduct($data)
    {
        $result = $this->request(self::$apiUrl['productSearch'], 'POST', '', $data);
        return $result->toArray();
    }

}
