<?php
/**
 * 
 * @author Samuel Apraku
 * @license   Apache-2.0
 *
 */

class Payment_Adapter_Paystack implements \Box\InjectionAwareInterface
{
    const ENDPOINT = 'https://api.paystack.co/transaction';
    const TXN_SUCCESS = 'success';

    private $config = array();

    protected $di;

    private $url;

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (!function_exists('curl_exec')) {
            throw new Payment_Exception('PHP Curl extension must be enabled in order to use Paystack gateway');
        }

        if (!isset($this->config['live_public_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update "Live Public Key" parameter.');
        }
        
        if (!isset($this->config['live_secret_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update "Live Secret Key" parameter.');
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Enter your Paystack Secret Key to start accepting payments by Paystack.',
            'description_client' => 'Fee: 2% (MoMo, Debit/Credit Cards)',
            'can_load_in_iframe' => true,
	    'logo' => array(
                'logo' => 'paystack.png',
                'height' => '30px',
                'width' => '65px',
            ),
            'form' => array(
                'live_public_key' => array('text', array(
                    'label' => 'Live Public Key',
                ),),
                'live_secret_key' => array('text', array(
                    'label' => 'Live Secret Key',
                ),),
                'test_public_key' => array('text', array(
                    'label' => 'Test Public Key',
                ),),
                'test_secret_key' => array('text', array(
                    'label' => 'Test Secret Key',
                ),),
                'charge' => array('text', array(
                    'label' => 'Transaction Charge (%)',
                ),
                ),
                'auto_process_invoice' => array('radio', array(
                    'label' => 'Process invoice after payment',
                    'multiOptions' => array('1'=>'Yes', '0'=>'No')
                ),
                )
            ),
        );
    }

    public function getPublicKey(){
        if($this->config['test_mode']) {
            return $this->config['test_public_key'];
        }
        else return $this->config['live_public_key'];
    }

    public function getSecretKey(){
        if($this->config['test_mode']) {
            return $this->config['test_secret_key'];
        }
        else return $this->config['live_secret_key'];
    }
    
    /**
     * Payment gateway endpoint
     *
     * @return string
     */
    public function getServiceUrl()
    {
        return $this->url;
    }

    /**
     * Return payment gateway type
     * @return string
     */
    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_HTML;
    }

  
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(array('id' => $invoice_id));
        // Paystack requires amount to be in pesewas/cents
        $amount = round($this->getAmountInDefaultCurrency($invoice["currency"], $invoice["total"]) * 100, 0, PHP_ROUND_HALF_UP); 
        
        $fee = 0.00;
              
        if(is_numeric($this->config["charge"]) && $this->config["charge"] > 0){
            $fee = round(($amount * $this->config["charge"] / 100 ), 0, PHP_ROUND_HALF_UP);
        }

        if($fee > 0){
            $amount += $fee;
        }

        $form = '<div class="text-center">' . PHP_EOL;
        $form .= '<p>Pay directly with MoMo/Debit/Credit Card.<br><strong>2% Fee</strong></p>'.PHP_EOL;
        $form .= '<form id="paymentForm">' . PHP_EOL;
        $form .= '<input type="submit" class="btn btn-alt btn-primary btn-large" value="Complete Payment with Paystack" />'.PHP_EOL;
        $form .= '</form>'. PHP_EOL;
        $form .= '</div>'.PHP_EOL;
        $form .= '<script src="https://js.paystack.co/v1/inline.js"></script>'.PHP_EOL;
        $form .= "<script type='text/javascript'>
                    const paymentForm = document.getElementById('paymentForm');
                    paymentForm.addEventListener('submit', payWithPaystack, false);
                    function payWithPaystack(e) {
                    e.preventDefault();
                    let handler = PaystackPop.setup({
                    key:'".$this->getPublicKey()."', 
                    email:'".$invoice['buyer']['email']."',
                    amount: $amount,
                    firstname:'".$invoice['buyer']['first_name']."',
                    lastname:'".$invoice['buyer']['last_name']."',
                    currency: '".$this->getDefaultCurrency()."',
                    metadata: { 
                        bb_gateway_id:".$invoice['gateway_id'].",
                        bb_invoice_id:".$invoice['id'].",
                        custom_fields: [
                                        {
                                           display_name: 'Invoice ID',
                                           variable_name: 'bb_invoice_id',
                        	               value:". $invoice['id']."
                                           }
                                            ]
                                        },
                    onClose: function(){
                                alert('Payment has been cancelled.');
                            },
                    callback: function(response){
                                let message = 'Payment complete! Reference: ' + response.reference;
                                alert(message);
                                }
                                });
                                handler.openIframe();
                            }
                </script>".PHP_EOL;    

