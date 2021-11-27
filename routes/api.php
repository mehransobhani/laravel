<?php

use App\Http\Controllers\BannerController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DeliveryServiceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\ApiAuthenticationMiddleware;
use App\Http\Middleware\Cors;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPack;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

//header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
//header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, Authorization, Accept,charset,boundary,Content-Length');
//header('Access-Control-Allow-Origin: *');


Route::post('login', 'API\UserController@login');
Route::post('register', 'API\UserController@register');

Route::group(['middleware' => 'auth:api'], function(){
    Route::post('details', 'API\UserController@details');
});


Route::get('/get-banners', function () {
    $first = Banner::where('isActive', 1)->where('isBanner', 3)->where('_order', 1)->where(function($query){
        return $query->where(function($query){
            return $query->where('start_date', 0)->where('end_date', 0);
        })->orWhere(function($query){
            $time = time();
            return $query->where('start_date', '<=', $time)->where('end_date', '>=', $time);
        });
    })->orderBy('date', 'DESC');
    $second= Banner::where('isActive', 1)->where('isBanner', 3)->where('_order', 2)->where(function($query){
        return $query->where(function($query){
            return $query->where('start_date', 0)->where('end_date', 0);
        })->orWhere(function($query){
            $time = time();
            return $query->where('start_date', '<=', $time)->where('end_date', '>=', $time);
        });
    })->orderBy('date', 'DESC');
    $third = Banner::where('isActive', 1)->where('isBanner', 3)->where('_order', 3)->where(function($query){
        return $query->where(function($query){
            return $query->where('start_date', 0)->where('end_date', 0);
        })->orWhere(function($query){
            $time = time();
            return $query->where('start_date', '<=', $time)->where('end_date', '>=', $time);
        });
    })->orderBy('date', 'DESC');

    $firstBannerImage = null;
    $firstBannerAnchor = null;
    $secondBannerImage = null;
    $secondBannerAnchor = null;
    $thirdBannerImage = null;
    $thirdBannerAnchor = null;

    if($first->count() !== 0){
        $first = $first->first();
        $firstBannerImage = $first->img;
        $firstBannerAnchor = $first->anchor;
    }
    
    if($second->count() !== 0){
        $second = $second->first();
        $secondBannerImage = $second->img;
        $secondBannerAnchor = $second->anchor;
    }
    
    if($third->count() !== 0){
        $third = $third->first();
        $thirdBannerImage = $third->img;
        $thirdBannerAnchor = $third->anchor;
    }
    echo json_encode(array('status' => 'done', 'banners' => array('firstImage' => $firstBannerImage, 'firstAnchor' => $firstBannerAnchor, 'secondImage' => $secondBannerImage, 'secondAnchor' => $secondBannerAnchor, 'thirdImage' => $thirdBannerImage, 'thirdAnchor' => $thirdBannerAnchor)));
});

