<?php
/**
 * Copyright © Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */

namespace Amazon\PayV2\Model\Adapter;

class AmazonPayV2Adapter
{
    const PAYMENT_INTENT_CONFIRM = 'Confirm';
    const PAYMENT_INTENT_AUTHORIZE = 'Authorize';
    const PAYMENT_INTENT_AUTHORIZE_WITH_CAPTURE = 'AuthorizeWithCapture';

    /**
     * @var \Amazon\PayV2\Client\ClientFactoryInterface
     */
    private $clientFactory;

    /**
     * @var \Amazon\PayV2\Model\AmazonConfig
     */
    private $amazonConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var \Amazon\PayV2\Helper\Data
     */
    private $amazonHelper;

    /**
     * @var \Amazon\PayV2\Logger\Logger
     */
    private $logger;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $url;

    /**
     * AmazonPayV2Adapter constructor.
     * @param \Amazon\PayV2\Client\ClientFactoryInterface $clientFactory
     * @param \Amazon\PayV2\Model\AmazonConfig $amazonConfig
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Amazon\PayV2\Helper\Data $amazonHelper
     * @param \Amazon\PayV2\Logger\Logger $logger
     * @pqram \Magento\Framework\UrlInterface $url
     */
    public function __construct(
        \Amazon\PayV2\Client\ClientFactoryInterface $clientFactory,
        \Amazon\PayV2\Model\AmazonConfig $amazonConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Amazon\PayV2\Helper\Data $amazonHelper,
        \Amazon\PayV2\Logger\Logger $logger,
        \Magento\Framework\UrlInterface $url
    ) {
        $this->clientFactory = $clientFactory;
        $this->amazonConfig = $amazonConfig;
        $this->storeManager = $storeManager;
        $this->quoteRepository = $quoteRepository;
        $this->amazonHelper = $amazonHelper;
        $this->logger = $logger;
        $this->url = $url;
    }

    /**
     * @return string
     */
    protected function getMerchantCustomInformation()
    {
        return sprintf('Magento Version: 2, Plugin Version: %s (v2)', $this->amazonHelper->getVersion());
    }

    /**
     * @param mixed $amount
     * @param string $currencyCode
     * @return array
     */
    protected function createPrice($amount, $currencyCode)
    {
        switch ($currencyCode) {
            case 'JPY':
                $amount = round($amount);
                break;
            default:
                $amount = (float) $amount;
                break;
        }
        return [
            'amount' => $amount,
            'currencyCode' => $currencyCode,
        ];
    }

