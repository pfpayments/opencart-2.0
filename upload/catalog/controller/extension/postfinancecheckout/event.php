<?php
/**
 * PostFinanceCheckout OpenCart
 *
 * This OpenCart module enables to process payments with PostFinanceCheckout (https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html).
 *
 * @package Whitelabelshortcut\PostFinanceCheckout
 * @author wallee AG (https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html)
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache Software License (ASL 2.0)
 */
require_once modification(DIR_SYSTEM . 'library/postfinancecheckout/helper.php');

/**
 * Frontend event hook handler
 * See admin/model/extension/postfinancecheckout/setup::addEvents
 */
class ControllerExtensionPostFinanceCheckoutEvent extends PostFinanceCheckout\Controller\AbstractEvent {

	/**
	 * Adds the postfinancecheckout device identifier script
	 *
	 * @param string $route
	 * @param array $parameters
	 * @param object $output
	 */
	public function includeDeviceIdentifier(){
		$script = \PostFinanceCheckoutHelper::instance($this->registry)->getBaseUrl();
		$script .= '/s/[spaceId]/payment/device.js?sessionIdentifier=[UniqueSessionIdentifier]';
		
		$this->setDeviceCookie();
		
		$script = str_replace(array(
			'[spaceId]',
			'[UniqueSessionIdentifier]' 
		), array(
			$this->config->get('postfinancecheckout_space_id'),
			$this->request->cookie['postfinancecheckout_device_id'] 
		), $script);
		
		// async hack
		$script .= '" async="async';
		
		$this->document->addScript($script);
	}

	private function setDeviceCookie(){
		if (isset($this->request->cookie['postfinancecheckout_device_id'])) {
			$value = $this->request->cookie['postfinancecheckout_device_id'];
		}
		else {
			$this->request->cookie['postfinancecheckout_device_id'] = $value = \PostFinanceCheckoutHelper::generateUuid();
		}
		setcookie('postfinancecheckout_device_id', $value, time() + 365 * 24 * 60 * 60, '/');
	}

	/**
	 * Prevent line item changes to authorized postfinancecheckout transactions.
	 *
	 * @param array $order_info
	 */
	public function canSaveOrder($order_info){
		if (!isset($this->request->get['order_id'])) {
			return;
		}
		
		$order_id = $this->request->get['order_id'];
		$transaction_info = \PostFinanceCheckout\Entity\TransactionInfo::loadByOrderId($this->registry, $order_id);
		
		if ($transaction_info->getId() === null) {
			// not a postfinancecheckout transaction
			return;
		}
		
		if (\PostFinanceCheckoutHelper::isEditableState($transaction_info->getState())) {
			// changing line items still permitted
			return;
		}
		
		$old_order = $this->getOldOrderLineItemData($order_id);
		$new_order = $this->getNewOrderLineItemData($order_info);
		
		foreach ($new_order as $key => $new_item) {
			foreach ($old_order as $old_item) {
				if ($old_item['id'] == $new_item['id'] && \PostFinanceCheckoutHelper::instance($this->registry)->areAmountsEqual($old_item['total'],
						$new_item['total'], $transaction_info->getCurrency())) {
					unset($new_order[$key]);
					break;
				}
			}
		}
		
		if (!empty($new_order)) {
			\PostFinanceCheckoutHelper::instance($this->registry)->log($this->language->get('error_order_edit') . " ($order_id)",
					\PostFinanceCheckoutHelper::LOG_ERROR);
			
			if ($this->request->get['route'] == 'checkout/checkout') {
				// ensure reload without order_id
				unset($this->session->data['order_id']);
				\PostFinanceCheckout\Service\Transaction::instance($this->registry)->waitForStates($this->request->get['order_id'],
						array(
							\PostFinanceCheckout\Sdk\Model\TransactionState::FAILED 
						), 5);
				$transaction_info = \PostFinanceCheckout\Entity\TransactionInfo::loadByOrderId($this->registry, $order_id);
				$this->session->data['error'] = $transaction_info->getFailureReason();
				$this->response->redirect($this->createUrl('checkout/checkout', $this->request->get));
			}
			else {
				$this->language->load('payment/postfinancecheckout');
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode(array(
					'error' => $this->language->get('error_order_edit') 
				)));
				$this->response->output();
				die();
			}
		}
	}

	public function update(){
		try {
			$this->validate();
			$this->validateOrder();
			
			$transaction_info = \PostFinanceCheckout\Entity\TransactionInfo::loadByOrderId($this->registry, $this->request->get['order_id']);
			
			if ($transaction_info->getState() == \PostFinanceCheckout\Sdk\Model\TransactionState::AUTHORIZED) {
				\PostFinanceCheckout\Service\Transaction::instance($this->registry)->updateLineItemsFromOrder($this->request->get['order_id']);
				return;
			}
		}
		catch (\Exception $e) {
		}
	}

	/**
	 * Return simple list of ids and total for the given new order information
	 *
	 * @param array $new_order
	 * @return array
	 */
	private function getNewOrderLineItemData(array $new_order){
		$line_items = array();
		
		foreach ($new_order['products'] as $product) {
			$line_items[] = array(
				'id' => $product['product_id'],
				'total' => $product['total'] 
			);
		}
		
		foreach ($new_order['vouchers'] as $voucher) {
			$line_items[] = array(
				'id' => $voucher['voucher_id'],
				'total' => $voucher['price'] 
			);
		}
		
		foreach ($new_order['totals'] as $total) {
			$line_items[] = array(
				'id' => $total['code'],
				'total' => $total['value'] 
			);
		}
		
		return $line_items;
	}

	/**
	 * Return a simple list of ids and total for the existing order identified by order_id
	 *
	 * @param int $order_id
	 * @return array
	 */
	private function getOldOrderLineItemData($order_id){
		$line_items = array();
		$model = \PostFinanceCheckoutHelper::instance($this->registry)->getOrderModel();
		
		foreach ($model->getOrderProducts($order_id) as $product) {
			$line_items[] = array(
				'id' => $product['product_id'],
				'total' => $product['total'] 
			);
		}
		
		foreach ($model->getOrderVouchers($order_id) as $voucher) {
			$line_items[] = array(
				'id' => $voucher['voucher_id'],
				'total' => $voucher['price'] 
			);
		}
		
		foreach ($model->getOrderTotals($order_id) as $total) {
			$line_items[] = array(
				'id' => $total['code'],
				'total' => $total['value'] 
			);
		}
		
		return $line_items;
	}

	protected function getRequiredPermission(){
		return '';
	}
}