Route::get('/get-six-new-products', function(){
    $products = Product::where('prodStatus', 1)->orderBy('prodDate', 'DESC')->take(6);
    if($products->count() !== 0){
        $products = $products->get();
        $prods = [];
        foreach($products as $product){
            $categoryName = '';
            $pack = ProductPack::where('product_id', $product->id)->first();
            $categoryLink = ProductCategory::where('product_id', $product->id);
            if($categoryLink->count() !== 0){
                $categoryLink = $categoryLink->first();
                $category = Category::where('id', $categoryLink->category);
                if($category->count() !== 0){
                    $category = $category->first();
                    $categoryName = $category->name;
                }else{
                    $categoryName = null;
                }
            }else{
                $categoryName = null;
            }
            array_push($prods, array('id' => $product->id, 'prodID' => $product->prodID, 'name' => $product->prodName_fa, 'categoryName' => $categoryName, 'url' => $product->url, 'price' => $pack->price));
        }
        echo json_encode(array('status' => 'done', 'products' => $prods));
    }else{
        echo json_encode(array('status' => 'failed'));
    }
});

    Route::post('/route-info', [HomeController::class, 'routeInfo']);


    /*##### Product routes #####*/
    Route::post('/product-basic-information', [ProductController::class, 'productBasicInformation']);
    Route::post('/product-description', [ProductController::class, 'productDescription']);
    Route::post('/product-features', [ProductController::class, 'productFeatures']);
    Route::post('/product-breadcrumb', [ProductController::class, 'productBreadcrumb']);
    Route::post('/similar-products', [ProductController::class, 'similarProducts']);
    Route::post('/top-six-bestseller-similar-products', [ProductController::class, 'topSixBestsellerSimilarProducts']);

    /*##### Category routes #####*/
    Route::post('/subcategories', [CategoryController::class, 'subCategories']);
    Route::post('/root-category-six-new-products', [CategoryController::class, 'rootCategorySixNewProducts']);
    Route::post('/filtered-paginated-category-products', [CategoryController::class, 'filterPaginatedcategoryProducts']);
    Route::post('/category-filters', [CategoryController::class, 'categoryFilters']);
    Route::post('/category-breadcrumb', [CategoryController::class, 'categoryBreadcrumb']);

    /*##### Banner routes #####*/
    Route::post('/category-banners', [BannerController::class, 'categoryBanners']);

    /*##### Menu routes ###*/
    Route::get('/menu', [MenuController::class, 'menuItemsInformation']); // OK!

    /*##### User routes  #####*/
    Route::middleware([ApiAuthenticationMiddleware::class])->group(function () {
        Route::post('/user-information',                [UserController::class,             'userInformation']); // OK!
        Route::post('/user-cart',                       [CartController::class,             'userCart']); // OK!
        Route::post('/user-increase-cart-by-one',       [CartController::class,             'increaseCartByOne']); // OK!
        Route::post('/user-decrease-cart-by-one',       [CartController::class,             'decreaseCartByOne']); // OK!
        Route::post('/user-add-to-cart',                [CartController::class,             'addToCart']); // OK!
        Route::post('/user-remove-from-cart',           [CartController::class,             'removeFromCart']); // OK!
        Route::post('/user-wipe-cart',                  [CartController::class,             'wipeCart']); // OK!
        Route::post('/user-cart-raw',                   [CartController::class,             'userCartRaw']); // TO BE TESTED ...
        Route::post('/user-all-return-requests',        [ReturnController::class,           'userAllReturnRequests']); // TEST
        Route::post('/user-accepted-return-requests',   [ReturnController::class,           'userAcceptedReturnRequests']); // TEST
        Route::post('/user-pending-return-requests',    [ReturnController::class,           'userPendingReturnRequests']); // TEST
        Route::post('/user-considered-return-requests', [ReturnController::class,           'userConsideredReturnRequests']); // is seems that is has authentication problem
        Route::post('/user-orders-history',             [OrderController::class,            'getUserOrdersHistory']); // OK!
        Route::post('/user-order-details',              [OrderController::class,            'getOrderDetails']); // OK!
        Route::post('/user-balance',                    [WalletController::class,           'userBalance']); // OK!
        Route::post('/user-withdrawal-history',         [WalletController::class,           'userWithdrawalHistory']); // OK!
        Route::post('/user-last-withdrawal-request',    [WalletController::class,           'userLastWithdrawalRequest']); // OK!
        Route::post('/user-add-withdrawal-request',     [WalletController::class,           'addUserWithdrawalRequest']); // OK!
        Route::post('/user-check-address',              [UserController::class,             'checkUserAddress']); //OK!
        Route::post('/user-delivery-options',           [DeliveryServiceController::class,  'getAvailableDeliveryServices']); //------------
        Route::post('/user-delivery-service-work-times',[DeliveryServiceController::class,  'getDeliveryServiceWorkTimes']); // TO BE TESTE !
    });

    /*##### Guest Based Routes #####*/
    Route::post('/guest-cart',                          [CartController::class,             'guestCart']); // OK!
    Route::post('/guest-add-to-cart',                   [CartController::class,             'guestAddToCart']); // TO BE TESTED!
    Route::post('/guest-check-cart-changes',            [CartController::class,             'checkGuestCartChanges']); // ****** I HAVE TO WORKD ON THIS FIRST ******
    //Route::post('/cpd', [CategoryController::class, 'calculateProductsDiscount']);