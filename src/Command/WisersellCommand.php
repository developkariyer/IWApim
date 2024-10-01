<?php

namespace App\Command;

use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Pimcore\Model\DataObject\Product;
use App\Model\DataObject\VariantProduct;
use Pimcore\Model\DataObject\Category;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use App\Utils\Utility;
use Exception;


#[AsCommand(
    name: 'app:wisersell',
    description: 'connect wisersell api'
)]

class WisersellCommand extends AbstractCommand
{
   
    private $wisersellListings = [];
    private $iwapimListings = [];

    private static $apiServer = 'https://dev2.wisersell.com/restapi/';

    private static $apiUrl = [
        'productSearch' => 'product/search',
        'category' => 'category',
        'product'=> 'product',
    ];
    private $httpClient = null;

    public function __construct()
    {
        $parent = parent::__construct();
        $this->httpClient = HttpClient::create();
        $this->prepareToken();     
    }

    protected function configure() 
    {
        $this
            ->addOption('category', null, InputOption::VALUE_NONE, 'Category add wisersell')
            ->addOption('product', null, InputOption::VALUE_NONE, 'Product add wisersell')
            ->addOption('control', null, InputOption::VALUE_NONE, 'Control wisersell product')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('category')) {
            $this->addCategoryByIwapim();
        }
        if($input->getOption('product')){
            $this->addProductByIwapim();
        }
        if($input->getOption('control')){
            $this->controlWisersellProduct();
        }
        return Command::SUCCESS;
    }

    protected function prepareToken()
    {
        $token = $this->getAccessToken();
        $this->httpClient = ScopingHttpClient::forBaseUri($this->httpClient, static::$apiServer, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]); 
    }

    protected function getAccessToken()
    {
        $token = json_decode(Utility::getCustomCache('wisersell_access_token.json', PIMCORE_PROJECT_ROOT . '/tmp'), true);
        if (Utility::checkJwtTokenValidity($token['token'] ?? '')) {
            echo "Bearer Token: " . $token['token'] . "\n";
            return $token['token'];
        }
        echo "Token file not found or empty. Fetching new token...\n";
        return $this->fetchToken();
    }

    protected function fetchToken()
    {
        $url = "https://dev2.wisersell.com/restapi/token"; 
        $client = HttpClient::create();
        $response = $client->request('POST', $url, [
            'json' => [
                "email" => $_ENV['WISERSELL_DEV_USER'],
                "password" => $_ENV['WISERSELL_DEV_PASSWORD']
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
        echo "Bearer Token: " . $result['token'] . "\n";
        Utility::setCustomCache('wisersell_access_token.json', PIMCORE_PROJECT_ROOT . '/tmp', json_encode($result));
        echo "New token saved to file.\n";
        return $result['token'];
    }

    protected function request($apiEndPoint, $type, $parameter, $json = [])
    {
        $response = $this->httpClient->request($type, $apiEndPoint . $parameter,['json' => $json]);
        $statusCode = $response->getStatusCode();
        if ($response->getStatusCode() !== 200) {
            echo "Failed to {$type} {$apiEndPoint}{$parameter}:".$response->getContent()."\n";
            return null;
        }
        echo "{$apiEndPoint}{$parameter} ";
        return $response;
    }

    protected function productSearch($data)
    {
        $result = $this->request(self::$apiUrl['productSearch'], 'POST', '', $data);
        return $result->toArray();
    }

    protected function getCategories()
    {
        $result = $this->request(self::$apiUrl['category'], 'GET', '');
        return $result->toArray();
    }

    protected function addCategory($categories)
    {
        $data = array_map(function($category) {
            return ["name" => $category];
        }, $categories);
        $result = $this->request(self::$apiUrl['category'], 'POST', '', $data);
        return $result->toArray();
    }

    protected function addProduct($data)
    {
        print_r($data);
        $result = $this->request(self::$apiUrl['product'], 'POST', '', $data);
        return $result->toArray();
    }

    protected function productControl($key)
    {
        $searchData = [
            "code"=>$key,
            "page"=> 0,
            "pageSize"=> 10,
        ];
        $response = $this->productSearch($searchData);
        return $response;
    }

    protected function categoryControl($data)
    {
        $apiCategories = $this->getCategories();
        $apiCategoryMap = [];
        foreach ($apiCategories as $apiCategory) {
            $apiCategoryMap[$apiCategory["name"]] = $apiCategory["id"];
        }
        $listingObject = new Category\Listing();
        $categories = $listingObject->load(); 
        $pimcoreCategoryMap = [];
        foreach ($categories as $pimcoreCategory) {
            $pimcoreCategoryMap[$pimcoreCategory->getCategory()] = $pimcoreCategory;
        }
        $newCategories = [];
        foreach ($data as $categoryName) {
            if (isset($apiCategoryMap[$categoryName])) {
                $categoryId = $apiCategoryMap[$categoryName];
                if (isset($pimcoreCategoryMap[$categoryName])) {
                    $pimcoreCategory = $pimcoreCategoryMap[$categoryName];
                    $pimcoreCategory->setWisersellCategoryId($categoryId);
                    $pimcoreCategory->save();
                    echo "Category updated: " . $categoryName . "\n";
                }
            } else {
                echo "New Category Detected: $categoryName\n";
                $newCategories[] = $categoryName;
            }
        }
        return $newCategories;
    }

    protected function addCategoryByIwapim()
    {
        $this->prepareToken();
        $listingObject = new Category\Listing();
        $categories = $listingObject->load();
        $data = [];
        foreach ($categories as $category) {
            $data[] = $category->getCategory();
        }
        $newCategories = $this->categoryControl($data);    
        sleep(3);
        if(!empty($newCategories)){
            $result = $this->addCategory($newCategories);
            foreach ($result as $wisersellCategory) {
                foreach ($categories as $category) {
                    if ($category->getCategory() === $wisersellCategory['name']) {
                        $category->setWisersellCategoryId($wisersellCategory['id']);
                        $category->save();
                        echo "Category Saved: " . $category->getCategory() . "\n";
                        break;
                    }
                }
            }
        }    
    }

    protected function addProductByIwapim()
    {
        $this->prepareToken();
        $listingCategories = new Category\Listing();
        $listingCategories->setUnpublished(false);
        $categories = $listingCategories->load();
        $listingObject = new Product\Listing();
        $listingObject->setUnpublished(false);
        $listingObject->setCondition("iwasku IS NOT NULL AND iwasku != ? AND (wisersellId IS NULL OR wisersellId = ?)", ['', '']);
        $pageSize = 10;
        $offset = 0;
        while (true) {
            $listingObject->setLimit($pageSize);
            $listingObject->setOffset($offset);
            $products = $listingObject->load();
            if (empty($products)) {
                break;
            }
            echo "\nProcessed {$offset} ";
            $offset += $pageSize;
            foreach ($products as $product) {
                if ($product->level()!=1) continue;
                $iwasku = $product->getInheritedField("iwasku");
                sleep(3);
                $response = $this->productControl($iwasku);
                if($response['count']===0) {
                    $productName = $product->getInheritedField("name"); 
                    $categoryName = $product->getInheritedField("productCategory");
                    $categoryId = null;
                    foreach($categories as $category){
                        if($category->getCategory() == $categoryName){
                            $categoryId = $category->getWisersellCategoryId();
                            break;
                        }
                    }
                    if($categoryId==null) continue;
                    $variationSize = $product->getInheritedField("variationSize") ?? null;
                    $variationColor = $product->getInheritedField("variationColor") ?? null;
                    $width = $product->getInheritedField("packageDimension1") ?? null;
                    $length = $product->getInheritedField("packageDimension2") ?? null;
                    $height = $product->getInheritedField("packageDimension3") ?? null;
                    $weight = $product->getInheritedField("packageWeight") ?? null;
                    $extraData = [                        
                        "variationSize" => $variationSize,
                        "variationColor" => $variationColor
                    ];
                    $productData = [
                        [
                            "name" => $productName,
                            "code" => $iwasku,
                            "categoryId" => $categoryId,
                            "weight" => $weight,
                            "width" => $width,
                            "length" => $length,
                            "height" => $height,
                            "extradata"=> $extraData,
                            "subproducts" => []
                        ]
                    ];
                    sleep(2);
                    $result = $this->addProduct($productData);
                    if(isset($result[0]['id'])){
                        $wisersellId = $result[0]['id'];
                        try {
                            $product->setWisersellId($wisersellId); 
                            $product->setWisersellJson(json_encode($result));
                            $product->save();
                            echo "WisersellId updated successfully: " . $wisersellId;
                        } catch (Exception $e) {
                            echo "Error occurred while updating WisersellId: " . $e->getMessage();
                        }
                        echo "New Product added successfully\n";
                    } else {
                        echo "'id' field not found or is empty in the API response.";
                    }
                }
                else {
                    echo "\n\n\n!!!!!!!!!!!!!!UPDATED PRODUCT!!!!!!!!!!!!!!!!!!!!!!\n\n\n";
                    $wisersellId = $response['rows'][0]['id'];
                    try {
                        $product->setWisersellId($wisersellId); 
                        $product->setWisersellJson(json_encode($response));
                        $product->save();
                        echo "WisersellId updated successfully: " . $wisersellId;
                    } catch (Exception $e) {
                        echo "Error occurred while updating WisersellId: " . $e->getMessage();
                    }
                }
            }
        }
    }

    protected function downloadWisersellProduct($forceDownload = false)
    {
        $this->prepareToken();
        $filenamejson =  PIMCORE_PROJECT_ROOT. '/tmp/wisersell.json';
        if (!$forceDownload && file_exists($filenamejson) && filemtime($filenamejson) > time() - 86400) {
            $contentJson = file_get_contents($filenamejson);
            $this->wisersellListings = json_decode($contentJson, true);          
            echo "Using cached data ";
        }
        else {
            $this->wisersellListings = [];
            $page = 0;
            $pageSize = 100;
            $searchData = [
                "page" => $page,
                "pageSize" => $pageSize
            ];
            $response = $this->productSearch($searchData);
            sleep(2);
            $this->wisersellListings = $response['rows'];
            while ($response['count'] > 0) {
                $page++;
                $searchData = [
                    "page" => $page,
                    "pageSize" => $pageSize
                ];
                $response = $this->productSearch($searchData);
                sleep(2);
                $this->wisersellListings = array_merge($this->wisersellListings, $response['rows']);
                if(count($response['rows'])<$pageSize)
                    break;
            }  
        }
        $jsonListings = json_encode($this->wisersellListings);
        file_put_contents($filenamejson, $jsonListings);
        echo "count listings: ".count($this->wisersellListings)."\n";
    }

    protected function controlWisersellProduct()
    {
        $this->downloadWisersellProduct();
        $iwaskuControlArray = [];
        foreach ($this->wisersellListings as $listing) {
            echo "WID: {$listing['id']} => IWASKU: {$listing['code']}\n";
            if (empty($listing['code'])) {
                echo "Hata: '{$listing['id']}' Wisersel Id numarasina sahip urun code icermiyor.\n";
                continue;
            }
            if (isset($iwaskuControlArray[$listing['code']])) {
                echo "Hata: '{$listing['id']}' Wisersel Id numarasina sahip urun aynı zamanda {$iwaskuControlArray[$listing['code']]} code tekrar ediyor.\n";
                continue;
            }
            $iwaskuControlArray[$listing['code']] = $listing['id'];
            $product = Product::findByField('iwasku', $listing['code']);
            if (empty($product)) {
                echo "Hata: '{$listing['id']}' Wisersel Id numarasina sahip urun pimde yok.\n";
                continue;
            }
            echo "WID: {$listing['id']} => IWASKU: {$listing['code']} => Stored WID: {$product->getWisersellId()}\n";
            if ($product->getWisersellId() == $listing['id']) {
                continue;
            }
            $product->setWisersellId($listing['id']);
            $product->setWisersellJson(json_encode($listing));
            $product->save();
            echo "WisersellId updated successfully: " . $listing['id']."\n";            
        }
    }
    
}
