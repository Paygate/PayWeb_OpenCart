<?php

use Opencart\System\Engine\Controller;

class PaygateTransaction extends Controller
{
	private string $tableName = DB_PREFIX . 'paygate_transaction';
	private const PAYGATE_CODE        = 'paygate.paygate';
	private const CHECKOUT_MODEL = 'checkout/order';

	/**
	 * @param $order_info
	 * @param $pay_method_data
	 * @return array
	 */
	public function initiate_data($order_info, $pay_method_data): array
	{
		$utils = new PaygateUtils($this->registry);
		$doVault = '';
		$vaultID = '';

		if (isset($pay_method_data['PAY_METHOD'])) {
			$PAY_METHOD = $pay_method_data['PAY_METHOD'];
			$PAY_METHOD_DETAIL = $pay_method_data['PAY_METHOD_DETAIL'];
		}

		$orderAmount = $order_info['total'] * $order_info['currency_value'];
		$preAmount = number_format($orderAmount, 2, '', '');
		$reference = htmlspecialchars($order_info['order_id']);
		$amount = filter_var($preAmount, FILTER_SANITIZE_NUMBER_INT);
		$currency = $utils->getCurrency();

		$returnUrl = filter_var(
			$this->url->link('extension/paygate/payment/paygate|paygate_return', '', true),
			FILTER_SANITIZE_URL
		);
		$transDate = date('Y-m-d H:i:s');
		$locale = 'en';
		$country = !$order_info['payment_iso_code_3']
			? $order_info['shipping_iso_code_3'] : $order_info['payment_iso_code_3'];
		$email = filter_var($order_info['email'], FILTER_SANITIZE_EMAIL);

		$email = empty($email) ? $this->config->get('config_email') : $email;
		$payMethod = $PAY_METHOD ?? '';
		$payMethodDetail = $PAY_METHOD_DETAIL ?? '';

		$notifyUrl = $utils->getNotifyUrl();
		$userField1 = $order_info['customer_id'];
		$firstName = !$order_info['payment_firstname']
			? $order_info['shipping_firstname'] : $order_info['payment_firstname'];
		$lastName = !$order_info['payment_lastname']
			? $order_info['shipping_lastname'] : $order_info['payment_lastname'];
		$userField2 = "$firstName $lastName";
		$userField3 = 'opencart-v3.3.0';

		$initiateData = [
			'REFERENCE' => $reference,
			'AMOUNT' => $amount,
			'CURRENCY' => $currency,
			'RETURN_URL' => $returnUrl,
			'TRANSACTION_DATE' => $transDate,
			'LOCALE' => $locale,
			'COUNTRY' => $country,
			'EMAIL' => $email,
			'PAY_METHOD' => $payMethod,
			'PAY_METHOD_DETAIL' => $payMethodDetail,
		];
		if ($this->config->get('payment_paygate_notifyredirect') === 'notify') {
			$initiateData['NOTIFY_URL'] = $notifyUrl;
		}
		$initiateData['USER1'] = $userField1;
		$initiateData['USER2'] = $userField2;
		$initiateData['USER3'] = $userField3;
		$initiateData['VAULT'] = $doVault;
		$initiateData['VAULT_ID'] = $vaultID;

		$initiateData = array_filter($initiateData, fn($value) => $value !== null && $value !== '');

		return $initiateData;
	}

	/**
	 * Save Paygate transaction data
	 */
	public function savePaygateTransaction($order_info, $result): void
	{
		$paygateData = serialize($order_info);
		$paygateSession = [
			'customer' => $this->customer,
			'customerId' => $order_info['customer_id'],
		];
		$paygateSession = base64_encode(serialize($paygateSession));
		$createDate = date('Y-m-d H:i:s');
		$query = <<<QUERY
INSERT INTO $this->tableName (customer_id, order_id, paygate_reference, paygate_data, paygate_session, date_created, date_modified)
VALUES (
    '{$order_info['customer_id']}',
    '{$order_info['order_id']}',
    '{$result['PAY_REQUEST_ID']}',
    '$paygateData',
    '$paygateSession',
    '$createDate',
    '$createDate'
)
QUERY;

		$this->db->query($query);
	}

	/**
	 * Confirms order before redirect
	 */
	public function confirm(): void
	{
		if ($this->session->data['payment_method']['code'] == self::PAYGATE_CODE) {
			$this->load->model(self::CHECKOUT_MODEL);
			$comment = 'Redirected to Paygate';
			$this->model_checkout_order->addHistory(
				$this->session->data['order_id'],
				$this->config->get('payment_paygate_order_status_id'),
				$comment,
				true
			);
		}
	}

	/**
	 * Handles actions before redirect
	 */
	public function before_redirect(): void
	{
		$json = [];

		if ($this->session->data['payment_method']['code'] == self::PAYGATE_CODE) {
			$this->load->model(self::CHECKOUT_MODEL);
			$this->model_checkout_order->addHistory($this->session->data['order_id'], 1);
			$json['answer'] = 'success';
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

}
