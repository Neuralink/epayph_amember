<?php
/**
 * @table paysystems
 * @id epayph
 * @title ePayph
 * @visible_link http://www.epay.ph/
 * @recurring paysystem
 * @logo_url epayph.png
 */
class Am_Paysystem_Epayph extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '0.0.1';

    public $domain = "";

    protected $defaultTitle = "ePay.ph";
    protected $defaultDescription = "secure credit card payment";
    
    protected $_canAutoCreate = false;
    protected $_canResendPostback = true;
    
    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'epayph_id', 
            "ePayph Button Item Number", 
            "if you want to use ePayph buttons, create button with \n".
            "the same billing settings, and enter its item number here"
            ,array(/*,'required'*/)
            ));
    }
    
    
    public function getSupportedCurrencies()
    {
        return array(
            'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY',
            'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF',
            'TWD', 'THB', 'USD', 'TRY', 'RUB');
    }
    
    public function  _initSetupForm(Am_Form_Setup $form)
    {
        $plugin = $this->getId();
        $form->addText("business", array('size'=>40))
             ->setLabel("Primary ePay Business Account");

        $form->addText("merchant_id", array('size'=>40))
             ->setLabel(array("Your Merchant ID", "You can get it from Your Account -> Profile"));
        
        $form->addAdvCheckbox("testing")
             ->setLabel("Is it a Sandbox(Testing) Account?");
        $form->addTextarea("alt_business", array('cols'=>40, 'rows'=>3,))
             ->setLabel("Alternate ePay account emails (one per line)");
        $form->addAdvCheckbox("dont_verify")
             ->setLabel(
            "Disable IPN verification\n" .
            "<b>Usually you DO NOT NEED to enable this option.</b>
            However, on some webhostings PHP scripts are not allowed to contact external
            web sites. It breaks functionality of the ePayph payment integration plugin,
            and aMember Pro then is unable to contact ePayph to verify that incoming
            IPN post is genuine. In this case, AS TEMPORARY SOLUTION, you can enable
            this option to don't contact ePayph server for verification. However,
            in this case \"hackers\" can signup on your site without actual payment.
            So if you have enabled this option, contact your webhost and ask them to
            open outgoing connections to www.epayph.com port 80 ASAP, then disable
            this option to make your site secure again.");
        $form->addText("lc", array('size'=>4))
             ->setLabel("ePayph Language Code\n" .
                "This field allows you to configure ePayph page language
                that will be displayed when customer is redirected from your website
                to ePayph for payment. By default, this value is empty, then ePayph
                will automatically choose which language to use. Or, alternatively,
                you can specify for example: en (for english language), or fr
                (for french Language) and so on. In this case, ePayph will not choose
                language automatically. <br />
                Default value for this field is empty string");
        $form->addText("page_style")
            ->setLabel("ePayph Page Style" . "\n" . "use the custom payment page
                style from your account profile that has the specified name.
                Default value for this field is empty string");
        $form->addAdvCheckbox("accept_pending_echeck")
             ->setLabel("Recognize pending echeck payments as completed");
    }

    function init()
    {
        $this->domain = $this->getConfig('testing') ? 'www.sandbox.epayph.com' : 'www.epay.ph';
    }
    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }
    public function isNotAcceptableForInvoice(Invoice $invoice) 
    {
        if ($err = parent::isNotAcceptableForInvoice($invoice))
            return $err;
        if ($invoice->rebill_times >= 1 && $err = $this->checkPeriod(new Am_Period($invoice->first_period)))
            return array($err);
        if ($invoice->rebill_times >= 2 && $err = $this->checkPeriod(new Am_Period($invoice->second_period)))
            return array($err);
        if ($invoice->rebill_times != IProduct::RECURRING_REBILLS && $invoice->rebill_times > 52)
            return array('ePayph can not handle subscription terms with number of rebills more than 52');
    }
    /**
     * Return error message if period could not be handled by ePayph
     * @param Am_Period $p
     */
    public function checkPeriod(Am_Period $p){
        $period = $p->getCount();
        switch ($unit = strtoupper($p->getUnit())){
        case 'Y':
            if (($period < 1) or ($period > 5))
                return ___('Period must be in interval 1-5 years');
            break;
        case 'M':
            if (($period < 1) or ($period > 24))
                return ___('Period must be in interval 1-24 months');
            break;
        case 'D':
            if (($period < 1) or ($period > 90))
                 return ___('Period must be in interval 1-90 days');
            break;
        default:
            return sprintf(___('Unknown period unit: %s'), $unit);
        }
    }
    function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result)
    {
        if (!$this->getConfig('business'))
            throw new Am_Exception_Configuration("There is a configuration error in [epayph] plugin - no [business] e-mail configured");
        $a = new Am_Paysystem_Action_Redirect('https://' . $this->domain . '/checkout/api/');
        $result->setAction($a);
        $a->business      = $this->getConfig('business');
        $a->return        = $this->getReturnUrl();
        $a->notify_url    = $this->getPluginUrl('ipn');
        $a->cancel_return = $this->getCancelUrl();
        $a->item_name     = $invoice->getLineDescription();
        $a->no_shipping   = $invoice->hasShipping() ? 0 : 1;
        $a->shipping      = $invoice->first_shipping;
        $a->currency_code = strtoupper($invoice->currency);
        $a->no_note       = 1;
        $a->invoice       = $invoice->getRandomizedId();
        $a->bn            = 'CgiCentral.aMemberPro';
        $a->first_name    = $invoice->getFirstName();
        $a->last_name     = $invoice->getLastName();
        $a->address1      = $invoice->getStreet();
        $a->city          = $invoice->getCity();
        $a->state         = $invoice->getState();
        $a->zip           = $invoice->getZip();
        $a->country       = $invoice->getCountry();
        $a->charset       = 'utf-8';
        if ($lc = $this->getConfig('lc'))
            $a->lc = $lc;
        if ($page_style = $this->getConfig('page_style'))
            $a->page_style = $page_style;
        $a->rm  = 2;
        if ($invoice->rebill_times)
        {
            $a->cmd           = '_xclick-subscriptions';
            $a->sra = 1;
            /** @todo check with rebill times = 1 */
            $p1 = new Am_Period($invoice->first_period);
            $p3 = new Am_Period($invoice->second_period);
            $a->a3 = $invoice->second_total;
            $a->p3 = $p3->getCount();
            $a->t3 = $this->getPeriodUnit($p3->getUnit());
            $a->tax3 = $invoice->second_tax;
            if($invoice->first_total == $invoice->second_total && 
                $invoice->first_period == $invoice->second_period && 
                $invoice->first_tax == $invoice->second_tax)
            {
                $a->src = 1; //Ticket #HPU-80211-470: epayph_r plugin not passing the price properly (or at all)?
                if ($invoice->rebill_times != IProduct::RECURRING_REBILLS )
                    $a->srt = $invoice->rebill_times + 1;                    
            }
            else
            {
                if ($invoice->rebill_times == 1)
                {
                    $a->src = 0;
                } else {
                    $a->src = 1; //Ticket #HPU-80211-470: epayph_r plugin not passing the price properly (or at all)?
                    if ($invoice->rebill_times != IProduct::RECURRING_REBILLS )
                        $a->srt = $invoice->rebill_times;
                }
                $a->a1 = $invoice->first_total;
                $a->p1 = $p1->getCount();
                $a->t1 = $this->getPeriodUnit($p1->getUnit());
                $a->tax1 = $invoice->first_tax;
            }

        } else  {
            $a->cmd           = '_xclick';
            $a->amount = $invoice->first_total - $invoice->first_tax - $invoice->first_shipping;
            $a->tax = $invoice->first_tax;
        }
    }
    function getPeriodUnit($unit){
        $units = array('D', 'M', 'Y');
        $unit = strtoupper($unit);
        if (!in_array($unit, $units))
            throw new Am_Exception_Paysystem("Unfortunately ePayph could not handle period unit [$unit], please choose another payment method");
        return $unit;
    }
    
    public function createTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Epayph_Transaction($this, $request, $response, $invokeArgs);
    }
    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
