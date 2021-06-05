<?php

/**
 * The ShipStream order.increment_id is the Magento shipment.increment_id.
 * The ShipStream order ref (order.ext_order_id) is the Magento order.increment_id.
 */
class ShipStream_Magento1_Plugin extends Plugin_Abstract
{
    const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';
    const DATE_FORMAT = 'Y-m-d H:i:s';

    const STATE_ORDER_LAST_SYNC_AT = 'order_last_sync_at';
    const STATE_LOCK_ORDER_PULL = 'lock_order_pull';

    /** @var ShipStream_Magento1_Client */
    protected $_client = NULL;

    /**
     * @return bool
     */
    public function hasConnectionConfig()
    {
        return $this->getConfig('api_url') && $this->getConfig('api_login') && $this->getConfig('api_password');
    }

    /**
     * @return array
     * @throws Plugin_Exception
     */
    public function connectionDiagnostics()
    {
        $info = $this->_magentoApi('magento.info');
        $lines = [];
        $lines[] = sprintf('Magento Edition: %s', $info['magento_edition'] ?? 'undefined');
        $lines[] = sprintf('Magento Version: %s', $info['magento_version'] ?? 'undefined');
        $lines[] = sprintf('OpenMage Version: %s', $info['openmage_version'] ?? 'undefined');
        $lines[] = sprintf('ShipStream Sync Version: %s', $info['shipstream_sync_version'] ?? 'undefined');
        return $lines;
    }
    
    /**
     * Trigger an inventory sync from the Magento side which is more atomic
     *
     * @throws Plugin_Exception
     */
    public function sync_inventory()
    {
        $result = $this->_magentoApi('shipstream.sync_inventory', []);
        if ( ! $result['success']) {
            throw new Plugin_Exception($result['message']);
        }
    }

    /**
     * Synchronize orders since the configured date
     *
     * @throws Plugin_Exception
     * @return void
     */
    public function sync_orders()
    {
        if ($since = $this->getConfig('sync_orders_since')) {
            if ( ! preg_match(self::DATE_PATTERN, trim($since), $matches)) {
                throw new Plugin_Exception('Invalid synchronize orders since date format. Valid format: YYYY-MM-DD.');
            }
            if ( ! checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
                throw new Plugin_Exception('Invalid synchronize orders since date.');
            }
        }
        $this->_importOrders($since ?: NULL);
    }

    /**
     * Synchronize orders since the last sync
     */
    public function cron_sync_orders()
    {
        $this->_importOrders(NULL);
    }

    /**
     * @return array|string|null
     */
    public function isFulfillmentServiceRegistered()
    {
        return $this->getState('fulfillment_service_registered');
    }

    /**
     * Register fulfillment service
     */
    public function register_fulfillment_service()
    {
        if ($this->_magentoApi('shipstream.set_config', ['warehouse_api_url', $this->getCallbackUrl('{{method}}')])) {
            $this->setState('fulfillment_service_registered', TRUE);
        }
    }

    /**
     * Unregister fulfillment service
     */
    public function unregister_fulfillment_service()
    {
        $this->_magentoApi('shipstream.set_config', ['warehouse_api_url', NULL]);
        $this->setState('fulfillment_service_registered', NULL);
    }

    /*****************
     * Event methods *
     *****************/

