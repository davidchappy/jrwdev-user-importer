<div class="wrap">
	<div id="icon-woocommerce" class="icon32 icon32-posts-shop_coupon"><br></div>
	<?php echo "<h2>" . __( 'Send Store Credit', 'woocommerce-store-credit' ) . "</h2>"; ?>

	<form method="post">

		<table class="form-table">

			<tr valign="top"><th scope="row"><?php _e( 'Email Address', 'woocommerce-store-credit' ); ?></th><td>
				<input id="store_credit_email_address" name="store_credit_email_address" class="regular-text" />
			</td></tr>

			<tr valign="top"><th scope="row"><?php _e( 'Credit Amount', 'woocommerce-store-credit' ); ?></th><td>
				<input id="store_credit_amount" name="store_credit_amount" class="regular-text" placeholder="0.00" />
			</td></tr>

		</table>

		<p class="submit">
			<input type="submit" class="button-primary" value="<?php _e( 'Generate coupon and email customer', 'woocommerce-store-credit' ); ?>" />
		</p>

	</form>

</div>