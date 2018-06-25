<?php

/**
 * A class to handle requests to Api server
 * 
 * This class makes direct requests to an external
 * server to get data in real time.
 * For example we need to query the order status
 * from the external server, because it is handled there.
 * 
 */

namespace MyModule\ApiClient\Model;

use Magento\Cron\Exception;
use Magento\Framework\Model\AbstractModel;
use Zend\Form\FormAbstractServiceFactory;
use Magento\Backend\Test\Block\Widget\FormTabs;
use League\CLImate\Decorator\Component\Format;
use Magento\Customer\Model\Session;

class ApiRequest extends AbstractModel {

    private $orderSortTypes = [
        'open' => 'openstaand',
        'history' => 'historie'
    ];
    
    public function __construct(\Magento\Customer\Model\Session $customerSession) {
        $this->customerSession = $customerSession;
        
    }    

    /**
     * Path to read configuration variables
     * 
     * from MyModule/ApiClient/etc/config.xml
     * 
     * @var string
     */
    private $configPath = 'MyModule_ApiClient/apiConfiguration/';
    

    /**
     * Read configuration variable form  MyModule/ProductDiscount/etc/config.xml
     *  
     * @param strong $name
     * @return unknown
     */
    private function getConfigVariable($name) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $scopeConfig = $objectManager->create('\Magento\Framework\App\Config\ScopeConfigInterface');
        return $scopeConfig->getValue($this->configPath . $name, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
    

    /**
     * Do request to Api API
     * 
     * @param unknown $uri			Request details
     * @return \stdClass | bool
     */
    private function doRequest($uri) {

        // cache duplicate requests
        $hash = sha1($uri);
        
        if (!empty($this->customerSession->getApiCalls())) {
            $calls = $this->customerSession->getApiCalls();
            if (isset($calls[$hash])) {
                file_put_contents("/tmp/api.log", "CACHED CALL: " . $uri . "\n", FILE_APPEND);
                return $calls[$hash];
            }
        } else {
            $calls = array();
        }
        
        file_put_contents("/tmp/api.log", "NEW CALL: " . $uri . "\n", FILE_APPEND);

 
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $url = $this->getConfigVariable('url');
        $username = $this->getConfigVariable('username');
        $password = $this->getConfigVariable('password');
        $timeOutSeconds = $this->getConfigVariable('time_out_seconds');


        $context = stream_context_create(array(
            'http' => array(
                'header' => "Authorization: Basic " . base64_encode("$username:$password"),
                'timeout' => $timeOutSeconds// set time out if Api does not respond
            )
        ));

        $url .= $uri;

        $data = @file_get_contents($url, false, $context);


        if ($data === false) {

            return false;
        }

        $dataObj = json_decode($data);
        
        $calls[$hash] = $dataObj;
        $this->customerSession->setApiCalls($calls);        

        return $dataObj;
    }
    
    
    /**
     * Check if Api  server is up.
     * 
     * Save results to cache to some seconds avoid too many requests
     * 
     * 
     * @return boolean
     */
    public function isApiServerUp() {

        $checkPeriod = 30; // seconds
        // Check if status found in the cache
        if (!empty($this->customerSession->getIsApiUp())) {
            $isApiServerUp = $this->customerSession->getIsApiUp();
            $parts = explode(':', $isApiServerUp);

            if (time() < (int) end($parts)) {
                return ($parts[0] == 'true') ? true : false;
            }
        }

        // Check if api is up and save to cache
        $url = $this->getConfigVariable('url');

        $username = $this->getConfigVariable('username');
        $password = $this->getConfigVariable('password');
        $timeOutSeconds = $this->getConfigVariable('time_out_seconds');


        $context = stream_context_create(array(
            'http' => array(
                'header' => "Authorization: Basic " . base64_encode("$username:$password"),
                'timeout' => 5// set time out if Api does not respond
            )
        ));

        $data = @file_get_contents($url, false, $context);

        $isApiServerUp = ($data === false) ? 'false' : 'true';
        $expires = time() + $checkPeriod;
        $this->customerSession->setIsApiUp($isApiServerUp . ':' . $expires);
       

        return $isApiServerUp;
    }

    
    /**
     * Get orders
     * 
     * Here we get orders of the client from the API server
     * 
     * @param string    $clientId
     * @param array     $options        'getDetails': get details (order rows)
     *                                  'type':  'open' or 'history'
     *                                  'limit':  'array ['start','end']
     *                                  'last' return last x items 
     * @return array
     */
    public function getOrders($clientId, $options = []) {
      
        $request = 'GetOrders?api_customer_id=' . $clientId;
        if (isset($options['type'])) {
            $request .= '&type=' . $this->orderSortTypes[$options['type']];
        }

        $data = $this->doRequest($request);

        if (!$data) {
            return [];
        }

    

        if (isset($options['limit'])) {
            $limit = $options['limit'];
            $data->subfile = array_slice($data->subfile, $limit['start'], $limit['end']);
        }

        if (isset($options['end'])) {
            $length = count($data->subfile);
            $end = $options['end'];
            $start = $length - end;
            $data->subfile = array_slice($data->subfile, $start, $end);
        }

        if (isset($options['getDetails'])) {

            array_walk(
                    $data->subfile, function (&$apiOrder) use ($clientId, &$totalPrice, &$status) {

                $apiOrder->details = $this->getOrderDetails(
                        $clientId, $apiOrder->api_order
                );

                $apiOrder->status = $apiOrder->details->status;
                $apiOrder->totalPrice = $apiOrder->details->totalPrice;
                $apiOrder->totalShippingCosts = $apiOrder->details->totalShippingCosts;
            }
            );
        }

        return ($data) ? $data->subfile : [];
    }
    

    /**
     * Get order details
     * 
     * @param string $clientId
     * @param string $apiOrderId
     * @return array
     */
    public function getOrderDetails($clientId, $apiOrderId) {

        $request = 'GetOrderDetails?api_customer_id=' . $clientId .
                '&api_order=' . $apiOrderId;
        $data = $this->doRequest($request);

        $data->totalPrice = $this->getOrderTotalPrice($data->subfile);
        $data->totalShippingCosts = $this->getOrderTotalShippinCosts($data->subfile);
        $data->status = $this->getOrderStatus($data->subfile);

        return ($data) ? $data : [];
    }

    /**
     * Get total price of an order
     * 
     * @param array $orderLines
     * @return int
     */
    private function getOrderTotalPrice($orderLines) {

        $totalPrice = 0;
        if (!$orderLines) {
            return 0;
        }

        foreach ($orderLines as $orderRow) {
            $totalPrice += $orderRow->price;
        }

        return $totalPrice;
    }

    /**
     * Get status  of an order
     * 
     * @param array $orderLines
     * @return string
     */
    private function getOrderStatus($orderLines) {

        $status = [];
        foreach ($orderLines as $orderRow) {
            $status[] = $orderRow->api_orderline_status;
        }
        return implode(', ', array_unique($status));
    }

    
  

 

    /**
     * 
     * @param sting $clientId 	Api id of the client
     * @param array $products		Producst data needed for the request, if empty grab the contents of your cart
     * 									[
     * 										sku1 => ['qty' => x, 'pu' => 'y'],
     * 										sku2 => ['qty' => x, 'pu' => 'y'],
     * 									]
     * @param array $addedProductData   The product you need, even if it it not available in your current cart
     * @return array 				all found discounts in Format
     * 									[
     * 											'sku1' => x,
     * 											'sku2' => y
     * 									]
     */
    public function getOrderDiscount($clientId, $products = array(), $addedProductData = array()) {
        if(!$products) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $cart = $objectManager->get('\Magento\Checkout\Model\Cart');
            $items = $cart->getQuote()->getAllVisibleItems();
            
            foreach($items as $item) {
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();    
                    $productRepository = $objectManager->get('\Magento\Catalog\Model\ProductRepository');
                    $product = $productRepository->get($item->getProduct()->getSku());            
                    $apisku = explode('-->', $product->getApiSku());
                    $pqty = (!empty($product->getPackageQuantity()) ? $product->getPackageQuantity() : 1);

                    $products[$item->getSku()] = array(
                        'qty' => $item->getData('qty'),
                        'sku' => $apisku[0],
                        'pu' => (isset($apisku[1]) ? $apisku[1] : "0001"),
                        'pqty' => $pqty
                    );
                }
        }
        
        //If addedproductdata is not set in the existing products array, add it
        if($addedProductData && !isset($products[$addedProductData['full_sku']])) {            
            $products[$addedProductData['full_sku']] = $addedProductData;
        }

        $discounts = [];
        if (!empty($products)) {
            $url = 'GetItemInfo?api_customer_id=' . $clientId . '&piARRLEN=' . count($products);
            $index = 1;
            foreach ($products as $sku => $values) {

                if(!isset($values['pqty']) && isset($values['package_quantity'])) {
                    $values['pqty'] = $values['package_quantity'];
                } else {
                     $values['pqty']  = 1;
                }

                if(!isset($values['pu'])) {
                    $explodedSku = explode('-', $values['sku']);

                    if(count($explodedSku) > 1) {
                        $values['sku'] = $explodedSku[0];
                        $values['pu'] = $explodedSku[1];
                    }
                }
                
                $url .= '&sku_' . $index . '=' . $values['sku'] .
                        '&order_qty_' . $index . '=' . $values['qty'] * $values['pqty'] .
                        '&pu_' . $index . '=' . $values['pu'];
                $index ++;
            }

            $dataObj = $this->doRequest($url);

            if (isset($dataObj->subfile)) {
                array_walk(
                        $dataObj->subfile, function($product) use (&$discounts) {
                    $pricePerPiece = $product->price;
                    $pieces = $product->order_qty;
                    $totalAmount = 0;

                    if ($pieces && $pricePerPiece) {
                        $totalAmount = ($pieces * $pricePerPiece);
                    }

                    $discounts[$product->sku] = 0;
                    if ($totalAmount && $product->line_amt) {
                        // define discount and round it to 2 decimals
                        $discountedAmount = ($totalAmount * (1 - ((double) $product->discount_per2 / 100)));//Price minus SEPA discount
                        
                        $discount = ($totalAmount - $discountedAmount);
                        $discount = number_format((float) $discount, 2, '.', '');
                        
                        $totalDiscount = ($totalAmount - $product->line_amt);
                        $totalDiscount = number_format((float) $totalDiscount, 2, '.', '');

                        $discounts[$product->sku] = array(
                            'amount' => $discount,
                            'display_amount' => $totalDiscount,
                            'quantity' => $product->order_qty,
                            'discount_percentage' => ($product->discount_perc),
                            'discount_percentage2' => ($product->discount_per2),
                            'price' => $product->price * $product->package_qty
                        );
                    }
                }
                );
            }
        }

        return $discounts;
    }
    
