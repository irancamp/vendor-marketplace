<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * پردازش درخواست‌های AJAX فرم‌های کیف پول
 * (این فرم‌ها مستقل از JetEngine ساخته شده‌اند تا با داده‌های سفارشی جدول کیف پول سازگار باشند
 *  و هیچ تداخلی با فرم‌های JetEngine یا ویجت‌های المنتور پرو ایجاد نکنند)
 */
class VM_Ajax {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_vm_request_withdrawal', array( $this, 'request_withdrawal' ) );
		add_action( 'wp_ajax_vm_vendor_complete_order', array( $this, 'vendor_complete_order' ) );
	}

	/**
	 * ارسال پاسخ موفق JSON، با پاک‌سازی هر خروجی اضافه‌ای (مثل هشدار/نوتیس PHP)
	 * که ممکن است قبل از این خط چاپ شده و پاسخ JSON را از دید مرورگر خراب کند
	 * و باعث خطای «ارتباط با سرور برقرار نشد» در سمت کاربر شود.
	 */
	private function send_success( $data ) {
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		wp_send_json_success( $data );
	}

	private function send_error( $data ) {
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		wp_send_json_error( $data );
	}

	private function check_vendor() {
		$allowed = is_user_logged_in() && ( VM_Roles::current_user_is_vendor() || current_user_can( 'manage_options' ) );
		if ( ! $allowed ) {
			$this->send_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
		}
		if ( ! check_ajax_referer( 'vm_nonce', 'nonce', false ) ) {
			$this->send_error( array( 'message' => 'نشست شما نامعتبر است، صفحه را رفرش کنید.' ) );
		}
	}

	/**
	 * درخواست برداشت وجه - برای هر فروشنده‌ای که موجودی قابل برداشت دارد فعال است.
	 * توجه: این افزونه دیگر فیلد اختصاصی شماره حساب ندارد (شماره حساب از طریق
	 * فرم JetEngine خود سایت روی متای کاربر ذخیره می‌شود و در VM_Wallet::get_bank_info
	 * خوانده می‌شود). اگر شماره حساب هنوز ثبت نشده باشد هم درخواست برداشت ثبت می‌شود؛
	 * مدیر هنگام تسویه می‌تواند شماره حساب فروشنده را از پروفایل او بررسی کند.
	 */
	public function request_withdrawal() {
		ob_start();
		$this->check_vendor();

		$user_id = get_current_user_id();
		$amount  = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;

		$bank = VM_Wallet::get_bank_info( $user_id );

		$result = VM_Wallet::request_withdrawal(
			$user_id,
			$amount,
			$bank['account_number'],
			'',
			''
		);

		if ( is_wp_error( $result ) ) {
			$this->send_error( array( 'message' => $result->get_error_message() ) );
		}

		$this->send_success( array(
			'message' => 'درخواست برداشت شما با موفقیت ثبت شد و پس از بررسی پرداخت می‌شود.',
			'balance' => number_format( VM_Wallet::get_withdrawable_balance( $user_id ) ),
		) );
	}

	/**
	 * تغییر وضعیت سفارش به «تکمیل‌شده» توسط خود فروشنده از پنل فروشنده
	 * (چون یک سفارش ووکامرس فقط یک وضعیت کلی دارد و بین چند فروشنده مشترک نیست،
	 *  این عملیات کل سفارش را تکمیل‌شده می‌کند - نه فقط سهم همین فروشنده)
	 */
	public function vendor_complete_order() {
		ob_start();
		$this->check_vendor();

		$user_id  = get_current_user_id();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			$this->send_error( array( 'message' => 'سفارش یافت نشد.' ) );
		}

		// بررسی امنیتی: این فروشنده باید واقعاً حداقل یک محصول در این سفارش داشته باشد
		$vendor_items = VM_Orders::get_vendor_items_for_order( $order, $user_id );
		if ( empty( $vendor_items ) && ! current_user_can( 'manage_options' ) ) {
			$this->send_error( array( 'message' => 'شما اجازه تغییر وضعیت این سفارش را ندارید.' ) );
		}

		if ( $order->get_status() === 'completed' ) {
			$this->send_success( array( 'message' => 'این سفارش قبلاً تکمیل شده است.' ) );
		}

		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
			$this->send_error( array( 'message' => 'این سفارش لغو یا ناموفق بوده و قابل تکمیل نیست.' ) );
		}

		$order->update_status( 'completed', 'سفارش توسط فروشنده از طریق پنل فروشنده تکمیل‌شده علامت‌گذاری شد.' );

		$this->send_success( array( 'message' => 'سفارش با موفقیت تکمیل شد و کمیسیون به کیف پول واریز شد.' ) );
	}
}
