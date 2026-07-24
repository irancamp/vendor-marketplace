<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * مدیریت کیف پول فروشندگان
 */
class VM_Wallet {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// این کلاس عمدتاً به صورت API استفاده می‌شود، هوک خاصی در سازنده لازم نیست
	}

	public static function transactions_table() {
		global $wpdb;
		return $wpdb->prefix . 'vm_wallet_transactions';
	}

	public static function withdrawals_table() {
		global $wpdb;
		return $wpdb->prefix . 'vm_withdrawal_requests';
	}

	/**
	 * افزودن تراکنش اعتباری (واریز کمیسیون فروش) به کیف پول
	 */
	public static function credit( $user_id, $amount, $order_id = null, $order_item_id = null, $description = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::transactions_table(),
			array(
				'user_id'       => $user_id,
				'type'          => 'credit',
				'amount'        => $amount,
				'order_id'      => $order_id,
				'order_item_id' => $order_item_id,
				'description'   => $description,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%d', '%d', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	/**
	 * کسر از کیف پول (برداشت تأییدشده)
	 */
	public static function debit( $user_id, $amount, $description = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::transactions_table(),
			array(
				'user_id'     => $user_id,
				'type'        => 'debit',
				'amount'      => $amount,
				'description' => $description,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	/**
	 * محاسبه موجودی فعلی کیف پول کاربر
	 * موجودی = مجموع واریزها - مجموع برداشت‌ها
	 */
	public static function get_balance( $user_id ) {
		global $wpdb;
		$table = self::transactions_table();

		$credit = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(amount) FROM {$table} WHERE user_id = %d AND type = 'credit'",
			$user_id
		) );
		$debit = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(amount) FROM {$table} WHERE user_id = %d AND type = 'debit'",
			$user_id
		) );

		$balance = (float) $credit - (float) $debit;
		return $balance;
	}

	/**
	 * جمع کل درآمد فروشنده از ابتدا تا الان، بدون کسر مبالغ برداشت‌شده
	 * (برای نمایش «جمع کل فروش من» به‌عنوان یک عدد انگیزشی/کاربرپسند،
	 *  جدا از موجودی فعلی کیف پول که برداشت‌ها از آن کسر شده‌اند)
	 */
	public static function get_gross_earned( $user_id ) {
		global $wpdb;
		$table = self::transactions_table();
		$sum = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(amount) FROM {$table} WHERE user_id = %d AND type = 'credit'",
			$user_id
		) );
		return (float) $sum;
	}

	/**
	 * موجودی در حال انتظار برداشت (درخواست‌های pending) - برای جلوگیری از برداشت مضاعف
	 */
	public static function get_pending_withdrawals_sum( $user_id ) {
		global $wpdb;
		$table = self::withdrawals_table();
		$sum = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(amount) FROM {$table} WHERE user_id = %d AND status = 'pending'",
			$user_id
		) );
		return (float) $sum;
	}

	/**
	 * موجودی قابل برداشت = موجودی کل - مجموع درخواست‌های در انتظار
	 */
	public static function get_withdrawable_balance( $user_id ) {
		$balance = self::get_balance( $user_id );
		$pending = self::get_pending_withdrawals_sum( $user_id );
		$result  = $balance - $pending;
		return $result > 0 ? $result : 0;
	}

	/**
	 * لیست تراکنش‌های یک کاربر
	 */
	public static function get_transactions( $user_id, $limit = 20 ) {
		global $wpdb;
		$table = self::transactions_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
			$user_id, $limit
		) );
	}

	/**
	 * ثبت درخواست برداشت جدید
	 */
	public static function request_withdrawal( $user_id, $amount, $account_number, $account_owner = '', $bank_name = '' ) {
		global $wpdb;

		$withdrawable = self::get_withdrawable_balance( $user_id );
		if ( $amount <= 0 || $amount > $withdrawable ) {
			return new WP_Error( 'vm_insufficient_balance', 'مبلغ درخواستی بیشتر از موجودی قابل برداشت است.' );
		}

		$min = (float) get_option( 'vm_min_withdrawal', 0 );
		if ( $min > 0 && $amount < $min ) {
			return new WP_Error( 'vm_min_withdrawal', sprintf( 'حداقل مبلغ برداشت %s است.', number_format( $min ) ) );
		}

		$wpdb->insert(
			self::withdrawals_table(),
			array(
				'user_id'        => $user_id,
				'amount'         => $amount,
				'account_number' => sanitize_text_field( $account_number ),
				'account_owner'  => sanitize_text_field( $account_owner ),
				'bank_name'      => sanitize_text_field( $bank_name ),
				'status'         => 'pending',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * لیست درخواست‌های برداشت یک کاربر
	 */
	public static function get_withdrawal_requests( $user_id, $limit = 20 ) {
		global $wpdb;
		$table = self::withdrawals_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
			$user_id, $limit
		) );
	}

	/**
	 * تغییر وضعیت درخواست برداشت (استفاده در پنل مدیریت)
	 */
	public static function update_withdrawal_status( $request_id, $status, $admin_note = '' ) {
		global $wpdb;
		$table = self::withdrawals_table();

		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $request_id ) );
		if ( ! $request ) {
			return new WP_Error( 'vm_not_found', 'درخواست یافت نشد.' );
		}

		$wpdb->update(
			$table,
			array(
				'status'     => $status,
				'admin_note' => $admin_note,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $request_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		// اگر تأیید شد، از کیف پول کسر شود
		if ( $status === 'approved' && $request->status !== 'approved' ) {
			self::debit( $request->user_id, $request->amount, sprintf( 'برداشت تأییدشده - درخواست #%d', $request_id ) );
		}

		return true;
	}

	/* --------------------------------------------------------------
	 * اطلاعات حساب بانکی فروشنده
	 * توجه: این افزونه دیگر فرم/فیلد اختصاصی برای شماره حساب ندارد؛ شماره حساب
	 * از طریق فرم JetEngine خود سایت که به متای کاربر وصل است خوانده می‌شود.
	 * کلید متا از تنظیمات افزونه قابل تغییر است (پیشخوان → بازار فروشندگان → تنظیمات).
	 * ------------------------------------------------------------ */
	public static function get_bank_info( $user_id ) {
		$meta_key = get_option( 'vm_bank_account_meta_key', 'account_number' );
		return array(
			'account_number' => get_user_meta( $user_id, $meta_key, true ),
		);
	}
}
