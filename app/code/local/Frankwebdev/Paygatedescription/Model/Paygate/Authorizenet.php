<?php

class Frankwebdev_Paygatedescription_Model_Paygate_Authorizenet extends Mage_Paygate_Model_Authorizenet
{

	private $_include_line_items = false;
	
    /**
     * Prepare request to gateway
     *
     * @link http://www.authorize.net/support/AIM_guide.pdf
     * @param Mage_Payment_Model_Info $payment
     * @return Mage_Paygate_Model_Authorizenet_Request
     */
    protected function _buildRequest(Varien_Object $payment)
    {
        $order = $payment->getOrder();

        $this->setStore($order->getStoreId());

        $request = $this->_getRequest()
            ->setXType($payment->getAnetTransType())
            ->setXMethod(self::REQUEST_METHOD_CC);

        if ($order && $order->getIncrementId()) {
            $request->setXInvoiceNum($order->getIncrementId());
        }

        if($payment->getAmount()){
            $request->setXAmount($payment->getAmount(),2);
            $request->setXCurrencyCode($order->getBaseCurrencyCode());
        }

        switch ($payment->getAnetTransType()) {
            case self::REQUEST_TYPE_AUTH_CAPTURE:
                $request->setXAllowPartialAuth($this->getConfigData('allow_partial_authorization') ? 'True' : 'False');
                if ($payment->getAdditionalInformation($this->_splitTenderIdKey)) {
                    $request->setXSplitTenderId($payment->getAdditionalInformation($this->_splitTenderIdKey));
                }
                break;
            case self::REQUEST_TYPE_AUTH_ONLY:
                $request->setXAllowPartialAuth($this->getConfigData('allow_partial_authorization') ? 'True' : 'False');
                if ($payment->getAdditionalInformation($this->_splitTenderIdKey)) {
                    $request->setXSplitTenderId($payment->getAdditionalInformation($this->_splitTenderIdKey));
                }
                break;
            case self::REQUEST_TYPE_CREDIT:
                /**
                 * need to send last 4 digit credit card number to authorize.net
                 * otherwise it will give an error
                 */
                $request->setXCardNum($payment->getCcLast4());
                $request->setXTransId($payment->getXTransId());
                $this->_include_line_items = false; // credit transactions don't need line item information
                break;
            case self::REQUEST_TYPE_VOID:
                $request->setXTransId($payment->getXTransId());
                $this->_include_line_items = false; // same for voids
                break;
            case self::REQUEST_TYPE_PRIOR_AUTH_CAPTURE:
                $request->setXTransId($payment->getXTransId());
                break;
            case self::REQUEST_TYPE_CAPTURE_ONLY:
                $request->setXAuthCode($payment->getCcAuthCode());
                break;
        }

        if ($this->getIsCentinelValidationEnabled()){
            $params  = $this->getCentinelValidator()->exportCmpiData(array());
            $request = Varien_Object_Mapper::accumulateByMap($params, $request, $this->_centinelFieldMap);
        }

        if (!empty($order)) {
            $billing = $order->getBillingAddress();
            if (!empty($billing)) {
                $request->setXFirstName($billing->getFirstname())
                    ->setXLastName($billing->getLastname())
                    ->setXCompany($billing->getCompany())
                    ->setXAddress($billing->getStreet(1))
                    ->setXCity($billing->getCity())
                    ->setXState($billing->getRegion())
                    ->setXZip($billing->getPostcode())
                    ->setXCountry($billing->getCountry())
                    ->setXPhone($billing->getTelephone())
                    ->setXFax($billing->getFax())
                    ->setXCustId($billing->getCustomerId())
                    ->setXCustomerIp($order->getRemoteIp())
                    ->setXCustomerTaxId($billing->getTaxId())
                    ->setXEmail($order->getCustomerEmail())
                    ->setXEmailCustomer($this->getConfigData('email_customer'))
                    ->setXMerchantEmail($this->getConfigData('merchant_email'));
            }

            $shipping = $order->getShippingAddress();
            if (!empty($shipping)) {
                $request->setXShipToFirstName($shipping->getFirstname())
                    ->setXShipToLastName($shipping->getLastname())
                    ->setXShipToCompany($shipping->getCompany())
                    ->setXShipToAddress($shipping->getStreet(1))
                    ->setXShipToCity($shipping->getCity())
                    ->setXShipToState($shipping->getRegion())
                    ->setXShipToZip($shipping->getPostcode())
                    ->setXShipToCountry($shipping->getCountry());
            }
            
            // don't show configurable products, because the simple products are what's actually being sold here.
	        $order_items = $order->getAllVisibleItems();
	        $item_increment = 1;
	        
	        if (count($order_items) > 0 && $this->_include_line_items === true){
		        $order_item_list = array();
		        $IDL = '<|>'; // auth.net item delimiter
		        
		        foreach ($order_items as $item){
		        
		        	$item_options = '';
		        	
		        	
					foreach($item->getProduct()->getTypeInstance(true)->getSelectedAttributesInfo($item->getProduct()) as $attribute){
						
						$item_options .= $attribute['label'] . ' - ' . $attribute['value'] . '; ';
					
					}
		        	
		        	$order_item_list[] = 	  $item->getSku()
			        						. $IDL
			        						. substr($item->getName(), 0, 30)
			        						. $IDL
			        						. substr(chop($item_options, '; '), 0, 255)
			        						. $IDL
			        						. $item->getQtyOrdered()
			        						. $IDL
			        						. $item->getOriginalPrice() // unit price
			        						. $IDL
			        						. ( $item->getTaxAmount() > 0 ? 'Y' : 'N') // taxes?
			        						;
			        						
					$item_increment += 1;


		        }
		        
		        if (count($order_item_list) == 1){
		        	$order_item_list = implode("", $order_item_list); // convert to string for 1 item
		        }
	
				$request->setXLineItem($order_item_list);
				
	        }
	        
			$store_information = $order->getStoreName(1); // 1 - store group name, 0 - website, 2 store view name
	        					
            $request
            	->setXPoNum($payment->getPoNumber())
                ->setXTax($order->getBaseTaxAmount())
                ->setXFreight($order->getBaseShippingAmount())
                ->setXDescription($store_information);

            ;
        }

        if($payment->getCcNumber()){
            $request->setXCardNum($payment->getCcNumber())
                ->setXExpDate(sprintf('%02d-%04d', $payment->getCcExpMonth(), $payment->getCcExpYear()))
                ->setXCardCode($payment->getCcCid());
        }

        return $request;
    }
    
