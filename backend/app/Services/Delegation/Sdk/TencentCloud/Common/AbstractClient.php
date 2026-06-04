<?php

/*
 * Copyright (c) 2017 Tencent. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */

namespace App\Services\Delegation\Sdk\TencentCloud\Common;

use App\Services\Delegation\Sdk\TencentCloud\Common\Exception\TencentCloudSDKException;
use App\Services\Delegation\Sdk\TencentCloud\Common\Http\HttpConnection;
use App\Services\Delegation\Sdk\TencentCloud\Common\Profile\ClientProfile;
use App\Services\Delegation\Sdk\TencentCloud\Common\Profile\HttpProfile;
use ReflectionClass;

/**
 * 抽象api类，禁止client引用
 */
abstract class AbstractClient
{
    /**
     * @var string SDK版本
     */
    public static $SDK_VERSION = 'SDK_PHP_3.0.1479';

    /**
     * @var int http响应码200
     */
    public static $HTTP_RSP_OK = 200;

    private $PHP_VERSION_MINIMUM = '5.6.0';

    /**
     * @var Credential 认证类实例，保存认证相关字段
     */
    private $credential;

    /**
     * @var ClientProfile 会话配置信息类
     */
    private $profile;

    /**
     * @var string 产品地域
     */
    private $region;

    /**
     * @var string 请求路径
     */
    private $path;

    /**
     * @var string sdk版本号
     */
    private $sdkVersion;

    /**
     * @var string api版本号
     */
    private $apiVersion;

    /**
     * @var HttpConnection
     */
    private $httpConn;

    /**
     * @var string|null 服务端点
     */
    private $endpoint;

    /**
     * @var string 服务名称
     */
    protected $service = '';

    /**
     * 基础client类
     *
     * @param  string  $endpoint  Deprecated, we use service+rootdomain instead
     * @param  string  $version  api版本
     * @param  Credential  $credential  认证信息实例
     * @param  string  $region  产品地域
     * @param  ClientProfile  $profile
     */
    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct($endpoint, $version, $credential, $region, $profile = null)
    {
        $this->path = '/';
        $this->credential = $credential;
        $this->region = $region;
        if ($profile) {
            $this->profile = $profile;
        } else {
            $this->profile = new ClientProfile;
        }

        $this->getRefreshedEndpoint();

        $this->sdkVersion = AbstractClient::$SDK_VERSION;
        $this->apiVersion = $version;

        if (version_compare(phpversion(), $this->PHP_VERSION_MINIMUM, '<') && $profile->getCheckPHPVersion()) {
            throw new TencentCloudSDKException('ClientError', 'PHP version must >= '.$this->PHP_VERSION_MINIMUM.', your current is '.phpversion());
        }

        $this->httpConn = $this->createConnect();
    }

    /**
     * 将API响应转换为模型对象
     */
    abstract protected function returnResponse($action, $response);

    /**
     * 设置产品地域
     *
     * @param  string  $region  地域
     */
    public function setRegion($region)
    {
        $this->region = $region;
    }

    /**
     * 获取产品地域
     *
     * @return string
     */
    public function getRegion()
    {
        return $this->region;
    }

    /**
     * 设置认证信息实例
     *
     * @param  Credential  $credential  认证信息实例
     */
    public function setCredential($credential)
    {
        $this->credential = $credential;
    }

    /**
     * 返回认证信息实例
     *
     * @return Credential
     */
    public function getCredential()
    {
        return $this->credential;
    }

    /**
     * 设置配置实例
     *
     * @param  ClientProfile  $profile  配置实例
     */
    public function setClientProfile($profile)
    {
        $this->profile = $profile;
    }

    /**
     * 返回配置实例
     *
     * @return ClientProfile
     */
    public function getClientProfile()
    {
        return $this->profile;
    }

    /**
     * @param  string  $action  方法名
     * @param  array  $request  参数列表
     * @return mixed
     *
     * @throws TencentCloudSDKException
     */
    public function __call($action, $request)
    {
        return $this->doRequestWithOptions($action, $request[0], []);
    }

