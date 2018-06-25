<?php
/**
 * This is a class to to insert a simple or a configurable product to cart
 * 
 * Also this class gives some functions for debugging and easier
 * programming.
 * 
 *  This class is made for configurable products with 
 * attribute 'package_unit'.
 *
 */

namespace MyModule\Lib\Model;

use Magento\Framework\Model\AbstractModel;

class Cart extends AbstractModel {

    /**
     * attribute_id for 'package_unit' in table `eav_attribute`
     *   
     * @var integer
     */
    private $attributeIdPackageUnit = 153;

    
    /**
     * Current store id
     * 
     * Default is 0
     * 
     * @var integer
     */
    private $stordeId = 0;

  

    /**
     * Current cart
     * 
     * @var \Magento\Checkout\Model\Cart
     */
    private $cart;

    
    /**
     * constructor
     * 
     */
    public function __construct() {

        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->stordeId = $this->objectManager->get('Magento\Store\Model\StoreManagerInterface')
                ->getStore()
                ->getId();
        $this->cart = $this->objectManager->get('\Magento\Checkout\Model\Cart');
    }

    
   
    /**
     * Get cart as an array of parent items and children as sub arrays
     * 
     * @return array
     */
    public function getCart() {

        // get parent items (or non configurable products)
        $items = $this->cart->getQuote()->getAllVisibleItems();

        if (empty($items)) {
            return [];
        }

        $cart = [];
        // set parents
        foreach ($items as $item) {
            $sku = $item->getSku();
            $cart[$sku]['parent'] = $item;
            $cart[$sku]['children'] = [];
        }

        $items = $this->cart->getQuote()->getAllItems();

        foreach ($items as $item) {
            $sku = $item->getSku();
            $parent = $cart[$sku]['parent'];
            if ($parent->getProductId() != $item->getProductId()) {
                $cart[$sku]['children'][] = $item;
            }
        }
        return $cart;
    }

    
    /**
     * Empty cart
     * 
     */
    public function emptyCart() {
        $cartItems = $this->getCart();
        // Empty cart
        foreach ($cartItems as $cartItem) {

            if (empty($cartItem['children'])) {
                // this is not a configurable product
                continue;
            }

            $cartParentItem = $cartItem['parent'];
            $cartChildItem = current($cartItem['children']);

            $cartParentItem->delete();
            $cartChildItem->delete();
        }
    }

    
     /**
     * Add a configurable product to cart
     * 
     * @param integer $childEntityId   entity id of the simple product option
     * @param integer $quantity
     */
    public function addToCartConfigurableProduct($childEntityId, $quantity) {

        $parentEntityId = $this->getParentIdOfAProduct($childEntityId);
  
        $product = $this->objectManager
                ->create('Magento\Catalog\Model\Product')
                ->setStoreId($this->stordeId)
                ->load($parentEntityId);
        
  
        $packageUnitCode = $this->getPackageUnitCode($childEntityId, $parentEntityId);

        $params = [
            "product" => $parentEntityId,
            "selected_configurable_option" => "",
            "related_product" => "",
            "super_attribute" => [
                $this->attributeIdPackageUnit => $packageUnitCode
            ],
            "qty" => $quantity,
            "product_page" => "true"
        ];
 
        $this->cart->addProduct($product, $params);
        
    }

    
    /**
     * Add a simple product to cart 
     * 
     * @param integer $entityId
     * @param integer $qty
     */
    public function addToCartSimpleProduct($entityId, $qty) {

        $params = array(
            'product' => $entityId,
            'qty' => $qty
        );

        $this->cart->addProduct($entityId, $params);

    }


    /**
     * Save cart after all updates
     * 
     */
    public function collectTotals() {
        $this->cart->getQuote()->collectTotals()->save();
    }
    
    
    /**
     * Get Parent Id Of A Product
     *  
     * @param integer $entityId
     * @return integer
     */
    private function getParentIdOfAProduct($entityId) {
        $parentProduct = $this->objectManager
                ->create('Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable')
                ->getParentIdsByChild($entityId);
   
        return (isset($parentProduct[0])) ? $parentProduct[0] : 0;
    }

    
    /**
     * Get package unit code for the given product
     * 
     * @param integer $childEntityId    entity id of the simple product option
     * @param integer $parentEntityId   entity id of the parent product 
     * 
     * @return integer|bool
     */
    private function getPackageUnitCode($childEntityId, $parentEntityId) {

        $product = $this->objectManager
                ->create('Magento\Catalog\Model\Product')
                ->setStoreId($this->stordeId)
                ->load($parentEntityId);

        $simples = $product->getTypeInstance()->getConfigurableOptions($product);

        foreach ($simples[$this->attributeIdPackageUnit] as $simple) {

            $productRepository = $this->objectManager->get('\Magento\Catalog\Model\ProductRepository');
            $simple_product = $productRepository->get($simple['sku']);
            if ($childEntityId == $simple_product->getId()) {
                $packageUnit = $simple_product->getData('package_unit');
                return $packageUnit;
            }
        }
        return false;
    }

    
    /**
     * Render cart item 
     * 
     * A function for debugging
     * 
     * @param MyModule\Quote\Model\Item $item
     */
    private function renderCartItem($item) {

        echo 'ID: ' . $item->getProductId() . '<br />';
        echo 'Name: ' . $item->getName() . '<br />';
        echo 'Sku: ' . $item->getSku() . '<br />';
        echo 'Entity ID: ' . $item->getProductId() . '<br />';
        echo 'Quantity: ' . $item->getQty() . '<br />';
        echo 'Price: ' . $item->getPrice() . '<br />';
        echo 'Created at: ' . $item->getCreatedAt() . '<br />';
    }

    
    /**
     * Render contents of current cart for debugging
     * 
     */
    public function debugCart() {
        
          $items = $this->getCart();  
          
          if (!empty($items)) {
              foreach ($items as $item) {
                  echo "<br />=============================== <br>";
                  echo "<br />parent:<br>";
                  $this->renderCartItem($item['parent']);
                  if (!empty($item['children'])) {
                      echo "<br />children:<br>";
                      foreach ($item['children']as $child) {
                           $this->renderCartItem($child);
                           echo "<br />------ <br>";
                      }
                  }
                  
              
              }
          }
    }
    

}
