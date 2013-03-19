<?php

namespace Ibrows\PaymentSaferpayBundle\Plugin;
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

        $saferpay->setData(new SaferpayData());

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
        $instruction = $transaction->getPayment()->getPaymentInstruction();
        $data = $instruction->getExtendedData();

        /* @var $data ExtendedData  */
        $data->set('AMOUNT', $transaction->getRequestedAmount() * 100);

        $sfpaydata = $this->saferpay->getData();

        $sfpaydata->setPaymentData($data,'init');

        $querydata = $data->get('querydata');

        if (isset($querydata['DATA']) && isset($querydata['SIGNATURE'])) {
            $confirm = $this->saferpay->confirmPayment($querydata['DATA'], $querydata['SIGNATURE']);
            $data = $transaction->getExtendedData();
            $sfpaydata->writeInPaymentData($data);
            if ($confirm != '') {
                $transaction->setProcessedAmount($transaction->getRequestedAmount());
                $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
                $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
                return true;
            }
            $ex = new FinancialException('Payment cant confirmed');
            $ex->setFinancialTransaction($transaction);
            $transaction->setResponseCode('Failed');
            $transaction->setReasonCode('Failed');

        }

        $url = $this->saferpay->initPayment($sfpaydata->getInitData());

        $sfpaydata = $this->saferpay->getData();

        $data = $transaction->getExtendedData();
        $sfpaydata->writeInPaymentData($data);

        // $this->session->set('payment.saferpay.data', $this->saferpay->getData());

        if ($url != '') {

            $transaction->setReferenceNumber($sfpaydata->getInitData()->get('ACCOUNTID'));



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
        $data->remove('initData');

        $sfpaydata = $this->saferpay->getData();
        $sfpaydata->setPaymentData($data);

        if ($this->saferpay->completePayment() != '') {
            //         $transaction->setReferenceNumber($authorizationId);
            $transaction->setProcessedAmount($transaction->getRequestedAmount());
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
            return true;
        }

        $ex = new FinancialException('PaymentStatus is not completed: ');
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
