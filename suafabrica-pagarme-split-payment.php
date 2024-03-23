<?php
/**
 * Plugin Name: Sua Fábrica Pagar.me Split Payment for WooCommerce
 * Description: Allow you to use Sua Fabrica rules to split payment with using Pagar.me gateway.
 * Version: 1.0.0
 * Author: FLTECH
 * Author URI: https://suafabrica.com.br
 * Text Domain: suafabrica-pagarme-split-payment
 * Domain Path: /i18n/languages/
 *
 * @package SuaFabricaPagarmeSplitPayment
 */

namespace SuaFabricaPagarmeSplitPayment;

use Pagarme\Core\Marketplace\Aggregates\Split;

defined( 'ABSPATH' ) || exit;
define('PLUGIN_NAME', 'Sua Fábrica Pagar.me Split Payment');

class SuaFabricaPagarmeSplitWooCommerce {
    public static function run()
    {
        // Business rules
        (new SplitRules())->addSplit();
    }
}

class SplitRules
{

    public function split(\WC_Order $order, $paymentMethod)
    {
        $mainRecipientId = get_field('codigo_sua_fabrica_pagarme', 'option');
        $retailerRecipientId = get_field('codigo_recebedor_pagarme', 'option');

        if(!$retailerRecipientId || !$mainRecipientId || !$paymentMethod)
            return null;

        $paymentMethodParsed = str_replace("_","", strtolower($paymentMethod));

        $fieldNameByPaymentMethod = array(
            'creditcard' => 'percentual_credito',
            'credit' => 'percentual_credito',
            'debitcard' => 'percentual_debito',
            'debit' => 'percentual_debito',
            'pix' => 'percentual_pix',
            'boleto' => 'percentual_boleto'
        );

        $percentageFieldKey = $fieldNameByPaymentMethod[$paymentMethodParsed];

        $pergentage = get_field($percentageFieldKey, 'option');

        $partners = $this->partnersAmountOverOrder($order, $pergentage, $mainRecipientId, $retailerRecipientId);

        if(empty($partners))
            return null;

        return $partners;
    }

    private function partnersAmountOverOrder(\WC_Order $order, $suaFabricaGatewayPercentage, $mainRecipientId, $retailerRecipientId)
    {
        $logMessage = "Calculating split for order " . $order->get_id();

        wc_get_logger()->info( $logMessage, array( 'source' => 'SUA_FABRICA' ) );

        $items = $order->get_items();

        if (!$items) {
            return [];
        }

        $fullOrderTotal = $order->get_total();
        $shippingTotal = $order->get_shipping_total();
        $gatewayTax = $fullOrderTotal * ($suaFabricaGatewayPercentage / 100);
        $orderTotalCost = (float) $order->get_meta( '_wc_cog_order_total_cost', true, 'edit' );

        $suaFabricaTotal = $orderTotalCost + $shippingTotal + $gatewayTax;
        $retailerTotal = $fullOrderTotal - $suaFabricaTotal;

        /*
        ## EXEMPLO ##

        Pedido: R$ 500,00
        Frete: R$ 27,00
        Preço de Custo Itens: R$ 300,00
        Percentual Sua Fábrica: 3% = R$ 15,00

        Valor Sua Fábrica: 300 + 27 + 15 = 342
        Valor Cliente: 500 - 342 = 158
        */

        $suaFabricaTotal = round($suaFabricaTotal, 2, PHP_ROUND_HALF_UP);
        $retailerTotal = round($retailerTotal, 2, PHP_ROUND_HALF_DOWN);

        $logMessage = "Calculated split for order " . $order->get_id() . ". [Sua Fabrica]: " . $suaFabricaTotal . " [Retailer]: " . $retailerTotal;

        wc_get_logger()->info( $logMessage, array( 'source' => 'SUA_FABRICA' ) );

        return $this->buildSplit($mainRecipientId, $retailerRecipientId, $suaFabricaTotal, $retailerTotal);
    }

    private function buildSplit($mainRecipientId, $retailerRecipientId, $suaFabricaTotal, $retailerTotal) {

        $suaFabricaTotal = intval($suaFabricaTotal * 100);
        $retailerTotal = intval($retailerTotal * 100);

        $sellerAndCommisions = [
            "marketplaceCommission" => $suaFabricaTotal,
            "commission" => $retailerTotal,
            "pagarmeId" => $retailerRecipientId
        ];

        $splitDataFromOrder = [
            'sellers' => [
                $retailerRecipientId => $sellerAndCommisions
            ],
            'marketplace' => [
                'totalCommission' => $suaFabricaTotal
            ]
        ];

        $splitData = new Split();
        $splitData->setRecipientId($mainRecipientId);
        $splitData->setCommission($suaFabricaTotal);
        $splitData->setSellersData($splitDataFromOrder['sellers']);
        $splitData->setMarketplaceData($splitDataFromOrder['marketplace']);

        return $splitData;
    }

    public function addSplit()
    {
        add_filter('wc_pagarme_split_data', array($this, 'split'), 10, 2);
    }
}

add_action('after_setup_theme', function() {
    SuaFabricaPagarmeSplitWooCommerce::run();
});
