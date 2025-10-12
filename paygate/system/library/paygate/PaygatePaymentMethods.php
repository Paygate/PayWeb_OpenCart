<?php

use Opencart\System\Engine\Controller;

class PaygatePaymentMethods extends Controller
{
	/**
	 * @return array
	 */
	public function getPaymentMethods(): array
	{
		// Add enabled payment methods as checkout options
		$imgs       = 'extension/paygate/catalog/view/image/payment/';
		$paymethods = [
			'creditcardmethod'   => [
				'title' => 'Card',
				'img'   => $imgs . 'mastercard-visa.svg',
			],
			'banktransfermethod' => [
				'title' => 'SiD Secure EFT',
				'img'   => $imgs . 'sid.svg',
			],
			'zappermethod'       => [
				'title' => 'Zapper',
				'img'   => $imgs . 'zapper.svg',
			],
			'snapscanmethod'     => [
				'title' => 'SnapScan',
				'img'   => $imgs . 'snapscan.svg',
			],
			'paypalmethod'       => [
				'title' => 'PayPal',
				'img'   => $imgs . 'paypal.svg',
			],
			'mobicredmethod'     => [
				'title' => 'Mobicred',
				'img'   => $imgs . 'mobicred.svg',
			],
			'momopaymethod'      => [
				'title' => 'MoMoPay',
				'img'   => $imgs . 'momopay.svg',
			],
			'scantopaymethod'    => [
				'title' => 'ScanToPay',
				'img'   => $imgs . 'scan-to-pay.svg',
			],
			'rcsmethod'          => [
				'title' => 'RCS',
				'img'   => $imgs . 'rcs.svg',
			],
			'applepaymethod'     => [
				'title' => 'Apple Pay',
				'img'   => $imgs . 'apple-pay.svg',
			],
			'samsungpaymethod'   => [
				'title' => 'Samsung Pay',
				'img'   => $imgs . 'samsung-pay.svg',
			],
		];
		$pms        = [];
		foreach ($paymethods as $key => $paymethod) {
			$setting = 'payment_paygate_' . $key;
			if ($this->config->get($setting) === 'yes') {
				$pms[] = ['method' => $key, 'title' => $paymethod['title'], 'img' => $paymethod['img']];
			}
		}

		return $pms;
	}

	/**
	 * @return array
	 */
	public function getPayMethodDetails(): array
	{
		$data       = [];
		$PAY_METHOD = 'EW';
		switch ($_POST['paygate_pay_method']) {
			case 'creditcardmethod';
				$PAY_METHOD        = 'CC';
				$PAY_METHOD_DETAIL = 'pw3_credit_card';
				break;
			case 'banktransfermethod':
				$PAY_METHOD        = 'BT';
				$PAY_METHOD_DETAIL = 'SID';
				break;
			case 'zappermethod':
				$PAY_METHOD_DETAIL = 'Zapper';
				break;
			case 'snapscanmethod':
				$PAY_METHOD_DETAIL = 'SnapScan';
				break;
			case 'paypalmethod':
				$PAY_METHOD_DETAIL = 'PayPal';
				break;
			case 'mobicredmethod':
				$PAY_METHOD_DETAIL = 'Mobicred';
				break;
			case 'momopaymethod':
				$PAY_METHOD_DETAIL = 'Momopay';
				break;
			case 'scantopaymethod':
				$PAY_METHOD_DETAIL = 'MasterPass';
				break;
			case 'rcsmethod':
				$PAY_METHOD        = 'CC';
				$PAY_METHOD_DETAIL = 'RCS';
				break;
			case 'applepaymethod':
				$PAY_METHOD        = 'CC';
				$PAY_METHOD_DETAIL = 'Applepay';
				break;
			case 'samsungpaymethod':
				$PAY_METHOD_DETAIL = 'Samsungpay';
				break;
			default:
				$PAY_METHOD_DETAIL = $_POST['paygate_pay_method'];
				break;
		}
		$data['PAY_METHOD']        = $PAY_METHOD;
		$data['PAY_METHOD_DETAIL'] = $PAY_METHOD_DETAIL;

		return $data;
	}

