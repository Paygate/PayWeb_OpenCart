<?php

use Opencart\System\Engine\Controller;

class PaygateUtils extends Controller
{
	protected string $testmode;

	/**
	 * @return string
	 */
	public function getCurrency(): string
	{
		if ($this->config->get('config_currency') != '') {
			$currency = htmlspecialchars($this->config->get('config_currency'));
		} else {
			$currency = htmlspecialchars($this->currency->getCode());
		}

		return $currency;
	}

	/**
	 * @return string
	 */
	public function getNotifyUrl(): string
	{
		$notifyUrl = '';
		if ($this->config->get('payment_paygate_notifyredirect') === 'notify') {
			$notifyUrl = filter_var(
				$this->url->link('extension/paygate/payment/paygate|notify_handler', '', true),
				FILTER_SANITIZE_URL
			);
		}

		return $notifyUrl;
	}

	/**
	 * @return string
	 */
	public function getPaygateId(): string
	{
		$this->testmode = $this->config->get('payment_paygate_testmode') === 'test';
		return $this->testmode ? '10011072130' : htmlspecialchars(
			$this->config->get('payment_paygate_merchant_id')
		);
	}

	/**
	 * @return string
	 */
	public function getEncryptionkey(): string
	{
		$this->testmode = $this->config->get('payment_paygate_testmode') === 'test';
		return $this->testmode ? 'secret' : $this->config->get('payment_paygate_merchant_key');
	}

	/**
	 * @return int
	 */
	public function getOrderIdFromSession(): int
	{
		$m = [];
		$orderId = 0;
		preg_match('/^.*\/(\d+)$/', $_GET['route'], $m);
		if (count($m) > 1) {
			$orderId = (int)$m[1];
		} elseif (isset($this->session->data['order_id'])) {
			$orderId = (int)$this->session->data['order_id'];
		}

		return $orderId;
	}
}
