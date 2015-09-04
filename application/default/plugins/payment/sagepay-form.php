<?php
/**
 * @table paysystems
 * @id sagepay-form
 * @title Sagepay Form
 * @visible_link http://www.sagepay.com/
 * @logo_url sagepay.png
 * @recurring none
 */
class Am_Paysystem_SagepayForm extends Am_Paysystem_Abstract {
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '4.6.3';
    
    protected $defaultTitle = 'Sagepay Form';
    protected $defaultDescription = 'Pay by credit card';
    
    const TEST_URL = "https://test.sagepay.com/gateway/service/vspform-register.vsp";
    const LIVE_URL = "https://live.sagepay.com/gateway/service/vspform-register.vsp";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('login')->setLabel(array('Your SagePay login', ''));
        $form->addPassword('pass')->setLabel(array('Your SagePay password', ''));
        $form->addAdvCheckbox('testing')->setLabel("Test Mode Enabled");        
    }
    public function init()
    {
        parent::init();
    }
    public function getSupportedCurrencies()
    {
        return array('AUD', 'CAD', 'CHF', 'DKK', 'EUR', 'GBP', 
            'HKD', 'IDR', 'JPY', 'LUF', 'NOK', 'NZD', 'SEK', 'SGD', 'TRL', 'USD');
    }
    public function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result) {
        $u = $invoice->getUser();
        $a  = new Am_Paysystem_Action_Form($this->getConfig('testing') ? self::TEST_URL : self::LIVE_URL);
        $a->VPSProtocol = '3.00';
        $a->TxType = 'PAYMENT';
        $a->Vendor = $this->getConfig('login');
        $vars = array(
            'VendorTxCode='.$invoice->public_id,
            'Amount='.$invoice->first_total,
            'Currency='.$invoice->currency,
            'Description='.$invoice->getLineDescription(),
            'SuccessURL='.$this->getPluginUrl('thanks'),
            'FailureURL='.$this->getCancelUrl(),
            'CustomerEmail='.$u->email,
            'VendorEmail='.$this->getDi()->config->get('admin_email'),
            'CustomerName='.$u->name_f . ' ' . $u->name_l,
        );
            
        // New mandatory fields for 3.00 protocol
        // All mandatory fields must contain a value, apart from the BillingPostcode/DeliveryPostCode.
        $surname    = ($u->name_l != '')    ? $u->name_l    : 'Surname';
        $firstname  = ($u->name_f != '')    ? $u->name_f    : 'Firstname';
        $address    = ($u->street != '')    ? $u->street    : 'Address';
        $city       = ($u->city != '')      ? $u->city      : 'City';
        $country    = ($u->country != '')   ? $u->country   : 'US';
        $state      = ($u->state != '')     ? $u->state     : 'AL';
        $zip        = ($u->zip != '')       ? $u->zip       : '12345';

        $vars[] = 'BillingSurname='.$surname;
        $vars[] = 'BillingFirstnames='.$firstname;
        $vars[] = 'BillingAddress1='.$address;
        $vars[] = 'BillingCity='.$city;
        $vars[] = 'BillingPostCode='.$zip;
        $vars[] = 'BillingCountry='.$country;
         
        $vars[] = 'DeliverySurname='.$surname;
        $vars[] = 'DeliveryFirstnames='.$firstname;
        $vars[] = 'DeliveryAddress1='.$address;
        $vars[] = 'DeliveryCity='.$city;
        $vars[] = 'DeliveryPostCode='.$zip;
        $vars[] = 'DeliveryCountry='.$country;
        
        if ($country == 'US') {
            //becomes mandatory when the BillingCountry/DeliveryCountry is set to US
            $vars[] = 'BillingState='.$state;
            $vars[] = 'DeliveryState='.$state;
        }
        /*
         * Important – if your business is classed as Financial Institution (Merchant code – 6012)
         * there are 4 additional fields that will need to be included with the transaction post from your system.

         * FIRecipientAcctNumber
         * FIRecipientSurname
         * FIRecipientPostcode
         * FIRecipientDoB
         */

        //$a->Crypt = base64_encode($this->sagepay_simple_xor(implode('&',$vars), $this->getConfig('pass')));
        $a->Crypt = self::encryptAes(implode('&',$vars), $this->getConfig('pass'));
                
        $a->filterEmpty();
        $result->setAction($a);
    }
