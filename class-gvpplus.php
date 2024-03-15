<?php
use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;

class GiveWPPplusGateways extends PaymentGateway {
    public $secureRouteMethods = [ 'handleCreatePaymentRedirect',];
    public function __construct(){
        add_action('rest_api_init', function () {
            register_rest_route( 'gvpplus/v1', '/donation-failed/',array(
                'methods'  => WP_REST_Server::ALLMETHODS,
                'callback' => array($this,'gvpplus_api_donation_failed_response'),
              ));
            register_rest_route( 'gvpplus/v1', '/donation-callback/',array(
                'methods'  => WP_REST_Server::ALLMETHODS,
                'callback' => array($this,'gvpplus_api_donation_callback_response'),
              ));
        });

       
    }
    public static function id(): string {
        return 'givewp-pplus-gateway';
    }
    public function getId(): string {
        return self::id();
    }
    public function getName(): string {
        return __('PayPlus Gateway', 'gvpplus');
    }
    public function getPaymentMethodLabel(): string {
        return __('PayPlus Gateway', 'gvpplus');
    }
    public function getLegacyFormFieldMarkup(int $formId, array $args): string {
        $options=$this->gvpplus_get_option();
        $desc=isset($options['gvpplus_description']) ? $options['gvpplus_description'] : "Pay securely by Debit or Credit Card through PayPlus";
        return "<div class='example-offsite-help-text'>
                    <p>".__($desc, 'gvpplus')."</p>
                </div>";
    }

    public function gvpplus_pay_modes($options){
        $payment_mode=isset($options['test_mode']) ? $options['test_mode'] : "disabled";
        if($payment_mode=='enabled'){
            $post_url='https://restapidev.payplus.co.il/api/v1.0/PaymentPages/generateLink';
        }else{
            $post_url='https://restapi.payplus.co.il/api/v1.0/PaymentPages/generateLink'; 
        }
        return $post_url;
        
    }
    public function gvpplus_refund_modes($options){
        $payment_mode=isset($options['test_mode']) ? $options['test_mode'] : "disabled";
        if($payment_mode=='enabled'){
            $post_url='https://restapidev.payplus.co.il/api/v1.0/Transactions/RefundByTransactionUID';
        }else{
            $post_url='https://restapi.payplus.co.il/api/v1.0/Transactions/RefundByTransactionUID'; 
        }
        return $post_url;
    }
    public function gvpplus_payload_post(Donation $donation): array {
        $options=$this->gvpplus_get_option();
        $page_uid=isset($options['gvpplus_page_uid']) ? $options['gvpplus_page_uid'] : "";
        $api_key=isset($options['gvpplus_api_key']) ? $options['gvpplus_api_key'] : "";
        $secret_key=isset($options['gvpplus_secret_key']) ? $options['gvpplus_secret_key'] : "";
        ////give_get_failed_transaction_uri(),
        $payload=array(
            'payment_page_uid'=>$page_uid,
            'charge_method'=>1,
            'expiry_datetime'=>"30",
            'hide_other_charge_methods'=>true,
            'language_code'=>'en',
            'refURL_success'=> give_get_success_page_uri(),
            'refURL_failure'=> get_rest_url(null,'gvpplus/v1/donation-failed/'), 
            'refURL_callback'=> get_rest_url(null,'gvpplus/v1/donation-callback/'),
            'charge_default'=>'credit-card',
            'customer'=>array(
                'email'=>$donation->email,
                'customer_name'=>$donation->firstName.' '.$donation->lastName,
            ),
            'items'=> array(
                array(
                    'name'=>$donation->formTitle,
                    'barcode'=>$donation->formTitle,
                    'quantity'=>1,
                    'price'=>$donation->amount->formatToDecimal(),
                    'vat_type'=>0
                )
            ),
            'amount'=>$donation->amount->formatToDecimal(),
            'currency_code'=>$options['currency'],
            'sendEmailApproval'=>false,
            'sendEmailFailure'=>false,
            'create_token'=>true,
            'initial_invoice'=>false,
            'more_info'=> $donation->id,
            'more_info_1'=> sprintf(__('Donation via GiveWP, ID %s', 'gvpplus'), $donation->id),

        );

        $args= array(
        'body' => json_encode($payload),
        'timeout' => '60',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => [],
        'headers' => array(
            'User-Agent' => 'WordPress '.$_SERVER['HTTP_USER_AGENT'],
            'Content-Type' => 'application/json',
            'Authorization' => '{"api_key":"'.$api_key.'","secret_key":"'.$secret_key.'"}'
        )
    );
        $response = wp_remote_post($this->gvpplus_pay_modes($options), $args);
        $wp_error='WP erros-';
        if (is_wp_error($response)) {
            throw new PaymentGatewayException(
                sprintf(__("[%s] PayPlus connect is Failed, please try again.",'gvpplus'),$wp_error)
            );
        } else {
            $res=wp_remote_retrieve_body($response);
            $respond=json_decode($res,true);
            $result=isset($respond['results']) ? $respond['results'] : '';
            if($result['status']=='success' && $result['code']==0){
                DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(esc_html__('Donation: %s', 'gvpplus'), $result['description'])
              ]);
             $data=$respond['data'];
             $data['response']=true;
             return $data;

            } else {
                if(is_array($respond)){
                     DonationNote::create([
                     'donationId' => $donation->id,
                     'content' => sprintf(esc_html__('Something went wrong with the payment page. Reason: %s', 'gvpplus'), $result['description'])
                    ]);

                    return array('response'=>false,'rests'=>$result['description']);

                }else{

                     DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(esc_html__('Something went wrong with the payment page. Reason: %s', 'gvpplus'), $res)
                    ]);