	/**
	 * @param $order_info
	 * @param $pay_method_data
	 *
	 * @return array
	 */
	public function initiate_data($order_info, $pay_method_data): array
	{
		$doVault        = '';
		$vaultID        = '';

		if (isset($pay_method_data['PAY_METHOD'])) {
			$PAY_METHOD        = $pay_method_data['PAY_METHOD'];
			$PAY_METHOD_DETAIL = $pay_method_data['PAY_METHOD_DETAIL'];
		}

		/* getting order info ********/

		$preAmount = number_format($order_info['total'], 2, '', '');
		$reference = htmlspecialchars($order_info['order_id']);
		$amount    = filter_var($preAmount, FILTER_SANITIZE_NUMBER_INT);
		$currency  = $this->getCurrency();

		$returnUrl = filter_var(
			$this->url->link('extension/paygate/payment/paygate|paygate_return', '', true),
			FILTER_SANITIZE_URL
		);
		$transDate = date('Y-m-d H:i:s');
		$locale    = 'en';
		$country   = !$order_info['payment_iso_code_3']
			? $order_info['shipping_iso_code_3'] : $order_info['payment_iso_code_3'];
		$email     = filter_var($order_info['email'], FILTER_SANITIZE_EMAIL);

		// Check if email empty due to some custom themes displaying this on the same page
		$email           = empty($email) ? $this->config->get('config_email') : $email;
		$payMethod       = $PAY_METHOD ?? '';
		$payMethodDetail = $PAY_METHOD_DETAIL ?? '';

		// Add notify if enabled
		$notifyUrl  = $this->getNotifyUrl();
		$userField1 = $order_info['customer_id'];
		$firstName  = !$order_info['payment_firstname']
			? $order_info['shipping_firstname'] : $order_info['payment_firstname'];
		$lastName   = !$order_info['payment_lastname']
			? $order_info['shipping_lastname'] : $order_info['payment_lastname'];
		$userField2 = "$firstName $lastName";
		$userField3 = 'opencart-v4.x';

		$initiateData = [
			'REFERENCE'         => $reference,
			'AMOUNT'            => $amount,
			'CURRENCY'          => $currency,
			'RETURN_URL'        => $returnUrl,
			'TRANSACTION_DATE'  => $transDate,
			'LOCALE'            => $locale,
			'COUNTRY'           => $country,
			'EMAIL'             => $email,
			'PAY_METHOD'        => $payMethod,
			'PAY_METHOD_DETAIL' => $payMethodDetail,
		];
		if ($this->config->get('payment_paygate_notifyredirect') === 'notify') {
			$initiateData['NOTIFY_URL'] = $notifyUrl;
		}
		$initiateData['USER1']    = $userField1; // Used for customer id
		$initiateData['USER2']    = $userField2;
		$initiateData['USER3']    = $userField3;
		$initiateData['VAULT']    = $doVault;
		$initiateData['VAULT_ID'] = $vaultID;
		// Filter out empty values
		$initiateData = array_filter($initiateData, fn($value) => $value !== null && $value !== '');

		return $initiateData;
	}

	/**
	 * Save Paygate transaction data.
	 */
	private function savePaygateTransaction($order_info, $result): void
	{
		$paygateData    = serialize($order_info);
		$paygateSession = [
			'customer'   => $this->customer,
			'customerId' => $order_info['customer_id'],
		];
		$paygateSession = base64_encode(serialize($paygateSession));
		$createDate     = date('Y-m-d H:i:s');
		$query          = <<<QUERY
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
	 * @param $transactionStatus
	 *
	 * @return array
	 */
	public function getOrderStatusDesc($transactionStatus): array
	{
		$txnData = [];
		if ($transactionStatus == 0) {
			$orderStatusId = 1;
			$statusDesc    = 'pending';
		} elseif ($transactionStatus == 1) {
			$orderStatusId = $this->config->get('payment_paygate_success_order_status_id');
			$statusDesc    = 'approved';
		} elseif ($transactionStatus == 2) {
			$orderStatusId = $this->config->get('payment_paygate_failed_order_status_id');
			$statusDesc    = 'declined';
		} elseif ($transactionStatus == 4) {
			$orderStatusId = $this->config->get('payment_paygate_cancelled_order_status_id');
			$statusDesc    = 'cancelled';
		}

		$txnData['orderStatusId'] = $orderStatusId;
		$txnData['statusDesc']    = $statusDesc;

		return $txnData;
	}

	/**
	 * Render the Paygate HTML form.
	 */
	private function renderHtmlForm($htmlForm): void
	{
		echo <<<HTML
        $htmlForm
        <p style="text-align:center;">Redirecting you to Payfast...</p>
        <script type="text/javascript">document.getElementById("paygate_payment_form").submit();</script>
HTML;
	}
}
