<?php

namespace Hoancaretllc\Paygent;

require __DIR__.'/vendor/autoload.php';

use Hoancaretllc\Paygent\Exceptions\InvalidArgumentException;
use PaygentModule\System\PaygentB2BModule;

date_default_timezone_set('Asia/Tokyo');

class Paygent
{
    protected $paygent;

    /*
     *  Initialization
     * @param string $env environment [local、production]
     * @param string $merchant_id merchant_id
     * @param string $connect_id
     * @param string $connect_password
     * @param string $pem
     * @param string $crt
     * @param string $telegram_version
     */
    public function __construct($env, $merchant_id, $connect_id, $connect_password, $pem, $crt, $telegram_version = '1.0')
    {
        if (!in_array(strtolower($env), ['local', 'production'])) {
            throw new InvalidArgumentException('Invalid response env: '.$env);
        }

        // env => [local、production], pem, crt
        $this->paygent = new PaygentB2BModule($env, $pem, $crt);
        $this->paygent->init();

        // merchant_id
        $this->paygent->reqPut('merchant_id', $merchant_id);
        // connect id
        $this->paygent->reqPut('connect_id', $connect_id);
        // connect_password
        $this->paygent->reqPut('connect_password', $connect_password);
        // telegram_version
        $this->paygent->reqPut('telegram_version', $telegram_version);
    }

    /*
     * Pay by credit card
     * @param array $params Payment data
     * @param int split_count number of instalments
     * @param string card_token token
     * @param string trading_id order number
     * @param string payment_amount amount
     * @return array
     */
    public function paySend($split_count, $card_token, $trading_id, $payment_amount)
    {
        $this->paygent->reqPut('3dsecure_ryaku', 1);

        $payment_class = '1' === $split_count ? 10 : 61;
        $this->paygent->reqPut('split_count', $split_count);
        $this->paygent->reqPut('payment_class', $payment_class);
        $this->paygent->reqPut('card_token', $card_token);
        $this->paygent->reqPut('trading_id', $trading_id);
        $this->paygent->reqPut('payment_amount', $payment_amount);

        // Payment Types
        $this->paygent->reqPut('telegram_kind', '020');
        // send
        $result = $this->paygent->post();
        // 1 request failed, 0 request succeeded
        if (true !== $result) {
            return ['code' => 1, 'result' => $result];
        } else {
            // After the request is successful, directly confirm the payment
            if ($this->paygent->hasResNext()) {
                $res = $this->paygent->resNext();
                $this->paygent->reqPut('payment_id', $res['payment_id']);
                // Credit card confirmation payment
                $this->paygent->reqPut('telegram_kind', '022');
            }
            // send
            $result = $this->paygent->post();

            if (true !== $result) {
                return ['code' => 1, 'result' => $result];
            }

            $response = [
                'code' => 0,
                'status' => $this->paygent->getResultStatus(),
                'pay_code' => $this->paygent->getResponseCode(), // 0 for success, 1 for failure, others are specific error codes
                'payment_id' => $res['payment_id'],
                'detail' => $this->iconv_parse($this->paygent->getResponseDetail()),
            ];

            return $response;
        }
    }

    /*
     * post-pay request
     * @param string $trading_id
     * @param string $payment_amount
     * @param string $shop_order_date YmdHis
     * @param string $customer_name_kanji 
     * @param string $customer_name_kana 
     * @param string $customer_email
     * @param string $customer_zip_code zip_code 2740065
     * @param string $customer_address
     * @param string $customer_tel  090-4500-9650
     * @param array $goods_list
     * @param array $goods_list[goods[0]] 
     * @param array $goods_list[goods_price[0]] 
     * @param array $goods_list[goods_amount[0]] 
     */
    public function afterPaySend($trading_id, $payment_amount, $shop_order_date, $customer_name_kanji, $customer_name_kana,
                                 $customer_email, $customer_zip_code, $customer_address, $customer_tel, $goods_list)
    {
        // Payment Type
        $this->paygent->reqPut('telegram_kind', '220');

        $this->paygent->reqPut('trading_id', $trading_id);
        $this->paygent->reqPut('payment_amount', $payment_amount);
        $this->paygent->reqPut('shop_order_date', $shop_order_date);
        $this->paygent->reqPut('customer_name_kanji', $this->iconv_parse2(preg_replace('/\\s+/', '', $this->makeSemiangle($customer_name_kanji))));
        $this->paygent->reqPut('customer_name_kana', $this->iconv_parse2(preg_replace('/\\s+/', '', $this->makeSemiangle($customer_name_kana))));
        $this->paygent->reqPut('customer_email', $this->makeSemiangle($customer_email));
        $this->paygent->reqPut('customer_zip_code', $this->makeSemiangle($customer_zip_code));
        $this->paygent->reqPut('customer_address', $this->iconv_parse2($this->makeSemiangle($customer_address)));
        $this->paygent->reqPut('customer_tel', $this->makeSemiangle($customer_tel));

        foreach ($goods_list as $key => $value) {
            $this->paygent->reqPut('goods['.$key.']', $this->iconv_parse2($this->makeSemiangle($value['goods'])));
            $this->paygent->reqPut('goods_price['.$key.']', $value['goods_price']);
            $this->paygent->reqPut('goods_amount['.$key.']', $value['goods_amount']);
        }

        // ask
        $result = $this->paygent->post();

        if (true !== $result) {
            return ['code' => 1, 'result' => $result];
        } else {
            // request succeeded
            if (!$this->paygent->hasResNext()) {
                return ['code' => 1, 'result' => $result];
            }
            $res = $this->paygent->resNext();

            $response = [
                'code' => 0,
                'status' => $this->paygent->getResultStatus(),
                'pay_code' => $this->paygent->getResponseCode(), // 0 for success, 1 for failure, others are specific error codes
                'payment_id' => $res['payment_id'],
                'detail' => $this->iconv_parse($this->paygent->getResponseDetail()),
            ];

            return $response;
        }
    }