                    return array('response'=>false,'rests'=>$res);
                }

            }
        }

    }

    public function createPayment(Donation $donation, $gatewayData) {
        $paypload=$this->gvpplus_payload_post($donation);
        if($paypload['response']){

        $returnUrl = $this->generateSecureGatewayRouteUrl(
            'handleCreatePaymentRedirect',
            $donation->id,
            [
                'givewp-donation-id' => $donation->id,
                'givewp-success-url' => urlencode(give_get_success_page_uri()),
                'page-request-uid' => $paypload['page_request_uid'],
            ]
        );

        $queryParams = array('return_url' => $returnUrl);
        $gatewayUrl = add_query_arg($queryParams, $paypload['payment_page_link']);
        return new RedirectOffsite($gatewayUrl);
        }else{
            throw new PaymentGatewayException(
                sprintf(__("PayPlus Token Payment page Failed ",'gvpplus'),$paypload['rests'])
            );
        }
    }

    protected function handleCreatePaymentRedirect(array $queryParams): RedirectResponse {

        file_put_contents(dirname(__FILE__) . '/payplus_success_queryparam.txt', json_encode($queryParams));
        file_put_contents(dirname(__FILE__) . '/payplus_success_queryparam_get.txt', json_encode($_GET));

        $donationId = $queryParams['givewp-donation-id'];
        $gatewayTransactionId = $queryParams['transaction_uid'];
        $successUrl = $queryParams['givewp-success-url'];
        $status_code = $queryParams['status_code'];
        $status = $queryParams['status'];
        $status_description = $queryParams['status_description'];
        $donation_id=$queryParams['more_info'];
        $donation = Donation::find($donationId);
        if($status_code == '000' && $status== 'approved' && $donation_id==$donation->id){

            $donation->status = DonationStatus::COMPLETE();
            $donation->gatewayTransactionId = $gatewayTransactionId;
            $donation->save();
            DonationNote::create([
            'donationId' => $donation->id,
            'content' =>  sprintf(esc_html__('Donation Completed from Payplus. Description: %s', 'gvpplus'), $status_description)
          ]);

        }else{

            $donation->status = DonationStatus::PROCESSING();
            $donation->gatewayTransactionId = $gatewayTransactionId;
            $donation->save();
            DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(esc_html__('Donation Completed from Payplus. Description: %s', 'gvpplus'), $status_description)
          ]);
        }

        return new RedirectResponse($successUrl);
    }

    public function refundDonation(Donation $donation): PaymentRefunded {
        $options=$this->gvpplus_get_option();
        $api_key=isset($options['gvpplus_api_key']) ? $options['gvpplus_api_key'] : "";
        $secret_key=isset($options['gvpplus_secret_key']) ? $options['gvpplus_secret_key'] : "";

        $payload=array(
            'transaction_uid'=>$donation->gatewayTransactionId,
            'amount'=>$donation->amount->formatToDecimal(),
            'more_info'=>$donation->id,
            'more_info_1'=>sprintf(__('Refund via GiveWP, ID %s', 'gvpplus'), $donation->id),
        );
        $args= array(
        'body' => json_encode($payload),
        'timeout' => '60',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => [],
        'headers' => array(
            'User-Agent' => 'WordPress '.$_SERVER['HTTP_USER_AGENT'],
            'Content-Type' => 'application/json',
            'Authorization' => '{"api_key":"'.$api_key.'","secret_key":"'.$secret_key.'"}'
        )
     );
      if (floatval($donation->amount->formatToDecimal())) {
        $response = wp_remote_post($this->gvpplus_refund_modes($options), $args);
        $wp_error='WP erros-';
        if (is_wp_error($response)) {
            throw new PaymentGatewayException(
                sprintf(__("[%s] PayPlus Refund is Failed, please try again.",'gvpplus'),$wp_error)
            );
        }else{
            $res = json_decode(wp_remote_retrieve_body($response));
            file_put_contents(dirname(__FILE__) . '/payplus_refund_data.txt', $res);
            if ($res->results->status == "success" && $res->data->transaction->status_code == "000") {
                  
                    DonationNote::create([
                        'donationId' => $donation->id,
                        'content' => sprintf(esc_html__('PayPlus Refund is Successful<br />Refund Transaction Number: %s<br />Amount: %s %s<br />Reason: %s', 'gvpplus'), $res->data->transaction->number, $res->data->transaction->amount, $options['currency'], $res->results->description)
                    ]);
                     return new PaymentRefunded();
                } else {
                    DonationNote::create([
                        'donationId' => $donation->id,
                        'content' => sprintf(esc_html__('PayPlus Refund is Failed<br />Status: %s<br />Description: %s', 'gvpplus'), $res->results->status, $res->results->description)
                    ]);
                    return false;
                }
        }

       }else{
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(esc_html__('Amount is not floating value %s', 'gvpplus'), $donation->amount->formatToDecimal())
            ]);
            return false;
       }
         
    }
    public function gvpplus_get_option(){
        return get_option('give_settings');
    }
    public function gvpplus_api_donation_failed_response(){
        $params = $_REQUEST;
        $donationId=isset($params['more_info']) ? $params['more_info'] : "";
        $gatewayTransactionId=isset($params['transaction_uid']) ? $params['transaction_uid'] : "";
        $status_description=isset($params['status_description']) ? $params['status_description'] : "";
        if($donationId && $gatewayTransactionId){
            $donation = Donation::find($donationId);
            $donation->status = DonationStatus::FAILED();
            $donation->gatewayTransactionId = $gatewayTransactionId;
            $donation->save();
            DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(esc_html__('PayPlus Payment is Failed<br />Reason: %s', 'gvpplus'), $status_description)
            ]);
        }

        $failedUrl=give_get_success_page_uri();
        wp_redirect($failedUrl);
        exit();

    }
    public function gvpplus_api_donation_callback_response(){
        $params = $_REQUEST;
         file_put_contents(dirname(__FILE__) . '/payplus_callback_response.txt', json_encode($params));

        $status_code = $response['transaction']['status_code'];
        $donation_id = $response['transaction']['more_info'];
        $page_request_uid = $response['transaction']['payment_page_request_uid'];
        $transaction_uid = $response['transaction']['uid'];

        $donation = Donation::find($donation_id);
        if($status_code=='000' && $transaction_uid ==$donation->gatewayTransactionId && $donation_id ==$donation->id){

            $donation->status = DonationStatus::COMPLETE();
            $donation->save();
            DonationNote::create([
            'donationId' => $donation->id,
            'content' => _e('Payplus payment completed.','gvpplus')
            ]);

            update_post_meta($donation->id,'gvpplus_payment_page_request_uid',$page_request_uid);
        }else{
            if($donation_id ==$donation->id && $transaction_uid ==$donation->gatewayTransactionId){
                $donation->status = DonationStatus::FAILED();
                $donation->save();
                DonationNote::create([
                'donationId' => $donation->id,
                'content' => _e('Payplus payment is failed.','gvpplus')
                ]);

                update_post_meta($donation->id,'gvpplus_payment_page_request_uid',$page_request_uid);
            }
            
        }

        $parameter=array('status'=>'success','code'=>200,'description'=>'done');
        $response = new WP_REST_Response($parameter);
        $response->set_status(200);
        return $response;
    }
}