        return $form;
    }


    public function getInvoiceTitle(array $invoice)
    {
        $p = array(
            ':id' => sprintf('%05s', $invoice['nr']),
            ':serie' => $invoice['serie'],
        );
        return __('Payment for invoice :serie:id', $p);
    }


    /**
     * @param $api_admin - admin api
     * @param $id - transaction id from db
     * @param $data - ipn data
     * @param $gateway_id - gateway id
     */

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if(APPLICATION_ENV != 'testing' && !$this->isIpnValid($data)) {
            throw new Payment_Exception('Paystack IPN is not valid');
        }

        $ipn = $this->_getIpnObject($data); // paystack returns post body in webhook
        $tx = $api_admin->invoice_transaction_get(['id' => $id]);
        $invoice_id = isset($tx['invoice_id']) ? $tx['invoice_id'] :  $ipn->data->metadata->bb_invoice_id;
        if($tx['status'] === Model_Transaction::STATUS_PROCESSED)
        {
            return;
        }

        $reference = $ipn->data->reference;
        $amount = $ipn->data->amount * 1/100;
        $currency = $ipn->data->currency;

        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
        $client_id = $invoice['client']['id'];

        $tx_data = ['id' => $id];
        if (!$tx['invoice_id']) {
            $tx_data['invoice_id'] = $invoice_id;
            $api_admin->invoice_transaction_update($tx_data);
        }

        if($tx['status'] === Model_Transaction::STATUS_RECEIVED) {
            $this->verifyTransaction($api_admin, $id, $data);
        }

        if (!$tx['amount']) {
            $tx_data['amount'] = $amount;
        }
        if (!$tx['currency']) {
            $tx_data['currency'] = $currency;
        }
        
        if (!$tx['txn_id']) {
            $tx_data['txn_id'] = $reference;
        }
        if (!$tx['type']) {
            $tx_data['type'] = \Payment_Transaction::TXTYPE_PAYMENT;
        }

        if($ipn->event === 'charge.success') {
            $markAsPaid = $this->config['auto_process_invoice'] ?? false;

            $this->di['logger']->info("Processing transaction from Paystack with id: " .$reference);
            
            if ($markAsPaid){
                $this->di['logger']->info("Executing");
                if($ipn->data->status === 'success') {
                    $this->di['logger']->info("IPN success.");
                    // Don't execute. let cron activate it
                    $api_admin->invoice_mark_as_paid([
                        'id'=> $invoice_id,
                        'check_product_setup' => true
                    ]);
                }
            } 
        }

        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);

        if ($invoice['status'] === \Model_Invoice::STATUS_PAID) {
            $tx_data['status'] = Model_Transaction::STATUS_PROCESSED;
        }
        $api_admin->invoice_transaction_update($tx_data);
    }

    private function _getIpnObject($ipn){
        return json_decode($ipn['http_raw_post_data']); 
    }

    private function _isSuccessEvent($ipnObject) : bool {
        return $ipnObject->event === 'charge.success';
    }


    public function isIpnDuplicate($txn_id, $invoice_id, $gateway_id, $amount, $ipn)
    {
        $sql = 'SELECT id
                FROM transaction
                WHERE txn_id = :transaction_id
                  AND txn_status = :transaction_status
                  AND type = :transaction_type
                  AND amount = :transaction_amount
                LIMIT 2';

        $bindings = array(
            ':transaction_id' => $txn_id,
            ':transaction_status' => $ipn['data']['status'],
            ':transaction_type' => $ipn['txn_type'],
            ':transaction_amount' => $amount,
        );

        $rows = $this->di['db']->getAll($sql, $bindings);
        if (count($rows) > 1){
            return true;
        }


        return false;
    }

    public function verifyTransaction($api_admin, $id, $ipn)
    {       
        $ipnObj = $this->_getIpnObject($ipn);
        if(!$this->_issuccessEvent($ipnObj)) {
            return false;
        }

        $reference = $ipnObj->data->reference;

        $response = $this->request("/verify/".$reference);
        if(!$response) return false;

        $obj = json_decode($response);
        $status = "unknown";
        if (isset($obj->status) && $obj->status) {
            $txn = $api_admin->invoice_transaction_get(['id' => $id]);
            $status = Model_Transaction::STATUS_APPROVED;
            if ($txn['status'] === Model_Transaction::STATUS_PROCESSED ) {
                $status = Model_Transaction::STATUS_PROCESSED;
            }
            $d = [
                'id' => $id,
                'status' => $status,
                'txn_status' => $obj->data->status,
                'note' => $obj->message,
                'output' => $obj->data,
                'error' => '',
                'error_code' => null,
            ];
            
        } else {
            $d = [
                'id' => $id,
                'status' => Model_Transaction::STATUS_RECEIVED,
                'error' => $obj->message,
            	'error_code' => null,
                'txn_status' => $status,
            ];
        }
        $d['updated_at'] = date('Y-m-d H:i:s');
        $api_admin->invoice_transaction_update($d);
        return $obj->status;

    }

	/**
	 * @param string $url
	 */
	private function request($path, $post_vars = array(), $pheaders = array())
    {
        $post_contents = array();
        
		if ($post_vars) {
            if(is_array($post_vars)){
                $post_contents = array_merge($auth_params, $post_vars);
            }
        }

        $secretKey = $this->getSecretKey();
        
        if (!empty($pheaders)) {
			if (!is_array($pheaders)) {
				$headers[count($headers)] = $pheaders;
			} else {
				$next = count($headers);
				$count = count($pheaders);
				for ($i = 0; $i < $count; $i++) { $headers[$next + $i] = $pheaders[$i]; }
			}
		}
		    
        $url = self::ENDPOINT.$path;

        $ch = curl_init();
		curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                                    "Authorization: Bearer $secretKey",
                                    "Cache-Control: no-cache",
                                    ),
        ));

		$data = curl_exec($ch);      
		if (curl_errno($ch)) return false;
		curl_close($ch);
		return $data;
	}
    

    /**
     * Generate links for performing actions required by gateway
     */
    public function getActions()
    {
        return array(
            array(
                'name' => 'verify',
                'label' => 'Verify Transaction',
            ),
        );
    }

    /**
     * process actions to be performed by gateway
     */
    public function processAction($api_admin, $id, $ipn, $gateway_id, $action)
    {

        switch ($action) {
            case "verify":
                return $this->verifyTransaction($api_admin, $id, $ipn);
                break;
            default:
                return;

        }
    }

    protected function getAmountInDefaultCurrency($currency, $amount)
    {
        $currencyService = $this->di['mod_service']('currency');
        return $currencyService->toBaseCurrency($currency, $amount);

    }

    protected function getDefaultCurrency()
    {
        $currencyService = $this->di['mod_service']('currency');
        $default = $currencyService->getDefault();
        return $default->code;
    }
     
	private function isIpnValid($data)
    {
        // only a post with paystack signature header gets our attention
        $server = $data['server'];
        $input = $data['http_raw_post_data'];

        define('PAYSTACK_SECRET_KEY', $this->getSecretKey());
        
       // validate event do all at once to avoid timing attack
       if(isset($server['HTTP_X_PAYSTACK_SIGNATURE']) ){
            return $server['HTTP_X_PAYSTACK_SIGNATURE'] === hash_hmac('sha512', $input, PAYSTACK_SECRET_KEY);
        }
        return false;
    }
}
