<?php

use Opencart\System\Engine\Controller;
use Payfast\PayfastCommon\Gateway\Request\PaymentRequest;

require_once __DIR__ . '/../vendor/autoload.php';


class PaygateResponseHandler extends Controller
{
	private string $tableName = DB_PREFIX . 'paygate_transaction';
	private const CHECKOUT_MODEL = 'checkout/order';
	private const INFORMATION_CONTACT = 'information/contact';


	/**
	 * Display error message and stop further execution
	 */
	public function displayError($message): string
	{
		return '<p>' . $message . '. Log support ticket to <a href="' . $this->url->link(self::INFORMATION_CONTACT) . '">shop owner</a></p>';
	}

	/**
	 * Render the Paygate HTML form
	 */
	public function renderHtmlForm($htmlForm): void
	{
		echo <<<HTML
        $htmlForm
        <p style="text-align:center;">Redirecting you to Payfast...</p>
        <script type="text/javascript">document.getElementById("paygate_payment_form").submit();</script>
HTML;
	}

	/**
	 * Handles redirect response from Paygate
	 */
	public function paygate_return(): void
	{
		$utils = new PaygateUtils($this->registry);
		$this->load->language('extension/paygate/checkout/paygate');
		$payRequestId = htmlspecialchars($_POST['PAY_REQUEST_ID']);
		$transactionStatus = (int)$_POST['TRANSACTION_STATUS'];
		$checksum = htmlspecialchars($_POST['CHECKSUM']);

		$record = $this->db->query(
			"select * from $this->tableName where paygate_reference = '$payRequestId';"
		);
		$record = $record?->rows[0];
		$orderId = $record['order_id'] ?? 0;
		$ps = $record['paygate_session'];
		$pas = base64_decode($ps);

		$checkString = $utils->getPaygateId() . $payRequestId . $transactionStatus . $orderId . $utils->getEncryptionkey();
		$ourChecksum = md5($checkString);

		$status = '';
		$result = '';
		$r = '';
		$error = '';

		if (!hash_equals($checksum, $ourChecksum)) {
			$status = 'checksum_failed';
		}

		$useRedirect = $this->config->get('payment_paygate_notifyredirect') === 'redirect';

		$sessionOrderId = $this->session->data['order_id'] ?? 'Session data not set';
		if ($orderId !== 0) {
			$this->load->model('account/activity');
			$this->load->model(self::CHECKOUT_MODEL);
			$order = $this->model_checkout_order->getOrder($orderId);
			$products = $this->model_checkout_order->getProducts($orderId);

			$this->setActivityData($order, $orderId);
			$payMethodDesc = '';
			$respData = $this->sendClientRequest($record);
			$result = $respData['result'] ?? '';
			$r = $respData['r'] ?? '';
			$error = $respData['error'] ?? '';

			if (isset($result['PAY_METHOD_DETAIL']) && $result['PAY_METHOD_DETAIL'] != '') {
				$payMethodDesc = ', using a payment method of ' . $result['PAY_METHOD_DETAIL'];
			}

			$pgData = $this->mapPGData($result, $useRedirect, $payMethodDesc);
			$orderStatusId = $pgData['orderStatusId'];
			$statusDesc = $pgData['statusDesc'];
			$resultsComment = $pgData['resultsComment'];
			$status = $pgData['status'];

			if ($statusDesc !== 'approved') {
				$this->restoreCart($products, $statusDesc, $orderId);
			}

			$this->model_checkout_order->addHistory(
				$orderId,
				$orderStatusId,
				$resultsComment,
				true
			);

			if ($useRedirect) {
				unset($this->session->data['shipping_method']);
				unset($this->session->data['shipping_methods']);
				unset($this->session->data['payment_method']);
				unset($this->session->data['payment_methods']);
				unset($this->session->data['guest']);
				unset($this->session->data['comment']);
				unset($this->session->data['order_id']);
				unset($this->session->data['coupon']);
				unset($this->session->data['reward']);
				unset($this->session->data['voucher']);
				unset($this->session->data['vouchers']);
				unset($this->session->data['totals']);
			}
		}

		$this->setHeadingValues([
									'result' => $result,
									'status' => $status,
									'error' => $error,
									'response' => $r,
									'sessionOrderId' => $sessionOrderId,
									'statusDesc' => $statusDesc,
								]);
	}

	/**
	 * @param $order
	 * @param $orderId
	 */
	public function setActivityData($order, $orderId): void
	{
		if ($this->customer->isLogged()) {
			$activityData = [
				'customer_id' => $this->customer->getId(),
				'name' => $this->customer->getFirstName() . ' ' . $this->customer->getLastName(),
				'order_id' => $orderId,
			];
			$this->model_account_activity->addActivity('order_account', $activityData);
		} else {
			$activityData = [
				'name' => $order['firstname'] . ' ' . $order['lastname'],
				'order_id' => $orderId,
			];
			$this->model_account_activity->addActivity('order_guest', $activityData);
		}
	}