    /**
     * Import client order
     *
     * @param Varien_Object $data
     * @return void
     * @throws Exception
     */
    public function importOrderEvent(Varien_Object $data)
    {
        $orderIncrementId = $data->getData('increment_id');
        $errorPrefix = sprintf('Magento Order # %s: ', $orderIncrementId);

        // Check if order exists locally and if not, create new local order
        $result = $this->call('order.search', [['order_ref' => $orderIncrementId],[], []]);
        if ($result['totalCount'] > 0) {
            return; // Ignore already existing orders
            // TODO - update status to submitted if order exists but Magento status is not submitted in case addComment failed
        }

        // Get full client order data
        $magentoOrder = $this->_magentoApi('order.info', $orderIncrementId);

        // Setup order.create arguments
        $shippingAddress = $magentoOrder['shipping_address'];
        $shippingAddress['street1'] = $shippingAddress['street'];

        // Prepare order and shipment items
        $orderItems = [];
        foreach ($magentoOrder['items'] as $item) {
            if ( ! $this->_checkItem($item)) continue;
            $qty = max($item['qty_ordered'] - $item['qty_canceled'] - $item['qty_refunded'] - $item['qty_shipped'], 0);
            if ($qty > 0) {
                $orderItems[] = [
                    'sku' => $item['sku'],
                    'qty' => $qty,
                ];
            }
        }
        if (empty($orderItems)) {
            return;
        }

        // Prepare additional order data
        $additionalData = [
            'order_ref' => $magentoOrder['increment_id'],
            'shipping_method' => $magentoOrder['shipping_method'],
            'source' => 'magento:'.$magentoOrder['increment_id'],
        ];

        $newOrderData = [
            'store' => NULL,
            'items' => $orderItems,
            'address' => $shippingAddress,
            'options' => $additionalData,
            'timestamp' => new \DateTime('now', $this->getTimeZone()),
        ];

        // Apply user scripts
        try {
            if ($script = $this->getConfig('filter_script')) {
                // Add product info for use in script
                // TODO - use product.search for single lookup?
                foreach ($newOrderData['items'] as &$item) {
                    try {
                        $item['product'] = $this->call('product.info', [$item['sku']]);
                    }
                    catch (Plugin_Exception $e) {
                        if ($e->getCode() == 101) {
                            $item['product'] = NULL;
                        }
                    }
                }
                unset($item);

                try {
                    $newOrderData = $this->applyScript($script, ['order' => $newOrderData, 'magentoOrder' => $magentoOrder], 'order');
                } catch (Mage_Core_Exception $e) {
                    throw new Plugin_Exception($errorPrefix.$e->getMessage(), 102);
                } catch (Exception $e) {
                    throw new Plugin_Exception($errorPrefix.'An unexpected error occurred while applying the order transform script.', 102, $e);
                }
                if ( ! array_key_exists('store', $newOrderData)
                    || empty($newOrderData['items'])
                    || empty($newOrderData['address'])
                    || empty($newOrderData['options'])
                ) {
                    throw new Plugin_Exception($errorPrefix.sprintf('The order transform script did not return the data expected.'));
                }
                if ( ! empty($newOrderData['skip'])) {
                    // do not submit order
                    $this->log($errorPrefix.sprintf('Order has been skipped by a script.'),self::DEBUG);
                    return;
                }
                foreach ($newOrderData['items'] as $k => $item) {
                    // Remove added product info from items data
                    unset($newOrderData['items'][$k]['product']);

                    if ( ! empty($item['skip'])) {
                        //  Skipping an item
                        $this->log($errorPrefix.sprintf('SKU "%s" has been skipped by a script.', $newOrderData['items'][$k]['sku']), self::DEBUG);
                        unset($newOrderData['items'][$k]);
                    }
                }
                if (empty($newOrderData['items'])) {
                    // no items to submit, all were skipped
                    $this->log($errorPrefix.sprintf('All SKUs have been skipped.'), self::DEBUG);
                    return;
                }
            }
        } catch (Plugin_Exception $e) {
            try {
                $message = sprintf('Order could not be submitted due to a script error: %s', $e->getMessage());
                $this->_magentoApi('order.addComment', [$magentoOrder['increment_id'], 'failed_to_submit', $message]);
                $this->log($errorPrefix.$message, self::ERR);
            } catch (Exception $ex) {}
            throw $e;
        }

        // Submit order
        $this->_lockOrderImport();
        try {
            try {
                $this->call('order.create', [$newOrderData['store'], $newOrderData['items'], $newOrderData['address'], $newOrderData['options']]);
                $message = sprintf('Created ShipStream Order # %s', $result['unique_id']);
                $this->log($message);

                // Update Magento order status and add comment
                $this->_magentoApi('order.addComment', [$magentoOrder['increment_id'], 'submitted', $message]);
            } catch (Plugin_Exception $e) {
                try {
                    $message = sprintf('Order could not be submitted due to a script error: %s', $e->getMessage());
                    $this->_magentoApi('order.addComment', [$magentoOrder['increment_id'], 'failed_to_submit', $message]);
                } catch (Exception $ex) {}
            }
        } finally {
            $this->_unlockOrderImport();
        }
    }

    /**
     * Adjust inventory
     *
     * Example: [ '{SKU}' => [ 'qty_adjust' => 5, 'qty_available' => 95, 'external_id' => '1234' ] ]
     *
     * @param Varien_Object $data
     * @throws Plugin_Exception
     */
    public function adjustInventoryEvent(Varien_Object $data)
    {
        foreach ($data->getStockAdjustments() as $sku => $change) {
            if (empty($sku) || empty($change['qty_adjust'])) {
                continue;
            }
            $this->_magentoApi('shipstream_stock_item.adjust', [$sku, (float)$change['qty_adjust']]);
            $this->log(sprintf('Adjusted inventory for the product %s. Adjustment: %.4f.', $sku, $change['qty_adjust']));
        }
    }

