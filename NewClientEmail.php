<?php

/**
 *  Class to make password and send email to new clients
 *
 */

namespace MyModule\NewClient\Model;

use Magento\Framework\Model\AbstractModel;
use \Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Data\Test\Unit\Collection\DbCollection;

class NewClientEmail extends AbstractModel {

    /**
     * Database connection 
     * 
     * @var Magento\Framework\DB\Adapter\Pdo\Mysq
     */
    private $dbConnection = false;

    
    /**
     * objectManager
     * 
     * @var Magento\Framework\ObjectManager\ObjectManager
     */
    private $objectManager;
    

    /**
     * Path to read configuration variables
     *
     * from MyModule/NewClient/etc/config.xml
     *
     * @var string
     */
    private $configPath = 'MyModule_NewClient/';

    
    /**
     * site urls 
     * 
     * @var array
     */
    private $siteUrls = [];
    

    /**
     * Uri to combine with $siteUrls to get full login link
     * 
     */
    const LOGIN_URI = 'customer/account/login/';

    
    /**
     * Id of is_welcome_mail_sent in table 'eav_attribute'
     * 
     * @var integrer
     */
    private $isWelcomeMailSentAttributeId = false;
    

    /**
     * Log object
     * 
     * @var MyModule\NewClient\Model\Log 
     */
    private $log;
    
    
    /**
     * constructor
     * 
     */
    public function __construct() {

        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->log = $this->objectManager->get('MyModule\NewClient\Model\Log');
        $this->log->set( 'Starting sending emails to new clients');
        
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnectionByName('default');
        
        $this->dbConnection = $connection;
        $this->getWebSiteurls();
        
    }

    /**
     * Main function to start the send process
     * 
     */
    public function send() {
        $localeInterface = $this->objectManager->create('Magento\Framework\Locale\ResolverInterface');

        $clients = $this->getNewClients();

        // make reset passwords and make emails
        array_walk(
                $clients, function ($item) {

                    $password = $this->generateRandomPassword();
                    $entityId = $item['entity_id'];
                    $this->log->set('Handling client with entity id ' . $entityId);
                    $this->updatePassword($entityId, $password);
                    $this->sendMail($entityId, $password);
                    $this->markEmailSent($entityId);
                    $this->log->set('Client with entity id ' . $entityId . 'done');
        }
        );
        $this->log->set('End sending emails to new clients');
    }

    
    /**
     * Get new clients who should get  login data by mail
     * 
     * @return array
     */
    private function getNewClients() {

        $attributeId = $this->getIsWelcomeMailSentAttributeId();
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        // Get clients who should get login

        $tableName = $resource->getTableName('customer_entity_int');

        $sql = $this->dbConnection->select($tableName)
                ->from(['customer_entity_int'], ['entity_id'])
                ->where('attribute_id = ?', $attributeId);

        $result = $this->dbConnection->fetchAll($sql);
        $this->log->set( 'Found clients' . print_r($result, true));
        
        return $result;
    }

    
    /**
     * Get Id of is_welcome_mail_sent in table 'eav_attribute'
     * 
     * @return integer
     */
    private function getIsWelcomeMailSentAttributeId() {

        if (!$this->isWelcomeMailSentAttributeId) {
            $connection = $this->dbConnection;
            $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');

            // get attrinute id for 'is_welcome_mail_sent'
            $tableName = $resource->getTableName('eav_attribute');
            $sql = $connection->select()
                    ->from([$tableName], ['attribute_id'])
                    ->where('attribute_code = ?', 'is_welcome_mail_sent');
            $result = $connection->fetchAll($sql);
            $this->isWelcomeMailSentAttributeId = $result[0]['attribute_id'];
        }

        return $this->isWelcomeMailSentAttributeId;
    }

    
    /**
     * Get web site urls and them to $this->siteUrls
     */
    private function getWebSiteurls() {
        $connection = $this->dbConnection;
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $tableName = $resource->getTableName('store');

        $sql = $connection->select()
                ->from([$tableName], ['store_id']);

        $result = $connection->fetchAll($sql);
        array_walk(
                $result, function ($item) {
                    $siteId = $item['store_id'];
                    $this->siteUrls[$siteId] = $this->objectManager
                            ->get('Magento\Store\Model\StoreManagerInterface')
                            ->getStore($siteId)
                            ->getBaseUrl();
            }
        );
    }

