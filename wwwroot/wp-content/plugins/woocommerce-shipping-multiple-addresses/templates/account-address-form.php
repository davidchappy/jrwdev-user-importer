<form action="" method="post" id="address_form">

    <?php if ( $updating ): ?>

        <div id="addresses">

            <div class="shipping_address address_block" id="shipping_address_<?php echo $idx; ?>">
                <?php
                foreach ( $shipFields as $key => $field ) {
                    $val = '';

                    if ( isset( $address[ $key ] ) ) {
                        $val = $address[$key];
                    }

                    woocommerce_form_field( $key, $field, $val );
                }

                do_action( 'woocommerce_after_checkout_shipping_form', $checkout);
                ?>
            </div>

        </div>

    <?php else: ?>

        <div id="addresses" class="address-column">

        <?php
        foreach ( $shipFields as $key => $field ) :
            $val = '';

            woocommerce_form_field( $key, $field, $val );
        endforeach;
        ?>
        </div>
    <?php endif; ?>

    <div class="form-row">
        <input type="hidden" name="idx" value="<?php echo $idx; ?>" />
        <input type="hidden" name="shipping_account_address_action" value="save" />
        <input type="submit" name="set_addresses" value="<?php _e( 'Save Address', 'wc_shipping_multiple_address' ); ?>" class="button alt" />
    </div>
</form>
<script type="text/javascript">
    jQuery(document).ready(function() {
        jQuery("#address_form").submit(function() {
            var valid = true;
            jQuery("input[type=text],select").each(function() {
                if (jQuery(this).prev("label").children("abbr").length == 1 && jQuery(this).val() == "") {
                    jQuery(this).focus();
                    valid = false;
                    return false;
                }
            });
            return valid;
        });
    });
</script>