    /**
     * Update Magento order from shipment:packed data
     *
     * @param Varien_Object $data
     * @return void
     * @throws Plugin_Exception
     */
    public function shipmentPackedEvent(Varien_Object $data)
    {
        $clientOrderId = $this->_getMagentoShipmentId($data->getSource());
        $clientOrder = $this->_magentoApi('order.info', $clientOrderId);
        if ($clientOrder['status'] != 'submitted' && $clientOrder['status'] != 'failed_to_submit') {
            throw new Plugin_Exception("Order $clientOrderId status is '{$clientOrder['status']}', expected 'submitted'.");
        }
        if ($clientOrder['status'] == 'failed_to_submit') {
            $this->log(sprintf('Order # %s was Failed to Submit, but we assume it is ok to complete it anyway.', $clientOrderId));
        }

        // Submit webhook payload to custom method
        $payload = $data->getData();
        $payload['warehouse_name'] = $this->getWarehouseName($data->getWarehouseId());
        $magentoShipmentId = $this->_magentoApi('shipstream_order_shipment.createWithTracking', [$clientOrderId, $payload]);
        $this->log(sprintf('Created Magento shipment # %s for order # %s', $magentoShipmentId, $clientOrderId));
    }

    /****************************
     * Internal Event Observers *
     ****************************/

    /**
     * Respond to the delivery committed webhook
     *
     * @param Varien_Object $data
     */
    public function respondDeliveryCommitted(Varien_Object $data)
    {
        $this->addEvent('adjustInventoryEvent', ['stock_adjustments' => $data->getStockAdjustments()]);
    }

    /**
     * Respond to the inventory adjustment webhook
     *
     * @param Varien_Object $data
     */
    public function respondInventoryAdjusted(Varien_Object $data)
    {
        $this->addEvent('adjustInventoryEvent', ['stock_adjustments' => $data->getStockAdjustments()]);
    }

    /**
     * Respond to shipment:packed event, completes the fulfillment
     *
     * @param Varien_Object $data
     */
    public function respondShipmentPacked(Varien_Object $data)
    {
        if ( ! $this->_getMagentoShipmentId($data->getSource())) {
            return;
        }
        $this->addEvent('shipmentPackedEvent', $data->getData());
    }

    /************************
     * Callbacks (<routes>) *
     ************************/

    /**
     * Inventory with order import lock request handler
     *
     * @param array $query
     * @return string
     * @throws Plugin_Exception
     */
    public function inventoryWithLock($query)
    {
        $result = $skus = [];
        try {
            $this->_lockOrderImport();
            $rows = $this->call('inventory.list', empty($query['sku']) ? NULL : strval($query['sku']));
            foreach ($rows as $row) {
                $skus[$row['sku']] = intval($row['qty_available']);
            }
            $result['skus'] = $skus;
        } catch (Plugin_Exception $e) {
            $result['errors'] = $e->getMessage();
        } catch (Exception $e) {
            $result['errors'] = 'An unexpected error occurred while retrieving the inventory.';
        }

        return json_encode($result);
    }

    /**
     * @throws Plugin_Exception
     */
    public function unlockOrderImport()
    {
        $this->_unlockOrderImport();
        return TRUE;
    }

    /*********************
     * Protected methods *
     *********************/

