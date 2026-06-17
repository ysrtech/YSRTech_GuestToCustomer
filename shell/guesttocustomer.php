<?php
/**
 * YSRTech_GuestToCustomer
 *
 * Retroactive sync shell script.
 *
 * Iterates all existing guest orders (customer_is_guest = 1) that have a
 * non-empty email and attempts to link each one to a matching registered
 * customer via the shared helper. Processing is chunked so it is safe on large
 * order tables.
 *
 * Usage:
 *   php shell/guesttocustomer.php
 *   php shell/guesttocustomer.php --dry-run
 *   php shell/guesttocustomer.php --batch-size 500
 *   php shell/guesttocustomer.php help
 *
 * @category   YSRTech
 * @package    YSRTech_GuestToCustomer
 */

require_once 'abstract.php';

class YSRTech_GuestToCustomer_Shell extends Mage_Shell_Abstract
{
    /**
     * Default number of orders loaded per chunk.
     */
    const DEFAULT_BATCH_SIZE = 500;

    /**
     * Run the sync.
     *
     * @return void
     */
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
        $batchSize = (int) $this->getArg('batch-size');
        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH_SIZE;
        }

        if ($dryRun) {
            echo "*** DRY RUN: no changes will be saved. ***\n";
        }

        $scanned = 0;
        $linked  = 0;
        $page    = 1;

        do {
            /** @var Mage_Sales_Model_Resource_Order_Collection $collection */
            $collection = Mage::getResourceModel('sales/order_collection')
                ->addFieldToFilter('customer_is_guest', 1)
                ->addFieldToFilter('customer_email', array('neq' => ''))
                ->addFieldToFilter('customer_email', array('notnull' => true))
                ->setOrder('entity_id', Varien_Data_Collection::SORT_ORDER_ASC);

            // Chunked loading keeps memory flat on large tables.
            $collection->setPageSize($batchSize)->setCurPage($page);
            $collection->load();

            $count = count($collection);
            if ($count === 0) {
                break;
            }

            foreach ($collection as $orderRow) {
                $scanned++;

                // Reload as a full order model so save() works as expected.
                $order = Mage::getModel('sales/order')->load($orderRow->getId());
                try {
                    if ($helper->linkOrderToCustomer($order, $dryRun)) {
                        $linked++;
                        if ($dryRun) {
                            // In dry-run the order is not modified; details are in the log file.
                            echo sprintf("[would link] order #%s\n", $order->getIncrementId());
                        } else {
                            echo sprintf(
                                "[linked] order #%s -> customer_id %d\n",
                                $order->getIncrementId(),
                                (int) $order->getCustomerId()
                            );
                        }
                    }
                } catch (Exception $e) {
                    echo sprintf(
                        "[error] order #%s: %s\n",
                        $order->getIncrementId(),
                        $e->getMessage()
                    );
                    Mage::log(
                        'Shell link failed for order ' . $order->getIncrementId() . ': ' . $e->getMessage(),
                        Zend_Log::ERR,
                        YSRTech_GuestToCustomer_Helper_Data::LOG_FILE
                    );
                }
            }

            // Free memory before the next chunk.
            $collection->clear();
            unset($collection);

            $page++;
        } while ($count >= $batchSize);

        echo "\n----------------------------------------\n";
        echo sprintf("Scanned guest orders : %d\n", $scanned);
        echo sprintf("%s        : %d\n", $dryRun ? 'Would be linked' : 'Linked         ', $linked);
        echo sprintf("Already-linked/skipped: %d\n", $scanned - $linked);
        echo "----------------------------------------\n";
    }

    /**
     * Help.
     *
     * @return string
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:  php guesttocustomer.php [options]

  --dry-run           Report what would change without saving anything.
  --batch-size <n>    Orders to load per chunk (default: 500).
  --force             Run even if the module is disabled in config.
  help                This help.

USAGE;
    }
}

$shell = new YSRTech_GuestToCustomer_Shell();
$shell->run();
