<?php
/**
 * Drop-in replacement for RSMemberFunction::purchase_history_percentage()
 * in the SUMO Reward Points plugin (CodeCanyon item 7791451).
 *
 * This is NOT a vendor file - it's a single corrected method, written from
 * scratch, intended to replace the broken method of the same name and
 * signature inside the plugin's own RSMemberFunction class. See the
 * repository README for the bug explanation and installation steps.
 *
 * Fixes:
 *   1. Lifetime-spend calculation is now HPOS-aware (the original used a
 *      raw SQL query against wp_posts/wp_postmeta, which doesn't see real
 *      order data once a store migrates to WooCommerce's High-Performance
 *      Order Storage).
 *   2. Tier matching now evaluates all configured rules and selects the
 *      correct (best-matching) tier instead of exiting on the first match,
 *      which previously made higher tiers unreachable.
 *   3. Matching tiers are tracked in a plain sequential array instead of
 *      being keyed by threshold value, avoiding a silent collision if an
 *      order-count rule and a spend-amount rule ever share the same
 *      numeric value.
 *
 * Performance note: totals are read via a single aggregate SQL SUM() query
 * against whichever order table is authoritative for your store (HPOS or
 * legacy), rather than instantiating a WC_Order object per past order -
 * this stays fast regardless of how many orders a customer has placed.
 */
public static function purchase_history_percentage( $user_id, $percentage, $Type, $BoolVal = 'no' ) {
	$rules = ( 'earning' == $Type ) ? get_option( 'rewards_dynamic_rule_purchase_history' ) : get_option( 'rewards_dynamic_rule_purchase_history_redeem' );
	if ( ! srp_check_is_array( $rules ) ) {
		return $percentage;
	}

	$purchase_history_selected_order_status = get_option( 'rs_earning_percentage_order_status_control', array( 'completed' ) );

	// wc_get_orders() works correctly regardless of storage backend (HPOS
	// or legacy post-based), and honors the admin's chosen order statuses.
	// Returning IDs only keeps this lightweight.
	$order_ids = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'status'      => $purchase_history_selected_order_status,
			'limit'       => -1,
			'return'      => 'ids',
		)
	);

	$OrderCount = srp_check_is_array( $order_ids ) ? count( $order_ids ) : 0;
	$OrderTotal = 0;

	// Single aggregate query for the totals - no per-order object
	// instantiation, regardless of how many orders the customer has.
	if ( $OrderCount > 0 ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, $OrderCount, '%d' ) );

		$hpos_active = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos_active ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$OrderTotal   = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(total_amount) FROM {$orders_table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$order_ids
				)
			);
		} else {
			$OrderTotal = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_order_total' AND post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$order_ids
				)
			);
		}
	}

	$use_lte = ( '1' == get_option( 'rs_product_purchase_history_range' ) );

	// Sequential [value, percentage] pairs - avoids the array-key
	// collision risk of keying by $Rule['value'] when order-count rules
	// and spend-amount rules could theoretically share a numeric value.
	$matches = array();
	foreach ( $rules as $Rule ) {
		if ( '1' == $Rule['type'] ) {
			// Order-count based rule.
			$BoolValue = ( 'earning' == $Type )
				? ( $use_lte ? ( $OrderCount <= $Rule['value'] ) : ( $OrderCount >= $Rule['value'] ) )
				: ( $OrderCount <= $Rule['value'] );
		} else {
			// Total-amount-spent based rule.
			$BoolValue = ( 'earning' == $Type )
				? ( $use_lte ? ( $OrderTotal <= floatval( $Rule['value'] ) ) : ( $OrderTotal >= floatval( $Rule['value'] ) ) )
				: ( $OrderTotal <= $Rule['value'] );
		}

		// NOTE: no `break` here - collect every tier the customer
		// qualifies for, so we can pick the correct (highest) one below
		// instead of stopping at whichever tier happens to be listed first.
		if ( $BoolValue ) {
			$matches[] = array(
				'value'      => (float) $Rule['value'],
				'percentage' => $Rule['percentage'],
			);
		}
	}

	if ( ! srp_check_is_array( $matches ) ) {
		return $percentage;
	}

	// For ">=" threshold semantics ("after reaching specified value"), the
	// customer should get the HIGHEST tier they qualify for. For "<=" range
	// semantics, they should get the tightest (lowest) matching bound.
	$best = null;
	foreach ( $matches as $entry ) {
		if ( null === $best ) {
			$best = $entry;
			continue;
		}
		if ( $use_lte ? ( $entry['value'] < $best['value'] ) : ( $entry['value'] > $best['value'] ) ) {
			$best = $entry;
		}
	}
	$points_percentage = $best['percentage'];

	$Priority = ( 'earning' == $Type ) ? get_option( 'rs_choose_priority_level_selection' ) : get_option( 'rs_choose_priority_level_selection_for_redeem' );
	if ( '1' == $Priority ) {
		$percentage = ( 'no' == $BoolVal ) ? ( ( $percentage >= $points_percentage ) ? $percentage : $points_percentage ) : $points_percentage;
	} else {
		$percentage = ( 'no' == $BoolVal ) ? ( ( $percentage <= $points_percentage ) ? $percentage : $points_percentage ) : $points_percentage;
	}

	return $percentage;
}
