<?php
$shipments = WC_MS_Order_Shipment::get_by_order( $post->ID );
foreach ( $shipments as $shipment_id ) :
    $shipment = wc_get_order( $shipment_id );
    ?>
    <div class="wc-shipment" id="wc-shipment-<?php echo $shipment_id; ?>">

        <div class="address">
            <strong><?php _e('<strong>Ship To:</strong><br/>', 'wc_shipping_multiple_address'); ?></strong>
            <?php echo $shipment->get_formatted_shipping_address(); ?>
            <br/>
            (<a href="#" class="edit-shipment-address">Edit</a>)
        </div>

        <div class="edit_shipping_address" style="display:none;">
            <?php
            $shipFields = WC()->countries->get_address_fields( $shipment->shipping_country, 'shipping_' );

            if ( $shipFields ) {
                foreach ( $shipFields as $key => $field ) :
                    if (!isset($field['type'])) $field['type'] = 'text';
                    if (!isset($field['label'])) $field['label'] = '';
                    switch ($field['type']) {
                        case "select" :
                            woocommerce_wp_select( array( 'id' => $key, 'label' => $field['label'], 'options' => $field['options'], 'value' => $package['destination'][$addr_key] ) );
                            break;
                        default :
                            woocommerce_wp_text_input( array( 'id' => $key, 'label' => $field['label'], 'value' => $shipment->$key ) );
                            break;

                    }
                endforeach;
            }
            echo '<input type="hidden" name="edit_shipment[]" value="'. $shipment_id .'" />';
            ?>
        </div>
        <h3><?php printf(__('Shipment #%d', 'wc_shipping_multiple_address'), $shipment_id); ?></h3>

        <table class="shipment-items">
            <thead>
            <tr>
                <th class="left"><?php _e('Item', 'wc_shipping_multiple_address'); ?></th>
                <th><?php _e('Quantity', 'wc_shipping_multiple_address'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ( $shipment->get_items() as $item ):
                // get the product; if this variation or product has been deleted, this will return null...
                $_product = $shipment->get_product_from_item( $item );

                $sku = $variation = '';

                if ( $_product ) $sku = $_product->get_sku();
                $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );

                // first, is there order item meta avaialble to display?
                $variation = $item_meta->display( true, true );

                if ( ! $variation && $_product && isset( $_product->variation_data ) ) {
                    // otherwise (for an order added through the admin) lets display the formatted variation data so we have something to fall back to
                    $variation = wc_get_formatted_variation( $_product->variation_data, true );
                }

                $variation = apply_filters( 'wc_ms_product_variation_data', $variation, $_product, $shipment );

                if ( $variation ) {
                    $variation = '<br/><small>' . $variation . '</small>';
                }
            ?>
                <tr>
                    <td><?php echo apply_filters( 'woocommerce_order_product_title', $item['name'], $_product ) . $variation; ?></td>
                    <td align="center"><?php echo $item['qty']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endforeach;