return <<<CUT
<b>ePayph payment plugin installation</b>

Up to date instructions how to enable and configure ePayph plugin you  may find at 
<a href='http://www.amember.com/docs/Payment/ePayph'>http://www.amember.com/docs/Payment/ePayph</a>

IPN URL to enter into ePayph settings:
  <b><i>$url</i></b>
      
It is only necessary to enable IPN in ePayph. If IPN is already enabled, it does not matter
what exactly URL is specified. aMember will automatically let ePayph know to use aMember URL.
CUT;
    }
    
    
    function getUserCancelUrl(Invoice $invoice)
    {
        return 'https://' . $this->domain . '/checkout/api/r.php?' . http_build_query(array(
            'invoice' => $invoice->getRandomizedId(),
        ), '', '&');
    }
    
    public function getAdminCancelUrl(Invoice $invoice)
    {
        return 'https://' . $this->domain . '/checkout/api/r.php' . http_build_query(array(
            'invoice' => $invoice->getRandomizedId(),
        ), '', '&');
    }
    
    public function canAutoCreate()
    {
        return true;
    }
    
}

class Am_Paysystem_Epayph_Transaction extends Am_Paysystem_Transaction_Epayph
{
    protected $_autoCreateMap = array(
        'name_f'    =>  'first_name',
        'name_l'    =>  'last_name',
        'email'     =>  'payer_email',
        'street'    =>  'addres_street',
        'zip'       =>  'address_zip',
        'state'     =>  'address_state',
        'country'   =>  'address_country_code',
        'city'      =>  'address_city',
        'user_external_id' => 'payer_id',
        'invoice_external_id' => array('parent_txn_id', 'subscr_id', 'txn_id') ,
    );
    public function processValidated()
    { 
        switch ($this->txn_type) {
            case self::TXN_SUBSCR_SIGNUP:
                if ((float)$this->invoice->first_total <= 0) // no payment will be reported
                    if ($this->invoice->status == Invoice::PENDING) // handle only once
                        $this->invoice->addAccessPeriod($this); // add first trial period
            break;
            case self::TXN_SUBSCR_EOT:
                // Stop access only if we have lifetime access within invoice. 
                // This is done to handle records imported from v3 
                if(Am_Di::getInstance()->getDbService()->selectCell('
                    SELECT count(*) 
                    FROM  ?_access 
                    WHERE invoice_id = ? and expire_date = ?', $this->invoice->pk(), Am_Period::MAX_SQL_DATE))
                $this->invoice->stopAccess($this);
            break;
            case self::TXN_SUBSCR_CANCEL:
                $this->invoice->setCancelled(true);
            break;
            case self::TXN_WEB_ACCEPT:
            case self::TXN_SUBSCR_PAYMENT:
            case self::TXN_CART:
                switch ($this->request->payment_status)
                {
                    case 'Completed':
                        $this->invoice->addPayment($this);
                        break;
                    case 'Pending':
                        if($this->plugin->getConfig('accept_pending_echeck') && $this->request->payment_type == 'echeck')
                            $this->invoice->addPayment($this);
                        break;
                    default:
                }
            break;
        }
        switch($this->request->payment_status){
           case 'Refunded':
           case 'Chargeback':
               $this->invoice->addRefund($this, $this->request->parent_txn_id, $this->getAmount());
           break;
        }
    }
    public function validateStatus()
    {
        $status = $this->request->getFiltered('status');
        if($this->plugin->getConfig('accept_pending_echeck') 
            && $this->request->getFiltered('payment_type') == 'echeck')
        {
            if($this->request->getFiltered('payment_status') == 'Pending' || $status == 'Pending')
                return true;
        }
        return $status === null || $status === 'Completed';
    }
    public function validateTerms()
    {
        $currency = $this->request->getFiltered('mc_currency');
        if ($currency && (strtoupper($this->invoice->currency) != $currency))
            throw new Am_Exception_Paysystem_TransactionInvalid("Wrong currency code [$currency] instead of {$this->invoice->currency}");
        if (in_array($this->txn_type, array(self::TXN_CART, self::TXN_SUBSCR_PAYMENT, self::TXN_WEB_ACCEPT)))
        {
            $isFirst = $this->invoice->first_total && !$this->invoice->getPaymentsCount();
            if($this->invoice->first_total == $this->invoice->second_total && $this->invoice->first_period == $this->invoice->second_period)
            {
                $isFirst = false;
            }
            $expected = $isFirst ? $this->invoice->first_total : $this->invoice->second_total;
            if ($expected > ($amount = $this->getAmount()))
                throw new Am_Exception_Paysystem_TransactionInvalid("Payment amount is [$amount], expected not less than [$expected]");
        } elseif ($this->txn_type == self::TXN_SUBSCR_SIGNUP) {
            if ($this->invoice->first_total != $this->invoice->second_total || $this->invoice->first_period != $this->invoice->second_period)
            {
                if ($this->invoice->first_total  != $this->request->get('mc_amount1')) return false;
            }
            if (""                           != $this->request->get('mc_amount2')) return false;
            if ($this->invoice->second_total != $this->request->get('mc_amount3')) return false;
            if ($this->invoice->currency != $this->request->get('mc_currency')) return false;
            $p1 = new Am_Period($this->invoice->first_period);
            $p3 = new Am_Period($this->invoice->second_period);
            try {
                $p1 = $p1->getCount() . ' ' . $this->plugin->getPeriodUnit($p1->getUnit());
                $p3 = $p3->getCount() . ' ' . $this->plugin->getPeriodUnit($p3->getUnit());
            } catch (Exception $e) {  }
            if ($this->invoice->first_total != $this->invoice->second_total || $this->invoice->first_period != $this->invoice->second_period)
            {
                if ($p1  != $this->request->get('period1')) return false;
            }
            if (""   != $this->request->get('period2')) return false;
            if ($p3  != $this->request->get('period3')) return false;
        }
        return true;
    }
    public function autoCreateGetProducts()
    {
        $item_number = $this->request->get('item_number', $this->request->get('item_number1'));
        if (empty($item_number)) return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('epayph_id', $item_number);
        if($billing_plan) return array($billing_plan->getProduct());
    } 
}
