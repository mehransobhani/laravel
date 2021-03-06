<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Classes\DiscountCalculator;
use Exception;
use Illuminate\Support\Facades\Validator;
use stdClass;

class DeliveryServiceController extends Controller
{

    public static function findDeliveryServices($user, $provinceId, $cityId, $weight, $volume, $maxLength){
        $deliveryServicesInformation = [];
        $deliveryServices = DB::select("SELECT * FROM delivery_services WHERE status = 1");
        foreach($deliveryServices as $service){
            $deliveryServicePlans = DB::select("SELECT id 
                FROM delivery_service_plans 
                WHERE service_id = $service->id AND 
                (((city_id IS NULL AND province_id = $provinceId) OR (province_id = $provinceId)) OR (city_id = $cityId)) 
                LIMIT 1 ");
            if(count($deliveryServicePlans) == 0){
                continue;
            }

            $limitationAllowed = false;
            $limitation = DB::select("SELECT * FROM delivery_service_limitations WHERE service_id = $service->id LIMIT 1");

            if(count($limitation) == 0){
                $limitationAllowed = true;
            }else{
                $limitation = $limitation[0];
                if($limitation->max_weight == NULL || $weight <= $limitation->max_weight){
                    if($limitation->max_volume == NULL || $$volume <= $limitation->max_volume){
                        if($limitation->max_length == NULL || $maxLength <= $limitation->max_length){
                            $limitationAllowed = true;
                        }
                    }
                }
            }

            if($limitationAllowed === false){
                continue;
            }

            $userHasLocation = false;
            $locationAllowed = false;

            if($user->lat !== NULL && $user->lat !== 0 && $user->lat !== ''){
                if($user->lng !== NULL && $user->lng !== 0 && $user->lng !== ''){
                    $userHasLocation = true;
                }
            }

            if($userHasLocation === true && $service->location == 1 ){
                $locationAllowed = DeliveryServiceController::checkLocation($service->id, $user->lat, $user->lng);
            }else if($service->location == 0){
                $locationAllowed = true;
            }

            if($locationAllowed === false){
                continue;
            }

            $s = new stdClass();
            $s->id = $service->id;
            $s->ename = $service->name;
            $s->fname = $service->fa_name;
            $s->scheduling = $service->scheduling;
            
            array_push($deliveryServicesInformation, $s);
        }
        return $deliveryServicesInformation;
    }

    public static function calculateDeliveryServicesPrice($services, $provinceId, $cityId, $weight, $volume, $maxLength){
        foreach($services as $service){
            $deliveryServicePlans = DB::select(
                "SELECT * 
                FROM delivery_service_plans 
                WHERE service_id = $service->id AND 
                (((city_id IS NULL AND province_id = $provinceId) OR (province_id = $provinceId)) OR (city_id = $cityId)) 
                ORDER BY min_weight ASC" 
            );
            
            if(count($deliveryServicePlans) === 1){
                $deliveryServicePlans = $deliveryServicePlans[0];
                if($deliveryServicePlans->max_weight == null){
                    $service->price = $deliveryServicePlans->price;
                }
            }else{
                $calculated = false;
                foreach($deliveryServicePlans as $dsp){
                    if(!$calculated && $weight >= $dsp->min_weight && $weight <= $dsp->max_weight){
                        $calculated = true;
                        $service->price = $dsp->price;
                    }
                }
                if(!$calculated){
                    $lastPricePlan = $deliveryServicePlans[count($deliveryServicePlans) - 1];
                    $price = $lastPricePlan->price;
                    for($w1 = $lastPricePlan->max_weight, $w2 = $lastPricePlan->max_weight + 1000; $w1 < $w2 ;$w1 += 1000, $w2 += 1000){
                        $price += 2500;
                        if($w1 <= $weight && $weight < $w2){
                            $service->price = $price;
                            $calculated = true;
                            break;
                        }
                    }
                }
            }
        }
        return $services;
    }

    public static function calculateDeliveryPrice($serviceId, $provinceId, $cityId, $weight, $volume, $maxLength){
        $price = -1;
        $deliveryServicePlans = DB::select(
            "SELECT * 
            FROM delivery_service_plans 
            WHERE service_id = $serviceId AND 
            (((city_id IS NULL AND province_id = $provinceId) OR (province_id = $provinceId)) OR (city_id = $cityId)) 
            ORDER BY min_weight ASC" 
        );
        
        if(count($deliveryServicePlans) === 1){
            $dsps = $deliveryServicePlans[0];
            if($dsps->max_weight == null){
                $price = $dsps->price;
            }
        }else {
            $calculated = false;
            foreach($deliveryServicePlans as $dsp){
                if(!$calculated && $weight >= $dsp->min_weight && $weight <= $dsp->max_weight){
                    $calculated = true;
                    $price = $dsp->price;
                }
            }
            if(!$calculated){
                $lastPricePlan = $deliveryServicePlans[count($deliveryServicePlans) - 1];
                for($w1 = $lastPricePlan->max_weight, $w2 = $lastPricePlan->max_weight + 1000; $w1 < $w2 ;$w1 += 1000, $w2 += 1000){
                    $price += 2500;
                    if($w1 <= $weight && $weight < $w2){
                        $price = $price;
                        $calculated = true;
                        break;
                    }
                }
            }
        }

        return $price;
    }
        

    //@route: /api/user-delivery-options <--> @middleware: ApiAuthenticationMiddleware
    public function getAvailableDeliveryServices(Request $request){
        $userId = $request->userId;
        $user = DB::select("SELECT * FROM users WHERE id = $userId LIMIT 1");
        if(count($user) == 0){
            echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => 'user not found', 'umessage' => '?????????? ???????? ??????'));
            exit();
        }