    protected function doRequestWithOptions($action, $request, $options)
    {
        try {
            $serializeRequest = $request->serialize();
            $method = $this->getPrivateMethod($request, 'arrayMerge');
            $serializeRequest = $method->invoke($request, $serializeRequest);
            $responseData = $this->getResponseData($action, $request, $options);

            if ($responseData->getStatusCode() !== AbstractClient::$HTTP_RSP_OK) {
                throw new TencentCloudSDKException($responseData->getReasonPhrase(), $responseData->getBody());
            }

            return $this->handleJsonResponse($action, $responseData);
        } catch (\Exception $e) {
            if (! ($e instanceof TencentCloudSDKException)) {
                throw new TencentCloudSDKException('', $e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    private function getResponseData($action, $request, $options)
    {
        switch ($this->profile->getSignMethod()) {
            case ClientProfile::$SIGN_HMAC_SHA1:
            case ClientProfile::$SIGN_HMAC_SHA256:
                return $this->doRequest($action, $request->serialize());
            case ClientProfile::$SIGN_TC3_SHA256:
                return $this->doRequestWithTC3($action, $request, $options, [], '');
            default:
                throw new TencentCloudSDKException('ClientError', 'Invalid sign method');
        }
    }

    private function handleJsonResponse($action, $responseData)
    {
        $tmpResp = json_decode($responseData->getBody(), true)['Response'];
        if (array_key_exists('Error', $tmpResp)) {
            throw new TencentCloudSDKException($tmpResp['Error']['Code'], $tmpResp['Error']['Message'], $tmpResp['RequestId']);
        }

        return $this->returnResponse($action, $tmpResp);
    }

    private function doRequest($action, $request)
    {
        switch ($this->profile->getHttpProfile()->getReqMethod()) {
            case HttpProfile::$REQ_GET:
                return $this->getRequest($action, $request);
            case HttpProfile::$REQ_POST:
                return $this->postRequest($action, $request);
            default:
                throw new TencentCloudSDKException('', 'Method only support (GET, POST)');
        }
    }

    private function doRequestWithTC3($action, $request, $options, $headers, $payload)
    {
        $endpoint = $this->getRefreshedEndpoint();
        $headers['Host'] = $endpoint;
        $headers['X-TC-Action'] = ucfirst($action);
        $headers['X-TC-RequestClient'] = $this->sdkVersion;
        $headers['X-TC-Timestamp'] = time();
        $headers['X-TC-Version'] = $this->apiVersion;

        if ($this->region) {
            $headers['X-TC-Region'] = $this->region;
        }

        if ($this->credential->getToken()) {
            $headers['X-TC-Token'] = $this->credential->getToken();
        }

        $language = $this->profile->getLanguage();
        if ($language) {
            $headers['X-TC-Language'] = $language;
        }

        $canonicalUri = $this->path;
        $canonicalQueryString = '';

        $reqmethod = $this->profile->getHttpProfile()->getReqMethod();
        if (HttpProfile::$REQ_GET == $reqmethod) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $rs = $request->serialize();
            $am = $this->getPrivateMethod($request, 'arrayMerge');
            $rsam = $am->invoke($request, $rs);
            $canonicalQueryString = http_build_query($rsam);
            $payload = '';
        }
        if (HttpProfile::$REQ_POST == $reqmethod) {
            if (isset($options['IsMultipart']) && $options['IsMultipart'] === true) {
                $boundary = uniqid();
                $headers['Content-Type'] = 'multipart/form-data; boundary='.$boundary;
                $canonicalQueryString = '';
                $payload = $this->getMultipartPayload($request, $boundary, $options);
            } elseif (isset($options['IsOctetStream']) && $options['IsOctetStream'] === true) {
                $headers['Content-Type'] = 'application/octet-stream';
                $canonicalQueryString = '';
            } elseif (isset($options['IsCommonJson']) && $options['IsCommonJson'] === true) {
                $headers['Content-Type'] = 'application/json';
                $canonicalQueryString = '';
                $payload = json_encode($request);
            } else {
                $headers['Content-Type'] = 'application/json';
                $canonicalQueryString = '';
                $payload = $request->toJsonString();
            }
        }

        if ($this->profile->getUnsignedPayload() == true) {
            $headers['X-TC-Content-SHA256'] = 'UNSIGNED-PAYLOAD';
            $payloadHash = hash('SHA256', 'UNSIGNED-PAYLOAD');
        } else {
            $payloadHash = hash('SHA256', $payload);
        }

        $canonicalHeaders = 'content-type:'.$headers['Content-Type']."\n".
                            'host:'.$headers['Host']."\n";
        $signedHeaders = 'content-type;host';
        $canonicalRequest = $reqmethod."\n".
                            $canonicalUri."\n".
                            $canonicalQueryString."\n".
                            $canonicalHeaders."\n".
                            $signedHeaders."\n".
                            $payloadHash;
        $algo = 'TC3-HMAC-SHA256';
        $date = gmdate('Y-m-d', $headers['X-TC-Timestamp']);
        $service = explode('.', $endpoint)[0];
        $credentialScope = $date.'/'.$service.'/tc3_request';
        $hashedCanonicalRequest = hash('SHA256', $canonicalRequest);
        $str2sign = $algo."\n".
                    $headers['X-TC-Timestamp']."\n".
                    $credentialScope."\n".
                    $hashedCanonicalRequest;
        $skey = $this->credential->getSecretKey();
        $signature = Sign::signTC3($skey, $date, $service, $str2sign);

        $sid = $this->credential->getSecretId();
        $auth = $algo.
                ' Credential='.$sid.'/'.$credentialScope.
                ', SignedHeaders=content-type;host, Signature='.$signature;
        $headers['Authorization'] = $auth;

        if (HttpProfile::$REQ_GET == $reqmethod) {
            $connect = $this->getConnect();

            return $connect->getRequest($this->path, $canonicalQueryString, $headers);
        } else {
            $connect = $this->getConnect();

            return $connect->postRequestRaw($this->path, $headers, $payload);
        }
    }

    private function getMultipartPayload($request, $boundary, $options)
    {
        $body = '';
        $params = $request->serialize();
        foreach ($params as $key => $value) {
            $body .= '--'.$boundary."\r\n";
            $body .= 'Content-Disposition: form-data; name="'.$key;
            if (in_array($key, $options['BinaryParams'])) {
                $body .= '"; filename="'.$key;
            }
            $body .= "\"\r\n";
            if (is_array($value)) {
                $value = json_encode($value);
                $body .= "Content-Type: application/json\r\n";
            }
            $body .= "\r\n".$value."\r\n";
        }
        if ($body != '') {
            $body .= '--'.$boundary."--\r\n";
        }

        return $body;
    }

    /**
     * @throws TencentCloudSDKException
     */
    private function getRequest($action, $request)
    {
        $query = $this->formatRequestData($action, $request, HttpProfile::$REQ_GET);
        $connect = $this->getConnect();

        return $connect->getRequest($this->path, $query, []);
    }

    /**
     * @throws TencentCloudSDKException
     */
    private function postRequest($action, $request)
    {
        $body = $this->formatRequestData($action, $request, HttpProfile::$REQ_POST);
        $connect = $this->getConnect();

        return $connect->postRequest($this->path, [], $body);
    }

    /**
     * @throws TencentCloudSDKException
     */
    private function formatRequestData($action, $request, $reqMethod)
    {
        $param = $request;
        $param['Action'] = ucfirst($action);
        $param['RequestClient'] = $this->sdkVersion;
        $param['Nonce'] = rand();
        $param['Timestamp'] = time();
        $param['Version'] = $this->apiVersion;

        if ($this->credential->getSecretId()) {
            $param['SecretId'] = $this->credential->getSecretId();
        }

        if ($this->region) {
            $param['Region'] = $this->region;
        }

        if ($this->credential->getToken()) {
            $param['Token'] = $this->credential->getToken();
        }

        if ($this->profile->getSignMethod()) {
            $param['SignatureMethod'] = $this->profile->getSignMethod();
        }

        $language = $this->profile->getLanguage();
        if ($language) {
            $param['Language'] = $language;
        }

        $signStr = $this->formatSignString($this->getRefreshedEndpoint(), $this->path, $param, $reqMethod);
        $param['Signature'] = Sign::sign($this->credential->getSecretKey(), $signStr, $this->profile->getSignMethod());

        return $param;
    }

    private function createConnect()
    {
        $prot = $this->profile->getHttpProfile()->getProtocol();

        return new HttpConnection($prot.$this->getRefreshedEndpoint(), $this->profile);
    }

    private function getConnect()
    {
        $keepAlive = $this->profile->getHttpProfile()->getKeepAlive();
        if ($keepAlive === true) {
            return $this->httpConn;
        } else {
            return $this->createConnect();
        }
    }

    private function formatSignString($host, $uri, $param, $requestMethod)
    {
        $tmpParam = [];
        ksort($param);
        foreach ($param as $key => $value) {
            array_push($tmpParam, $key.'='.$value);
        }
        $strParam = implode('&', $tmpParam);
        $signStr = strtoupper($requestMethod).$host.$uri.'?'.$strParam;

        return $signStr;
    }

    private function getPrivateMethod($obj, $methodName)
    {
        $objReflectClass = new ReflectionClass(get_class($obj));
        $method = $objReflectClass->getMethod($methodName);

        return $method;
    }

    /**
     * User might call httpProfile.SetEndpoint after client is initialized,
     * so everytime we get the enpoint we need to check it.
     * Or we must find a way to disable such usage.
     */
    private function getRefreshedEndpoint()
    {
        $this->endpoint = $this->profile->getHttpProfile()->getEndpoint();
        if ($this->endpoint === null) {
            $this->endpoint = $this->service.'.'.$this->profile->getHttpProfile()->getRootDomain();
        }

        return $this->endpoint;
    }
}