    /*
     * Postpay Cancellation
     * @param string $trading_id
     * @param string $payment_id
     * @return array
     */
    public function afterPayCancel($trading_id = null, $payment_id = null)
    {
        // Payment Types
        $this->paygent->reqPut('telegram_kind', '221');
        // In the case of all transmissions, use the order number
        isset($trading_id) && null != $trading_id ? $this->paygent->reqPut('trading_id', $trading_id) : $this->paygent->reqPut('payment_id', $payment_id);
        $result = $this->paygent->post();

        if (true !== $result) {
            return ['code' => 1, 'result' => $result];
        } else {
            if (!$this->paygent->hasResNext()) {
                return ['code' => 1, 'result' => $result];
            }

            $response = [
                'code' => 0,
                'status' => $this->paygent->getResultStatus(),
                'pay_code' => $this->paygent->getResponseCode(),
                'detail' => $this->iconv_parse($this->paygent->getResponseDetail()),
            ];

            return $response;
        }
    }

    /*
     * Post payment confirmation
     * @param string $delivery_company_code
     * @param string $delivery_slip_no
     * @param string $trading_id 
     * @param string $payment_id
     * @return array
     */
    public function afterPayConfirm($delivery_company_code, $delivery_slip_no, $trading_id = null, $payment_id = null)
    {
        $this->paygent->reqPut('telegram_kind', 222);
        $this->paygent->reqPut('delivery_company_code', intval($delivery_company_code));
        $this->paygent->reqPut('delivery_slip_no', $delivery_slip_no);

        isset($trading_id) && null != $trading_id ? $this->paygent->reqPut('trading_id', $trading_id) : $this->paygent->reqPut('payment_id', $payment_id);

        $result = $this->paygent->post();

        if (true !== $result) {
            return ['code' => 1, 'result' => $result];
        } else {
            if (!$this->paygent->hasResNext()) {
                return ['code' => 1, 'result' => $result];
            }
            $response = [
                'code' => 0,
                'status' => $this->paygent->getResultStatus(),
                'pay_code' => $this->paygent->getResponseCode(),
                'detail' => $this->iconv_parse($this->paygent->getResponseDetail()),
            ];

            return $response;
        }
    }

    /*
     * full-width to half-width
     */
    public function makeSemiangle($str)
    {
        $arr = array('０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
            'Ａ' => 'A', 'Ｂ' => 'B', 'Ｃ' => 'C', 'Ｄ' => 'D', 'Ｅ' => 'E',
            'Ｆ' => 'F', 'Ｇ' => 'G', 'Ｈ' => 'H', 'Ｉ' => 'I', 'Ｊ' => 'J',
            'Ｋ' => 'K', 'Ｌ' => 'L', 'Ｍ' => 'M', 'Ｎ' => 'N', 'Ｏ' => 'O',
            'Ｐ' => 'P', 'Ｑ' => 'Q', 'Ｒ' => 'R', 'Ｓ' => 'S', 'Ｔ' => 'T',
            'Ｕ' => 'U', 'Ｖ' => 'V', 'Ｗ' => 'W', 'Ｘ' => 'X', 'Ｙ' => 'Y',
            'Ｚ' => 'Z', 'ａ' => 'a', 'ｂ' => 'b', 'ｃ' => 'c', 'ｄ' => 'd',
            'ｅ' => 'e', 'ｆ' => 'f', 'ｇ' => 'g', 'ｈ' => 'h', 'ｉ' => 'i',
            'ｊ' => 'j', 'ｋ' => 'k', 'ｌ' => 'l', 'ｍ' => 'm', 'ｎ' => 'n',
            'ｏ' => 'o', 'ｐ' => 'p', 'ｑ' => 'q', 'ｒ' => 'r', 'ｓ' => 's',
            'ｔ' => 't', 'ｕ' => 'u', 'ｖ' => 'v', 'ｗ' => 'w', 'ｘ' => 'x',
            'ｙ' => 'y', 'ｚ' => 'z',
            '（' => '(', '）' => ')', '〔' => '[', '〕' => ']', '【' => '[',
            '】' => ']', '〖' => '[', '〗' => ']', '“' => '[', '”' => ']',
            '‘' => '[', '’' => ']', '｛' => '{', '｝' => '}', '《' => '<',
            '》' => '>',
            '％' => '%', '＋' => '+', '—' => '-', '－' => '-', '～' => '-',
            '：' => ':', '。' => '.', '、' => ',', '，' => '.', '、' => '.',
            '；' => ',', '？' => '?', '！' => '!', '…' => '-', '‖' => '|',
            '”' => '"', '’' => '`', '‘' => '`', '｜' => '|', '〃' => '"',
            '　' => ' ', '『' => '', '』' => '', '･' => '', );

        return strtr($str, $arr);
    }

    /*
     * transcoding format conversion SHITF_JIS->UTF-8
     * $param string $str
     * return $str
     */
    public function iconv_parse($str)
    {
        return iconv('Shift_JIS', 'UTF-8', $str);
    }

    /*
    * transcoding format conversion UTF-8->SHITF_JIS
    * $param string $str
    * return $str
    */
    public function iconv_parse2($str)
    {
        return iconv('UTF-8', 'Shift_JIS', $str);
    }
}