        $user = $user[0];
        $provinceId = 0;
        $cityId = 0;

        $result = UserController::getProvinceId($user);
        if($result->successful){
            $provinceId = $result->provinceId;
        }else{
            echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => $result->message, 'umessage' => $result->umessage));
            exit();
        }

        $result = UserController::getCityId($user);
        if($result->successful){
            $cityId = $result->cityId;
        }else{
            echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => $result->message, 'umessage' => $result->umessage));
            exit();
        }

        $shoppingCart = DB::select(
            "SELECT products 
            FROM shoppingCarts
            WHERE user_id = $userId AND active = 1
            ORDER BY id DESC
            LIMIT 1"
        );
        if(count($shoppingCart) == 0){
            echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => 'there is not any available shopping cart', 'umessage' => '?????? ?????????? ???????? ??????'));
            exit();
        }
        $shoppingCart = $shoppingCart[0];
        $totalWeight = 0;
        $maxLength = 0;
        $totalVolume = 0;
        $totalCartPrice = 0;
        $shoppingCartProducts = json_decode($shoppingCart->products); 
        $productsInformation = [];
        foreach($shoppingCartProducts as $key => $value){
            $productInfo = DB::select(
                        "SELECT P.id, P.prodName_fa, P.prodStatus, P.prodID, P.prodPicture, P.stock AS prodStock, PC.category, 
                        PP.label, PP.count, PP.base_price, PP.price, PP.stock, PP.status, P.prodWeight AS weight, PI.length, PI.width, PI.height
                        FROM products P INNER JOIN product_pack PP ON P.id = PP.product_id INNER JOIN product_category PC ON P.id = PC.product_id 
                        INNER JOIN product_info PI ON P.id = PI.product_id 
                        WHERE PP.id = $key 
                        LIMIT 1" 
            );
            if(count($productInfo) == 0){
                continue;
            }
            $productInfo = $productInfo[0];
            if(
                $productInfo->status != 1 || 
                $productInfo->prodStatus != 1 ||
                $productInfo->stock <= 0 ||
                $productInfo->prodStock <= 0 ||
                ($productInfo->count * $productInfo->stock > $productInfo->prodStock)
            ){
                continue;
            }else{
                $totalCartPrice += $productInfo->price * $value->count;
                $pi = new stdClass;
                $pi->productId = $productInfo->id;
                $pi->categoryId = $productInfo->category;
                array_push($productsInformation,$pi);
                if($productInfo->weight !== NULL && $productInfo->weight >= 0){
                    $totalWeight += ($productInfo->weight * $value->count);
                }
                if($productInfo->width !== NULL && $productInfo->height !== NULL && $productInfo->length !== NULL){
                    $volume = $productInfo->width * $productInfo->height * $productInfo->length;
                    $totalVolume += $volume;
                    $max = $productInfo->length;
                    if($max < $productInfo->width){
                        $max = $productInfo->width;
                    }
                    if($max < $productInfo->height){
                        $max = $productInfo->height;
                    }
                    if($maxLength === 0){
                        $maxLength = $max;
                    }else if($max > $maxLength){
                        $maxLength = $max;
                    }
                }
            }
        }

        $deliveryOptions = $this->findDeliveryServices($user, $provinceId, $cityId, $totalWeight, $totalVolume, $maxLength);

        if(count($deliveryOptions) === 0){
            echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => 'there is not any available delivery options yet', 'options' => []));
            exit();
        }

        $deliveryOptions = $this->calculateDeliveryServicesPrice($deliveryOptions, $provinceId, $cityId, $totalWeight, $totalVolume, $maxLength);

        $deliveryOptions = DiscountCalculator::calculateDeliveryDiscount($userId, $provinceId, $totalCartPrice, $productsInformation, $deliveryOptions);

        echo json_encode(array('status' => 'done', 'message' => 'delivery options successfully found', 'options' => $deliveryOptions));
    }

    public static function checkLocationForTehranPeyk($lat, $lon){
        $locations = [[35.7398594,51.6230822],[35.7401032,51.6225243],[35.7463729,51.598835],[35.7526422,51.5966034],[35.7573786,51.5849304],
            [35.7664328,51.5873337],[35.78231,51.5366936],[35.7919531,51.530664],[35.7933107,51.5385818],[35.8027784,51.537466],[35.8164908,51.5341187],
            [35.8196227,51.5087128],[35.8163516,51.5009022],[35.8226849,51.4917183],[35.8193443,51.484766],[35.8276955,51.4683723],[35.8269996,51.4637375],
            [35.8205971,51.4539528],[35.8217802,51.4423656],[35.8259558,51.4263153],[35.8186135,51.4130545],[35.8112706,51.3830566],[35.8065026,51.3745594],
            [35.7996807,51.3845587],[35.7950164,51.3844299],[35.7937632,51.378293],[35.8042751,51.3617706],[35.8031265,51.3588095],[35.7966176,51.3558912],
            [35.7963391,51.3414717],[35.7895511,51.334734],[35.7853736,51.3070107],[35.7724916,51.2896729],[35.7759038,51.2722492],[35.7689399,51.2545681],
            [35.7680346,51.2405777],[35.7617666,51.2380028],[35.7635077,51.2141418],[35.7656667,51.197834],[35.757309,51.1948299],[35.7558463,51.1869335],
            [35.7589806,51.1727715],[35.7613487,51.1589527],[35.7534432,51.1274099],[35.7484279,51.1306715],[35.7458505,51.1275816],[35.7373861,51.1206722],
            [35.730837,51.1251783],[35.724357,51.1300278],[35.7169705,51.1398125],[35.712162,51.1522579],[35.7079804,51.1667633],[35.7035198,51.1835861],
            [35.6835835,51.2333679],[35.6783546,51.2310505],[35.6748685,51.2309647],[35.6713823,51.2319088],[35.665525,51.2360287],[35.6614107,51.2428093],
            [35.6501128,51.2735367],[35.6417429,51.2926769],[35.6323258,51.3111305],[35.6264657,51.3198853],[35.615093,51.3297558],[35.6080453,51.3444328],
            [35.6093712,51.3559341],[35.6118833,51.366148],[35.603649,51.3704395],[35.6038583,51.3901806],[35.6015205,51.4180756],[35.5841065,51.4216805],
            [35.5842461,51.4227533],[35.5838971,51.4245558],[35.5833038,51.4256716],[35.5822218,51.427002],[35.5805116,51.4369583],[35.5814191,51.4467001],
            [35.5848046,51.4494467],[35.5904583,51.4560127],[35.5928663,51.4562702],[35.5950299,51.4557123],[35.597019,51.4541245],[35.6102783,51.4558411],
            [35.6146046,51.4682865],[35.6181631,51.4713764],[35.6046957,51.4997005],[35.6116041,51.5058804],[35.6210935,51.5021038],[35.6287679,51.5076828],
            [35.6375577,51.5076828],[35.6372089,51.5023613],[35.6413244,51.5010738],[35.6443237,51.5019321],[35.6440447,51.506052],[35.6461372,51.5091419],
            [35.6748685,51.5084553],[35.6689418,51.5236902],[35.670441,51.525836],[35.6911125,51.5049791],[35.6935523,51.4952374],[35.6943191,51.4966106],
            [35.7073531,51.5155792],[35.7118484,51.5167809],[35.7153677,51.5117168],[35.7219878,51.5176392],[35.7216394,51.5289688],[35.7234511,51.5377665],
            [35.7221969,51.5481091],[35.7243918,51.553688],[35.7246705,51.5633869],[35.7223014,51.5706396],[35.7220575,51.5864754],[35.7237995,51.5960455],
            [35.7242176,51.6026115],[35.7278061,51.6138554],[35.7398594,51.6230822]];
    
        $inside = false;
        for ($i = 0, $j = count($locations) - 1; $i < count($locations); $j = $i++) {
            $xi = $locations[$i][0]; $yi = $locations[$i][1];
            $xj = $locations[$j][0]; $yj = $locations[$j][1];
            
            $intersect = (($yi > $lon) != ($yj > $lon))
                && ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi) + $xi);
            if ($intersect) $inside = !$inside;
        }
        
        return $inside;
    }

    public static function checkLocationForTaroffPeyk($lat, $lon){
        $locations = [[35.7398594,51.6230822],[35.7401032,51.6225243],[35.7463729,51.598835],[35.7526422,51.5966034],[35.7573786,51.5849304],
        [35.7664328,51.5873337],[35.78231,51.5366936],[35.7919531,51.530664],[35.7933107,51.5385818],[35.8027784,51.537466],[35.8164908,51.5341187],
        [35.8196227,51.5087128],[35.8163516,51.5009022],[35.8226849,51.4917183],[35.8193443,51.484766],[35.8276955,51.4683723],[35.8269996,51.4637375],
        [35.8205971,51.4539528],[35.8217802,51.4423656],[35.8259558,51.4263153],[35.8186135,51.4130545],[35.8112706,51.3830566],[35.8065026,51.3745594],
        [35.7996807,51.3845587],[35.7950164,51.3844299],[35.7937632,51.378293],[35.8042751,51.3617706],[35.8031265,51.3588095],[35.7966176,51.3558912],
        [35.7963391,51.3414717],[35.7895511,51.334734],[35.7853736,51.3070107],[35.7724916,51.2896729],[35.7759038,51.2722492],[35.7689399,51.2545681],
        [35.7680346,51.2405777],[35.7617666,51.2380028],[35.7635077,51.2141418],[35.7656667,51.197834],[35.757309,51.1948299],[35.7558463,51.1869335],
        [35.7589806,51.1727715],[35.7613487,51.1589527],[35.7534432,51.1274099],[35.7484279,51.1306715],[35.7458505,51.1275816],[35.7373861,51.1206722],
        [35.730837,51.1251783],[35.724357,51.1300278],[35.7169705,51.1398125],[35.712162,51.1522579],[35.7079804,51.1667633],[35.7035198,51.1835861],
        [35.6835835,51.2333679],[35.6783546,51.2310505],[35.6748685,51.2309647],[35.6713823,51.2319088],[35.665525,51.2360287],[35.6614107,51.2428093],
        [35.6501128,51.2735367],[35.6417429,51.2926769],[35.6323258,51.3111305],[35.6264657,51.3198853],[35.615093,51.3297558],[35.6080453,51.3444328],
        [35.6093712,51.3559341],[35.6118833,51.366148],[35.603649,51.3704395],[35.6038583,51.3901806],[35.6015205,51.4180756],[35.5841065,51.4216805],
        [35.5842461,51.4227533],[35.5838971,51.4245558],[35.5833038,51.4256716],[35.5822218,51.427002],[35.5805116,51.4369583],[35.5814191,51.4467001],
        [35.5848046,51.4494467],[35.5904583,51.4560127],[35.5928663,51.4562702],[35.5950299,51.4557123],[35.597019,51.4541245],[35.6102783,51.4558411],
        [35.6146046,51.4682865],[35.6181631,51.4713764],[35.6046957,51.4997005],[35.6116041,51.5058804],[35.6210935,51.5021038],[35.6287679,51.5076828],
        [35.6375577,51.5076828],[35.6372089,51.5023613],[35.6413244,51.5010738],[35.6443237,51.5019321],[35.6440447,51.506052],[35.6461372,51.5091419],
        [35.6748685,51.5084553],[35.6689418,51.5236902],[35.670441,51.525836],[35.6911125,51.5049791],[35.6935523,51.4952374],[35.6943191,51.4966106],
        [35.7073531,51.5155792],[35.7118484,51.5167809],[35.7153677,51.5117168],[35.7219878,51.5176392],[35.7216394,51.5289688],[35.7234511,51.5377665],
        [35.7221969,51.5481091],[35.7243918,51.553688],[35.7246705,51.5633869],[35.7223014,51.5706396],[35.7220575,51.5864754],[35.7237995,51.5960455],
        [35.7242176,51.6026115],[35.7278061,51.6138554],[35.7398594,51.6230822]];
    
        $inside = false;
        for ($i = 0, $j = count($locations) - 1; $i < count($locations); $j = $i++) {
            $xi = $locations[$i][0]; $yi = $locations[$i][1];
            $xj = $locations[$j][0]; $yj = $locations[$j][1];
            
            $intersect = (($yi > $lon) != ($yj > $lon))
                && ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi) + $xi);
            if ($intersect) $inside = !$inside;
        }
        
        return $inside;
    }

    public static function checkLocationForKarajPeyk ($lat, $lon){
        $locations = [[35.5885389,51.4569998],[35.5895161,51.4593601],[35.5868986,51.4613771],[35.5850838,51.4624929],[35.5692721,51.4722347],
        [35.5602304,51.4767838],[35.5586943,51.470089],[35.5516416,51.4357567],[35.5337629,51.4357567],[35.495897,51.4033127],[35.4931018,51.3864899],
        [35.4992512,51.3514709],[35.506518,51.3274384],[35.5126664,51.2388611],[35.4897474,51.2299347],[35.4819199,51.2072754],[35.4964561,51.2024689],
        [35.4925427,51.177063],[35.4668218,51.1489105],[35.4657033,51.1276245],[35.4863928,51.1104584],[35.4679403,51.0816193],[35.4550769,51.0507202],
        [35.4556363,51.0205078],[35.4394144,51.0198212],[35.4371767,50.9861755],[35.4517209,50.95047],[35.482479,50.896225],[35.5026052,50.8955383],
        [35.5221675,50.9195709],[35.5081949,50.9436035],[35.4841564,50.9593964],[35.5031642,51.0671997],[35.5316674,51.0932922],[35.5713316,51.0644531],
        [35.5819426,51.0280609],[35.6545066,51.0445404],[35.6539487,50.9751892],[35.6784941,50.9024048],[35.6879755,50.8708191],[35.6879755,50.8364868],
        [35.65897,50.8268738],[35.6667802,50.8007812],[35.6796096,50.7959747],[35.7058198,50.7657623],[35.7292345,50.7540894],[35.7448404,50.7801819],
        [35.7509704,50.84198],[35.7827277,50.7959747],[35.8684647,50.8289337],[35.8990626,50.7609558],[35.8896064,50.7479095],[35.9074055,50.7115173],
        [35.9396561,50.7019043],[35.960223,50.6318665],[35.9140791,50.6174469],[35.9101862,50.5879211],[35.9224203,50.5542755],[35.9552207,50.5487823],
        [36.0074508,50.6147003],[35.9835626,50.6813049],[36.023002,50.7025909],[36.0024516,50.7774353],[35.9424358,50.7534027],[35.9051808,50.8934784],
        [35.8868249,50.9772491],[35.8317316,51.0390472],[35.7766001,51.0490036],[35.7665721,51.0682297],[35.7297919,51.0836792],[35.7473482,51.1073685],
        [35.7531994,51.116209],[35.753687,51.1267662],[35.7484627,51.1307144],[35.7458853,51.1272812],[35.7373165,51.1205864],[35.7277016,51.1274529],
        [35.724357,51.1302853],[35.7207335,51.1348343],[35.7169705,51.1397266],[35.7146011,51.1459923],[35.712371,51.1520863],[35.7098621,51.1594677],
        [35.7080501,51.1665916],[35.6226982,51.2361145],[35.7036592,51.1834145],[35.6937614,51.2083054],[35.6835835,51.2331104],[35.6812131,51.232338],
        [35.6783546,51.2309647],[35.6769602,51.2310505],[35.6752172,51.2307072],[35.6731254,51.2313938],[35.671452,51.2319946],[35.665525,51.235857],
        [35.6614805,51.2427235],[35.6503918,51.273365],[35.6418824,51.2925053],[35.632256,51.3109589],[35.6263959,51.3198853],[35.615093,51.3295841],
        [35.6080453,51.3441753],[35.6093712,51.3555908],[35.6107668,51.3611698],[35.611953,51.3662338],[35.6038583,51.3704395],[35.604277,51.3899231],
        [35.6014856,51.4180756],[35.5841763,51.4216375],[35.5843159,51.4226675],[35.5838971,51.4245987],[35.5833387,51.4256287],[35.5823265,51.4269161],
        [35.5805116,51.4369154],[35.581454,51.4466572],[35.5848046,51.4494038],[35.590249,51.4558411]];

        $inside = false;
            for ($i = 0, $j = count($locations) - 1; $i < count($locations); $j = $i++) {
                $xi = $locations[$i][0]; $yi = $locations[$i][1];
                $xj = $locations[$j][0]; $yj = $locations[$j][1];
                
                $intersect = (($yi > $lon) != ($yj > $lon))
                    && ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi) + $xi);
                if ($intersect) $inside = !$inside;
            }
            
            return $inside;
    }

    public static function checkLocation($serviceId, $lat, $lon){
        $inside = false;
        $locations = [];
        if($serviceId == 11){
            $locations = [[35.7398594,51.6230822],[35.7401032,51.6225243],[35.7463729,51.598835],[35.7526422,51.5966034],[35.7573786,51.5849304],
            [35.7664328,51.5873337],[35.78231,51.5366936],[35.7919531,51.530664],[35.7933107,51.5385818],[35.8027784,51.537466],[35.8164908,51.5341187],
            [35.8196227,51.5087128],[35.8163516,51.5009022],[35.8226849,51.4917183],[35.8193443,51.484766],[35.8276955,51.4683723],[35.8269996,51.4637375],
            [35.8205971,51.4539528],[35.8217802,51.4423656],[35.8259558,51.4263153],[35.8186135,51.4130545],[35.8112706,51.3830566],[35.8065026,51.3745594],
            [35.7996807,51.3845587],[35.7950164,51.3844299],[35.7937632,51.378293],[35.8042751,51.3617706],[35.8031265,51.3588095],[35.7966176,51.3558912],
            [35.7963391,51.3414717],[35.7895511,51.334734],[35.7853736,51.3070107],[35.7724916,51.2896729],[35.7759038,51.2722492],[35.7689399,51.2545681],
            [35.7680346,51.2405777],[35.7617666,51.2380028],[35.7635077,51.2141418],[35.7656667,51.197834],[35.757309,51.1948299],[35.7558463,51.1869335],
            [35.7589806,51.1727715],[35.7613487,51.1589527],[35.7534432,51.1274099],[35.7484279,51.1306715],[35.7458505,51.1275816],[35.7373861,51.1206722],
            [35.730837,51.1251783],[35.724357,51.1300278],[35.7169705,51.1398125],[35.712162,51.1522579],[35.7079804,51.1667633],[35.7035198,51.1835861],
            [35.6835835,51.2333679],[35.6783546,51.2310505],[35.6748685,51.2309647],[35.6713823,51.2319088],[35.665525,51.2360287],[35.6614107,51.2428093],
            [35.6501128,51.2735367],[35.6417429,51.2926769],[35.6323258,51.3111305],[35.6264657,51.3198853],[35.615093,51.3297558],[35.6080453,51.3444328],
            [35.6093712,51.3559341],[35.6118833,51.366148],[35.603649,51.3704395],[35.6038583,51.3901806],[35.6015205,51.4180756],[35.5841065,51.4216805],
            [35.5842461,51.4227533],[35.5838971,51.4245558],[35.5833038,51.4256716],[35.5822218,51.427002],[35.5805116,51.4369583],[35.5814191,51.4467001],
            [35.5848046,51.4494467],[35.5904583,51.4560127],[35.5928663,51.4562702],[35.5950299,51.4557123],[35.597019,51.4541245],[35.6102783,51.4558411],
            [35.6146046,51.4682865],[35.6181631,51.4713764],[35.6046957,51.4997005],[35.6116041,51.5058804],[35.6210935,51.5021038],[35.6287679,51.5076828],
            [35.6375577,51.5076828],[35.6372089,51.5023613],[35.6413244,51.5010738],[35.6443237,51.5019321],[35.6440447,51.506052],[35.6461372,51.5091419],
            [35.6748685,51.5084553],[35.6689418,51.5236902],[35.670441,51.525836],[35.6911125,51.5049791],[35.6935523,51.4952374],[35.6943191,51.4966106],
            [35.7073531,51.5155792],[35.7118484,51.5167809],[35.7153677,51.5117168],[35.7219878,51.5176392],[35.7216394,51.5289688],[35.7234511,51.5377665],
            [35.7221969,51.5481091],[35.7243918,51.553688],[35.7246705,51.5633869],[35.7223014,51.5706396],[35.7220575,51.5864754],[35.7237995,51.5960455],
            [35.7242176,51.6026115],[35.7278061,51.6138554],[35.7398594,51.6230822]];
        }else if($serviceId == 12){
            $locations = [[35.5885389,51.4569998],[35.5895161,51.4593601],[35.5868986,51.4613771],[35.5850838,51.4624929],[35.5692721,51.4722347],
            [35.5602304,51.4767838],[35.5586943,51.470089],[35.5516416,51.4357567],[35.5337629,51.4357567],[35.495897,51.4033127],[35.4931018,51.3864899],
            [35.4992512,51.3514709],[35.506518,51.3274384],[35.5126664,51.2388611],[35.4897474,51.2299347],[35.4819199,51.2072754],[35.4964561,51.2024689],
            [35.4925427,51.177063],[35.4668218,51.1489105],[35.4657033,51.1276245],[35.4863928,51.1104584],[35.4679403,51.0816193],[35.4550769,51.0507202],
            [35.4556363,51.0205078],[35.4394144,51.0198212],[35.4371767,50.9861755],[35.4517209,50.95047],[35.482479,50.896225],[35.5026052,50.8955383],
            [35.5221675,50.9195709],[35.5081949,50.9436035],[35.4841564,50.9593964],[35.5031642,51.0671997],[35.5316674,51.0932922],[35.5713316,51.0644531],
            [35.5819426,51.0280609],[35.6545066,51.0445404],[35.6539487,50.9751892],[35.6784941,50.9024048],[35.6879755,50.8708191],[35.6879755,50.8364868],
            [35.65897,50.8268738],[35.6667802,50.8007812],[35.6796096,50.7959747],[35.7058198,50.7657623],[35.7292345,50.7540894],[35.7448404,50.7801819],
            [35.7509704,50.84198],[35.7827277,50.7959747],[35.8684647,50.8289337],[35.8990626,50.7609558],[35.8896064,50.7479095],[35.9074055,50.7115173],
            [35.9396561,50.7019043],[35.960223,50.6318665],[35.9140791,50.6174469],[35.9101862,50.5879211],[35.9224203,50.5542755],[35.9552207,50.5487823],
            [36.0074508,50.6147003],[35.9835626,50.6813049],[36.023002,50.7025909],[36.0024516,50.7774353],[35.9424358,50.7534027],[35.9051808,50.8934784],
            [35.8868249,50.9772491],[35.8317316,51.0390472],[35.7766001,51.0490036],[35.7665721,51.0682297],[35.7297919,51.0836792],[35.7473482,51.1073685],
            [35.7531994,51.116209],[35.753687,51.1267662],[35.7484627,51.1307144],[35.7458853,51.1272812],[35.7373165,51.1205864],[35.7277016,51.1274529],
            [35.724357,51.1302853],[35.7207335,51.1348343],[35.7169705,51.1397266],[35.7146011,51.1459923],[35.712371,51.1520863],[35.7098621,51.1594677],
            [35.7080501,51.1665916],[35.6226982,51.2361145],[35.7036592,51.1834145],[35.6937614,51.2083054],[35.6835835,51.2331104],[35.6812131,51.232338],
            [35.6783546,51.2309647],[35.6769602,51.2310505],[35.6752172,51.2307072],[35.6731254,51.2313938],[35.671452,51.2319946],[35.665525,51.235857],
            [35.6614805,51.2427235],[35.6503918,51.273365],[35.6418824,51.2925053],[35.632256,51.3109589],[35.6263959,51.3198853],[35.615093,51.3295841],
            [35.6080453,51.3441753],[35.6093712,51.3555908],[35.6107668,51.3611698],[35.611953,51.3662338],[35.6038583,51.3704395],[35.604277,51.3899231],
            [35.6014856,51.4180756],[35.5841763,51.4216375],[35.5843159,51.4226675],[35.5838971,51.4245987],[35.5833387,51.4256287],[35.5823265,51.4269161],
            [35.5805116,51.4369154],[35.581454,51.4466572],[35.5848046,51.4494038],[35.590249,51.4558411]];
        }else if($serviceId == 15){
            $locations = [
            [35.7678951,51.1938072],[35.7696217,51.2508766],[35.7690983,51.2621231],[35.7768422,51.2689225],[35.7785644,51.2967065],[35.7862084,51.3059033],
            [35.7918831,51.3196825],[35.7892818,51.3315677],[35.7904095,51.3377407],[35.7972048,51.3422042],[35.7968226,51.3528663],[35.8053393,51.3601513],
            [35.8005088,51.3666233],[35.7956501,51.3780257],[35.8051731,51.3734974],[35.8085076,51.3763131],[35.8096307,51.3822736],[35.8119363,51.3859429],
            [35.8150924,51.3888987],[35.8148724,51.3942111],[35.8158327,51.4042854],[35.8200465,51.4213542],[35.8205242,51.4247632],[35.8245370,51.4247153],
            [35.8252353,51.4267225],[35.8251624,51.4390798],[35.8235752,51.4475890],[35.8212835,51.4522707],[35.8179348,51.4468593],[35.8170015,51.4508088],
            [35.8235183,51.4639101],[35.8208972,51.4720379],[35.8226567,51.4769215],[35.8205653,51.4876017],[35.8207740,51.4947366],[35.8128140,51.5054528],
            [35.8163383,51.5112794],[35.8097724,51.5399374],[35.7980263,51.5412266],[35.7923463,51.5336019],[35.7842613,51.5329275],[53.7765867,51.5371536],
            [35.7698325,51.5570288],[35.7597939,51.5723240],[35.7568246,51.5754009],[35.7524246,51.5784346],[35.7481890,51.5876056],[35.7481078,51.5942195],
            [35.7437563,51.6065785],[35.7373184,51.6030338],[35.7238774,51.5862926],[35.7220007,51.5736922],[35.7244328,51.5612418],[35.7240748,51.5530939],
            [35.7222018,51.5464808],[35.7234481,51.5379022],[35.7217835,51.5292289],[35.7219163,51.5212397],[35.7210138,51.5143673],[35.7166909,51.5133534],
            [35.7090358,51.5164255],[35.7044312,51.5117984],[35.7025784,51.5029122],[35.7126645,51.5054546],[35.6843513,51.4925738],[35.6804406,51.5227764],
            [35.6637648,51.5340136],[35.6626461,51.5091079],[35.6564673,51.5097168],[35.6451761,51.5033783],[35.6374860,51.5138455],[35.6227425,51.5163575],
            [35.6202458,51.4973011],[35.6065173,51.5087552],[35.6045656,51.5004295],[35.6189728,51.4713057],[35.6148969,51.4691988],[35.6114911,51.4578996],
            [35.5979379,51.4541232],[35.5935481,51.4564696],[35.5904347,51.4559604],[35.5805346,51.4456986],[35.5773170,51.4382641],[35.5774398,51.4325575],
            [35.5835408,51.4259596],[35.5843774,51.4232982],[35.5843161,51.4215050],[35.6152161,51.4151430],[35.6139743,51.3749884],[35.6076254,51.3485511],
            [35.6101370,51.3361830],[35.6282584,51.3182995],[35.6346999,51.3074997],[35.6502165,51.2736295],[35.6625932,51.2400457],[35.6655133,51.2359081],
            [35.6706070,51.2319622],[35.6776560,51.2314911],[35.6839684,51.2333214],[35.6978600,51.2484719],[35.7083325,51.2531366],[35.7147300,51.2522359],
            [35.7152947,51.2424655],[35.7198986,51.2388526],[35.7260538,51.2393629],[35.7280461,51.2355969],[35.7289567,51.2313109],[35.7297121,51.2200515],
            [35.7355458,51.2163590],[35.7399676,51.2103184],[35.7508945,51.2052536],[35.7510400,51.2007221],[35.7503063,51.1998899],[35.7678951,51.1938072]];
        }else{
            return false;
        }

        for ($i = 0, $j = count($locations) - 1; $i < count($locations); $j = $i++) {
            $xi = $locations[$i][0]; $yi = $locations[$i][1];
            $xj = $locations[$j][0]; $yj = $locations[$j][1];
            
            $intersect = (($yi > $lon) != ($yj > $lon))
                && ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi) + $xi);
            if ($intersect) $inside = !$inside;
        }
        
        return $inside;
    }

    //@route: /api/user-delivery-service-work-times <--> @middleware: ApiAuthenticationMiddleware
    public function getDeliveryServiceWorkTimes (Request $request){
        $validator = Validator::make($request->all(), [
            'deliveryServiceId' => 'required|numeric',
        ]);
        if($validator->fails()){
            echo json_encode(array('status' => 'failed', 'source' => 'v', 'message' => 'argument validation failed', 'umessage' => '?????? ???? ???????????? ???????????? ??????????'));
            exit();
        }

        $deliveryServiceId = $request->deliveryServiceId;
        if($deliveryServiceId != 11 && $deliveryServiceId != 12 && $deliveryServiceId != 15){
            echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => 'wrong delivery service', 'umessage' => '?????????? ?????????? ?????????????? ?????? ??????'));
            exit();
        }
        $allTimes = DB::select("SELECT WT.id, WT.day, WT.interval_id, WT.type_house, WT.max_item_count, WT.expire_time, WT.label FROM work_times WT INNER JOIN work_time_interval WTI ON WT.interval_id = WTI.id ORDER BY WT.day ASC, WT.type_house ASC");
        if(count($allTimes) === 0){
            echo json_encode(array('status' => 'failed', 'message' => 'not interval found', 'umessage' => '???????? ?????????? ?????????? ???????? ??????????'));
            exit();
        }
        $currentTime = time();
        $currentHour = intval(jdate('G', $currentTime ,'', '', 'en'));
        $currentMinute = intval(jdate('i', $currentTime, '', '', 'en'));
        $currentSecond = intval(jdate('s', $currentTime, '', '', 'en'));
        $currentDayOfWeek = intval(jdate('w', $currentTime, '', '', 'en'));
        $start = $currentTime - ($currentSecond + (60 * $currentMinute) + (3600 * $currentHour));
        $start -= ($currentDayOfWeek * 86400);
