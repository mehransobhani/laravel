<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Classes\DiscountCalculator;
use Illuminate\Support\Facades\Validator;
use stdClass;

class CategoryController extends Controller
{
    public function subCategories(Request $request){
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric',
        ]);
        if($validator->fails()){
            echo json_encode(array('status' => 'failed', 'source' => 'v', 'message' => 'argument validation failed', 'umessage' => 'مقادیر ورودی صحیح نیست'));
            exit();
        }

        if($request->id){
            $categories = Category::where('parentID', $request->id)->where('hide', 0);
            if($categories->count() !== 0){
                $categories = $categories->get();
                $subCategories = [];
                foreach($categories as $category){
                    array_push($subCategories, array('name' => $category->name, 'url' => $category->url, 'image' => $category->info->ads_image));
                }
                echo json_encode(array('status' => 'done', 'found' => true, 'message' => 'subcategories successfully were found', 'subcategories' => $subCategories));
            }else{
                echo json_encode(array('status' => 'done', 'found' => false, 'message' => 'this category does not have any category'));
            }
        }else{
            echo json_encode(array('status' => 'failed', 'message' => 'not enough parameter'));
        }
    }

    public function rootCategorySixNewProducts(Request $request){
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric',
        ]);
        if($validator->fails()){
            echo json_encode(array('status' => 'failed', 'source' => 'v', 'message' => 'argument validation failed', 'umessage' => 'مقادیر ورودی صحیح نیست'));
            exit();
        }

        if($request->id){
            $category = Category::where('id', $request->id);
            if($category->count() !== 0){
                $category = $category->first();
                $products = Product::where('url', 'LIKE', 'shop/product/category/' . $category->url . '%')->where(function($query){
                    return $query->where('prodStatus', 1)->orWhere('prodStatus', 2);
                })->where('stock', '>', 0)->orderBy('prodDate', 'DESC');
                if($products->count() !== 0){
                    $products = $products->get();
                    $response = [];
                    $itemsSelected = 0;
                    foreach($products as $product){
                        if($itemsSelected < 6){
                            $productCategory = $product->productCategory;
                            $category = Category::where('id', $productCategory->category)->first();
                            if($product->pack->status === 1 && $product->pack->stock > 0 && (($product->pack->count * $product->pack->stock) <= $product->stock)){
                                array_push($response, array('name' => $product->prodName_fa, 'category' => $category->name, 'url' => $product->url, 'prodID' => $product->prodID, 'price' => $product->pack->price));
                                $itemsSelected++;
                            }
                        }else{
                            break;
                        }
                    }
                    echo json_encode(array('status' => 'done', 'found' => true, 'message' => 'new products of the category successfully were found', 'products' => $response));
                }else{
                    echo json_encode(array('status' => 'done', 'found' => false, 'message' => 'this category does not have any product'));
                }
            }else{
                echo json_encode(array('status' => 'failed', 'message' => 'category not found'));
            }
        }else{
            echo json_encode(array('status' => 'failed', 'message' => 'not enough parameter'));
        }
    }

    public function filterPaginatedCategoryProducts (Request $request){
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric', 
            'order' => 'required|string'
        ]);
        if($validator->fails()){
            echo json_encode(array('status' => 'failed', 'source' => 'v', 'message' => 'argument validation failed', 'umessage' => 'مقادیر ورودی صحیح نیست'));
            exit();
        }

        if(isset($request->id) && isset($request->order)){
            $category = Category::where('id', $request->id);
            if($category->count() !== 0){
                $category = $category->first();
                $order = $request->order;
                $onlyAvailableProducts = $request->onlyAvailableProducts;
                //$having = " AND PL.stock > 0 AND PL.pack_stock > 0 AND PL.anbar_id = 1 AND P.prodStatus = 1 AND P.stock > 0 " ; //" AND PP.stock > 0 AND PP.status = 1 AND (PP.count * PP.stock <= P.stock)";
                //$finished = " AND P.prodStatus = 1 AND (PL.stock <=  0 OR PL.pack_stock <= 0 )";//  AND ((P.id NOT IN (SELECT DISTINCT PLL.product_id FROM products_location PLL ) OR (SELECT PLLL.id FROM products_location PLLL WHERE PLLL.product_id = P.id AND PLLL.pack_stock <> 0) IS NULL)) "; //"AND (P.stock = 0 OR PP.stock = 0 OR  PP.status = 0 OR (PP.count * PP.stock > P.stock))";
                $name = '';
                $minPrice = '';
                $maxPrice = '';
                if($request->minPrice != 0 && $request->minPrice != ''){
                    $minPrice = " AND PP.price >= $request->minPrice ";
                }
                if($request->maxPrice != 0 && $request->maxPrice != ''){
                    $maxPrice = " AND PP.price <= $request->maxPrice ";
                }
                if($request->searchInput != ''){
                    $name = " AND P.prodName_fa LIKE '%" . $request->searchInput . "%'";
                }
                
                $order = " ORDER BY P.prodDate DESC";
		$finishedOrder = $order;
                if($request->order == 'old'){
                    $order = " ORDER BY P.prodDate ASC";
                }else if($request->order == 'cheap'){
                    $order = " ORDER BY PP.price ASC, P.prodDate DESC";
		    $finishedOrder = " ORDER BY P.prodDate DESC ";
                }else if($request->order == 'expensive'){
                    $order = " ORDER BY PP.price DESC, P.prodDate DESC";
		    $finishedOrder = " ORDER BY P.prodDate DESC ";
                }
                
                $response = [];

		$having = " AND P.prodStatus = 1 AND PL.stock > 0 AND  PL.pack_stock > 0 AND PP.status = 1 AND (PP.count * PL.pack_stock <= PL.stock) ";
                $finished = " AND P.prodStatus = 1 AND ((P.id NOT IN (SELECT DISTINCT PLL.product_id FROM products_location PLL )) OR PL.stock = 0 OR PL.pack_stock = 0 ) "; //"AND (P.stock = 0 OR PP.stock = 0 OR  PP.status = 0 OR (PP.count * PP.stock > P.stock))";

		$having = " AND P.prodStatus = 1 AND PL.stock > 0 AND  PL.pack_stock > 0 AND PP.status = 1 ";
                $finished = " AND P.prodStatus = 1 AND (PL.stock <=  0 OR PL.pack_stock <= 0 ) "; 

                $queryHaving = "SELECT  P.id, PP.id AS packId, P.prodName_fa, P.prodID, P.url, P.prodStatus, P.prodUnite, P.stock AS productStock, PP.stock AS packStock, PP.status, PP.price, PP.base_price, PP.label, PP.count, PPC.category FROM products P INNER JOIN products_location PL ON P.id = PL.product_id INNER JOIN product_pack PP ON PL.pack_id = PP.id INNER JOIN product_category PPC ON PPC.product_id = P.id WHERE P.id IN (SELECT DISTINCT PC.product_id FROM product_category PC INNER JOIN category C ON PC.category = C.id
                    WHERE C.id = $request->id OR C.parentID = $request->id) AND PP.status = 1 AND PPC.kind = 'primary'" . $having . $name . $minPrice . $maxPrice . $order . "";
                
                $queryFinished = "SELECT  P.id, 0 AS packId, P.prodName_fa, P.prodID, P.url, P.prodStatus, P.prodUnite, 0 AS productStock, 0 AS packStock, 0 AS `status`, -1 AS price, 0 AS base_price, '' AS label, 0 AS `count`, PPC.category FROM products P INNER JOIN product_category PPC ON PPC.product_id = P.id INNER JOIN products_location PL ON P.id = PL.product_id WHERE P.id IN (SELECT DISTINCT PC.product_id FROM product_category PC INNER JOIN category C ON PC.category = C.id
                    WHERE C.id = $request->id OR C.parentID = $request->id ) and PPC.kind = 'primary' " . $finished . $name . $minPrice . $maxPrice . $finishedOrder . "";

		/*
                $queryHaving = "SELECT P.id, PP.id AS packId, P.prodName_fa, P.prodID, P.url, P.prodStatus, P.prodUnite, P.stock AS productStock, PP.stock AS packStock, PP.status, PP.price, PP.base_price, PP.label, PP.count, PPC.category FROM products P INNER JOIN product_category PPC ON PPC.product_id = P.id INNER JOIN products_location PL ON P.id = PL.product_id INNER JOIN product_pack PP ON PL.pack_id = PP.id WHERE P.id IN (SELECT DISTINCT PC.product_id FROM product_category PC INNER JOIN category C ON PC.category = C.id
                    WHERE C.id = $request->id OR C.parentID = $request->id) " . $having . $name . $minPrice . $maxPrice . " AND PPC.category = $request->id AND PP.status = 1 " . $order ; 
                $queryFinished = "SELECT P.id, 0 AS packId, P.prodName_fa, P.prodID, P.url, P.prodStatus, P.prodUnite, P.stock AS productStock, 0 AS packStock, 0 AS `status`, -1 AS price, 0 AS base_price, '' AS label, 0 AS `count`, PPC.category FROM products P INNER JOIN product_pack PP ON P.id = PP.product_id INNER JOIN product_category PPC ON PPC.product_id = P.id INNER JOIN products_location PL ON P.id = PL.product_id WHERE P.id IN (SELECT DISTINCT PC.product_id FROM product_category PC INNER JOIN category C ON PC.category = C.id  
                    WHERE C.id = $request->id OR C.parentID = $request->id ) " . $finished . $name . $minPrice . $maxPrice . " AND PPC.category = $request->id AND PP.status = 1 " . $order;
                */
		$havingProducts = DB::select($queryHaving);
                if($onlyAvailableProducts === 0){
                    $finishedProducts = DB::select($queryFinished);
                }else{
                    $finishedProducts = [];
                }
                $products = [];
                foreach($havingProducts as $hp){
                    if($hp->status == 1){
                        array_push($products, $hp);
                    }
                }
                foreach($finishedProducts as $fp){
                    array_push($products, $fp);
                }
                
                if(count($products) !== 0){
                    $allResponses = [];
                    $filters = $request->filters;
                    $distinctFiltersCount = 0;
                    $distinctFiltersArray = [];
                    foreach($filters as $filter){
                        $found = false;
                        foreach($distinctFiltersArray as $dfa){
                            if($dfa['en_name'] == $filter['en_name']){
                                $found = true;
                                break;
                            }
                        }
                        if($found === false){
                            array_push($distinctFiltersArray, $filter);
                        }
                    }
                    $distinctFiltersCount = count($distinctFiltersArray);
                    if(count($filters) !== 0){
                        foreach($products as $p){
                            $i = 0;
                            $productMetas = DB::select("SELECT * FROM products_meta WHERE product_id = $p->id");
                            if(count($productMetas) !== 0){
                                foreach($filters as $filter){
                                    foreach($productMetas as $pm){
                                        if($pm->key === '__' . $filter['en_name'] && $pm->value == $filter['value']){
                                            $i++;
                                        }
                                    }
                                }
                            }
                            if($i === $distinctFiltersCount){
                                $productObject = new stdClass();
                                $productObject->productId = $p->id;
                                $productObject->productPackId = $p->packId;
                                $productObject->productName = $p->prodName_fa;
                                $productObject->prodID = $p->prodID;
                                $productObject->categoryId = $p->category;
                                $productObject->productPrice = $p->price;
                                $productObject->productUrl = $p->url;
                                $productObject->productBasePrice = $p->base_price;
                                //$productObject->productCount = $value->count;
                                $productObject->productUnitCount = $p->count;
                                $productObject->productUnitName = $p->prodUnite;
                                $productObject->productLabel = $p->label;
                                array_push($allResponses, $productObject);
                            }
                        }
                        $r = array_slice($allResponses, ($request->page - 1)*12, 12);
                        $response = DiscountCalculator::calculateProductsDiscount($r);
                        echo json_encode(array('status' => 'done', 'found' => true, 'categoryName' => $category->name, 'count' => count($allResponses), 'products' => $response, 'message' => 'products are successfully found'));
                    }else{
                        foreach($products as $pr){
                            $productObject = new stdClass();
                            $productObject->productId = $pr->id;
                            $productObject->productPackId = $pr->packId;
                            $productObject->productName = $pr->prodName_fa;
                            $productObject->prodID = $pr->prodID;
                            $productObject->categoryId = $pr->category;
                            $productObject->productPrice = $pr->price;
                            $productObject->productUrl = $pr->url;
                            $productObject->productBasePrice = $pr->base_price;
                            //$productObject->productCount = $value->count;
                            $productObject->productUnitCount = $pr->count;
                            $productObject->productUnitName = $pr->prodUnite;
                            $productObject->productLabel = $pr->label;
                            array_push($allResponses, $productObject);
                        }
                        $r = array_slice($allResponses, ($request->page - 1)*12, 12);
                        $response = DiscountCalculator::calculateProductsDiscount($r);
                        echo json_encode(array('status' => 'done', 'found' => true, 'categoryName' => $category->name, 'count' => count($allResponses), 'products' => $response, 'message' => 'products are successfully found'));
                    }
                }else{
                    echo json_encode(array('status' => 'done',  'found' => false, 'message' => 'there is not any products available to show', 'categoryName' => $category->name, 'count' => 0, 'products' => []));
                }
            }else{
                echo json_encode((array('status' => 'failed', 'message' => 'category not found')));
            }
        }else{
            echo json_encode(array('status' => 'failed', 'message' => 'not enough parameter'));
        }
    }

    public function categoryFilters(Request $request){
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric',
        ]);
        if($validator->fails()){
            echo json_encode(array('status' => 'failed', 'source' => 'v', 'message' => 'argument validation failed', 'umessage' => 'مقادیر ورودی صحیح نیست'));
            exit();
        }

        if(isset($request->id)){
            $category = DB::select("SELECT feature_group_id FROM category WHERE id = $request->id LIMIT 1");
            if(count($category) !== 0){
                $category = $category[0];
                if($category->feature_group_id !== 0){
                    $featureGroupId = $category->feature_group_id;
                    $filterGroup = DB::select("SELECT * FROM product_feature_groups WHERE id = $featureGroupId");
                    if(count($filterGroup) !== 0){
                        $filterGroup = $filterGroup[0];
                        $featureIds = explode(',', $filterGroup->feature_ids);
                        $response = [];
                        foreach($featureIds as $featureId){
                            $feature = DB::select("SELECT * FROM product_features WHERE id = $featureId AND show_in_filter = 1");
                            if(count($feature) !== 0){
                                $feature = $feature[0];
                                array_push($response, array('id' => $feature->id, 'name' => $feature->name, 'type' => $feature->type, 'enName' => $feature->en_name, 'options' => $feature->options));
                            }
                        }
                        echo json_encode(array('status' => 'done', 'found' => true, 'message' => 'category filters is found', 'filters' => $response));
                    }else{
                        echo json_encode(array('status' => 'done', 'found' => false, 'message' => 'specified filter did not found'));
                    }
                }else{
                    echo json_encode(array('status' => 'done', 'found' => false, 'message' => 'this category does not have any filter'));
                }
            }else{
                echo json_encode(array('status' => 'failed', 'message' => 'category not found'));
            }
        }else{
            echo json_encode(array('status' => 'failed', 'message' => 'not enough parameter'));
        }
        /*##### I wrote this API but did not test it #####*/
    }

    public function categoryBreadCrumb(Request $request){
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric',
        ]);
        if($validator->fails()){
            echo json_encode(array('status' => 'failed', 'source' => 'v', 'message' => 'argument validation failed', 'umessage' => 'مقادیر ورودی صحیح نیست'));
            exit();
        }

        if(isset($request->id)){
            $categoryId = $request->id;
            $c = Category::where('id', $categoryId);
            if($c->count() !== 0){
                //$category = $category->first();
                //$categoryId = $category->cid;
                $categories = [];
                do{
                    $category = Category::where('id', $categoryId)->first();
                    array_push($categories, array('name' => $category->name, 'url' => $category->url));
                    $categoryId = $category->parentID;

                }while($categoryId !== 0);
                echo json_encode(array('status' => 'done', 'message' => 'categories successfully found', 'categories' => array_reverse($categories)));
            }else{
                echo json_encode(array('status' => 'failed', 'message' => 'category not found'));
            }
        }else{
            echo json_encode(array('status' => 'failed', 'message' => 'not enough parameter'));
        }
    }

    public function topSixBestSellerCategories(Request $request){
        $categories = DB::select(
            "SELECT COUNT(RESULT.categoryId) AS `count`, 
                RESULT.categoryId, 
                RESULT.categoryName, 
                RESULT.categoryUrl 
            FROM (
                SELECT  
                    C.id AS categoryId,
                    C.name AS categoryName, 
                    C.url AS categoryUrl 
                FROM product_stock PS 
                INNER JOIN product_category PC ON PS.product_id = PC.product_id 
                INNER JOIN category C ON PC.category = C.id 
                WHERE PS.kind IN (5, 6) 
                ORDER BY PS.id DESC   
                LIMIT 50 
                ) AS RESULT  
            GROUP BY RESULT.categoryId, RESULT.categoryName, RESULT.categoryUrl
            ORDER BY COUNT(RESULT.categoryId) DESC 
            LIMIT 6"
        );
        if(count($categories) === 0){
            echo json_encode(array('status' => 'done', 'found' => false, 'message' => 'top categories not found', 'categories' => []));
            exit();
        }
        foreach($categories as $category){
            $product = DB::select(
                "SELECT P.prodID 
                FROM products P 
                INNER JOIN product_category PC ON P.id = PC.product_id 
                WHERE P.stock > 0 AND 
                    P.prodStatus = 1 AND 
                    PC.category = $category->categoryId 
                ORDER BY P.id DESC 
                LIMIT 1"
            );
            if(count($product) === 0){
                $category->categoryImage = '';
            }else{
                $product = $product[0];
                $category->categoryImage = 'https://honari.com/image/resizeTest/shop/_200x/thumb_' . $product->prodID . '.jpg';
            }
        }
        echo json_encode(array('status' => 'done', 'found' => true, 'message' => 'categories successfully found', 'categories' => $categories));
    }
}
