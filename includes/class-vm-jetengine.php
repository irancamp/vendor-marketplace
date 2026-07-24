<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * اتصال به فرم‌های JetEngine برای افزودن محصول توسط فروشنده
 *
 * روش استفاده: در JetEngine یک «Post Form» بساز که Post Type آن را روی
 * «Product» (محصول ووکامرس) تنظیم کرده باشی و آن را در صفحه پنل فروشنده
 * (که با المنتور پرو ساخته‌ای) قرار بده. این کلاس تضمین می‌کند که:
 *  - نویسنده محصول ثبت‌شده، همان فروشنده لاگین‌کرده باشد (نه قابل جعل)
 *  - وضعیت پیش‌فرض محصولات جدید بر اساس تنظیمات افزونه اعمال شود
 *  - فقط کاربران دارای نقش فروشنده اجازه ثبت داشته باشند
 */
class VM_JetEngine {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// روش اصلی و مستقل از نسخه JetEngine: اصلاح نویسنده و وضعیت محصول
		// درست پیش از درج در دیتابیس (چه از فرم JetEngine، چه از هر مسیر فرانت‌اند دیگر)
		add_filter( 'wp_insert_post_data', array( $this, 'force_vendor_authorship' ), 20, 2 );

		// هوک اختصاصی JetEngine Forms برای اعمال تنظیمات تکمیلی بعد از ثبت
		// (در صورت وجود این هوک در نسخه نصب‌شده اجرا می‌شود، در غیر این‌صورت بی‌اثر است)
		add_action( 'jet-engine/forms/insert-post/after-insert', array( $this, 'after_jetengine_product_insert' ), 10, 2 );
		add_action( 'jet-engine/forms/after-send', array( $this, 'after_jetengine_generic_send' ), 10, 2 );

		// جلوگیری از ثبت محصول برای کاربرانی که نقش فروشنده ندارند (امنیت فرم فرانت‌اند)
		add_filter( 'jet-engine/forms/handler/before-send', array( $this, 'guard_non_vendor_submission' ), 10, 1 );
	}

	/**
	 * تعیین نویسنده محصول = کاربر فروشنده لاگین‌کرده
	 * این فیلتر مستقل از نسخه JetEngine کار می‌کند چون روی هسته وردپرس (wp_insert_post) است
	 */
	public function force_vendor_authorship( $data, $postarr ) {
		if ( ! is_user_logged_in() ) return $data;
		if ( 'product' !== ( $data['post_type'] ?? '' ) ) return $data;
		if ( is_admin() && ! wp_doing_ajax() ) return $data; // ویرایش دستی از پیشخوان توسط ادمین دست‌نخورده بماند

		$user_id = get_current_user_id();
		if ( ! VM_Roles::user_is_vendor( $user_id ) ) return $data;

		// نویسنده را همیشه برابر فروشنده لاگین‌کرده قرار بده (غیرقابل تغییر از فرم)
		$data['post_author'] = $user_id;

		// اگر محصول تازه در حال ایجاد است، وضعیت پیش‌فرض را از تنظیمات افزونه بگیر
		if ( empty( $postarr['ID'] ) ) {
			$default_status = get_option( 'vm_new_product_status', 'pending' );
			$data['post_status'] = $default_status;
		}

		return $data;
	}

	/**
	 * بعد از ثبت محصول از طریق فرم اختصاصی JetEngine (Post Form)
	 */
	public function after_jetengine_product_insert( $post_id, $handler = null ) {
		if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) return;
		$this->finalize_vendor_product( $post_id );
	}

	/**
	 * هوک عمومی‌تر JetEngine Forms برای اطمینان از پوشش نسخه‌های مختلف
	 */
	public function after_jetengine_generic_send( $request, $handler ) {
		$post_id = 0;

		if ( is_object( $handler ) && method_exists( $handler, 'get_post_id' ) ) {
			$post_id = $handler->get_post_id();
		} elseif ( is_array( $request ) && ! empty( $request['post_id'] ) ) {
			$post_id = absint( $request['post_id'] );
		}

		if ( $post_id && get_post_type( $post_id ) === 'product' ) {
			$this->finalize_vendor_product( $post_id );
		}
	}

	/**
	 * اعمال نهایی: اطمینان از مالکیت و ثبت متادیتای اولیه فروشگاهی محصول
	 */
	private function finalize_vendor_product( $post_id ) {
		$user_id = get_current_user_id();
		if ( ! VM_Roles::user_is_vendor( $user_id ) ) return;

		// تضمین دوباره نویسنده (لایه امنیتی دوم)
		if ( (int) get_post_field( 'post_author', $post_id ) !== (int) $user_id ) {
			wp_update_post( array( 'ID' => $post_id, 'post_author' => $user_id ) );
		}

		update_post_meta( $post_id, '_vm_vendor_id', $user_id );

		do_action( 'vm_vendor_product_created', $post_id, $user_id );
	}

	/**
	 * جلوگیری از سابمیت فرم‌های افزودن محصول توسط کاربرانی که نقش فروشنده ندارند
	 */
	public function guard_non_vendor_submission( $handler ) {
		if ( is_object( $handler ) && method_exists( $handler, 'get_form_id' ) ) {
			$form_id = $handler->get_form_id();
			$vendor_forms = get_option( 'vm_jetengine_form_ids', array() );

			if ( ! empty( $vendor_forms ) && in_array( $form_id, $vendor_forms, true ) ) {
				if ( ! is_user_logged_in() || ! VM_Roles::current_user_is_vendor() ) {
					if ( method_exists( $handler, 'add_error_message' ) ) {
						$handler->add_error_message( 'برای ثبت محصول باید با حساب فروشنده وارد شوید.' );
					}
				}
			}
		}
		return $handler;
	}
}
