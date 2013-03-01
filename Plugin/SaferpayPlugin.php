<?php

namespace Ibrows\PaymentSaferpayBundle\Plugin;
use Payment\Saferpay\SaferpayData;

use JMS\Payment\CoreBundle\Model\PaymentInterface;

use JMS\Payment\CoreBundle\Model\PaymentInstructionInterface;

use Payment\HttpClient\GuzzleClient;

use JMS\Payment\CoreBundle\Entity\ExtendedData;

use Payment\Saferpay\Saferpay;

use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Util\Number;
use JMS\Payment\PaypalBundle\Client\Client;
use JMS\Payment\PaypalBundle\Client\Response;

/**
 * @author marcsteiner
 *
 */
class SaferpayPlugin extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $returnUrl;

    /**
     * @var string
     */
    protected $cancelUrl;

    /**
     * @var \JMS\Payment\PaypalBundle\Client\Client
     */
    protected $client;

    /**
     * @var \Payment\Saferpay\Saferpay
     */
    protected $saferpay;

    protected $session;


    /**
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param \JMS\Payment\PaypalBundle\Client\Client $client
     */
    public function __construct(Saferpay $saferpay, $logger, $session)
    {
        $this->saferpay = $saferpay;
        $this->saferpay->setLogger($logger);
        $this->saferpay->setHttpClient(new GuzzleClient());
        $this->session = $session;
        $saferpay->setData($session->get('payment.saferpay.data'));
    }

    /**
     * @param PaymentInstructionInterface $instruction
     * @throws FunctionNotSupportedException
     */
    public function checkPaymentInstruction(PaymentInstructionInterface $instruction)
    {
        return true;

    }

    public function approve(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        /* @var $data ExtendedData  */

        if($data->has('querydata') && $data->has('signature')){
            $transaction->setProcessedAmount($transaction->getRequestedAmount());
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
            return true;
        }

        $data->set('AMOUNT', $transaction->getRequestedAmount() * 100);

        $sfpaydata = $this->saferpay->getKeyValuePrototype();
        foreach ($data->all() as $key => $val) {
            $sfpaydata->set($key, $val[0]);
        }

        $url = $this->saferpay->initPayment($sfpaydata);

        $data = $transaction->getExtendedData();
        foreach ($this->saferpay->getData() as $key => $val) {
            $data->set($key, $val);
        }

        $transaction->setExtendedData($data);
        $this->session->set('payment.saferpay.data', $this->saferpay->getData());

        if ($url != '') {
            /*
             *
            $transaction->setReferenceNumber());
            $transaction->setProcessedAmount());

             */

            // redirect to saferpay
            $actionRequest = new ActionRequiredException('User must authorize the transaction.');
            $actionRequest->setFinancialTransaction($transaction);
            $actionRequest->setAction(new VisitUrl($url));
            throw $actionRequest;

        } else {
            $ex = new FinancialException('PaymentAction failed.');
            $transaction->setResponseCode('Failed');
            $transaction->setReasonCode('PaymentActionFailed');
            $ex->setFinancialTransaction($transaction);

            throw $ex;
        }

    }

    public function deposit(FinancialTransactionInterface $transaction, $retry)
    {

        $data = $transaction->getExtendedData();

        $querydata = $data->get('querydata');
        $signature = $data->get('signature');

        $sfpaydata = $this->saferpay->getKeyValuePrototype();
        foreach ($data->all() as $key => $val) {
            $sfpaydata->set($key, $val[0]);
        }
        $sfpaydata->set('AMOUNT', $transaction->getRequestedAmount() * 100);

        if ($this->saferpay->confirmPayment($querydata, $signature)) {
            if ($this->saferpay->completePayment() != '') {
                //         $transaction->setReferenceNumber($authorizationId);
                $transaction->setProcessedAmount($transaction->getRequestedAmount());
                $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
                $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
                return true;
            }
        }
        $ex = new FinancialException('PaymentStatus is not completed: ' . $response->body->get('PAYMENTSTATUS'));
        $ex->setFinancialTransaction($transaction);
        $transaction->setResponseCode('Failed');
        $transaction->setReasonCode('Failed');

        throw $ex;

    }

    public function processes($paymentSystemName)
    {
        return 'saferpay' === $paymentSystemName;
    }

    public function isIndependentCreditSupported()
    {
        return false;
    }

}
