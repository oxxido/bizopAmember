<?php
/**
 * @table paysystems
 * @id okpay
 * @title OKPAY
 * @visible_link https://www.okpay.com
 * @recurring none
 * @logo_url okpay.png
 * @country GB
 * @country CY
 * @adult 1
 * @international 1
 *
 * @end
 *
 * OKPay payment module for aMemeber [4.x.x]
 *
 * This module allows merchants to get payments via {@link https://www.okpay.com OKPAY}
 * processing
 *
 * @author		Mike Iceman
 * @copyright	OKPAY Inc. 2012
 * @version		1.1
 * @package		aMemeber 4.2.3
 * @subpackage	Payment gateways
 */
class Am_Paysystem_Okpay extends Am_Paysystem_Abstract {
	const PLUGIN_STATUS = self::STATUS_PRODUCTION;
	const PLUGIN_REVISION = '1.0.1';
	
	const URL = "https://www.okpay.com/process.html";
	
	protected $defaultTitle = 'OKPAY';
	protected $defaultDescription = 'OKPay secure payment';
	
	// here you add elements to HTML_QuickForm2 form with
	// parameters necessary to configure your form
	// saved parameters are available with $this->getConfig('paramname')
	// api call in other plugin functions   
	public function _initSetupForm(Am_Form_Setup $form) {
		$form->addText('wallet_id', array('size'=>40))
				->setLabel('Wallet ID or e-mail');
	}
	/// now lets write payment redirect function 
	public function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result) {
		if (!$this->getConfig('wallet_id')) {
			throw new Am_Exception_Configuration("There is a configuration error in [okpay] plugin - no [wallet_id] Wallet ID or e-mail");
		}
		$a = new Am_Paysystem_Action_Redirect(self::URL);
		$result->setAction($a);
		
		# Payment config
		$a->ok_receiver     = $this->getConfig('wallet_id');
		$a->ok_invoice      = $invoice->getRandomizedId();
		$a->ok_currency     = strtoupper($invoice->currency);
		$a->ok_item_1_name  = $invoice->getLineDescription();
		$a->ok_item_1_price = $invoice->first_total;
		# Payer data
		$a->ok_payer_first_name = $invoice->getFirstName();
		$a->ok_payer_last_name  = $invoice->getLastName();
		$a->ok_payer_street     = $invoice->getStreet();
		$a->ok_payer_city       = $invoice->getCity();
		$a->ok_payer_state      = $invoice->getState();
		$a->ok_payer_zip        = $invoice->getZip();
		$a->ok_payer_country    = $invoice->getCountry();
		# IPN and Return URLs
		$a->ok_ipn            = $this->getPluginUrl('ipn');
		$a->ok_return_success = $this->getReturnUrl();
		$a->ok_return_fail    = $this->getCancelUrl();
	}
	// let aMember know how this plugin is going to deal with recurring payments
	public function getRecurringType() {
		return self::REPORTS_NOT_RECURRING;
	}
	
	public function getReadme() {
		return <<<CUT
			OKPAY payment plugin configuration

1. Enable "OKPAY" payment plugin at aMember CP->Setup->Plugins
2. Configure "OKPAY" payment plugin at aMember CP->Setup->OKPAY
   Set Wallet ID or E-mail, linked to your wallet.
3. That's all. Now your aMember shop can receive OKPAY payments!
CUT;
	}
 
	public function createTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs) {
		return new Am_Paysystem_Transaction_Okpay_Ipn($this, $request, $response, $invokeArgs);
	}
}

// the following class takes incoming IPN post from your payment system, parses
// it if necessary, checks that it came unchanged, from trusted source, finds
// corresponding amember invoice for it, and adds payment record to the invoice
// it is all what is required to handle payment
class Am_Paysystem_Transaction_Okpay_Ipn extends Am_Paysystem_Transaction_Incoming {
	public function validateSource() {
		// there must be some code to validate if IPN came from payment system, and not from a "hacker"
		return true;
	}
	public function validateStatus() {
		// there must be code to check post variables and confirm that the post indicates successful payment transaction
		switch ($this->request->get('ok_txn_status')) {
			case 'completed':
				$this->invoice->addPayment($this);
				if ($this->invoice->first_total <= 0 && $this->invoice->status == Invoice::PENDING) {
					$this->invoice->addAccessPeriod($this); // add first trial period
				}
				return true;
				break;
			case 'reversed':
				if($this->request->get('ok_txn_reversal_reason') == 'refund' || $this->request->get('ok_txn_reversal_reason') == 'chargeback') {
					$this->invoice->addRefund($this, $this->request->get('ok_txn_id'), $this->request->get('ok_txn_gross'));
				}
				return true;
				break;
			default:
				throw new Am_Exception_Paysystem_TransactionInvalid("Unknown method");
				break;
		}
		return false;
	}
	public function findInvoiceId() {
		// it takes invoice ID from request as sent by payment system
		return $this->request->getFiltered('ok_invoice'); 
	}    
	public function getUniqId() {
		// take unique transaction id from okpay IPN post
		return $this->request->get('ok_txn_id'); 
	}
	public function validateTerms() {
		// compare our invoice payment settings, and what payment system handled 
		// if there is difference, it is possible that redirect url was modified 
		// before payment
		$currency = $this->request->get('ok_txn_currency');
		$amount = $this->request->get('ok_txn_gross');
		
		if ($currency && (strtoupper($this->invoice->currency) != $currency)) 
			throw new Am_Exception_Paysystem_TransactionInvalid("Wrong currency code [$currency] instead of {$this->invoice->currency}");
		
		if($amount && $amount != $this->invoice->first_total)
			throw new Am_Exception_Paysystem_TransactionInvalid("Payment amount is [$amount] instead of {$this->invoice->first_total}");
		
		return true;
	}
}
