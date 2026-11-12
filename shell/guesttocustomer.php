<?php
/**
 * YSRTech_GuestToCustomer
 *
 * Shell script for the guest orders already in the store: links each to the
 * registered customer with the same email on its website and, with --create,
 * first gives a guest who has no account one.
 *
 * Run from the Magento root:
 *   php shell/guesttocustomer.php --dry-run --create
 *   php shell/guesttocustomer.php --create
 *
 * @category   YSRTech
 * @package    YSRTech_GuestToCustomer
 */

require_once 'abstract.php';

class YSRTech_GuestToCustomer_Shell extends Mage_Shell_Abstract
{
    const DEFAULT_BATCH_SIZE = 500;

    public function run()
    {
        if ($this->getArg('help')) {
            echo $this->usageHelp();
            return;
        }

        /** @var YSRTech_GuestToCustomer_Helper_Data $helper */
        $helper = Mage::helper('ysrtech_guesttocustomer');

        // Respect the master enable switch unless explicitly forced.
        if (!$helper->isEnabled() && !$this->getArg('force')) {
            echo "Module is disabled (ysrtech_guesttocustomer/general/enabled). "
                . "Use --force to run anyway.\n";
            return;
        }

        $dryRun = (bool) $this->getArg('dry-run');
        $create = (bool) $this->getArg('create');
        $quiet  = (bool) $this->getArg('quiet');
        $limit  = (int) $this->getArg('limit');

        $batchSize = (int) $this->getArg('batch-size');
        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH_SIZE;
        }

        if ($dryRun) {
            echo "*** DRY RUN: no changes will be saved. ***\n";
        }
        if ($create) {
            echo "Guests with no account are given one (no email is sent to them).\n";
        }

        $scanned = 0;
        $linked  = 0;
        $created = 0;
        $errors  = 0;

        // In a dry run nothing is saved, so an address met twice would be
        // counted as two new accounts; remember the ones already counted.
        $wouldCreate = array();

        /*
         * Newest order first, so a new account takes its name and addresses
         * from the person's latest order. Walked by id rather than by page
         * number: linked orders drop out of the "guest" filter as the run
         * goes, and page 2 of a shrinking list skips whatever slid onto
         * page 1.
         */
        $below = null;

        do {
            $collection = Mage::getResourceModel('sales/order_collection')
                ->addFieldToSelect('entity_id')
                ->addFieldToFilter('customer_is_guest', 1)
                ->addFieldToFilter('customer_email', array('neq' => ''))
                ->addFieldToFilter('customer_email', array('notnull' => true))
                ->setOrder('entity_id', Varien_Data_Collection::SORT_ORDER_DESC)
                ->setPageSize($batchSize)
                ->setCurPage(1);

            if ($below !== null) {
                $collection->addFieldToFilter('entity_id', array('lt' => $below));
            }

            $ids   = $collection->getColumnValues('entity_id');
            $count = count($ids);

            foreach ($ids as $id) {
                $below = (int) $id;
                $scanned++;

                // Reload as a full order model so save() works as expected.
                $order = Mage::getModel('sales/order')->load($id);

                try {
                    if (!$helper->linkOrderToCustomer($order, $dryRun, $create)) {
                        continue;
                    }

                    $isNew = $helper->lastCreated;

                    if ($isNew && $dryRun) {
                        $key = strtolower(trim((string) $order->getCustomerEmail())) . '|' . $helper->getOrderWebsiteId($order);
                        $isNew = !isset($wouldCreate[$key]);
                        $wouldCreate[$key] = true;
                    }

                    $linked++;
                    $created += $isNew ? 1 : 0;

                    if (!$quiet) {
                        echo sprintf(
                            "[%s] order #%s%s\n",
                            $dryRun ? ($isNew ? 'would create + link' : 'would link') : ($isNew ? 'created + linked' : 'linked'),
                            $order->getIncrementId(),
                            $dryRun ? '' : ' -> customer_id ' . (int) $order->getCustomerId()
                        );
                    }
                } catch (Exception $e) {
                    $errors++;
                    echo sprintf("[error] order #%s: %s\n", $order->getIncrementId(), $e->getMessage());
                    Mage::log(
                        'Shell link failed for order ' . $order->getIncrementId() . ': ' . $e->getMessage(),
                        Zend_Log::ERR,
                        YSRTech_GuestToCustomer_Helper_Data::LOG_FILE
                    );
                }

                if ($limit > 0 && $scanned >= $limit) {
                    break 2;
                }
            }

            $collection->clear();
            unset($collection);
        } while ($count >= $batchSize);

        echo "\n----------------------------------------\n";
        echo sprintf("Guest orders looked at  : %d\n", $scanned);
        echo sprintf("%s : %d\n", $dryRun ? 'Accounts that would be made' : 'Accounts made           ', $created);
        echo sprintf("%s : %d\n", $dryRun ? 'Orders that would be linked' : 'Orders linked           ', $linked);
        echo sprintf("Left as they are        : %d\n", $scanned - $linked - $errors);
        echo sprintf("Errors                  : %d\n", $errors);
        echo "----------------------------------------\n";

        if (!$create && $scanned - $linked - $errors > 0) {
            echo "Orders left as they are have no registered customer with their email.\n"
                . "Run again with --create to give those guests an account.\n";
        }
    }

    public function usageHelp()
    {
        return <<<USAGE
Usage:  php guesttocustomer.php [options]

  --dry-run           Report what would change without saving anything.
  --create            Give a guest with no account one, then link their orders to it.
                      No email is sent, the password is random, and nobody is
                      subscribed to the newsletter.
  --batch-size <n>    Orders to load per chunk (default: 500).
  --limit <n>         Stop after looking at this many orders (to try it on a few).
  --quiet             Print the totals only, not a line per order.
  --force             Run even if the module is disabled in config.
  help                This help.

USAGE;
    }
}

$shell = new YSRTech_GuestToCustomer_Shell();
$shell->run();