//        $start += 86400;
        //echo 'start : ' . $start . ' and start : ' . jdate('Y-m-d H:i:s', $start);
	$foundDatesInformation = [];
        $found = false;
        for($k = 0; $k <= 2; $k++){
            if($found){
                break;
            }
            for($a=0; $a<sizeof($allTimes); $a++){
                $wt = $start + (($k * 7* 86400) + ($allTimes[$a]->day * 86400) + $allTimes[$a]->type_house);
                //if($wt >= 1647894600){
                //    $wt -= 3600;
                //}
                $wm = $allTimes[$a]->max_item_count;
                $we = $allTimes[$a]->expire_time;
                if(jdate('m', $wt, '', '', 'en') == '12' && jdate('d', $wt, '', '', 'en') == '29'){
                    continue;
                }
                $wds = jdate('o/m/d', $wt, '', '', 'en');
                $whs = jdate('G:i', $wt, '', '', 'en');
                if($wt - $we > $currentTime){
                    $freeDayResult = DB::select("SELECT id FROM free_day WHERE date = '$wds' AND time = '$whs'");
                    if(count($freeDayResult) === 0){
                        $count = DB::select("SELECT COUNT(post_time) AS c FROM order_info WHERE post_time = $wt");
                        if(count($count) === 0 || $count[0]->c < $wm){
                            $obj = new stdClass();
                            $obj->timestamp = $wt;
                            $obj->worktimeId = $allTimes[$a]->id;
                            $obj->date = $wds;
                            $obj->time = $whs;
                            $obj->day = jdate('l', $wt);
                            $obj->label = $allTimes[$a]->label;
                            array_push($foundDatesInformation, $obj);
                            if(count($foundDatesInformation) === 3){
                                $found = true;
                                break;
                            }
                        }
                    }
                }
            }
        }
        if(count($foundDatesInformation) === 0){
            echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => 'could not find any available work time', 'umessasge' => '?????? ???????? ???????? ?????????? ???????? ??????'));
            exit();
        }
        echo json_encode(array('status' => 'done', 'messsage' => 'successfully found available work times', 'workTimes' => $foundDatesInformation));
    }

    //@route: /api/user-set-delivery-service-temporary-information <--> @middleware: ApiAuthenticationMiddleware
    public function setDeliveryServiceTemporaryInformation(Request $request){
        $validator = Validator::make($request->all(), [
            'serviceId' => 'required|numeric', 
            'workTime' => 'required|numeric', 
            'workTimeId' => 'required|numeric', 
        ]);
        if($validator->fails()){
            echo json_encode(array('status' => 'failed', 'source' => 'v', 'message' => 'argument validation failed', 'umessage' => '?????? ???? ???????????? ???????????? ??????????'));
            exit();
        }

        $userId = $request->userId;
        $serviceId = $request->serviceId;
        $workTime = $request->workTime;
        $workTimeId = $request->workTimeId;
        $time = time();
        $activeTemporaryInformation = DB::select(
            "SELECT * 
            FROM delivery_service_temporary_information 
            WHERE user_id = $userId AND expiration_date > $time"
        );
        if(count($activeTemporaryInformation) === 0){
            $expirationDate = $time + (30 * 60);
            $insertResult = DB::insert("INSERT INTO delivery_service_temporary_information
                (user_id, service_id, work_time, work_time_id, expiration_date)
                VALUES ($userId, $serviceId, $workTime, $workTimeId, $expirationDate)"
            );
            if(!$insertResult){
                echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => 'error while inserting the new values', 'umessage' => '?????? ?????????? ?????????? ???????? ?????????????? ????????'));
                exit();
            }
            echo json_encode(array('status' => 'done', 'message' => 'new values successfully inserted'));
        }else{
            $activeTemporaryInformation = $activeTemporaryInformation[0];
            $expirationDate = $time + (30 * 60);
            $updateResult = DB::update(
                "UPDATE delivery_service_temporary_information
                SET service_id = $serviceId, work_time = $workTime, work_time_id = $workTimeId, expiration_date = $expirationDate
                WHERE id = $activeTemporaryInformation->id"
            );
            //if(!$updateResult){
            //    echo json_encode(array('status' => 'failed', 'source' => 'c', 'message' => 'error while updating the previous values', 'umessage' => '?????? ???????????? ???????????? ?????????????? ????????'));
            //    exit();
            //}
            echo json_encode(array('status' => 'done', 'message' => 'current information successfully updated'));
        }
    } 

    //@route: /api/user-check-temporary-delivery-info-existance <--> @middleware: ApiAuthenticationMiddleware
    public function checkTemporaryDeliveryServiceInformationExistance(Request $request) {
        $userId = $request->userId;
        $currentTime = time();
        $availableSelectedService = DB::select(
            "SELECT * 
            FROM delivery_service_temporary_information
            WHERE user_id = $userId AND expiration_date > $currentTime
            ORDER BY expiration_date DESC LIMIT 1"
        );
        if(count($availableSelectedService) === 0){
            echo json_encode(array('status' => 'done', 'found' => false, 'message' => 'user have not chosen a delivery service recently'));
            exit();
        }
        $availableSelectedService = $availableSelectedService[0];
        if(($availableSelectedService->service_id == 12 ||$availableSelectedService->service_id == 12) && $availableSelectedService->work_time == 0){
            echo json_encode(array('status' => 'done', 'found' => false, 'message' => 'user have not chosen a work time for the selected delivery service'));
            exit();
        }
        echo json_encode(array('status' => 'done', 'found' => true, 'message' => 'user have chosen a delivery service recently'));
    }
}