    /**
     * Post request to gateway and return responce
     *
     * @param Mage_Paygate_Model_Authorizenet_Request $request)
     * @return Mage_Paygate_Model_Authorizenet_Result
     */
    protected function _postRequest(Varien_Object $request)
    {
        $debugData = array('request' => $request->getData());

        $result = Mage::getModel('paygate/authorizenet_result');

        $client = new Varien_Http_Client();

        $uri = $this->getConfigData('cgi_url');
        $client->setUri($uri ? $uri : self::CGI_URL);
        $client->setConfig(array(
            'maxredirects'=>0,
            'timeout'=>30,
            //'ssltransport' => 'tcp',
        ));
        foreach ($request->getData() as $key => $value) {
            $request->setData($key, str_replace(self::RESPONSE_DELIM_CHAR, '', $value));
        }
        $request->setXDelimChar(self::RESPONSE_DELIM_CHAR);
        
        $client->setParameterPost($request->getData());
        
        $raw_post_data = '';
        
        foreach ($request->getData() as $request_key=>$request_data){
        
        	// ignore multiple dimensions, the only one that's multidimensinoal is x_line_item and we're handling that separately below
        	if (is_array($request_data)){
        		continue;
        	}
        	
        	$raw_post_data .= urlencode($request_key) . '=' . urlencode($request_data) . '&';
        }
        
        $raw_post_data = chop($raw_post_data, '&');

        if ($request->getXLineItem()){
        
        	$line_items = array();
        
        	foreach($request->getXLineItem() as $line_item){
        	
        		$line_items[] = urlencode($line_item);
        	
        	}
        	
    		$raw_post_data .= '&x_line_item=' . implode('&x_line_item=', $line_items);

        }
        
        $client->setRawData($raw_post_data);
        
        $client->setMethod(Zend_Http_Client::POST);

        try {
            $response = $client->request();
        } catch (Exception $e) {
            $result->setResponseCode(-1)
                ->setResponseReasonCode($e->getCode())
                ->setResponseReasonText($e->getMessage());

            $debugData['result'] = $result->getData();
            $this->_debug($debugData);
            Mage::throwException($this->_wrapGatewayError($e->getMessage()));
        }

        $responseBody = $response->getBody();

        $r = explode(self::RESPONSE_DELIM_CHAR, $responseBody);

        if ($r) {
            $result->setResponseCode((int)str_replace('"','',$r[0]))
                ->setResponseSubcode((int)str_replace('"','',$r[1]))
                ->setResponseReasonCode((int)str_replace('"','',$r[2]))
                ->setResponseReasonText($r[3])
                ->setApprovalCode($r[4])
                ->setAvsResultCode($r[5])
                ->setTransactionId($r[6])
                ->setInvoiceNumber($r[7])
                ->setDescription($r[8])
                ->setAmount($r[9])
                ->setMethod($r[10])
                ->setTransactionType($r[11])
                ->setCustomerId($r[12])
                ->setMd5Hash($r[37])
                ->setCardCodeResponseCode($r[38])
                ->setCAVVResponseCode( (isset($r[39])) ? $r[39] : null)
                ->setSplitTenderId($r[52])
                ->setAccNumber($r[50])
                ->setCardType($r[51])
                ->setRequestedAmount($r[53])
                ->setBalanceOnCard($r[54])
                ;

               // Mage::log('DEBUG PAYGATE');
               // Mage::log($result);

        }
        else {
             Mage::throwException(
                Mage::helper('paygate')->__('Error in payment gateway.')
            );
        }

        $debugData['result'] = $result->getData();
        $this->_debug($debugData);

        return $result;
    }

}