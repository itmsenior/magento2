<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\PaypalGraphQl\Model\Plugin\Resolver;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Framework\Stdlib\ArrayManagerFactory;
use Magento\Paypal\Model\Express\Checkout\Factory as CheckoutFactory;
use Magento\PaypalGraphQl\Model\Provider\Checkout as CheckoutProvider;
use Magento\PaypalGraphQl\Model\Provider\Config as ConfigProvider;

/**
 * Plugin to perform Paypal-specific logic when setting payment method on cart
 */
class SetPaymentMethodOnCart
{
    private const PATH_CODE = 'input/payment_method/code';

    private const PATH_PAYMENT_METHOD_DATA = 'input/payment_method';

    /**
     * @var array $allowedPaymentMethodCodes
     */
    private array $allowedPaymentMethodCodes = [];

    /**
     * @var CheckoutFactory
     */
    private $checkoutFactory;

    /**
     * @var ArrayManagerFactory
     * phpcs:disable Magento2.Commenting.ClassPropertyPHPDocFormatting
     */
    private readonly ArrayManagerFactory $arrayManagerFactory;

    /**
     * @var CheckoutProvider
     */
    private $checkoutProvider;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @param CheckoutFactory $checkoutFactory
     * @param ArrayManager $arrayManager @deprecated @see $arrayManagerFactory
     * @param CheckoutProvider $checkoutProvider
     * @param ConfigProvider $configProvider
     * @param array $allowedPaymentMethodCodes
     * @param ArrayManagerFactory|null $arrayManagerFactory
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        CheckoutFactory $checkoutFactory,
        ArrayManager $arrayManager,
        CheckoutProvider $checkoutProvider,
        ConfigProvider $configProvider,
        array $allowedPaymentMethodCodes = [],
        ?ArrayManagerFactory $arrayManagerFactory = null,
    ) {
        $this->checkoutFactory = $checkoutFactory;
        $this->arrayManagerFactory = $arrayManagerFactory
            ?? ObjectManager::getInstance()->get(ArrayManagerFactory::class);
        $this->checkoutProvider = $checkoutProvider;
        $this->configProvider = $configProvider;
        $this->allowedPaymentMethodCodes = $allowedPaymentMethodCodes;
    }

    /**
     * Update Paypal payment information on cart
     *
     * @param ResolverInterface $subject
     * @param array $resolvedValue
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return mixed
     * @throws GraphQlInputException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterResolve(
        ResolverInterface $subject,
        $resolvedValue,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $paymentCode = $this->arrayManagerFactory->create()->get(self::PATH_CODE, $args) ?? '';
        if (!$this->isAllowedPaymentMethod($paymentCode)) {
            return $resolvedValue;
        }
        $paypalAdditionalData = $this->arrayManagerFactory->create()->get(self::PATH_PAYMENT_METHOD_DATA, $args) ?? [];
        $payerId = $paypalAdditionalData[$paymentCode]['payer_id'] ?? null;
        $token = $paypalAdditionalData[$paymentCode]['token'] ?? null;
        $cart = $resolvedValue['cart']['model'];

        if ($payerId && $token) {
            $config = $this->configProvider->getConfig($paymentCode);
            $checkout = $this->checkoutProvider->getCheckout($config, $cart);

            try {
                $checkout->returnFromPaypal($token, $payerId);
            } catch (LocalizedException $e) {
                throw new GraphQlInputException(__($e->getMessage()));
            }
        }

        return $resolvedValue;
    }

    /**
     * Check if payment method code is one that should be handled by this plugin
     *
     * @param string $paymentCode
     * @return bool
     */
    private function isAllowedPaymentMethod(string $paymentCode): bool
    {
        return !empty($paymentCode) && in_array($paymentCode, $this->allowedPaymentMethodCodes);
    }
}