    /**
     *  Update the password
     *  
     * @param integer $entityId
     * @param string $password
     */
    private function updatePassword($entityId, $password) {
        $encryptor = $this->objectManager->get('\Magento\Framework\Encryption\EncryptorInterface');
        $customerRepo = $this->objectManager->get('\Magento\Customer\Model\ResourceModel\CustomerRepository');
        $customerReg = $this->objectManager->get('\Magento\Customer\Model\CustomerRegistry');
        
        
        $customer = $customerRepo->getById($entityId);
        $passwordHash = $encryptor->getHash($password, true);
        $customerSecure = $customerReg->retrieveSecureData($customer->getId());
        $customerSecure->setRpToken(null);
        $customerSecure->setRpTokenCreatedAt(null);
        $customerSecure->setPasswordHash($passwordHash);        
        $customerRepo->save($customer, $passwordHash);
        $customerReg->remove($customer->getId());
    }

    
    /**
     * Send mail to one particular client
     * 
     *
     * @param integer $entityId
     * @param string $password
     */
    private function sendMail($entityId, $password) {

        // get client
        $customerRepositoryInterface = $this->objectManager->get('\Magento\Customer\Api\CustomerRepositoryInterface');
        $customer = $customerRepositoryInterface->getById($entityId);

        $language = $customer->getCreatedin(); 
        $locale = ($language == 'NL') ? 'nl_NL' : 'en_US';
        $websiteId = $customer->getStoreId();
       
        // Set correct locale
        $localeInterface = $this->objectManager->create('Magento\Framework\Translate');
        $localeInterface->setLocale( $locale);
      
        $translateInterface = $this->objectManager->create('Magento\Framework\Phrase\Renderer\Translate', array('translator' => $localeInterface));

        $renderer = __('');
       
        $renderer->setRenderer($translateInterface);

        $localeInterface->loadData(null, true);
              
        
        $renderer->setRenderer($translateInterface);
 
        // Get name
        $name = $customer->getFirstName() . ' ';
        $name .= (($customer->getMiddleName() && $customer->getMiddleName() != null && $customer->getMiddleName() != 'null') ? $customer->getMiddleName() . ' ' : '');
        $name .= $customer->getLastName();

        $receiverInfo = [
            'name' => $name,
            'email' => $customer->getEmail()
        ];
        
        $senderInfo = [
            'name' => $this->getConfigVariable('sendername', 'emailsenderdata'),
            'email' => $this->getConfigVariable('senderemail', 'emailsenderdata')
        ];

        $emailTempVariables = [
            'language' => $language,
            'varName' => $name,
            'varPassword' => $password,
            'varLink' => $this->siteUrls[$websiteId] . self::LOGIN_URI
        ];

        $this->objectManager->get('MyModule\NewClient\Helper\Email')
                ->mail($emailTempVariables, $senderInfo, $receiverInfo);
    }

    /**
     * After having sent the mail update this for the client in the database
     * 
     * @param integer  $entityId
     */
    private function markEmailSent($entityId) {
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $attributeId = $this->getIsWelcomeMailSentAttributeId();
       
      
        $tableName = $resource->getTableName('customer_entity_int');
        $sqlWhere = $this->dbConnection->select($tableName)
                ->from ($tableName)
                ->where('attribute_id = ?', $attributeId)
                ->where('entity_id  = ? ', $entityId );
       
   
        $query = $this->dbConnection
           ->deleteFromSelect($sqlWhere, $tableName);

        $this->dbConnection->query($query);
        
    }

    /**
     * Create random password
     * 
     * @return string
     */
    private function generateRandomPassword() {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
        $pass = array();
        $alphaLength = strlen($alphabet) - 1;

        for ($i = 0; $i < 8; $i++) {
            $n = rand(0, $alphaLength);
            $pass[] = $alphabet[$n];
        }
        return implode($pass);
    }
    

    /**
     * Read configuration variable form  MyModule/ProductDiscount/etc/config.xml
     *
     * @param strong $name
     * @return unknown
     */
    private function getConfigVariable($name, $section) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $scopeConfig = $objectManager->create('\Magento\Framework\App\Config\ScopeConfigInterface');
        $key = $this->configPath . $section . '/' . $name;
        $value = $scopeConfig->getValue(
                $key, \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return $value;
    }

}
