<?php

namespace PostFinanceCheckout\Provider;

/**
 * Provider of payment connector information from the gateway.
 */
class PaymentConnector extends AbstractProvider {

	protected function __construct(\Registry $registry){
		parent::__construct($registry, 'oc_postfinancecheckout_payment_connectors');
	}

	/**
	 * Returns the payment connector by the given id.
	 *
	 * @param int $id
	 * @return \PostFinanceCheckout\Sdk\Model\PaymentConnector
	 */
	public function find($id){
		return parent::find($id);
	}

	/**
	 * Returns a list of payment connectors.
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\PaymentConnector[]
	 */
	public function getAll(){
		return parent::getAll();
	}

	protected function fetchData(){
		$connector_service = new \PostFinanceCheckout\Sdk\Service\PaymentConnectorService(\PostFinanceCheckoutHelper::instance($this->registry)->getApiClient());
		return $connector_service->all();
	}

	protected function getId($entry){
		/* @var \PostFinanceCheckout\Sdk\Model\PaymentConnector $entry */
		return $entry->getId();
	}
}