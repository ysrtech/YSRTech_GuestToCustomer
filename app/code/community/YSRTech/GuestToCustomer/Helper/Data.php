<?php
/**
 * YSRTech_GuestToCustomer
 *
 * Core helper holding ALL guest-order linking logic so it can be reused from
 * observers, the shell script, or any admin/custom code.
 *
 * @category   YSRTech
 * @package    YSRTech_GuestToCustomer
 */
class YSRTech_GuestToCustomer_Helper_Data extends Mage_Core_Helper_Abstract
{
    const LOG_FILE = 'ysrtech_guesttocustomer.log';

    const XML_PATH_ENABLED              = 'ysrtech_guesttocustomer/general/enabled';
    const XML_PATH_TRIGGER_ORDER_PLACE  = 'ysrtech_guesttocustomer/triggers/on_order_place';
    const XML_PATH_TRIGGER_REGISTER     = 'ysrtech_guesttocustomer/triggers/on_customer_register';

    /**
     * Master enable switch, scoped to the given store (or website).
     *
     * @param mixed $store
     * @return bool
     */
    public function isEnabled($store = null)
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_ENABLED, $store);
    }

    /**
     * Whether linking on order placement is enabled.
     *
     * @param mixed $store
     * @return bool
     */
    public function isLinkOnOrderPlaceEnabled($store = null)
    {
        return $this->isEnabled($store)
            && Mage::getStoreConfigFlag(self::XML_PATH_TRIGGER_ORDER_PLACE, $store);
    }

    /**
     * Whether linking on customer registration is enabled.
     *
     * @param mixed $store
     * @return bool
     */
    public function isLinkOnCustomerRegisterEnabled($store = null)
    {
        return $this->isEnabled($store)
            && Mage::getStoreConfigFlag(self::XML_PATH_TRIGGER_REGISTER, $store);
    }

    /**
     * Link a single guest order to an existing registered customer account.
     *
     * This is the single source of truth for the linking logic. It is
     * idempotent and safe to call repeatedly: orders that are already linked
     * (or that are not guest orders) are skipped.
     *
     * IMPORTANT (per-website email uniqueness caveat):
     * Magento enforces customer email uniqueness PER WEBSITE by default
     * (account sharing scope = "Website"). The same email address can belong
     * to different customer accounts on different websites. We therefore always
     * scope the customer lookup to the ORDER'S website via setWebsiteId() so we
     * never link an order to a customer that belongs to another website.
     *
     * We deliberately DO NOT touch the order email, invoices, shipments, totals
     * or any historical/financial records — only the customer association
     * fields (customer_id, customer_is_guest, customer_group_id) are modified.
     *
     * @param Mage_Sales_Model_Order $order
     * @param bool                   $dryRun When true, no save is performed.
     * @return bool True if the order was linked (or would be, in dry-run); false otherwise.
     */
    public function linkOrderToCustomer(Mage_Sales_Model_Order $order, $dryRun = false)
    {
        // Guard: order must be loaded / persisted.
        if (!$order || !$order->getId()) {
            return false;
        }

        // Guard: only act on guest orders. Re-processing an already-linked order
        // (customer_is_guest = 0 and a customer_id present) is a no-op.
        if (!$order->getCustomerIsGuest() || $order->getCustomerId()) {
            return false;
        }

        $email = trim((string) $order->getCustomerEmail());
        if ($email === '') {
            return false;
        }

        // Resolve the website that owns this order, so the customer lookup is
        // correctly scoped (per-website email uniqueness — see method docblock).
        $websiteId = $this->getOrderWebsiteId($order);
        if ($websiteId === null) {
            return false;
        }

        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer')
            ->setWebsiteId($websiteId)
            ->loadByEmail($email);

        // No matching registered customer on this website -> do nothing.
        if (!$customer->getId()) {
            return false;
        }

        if ($dryRun) {
            Mage::log(sprintf(
                'DRY-RUN: order #%s (entity_id %d, website %d, email %s) would link to customer_id %d (group %d).',
                $order->getIncrementId(),
                $order->getId(),
                $websiteId,
                $email,
                $customer->getId(),
                $customer->getGroupId()
            ), Zend_Log::INFO, self::LOG_FILE);
            return true;
        }

        // Only set the customer-association fields. Nothing else is mutated.
        $order->setCustomerId($customer->getId())
            ->setCustomerIsGuest(0)
            ->setCustomerGroupId($customer->getGroupId());

        $order->save();

        Mage::log(sprintf(
            'Linked order #%s (entity_id %d, website %d, email %s) to customer_id %d (group %d).',
            $order->getIncrementId(),
            $order->getId(),
            $websiteId,
            $email,
            $customer->getId(),
            $customer->getGroupId()
        ), Zend_Log::INFO, self::LOG_FILE);

        return true;
    }

    /**
     * Find and link all guest orders matching a customer's email on that
     * customer's website. Used by the registration observer.
     *
     * @param Mage_Customer_Model_Customer $customer
     * @param bool                         $dryRun
     * @return int Number of orders linked.
     */
    public function linkGuestOrdersForCustomer(Mage_Customer_Model_Customer $customer, $dryRun = false)
    {
        $email = trim((string) $customer->getEmail());
        if (!$customer->getId() || $email === '') {
            return 0;
        }

        $websiteId = $customer->getWebsiteId();

        // Determine the store IDs that belong to the customer's website so we
        // only touch orders placed on that website (per-website scoping).
        $storeIds = $this->getWebsiteStoreIds($websiteId);
        if (empty($storeIds)) {
            return 0;
        }

        /** @var Mage_Sales_Model_Resource_Order_Collection $collection */
        $collection = Mage::getResourceModel('sales/order_collection')
            ->addFieldToFilter('customer_is_guest', 1)
            ->addFieldToFilter('customer_email', $email)
            ->addFieldToFilter('store_id', array('in' => $storeIds));

        $linked = 0;
        foreach ($collection as $orderRow) {
            // Reload as a full order model so save() behaves correctly.
            $order = Mage::getModel('sales/order')->load($orderRow->getId());
            if ($this->linkOrderToCustomer($order, $dryRun)) {
                $linked++;
            }
        }

        return $linked;
    }

    /**
     * Resolve the website id that owns an order.
     *
     * @param Mage_Sales_Model_Order $order
     * @return int|null
     */
    public function getOrderWebsiteId(Mage_Sales_Model_Order $order)
    {
        $storeId = $order->getStoreId();
        if ($storeId === null) {
            return null;
        }

        try {
            return (int) Mage::app()->getStore($storeId)->getWebsiteId();
        } catch (Exception $e) {
            Mage::log(
                'Unable to resolve website for store ' . $storeId . ': ' . $e->getMessage(),
                Zend_Log::WARN,
                self::LOG_FILE
            );
            return null;
        }
    }

    /**
     * Get all store ids belonging to a website.
     *
     * @param int $websiteId
     * @return int[]
     */
    public function getWebsiteStoreIds($websiteId)
    {
        try {
            return Mage::app()->getWebsite($websiteId)->getStoreIds();
        } catch (Exception $e) {
            return array();
        }
    }
}
