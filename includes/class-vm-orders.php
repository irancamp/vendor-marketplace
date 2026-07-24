<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * واکشی سفارشاتی که حاوی محصولات یک فروشنده خاص هستند
 * (چون هر سفارش ووکامرس می‌تواند شامل محصولات چند فروشنده باشد،
 *  کوئری بر اساس نویسنده محصولِ هر آیتم سفارش انجام می‌شود - سازگار با HPOS)
 */
class VM_Orders {

	private static $instance = null;

	// سفارشات با این وضعیت‌ها هرگز به فروشنده نمایش داده نمی‌شوند (سطل زباله و پیش‌نویس خودکار)
	const EXCLUDED_STATUSES = array( 'trash', 'auto-draft' );

	// سفارشاتی که در تب جداگانه «لغو شده» نمایش داده می‌شوند
	const CANCELLED_STATUSES = array( 'cancelled', 'failed', 'refunded' );

	// وضعیت‌هایی که یعنی سفارش پرداخت شده و در حال پیگیری/آماده‌سازی است (نه هنوز تکمیل نهایی)
	const IN_PROGRESS_STATUSES = array( 'processing', 'on-hold' );

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * همه شناسه‌های سفارشی که حداقل یک محصول از این فروشنده دارند (بدون صفحه‌بندی)
	 * چون فیلتر وضعیت سفارش (سطل‌زباله/لغو/...) در سطح PHP انجام می‌شود، ابتدا
	 * همه شناسه‌ها را می‌گیریم و بعد فیلتر و صفحه‌بندی می‌کنیم.
	 */
	private static function get_vendor_order_ids_all( $vendor_id ) {
		global $wpdb;

		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT oi.order_id
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
			     ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
			 INNER JOIN {$wpdb->posts} p ON p.ID = oim.meta_value
			 WHERE p.post_author = %d AND oi.order_item_type = 'line_item'
			 ORDER BY oi.order_item_id DESC",
			$vendor_id
		) );

		return array_map( 'absint', $order_ids );
	}

	/**
	 * لیست سفارشات (به‌همراه شیء WC_Order) یک فروشنده، با حذف سفارشات سطل‌زباله،
	 * به تفکیک تب: 'active' (سفارشات موفق/در جریان) یا 'cancelled' (لغوشده/ناموفق)
	 */
	private static function get_vendor_orders_filtered( $vendor_id, $tab = 'active' ) {
		$all_ids = self::get_vendor_order_ids_all( $vendor_id );
		$result  = array();

		foreach ( $all_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) continue;

			$status = $order->get_status();
			if ( in_array( $status, self::EXCLUDED_STATUSES, true ) ) continue;

			$is_cancelled_group = in_array( $status, self::CANCELLED_STATUSES, true );
			if ( $tab === 'cancelled' && ! $is_cancelled_group ) continue;
			if ( $tab === 'active' && $is_cancelled_group ) continue;

			$result[] = $order;
		}

		return $result;
	}

	/**
	 * فقط آیتم‌های متعلق به این فروشنده از یک سفارش را برمی‌گرداند
	 */
	public static function get_vendor_items_for_order( $order, $vendor_id ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			// نکته مهم: get_product_id() همیشه آیدی محصول اصلی (والد) را برمی‌گرداند،
			// اما get_product()->get_id() برای محصولات متغیر آیدی «تنوع» را برمی‌گرداند
			// که نویسنده‌اش می‌تواند با نویسنده محصول اصلی یکی نباشد.
			$product_id = $item->get_product_id();
			if ( ! $product_id ) continue;
			if ( (int) get_post_field( 'post_author', $product_id ) !== (int) $vendor_id ) continue;

			$line_total = (float) $item->get_total();
			$quantity   = max( 1, (int) $item->get_quantity() );

			$commission_meta = wc_get_order_item_meta( $item_id, '_vm_commission_amount', true );
			$payout_meta     = wc_get_order_item_meta( $item_id, '_vm_payout_amount', true );
			$is_final        = ( $commission_meta !== '' && $payout_meta !== '' );

			if ( $is_final ) {
				$commission = (float) $commission_meta;
				$payout     = (float) $payout_meta;
			} else {
				$commission = VM_Commission::calculate_commission( $vendor_id, $line_total );
				if ( $commission > $line_total ) $commission = $line_total;
				$payout = round( $line_total - $commission, 2 );
			}

			$items[] = array(
				'item_id'    => $item_id,
				'name'       => $item->get_name(),
				'quantity'   => $quantity,
				'unit_price' => round( $line_total / $quantity, 2 ),
				'total'      => $line_total,
				'commission' => $commission,
				'payout'     => $payout,
				'is_final'   => $is_final,
			);
		}
		return $items;
	}

	/**
	 * تبدیل یک WC_Order به آرایه آماده نمایش برای یک فروشنده مشخص
	 */
	private static function build_order_row( $order, $vendor_id ) {
		$items = self::get_vendor_items_for_order( $order, $vendor_id );

		$shipping_address = trim( $order->get_formatted_shipping_address() );
		if ( ! $shipping_address ) {
			$shipping_address = trim( $order->get_formatted_billing_address() );
		}

		$status = $order->get_status();

		return array(
			'order_id'            => $order->get_id(),
			'date'                => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y/m/d H:i' ) : '',
			'status'              => wc_get_order_status_name( $status ),
			'status_key'          => $status,
			'is_paid'             => in_array( $status, array_merge( self::IN_PROGRESS_STATUSES, array( 'completed' ) ), true ),
			'is_cancelled'        => in_array( $status, self::CANCELLED_STATUSES, true ),
			'can_vendor_complete' => in_array( $status, self::IN_PROGRESS_STATUSES, true ),
			'customer'            => $order->get_formatted_billing_full_name(),
			'phone'               => $order->get_billing_phone(),
			'email'               => $order->get_billing_email(),
			'address'             => $shipping_address,
			'payment_method'      => $order->get_payment_method_title(),
			'customer_note'       => $order->get_customer_note(),
			'items'               => $items,
			'sale_total'          => array_sum( wp_list_pluck( $items, 'total' ) ),
			'commission_total'    => array_sum( wp_list_pluck( $items, 'commission' ) ),
			'payout_total'        => array_sum( wp_list_pluck( $items, 'payout' ) ),
		);
	}

	/**
	 * خروجی نهایی آماده برای نمایش در شورت‌کد (شامل جزئیات کامل برای هر سفارش)
	 *
	 * @param int    $vendor_id
	 * @param int    $paged
	 * @param int    $per_page
	 * @param string $tab 'active' (سفارشات موفق/در جریان، پیش‌فرض) یا 'cancelled' (لغوشده/ناموفق)
	 */
	public static function get_vendor_orders_data( $vendor_id, $paged = 1, $per_page = 10, $tab = 'active' ) {
		$orders = self::get_vendor_orders_filtered( $vendor_id, $tab );
		$total  = count( $orders );

		$offset = ( max( 1, $paged ) - 1 ) * $per_page;
		$page_orders = array_slice( $orders, $offset, $per_page );

		$data = array();
		foreach ( $page_orders as $order ) {
			$data[] = self::build_order_row( $order, $vendor_id );
		}

		return array(
			'orders'      => $data,
			'total'       => $total,
			'per_page'    => $per_page,
			'paged'       => $paged,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * شمارش‌های خلاصه برای نوار بالای پنل فروشنده:
	 * تعداد سفارشات «در حال انجام»، تعداد سفارشات موفق (پرداخت‌شده)، و تعداد لغوشده
	 */
	public static function get_vendor_order_counts( $vendor_id ) {
		$active_orders    = self::get_vendor_orders_filtered( $vendor_id, 'active' );
		$cancelled_orders = self::get_vendor_orders_filtered( $vendor_id, 'cancelled' );

		$in_progress     = 0;
		$in_progress_sum = 0.0;
		$paid_total      = 0;
		foreach ( $active_orders as $order ) {
			$status = $order->get_status();
			if ( in_array( $status, self::IN_PROGRESS_STATUSES, true ) ) {
				$in_progress++;
				$row = self::build_order_row( $order, $vendor_id );
				$in_progress_sum += $row['sale_total'];
			}
			if ( in_array( $status, array_merge( self::IN_PROGRESS_STATUSES, array( 'completed' ) ), true ) ) {
				$paid_total++;
			}
		}

		return array(
			'in_progress_count' => $in_progress,
			'in_progress_sum'   => $in_progress_sum,
			'paid_count'        => $paid_total,
			'active_count'      => count( $active_orders ),
			'cancelled_count'   => count( $cancelled_orders ),
		);
	}

	/* ==================================================================
	 * نمای کلی سفارشات همه فروشندگان - مخصوص پنل مدیرکل
	 * هر ردیف = ترکیب (سفارش، فروشنده) تا وقتی سفارشی چند فروشنده دارد
	 * برای هر فروشنده جداگانه و شفاف نمایش داده شود
	 * ================================================================ */

	/**
	 * لیست جفت‌های (order_id, vendor_id) موجود در کل سایت، جدید به قدیم
	 */
	private static function get_all_order_vendor_pairs_all() {
		global $wpdb;

		$vendor_ids = get_users( array( 'role__in' => array_keys( VM_Roles::get_vendor_roles() ), 'fields' => 'ID' ) );
		if ( empty( $vendor_ids ) ) return array();

		$placeholders = implode( ',', array_fill( 0, count( $vendor_ids ), '%d' ) );

		$sql = "SELECT oi.order_id, p.post_author AS vendor_id, MAX(oi.order_item_id) AS last_item_id
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				     ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
				 INNER JOIN {$wpdb->posts} p ON p.ID = oim.meta_value
				 WHERE p.post_author IN ({$placeholders}) AND oi.order_item_type = 'line_item'
				 GROUP BY oi.order_id, p.post_author
				 ORDER BY last_item_id DESC";

		return $wpdb->get_results( $wpdb->prepare( $sql, $vendor_ids ) );
	}

	/**
	 * داده کامل و آماده برای نمایش در پنل مدیرکل: هر سفارش به تفکیک فروشنده (بدون سفارشات سطل‌زباله)
	 */
	public static function get_all_orders_overview( $paged = 1, $per_page = 20 ) {
		$pairs = self::get_all_order_vendor_pairs_all();
		$rows  = array();

		foreach ( $pairs as $pair ) {
			$order = wc_get_order( $pair->order_id );
			if ( ! $order ) continue;
			if ( in_array( $order->get_status(), self::EXCLUDED_STATUSES, true ) ) continue;

			$vendor = get_userdata( $pair->vendor_id );
			$items  = self::get_vendor_items_for_order( $order, $pair->vendor_id );

			if ( empty( $items ) ) continue; // لایه امنیتی؛ نباید رخ دهد

			$rows[] = array(
				'order_id'         => $pair->order_id,
				'vendor_id'        => $pair->vendor_id,
				'vendor_name'      => $vendor ? $vendor->display_name : '—',
				'date'             => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y/m/d H:i' ) : '',
				'status'           => wc_get_order_status_name( $order->get_status() ),
				'status_key'       => $order->get_status(),
				'customer'         => $order->get_formatted_billing_full_name(),
				'phone'            => $order->get_billing_phone(),
				'items'            => $items,
				'sale_total'       => array_sum( wp_list_pluck( $items, 'total' ) ),
				'commission_total' => array_sum( wp_list_pluck( $items, 'commission' ) ),
				'payout_total'     => array_sum( wp_list_pluck( $items, 'payout' ) ),
			);
		}

		$total  = count( $rows );
		$offset = ( max( 1, $paged ) - 1 ) * $per_page;
		$page_rows = array_slice( $rows, $offset, $per_page );

		return array(
			'rows'        => $page_rows,
			'total'       => $total,
			'per_page'    => $per_page,
			'paged'       => $paged,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}
}