//    public function sagepay_simple_xor($InString, $Key) {
//        // Initialise key array
//        $KeyList = array();
//        // Initialise out variable
//        $output = "";
//
//        // Convert $Key into array of ASCII values
//        for($i = 0; $i < strlen($Key); $i++){
//            $KeyList[$i] = ord(substr($Key, $i, 1));
//        }
//
//        // Step through string a character at a time
//        for($i = 0; $i < strlen($InString); $i++) {
//            // Get ASCII code from string, get ASCII code from key (loop through with MOD), XOR the two, get the character from the result
//            // % is MOD (modulus), ^ is XOR
//            $output.= chr(ord(substr($InString, $i, 1)) ^ ($KeyList[$i % strlen($Key)]));
//        }
//        // Return the result
//        return $output;
//    }

    public function createTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
    }
    public function createThanksTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_SagePayForm_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }    


    /**
     * PHP's mcrypt does not have built in PKCS5 Padding, so we use this.
     *
     * @param string $input The input string.
     *
     * @return string The string with padding.
     */
    static protected function addPKCS5Padding($input)
    {
        $blockSize = 16;
        $padd = "";

        // Pad input to an even block size boundary.
        $length = $blockSize - (strlen($input) % $blockSize);
        for ($i = 1; $i <= $length; $i++)
        {
            $padd .= chr($length);
        }

        return $input . $padd;
    }

    /**
     * Remove PKCS5 Padding from a string.
     *
     * @param string $input The decrypted string.
     *
     * @return string String without the padding.
     * @throws Am_Exception_Paysystem
     */
    static protected function removePKCS5Padding($input)
    {
        $blockSize = 16;
        $padChar = ord($input[strlen($input) - 1]);

        /* Check for PadChar is less then Block size */
        if ($padChar > $blockSize)
        {
            throw new Am_Exception_Paysystem('Invalid encryption string');
        }
        /* Check by padding by character mask */
        if (strspn($input, chr($padChar), strlen($input) - $padChar) != $padChar)
        {
            throw new Am_Exception_Paysystem('Invalid encryption string');
        }

        $unpadded = substr($input, 0, (-1) * $padChar);
        /* Chech result for printable characters */
        if (preg_match('/[[:^print:]]/', $unpadded))
        {
            throw new Am_Exception_Paysystem('Invalid encryption string');
        }
        return $unpadded;
    }

    /**
     * Encrypt a string ready to send to SagePay using encryption key.
     *
     * @param  string  $string  The unencrypyted string.
     * @param  string  $key     The encryption key.
     *
     * @return string The encrypted string.
     */
    static public function encryptAes($string, $key)
    {
        // AES encryption, CBC blocking with PKCS5 padding then HEX encoding.
        // Add PKCS5 padding to the text to be encypted.
        $string = self::addPKCS5Padding($string);

        // Perform encryption with PHP's MCRYPT module.
        $crypt = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $string, MCRYPT_MODE_CBC, $key);

        // Perform hex encoding and return.
        return "@" . strtoupper(bin2hex($crypt));
    }

    /**
     * Decode a returned string from SagePay.
     *
     * @param string $strIn         The encrypted String.
     * @param string $password      The encyption password used to encrypt the string.
     *
     * @return string The unecrypted string.
     * @throws Am_Exception_Paysystem
     */
    static public function decryptAes($strIn, $password)
    {
        // HEX decoding then AES decryption, CBC blocking with PKCS5 padding.
        // Use initialization vector (IV) set from $str_encryption_password.
        $strInitVector = $password;

        // Remove the first char which is @ to flag this is AES encrypted and HEX decoding.
        $hex = substr($strIn, 1);

        // Throw exception if string is malformed
        if (!preg_match('/^[0-9a-fA-F]+$/', $hex))
        {
            throw new Am_Exception_Paysystem('Invalid encryption string');
        }
        $strIn = pack('H*', $hex);

        // Perform decryption with PHP's MCRYPT module.
        $string = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $password, $strIn, MCRYPT_MODE_CBC, $strInitVector);
        return self::removePKCS5Padding($string);
    }
    
}

class Am_Paysystem_Transaction_SagePayForm_Thanks extends Am_Paysystem_Transaction_Incoming
{

    public function __construct(Am_Paysystem_Abstract $plugin, Am_Request $request, Zend_Controller_Response_Http $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
//        $s = base64_decode(str_replace(" ", "+", $request->get("Crypt",$request->get("crypt"))));
//        $s = $plugin->sagepay_simple_xor($s, $plugin->getConfig('pass'));
        $s = Am_Paysystem_SagepayForm::decryptAes($request->get("Crypt", $request->get("crypt")), $plugin->getConfig('pass'));
        parse_str($s, $this->vars);
    }

    public function getAmount()
    {
        return moneyRound($this->vars['Amount']);
    }
    
    public function getUniqId()
    {
        return $this->vars["VPSTxId"];
    }
    
    public function findInvoiceId(){
        return $this->vars["VendorTxCode"];
    }

    public function validateSource()
    {
        return true;
    }
    
    public function validateStatus()
    {
        return $this->vars['Status'] == 'OK';
    }
    public function validateTerms()
    {
        return true;
    }
    function getInvoice(){
        return $this->loadInvoice($this->findInvoiceId());
    }    
}