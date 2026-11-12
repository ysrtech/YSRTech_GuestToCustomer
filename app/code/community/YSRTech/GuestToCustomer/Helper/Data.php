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

    /**
     * Whether the last linkOrderToCustomer() call made a new account rather
     * than finding one. For callers that count the two apart.
     *
     * @var bool
     */
    public $lastCreated = false;

    const XML_PATH_ENABLED              = 'ysrtech_guesttocustomer/general/enabled';
    const XML_PATH_TRIGGER_ORDER_PLACE  = 'ysrtech_guesttocustomer/triggers/on_order_place';
    const XML_PATH_TRIGGER_REGISTER     = 'ysrtech_guesttocustomer/triggers/on_customer_register';
    const XML_PATH_CREATE_ENABLED       = 'ysrtech_guesttocustomer/create/enabled';
    const XML_PATH_CREATE_ON_ORDER      = 'ysrtech_guesttocustomer/create/on_order_place';
    const XML_PATH_CREATE_ADDRESSES     = 'ysrtech_guesttocustomer/create/copy_addresses';
    const XML_PATH_CREATE_SUBSCRIBE     = 'ysrtech_guesttocustomer/create/subscribe';

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
     * Whether a guest with no account is given one.
     *
     * @param mixed $store
     * @return bool
     */
    public function isCreateEnabled($store = null)
    {
        return $this->isEnabled($store)
            && Mage::getStoreConfigFlag(self::XML_PATH_CREATE_ENABLED, $store);
    }

    /**
     * Whether that happens as each guest order is placed, rather than only
     * when the shell script is run.
     *
     * @param mixed $store
     * @return bool
     */
    public function isCreateOnOrderPlaceEnabled($store = null)
    {
        return $this->isCreateEnabled($store)
            && Mage::getStoreConfigFlag(self::XML_PATH_CREATE_ON_ORDER, $store);
    }

    /**
     * Create a customer account for the person who placed a guest order.
     *
     * The account is made quietly:
     *  - no welcome or confirmation email is sent;
     *  - the password is random and known to nobody, so the person signs in
     *    by resetting it (or by whatever passwordless login the store has);
     *  - they are NOT subscribed to the newsletter. A newsletter row that
     *    already exists for the address is attached to the new account with
     *    its status exactly as it was;
     *  - the account belongs to the order's website and the store's default
     *    customer group.
     *
     * It does not link the order: linkOrderToCustomer() does that, and calls
     * this first when there is no account to link to.
     *
     * @param Mage_Sales_Model_Order $order
     * @param bool                   $dryRun When true nothing is saved.
     * @return Mage_Customer_Model_Customer|null The new account (unsaved in a dry run), or null if none could be made.
     */
    public function createCustomerFromOrder(Mage_Sales_Model_Order $order, $dryRun = false)
    {
        $email = trim((string) $order->getCustomerEmail());

        // An address nobody could sign in with is not worth an account
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $websiteId = $this->getOrderWebsiteId($order);
        if ($websiteId === null || $websiteId === 0) {
            return null;
        }

        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer')->setWebsiteId($websiteId)->loadByEmail($email);
        if ($customer->getId()) {
            return $customer;
        }

        $billing = $order->getBillingAddress();
        $first   = trim((string) $order->getCustomerFirstname());
        $last    = trim((string) $order->getCustomerLastname());

        if ($billing && ($first === '' || $last === '')) {
            $first = $first !== '' ? $first : trim((string) $billing->getFirstname());
            $last  = $last !== '' ? $last : trim((string) $billing->getLastname());
        }

        // An account needs both names. One with neither on the order gets the
        // part of the address before the @, which is at least theirs.
        if ($first === '') {
            $first = substr($email, 0, (int) strpos($email, '@')) ?: $email;
        }
        if ($last === '') {
            $last = '-';
        }

        $store = Mage::app()->getStore($order->getStoreId());

        $customer = Mage::getModel('customer/customer');
        $customer->setData(array(
            'website_id' => $websiteId,
            'store_id'   => (int) $store->getId(),
            'group_id'   => (int) Mage::getStoreConfig(Mage_Customer_Model_Group::XML_PATH_DEFAULT_ID, $store),
            'email'      => $email,
            'prefix'     => $order->getCustomerPrefix(),
            'firstname'  => $first,
            'middlename' => $order->getCustomerMiddlename(),
            'lastname'   => $last,
            'suffix'     => $order->getCustomerSuffix(),
            'created_in' => $store->getName(),
        ));

        if ($order->getCustomerDob()) {
            $customer->setDob($order->getCustomerDob());
        }
        if ($order->getCustomerGender()) {
            $customer->setGender($order->getCustomerGender());
        }
        if ($order->getCustomerTaxvat()) {
            $customer->setTaxvat($order->getCustomerTaxvat());
        }

        if ($dryRun) {
            Mage::log(sprintf(
                'DRY-RUN: would create an account for %s (%s %s, website %d) from order #%s.',
                $email, $first, $last, $websiteId, $order->getIncrementId()
            ), Zend_Log::INFO, self::LOG_FILE);

            return $customer;
        }

        // Import mode keeps the newsletter model from emailing anybody
        $customer->setImportMode(true);
        $customer->setPassword($customer->generatePassword(20));
        // Where the store asks new sign-ups to confirm their address, this account does not wait on an email nobody was sent
        $customer->setForceConfirmed(true);
        $customer->setConfirmation(null);

        $subscription = $this->_subscriptionOf($email);

        $customer->save();

        if (Mage::getStoreConfigFlag(self::XML_PATH_CREATE_ADDRESSES, $store)) {
            $this->_copyAddresses($order, $customer);
        }

        // Last, after every save of the account: each one lets the newsletter
        // module have another go at the row
        $this->_restoreSubscription($subscription, $customer);

        if ($subscription === null && Mage::getStoreConfigFlag(self::XML_PATH_CREATE_SUBSCRIBE, $store)) {
            $this->_subscribe($customer, $store);
        }

        Mage::log(sprintf(
            'Created customer_id %d for %s (website %d) from order #%s.',
            $customer->getId(), $email, $websiteId, $order->getIncrementId()
        ), Zend_Log::INFO, self::LOG_FILE);

        return $customer;
    }

    /**
     * The newsletter row for an address as it stands, before an account is
     * made for it.
     *
     * @param string $email
     * @return array|null
     */
    protected function _subscriptionOf($email)
    {
        $resource = Mage::getSingleton('core/resource');
        $read     = $resource->getConnection('core_read');
        $row      = $read->fetchRow(
            $read->select()
                ->from($resource->getTableName('newsletter/subscriber'), array('subscriber_id', 'subscriber_status'))
                ->where('subscriber_email = ?', $email)
        );

        return $row ? $row : null;
    }

    /**
     * Saving a customer in the storefront or the admin makes the newsletter
     * module "tidy up" a row it finds for the address: an unconfirmed one is
     * confirmed, an inactive one unsubscribed. Making an account for somebody
     * is no reason to change what they agreed to, so the status is put back
     * as it was. A row made for an address that had none is removed.
     *
     * @param array|null                   $before
     * @param Mage_Customer_Model_Customer $customer
     * @return void
     */
    protected function _restoreSubscription($before, Mage_Customer_Model_Customer $customer)
    {
        $resource = Mage::getSingleton('core/resource');
        $write    = $resource->getConnection('core_write');
        $table    = $resource->getTableName('newsletter/subscriber');

        if ($before === null) {
            $write->delete($table, array('subscriber_email = ?' => $customer->getEmail()));

            return;
        }

        $write->update(
            $table,
            array('subscriber_status' => (int) $before['subscriber_status'], 'customer_id' => (int) $customer->getId()),
            array('subscriber_id = ?' => (int) $before['subscriber_id'])
        );

        // A subscriber who signed up with an address alone now has a name:
        // fill it in where theirs is empty, never over one they gave
        if ($this->_subscriberHasNames()) {
            foreach (array('subscriber_firstname' => $customer->getFirstname(), 'subscriber_lastname' => $customer->getLastname()) as $column => $value) {
                if (trim((string) $value) !== '') {
                    $write->update(
                        $table,
                        array($column => $value),
                        array('subscriber_id = ?' => (int) $before['subscriber_id'], "({$column} IS NULL OR {$column} = '')")
                    );
                }
            }
        }
    }

    /**
     * Whether the newsletter table keeps a name beside the address. OpenMage
     * 20 does; Magento 1 and older OpenMage do not.
     *
     * @return bool
     */
    protected function _subscriberHasNames()
    {
        static $has = null;

        if ($has === null) {
            $resource = Mage::getSingleton('core/resource');
            $table    = $resource->getTableName('newsletter/subscriber');
            $read     = $resource->getConnection('core_read');
            $has      = $read->tableColumnExists($table, 'subscriber_firstname')
                && $read->tableColumnExists($table, 'subscriber_lastname');
        }

        return $has;
    }

    /**
     * The customers who have never been on the newsletter list, as a select
     * of their ids.
     *
     * "Never" is strict: a customer with any row on the list - subscribed,
     * unsubscribed, unconfirmed, not active - is not one of them, whether the
     * row is tied to their account or only carries their address. Somebody
     * who said no stays no. Where the Email Campaigns module keeps a
     * suppression list (unsubscribes through a link, bounces, complaints),
     * an address on it is left out as well.
     *
     * @return Varien_Db_Select
     */
    protected function _neverSubscribedSelect()
    {
        $resource    = Mage::getSingleton('core/resource');
        $read        = $resource->getConnection('core_read');
        $subscribers = $resource->getTableName('newsletter/subscriber');

        $select = $read->select()
            ->from(array('c' => $resource->getTableName('customer/entity')), array('entity_id', 'email', 'store_id', 'website_id'))
            ->where('c.is_active = 1')
            ->where("c.email IS NOT NULL AND c.email != ''")
            ->where("NOT EXISTS (SELECT 1 FROM {$subscribers} s1 WHERE s1.customer_id = c.entity_id)")
            ->where("NOT EXISTS (SELECT 1 FROM {$subscribers} s2 WHERE s2.subscriber_email = c.email)");

        $suppressed = $resource->getTableName('ysrtech_emailcampaigns_suppression');

        if ($read->isTableExists($suppressed)) {
            $select->where("NOT EXISTS (SELECT 1 FROM {$suppressed} p WHERE p.email = c.email)");
        }

        return $select;
    }

    /**
     * How many customers have never been on the newsletter list.
     *
     * @return int
     */
    public function countNeverSubscribed()
    {
        $select = $this->_neverSubscribedSelect()->reset(Zend_Db_Select::COLUMNS)->columns(array(new Zend_Db_Expr('COUNT(*)')));

        return (int) Mage::getSingleton('core/resource')->getConnection('core_read')->fetchOne($select);
    }

    /**
     * One batch of "subscribe every customer who never subscribed".
     *
     * Rows are written straight to the newsletter table, so nothing emails
     * anybody: no confirmation request, no "you are subscribed". Each new
     * subscriber is tied to the account and carries its name.
     *
     * @param int $after Only customers with an id above this; 0 to start.
     * @param int $limit How many customers to add.
     * @return array{subscribed:int, after:int, done:bool}
     */
    public function subscribeCustomersBatch($after = 0, $limit = 500)
    {
        $resource = Mage::getSingleton('core/resource');
        $write    = $resource->getConnection('core_write');
        $table    = $resource->getTableName('newsletter/subscriber');
        $limit    = max(1, (int) $limit);

        $rows = $write->fetchAll(
            $this->_neverSubscribedSelect()
                ->where('c.entity_id > ?', (int) $after)
                ->order('c.entity_id ASC')
                ->limit($limit)
        );

        $result = array('subscribed' => 0, 'after' => (int) $after, 'done' => count($rows) < $limit);

        if (!$rows) {
            return $result;
        }

        $ids   = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['entity_id'];
        }

        // Names, for a list that keeps them
        $names = array();

        if ($this->_subscriberHasNames()) {
            foreach (array('firstname', 'lastname') as $code) {
                $attribute = Mage::getSingleton('eav/config')->getAttribute('customer', $code);

                if ($attribute && $attribute->getId()) {
                    $names[$code] = $write->fetchPairs(
                        $write->select()
                            ->from($attribute->getBackendTable(), array('entity_id', 'value'))
                            ->where('attribute_id = ?', (int) $attribute->getId())
                            ->where('entity_id IN (?)', $ids)
                    );
                }
            }
        }

        $defaultStore = array();
        $now          = Varien_Date::now();
        $insert       = array();

        foreach ($rows as $row) {
            $id      = (int) $row['entity_id'];
            $storeId = (int) $row['store_id'];

            // An account made in the admin belongs to no store view: the
            // list wants one, so its website's own
            if (!$storeId) {
                $websiteId = (int) $row['website_id'];

                if (!isset($defaultStore[$websiteId])) {
                    try {
                        $defaultStore[$websiteId] = (int) Mage::app()->getWebsite($websiteId)->getDefaultStore()->getId();
                    } catch (Exception $e) {
                        $defaultStore[$websiteId] = (int) Mage::app()->getDefaultStoreView()->getId();
                    }
                }

                $storeId = $defaultStore[$websiteId];
            }

            $line = array(
                'store_id'                => $storeId,
                'change_status_at'        => $now,
                'customer_id'             => $id,
                'subscriber_email'        => $row['email'],
                'subscriber_status'       => Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED,
                'subscriber_confirm_code' => Mage::helper('core')->getRandomString(32),
            );

            if ($this->_subscriberHasNames()) {
                $line['subscriber_firstname'] = isset($names['firstname'][$id]) ? $names['firstname'][$id] : null;
                $line['subscriber_lastname']  = isset($names['lastname'][$id]) ? $names['lastname'][$id] : null;
            }

            $insert[]        = $line;
            $result['after'] = $id;
        }

        $write->insertMultiple($table, $insert);
        $result['subscribed'] = count($insert);

        Mage::log(sprintf('Subscribed %d customers who had never been on the newsletter list (up to customer_id %d).', count($insert), $result['after']), Zend_Log::INFO, self::LOG_FILE);

        return $result;
    }

    /**
     * Bring the newsletter list up to date with the accounts: tie each
     * subscriber to the customer with the same address on the same website,
     * and give a subscriber with no name the customer's.
     *
     * For the whole list at once, in two statements - which is what puts
     * right the subscribers whose accounts were made before names were
     * carried across. Names somebody gave when subscribing are kept, and
     * nobody's subscription status is touched.
     *
     * @return array{linked:int, named:int}
     */
    public function fillSubscriberNames()
    {
        $resource    = Mage::getSingleton('core/resource');
        $write       = $resource->getConnection('core_write');
        $subscribers = $resource->getTableName('newsletter/subscriber');
        $customers   = $resource->getTableName('customer/entity');
        $stores      = $resource->getTableName('core/store');
        $result      = array('linked' => 0, 'named' => 0);

        // A subscriber row belongs to a store view; the account to a website
        $result['linked'] = (int) $write->query(
            "UPDATE {$subscribers} s
               JOIN {$stores} st ON st.store_id = s.store_id
               JOIN {$customers} c ON c.email = s.subscriber_email AND c.website_id = st.website_id
                SET s.customer_id = c.entity_id
              WHERE s.customer_id = 0 OR s.customer_id IS NULL"
        )->rowCount();

        if (!$this->_subscriberHasNames()) {
            return $result;
        }

        foreach (array('firstname' => 'subscriber_firstname', 'lastname' => 'subscriber_lastname') as $code => $column) {
            $attribute = Mage::getSingleton('eav/config')->getAttribute('customer', $code);

            if (!$attribute || !$attribute->getId()) {
                continue;
            }

            $named = (int) $write->query(
                "UPDATE {$subscribers} s
                   JOIN {$attribute->getBackendTable()} v ON v.entity_id = s.customer_id AND v.attribute_id = " . (int) $attribute->getId() . "
                    SET s.{$column} = v.value
                  WHERE s.customer_id > 0 AND (s.{$column} IS NULL OR s.{$column} = '') AND v.value IS NOT NULL AND v.value != ''"
            )->rowCount();

            $result['named'] = max($result['named'], $named);
        }

        return $result;
    }

    /**
     * Put a new account on the newsletter list, where the store has chosen
     * to (the "Subscribe New Accounts" setting).
     *
     * Only an address the newsletter has never heard of: somebody who is on
     * the list already, in whatever state - unsubscribed above all - is left
     * exactly as they are. Written with the newsletter model in import mode,
     * so no confirmation or "you are subscribed" email goes out.
     *
     * @param Mage_Customer_Model_Customer $customer
     * @param Mage_Core_Model_Store        $store
     * @return void
     */
    protected function _subscribe(Mage_Customer_Model_Customer $customer, $store)
    {
        try {
            /** @var Mage_Newsletter_Model_Subscriber $subscriber */
            $subscriber = Mage::getModel('newsletter/subscriber');
            $subscriber->setImportMode(true)
                ->setStoreId((int) $store->getId())
                ->setCustomerId((int) $customer->getId())
                ->setSubscriberEmail($customer->getEmail())
                ->setSubscriberStatus(Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
                ->setSubscriberConfirmCode($subscriber->randomSequence())
                ->setChangeStatusAt(Varien_Date::now());

            // The list shows a name beside each address, where the table has
            // somewhere to keep one
            if ($this->_subscriberHasNames()) {
                $subscriber->setSubscriberFirstname($customer->getFirstname())
                    ->setSubscriberLastname($customer->getLastname());
            }

            $subscriber->save();
        } catch (Exception $e) {
            Mage::log(
                'Could not subscribe customer_id ' . $customer->getId() . ': ' . $e->getMessage(),
                Zend_Log::WARN,
                self::LOG_FILE
            );
        }
    }

    /**
     * One batch of the retroactive run: the next guest orders below a given
     * id, newest first, each linked to its account - which is made first for
     * a guest who has none.
     *
     * Walked by id rather than by page number: linked orders drop out of the
     * "guest" filter as the run goes, and page 2 of a shrinking list skips
     * whatever slid onto page 1. The caller passes back the `below` it was
     * given until `done` is true.
     *
     * @param int|null $below  Only orders with an id under this; null to start from the newest.
     * @param int      $limit  How many orders to look at.
     * @param bool     $create Give a guest with no account one.
     * @param bool     $dryRun Save nothing.
     * @return array{scanned:int, linked:int, created:int, errors:int, below:int|null, done:bool}
     */
    public function runBatch($below = null, $limit = 100, $create = true, $dryRun = false)
    {
        $collection = Mage::getResourceModel('sales/order_collection')
            ->addFieldToSelect('entity_id')
            ->addFieldToFilter('customer_is_guest', 1)
            ->addFieldToFilter('customer_email', array('neq' => ''))
            ->addFieldToFilter('customer_email', array('notnull' => true))
            ->setOrder('entity_id', Varien_Data_Collection::SORT_ORDER_DESC)
            ->setPageSize(max(1, (int) $limit))
            ->setCurPage(1);

        if ($below !== null) {
            $collection->addFieldToFilter('entity_id', array('lt' => (int) $below));
        }

        $ids    = $collection->getColumnValues('entity_id');
        $result = array('scanned' => 0, 'linked' => 0, 'created' => 0, 'errors' => 0, 'below' => $below, 'done' => count($ids) < max(1, (int) $limit));

        foreach ($ids as $id) {
            $result['below'] = (int) $id;
            $result['scanned']++;

            try {
                $order = Mage::getModel('sales/order')->load($id);

                if ($this->linkOrderToCustomer($order, $dryRun, $create)) {
                    $result['linked']++;
                    $result['created'] += $this->lastCreated ? 1 : 0;
                }
            } catch (Exception $e) {
                $result['errors']++;
                Mage::log('Batch failed for order id ' . $id . ': ' . $e->getMessage(), Zend_Log::ERR, self::LOG_FILE);
            }
        }

        return $result;
    }

    /**
     * How many guest orders there are to go through.
     *
     * @return int
     */
    public function countGuestOrders()
    {
        return (int) Mage::getResourceModel('sales/order_collection')
            ->addFieldToFilter('customer_is_guest', 1)
            ->addFieldToFilter('customer_email', array('neq' => ''))
            ->addFieldToFilter('customer_email', array('notnull' => true))
            ->getSize();
    }

    /**
     * Put the order's billing and shipping addresses in the new account's
     * address book, as its defaults.
     *
     * @param Mage_Sales_Model_Order       $order
     * @param Mage_Customer_Model_Customer $customer
     * @return void
     */
    protected function _copyAddresses(Mage_Sales_Model_Order $order, Mage_Customer_Model_Customer $customer)
    {
        $fields = array(
            'prefix', 'firstname', 'middlename', 'lastname', 'suffix', 'company', 'street', 'city',
            'region', 'region_id', 'postcode', 'country_id', 'telephone', 'fax', 'vat_id',
        );
        $copy = function ($from) use ($fields, $customer) {
            $address = Mage::getModel('customer/address');
            foreach ($fields as $field) {
                $address->setData($field, $from->getData($field));
            }

            // Saved on its own an address marked as a default reloads the
            // customer and saves it again, outside import mode, which is when
            // the newsletter module sends its emails. The defaults are set on
            // the account here instead, below.
            return $address->setCustomerId($customer->getId())->setParentId($customer->getId())
                ->setIsCustomerSaveTransaction(true);
        };
        $same = function ($a, $b) use ($fields) {
            foreach ($fields as $field) {
                if (trim((string) $a->getData($field)) !== trim((string) $b->getData($field))) {
                    return false;
                }
            }

            return true;
        };

        try {
            $billing  = $order->getBillingAddress();
            $shipping = $order->getIsVirtual() ? null : $order->getShippingAddress();

            // No street, no country: not an address anybody could use
            $usable = function ($a) {
                return $a && trim((string) $a->getData('street')) !== '' && trim((string) $a->getData('country_id')) !== '';
            };

            $shipSeparately = $usable($shipping) && (!$usable($billing) || !$same($billing, $shipping));

            $billingId = $shippingId = null;

            if ($usable($billing)) {
                $billingId  = $copy($billing)->save()->getId();
                $shippingId = $billingId;
            }

            if ($shipSeparately) {
                $shippingId = $copy($shipping)->save()->getId();
                $billingId  = $billingId ?: $shippingId;
            }

            if ($billingId) {
                $customer->setImportMode(true)
                    ->setDefaultBilling($billingId)
                    ->setDefaultShipping($shippingId)
                    ->save();
            }
        } catch (Exception $e) {
            // The account stands without its addresses
            Mage::log(
                'Could not copy addresses to customer_id ' . $customer->getId() . ': ' . $e->getMessage(),
                Zend_Log::WARN,
                self::LOG_FILE
            );
        }
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
     * @param bool                   $create When true, a guest with no account is given one first
     *                                       (see createCustomerFromOrder()).
     * @return bool True if the order was linked (or would be, in dry-run); false otherwise.
     */
    public function linkOrderToCustomer(Mage_Sales_Model_Order $order, $dryRun = false, $create = false)
    {
        $this->lastCreated = false;

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

        // No matching registered customer on this website: make one if asked,
        // otherwise there is nothing to link to.
        if (!$customer->getId()) {
            if (!$create) {
                return false;
            }

            $customer = $this->createCustomerFromOrder($order, $dryRun);

            if (!$customer) {
                return false;
            }

            $this->lastCreated = true;

            if ($dryRun) {
                return true;
            }
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

        /*
         * Written as those three columns and nothing more (the order grid is
         * kept in step by the resource). A full save() of the order would run
         * everything that listens for an order being saved - status checks,
         * gift card and registry modules, anything that sends an email when an
         * order "changes" - on orders that may be years old. Linking an order
         * to an account is not an event in the order's life.
         */
        $order->getResource()->saveAttribute($order, array('customer_id', 'customer_is_guest', 'customer_group_id'));

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