    /**
     * Return checkout session details
     *
     * @param $storeId
     * @param $checkoutSessionId
     * @return mixed
     */
    public function getCheckoutSession($storeId, $checkoutSessionId)
    {
        $response = $this->clientFactory->create($storeId)->getCheckoutSession($checkoutSessionId);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Update Checkout Session to set payment info and transaction metadata
     *
     * @param $quote
     * @param $checkoutSessionId
     * @param $paymentIntent
     * @return mixed
     */
    public function updateCheckoutSession($quote, $checkoutSessionId, $paymentIntent = self::PAYMENT_INTENT_AUTHORIZE)
    {
        $storeId = $quote->getStoreId();
        $store = $quote->getStore();

        if (!$quote->getReservedOrderId()) {
            try {
                $quote->reserveOrderId()->save();
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());
            }
        }

        $payload = [
            'webCheckoutDetails' => [
                'checkoutResultReturnUrl' => $this->amazonConfig->getCheckoutResultUrl()
            ],
            'paymentDetails' => [
                'paymentIntent' => $paymentIntent,
                'canHandlePendingAuthorization' => $this->amazonConfig->canHandlePendingAuthorization(),
                'chargeAmount' => $this->createPrice($quote->getGrandTotal(), $quote->getQuoteCurrencyCode()),
            ],
            'merchantMetadata' => [
                'merchantReferenceId' => $quote->getReservedOrderId(),
                'merchantStoreName' => $this->amazonConfig->getStoreName(),
                'customInformation' => $this->getMerchantCustomInformation(),
            ],
            'platformId' => $this->amazonConfig->getPlatformId(),
        ];

        $response = $this->clientFactory->create($storeId)->updateCheckoutSession($checkoutSessionId, $payload);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Get charge
     *
     * @param $storeId
     * @param $chargeId
     * @return mixed
     */
    public function getCharge($storeId, $chargeId)
    {
        $response = $this->clientFactory->create($storeId)->getCharge($chargeId);
        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Create charge
     *
     * @param $storeId
     * @param $chargePermissionId
     * @param $amount
     * @param $currency
     * @param bool $captureNow
     * @return mixed
     */
    public function createCharge($storeId, $chargePermissionId, $amount, $currency, $captureNow = false)
    {
        $headers = $this->getIdempotencyHeader();

        $payload = [
            'chargePermissionId' => $chargePermissionId,
            'chargeAmount' => $this->createPrice($amount, $currency),
            'captureNow' => $captureNow,
        ];

        $response = $this->clientFactory->create($storeId)->createCharge($payload, $headers);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Capture charge
     *
     * @param $storeId
     * @param $chargeId
     * @param $amount
     * @param $currency
     * @param array $headers
     * @return mixed
     */
    public function captureCharge($storeId, $chargeId, $amount, $currency, $headers = [])
    {
        $headers = array_merge($headers, $this->getIdempotencyHeader());

        $payload = [
            'captureAmount' => $this->createPrice($amount, $currency),
        ];

        $response = $this->clientFactory->create($storeId)->captureCharge($chargeId, $payload, $headers);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Create refund
     *
     * @param $storeId
     * @param $chargeId
     * @param $amount
     * @param $currency
     * @return mixed
     */
    public function createRefund($storeId, $chargeId, $amount, $currency)
    {
        $headers = $this->getIdempotencyHeader();

        $payload = [
            'chargeId' => $chargeId,
            'refundAmount' => $this->createPrice($amount, $currency),
        ];

        $response = $this->clientFactory->create($storeId)->createRefund($payload, $headers);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Get refund
     *
     * @param $storeId
     * @param $refundId
     * @return mixed
     */
    public function getRefund($storeId, $refundId)
    {
        $response = $this->clientFactory->create($storeId)->getRefund($refundId);
        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * @param int $storeId
     * @param string $chargePermissionId
     * @return array
     */
    public function getChargePermission(int $storeId, string $chargePermissionId)
    {
        $response = $this->clientFactory->create($storeId)->getChargePermission($chargePermissionId);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Cancel charge
     *
     * @param $storeId
     * @param $chargeId
     */
    public function cancelCharge($storeId, $chargeId, $reason = 'ADMIN VOID')
    {
        $payload = [
            'cancellationReason' => $reason
        ];

        $response = $this->clientFactory->create($storeId)->cancelCharge($chargeId, $payload);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * @param int $storeId
     * @param string $chargePermissionId
     * @param string $reason
     * @param boolean $cancelPendingCharges
     * @return array
     */
    public function closeChargePermission($storeId, $chargePermissionId, $reason, $cancelPendingCharges = false)
    {
        $payload = [
            'closureReason' => substr($reason, 0, 255),
            'cancelPendingCharges' => $cancelPendingCharges,
        ];

        $response = $this->clientFactory->create($storeId)->closeChargePermission($chargePermissionId, $payload);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * AuthorizeClient and SaleClient Gateway Command
     *
     * @param $data
     * @return array|mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function authorize($data)
    {
        $quote = $this->quoteRepository->get($data['quote_id']);
        if (!empty($data['amazon_checkout_session_id'])) {
            $response = $this->getCheckoutSession($quote->getStoreId(), $data['amazon_checkout_session_id']);
        } elseif (!empty($data['charge_permission_id'])) {
            $getChargePermissionResponse = $this->getChargePermission(
                $quote->getStoreId(),
                $data['charge_permission_id']
            );
            if ($getChargePermissionResponse['statusDetails']['state'] == "Chargeable") {
                $response = $this->createCharge(
                    $quote->getStoreId(),
                    $data['charge_permission_id'],
                    $data['amount'],
                    $quote->getQuoteCurrencyCode(),
                    true
                );
            }
        }

        return $response;
    }

    /**
     * @param $storeId
     * @param $sessionId
     * @param $amount
     * @param $currencyCode
     */
    public function completeCheckoutSession($storeId, $sessionId, $amount, $currencyCode)
    {
        $payload = [
            'chargeAmount' => $this->createPrice($amount, $currencyCode),
        ];

        $rawResponse = $this->clientFactory->create($storeId)->completeCheckoutSession(
            $sessionId,
            json_encode($payload)
        );
        return $this->processResponse($rawResponse, __FUNCTION__);
    }

    /**
     * @param $token
     * @return array
     */
    public function getBuyer($token)
    {
        $response = $this->clientFactory
            ->create()
            ->getBuyer($token);

        return $this->processResponse($response, __FUNCTION__);
    }

    /**
     * Process SDK client response
     *
     * @param $clientResponse
     * @param $functionName
     * @return array
     */
    protected function processResponse($clientResponse, $functionName)
    {
        $response = [];

        if (!isset($clientResponse['response'])) {
            $this->logger->debug(__('Unable to ' . $functionName));
        } else {
            $response = json_decode($clientResponse['response'], true);
        }

        // Add HTTP response status code
        if (isset($clientResponse['status'])) {
            $response['status'] = $clientResponse['status'];
        }

        // Log
        $isError = !in_array($response['status'], [200, 201]);
        if ($isError || $this->amazonConfig->isLoggingEnabled()) {
            $debugBackTrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
            $this->logger->debug($functionName . ' <- ', $debugBackTrace[1]['args']);
            if ($isError) {
                $this->logger->error($functionName . ' -> ', $response);
            } else {
                $this->logger->debug($functionName . ' -> ', $response);
            }
        }

        return $response;
    }

    /**
     * Generate idempotency header
     *
     * @return array
     */
    protected function getIdempotencyHeader()
    {
        return [
            'x-amz-pay-idempotency-key' => uniqid(),
        ];
    }

    /**
     * Generate login static signature for amazon.Pay.renderButton used by checkout.js
     *
     * @return string
     */
    public function generateLoginButtonPayload()
    {
        $payload = [
            'signInReturnUrl' => $this->url->getRouteUrl('amazon_payv2/login/authorize/'),
            'storeId' => $this->amazonConfig->getClientId(),
            'signInScopes' => ['name', 'email'],
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Generate checkout static signature for amazon.Pay.renderButton used by checkout.js
     *
     * @return string
     */
    public function generateCheckoutButtonPayload()
    {
        $payload = [
            'webCheckoutDetails' => [
                'checkoutReviewReturnUrl' => $this->amazonConfig->getCheckoutReviewUrl(),
            ],
            'storeId' => $this->amazonConfig->getClientId(),
        ];

        if ($deliverySpecs = $this->amazonConfig->getDeliverySpecifications()) {
            $payload['deliverySpecifications'] = $deliverySpecs;
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    public function signButton($payload, $storeId = null)
    {
        return $this->clientFactory->create($storeId)->generateButtonSignature($payload);
    }
}
