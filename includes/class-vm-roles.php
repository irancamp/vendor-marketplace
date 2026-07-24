<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * نقش‌های کاربری فروشنده
 * افزونه از چند نقش فروشنده با اختیارات کاملاً یکسان پشتیبانی می‌کند
 * (مثلاً برای دسته‌بندی فروشندگان بر اساس نوع کسب‌وکار: تور/کمپ، اقامتگاه و ...)
 * برای افزودن نقش جدید در آینده، کافیست آن را به آرایه get_vendor_roles() اضافه کنید.
 */
class VM_Roles {

	private static $instance = null;

	// نگه‌داشته‌شده برای سازگاری با نگارش‌های قبلی افزونه (نقش اول = فروشنده تور و کمپ)
	const ROLE      = 'vm_vendor';
	const ROLE_TOUR  = 'vm_vendor';
	const ROLE_STAY  = 'vm_vendor_stay';

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'editable_roles', array( $this, 'ensure_role_exists' ) );

		// فروشنده اصلاً نباید به پیشخوان وردپرس (wp-admin) دسترسی داشته باشد؛
		// همه‌ی کارها (افزودن محصول، مشاهده کیف پول و سفارشات) فقط از طریق
		// شورت‌کدهای فرانت‌اند و فرم‌های JetEngine انجام می‌شود.
		add_action( 'admin_init', array( $this, 'block_admin_dashboard_access' ), 1 );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_vendor' ) );

		add_action( 'pre_get_posts', array( $this, 'restrict_product_list_to_own' ) );
	}

	/**
	 * لیست همه نقش‌های فروشنده موجود در افزونه
	 */
	public static function get_vendor_roles() {
		return apply_filters( 'vm_vendor_roles', array(
			self::ROLE_TOUR => 'فروشنده تور و کمپ',
			self::ROLE_STAY => 'فروشنده اقامتگاه',
		) );
	}

	/**
	 * آیا کاربر مشخص‌شده (یا کاربر لاگین‌کرده فعلی) یکی از نقش‌های فروشنده را دارد؟
	 */
	public static function user_is_vendor( $user_id = null ) {
		if ( $user_id === null ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) return false;

		foreach ( array_keys( self::get_vendor_roles() ) as $role ) {
			if ( user_can( $user_id, $role ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * میانبر برای بررسی کاربر لاگین‌کرده فعلی
	 */
	public static function current_user_is_vendor() {
		return self::user_is_vendor( get_current_user_id() );
	}

	/**
	 * ریدایرکت کامل فروشنده به سمت صفحه اصلی سایت در صورت تلاش برای ورود به /wp-admin/
	 * (درخواست‌های admin-ajax.php هوک admin_init را اجرا نمی‌کنند، پس فرم‌های AJAX کیف پول
	 *  و فرم‌های فرانت‌اند JetEngine بدون مشکل کار می‌کنند)
	 */
	public function block_admin_dashboard_access() {
		if ( ! is_user_logged_in() ) return;
		if ( current_user_can( 'manage_options' ) ) return;
		if ( ! self::current_user_is_vendor() ) return;

		$redirect_url = apply_filters( 'vm_vendor_admin_redirect_url', home_url( '/' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * حذف نوار مدیریت وردپرس (Admin Bar) در فرانت‌اند برای فروشنده
	 */
	public function hide_admin_bar_for_vendor( $show ) {
		if ( is_user_logged_in() && self::current_user_is_vendor() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * تعریف نقش‌های فروشنده در صورت نبود آن‌ها (برای اطمینان بعد از آپدیت افزونه)
	 */
	public function ensure_role_exists( $roles ) {
		foreach ( array_keys( self::get_vendor_roles() ) as $role ) {
			if ( ! get_role( $role ) ) {
				self::add_vendor_role();
				break;
			}
		}
		return $roles;
	}

	/**
	 * ساخت همه نقش‌های فروشنده با سطح دسترسی کاملاً یکسان و محدود
	 * فقط به محصولات و آپلود فایل دسترسی دارند، نه به کل تنظیمات ووکامرس
	 */
	public static function add_vendor_role() {
		$caps = array(
			'read'                       => true,
			'upload_files'               => true,
			'edit_products'              => true,
			'edit_published_products'    => true,
			'publish_products'           => true,
			'delete_products'            => false,
			'delete_published_products'  => false,
			// عمداً edit_others_products اعطا نمی‌شود تا هر فروشنده فقط محصولات خودش را ببیند/ویرایش کند
		);

		foreach ( self::get_vendor_roles() as $role_slug => $role_label ) {
			remove_role( $role_slug );
			add_role( $role_slug, $role_label, $caps );
		}

		// اطمینان از این‌که ادمین همچنان همه‌ی این قابلیت‌ها را دارد
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'edit_products' );
			$admin->add_cap( 'edit_others_products' );
		}
	}

	/**
	 * فروشنده در پیشخوان وردپرس فقط محصولات خودش را ببیند
	 * (این تابع به‌عنوان لایه امنیتی دوم نگه داشته شده؛ چون فروشنده اصلاً
	 *  اجازه ورود به wp-admin را ندارد، در عمل هرگز اجرا نمی‌شود، اما اگر
	 *  دسترسی مستقیم به admin-ajax یا REST رخ دهد همچنان محافظت می‌کند)
	 */
	public function restrict_product_list_to_own( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( 'product' !== $query->get( 'post_type' ) ) return;
		if ( ! self::current_user_is_vendor() || current_user_can( 'manage_options' ) ) return;

		$query->set( 'author', get_current_user_id() );
	}
}
