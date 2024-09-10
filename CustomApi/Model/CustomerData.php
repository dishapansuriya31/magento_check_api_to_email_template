<?php
namespace Kitchen\CustomApi\Model;

use Kitchen\CustomApi\Api\CustomerInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\AccountManagement;

class CustomerData implements CustomerInterface
{
    private $customerRepository;
    private $customerFactory;
    private $accountManagement;
    private $logger;
    private $storeManager;
    private $transportBuilder;
    private $scopeConfig;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        CustomerInterfaceFactory $customerFactory,
        AccountManagementInterface $accountManagement,
        StoreManagerInterface $storeManager,
        TransportBuilder $transportBuilder,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->customerFactory = $customerFactory;
        $this->accountManagement = $accountManagement;
        $this->storeManager = $storeManager;
        $this->transportBuilder = $transportBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function checkCustomerByEmail($email)
    {
        $customer = null;
        $message = '';
        $success = false;

        $customerExists = false;
        try {
            $customer = $this->customerRepository->get($email);
            $customerExists = true;
        } catch (NoSuchEntityException $e) {
            $customerExists = false;
        }

        if ($customerExists) {
            $this->logger->info('Customer found:', [
                'id' => $customer->getId(),
                'email' => $customer->getEmail(),
                'first_name' => $customer->getFirstname(),
                'last_name' => $customer->getLastname()
            ]);

            $success = true;
            $message = 'Customer exists.';
           
        } else {
            $customer = $this->customerFactory->create();
            $customer->setEmail($email);
            $customer->setFirstname('New');
            $customer->setLastname('Customer');
            $customer->setWebsiteId($this->storeManager->getStore()->getWebsiteId());

            try {
                $this->customerRepository->save($customer);
                $this->logger->info('New customer created:', [
                    'id' => $customer->getId(),
                    'email' => $customer->getEmail(),
                    'first_name' => $customer->getFirstname(),
                    'last_name' => $customer->getLastname()
                ]);

                if ($this->sendPasswordResetEmail($customer)) {
                    $success = true;
                    $message = 'New customer created and password reset email sent.';
                } else {
                    $success = false;
                    $message = 'New customer created but unable to send password reset email. Please try again later.';
                }
            } catch (LocalizedException $e) {
                $this->logger->error('Error creating customer: ' . $e->getMessage());
                $success = false;
                $message = 'Error creating customer: ' . $e->getMessage();
            }
        }

        return [
            'success' => $success,
            'message' => $message
        ];
    }

    protected function sendPasswordResetEmail($customer)
    {
        $templateId = 'custom_password_reset_template'; // Ensure this is the correct identifier for your custom template
        $sender = [
            'name' => $this->scopeConfig->getValue('trans_email/ident_general/name'),
            'email' => $this->scopeConfig->getValue('trans_email/ident_general/email')
        ];
        $store = $this->storeManager->getStore();
        
        try {
           
            $this->accountManagement->initiatePasswordReset($customer->getEmail(), 'email');
            
           
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => 'frontend', 'store' => $store->getId()])
                ->setTemplateVars([
                    'customer_name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                    'store_name' => $store->getName(),
                    'reset_password_url' => $this->storeManager->getStore()->getUrl('customer/account/resetPassword')
                ])
                ->setFrom($sender)
                ->addTo($customer->getEmail(), $customer->getFirstname() . ' ' . $customer->getLastname())
                ->getTransport();
        
            $transport->sendMessage();
            $this->logger->info('Password reset email sent to ' . $customer->getEmail());
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error sending email: ' . $e->getMessage());
            return false;
        }
    }
    

}
