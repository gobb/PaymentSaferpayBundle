<?php

namespace Ibrows\PaymentSaferpayBundle\Plugin;
use Payment\Saferpay\SaferpayData as BaseSaferpayData;

use Payment\Saferpay\SaferpayKeyValue;

use Payment\Saferpay\SaferpayKeyValueInterface;

use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;

use JMS\Payment\CoreBundle\Entity\ExtendedData;

use Payment\Saferpay\SaferpayDataInterface;

class SaferpayData extends BaseSaferpayData
{


    const DATA_KEY_COMPLETE = 'complete';
    const DATA_KEY_INIT = 'init';
    const DATA_KEY_CONFIRM = 'confirm';

    private function setData($key, $value, $datakey = null)
    {
        if($key == self::DATA_KEY_INIT || $key == self::DATA_KEY_COMPLETE || $key == self::DATA_KEY_CONFIRM){
            foreach($value as $subkey => $subvalue){
                $this->setData($subkey, $subvalue,$key );
            }
        }

        if ($datakey == null){
            $setter = "set".ucfirst($key);
            if(!method_exists($this, $setter)){
                echo "method not found $setter";
                return false;
            }
            $this->$setter($value);

        }
        else{
            $getter = "get".ucfirst($datakey). "Data";
            $proto = $this->$getter();
            if(is_array($value) || $value === null){
                return false;
            }
            $proto->set($key,$value);
        }
    }


    public function setPaymentData(ExtendedDataInterface $data, $datakey = null)
    {

        foreach ($data->all() as $key => $value) {
            $this->setData($key, $value[0], $datakey);
        }
        return $this;
    }

    public function writeInPaymentData(ExtendedDataInterface $data)
    {
        $datas = array();
        foreach ($this->getInitData() as $key => $value) {
            $datas[$key] = $value;
        }
        $data->set(self::DATA_KEY_INIT, $datas);
        $datas = array();
        foreach ($this->getCompleteData() as $key => $value) {
            $datas[$key] = $value;
        }
        $data->set(self::DATA_KEY_CONFIRM, $datas);
        $datas = array();
        foreach ($this->getCompleteData() as $key => $value) {
            $datas[$key] = $value;
        }
        $data->set(self::DATA_KEY_COMPLETE, $datas);

        $data->set('InitSignature',  $this->getInitSignature());
        $data->set('CompleteSignature',  $this->getCompleteSignature());
        $data->set('ConfirmSignature',  $this->getConfirmSignature());
        return $data;
    }

}
