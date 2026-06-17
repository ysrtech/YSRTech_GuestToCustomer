<?php
/**
 * YSRTech_GuestToCustomer
 *
 * Event observers. All linking logic lives in the helper; observers only
 * gate on configuration and delegate, wrapping everything in try/catch so a
 * failure can never block checkout or registration.
 *
 * @category   YSRTech
 * @package    YSRTech_GuestToCustomer
 */
class YSRTech_GuestToCustomer_Model_Observer
{
    /**
     * @return YSRTech_GuestToCustomer_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('ysrtech_guesttocustomer');
    }

    /**
     * Observer for sales_order_place_after.
     *
     * Links a freshly placed guest order to a matching registered customer.
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function linkOnOrderPlace(Varien_Event_Observer $observer)
    {
        try {
            /** @var Mage_Sales_Model_Order $order */
            $order = $observer->getEvent()->getOrder();
            if (!$order instanceof Mage_Sales_Model_Order) {
                return $this;
            }

            // Gate on config, scoped to the order's store.
            if (!$this->_helper()->isLinkOnOrderPlaceEnabled($order->getStoreId())) {
                return $this;
            }

            $this->_helper()->linkOrderToCustomer($order);
        } catch (Exception $e) {
            // Never let a linking failure interrupt checkout.
            Mage::log(
                'linkOnOrderPlace failed: ' . $e->getMessage(),
                Zend_Log::ERR,
                YSRTech_GuestToCustomer_Helper_Data::LOG_FILE
            );
        }

        return $this;
    }

    /**
     * Observer for customer_register_success.
     *
     * When a new customer registers, link any pre-existing guest orders that
     * match their email on their website.
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function linkOnCustomerRegister(Varien_Event_Observer $observer)
    {
        try {
            /** @var Mage_Customer_Model_Customer $customer */
            $customer = $observer->getEvent()->getCustomer();
            if (!$customer instanceof Mage_Customer_Model_Customer) {
                return $this;
            }

            // Gate on config; customer registration scope maps to a store via website.
            $store = Mage::app()->getWebsite($customer->getWebsiteId())->getDefaultStore();
            if (!$this->_helper()->isLinkOnCustomerRegisterEnabled($store)) {
                return $this;
            }

            $this->_helper()->linkGuestOrdersForCustomer($customer);
        } catch (Exception $e) {
            // Never let a linking failure interrupt registration.
            Mage::log(
                'linkOnCustomerRegister failed: ' . $e->getMessage(),
                Zend_Log::ERR,
                YSRTech_GuestToCustomer_Helper_Data::LOG_FILE
            );
        }

        return $this;
    }
}
