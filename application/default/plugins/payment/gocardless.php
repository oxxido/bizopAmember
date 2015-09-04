<?php
/**
 * @table paysystems
 * @id gocardless
 * @title GoCardless
 * @visible_link https://gocardless.com/
 * @recurring paysystem
 * @logo_url gocardless.png
 */
class Am_Paysystem_Gocardless extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '4.6.3';

    const LIVE_URL = "https://gocardless.com";
    const SANDBOX_URL = "https://sandbox.gocardless.com";
    
    protected $defaultTitle = 'GoCardless';
    protected $defaultDescription = 'Direct Debits online';

    public function getSupportedCurrencies()
    {
        return array('GBP');
    }
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', array('size' => 10))
            ->setLabel('Your Merchant ID');
        $form->addText('app_id', array('size' => 64))
            ->setLabel('Your App identifier');
        $form->addText('app_secret', array('size' => 64))
            ->setLabel('Your App secret');
        $form->addText('access_token', array('size' => 64))
            ->setLabel('Your Merchant access token');
        $form->addAdvCheckbox("testing")
             ->setLabel("Is it a Sandbox(Testing) Account?");
    }
    
    public function isConfigured()
    {
        return $this->getConfig('merchant_id') && $this->getConfig('app_id') && 
            $this->getConfig('app_secret') && $this->getConfig('access_token');
    }
    
    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        $rootURL = $this->getDi()->config->get('root_url');
        return <<<CUT
<b>GoCardless payment plugin configuration</b>
        
1. Enable "gocardless" payment plugin at aMember CP->Setup->Plugins

2. Configure "GoCardless" payment plugin at aMember CP -> Setup/Configuration -> GoCardless
   
3. Set up "Webhook URI" in your GoCardless merchant account to 
   $rootURL/payment/gocardless/ipn
       
   Set up "Redirect URI" and "Cancel URI" to $rootURL
       
4. You can test the payments in 'sandbox' mode using 
    Account number : 55779911
    Sort code : 20-00-00
CUT;
    }

    protected function generate_nonce()
    {
        $n = 1;
        $rand = '';
        do {
        $rand .= rand(1, 256);
        $n++;
        } while ($n <= 45);
        return base64_encode($rand);
    }
    
    public function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();        
        if(!is_null($invoice->second_period)){
            $a = new Am_Paysystem_Action_Redirect($url = ($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL) . '/connect/subscriptions/new');
            $coef = 1;
            if($invoice->second_period == Am_Period::MAX_SQL_DATE)
            {
                $interval_unit = 'month';
                $interval_length = 12*(2037-date('Y'));
            }
            else
            {
                $second_period = new Am_Period($invoice->second_period);
                switch ($second_period->getUnit())
                {
                    case 'd': $interval_unit = 'day'; break;
                    case 'm': $interval_unit = 'month'; break;
                    case 'y': $interval_unit = 'month'; $coef = 12; break;
                }
                $interval_length = $second_period->getCount();
            }
            $first_period = new Am_Period($invoice->first_period);            
            $start_at = new DateTime($first_period->addTo(date('Y-m-d')), new DateTimeZone('UTC'));
            $payment_details = array(
              'amount'          => $invoice->second_total,
              'interval_length' => $interval_length*$coef,
              'interval_unit'   => $interval_unit,
              'name'    => $invoice->getLineDescription(),
              'start_at' => $start_at->format('Y-m-d\TH:i:s\Z')
            );
            if($invoice->rebill_times != IProduct::RECURRING_REBILLS)
                $payment_details['interval_count'] = $invoice->rebill_times;
            if (doubleval($invoice->first_total)>0)
                  $payment_details['setup_fee'] = $invoice->first_total;
        }
        else
        {
            $a = new Am_Paysystem_Action_Redirect($url = ($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL) . '/connect/bills/new');
            $payment_details = array(
              'amount'  => $invoice->first_total,
              'name'    => $invoice->getLineDescription()
            );            
        }
        $user_details = array(
            'first_name' => $u->name_f,
            'last_name' => $u->name_l,
            'email' => $u->email,
            );
        $payment_details['merchant_id'] = $this->getConfig('merchant_id');
        ksort($payment_details);
        ksort($user_details);
        if(is_null($invoice->second_period))
        {
            foreach($payment_details as $v => $k)
                $a->__set("bill[$v]",$k);
            foreach($user_details as $v => $k)
                $a->__set("bill[user][$v]",$k);
        }
        $a->cancel_uri = $this->getCancelUrl();
        $a->client_id = $this->getConfig('app_id');
        $a->nonce = $this->generate_nonce();
        $a->redirect_uri = $this->getDi()->config->get('root_url') . "/payment/gocardless/thanks";
        $a->state = $invoice->public_id;
        if(!is_null($invoice->second_period))
        {
            foreach($payment_details as $v => $k)
                $a->__set("subscription[$v]",$k);
            foreach($user_details as $v => $k)
                $a->__set("subscription[user][$v]",$k);
        }
        $date = new DateTime(null, new DateTimeZone('UTC'));
        $a->timestamp = $date->format('Y-m-d\TH:i:s\Z');
        $url = parse_url($a->getUrl());
        $a->signature = hash_hmac('sha256',$url['query'], $this->getConfig('app_secret'));;
        $result->setAction($a);
    }
    
    public function directAction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        if($request->getRawBody())
        {
            $webhook = $request->getRawBody();
            $webhook_array = json_decode($webhook, true);
            $request = new Am_Request($webhook_array, $request->getActionName());
        }
        parent::directAction($request, $response, $invokeArgs);
    }

    function getReturnUrl(Zend_Controller_Request_Abstract $request = null)
    {
        return $this->getRootUrl() . "/thanks";
    }
    
    public function createTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gocardless($this, $request, $response, $invokeArgs);
    }
    public function createThanksTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gocardless_Thanks($this, $request, $response, $invokeArgs);
    }

}
class Am_Paysystem_Transaction_Gocardless_Bill extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->get('id');
    }
    public function findInvoiceId()
    {
        $request = $this->plugin->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'User-Agent' => 'gocardless-php/v0.3.3',
            'Authorization' => 'Bearer '.$this->plugin->getConfig('access_token'),
            ));
        $request->setUrl($this->request->get('uri'));
        $request->setMethod('GET');
        $response = $request->send();
        $response = json_decode($response->getBody(),true);
        $i =  Am_Di::getInstance()->invoiceTable->findFirstByData('gocardless_id', $response['source_id']);
        if($i) return $i->public_id;
        return null;        
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return $this->request->get('status') == 'paid';
    }

    public function validateTerms()
    {
        if(!$this->invoice) return true;
        return doubleval($this->request->get('amount')) == doubleval(($this->invoice->getPaymentsCount()==0) ? $this->invoice->first_total : $this->invoice->second_total);
    }
    public function autoCreate()
    {
        try {
            parent::autoCreate();
        }
        catch (Am_Exception_Paysystem $e)
        {
            Am_Di::getInstance()->errorLogTable->logException($e);
        }
    }
    public function processValidated()
    {
        if(!$this->invoice) return;
        parent::processValidated();
    }
}
class Am_Paysystem_Transaction_Gocardless extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        
    }

    public function validateSource()
    {
        $payload = $this->request->get('payload');
        ksort($payload);
        $sign = '';
        foreach($payload as $k => $v)
            if($k=='signature')
                continue;
            elseif($k=='bills')
                foreach($v as $bill)
                {
                    ksort($bill);
                    foreach($bill as $bk => $bv)
                        $sign.="&bills%5B%5D%5B$bk%5D=".urlencode($bv);
                }
            else
                $sign.="&$k=".urlencode($v);
            $sign=  substr($sign, 1);
       $hash = hash_hmac('sha256',$sign,$this->getPlugin()->getConfig('app_secret'));
       return $payload['signature'] == $hash;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }
    public function processValidated()
    {
        $payload = $this->request->get('payload');
        foreach($payload['bills'] as $bill)
        {
            $request = new Am_Request($bill, $this->request->getActionName());
            $transaction = new Am_Paysystem_Transaction_Gocardless_Bill($this->getPlugin(),$request, $this->response, $this->invokeArgs);
            $transaction->process();
        }
    }
    public function autoCreate()
    {
        return;
    }
}