    /*
     * @deprecated We cannot do this? Product prices are based on the contents of your whole cart
     */
    public function getProductPrices($clientId, $products) {
        return $this->getOrderDiscount($clientId, $products);
    }
    
    /*
     * Because product prices change based on the contents of the whole order, this is using the getOrderDiscount function indirectly
     * We pass the current product data as an extra parameter, to make sure the product price we request here is included in the order
     * Otherwise it will crash if you fetch the price of a product which is not in your cart
     */
    public function getProductPrice($clientId, $productData) {
        $sku = $productData['sku'];
        
        $explodedSku = explode('-', $sku);
        
        $apiSku = $explodedSku[0];
        $packageUnit = $explodedSku[1];
        
        $orderProductData = array(
            'full_sku' => $sku,
            'sku' => $apiSku,
            'pu' => $packageUnit,
            'pqty' => (isset($productData['package_quantity']) ? $productData['package_quantity'] : 1),
            'qty' => (isset($productData['qty']) ? $productData['qty'] : 1)
        );
        
        $discounts = $this->getOrderDiscount($clientId, array(), $orderProductData);

        if(!isset($discounts[$apiSku]) || !isset($discounts[$apiSku]['quantity'])) {
            return false;
        }
        
        $amount_including_discount = ($discounts[$apiSku]['price'] - ($discounts[$apiSku]['display_amount'] / $discounts[$apiSku]['quantity']));

        return $amount_including_discount;
    }

}





