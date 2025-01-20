<?php

namespace App\Controller;

use Doctrine\DBAL\Exception;
use Pimcore\Controller\FrontendController;
use Pimcore\Db;
use Pimcore\Model\DataObject\Marketplace;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ShopifyController extends FrontendController
{
    /**
     * @Route("/marketplace/shopify/{marketplaceId}", name="shopify_marketplace")
     * @throws Exception
     */
    public function shopifyAction(Request $request, $marketplaceId): JsonResponse
    {
        $marketplace = Marketplace::getById($marketplaceId);
        if (!$marketplace) {
            return new JsonResponse(['error' => 'Marketplace not found'], 404);
        }

        $db = Db::get();

        $variantProducts = $db->fetchAllAssociative("SELECT
    osv.id,
    osv.imageUrl,
    TRIM(BOTH '\"' FROM SUBSTRING(
        urlLink,
        LOCATE('https://', urlLink),
        LOCATE('\"', urlLink, LOCATE('https://', urlLink)) - LOCATE('https://', urlLink)
    )) AS extractedUrl,
    osv.title,
    osv.uniqueMarketplaceId,
    osv.salePrice,
    osv.saleCurrency,
    osv.quantity,
    osv.lastUpdate,
    osv.`key`
    orvp.dest_id 
FROM
    object_varyantproduct osv
JOIN
    object_relations_varyantproduct orvp
    ON osv.oo_id = orvp.src_id
    AND orvp.fieldname = 'marketplace'");
    //AND orvp.dest_id = ?;", [$marketplaceId]);

        if (empty($variantProducts)) {
            return new JsonResponse(['error' => 'No variant products found'], 404);
        }

        return new JsonResponse($variantProducts, 200);

    }

    /**
     * @Route("/marketplace/listing2product", name="listing2product")
     * @throws Exception
     */
    public function listing2productAction(Request $request): JsonResponse
    {
        $db = Db::get();

        $listingItems = $db->fetchAllAssociative("SELECT dest_id AS id, src_id AS productId FROM object_relations_product WHERE fieldname = 'listingItems'");

        if (empty($listingItems)) {
            return new JsonResponse(['error' => 'No listing items found'], 404);
        }

        return new JsonResponse($listingItems, 200);
    }

}


/*
        $variantProductIds = $db->fetchAllAssociative("SELECT
    osv.oo_id,
    osv.imageUrl,
 TRIM(BOTH '\"' FROM SUBSTRING(
        urlLink,
        LOCATE('https://', urlLink),
        LOCATE('\"', urlLink, LOCATE('https://', urlLink)) - LOCATE('https://', urlLink)
    )) AS extractedUrl,
    op.iwasku,
    op.productCategory,
    op.key
FROM
    object_store_varyantproduct osv
JOIN
    object_relations_varyantproduct orvp
    ON osv.oo_id = orvp.src_id
    AND orvp.fieldname = 'marketplace'
    AND orvp.dest_id = ?
LEFT JOIN
    object_relations_product orp
    ON orvp.src_id = orp.dest_id
    AND orp.fieldname = 'listingItems'
LEFT JOIN
    object_product op
    ON orp.src_id = op.oo_id;", [$marketplaceId]);
*/
