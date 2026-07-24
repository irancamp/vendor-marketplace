<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * محاسبه و واریز کمیسیون فروشندگان
 */
class VM_Commission {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		foreach ( self::get_trigger_statuses() as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'process_order_commissions' ), 10, 1 );
		}

		// فیلدهای کمیسیون در پروفایل کاربر (فقط قابل مشاهده/ویرایش برای مدیر)
		add_action( 'show_user_profile', array( $this, 'render_commission_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_commission_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_commission_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_commission_fields' ) );
	}

	/**
	 * وضعیت‌های سفارشی که با رسیدن به هرکدام، کمیسیون محاسبه و به کیف پول واریز می‌شود.
	 * پیش‌فرض: هم «در حال پردازش» و هم «تکمیل‌شده»، چون در بسیاری از کسب‌وکارهای
	 * خدماتی/رزرو (تور، کمپ، اقامتگاه) سفارش پس از پرداخت فقط به «در حال پردازش» می‌رود
	 * و هیچ‌وقت به‌صورت دستی «تکمیل‌شده» نمی‌شود.
	 * قابل تغییر از تنظیمات افزونه (پیشخوان → بازار فروشندگان → تنظیمات).
	 */
	public static function get_trigger_statuses() {
		$statuses = get_option( 'vm_commission_trigger_statuses', array( 'processing', 'completed' ) );
		if ( empty( $statuses ) || ! is_array( $statuses ) ) {
			$statuses = array( 'processing', 'completed' );
		}
		return apply_filters( 'vm_commission_trigger_statuses', $statuses );
	}

	/**
	 * نوع کمیسیون کاربر: percent | fixed
	 */
	public static function get_commission_type( $user_id ) {
		$type = get_user_meta( $user_id, 'vm_commission_type', true );
		if ( ! $type ) {
			$type = get_option( 'vm_default_commission_type', 'percent' );
		}
		return $type;
	}

	/**
	 * مقدار کمیسیون کاربر (درصد یا مبلغ ثابت به ازای هر واحد فروش)
	 */
	public static function get_commission_value( $user_id ) {
		$value = get_user_meta( $user_id, 'vm_commission_value', true );
		if ( $value === '' || $value === false ) {
			$value = get_option( 'vm_default_commission_value', 10 );
		}
		return (float) $value;
	}

	/**
	 * محاسبه کمیسیون بر اساس مبلغ خط سفارش
	 */
	public static function calculate_commission( $user_id, $line_total ) {
		$type  = self::get_commission_type( $user_id );
		$value = self::get_commission_value( $user_id );

		if ( $type === 'fixed' ) {
			return $value;
		}

		// درصدی
		return round( ( $line_total * $value ) / 100, 2 );
	}

	/**
	 * پردازش سفارش هنگام رسیدن به وضعیت تکمیل‌شده و واریز کمیسیون به کیف پول هر فروشنده
	 */
	public function process_order_commissions( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// جلوگیری از پردازش دوباره
		if ( $order->get_meta( '_vm_commissions_processed' ) === 'yes' ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			// get_product_id() همیشه آیدی محصول اصلی (والد) را برمی‌گرداند؛ برخلاف
			// get_product()->get_id() که برای محصولات متغیر آیدی «تنوع» را برمی‌گرداند
			// و می‌تواند نویسنده متفاوتی داشته باشد.
			$product_id = $item->get_product_id();
			if ( ! $product_id ) continue;

			$vendor_id = (int) get_post_field( 'post_author', $product_id );
			if ( ! $vendor_id || ! VM_Roles::user_is_vendor( $vendor_id ) ) {
				continue; // محصول متعلق به فروشنده نیست (مثلاً محصول مدیر)
			}

			// جلوگیری از پردازش دوباره این آیتم خاص
			if ( wc_get_order_item_meta( $item_id, '_vm_commission_credited', true ) === 'yes' ) {
				continue;
			}

			$line_total = (float) $item->get_total();
			// کمیسیون = مبلغی که طبق نرخ تعیین‌شده برای فروشنده از این فروش کسر می‌شود
			$commission = self::calculate_commission( $vendor_id, $line_total );
			if ( $commission > $line_total ) {
				$commission = $line_total; // کمیسیون هرگز نباید از مبلغ فروش بیشتر شود
			}
			// واریزی خالص به کیف پول فروشنده = مبلغ فروش منهای کمیسیون کسرشده
			$payout = round( $line_total - $commission, 2 );

			if ( $payout > 0 ) {
				$desc = sprintf( 'درآمد سفارش #%d پس از کسر کمیسیون - «%s»', $order_id, $item->get_name() );
				VM_Wallet::credit( $vendor_id, $payout, $order_id, $item_id, $desc );
			}

			wc_update_order_item_meta( $item_id, '_vm_commission_credited', 'yes' );
			wc_update_order_item_meta( $item_id, '_vm_vendor_id', $vendor_id );
			wc_update_order_item_meta( $item_id, '_vm_commission_amount', $commission );
			wc_update_order_item_meta( $item_id, '_vm_payout_amount', $payout );
		}

		$order->update_meta_data( '_vm_commissions_processed', 'yes' );
		$order->save();
	}

	/**
	 * بازپردازش سفارشات تکمیل‌شده‌ای که به هر دلیلی (مثلاً باگ نسخه‌های قبلی افزونه)
	 * هیچ‌وقت کمیسیون‌شان محاسبه و به کیف پول فروشنده واریز نشده است.
	 * سفارشاتی که قبلاً با موفقیت پردازش شده‌اند (متا _vm_commissions_processed=yes) نادیده گرفته می‌شوند،
	 * پس این عملیات امن است و باعث واریز دوباره/تکراری نمی‌شود.
	 *
	 * @return int تعداد سفارشاتی که در این اجرا پردازش شدند
	 */
	public static function reprocess_missing_commissions() {
		$order_ids = wc_get_orders( array(
			'status' => self::get_trigger_statuses(),
			'limit'  => -1,
			'return' => 'ids',
		) );

		$instance = self::instance();
		$count = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) continue;
			if ( $order->get_meta( '_vm_commissions_processed' ) === 'yes' ) continue;

			$instance->process_order_commissions( $order_id );
			$count++;
		}

		return $count;
	}

	/**
	 * نمایش فیلد کمیسیون در صفحه ویرایش کاربر (فقط مدیر می‌بیند)
	 */
	public function render_commission_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( ! VM_Roles::user_is_vendor( $user->ID ) ) return;

		$type  = get_user_meta( $user->ID, 'vm_commission_type', true );
		$value = get_user_meta( $user->ID, 'vm_commission_value', true );
		?>
		<h2>تنظیمات کمیسیون فروشنده</h2>
		<table class="form-table">
			<tr>
				<th><label for="vm_commission_type">نوع کمیسیون</label></th>
				<td>
					<select name="vm_commission_type" id="vm_commission_type">
						<option value="percent" <?php selected( $type, 'percent' ); ?>>درصدی</option>
						<option value="fixed" <?php selected( $type, 'fixed' ); ?>>مبلغ ثابت (به ازای هر محصول)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="vm_commission_value">مقدار کمیسیون</label></th>
				<td>
					<input type="number" step="0.01" name="vm_commission_value" id="vm_commission_value" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
					<p class="description">اگر خالی بگذارید، مقدار پیش‌فرض سراسری از تنظیمات افزونه اعمال می‌شود.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_commission_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( isset( $_POST['vm_commission_type'] ) ) {
			update_user_meta( $user_id, 'vm_commission_type', sanitize_text_field( $_POST['vm_commission_type'] ) );
		}
		if ( isset( $_POST['vm_commission_value'] ) ) {
			update_user_meta( $user_id, 'vm_commission_value', floatval( $_POST['vm_commission_value'] ) );
		}
	}
}
