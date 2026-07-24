<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * بارگذاری فایل‌های CSS/JS افزونه
 * برای جلوگیری از تداخل با المنتور پرو و JetEngine، تمام کلاس‌های CSS
 * با پیشوند اختصاصی vm- نام‌گذاری شده‌اند.
 *
 * نکته: قبلاً بارگذاری این فایل‌ها منوط به تشخیص خودکار وجود شورت‌کد در محتوای
 * صفحه بود، اما در صفحاتی که با قالب‌های سفارشی/پیج‌بیلدرهای خاص ساخته می‌شوند
 * (که شورت‌کد در post_content یا _elementor_data قابل تشخیص نیست) این روش
 * قابل‌اعتماد نبود و باعث می‌شد استایل اصلاً لود نشود (مثلاً آیکون‌های SVG بدون
 * محدودیت اندازه، بسیار بزرگ نمایش داده می‌شدند). به همین دلیل اکنون این دو
 * فایل سبک (حجم کم) به‌طور همیشگی در فرانت‌اند سایت بارگذاری می‌شوند.
 */
class VM_Assets {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
	}

	public function register_frontend_assets() {
		wp_enqueue_style(
			'vm-style',
			VM_PLUGIN_URL . 'assets/css/vm-style.css',
			array(),
			VM_VERSION
		);

		wp_enqueue_script(
			'vm-script',
			VM_PLUGIN_URL . 'assets/js/vm-script.js',
			array( 'jquery' ),
			VM_VERSION,
			true
		);

		wp_localize_script( 'vm-script', 'vmAjax', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'vm_nonce' ),
			'i18n'     => array(
				'confirm_withdraw' => 'آیا از ثبت این درخواست برداشت اطمینان دارید؟',
				'processing'       => 'در حال پردازش...',
			),
		) );
	}
}
