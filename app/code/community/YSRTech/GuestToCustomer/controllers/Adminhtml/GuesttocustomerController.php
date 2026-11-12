<?php
/**
 * YSRTech_GuestToCustomer
 *
 * The "Apply To Existing Guest Orders" button in the configuration. The work
 * is done a batch at a time, each batch its own short request, so a store
 * with years of guest orders never runs into a time limit: the page asks for
 * the next batch until there are none left.
 *
 * @category   YSRTech
 * @package    YSRTech_GuestToCustomer
 */
class YSRTech_GuestToCustomer_Adminhtml_GuesttocustomerController extends Mage_Adminhtml_Controller_Action
{
    const BATCH = 100;

    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/ysrtech_guesttocustomer');
    }

    /**
     * How many guest orders there are, for the question asked before starting.
     */
    public function countAction()
    {
        $this->_answer(function () {
            return array('orders' => Mage::helper('ysrtech_guesttocustomer')->countGuestOrders());
        });
    }

    /**
     * One batch. `below` is the id the last batch stopped at.
     */
    public function runAction()
    {
        $this->_answer(function () {
            $below = $this->getRequest()->getPost('below');
            $below = $below === null || $below === '' ? null : (int) $below;

            // A long run is many requests; none should be cut short halfway
            // through an order
            @set_time_limit(120);

            $helper = Mage::helper('ysrtech_guesttocustomer');
            $result = $helper->runBatch($below, self::BATCH, true, false);

            // At the end of the run: the newsletter list is tied to the
            // accounts, and subscribers with no name are given theirs
            if ($result['done']) {
                $result['subscribers'] = $helper->fillSubscriberNames();
            }

            return $result;
        });
    }

    /**
     * How many customers have never been on the newsletter list.
     */
    public function subscribeCountAction()
    {
        $this->_answer(function () {
            return array('customers' => Mage::helper('ysrtech_guesttocustomer')->countNeverSubscribed());
        });
    }

    /**
     * One batch of subscribing them. `after` is the customer id the last batch reached.
     */
    public function subscribeRunAction()
    {
        $this->_answer(function () {
            @set_time_limit(120);

            return Mage::helper('ysrtech_guesttocustomer')->subscribeCustomersBatch((int) $this->getRequest()->getPost('after'), 500);
        });
    }

    /**
     * Only a POST carrying the session's key does anything: these change
     * customers and orders.
     *
     * @param callable $work
     * @return void
     */
    protected function _answer($work)
    {
        $result = array('ok' => false);

        try {
            if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
                Mage::throwException($this->__('This page has been open a while. Reload it and try again.'));
            }

            $result = array('ok' => true) + $work();
        } catch (Mage_Core_Exception $e) {
            $result['message'] = $e->getMessage();
        } catch (Exception $e) {
            Mage::logException($e);
            $result['message'] = $this->__('Something went wrong. See the exception log.');
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($result));
    }
}
