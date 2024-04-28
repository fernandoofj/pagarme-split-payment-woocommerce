<?php
/**
 * Plugin Name: Sua Fábrica Pagar.me Split Payment for WooCommerce
 * Description: Allow you to use Sua Fabrica rules to split payment with using Pagar.me gateway.
 * Version: 1.2.0
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

        $item = reset($order->get_items());
        $isSubscription = $order->get_item_count() == 1 && $item != null && $item->get_product() != null && $item->get_product()->get_type() == 'subscription';

        if (!$isSubscription) {
            if (!$retailerRecipientId || !$mainRecipientId ) return null;

            $pergentage = get_field('taxa_administrativa', 'option');

            $partners = $this->partnersAmountOverOrder($order, $pergentage, $mainRecipientId, $retailerRecipientId);

            if (empty($partners)) return null;

            return $partners;
        }
        else {
            $percentualFernando = get_field('percentual_fernando', 'option');
            if (!$percentualFernando || $percentualFernando <= 10) throw new Exception('The split percentage was not configured yet');

            $partners = $this->partnersAmountOverSubscription($order, $percentualFernando, $mainRecipientId, $retailerRecipientId);

            if (empty($partners)) return null;

            return $partners;
        }
    }

    private function partnersAmountOverSubscription(\WC_Order $order, $percentualFernando, $mainRecipientId, $retailerRecipientId){

        $logMessage = "Calculating split for subscription " . $order->get_id();
        wc_get_logger()->info( $logMessage, array( 'source' => 'SUA_FABRICA' ) );

        $fullOrderTotal = $order->get_total();
        $fernandoTax = $fullOrderTotal * ($percentualFernando / 100);

        $suaFabricaTotal = $fullOrderTotal - $fernandoTax;
        $retailerTotal = $fernandoTax;

        $suaFabricaTotal = round($suaFabricaTotal, 2, PHP_ROUND_HALF_UP);
        $retailerTotal = round($retailerTotal, 2, PHP_ROUND_HALF_DOWN);

        $logMessage = "Calculated split for subscripton " . $order->get_id() . ". [Sua Fabrica]: " . $suaFabricaTotal . " [Fernando]: " . $retailerTotal;
        wc_get_logger()->info( $logMessage, array( 'source' => 'SUA_FABRICA' ) );

        return $this->buildSplit($mainRecipientId, $retailerRecipientId, $suaFabricaTotal, $retailerTotal);
    }

    private function partnersAmountOverOrder(\WC_Order $order, $suaFabricaAdminTax, $mainRecipientId, $retailerRecipientId)
    {
        $logMessage = "Calculating split for order " . $order->get_id();

        wc_get_logger()->info( $logMessage, array( 'source' => 'SUA_FABRICA' ) );

        $items = $order->get_items();

        if (!$items) {
            return [];
        }

        $fullOrderTotal = $order->get_total();
        $shippingTotal = $order->get_shipping_total();
        $gatewayTax = $fullOrderTotal * ($suaFabricaAdminTax / 100);
        $orderTotalCost = (float) $order->get_meta( '_wc_cog_order_total_cost', true, 'edit' );

        $suaFabricaTotal = $orderTotalCost + $shippingTotal + $gatewayTax;
        $retailerTotal = $fullOrderTotal - $suaFabricaTotal;

        /*
        ## EXEMPLO ##

        Pedido: R$ 500,00
        Frete: R$ 20,00
        Preço de Custo Itens: R$ 300,00
        Percentual Sua Fábrica: 10% = R$ 50,00

        Valor Sua Fábrica: 300 + 20 + 50 = 370
        Valor Cliente: 500 - 370 = 130
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
