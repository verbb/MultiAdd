<?php
namespace Craft;

class MultiAddController extends Commerce_BaseFrontEndController
{

    protected $allowAnonymous = true;

    /**
     * @param $error
     */
    private function logError($error){
        MultiAddPlugin::log($error, LogLevel::Error);
    }


    /**
     * @throws HttpException
     */
    public function actionMultiAdd()
    {

        //Called via Ajax?
        $ajax = craft()->request->isAjaxRequest();

        //Get plugin settings
        $settings = craft()->plugins->getPlugin('multiAdd')->getSettings();
        //Settings to control behavour when testing - we don't want to debug via ajax or it stuffs up the JSON response...
        $debug = ($settings->debug and !$ajax);
      
        //Store items added to the cart in case of later failure & rollback required
        $rollback = array();

        //Require POST request & set up erro handling
        $this->requirePostRequest();
        $errors = array();

        //Get the cart & form data
        $cart = craft()->commerce_cart->getCart();
        $items = craft()->request->getPost('items');

        //some crude debugging support
        if ($debug){
            echo '<h3>Items</h3><pre>';
            print_r($items);
            echo '</pre>';
        }

        
        if (!isset($items)) {
            $errors[] = "No items to add.";
        } 
        else{
            $itemsToProcess = false;
            //prevent submission of all 0 qtys            
            foreach ($items as $key => $item) {
                $qty = isset($item['qty']) ? (int)$item['qty'] : 0;
                if ($qty >0){
                    $itemsToProcess = true;
                    break;
                }
            }
            if(!$itemsToProcess){
                $errors[] = "All items have 0 quantity.";
            }
        }


        // Do some cart-adding using our new, faster, rollback-able service
        if (!$errors) {
            $error = "";
            if (!craft()->multiAdd_cart->multiAddToCart($cart, $items, $error)) {
                $errors[] = $error;  
            }              
        }

        //trouble?
        if ($errors) {
            foreach ($errors as $error) {
                $this->logError($error);
            }
            craft()->urlManager->setRouteVariables(['error' => $errors]);
        }
        //everything went fine!
        else {
            craft()->userSession->setFlash('notice', 'Products have been multiadd-ed');
            //only redirect if we're not debugging and we haven't submitted by ajax
            if (!$debug and !$ajax){
                $this->redirectToPostedUrl();
            }
        }


        // Appropriate Ajax responses...
        if($ajax){
            if($errors){
                $this->returnErrorJson($errors);
            }
            else{
                $this->returnJson(['success'=>true,'cart'=>$this->cartArray($cart)]);
            }
        }

        //Not AJAX? We're done!
    }

    public function actionUpdateCart()
    {
        $this->requirePostRequest();
        $cart = craft()->commerce_cart->getCart();

        $errors = array();
        $items = craft()->request->getPost('items');

        foreach ($items as $lineItemId => $item) {
            $lineItem = craft()->commerce_lineItems->getLineItemById($lineItemId);
            $lineItem->qty = $item['qty'];

            // Fail silently if its not their line item or it doesn't exist.
            if (!$lineItem || !$lineItem->id || ($cart->id != $lineItem->orderId)) {
                return true;
            }

            if (!craft()->commerce_lineItems->updateLineItem($cart, $lineItem, $error)) {
                $errors[] = $error;
            }
        }

        if ($errors) {
            craft()->userSession->setError(Craft::t('Couldn’t update line item: {message}', [
                'message' => $error
            ]));
        } else {
            craft()->userSession->setNotice(Craft::t('Items updated.'));
            $this->redirectToPostedUrl();
        }
    }
}