class Am_Paysystem_Transaction_Gocardless_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        return $this->request->get('resource_id');
    }

    public function validateSource()
    {
        $query = http_build_query(array(
                'resource_id'    => $this->request->get('resource_id'),
                'resource_type'  => $this->request->get('resource_type'),
                'resource_uri'   => $this->request->get('resource_uri'),
                'state'      => $this->request->get('state')
                ), '', '&');
        return $this->request->get('signature') == hash_hmac('sha256', $query, $this->plugin->getConfig('app_secret'));
    }

    public function validateStatus()
    {
        //confirm payment
        $request = $this->plugin->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'User-Agent' => 'gocardless-php/v0.3.3'
            ));
        $request->setAuth($this->plugin->getConfig('app_id'), $this->plugin->getConfig('app_secret'));
        $request->setUrl(($this->plugin->getConfig('testing') ? Am_Paysystem_Gocardless::SANDBOX_URL : Am_Paysystem_Gocardless::LIVE_URL) . '/api/v1/confirm');
        $request->addPostParameter('resource_id', $this->request->get('resource_id'));
        $request->addPostParameter('resource_type', $this->request->get('resource_type'));
        $request->addPostParameter('resource_uri', $this->request->get('resource_uri'));
        $request->setMethod('POST');
        $response = $request->send();
        $response = json_decode($response->getBody(),true);
        if(!$response['success']) return false;
        
        //get bill id
        $request = $this->plugin->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'User-Agent' => 'gocardless-php/v0.3.3',
            'Authorization' => 'Bearer '.$this->plugin->getConfig('access_token'),
            ));
        $request->setUrl(($this->plugin->getConfig('testing') ? 
            Am_Paysystem_Gocardless::SANDBOX_URL : Am_Paysystem_Gocardless::LIVE_URL) . 
            '/api/v1/' .$this->request->get('resource_type') . 's/' . $this->request->get('resource_id'));
        $request->setMethod('GET');
        $response = $request->send();
        $response = json_decode($response->getBody(),true);
        $this->invoice->data()->set('gocardless_id', $this->request->get('resource_id'))->update();
        return true;
    }

    public function validateTerms()
    {
        //todo
        return true;
    }
    public function findInvoiceId()
    {
        return $this->request->get('state');
    }
    public function processValidated()
    {
        //only for free trial
        if(!doubleval($this->invoice->first_total))
            parent::processValidated();
    }
}