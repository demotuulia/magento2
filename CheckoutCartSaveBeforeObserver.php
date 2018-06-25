<?php
/**
 * This observer is called before saving the cart
 * 
 * We use it to check if a configurable option has been changed
 * 
 */

namespace MyModule\CartOption\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

class CheckoutCartSaveBeforeObserver implements ObserverInterface {

    /**
     * @var \Magento\Framework\View\LayoutInterface
     */
    protected $_layout;

    /**
     * Store manager
     * 
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * Request
     * 
     * @var  \Magento\Framework\App\RequestInterface  
     */
    protected $_request;

    /**
     * Object manager 
     * 
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

  

    /**
     * constructor 
     * 
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\View\LayoutInterface $layout
     */
    public function __construct(
    \Magento\Store\Model\StoreManagerInterface $storeManager, \Magento\Framework\View\LayoutInterface $layout, \Magento\Framework\App\RequestInterface $request
    ) {
        $this->_layout = $layout;
        $this->_storeManager = $storeManager;
        $this->_request = $request;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    }

    /**
     * Here we do a check if an option of a package option is
     * changed in cart page. If so change the product.
     *
     * @param EventObserver $observer
     * @return void
     */
    public function execute(EventObserver $observer) {

        // If no option selections posted we don't do the check
        $postStr = json_encode($this->_request->getParams());
        if (strpos($postStr, 'cartOptionSelect') !== false) {
            $this->checkChangedOptions();
        }   
    }

    
    /**
     * Check if the package options has been changed on the cart page
     * 
     */
    private function checkChangedOptions() {

        $repository = $this->objectManager->create('Magento\Catalog\Model\ProductRepository');
        $post = $this->_request->getParams();
        $nineyardsCart = $this->objectManager->create('MyModule\Lib\Model\Cart');
        $cartItems = $nineyardsCart->getCart();

        $cartItemsCopy = $cartItems;

        $nineyardsCart->emptyCart();

        $renderOutOfStockError = false;
        // Loop to check if package unit has been changed 
        // Fill the cart again in the same order. If pacakge unit
        // changed add item with the changed entity id.
        foreach ($cartItemsCopy as $cartItem) {

            if (empty($cartItem['children'])) {
                // this is not a configurable product
                continue;
            }

            $cartParentItem = $cartItem['parent'];
            $cartChildItem = current($cartItem['children']);

            $parentCartItemEntityId = $cartParentItem->getProductId();
            $childCartItemEntityId = $cartChildItem->getProductId();

            $productInCart = $repository->getById($parentCartItemEntityId);
            $quantity = $cartParentItem->getQty();

            $optionsList = $this->getConfigrableOptions($productInCart);

            $attributeCode = 'package_unit';
            $postVarKey = 'cartOptionSelect_' . $parentCartItemEntityId . '_' . $attributeCode;

            $EntityIdToAdd = $childCartItemEntityId;

            // check if products options for 'package_unit'
            if (isset($optionsList[$attributeCode]) && isset($post[$postVarKey])) {

                $options = $optionsList[$attributeCode];

                $selectedOptioLabel = $post[$postVarKey];
                $selectedOptionEntityId = $options[$selectedOptioLabel];

                // If option has been changed, change the product
                if ($selectedOptionEntityId != $childCartItemEntityId) {
                    if ($this->isInStock($selectedOptionEntityId)) {
                        $EntityIdToAdd = $selectedOptionEntityId;
                    } else {
                        $renderOutOfStockError = true;
                    }
                }
            }

            $this->addConfigurableProduct($EntityIdToAdd, $quantity);
        }

        if ($renderOutOfStockError) {
            $this->renderOutOfStockError();
        }
      
    }

    /**
     * Is product in stock
     * 
     * @param integer $entityId
     * @return boolean
     */
    private function isInStock($entityId) {
 
        $data = $this->objectManager
                ->get('\Magento\Catalog\Model\ProductRepository')
                ->getById($entityId)
                ->getData();
        
        $isInStock = $this->objectManager
            ->create('MyModule\Lib\Model\IsInStock')
            ->availability($entityId, $data['package_quantity']);
      
        return (bool)($isInStock);
        
    }

    
    /**
     * Render out of stock error
     * 
     * This is used as Ajax response to send error message.
     */
    private function renderOutOfStockError() {
        die ('OUT_OF_STOCK');
    }

    
    /**
     * addConfigurableProduct
     * 
     * @param type $entityId
     * @param type $quantity
     */
    private function addConfigurableProduct($childEntityId,$quantity) {
        $nineyardsCart = $this->objectManager->create('MyModule\Lib\Model\Cart');
        $nineyardsCart->addToCartConfigurableProduct($childEntityId, $quantity);
        $nineyardsCart->collectTotals();
    }

   

    /**
     * Get configurable options of a product
     * 
     * @param Model\Catalog\Product\Interceptor $product   
     * 
     * @return array
     */
    private function getConfigrableOptions($product) {

       
        // Check if product has options
        $method = 'getTypeInstance';
        $methodVariable = array($product,);
        $typeInstance = $product->getTypeInstance();
        $method = 'getUsedProducts';
        $methodVariable = array($typeInstance);
        $hasOptions = method_exists($typeInstance, $method);


        $data = [];
        if ($hasOptions) {
            $children = $product->getTypeInstance()->getUsedProducts($product);
            $attributes = $product->getTypeInstance()->getConfigurableAttributes($product);

            foreach ($attributes as $attribute) {
                $coll = $this->objectManager->create(\Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection::class);
                $coll->addFieldToFilter('attribute_id', $attribute->getData('attribute_id'));
                $attribute = $coll->load()->getFirstItem();
                $attributeCode = $attribute->getAttributeCode();

                $option = array();
                foreach ($children as $child) {
                    $optionTitle = $child->getAttributeText($attributeCode);
                    $productId = $child->getId();
                    $option[$optionTitle] = $productId;
                }
                $data[$attributeCode] = $option;
            }
        }

        return $data;
    }

}
