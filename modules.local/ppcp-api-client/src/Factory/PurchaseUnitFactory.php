<?php
declare(strict_types=1);

namespace Inpsyde\PayPalCommerce\ApiClient\Factory;

use Inpsyde\PayPalCommerce\ApiClient\Entity\Amount;
use Inpsyde\PayPalCommerce\ApiClient\Entity\AmountBreakdown;
use Inpsyde\PayPalCommerce\ApiClient\Entity\Item;
use Inpsyde\PayPalCommerce\ApiClient\Entity\Money;
use Inpsyde\PayPalCommerce\ApiClient\Entity\PurchaseUnit;
use Inpsyde\PayPalCommerce\ApiClient\Entity\Shipping;
use Inpsyde\PayPalCommerce\ApiClient\Exception\RuntimeException;

class PurchaseUnitFactory
{

    private $amountFactory;
    private $payeeFactory;
    private $itemFactory;
    private $shippingFactory;
    public function __construct(
        AmountFactory $amountFactory,
        PayeeFactory $payeeFactory,
        ItemFactory $itemFactory,
        ShippingFactory $shippingFactory
    ) {

        $this->amountFactory = $amountFactory;
        $this->payeeFactory = $payeeFactory;
        $this->itemFactory = $itemFactory;
        $this->shippingFactory = $shippingFactory;
    }

    public function fromWcOrder(\WC_Order $order) : PurchaseUnit
    {
        $currency = get_woocommerce_currency();

        $items = array_map(
            function (\WC_Order_Item_Product $item) use ($currency, $order): Item {

                $product = $item->get_product();
                /**
                 * @var \WC_Product $product
                 */
                $quantity = $item->get_quantity();

                $price = (float) $order->get_item_subtotal($item, true);
                $priceWithoutTax = (float) $order->get_item_subtotal($item, false);
                $priceWithoutTaxRounded = round($priceWithoutTax, 2);
                $tax = round($price - $priceWithoutTaxRounded, 2);
                $tax = new Money($tax, $currency);
                return new Item(
                    mb_substr($product->get_name(), 0, 127),
                    new Money($priceWithoutTaxRounded, $currency),
                    $quantity,
                    mb_substr($product->get_description(), 0, 127),
                    $tax,
                    $product->get_sku(),
                    ($product->is_downloadable()) ? Item::DIGITAL_GOODS : Item::PHYSICAL_GOODS
                );
            },
            $order->get_items('line_item')
        );

        $total = new Money((float) $order->get_total(), $currency);
        $itemsTotal = new Money(array_reduce(
            $items,
            function (float $total, Item $item) : float {
                return $total + $item->quantity() * $item->unitAmount()->value();
            },
            0
        ), $currency);
        $shipping = new Money(
            (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(),
            $currency
        );
        $taxes = new Money(array_reduce(
            $items,
            function (float $total, Item $item) : float {
                return $total + $item->quantity() * $item->tax()->value();
            },
            0
        ), $currency);

        //ToDo: Discount, evaluate if more is needed? Fees?
        $breakdown = new AmountBreakdown(
            $itemsTotal,
            $shipping,
            $taxes,
            null, // insurance?
            null, // handling?
            null, //shipping discounts?
            null //discounts!
        );
        $amount = new Amount(
            $total,
            $breakdown
        );
        $shipping = $this->shippingFactory->fromWcOrder($order);

        $referenceId = 'default';
        $description = '';

        //ToDo: We need to create a Payee.
        $payee = null;

        $customId = '';
        $invoiceId = '';
        $softDescriptor = '';
        $purchaseUnit = new PurchaseUnit(
            $amount,
            $items,
            $shipping,
            $referenceId,
            $description,
            $payee,
            $customId,
            $invoiceId,
            $softDescriptor
        );

        return $purchaseUnit;
    }

    public function fromWcCart(\WC_Cart $cart) : PurchaseUnit
    {
        $currency = get_woocommerce_currency();
        $total = new Money((float) $cart->get_total('numeric'), $currency);
        $itemsTotal = $cart->get_cart_contents_total();
        $itemsTotal = new Money((float) $itemsTotal, $currency);
        $shipping = new Money(
            (float) $cart->get_shipping_total() + $cart->get_shipping_tax(),
            $currency
        );

        $taxes = new Money((float) $cart->get_cart_contents_tax(), $currency);

        //ToDo: Discount, evaluate if more is needed? Fees?
        $breakdown = new AmountBreakdown(
            $itemsTotal,
            $shipping,
            $taxes,
            null, // insurance?
            null, // handling?
            null, //shipping discounts?
            null //discounts!
        );
        $amount = new Amount(
            $total,
            $breakdown
        );
        $items = array_map(
            function (array $item) use ($currency): Item {
                $product = $item['data'];
                /**
                 * @var \WC_Product $product
                 */
                $quantity = (int) $item['quantity'];

                $price = (float) wc_get_price_including_tax($product);
                $priceWithoutTax = (float) wc_get_price_excluding_tax($product);
                $priceWithoutTaxRounded = round($priceWithoutTax, 2);
                $tax = round($price - $priceWithoutTaxRounded, 2);
                $tax = new Money($tax, $currency);
                return new Item(
                    mb_substr($product->get_name(), 0, 127),
                    new Money($priceWithoutTaxRounded, $currency),
                    $quantity,
                    mb_substr($product->get_description(), 0, 127),
                    $tax,
                    $product->get_sku(),
                    ($product->is_downloadable()) ? Item::DIGITAL_GOODS : Item::PHYSICAL_GOODS
                );
            },
            $cart->get_cart_contents()
        );

        //ToDo: Do we need shipping here?
        $shipping = null;

        $referenceId = 'default';
        $description = '';

        //ToDo: We need to create a Payee.
        $payee = null;

        $customId = '';
        $invoiceId = '';
        $softDescriptor = '';
        $purchaseUnit = new PurchaseUnit(
            $amount,
            $items,
            $shipping,
            $referenceId,
            $description,
            $payee,
            $customId,
            $invoiceId,
            $softDescriptor
        );

        return $purchaseUnit;
    }

    public function fromPayPalResponse(\stdClass $data) : PurchaseUnit
    {
        if (! isset($data->reference_id) || ! is_string($data->reference_id)) {
            throw new RuntimeException(__("No reference ID given.", "woocommercepaypal-commerce-gateway"));
        }

        $amount = $this->amountFactory->fromPayPalResponse($data->amount);
        $description = (isset($data->description)) ? $data->description : '';
        $customId = (isset($data->customId)) ? $data->customId : '';
        $invoiceId = (isset($data->invoiceId)) ? $data->invoiceId : '';
        $softDescriptor = (isset($data->softDescriptor)) ? $data->softDescriptor : '';
        $items = [];
        if (isset($data->items) && is_array($data->items)) {
            $items = array_map(
                function (\stdClass $item) : Item {
                    return $this->itemFactory->fromPayPalRequest($item);
                },
                $data->items
            );
        }
        $payee = isset($data->payee) ? $this->payeeFactory->fromPayPalResponse($data->payee) : null;
        $shipping = isset($data->shipping) ?
            $this->shippingFactory->fromPayPalResponse($data->shipping)
            : null;
        return new PurchaseUnit(
            $amount,
            $items,
            $shipping,
            $data->reference_id,
            $description,
            $payee,
            $customId,
            $invoiceId,
            $softDescriptor
        );
    }
}