	/**
	 * @param $result
	 * @param $useRedirect
	 * @param $payMethodDesc
	 * @return array
	 */
	public function mapPGData($result, $useRedirect, $payMethodDesc): array
	{
		$pgData = [];
		$orderStatusId = '7';
		$resultsComment = '';
		$status = '';

		if (isset($result['TRANSACTION_STATUS'])) {
			$status = 'ok';

			if ($result['TRANSACTION_STATUS'] == 0) {
				$orderStatusId = 1;
				$statusDesc = 'pending';
				$resultsComment = 'Transaction status verification failed. No transaction status.
                 Please contact the shop owner to confirm transaction status.';
			} elseif ($result['TRANSACTION_STATUS'] == 1) {
				$orderStatusId = $this->config->get('payment_paygate_success_order_status_id');
				$statusDesc = 'approved';
				$resultsComment = 'Transaction Approved.';
			} elseif ($result['TRANSACTION_STATUS'] == 2) {
				$orderStatusId = $this->config->get('payment_paygate_failed_order_status_id');
				$statusDesc = 'declined';
				$resultsComment = 'Transaction Declined by PayWeb.';
			} elseif ($result['TRANSACTION_STATUS'] == 4) {
				$orderStatusId = $this->config->get('payment_paygate_cancelled_order_status_id');
				$statusDesc = 'cancelled';
				$resultsComment = 'Transaction Cancelled by User.';
			}
			if ($useRedirect) {
				$resultsComment = 'Redirect response from Payfast Gateway with a status of ' . $statusDesc . $payMethodDesc;
			}
		} else {
			$orderStatusId = 1;
			$statusDesc = 'pending';
			$resultsComment = 'Transaction status verification failed. No transaction status.
             Please contact the shop owner to confirm transaction status.';
		}

		$pgData['orderStatusId'] = $orderStatusId;
		$pgData['statusDesc'] = $statusDesc;
		$pgData['resultsComment'] = $resultsComment;
		$pgData['status'] = $status;

		return $pgData;
	}

	/**
	 * @param $products
	 * @param $statusDesc
	 * @param $orderId
	 */
	public function restoreCart($products, $statusDesc, $orderId): void
	{
		if ($statusDesc !== 'approved' && is_array($products)) {
			foreach ($products as $product) {
				$options = $this->model_checkout_order->getOptions($orderId, $product['order_product_id']);
				$option = [];
				if (is_array($options) && count($options) > 0) {
					$option = $options;
				}
				$this->cart->add($product['product_id'], $product['quantity'], $option);
			}
		}
	}

	/**
	 * @param $record
	 * @return array
	 */
	public function sendClientRequest($record): array
	{
		$utils = new PaygateUtils($this->registry);
		$paygateID = $utils->getPaygateId();
		$encryptionKey = $utils->getEncryptionkey();
		$useRedirect = $this->config->get('payment_paygate_notifyredirect') === 'redirect';
		$respData = [];
		$orderId = $record['order_id'];
		$response = '';
		$error = false;
		if ($useRedirect) {
			$payRequestId = htmlspecialchars($_POST['PAY_REQUEST_ID']);
			$reference = $orderId;

			try {
				$paymentRequest = new PaymentRequest($paygateID, $encryptionKey);
				$response = $paymentRequest->query($payRequestId, $reference);
			} catch (Exception $exception) {
				error_log('Exception: ' . $exception->getMessage());
				$error = true;
			}

			$result = [];
			if ($response != '') {
				parse_str($response, $result);
			}
		} else {
			$result = $_POST;
		}
		$respData['result'] = $result;
		$respData['r'] = $response;
		$respData['error'] = $error;

		return $respData;
	}

	/**
	 * @param array $params
	 */
	public function setHeadingValues(array $params): void
	{
		$result = $params['result'] ?? [];
		$status = $params['status'] ?? '';
		$error = $params['error'] ?? '';
		$response = $params['response'] ?? '';
		$sessionOrderId = $params['sessionOrderId'] ?? '';
		$statusDesc = $params['statusDesc'] ?? '';

		$customerId = (int)isset($result['USER1']) ? $result['USER1'] : 0;
		if ($status == 'ok') {
			$data['heading_title'] = sprintf($this->language->get('heading_title'), $statusDesc);
		} else {
			$data['heading_title'] = 'Transaction status verification failed. Status not ok.
                 Please contact the shop owner to confirm transaction status.';
			$data['heading_title'] .= json_encode($_POST);
			$data['heading_title'] .= json_encode($result);
			$data['heading_title'] .= 'Curl error: ' . $error;
			$data['heading_title'] .= 'Curl response: ' . $response;
			$data['heading_title'] .= 'Session data: ' . $sessionOrderId;
		}
		$this->document->setTitle($data['heading_title']);

		$data['breadcrumbs'] = [];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home'),
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_basket'),
			'href' => $this->url->link('checkout/cart'),
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_checkout'),
			'href' => $this->url->link('checkout/checkout', '', 'SSL'),
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_success'),
			'href' => $this->url->link('checkout/success'),
		];