    /**
     * Import orders
     *
     * @param null|string $from
     * @return void
     */
    protected function _importOrders($from = NULL)
    {
        // Do not import orders while inventory is being synced
        $state = $this->getState(self::STATE_LOCK_ORDER_PULL, TRUE);
        if ( ! empty($state['value']) && $state['value'] == 'locked') {
            return;
        }

        $now = time();
        $limit = 100;
        if (is_null($from)) {
            $from = $this->getConfig(self::STATE_ORDER_LAST_SYNC_AT);
            if (empty($from)) {
                $from = date(self::DATE_FORMAT, $now - (86400*5)); // Go back up to 5 days
            }
        } else {
            $from .= ' 00:00:00';
        }
        $to = date(self::DATE_FORMAT, $now);

        // Order statuses for which orders should be automatically fulfilled
        $statuses = $this->getConfig('auto_fulfill');
        $statuses = preg_split('/\s*,\s*/', trim($statuses), NULL, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($statuses)) {
            $statuses = $statuses ? array($statuses) : array();
        }
        // Sanitize - map "Ready To Ship" to "ready_to_ship"
        $statuses = array_map(function($status) {
            return strtolower(str_replace(' ', '_', $status));
        }, $statuses);

        // Automatic fulfillment. When a new order is found in the specified statuses,
        // an order should be created on the ShipStream side and status updated to Submitted.
        if ($statuses) {
            do {
                $updatedAtMin = $from;
                $updatedAtMax = $to;
                $filters = array(array(
                    'updated_at' => array('from' => $updatedAtMin, 'to' => $updatedAtMax),
                    'status' => array('in' => $statuses),
                ));
                $data = $this->_magentoApi('order.list', $filters);
                foreach ($data as $orderData) {
                    if (strcmp($orderData['updated_at'], $updatedAtMin) > 0) {
                        $updatedAtMin = date('c', strtotime($orderData['updated_at'])+1);
                    }
                    $this->addEvent('importOrderEvent', ['increment_id' => $orderData['increment_id']]);
                    $this->log(sprintf('Queued import for order %s', $orderData['increment_id']));
                }
            } while (count($data) == $limit && strcmp($updatedAtMin, $updatedAtMax) < 0);
            $this->setState(self::STATE_ORDER_LAST_SYNC_AT, $updatedAtMax);
        }
    }

    /**
     * Set flag that prevents client Magento orders from being imported
     *
     * @return bool
     * @throws Exception
     */
    protected function _lockOrderImport()
    {
        $seconds = 0;
        do {
            $state = $this->getState(self::STATE_LOCK_ORDER_PULL, TRUE);
            if (empty($state['value']) || empty($state['updated_at']) || $state['value'] == 'unlocked') {
                if ($this->setState(self::STATE_LOCK_ORDER_PULL, 'locked')) {
                    return TRUE;
                }
            }
            $now = new DateTime(date('Y-m-d H:i:s', time()));
            $updatedAt = new DateTime($state['updated_at']);
            $interval = $now->diff($updatedAt);
            // Consider the lock to be stale if it is older than 1 minute
            if ($interval->i >= 1) {
                if ($this->setState(self::STATE_LOCK_ORDER_PULL, 'locked')) {
                    return TRUE;
                }
            }
            sleep(1);
            $seconds++;
        } while($seconds < 20);

        throw new Plugin_Exception('Cannot lock order importing.');
    }

    /**
     * Unlock order importing
     *
     * @return void
     */
    protected function _unlockOrderImport()
    {
        try {
            $this->setState(self::STATE_LOCK_ORDER_PULL, 'unlocked');
        } catch (Exception $e) {
            $this->log(sprintf('Cannot unlock order importing. Error: %s', $e->getMessage()));
        }
    }

    /**
     * The Magento shipment increment id is stored in the source field
     *
     * @param string $source
     * @return bool|string
     */
    protected function _getMagentoShipmentId($source)
    {
        if (preg_match('/^magento:(\d+)$/', $source, $matches)) {
            return $matches[1];
        }
        return FALSE;
    }

    /**
     * Check if the client's order/shipment item can be fulfilled
     *
     * Client Magento product types:
     *
     * 1. Simple - can be imported directly.
     * 2. Configurable - two order item rows are created in Magento: "configurable" and "simple".
     *    The "configurable" order item should be ignored.
     * 3. Grouped - simple products are sent in the API response.
     * 4. Bundle - two order item rows are created in Magento: "bundle" and "simple". Both order items are sent
     *    in the API response. The "bundle" order item should be ignored.
     * 5. Virtual - ignored.
     * 6. Downloadable - ignored.
     *
     * @see http://docs.magento.com/m1/ce/user_guide/catalog/product-types.html
     *
     * @param array $item
     * @return bool
     */
    protected function _checkItem(array $item)
    {
        return (isset($item['product_type']) && $item['product_type'] == 'simple');
    }

    /**
     * @param string $method
     * @param array $args
     * @param bool $canRetry
     * @return mixed
     * @throws Plugin_Exception
     */
    protected function _magentoApi($method, $args = array(), $canRetry = TRUE)
    {
        if ( ! $this->_client) {
            $this->_client = new ShipStream_Magento1_Client(
                array(
                    'base_url' => $this->getConfig('api_url'),
                    'login'    => $this->getConfig('api_login'),
                    'password' => $this->getConfig('api_password'),
                    'debug'    => $this->isDebug(),
                ), array(
                    'timeout'   => 90,
                    'useragent' => 'ShipStream_Magento1',
                    'keepalive' => TRUE,
                )
            );
        }
        return $this->_client->call($method, $args, $canRetry);
    }
}
