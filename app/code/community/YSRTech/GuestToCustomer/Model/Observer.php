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
     * Observer for sales_order_save_commit_after.
     *
     * Gives the person behind a guest order an account, and links the order
     * to it. Done once the order is safely stored rather than while it is
     * being placed: by then the checkout's own database transaction is over,
     * so nothing here can undo an order or be undone with one.
     *
     * The event fires on every save of every order, so the cheap checks come
     * first; an order already tied to an account stops at the second line.
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function createOnOrderCommit(Varien_Event_Observer $observer)
    {
        static $seen = array();

        try {
            /** @var Mage_Sales_Model_Order $order */
            $order = $observer->getEvent()->getOrder();
            if (!$order instanceof Mage_Sales_Model_Order || !$order->getId()
                || !$order->getCustomerIsGuest() || $order->getCustomerId() || isset($seen[$order->getId()])
            ) {
                return $this;
            }

            $seen[$order->getId()] = true;

            if (!$this->_helper()->isCreateOnOrderPlaceEnabled($order->getStoreId())) {
                return $this;
            }

            $this->_helper()->linkOrderToCustomer($order, false, true);
        } catch (Exception $e) {
            Mage::log(
                'createOnOrderCommit failed: ' . $e->getMessage(),
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
