<?php

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Opencart\Catalog\Controller\Extension\Paygate\Payment;

use DateTime;
use Exception;
use Opencart\System\Engine\Controller;
use Opencart\System\Library\Cart\Customer;
use Payfast\PayfastCommon\Gateway\Request\PaymentRequest;

require_once __DIR__ . '/../../../system/library/vendor/autoload.php';
require_once DIR_EXTENSION . 'paygate/system/library/paygate/PaygatePaymentMethods.php';
require_once DIR_EXTENSION . 'paygate/system/library/paygate/PaygateTransaction.php';
require_once DIR_EXTENSION . 'paygate/system/library/paygate/PaygateUtils.php';
require_once DIR_EXTENSION . 'paygate/system/library/paygate/PaygateResponseHandler.php';


/**
 *
 */
class Paygate extends Controller
{
	public const CHECKOUT_MODEL = 'checkout/order';
	public const PAYGATE_CODE        = 'paygate.paygate';
	/**
	 * @var PaygatePaymentMethods
	 */
	private $paymentMethods;

	/**
	 * @var PaygateTransaction
	 */
	private $transaction;

	/**
	 * @var PaygateResponseHandler
	 */
	private $responseHandler;

	/**
	 * @var PaygateUtils
	 */
	private $utils;

	public function __construct($registry)
	{
		parent::__construct($registry);
		$this->paymentMethods = new \PaygatePaymentMethods($registry);
		$this->transaction = new \PaygateTransaction($registry);
		$this->responseHandler = new \PaygateResponseHandler($registry);
		$this->utils = new \PaygateUtils($registry);
	}

    /**
     * Entry point from OC checkout
     *
     */
	public function index()
	{
		unset($this->session->data['REFERENCE']);

		$dateTime = new DateTime();
		$time     = $dateTime->format('YmdHis');

		$data['text_loading']   = $this->language->get('text_loading');
		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['continue']       = $this->language->get('payment_url');

		$this->load->model(self::CHECKOUT_MODEL);
		$pay_method_data = [];

		if (isset($this->session->data['order_id']) && is_numeric($this->session->data['order_id'])) {
			$order_info = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);
		} else {
			// Handle missing or invalid order_id
			$order_info = null;
			// Log or throw an error
			$this->log->write('Warning: Missing or invalid order_id in session data.');
		}

		// Handle payment methods list
		if (empty($_POST) && $order_info['payment_method']['code'] === self::PAYGATE_CODE) {
			$pms = $this->paymentMethods->getPaymentMethods();
			if (!empty($pms)) {
				return $this->load->view(
					'extension/paygate/payment/paygate_payment_method',
					[
						'pay_methods' => $pms,
						'action'      => $this->url->link(
							'extension/paygate/payment/paygate|index',
							'',
							true
						),
					]
				);
			}
		} elseif (isset($_POST['paygate_pay_method']) && $_POST['paygate_pay_method'] !== '') {
			$pay_method_data = $this->paymentMethods->getPayMethodDetails();
		}

		// Handle order and payment initiation
		if ($order_info) {
			$initiateData = $this->transaction->initiate_data($order_info, $pay_method_data);
			$paygateID      = $this->utils->getPaygateId();
			$encryption_key = $this->utils->getEncryptionkey();

			$paymentRequest = new PaymentRequest($paygateID, $encryption_key);
			$response       = $paymentRequest->initiate($initiateData);

			$result = [];
			parse_str($response, $result);

			if (isset($result['ERROR'])) {
				return $this->responseHandler->displayError(
					'Error trying to initiate a transaction, paygate error code: ' .
					$result['ERROR']
				);
			}

			$data['CHECKSUM']       = $result['CHECKSUM'];
			$data['PAY_REQUEST_ID'] = $result['PAY_REQUEST_ID'];

			$this->session->data['REFERENCE'] = $time;

			// Handle Paygate processing
			if ($order_info['payment_method']['code'] === self::PAYGATE_CODE) {
				$this->transaction->savePaygateTransaction($order_info, $result);
				$htmlForm = $paymentRequest->getRedirectHTML($result['PAY_REQUEST_ID'], $result['CHECKSUM']);
				$this->cart->clear();

				$this->responseHandler->renderHtmlForm($htmlForm);
			}
		} else {
			return $this->responseHandler->displayError('Order could not be found, order_id: ' . $this->session->data['order_id']);
		}
	}

	/**
	 * Handles redirect response from Paygate
	 */
	public function paygate_return(): void
	{
		$this->responseHandler->paygate_return();
	}

	/**
	 * Handles notify response from Paygate
	 */
	public function notify_handler(): void
	{
		$this->responseHandler->notify_handler();
	}

	/**
	 * Confirms order before redirect
	 */
	public function confirm(): void
	{
		$this->transaction->confirm();
	}

	/**
	 * Handles actions before redirect
	 */
	public function before_redirect(): void
	{
		$this->transaction->before_redirect();
	}
}
