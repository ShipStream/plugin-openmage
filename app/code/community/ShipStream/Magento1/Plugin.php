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
    const STATE_FULFILLMENT_SERVICE_REGISTERED = 'fulfillment_service_registered';

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
    public function connectionDiagnostics(bool $super): array
    {
        $info = $this->_magentoApi('shipstream.info');
        $lines = [];
        $lines[] = sprintf('Magento Edition: %s', $info['magento_edition'] ?? 'undefined');
        $lines[] = sprintf('Magento Version: %s', $info['magento_version'] ?? 'undefined');
        $lines[] = sprintf('OpenMage Version: %s', $info['openmage_version'] ?? 'undefined');
        $lines[] = sprintf('ShipStream Sync Version: %s', $info['shipstream_sync_version'] ?? 'undefined');
        $lines[] = sprintf('Service Status: %s', $this->isFulfillmentServiceRegistered() ? '✅ Registered' : '🚨 Not registered');
        return $lines;
    }

    /**
     * Activate the plugin
     *
     * @return string[]
     */
    public function activate(): array
    {
        $warnings = [];
        try {
            if ($this->_magentoApi('shipstream.set_config', ['warehouse_api_url', $this->getCallbackUrl(null)])) {
                $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED, TRUE);
            }
        } catch (Plugin_Exception $e) {
            $warnings[] = $e->getMessage();
        }
        return $warnings;
    }

    /**
     * Deactivate the plugin
     *
     * @return string[]
     */
    public function deactivate(): array
    {
        $errors = [];
        try {
            $this->_magentoApi('shipstream.set_config', ['warehouse_api_url', NULL]);
            $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED, NULL);
        } catch (Plugin_Exception $e) {
            $errors[] = $e->getMessage();
        }
        try {
            $this->setState([
                self::STATE_LOCK_ORDER_PULL => NULL,
                self::STATE_ORDER_LAST_SYNC_AT => NULL,
            ]);
        } catch (Plugin_Exception $e) {
            $errors[] = $e->getMessage();
        }
        return $errors;
    }

    /**
     * @return string[]
     */
    public function reinstall(): array
    {
        return $this->activate();
    }

    /**
     * Trigger an inventory sync from the Magento side which is more atomic
     *
     * @throws Plugin_Exception
     */
    public function sync_inventory()
    {
        try {
            $result = $this->_syncInventory();
            $this->log('Manual inventory sync completed: '.json_encode($result));
            return [
                'Errors: '.($result['errors'] ? "\n".implode("\n", $result['errors']) : 'none'),
                'Unchanged SKUs count: '.count($result['no_change']),
                'Updated SKUs: '.($result['updated'] ? "\n".implode("\n", array_map(function($item) {
                        return sprintf('%s: %d -> %d', $item['sku'], $item['old_qty'], $item['new_qty']);
                    }, $result['updated'])) : 'none'),
            ];
        } catch (Plugin_Exception $e) {
            $this->logException($e);
            throw $e;
        }
    }

    public function cron_sync_inventory()
    {
        $result = $this->_syncInventory();
        $this->log('Cron inventory sync completed: '.json_encode($result));
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
        return $this->getState(self::STATE_FULFILLMENT_SERVICE_REGISTERED);
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
        $logPrefix = sprintf('Magento Order # %s: ', $orderIncrementId);

        // Check if order exists locally and if not, create new local order
        $result = $this->call('order.search', [['order_ref' => $orderIncrementId],[], []]);
        if ($result['totalCount'] > 0) {
            // Local order exists, update Magento order status to 'submitted'.
            $message = sprintf('ShipStream Order # %s was created at %s', $result['results'][0]['unique_id'], $result['results'][0]['created_at']);
            $this->_addComment($orderIncrementId, 'submitted', $message);
            return; // Ignore already existing orders
        }

        // Get full client order data
        $magentoOrder = $this->_magentoApi('order.info', $orderIncrementId);

        // Setup order.create arguments
        $shippingAddress = $magentoOrder['shipping_address'];
        $shippingAddress['street1'] = $shippingAddress['street'];

        // Prepare order and shipment items
        $orderItems = [];
        $skus = [];
        foreach ($magentoOrder['items'] as $item) {
            if ( ! $this->_checkItem($item)) continue;
            $qty = max($item['qty_ordered'] - $item['qty_canceled'] - $item['qty_refunded'] - $item['qty_shipped'], 0);
            if ($qty > 0) {
                $orderItems[] = [
                    'sku' => $item['sku'],
                    'qty' => $qty,
                    'order_item_ref' => $item['item_id']
                ];
                $skus[] = $item['sku'];
            }
        }
        if (empty($orderItems)) {
            return;
        }

        // Prepare additional order data
        $additionalData = [
            'order_ref' => $magentoOrder['increment_id'],
            'shipping_method' => $this->_getShippingMethod(
                [
                    'shipping_lines' => [
                        [
                            'shipping_method' => $magentoOrder['shipping_method'],
                            'shipping_description' => $magentoOrder['shipping_description'],
                        ]
                    ]
                ]
            ),
            'source' => 'magento:'.$magentoOrder['increment_id'],
        ];

        $newOrderData = [
            'store' => NULL,
            'items' => $orderItems,
            'address' => $shippingAddress,
            'options' => $additionalData,
            'timestamp' => new \DateTime('now', $this->getTimeZone()),
        ];
        $output = NULL;

        // Apply user scripts
        try {
            if ($script = $this->getConfig('filter_script')) {
                // Add product info for use in script
                // API product.search returns an array of products or an empty array in key 'result'.
                $products = $this->call('product.search', [['sku' => ['in' => $skus]]])['result'];
                foreach ($newOrderData['items'] as &$item) {
                    $item['product'] = NULL;
                    foreach ($products as $product) {
                        if ($product['sku'] == $item['sku']) {
                            $item['product'] = $product;
                            break;
                        }
                    }
                }
                unset($item);

                try {
                    $newOrderData = $this->applyScriptForOrder($script, $newOrderData, ['magentoOrder' => $magentoOrder], $output);
                } catch (Plugin_Exception $e) {
                    throw new Plugin_Exception(sprintf('Order Transform Script error: %s', $e->getMessage()), 102, $e);
                } catch (Exception $e) {
                    throw new Plugin_Exception('An unexpected error occurred while applying the Order Transform Script.', 102, $e);
                }

                if ( ! empty($newOrderData['skip'])) {
                    // do not submit order
                    $this->log($logPrefix.'Order has been skipped by the Order Transform Script.', self::DEBUG);
                    return;
                }
                foreach ($newOrderData['items'] as $k => $item) {
                    // Remove added product info from items data
                    unset($newOrderData['items'][$k]['product']);

                    if ( ! empty($item['skip'])) {
                        //  Skipping an item
                        $this->log($logPrefix.sprintf('SKU "%s" has been skipped by the Order Transform Script.', $newOrderData['items'][$k]['sku']), self::DEBUG);
                        unset($newOrderData['items'][$k]);
                    }
                }
                if (empty($newOrderData['items'])) {
                    // no items to submit, all were skipped
                    $this->log($logPrefix.'All SKUs have been skipped by the Order Transform Script.', self::DEBUG);
                    return;
                }
            }
        } catch (Plugin_Exception $e) {
            if (empty($e->getSubjectType())) {
                $e->setSubject('Magento Order', $magentoOrder['increment_id']);
            }
            try {
                $message = sprintf('Order could not be submitted due to the following Order Transform Script error: %s', $e->getMessage());
                $this->_addComment($magentoOrder['increment_id'], 'failed_to_submit', $message);
            } catch (Exception $ex) {}
            throw $e;
        }

        // Submit order
        $this->_lockOrderImport();
        try {
            $result = $this->call('order.create', [$newOrderData['store'], $newOrderData['items'], $newOrderData['address'], $newOrderData['options']]);
            $this->log(sprintf('Created %s Order # %s for Magento Order # %s', $this->getAppTitle(), $result['unique_id'], $magentoOrder['increment_id']));
            if ($output) {
                if ( ! $this->isDeveloperMode()) {
                    $output = substr($output, 0, 512);
                }
                try {
                    $this->call('order.comment', [$result['unique_id'], sprintf("Script output from \"Order Transform Script\":\n<pre>%s</pre>", $output)]);
                } catch (Exception $e) {
                    $this->log(sprintf('Error saving Order Transform Script output comment on order %s: %s', $result['unique_id'], $e->getMessage()), self::ERR);
                }
            }
        } catch (Plugin_Exception $e) {
            $this->log(sprintf("Failed to submit order: %s\n%s", $e->getMessage(), json_encode($newOrderData)));
            if (empty($e->getSubjectType())) {
                $e->setSubject('Magento Order', $magentoOrder['increment_id']);
            }
            $e->setSkipAutoRetry(TRUE); // Do not retry order creations as errors are usually not temporary
            try {
                $message = sprintf('Order could not be submitted due to the following error: %s', $e->getMessage());
                $this->_addComment($magentoOrder['increment_id'], 'failed_to_submit', $message);
            } catch (Exception $ex) {}
            throw $e;
        } finally {
            $this->_unlockOrderImport();
        }

        // Update Magento order status and add comment
        $this->_addComment($magentoOrder['increment_id'], 'submitted', sprintf('Created %s Order # %s', $this->getAppTitle(), $result['unique_id']));
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
     * Update Magento order from shipment:packed or shipment:shipped data
     *
     * @param Varien_Object $data
     * @return void
     * @throws Plugin_Exception
     */
    public function shipmentPackedEvent(Varien_Object $data)
    {
        $shipmentIncrementId = $data->getData('unique_id');
        $clientOrderId = $this->_getMagentoId($data->getSource());
        $clientOrder = $this->_magentoApi('order.info', $clientOrderId);
        $magentoShipmentIncrementId = $this->_getMagentoShipmentIncrementId((string) $data->getExternalId());
        if ($magentoShipmentIncrementId) {
            $magentoShipment = $this->_magentoApi('shipstream_order_shipment.info', $magentoShipmentIncrementId);
            if (is_array($magentoShipment) && empty($magentoShipment['tracks'])) {
                $this->_magentoApi('shipstream_order_shipment.addTrackingNumbers', [$magentoShipmentIncrementId, $data->getData()]);
                $this->log(sprintf('Updated tracking info for existing shipment %s.', $shipmentIncrementId));

                $source = $this->_generateShipmentExternalId((string) $magentoShipment['increment_id'], TRUE);
                $this->call('shipment.update', [$shipmentIncrementId, ['source' => $source]]);
            } else {
                $this->log(sprintf('Tracking info already added for shipment # %s', $magentoShipmentIncrementId), Zend_Log::DEBUG);
            }

            return;
        }

        if ($clientOrder['status'] !== 'submitted' && $clientOrder['status'] !== 'failed_to_submit') {
            throw new Plugin_Exception("Order $clientOrderId status is '{$clientOrder['status']}', expected 'submitted'.");
        }
        if ($clientOrder['status'] === 'failed_to_submit') {
            $this->log(sprintf('Order # %s was Failed to Submit, but we assume it is ok to complete it anyway.', $clientOrderId));
        }

        // Submit webhook payload to custom method
        $payload = $data->getData();
        $payload['warehouse_name'] = $this->getWarehouseName($data->getWarehouseId());
        $magentoShipmentId = $this->_magentoApi('shipstream_order_shipment.createWithTracking', [$clientOrderId, $payload]);
        $this->log(sprintf('Created Magento shipment # %s for order # %s', $magentoShipmentId, $clientOrderId));
        $trackerAdded = FALSE;
        foreach ($payload['packages'] as $package) {
            if ( ! empty($package['tracking_numbers'])) {
                $trackerAdded = TRUE;
            }
        }

        $source = $this->_generateShipmentExternalId((string) $magentoShipmentId, $trackerAdded);
        $this->call('shipment.update', [$shipmentIncrementId, ['source' => $source]]);
    }

    /**
     * Update Magento order from shipment:reverted or shipment:labels_voided data
     *
     * @param Varien_Object $data
     * @return void
     * @throws Plugin_Exception
     */
    public function shipmentRevertedEvent(Varien_Object $data)
    {
        $magentoOrderId = $this->_getMagentoId($data->getSource());
        $magentoShipmentId = $this->_getMagentoShipmentIncrementId($data->getExternalId());

        // Submit webhook payload to custom method
        $payload = $data->getData();
        $payload['warehouse_name'] = $this->getWarehouseName($data->getWarehouseId());
        $this->_magentoApi('shipstream_order_shipment.revert', [$magentoOrderId, $magentoShipmentId, $payload]);
        $this->log(sprintf('Reverted Magento shipment # %s for order # %s', $magentoShipmentId, $magentoOrderId));

        /*
         * TODO on the remote end:
        // Implement the shipstream_order_shipment.revert method:
        // - Load the order using the order number and shipment using the shipment number
        // - Delete the shipment entirely
        // - Change order status back to Submitted
        // - Add a note to the Magento order (customer not notified): Reverted shipment # {magento_shipment_increment_id}
         */
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
        if ( ! $this->_getMagentoId($data->getSource())) {
            return;
        }

        $shipmentSource = $data->getData('external_id');
        if (str_contains((string) $shipmentSource, ':t')) {
            $this->log('Tracking info already updated for shipment source: ' . $shipmentSource, Zend_Log::DEBUG);
            return;
        }

        $packages = $data->getPackages();
        if (is_array($packages) && count($packages) > 0) {
            foreach ($packages as $package) {
                if ( ! empty($package['tracking_numbers'])) {
                    $this->addEvent(
                        'shipmentPackedEvent',
                        array_merge($data->getData(), ['event_name' => 'shipment:shipped'])
                    );
                    return;
                }
            }
        }
    }

    /**
     * Respond to shipment:shipped event, completes the fulfillment
     * If there are no tracking numbers, we wait until shipment is shipped to update Magento order
     *
     * @param Varien_Object $data
     */
    public function respondShipmentShipped(Varien_Object $data)
    {
        if ( ! $this->_getMagentoId($data->getSource())) {
            return;
        }

        $shipmentSource = $data->getData('external_id');
        if (str_contains((string) $shipmentSource, ':t')) {
            $this->log('Tracking info already updated for shipment source: ' . $shipmentSource, Zend_Log::DEBUG);
            return;
        }

        $this->addEvent('shipmentPackedEvent', array_merge($data->getData(), ['event_name'=> 'shipment:shipped']));
    }

    /**
     * Respond to shipment:reverted event, rolls back the fulfillment
     *
     * @param Varien_Object $data
     */
    public function respondShipmentReverted(Varien_Object $data)
    {
        if ( ! $this->_getMagentoId($data->getSource())) {
            return;
        }
        // external_id in webhook payload is set as 'source' using shipment.update
        if ( ! $this->_getMagentoShipmentIncrementId($data->getExternalId())) {
            return;
        }
        $this->addEvent('shipmentRevertedEvent', $data->toArray());
    }

    /**
     * Respond to shipment:labels_voided event, rolls back the fulfillment if there are tracking numbers
     *
     * @param Varien_Object $data
     */
    public function respondShipmentLabelsVoided(Varien_Object $data)
    {
        if ( ! $this->_getMagentoId($data->getSource())) {
            return;
        }
        // external_id in webhook payload is set as 'source' using shipment.update
        if ( ! $this->_getMagentoShipmentIncrementId($data->getExternalId())) {
            return;
        }
        if ( ! empty($data->getPackages()[0]['tracking_numbers'])) {
            $this->addEvent('shipmentRevertedEvent', $data->toArray());
        }
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
                $qtyAdvertised = intval($row['qty_advertised']);
                $qtyBackOrdered = intval($row['qty_backordered']);
                $skus[$row['sku']] = $qtyAdvertised > 0 ? $qtyAdvertised : -$qtyBackOrdered;
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

    /**
     * Callback to import an order.
     *
     * @param array $query
     * @return string|true
     */
    public function syncOrder($query)
    {
        if (isset($query['increment_id'])) {
            try {
                $this->addEvent('importOrderEvent', ['increment_id' => $query['increment_id']]);
                return TRUE;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = 'Invalid query.';
        }
        return json_encode(['errors' => $error]);
    }

    /*********************
     * Protected methods *
     *********************/

    /**
     * Inventory sync logic is handled on the Magento side, this just triggers it to start
     *
     * @return array{errors: string[], updated: array{sku: string, old_qty: int, new_qty: int}, no_change: string[]}
     * @throws Plugin_Exception
     */
    protected function _syncInventory()
    {
        return $this->_magentoApi('shipstream.sync_inventory', []);
    }

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
        $status = $this->getConfig('auto_fulfill_status');
        if ($status === 'custom') {
            $statuses = $this->getConfig('auto_fulfill_custom');
            $statuses = preg_split('/\s*,\s*/', trim($statuses), -1, PREG_SPLIT_NO_EMPTY);
            if ( ! is_array($statuses)) {
                $statuses = $statuses ? array($statuses) : array();
            }
            // Sanitize - map "Ready To Ship" to "ready_to_ship"
            $statuses = array_map(function($status) {
                return strtolower(str_replace(' ', '_', $status));
            }, $statuses);
        } else if ($status && $status !== '-') {
            $statuses = [strtolower(str_replace(' ', '_', $status))];
        } else {
            $statuses = NULL;
        }

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
                $data = $this->_magentoApi('shipstream_order.selectFields', $filters);
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
     * The Magento order/shipment increment id is stored in the "source" field of the ShipStream order/shipment
     *
     * @param string $source
     * @return bool|string
     */
    protected function _getMagentoId($source)
    {
        if (preg_match('/^magento:(\d+)$/', $source, $matches)) {
            return $matches[1];
        }
        return FALSE;
    }

    /**
     * Get magento shipment increment id from external id field stored on WMS side (ShipStream)
     *
     * @param string $wmsExternalId
     *
     * @return false|string
     */
    protected function _getMagentoShipmentIncrementId(string $wmsExternalId)
    {
        if (preg_match('/^magento:(\d+)#(:t)?$/', $wmsExternalId, $matches)) {
            return $matches[1];
        }
        return FALSE;
    }

    /**
     * Generate external shipment id field stored on WMS side (ShipStream) in the external_id field of the shipment.
     *
     * @param string $mageShipmentIncrementId
     * @param bool $addTrackingFlag
     *
     * @return string
     */
    protected function _generateShipmentExternalId(string $mageShipmentIncrementId, bool $addTrackingFlag = FALSE): string
    {
        return 'magento:' . $mageShipmentIncrementId . '#' . ($addTrackingFlag ? ':t' : '');
    }

    /**
     * Method is originally used for mapping Shopify shipping_lines to ShipStream shipping.
     * Reused as is for Magento1/OM.
     *
     * Map Shopify shipping method
     *
     * @param array $data
     * @return string
     * @throws Plugin_Exception
     */
    protected function _getShippingMethod($data)
    {
        $shippingLines = $data['shipping_lines'];
        if (empty($shippingLines)) {
            $shippingLines = [['shipping_description' => 'unknown', 'shipping_method' => 'unknown']];
        }

        // Extract shipping method
        $_shippingMethod = NULL;
        $rules = $this->getConfig('shipping_method_config');
        $rules = json_decode($rules, TRUE);
        $rules = empty($rules) ? [] : $rules;

        foreach ($shippingLines as $shippingLine) {
            if ($_shippingMethod === NULL) {
                $_shippingMethod = $shippingLine['shipping_method'] ?? NULL;
            }
            foreach ($rules as $rule) {
                if (count($rule) != 4) {
                    throw new Plugin_Exception('Invalid shipping method rule.');
                }
                foreach (['shipping_method', 'field', 'operator', 'pattern'] as $field) {
                    if (empty($rule[$field])) {
                        throw new Plugin_Exception('Invalid shipping method rule.');
                    }
                }
                list($shippingMethod, $field, $operator, $pattern) = [
                    $rule['shipping_method'], $rule['field'], $rule['operator'], $rule['pattern']
                ];
                $compareValue = empty($shippingLine[$field]) ? '' : $shippingLine[$field];
                if ($operator == '=~') {
                    if (@preg_match('/^'.$pattern.'$/i', '', $matches) === FALSE && $matches === NULL) {
                        throw new Plugin_Exception('Invalid RegEx expression after "=~" operator', NULL, NULL, 'Get shipping method');
                    }
                    if (preg_match('/^'.$pattern.'$/i', $compareValue)) {
                        $_shippingMethod = $shippingMethod;
                        break 2;
                    }
                }
                else {
                    $pattern = str_replace(['"', "'"], '', $pattern);
                    if ($operator == '=' && $compareValue == $pattern) {
                        $_shippingMethod = $shippingMethod;
                        break 2;
                    }
                    else {
                        if ($operator == '!=' && $compareValue != $pattern) {
                            $_shippingMethod = $shippingMethod;
                            break 2;
                        }
                    }
                }
            }
        }
        if (empty($_shippingMethod)) {
            throw new Plugin_Exception('Cannot identify shipping method.', NULL, NULL, 'Get shipping method');
        }

        return $_shippingMethod;
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
     * Update Magento order status and add comment.
     *
     * @param string $orderIncrementId
     * @param string $orderStatus
     * @param string $comment
     * @return void
     */
    protected function _addComment(string $orderIncrementId, string $orderStatus, string $comment = '')
    {
        try {
            $this->_magentoApi('order.addComment', [$orderIncrementId, $orderStatus, $comment]);
            $message = sprintf('Status of order # %s was changed to %s in merchant site, comment: %s', $orderIncrementId, $orderStatus, $comment);
            $this->log($message);
        } catch (Throwable $e) {
            $message = sprintf('Order status could not be changed in merchant site due to the following error: %s', $e->getMessage());
            $this->log($message, self::ERR);
        }
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
                    'connection_timeout' => 5,
                    'user_agent' => 'ShipStream Magento1 Plugin',
                    'keep_alive' => TRUE,
                    'cache_wsdl' => WSDL_CACHE_DISK,
                )
            );
        }
        return $this->_client->call($method, $args, $canRetry);
    }

}
