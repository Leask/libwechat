<?php

define ('DEBUG', true);


class httpKit {

    public static function request(
        $url,
        $argsGet     = null,
        $argsPost    = null,
        $headerOnly  = false,
        $binaryMode  = false,
        $timeout     = 300,
        $maxRedirs   = 3,
        $postType    = 'txt',
        $jsonDecode  = false,
        $decoAsArray = true,
        $proxy       = [],
        $cstRequest  = ''
    ) {
        if ($url) {
            if ($argsGet) {
                $url .= (strpos($url, '?') ? '&' : '?')
                      . http_build_query($argsGet);
            }
            $objCurl = curl_init();
            curl_setopt($objCurl, CURLOPT_URL,            $url);
            curl_setopt($objCurl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($objCurl, CURLOPT_HEADER,         $headerOnly);
            curl_setopt($objCurl, CURLOPT_BINARYTRANSFER, $binaryMode);
            curl_setopt($objCurl, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($objCurl, CURLOPT_MAXREDIRS,      $maxRedirs);
            curl_setopt($objCurl, CURLOPT_FOLLOWLOCATION, 1);
            if ($proxy && $proxy['type'] && $proxy['addr'] && $proxy['port']) {
                curl_setopt($objCurl, CURLOPT_PROXY,     $proxy['addr']);
                curl_setopt($objCurl, CURLOPT_PROXYPORT, $proxy['port']);
                if ($proxy['type'] === 'socks') {
                    curl_setopt($objCurl, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                }
            }
            if ($argsPost !== null) {
                switch ($postType) {
                    case 'json':
                        $argsPost = json_encode($argsPost);
                        break;
                    case 'form':
                        $argsPost = http_build_query($argsPost);
                }
                if (!$cstRequest) {
                    curl_setopt($objCurl, CURLOPT_POST, 1);
                }
                curl_setopt($objCurl, CURLOPT_POSTFIELDS, $argsPost);
            }
            if ($cstRequest) {
                curl_setopt($objCurl, CURLOPT_CUSTOMREQUEST, $cstRequest);
            }
            if (DEBUG) {
                error_log('httpKit fetching {');
                error_log("URL: {$url}");
                if ($proxy) {
                    error_log('PROXY: ' . json_encode($proxy));
                }
                if ($argsPost !== null) {
                    error_log("POST: {$argsPost}");
                }
            }
            $rawData     = @curl_exec($objCurl);
            $intHttpCode = @curl_getinfo($objCurl, CURLINFO_HTTP_CODE);
            $result = [
                'data'      => $rawData,
                'http_code' => $intHttpCode,
                'infos'     => @curl_getinfo($objCurl),
            ];
            curl_close($objCurl);
            if ($jsonDecode) {
                $result['json'] = @json_decode($rawData, $decoAsArray);
            }
            if (DEBUG) {
                $strLog = 'RETURN: ';
                if ($binaryMode) {
                    $strLog .= $rawData ? '"[binary data]"' : '"[null]"';
                } else {
                    $strLog .= $rawData;
                }
                error_log("HTTP-CODE: {$intHttpCode}");
                error_log("{$strLog}");
                error_log('httpKit fetching }');
            }
            return $result;
        }
        return null;
    }


    public static function fetchImageExpress($url) {
        $rawResult = self::request($url, null, null, false, true);
        if ($rawResult
         && $rawResult['data']
         && $rawResult['http_code'] === 200) {
            $objImage = @imagecreatefromstring($rawResult['data']);
            if ($objImage) {
                return $objImage;
            }
        }
        return null;
    }

}


class WeChat {

    public $sid  = '';

    public $uin  = '';

    public $uuid = '';

    public $data = '';

    public function encode($data){
        if(!is_array($data)){
            return $this->encode_str($data);
        }
        $ds = array();
        foreach($data as $k => $v){
            $ds [] = "\"$k\":" . $this->encode($v);
        }
        return '{' . join(',', $ds) . '}';
    }

    public function encode_str($str){
        if(preg_match('|^\d+$|', $str)){
            return $str;
        }
        return '"' . str_replace('"', '\"', iconv('GBK', 'UTF-8', $str)) . '"';
    }


    public function getLoginQrc() {
        echo "Init...\n";
        $rqResult = httpKit::request(
            'https://login.weixin.qq.com/jslogin?appid=wx782c26e4c19acffb&redirect_uri=https%3A%2F%2Fwx.qq.com%2Fcgi-bin%2Fmmwebwx-bin%2Fwebwxnewloginpage&fun=new&lang=zh_CN'
        );
        if ($rqResult && $rqResult['http_code'] === 200) {
            $this->uuid = preg_replace('/.*"(.*)".*/', '$1', $rqResult['data']);
            if ($this->uuid) {
                echo "Fetching QR Code...\n";
                $urlQrc = "https://login.weixin.qq.com/qrcode/{$this->uuid}?t=webwx\n";
                $imgQrc = httpkit::fetchImageExpress($urlQrc);
                if ($imgQrc) {
                    imagepng($imgQrc, 'wc_qrc.png');
                    imagedestroy($imgQrc);
                    shell_exec('open wc_qrc.png');
                } else {
                    return null;
                }
                echo "Waiting for confirm...";
                while (!$this->sid || !$this->uin) {
                    $rqResult = httpKit::request(
                        "https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?uuid={$this->uuid}&tip=1"
                    );
                    if ($rqResult && $rqResult['http_code'] === 200) {
                        $rqResult['data'] = implode('', explode('
', $rqResult['data']));
                        if (preg_match('/.*redirect_uri.*/', $rqResult['data'])
                        && ($url = preg_replace('/.*redirect_uri="(.*)".*/', '$1', $rqResult['data']))) {
                            $rqResult = httpKit::request($url, null, null, true);
                            $rqResult = explode('
', $rqResult['data']);
                            foreach ($rqResult as $rqItem) {
                                if (preg_match('/Set-Cookie: wxsid=.*/', $rqItem)) {
                                    $this->sid = preg_replace('/Set-Cookie: wxsid=([^;]*);.*/', '$1', $rqItem);
                                }
                                if (preg_match('/Set-Cookie: wxuin=.*/', $rqItem)) {
                                    $this->uin = preg_replace('/Set-Cookie: wxuin=([^;]*);.*/', '$1', $rqItem);
                                }
                            }
                        } else {
                            echo '.';
                        }
                    } else {
                        echo "\nError!\n";
                    }
                }
            }
        }
        return null;
    }



// $this->post_contents('https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsync?sid=' . urlencode($this->sid), '{"BaseRequest":{"Uin":'.$this->Uin.',"Sid":"'.$this->sid.'"},"SyncKey":{"Count":0,"List":[]}}');


// $data = array(
//     "BaseRequest" => array(
//         "Uin"=> $this->Uin,
//         "Sid"=> $this->sid,
//         "Skey"=> $this->Skey,
//         "DeviceID"=> $this->DeviceID,
//     ),
//     "Msg" => array(
//         "FromUserName" => $this->FromUserName,
//         "ToUserName" => $name,
//         "Type" => 1,
//         "Content" => $content,
//         "ClientMsgId" => $this->ClientMsgId,
//         "LocalID" => $this->ClientMsgId,
//     ),
// );
// $str = $this->encode($data);
// son_decode($this->post_contents('https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsendmsg?sid=' . urlencode($this->sid) . '&r=' .$this->ClientMsgId, $str), true);


// $data = '{"BaseRequest":{"Uin":'.$this->Uin.',"Sid":"'.$this->sid.'"},"SyncKey":{"Count":0,"List":[]}}';
//         $ret = json_decode($this->post_contents('https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsync?sid=' . urlencode($this->sid), $data, 30), true);





}


$objWeChat = new WeChat;

$objWeChat->getLoginQrc();

echo "\n";

$objWeChat->data = [
    'BaseRequest' => [
        'DeviceID' => $objWeChat->uuid,
        'Sid'      => $objWeChat->sid,
        'Skey'     => '',
        'Uin'      => $objWeChat->uin,
    ]
];

$pfResult = httpKit::request('https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxinit', null, $objWeChat->encode($objWeChat->data));
$pfResult = json_decode($pfResult['data'], true);

$me   = $pfResult['User'];
echo "My profile:\n";
print_r($me);
echo "\n";
echo "\n";
$skey = $pfResult['SKey'];

$objWeChat->data = [
    'BaseRequest' => [
        'DeviceID' => $objWeChat->uuid,
        'Sid'      => $objWeChat->sid,
        'Skey'     => $skey,
        'Uin'      => $objWeChat->uin,
    ],
    'Msg' => [
        'FromUserName' => $me['UserName'],
        'ToUserName'   => 'leaskh',
        'Type'         => 1,
        'Content'      => 'testing we chat bot by @Leaskh version alpha 1',
        'ClientMsgId'  => 1,
        'LocalID'      => 1,
    ],
];

$strData = $objWeChat->encode($objWeChat->data);
echo "Returns:\n";
$pfResult = httpKit::request('https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsendmsg?sid=' . urlencode($objWeChat->sid) . '&r=' . 1, null, $strData);

print_r($pfResult);
