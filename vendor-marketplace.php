<?php
/**
 * Plugin Name: بازار فروشندگان (Vendor Marketplace)
 * Plugin URI: #
 * Description: افزونه چند فروشندگی سبک برای ووکامرس، سازگار با JetEngine و Elementor Pro. امکانات: نقش فروشنده، ثبت محصول از طریق فرم‌های JetEngine، کمیسیون اختصاصی هر فروشنده، کیف پول با درخواست برداشت، و نمایش سفارشات اختصاصی فروشنده.
 * Version: 1.0.0
 * Author: Custom Build
 * Text Domain: vendor-marketplace
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // دسترسی مستقیم مجاز نیست
}

/* ------------------------------------------------------------------------
 * بافر خروجی زودهنگام برای AJAX های این افزونه
 * ---------------------------------------------------------------------- */
// اگر درخواست فعلی یکی از اکشن‌های AJAX همین افزونه است، از همین ابتدای بارگذاری
// فایل (پیش از init شدن سایر افزونه‌ها/قالب) بافر خروجی را باز می‌کنیم. این کار
// باعث می‌شود هر هشدار/نوتیس PHP - چه از خود این افزونه، چه از افزونه‌ها یا قالب
// دیگر سایت - که ممکن است پیش از ارسال پاسخ نهایی JSON چاپ شود، در بافر گرفته و
// درست پیش از ارسال پاسخ دور ریخته شود؛ در نتیجه پاسخ AJAX هرگز به‌خاطر یک هشدار
// نامرتبط خراب نمی‌شود (این خرابی معمولاً باعث خطای «ارتباط با سرور برقرار نشد» در سمت کاربر می‌شود).
if ( ! empty( $_REQUEST['action'] ) && in_array(
	$_REQUEST['action'],
	array( 'vm_request_withdrawal', 'vm_vendor_complete_order' ),
	true
) ) {
	ob_start();
}

/* ------------------------------------------------------------------------
 * ثابت‌های افزونه
 * ---------------------------------------------------------------------- */
define( 'VM_VERSION', '1.0.0' );
define( 'VM_PLUGIN_FILE', __FILE__ );
define( 'VM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* ------------------------------------------------------------------------
 * بررسی پیش‌نیازها (ووکامرس)
 * ---------------------------------------------------------------------- */
function vm_check_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo 'افزونه «بازار فروشندگان» نیاز به فعال بودن ووکامرس دارد.';
			echo '</p></div>';
		} );
		return false;
	}
	return true;
}

/* ------------------------------------------------------------------------
 * بارگذاری فایل‌های کلاس
 * ---------------------------------------------------------------------- */
function vm_load_includes() {
	$includes = array(
		'includes/class-vm-install.php',
		'includes/class-vm-roles.php',
		'includes/class-vm-wallet.php',
		'includes/class-vm-commission.php',
		'includes/class-vm-jetengine.php',
		'includes/class-vm-product-meta.php',
		'includes/class-vm-orders.php',
		'includes/class-vm-shortcodes.php',
		'includes/class-vm-admin.php',
		'includes/class-vm-assets.php',
		'includes/class-vm-ajax.php',
	);

	foreach ( $includes as $file ) {
		$path = VM_PLUGIN_DIR . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

/* ------------------------------------------------------------------------
 * راه‌اندازی افزونه
 * ---------------------------------------------------------------------- */
function vm_init_plugin() {
	if ( ! vm_check_dependencies() ) {
		return;
	}

	vm_load_includes();

	// راه‌اندازی کلاس‌های اصلی
	VM_Roles::instance();
	VM_Wallet::instance();
	VM_Commission::instance();
	VM_JetEngine::instance();
	VM_Product_Meta::instance();
	VM_Orders::instance();
	VM_Shortcodes::instance();
	VM_Assets::instance();
	VM_Ajax::instance();

	if ( is_admin() ) {
		VM_Admin::instance();
	}

	load_plugin_textdomain( 'vendor-marketplace', false, dirname( VM_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'vm_init_plugin', 20 );

/* ------------------------------------------------------------------------
 * فعال‌سازی / غیرفعال‌سازی
 * ---------------------------------------------------------------------- */
function vm_activate_plugin() {
	require_once VM_PLUGIN_DIR . 'includes/class-vm-install.php';
	require_once VM_PLUGIN_DIR . 'includes/class-vm-roles.php';

	VM_Install::create_tables();
	VM_Roles::add_vendor_role();

	update_option( 'vm_db_version', VM_VERSION );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'vm_activate_plugin' );

function vm_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'vm_deactivate_plugin' );