		if ($customerId > 0) {
			$data['text_message'] = sprintf(
				$this->language->get('text_customer'),
				$this->url->link('account/account', '', 'SSL'),
				$this->url->link('account/order', '', 'SSL'),
				$this->url->link('account/download', '', 'SSL'),
				$this->url->link(self::INFORMATION_CONTACT)
			);
		} else {
			$data['text_message'] = sprintf(
				$this->language->get('text_guest'),
				$this->url->link(self::INFORMATION_CONTACT)
			);
		}

		$data['button_continue'] = $this->language->get('button_continue');
		$data['continue'] = $this->url->link('common/home');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->addHeader('Content-Type: text/html; charset=utf-8');
		$this->response->setOutput($this->load->view('extension/paygate/common/paygate_success', $data));
	}

	/**
	 * Handles notify response from Paygate
	 */
	public function notify_handler(): void
	{
		if ($this->config->get('payment_paygate_notifyredirect') === 'notify') {
			echo 'OK';

			$errors = isset($EERROR);

			if (!$errors) {
				$postData = $this->prepareCheckSumParams();
				$checkSumParams = $postData['checkSumParams'];
				$notify_checksum = $postData['notify_checksum'];
				$transaction_status = $postData['transaction_status'];
				$order_id = $postData['order_id'];
				$payMethodDesc = $postData['pay_method_desc'];

				if ($checkSumParams != $notify_checksum) {
					$errors = true;
				}

				if (!$errors) {
					$txnData = $this->getOrderStatusDesc($transaction_status);
					$orderStatusId = $txnData['orderStatusId'];
					$statusDesc = $txnData['statusDesc'];

					$resultsComment = 'Notify response from Payfast Gateway with a status of ' . $statusDesc . $payMethodDesc;
					$this->load->model(self::CHECKOUT_MODEL);
					if ($statusDesc == 'approved') {
						$this->cart->clear();
					}
					$this->model_checkout_order->addHistory($order_id, $orderStatusId, $resultsComment, true);
				}
			}
		}
	}

	/**
	 * @return array
	 */
	public function prepareCheckSumParams(): array
	{
		$utils = new PaygateUtils($this->registry);
		$paygateID = $utils->getPaygateId();
		$encryptionKey = $utils->getEncryptionkey();

		$checkSumParams = '';

		$postData = [];
		foreach ($_POST as $key => $val) {
			if ($key == 'PAYGATE_ID') {
				$checkSumParams .= $paygateID;
			}

			if ($key != 'CHECKSUM' && $key != 'PAYGATE_ID') {
				$checkSumParams .= $val;
			}

			if ($key == 'CHECKSUM') {
				$notifyChecksum = $val;
			}

			if ($key == 'TRANSACTION_STATUS') {
				$transactionStatus = $val;
			}

			if ($key == 'USER1') {
				$orderId = $val;
			}

			if ($key == 'PAY_METHOD_DETAIL') {
				$payMethodDesc = ', using a payment method of ' . $val;
			}
		}

		$checkSumParams .= $encryptionKey;
		$checkSumParams = md5($checkSumParams);

		$postData['checkSumParams'] = $checkSumParams;
		$postData['notify_checksum'] = $notifyChecksum ?? '';
		$postData['transaction_status'] = $transactionStatus ?? '';
		$postData['order_id'] = $orderId ?? '';
		$postData['pay_method_desc'] = $payMethodDesc ?? '';

		return $postData;
	}

	/**
	 * @param $transactionStatus
	 * @return array
	 */
	public function getOrderStatusDesc($transactionStatus): array
	{
		$txnData = [];
		if ($transactionStatus == 0) {
			$orderStatusId = 1;
			$statusDesc = 'pending';
		} elseif ($transactionStatus == 1) {
			$orderStatusId = $this->config->get('payment_paygate_success_order_status_id');
			$statusDesc = 'approved';
		} elseif ($transactionStatus == 2) {
			$orderStatusId = $this->config->get('payment_paygate_failed_order_status_id');
			$statusDesc = 'declined';
		} elseif ($transactionStatus == 4) {
			$orderStatusId = $this->config->get('payment_paygate_cancelled_order_status_id');
			$statusDesc = 'cancelled';
		}

		$txnData['orderStatusId'] = $orderStatusId;
		$txnData['statusDesc'] = $statusDesc;

		return $txnData;
	}

}
