<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * شورت‌کدهای پنل فروشنده - همگی با ظاهر یکسان (کلاس‌های vm-*)
 * می‌توانند تکی داخل ویجت Shortcode المنتور یا در کنار JetEngine Listing استفاده شوند.
 */
class VM_Shortcodes {

	private static $instance = null;
	private static $detail_toggle_script_printed = false;
	private static $preview_bar_printed = false;
	private static $wallet_forms_script_printed = false;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'vm_wallet_balance', array( $this, 'wallet_balance' ) );
		add_shortcode( 'vm_withdraw_request_form', array( $this, 'withdraw_request_form' ) );
		add_shortcode( 'vm_withdraw_history', array( $this, 'withdraw_history' ) );
		add_shortcode( 'vm_vendor_orders', array( $this, 'vendor_orders' ) );
		add_shortcode( 'vm_orders_summary', array( $this, 'orders_summary' ) );
		add_shortcode( 'vm_commission_info', array( $this, 'commission_info' ) );
		add_shortcode( 'vm_vendor_dashboard', array( $this, 'vendor_dashboard' ) );
	}

	/**
	 * پیام یکسان برای کاربرانی که فروشنده نیستند یا لاگین نکرده‌اند
	 * مدیرکل (manage_options) همیشه به این بخش‌ها دسترسی دارد تا بتواند
	 * پنل فروشنده را بدون نیاز به ساخت حساب فروشنده جداگانه مشاهده/تست کند.
	 */
	private function guard() {
		if ( ! is_user_logged_in() ) {
			return '<div class="vm-wrap"><div class="vm-card vm-notice">برای مشاهده این بخش ابتدا وارد حساب کاربری خود شوید.</div></div>';
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( ! VM_Roles::current_user_is_vendor() ) {
			return '<div class="vm-wrap"><div class="vm-card vm-notice">این بخش مخصوص کاربران فروشنده است.</div></div>';
		}
		return true;
	}

	/**
	 * شناسه کاربری که باید داده‌هایش (کیف پول/سفارشات/کمیسیون) نمایش داده شود.
	 * برای خود فروشنده: همان کاربر لاگین‌کرده.
	 * برای مدیرکل: چون مدیرکل خودش فروشنده نیست و کیف پول/سفارشی ندارد، به‌جای نمایش
	 * صفر برای خودش، امکان «پیش‌نمایش» داده‌های یک فروشنده واقعی (انتخاب‌شده از URL یا
	 * به‌صورت پیش‌فرض اولین فروشنده سایت) به او داده می‌شود.
	 */
	private function get_display_user_id() {
		$current_id = get_current_user_id();

		if ( VM_Roles::user_is_vendor( $current_id ) ) {
			return $current_id;
		}

		if ( current_user_can( 'manage_options' ) ) {
			if ( isset( $_GET['vm_preview_vendor'] ) ) {
				$preview_id = absint( $_GET['vm_preview_vendor'] );
				if ( VM_Roles::user_is_vendor( $preview_id ) ) {
					return $preview_id;
				}
			}

			$vendors = get_users( array(
				'role__in' => array_keys( VM_Roles::get_vendor_roles() ),
				'fields'   => 'ID',
				'number'   => 1,
				'orderby'  => 'display_name',
			) );
			if ( ! empty( $vendors ) ) {
				return (int) $vendors[0];
			}
		}

		return $current_id;
	}

	/**
	 * نوار پیش‌نمایش که فقط برای مدیرکل نمایش داده می‌شود تا بداند دارد
	 * داده‌های کدام فروشنده را می‌بیند، و بتواند فروشنده دیگری انتخاب کند.
	 * چون خود مدیرکل هیچ کیف پول/سفارشی ندارد، دیدن صفر برای حساب خودش طبیعی و درست است؛
	 * این نوار دقیقاً همین موضوع را برای مدیرکل روشن می‌کند.
	 */
	private function render_admin_preview_bar() {
		if ( ! current_user_can( 'manage_options' ) || VM_Roles::current_user_is_vendor() ) {
			return '';
		}
		if ( self::$preview_bar_printed ) {
			return '';
		}
		self::$preview_bar_printed = true;

		$vendors = get_users( array(
			'role__in' => array_keys( VM_Roles::get_vendor_roles() ),
			'orderby'  => 'display_name',
		) );

		if ( empty( $vendors ) ) {
			return '<div class="vm-wrap"><div class="vm-notice vm-notice-warning">شما با حساب مدیرکل وارد شده‌اید و هنوز هیچ فروشنده‌ای در سایت ثبت نشده؛ به همین دلیل کیف پول/سفارشی برای نمایش وجود ندارد. یک کاربر با نقش فروشنده بسازید تا بتوانید پنل او را اینجا پیش‌نمایش کنید.</div></div>';
		}

		$selected = $this->get_display_user_id();

		ob_start();
		?>
		<div class="vm-wrap">
			<div class="vm-preview-bar">
				<span class="vm-preview-bar-label">شما با حساب مدیرکل وارد شده‌اید؛ کیف پول/سفارشات فروشنده زیر را پیش‌نمایش می‌کنید. (توجه: ثبت فرم‌ها در این حالت روی حساب خودتان اعمال می‌شود، نه فروشنده انتخاب‌شده)</span>
				<select class="vm-preview-select" onchange="var u=new URL(window.location.href); u.searchParams.set('vm_preview_vendor', this.value); window.location.href=u.toString();">
					<?php foreach ( $vendors as $v ) : ?>
						<option value="<?php echo esc_attr( $v->ID ); ?>" <?php selected( $selected, $v->ID ); ?>><?php echo esc_html( $v->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ==================================================================
	 * ۱) موجودی کیف پول
	 * ================================================================ */
	public function wallet_balance( $atts ) {
		$guard = $this->guard();
		if ( $guard !== true ) return $guard;

		$user_id = $this->get_display_user_id();
		$balance = VM_Wallet::get_balance( $user_id );
		$withdrawable = VM_Wallet::get_withdrawable_balance( $user_id );
		$pending = VM_Wallet::get_pending_withdrawals_sum( $user_id );

		ob_start();
		?>
		<div class="vm-wrap">
			<div class="vm-card vm-wallet-card">
				<div class="vm-wallet-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 7.5C3 6.11929 4.11929 5 5.5 5H18.5C19.8807 5 21 6.11929 21 7.5V16.5C21 17.8807 19.8807 19 18.5 19H5.5C4.11929 19 3 17.8807 3 16.5V7.5Z" stroke="currentColor" stroke-width="1.6"/><path d="M16 12.5C16 13.3284 16.6716 14 17.5 14C18.3284 14 19 13.3284 19 12.5C19 11.6716 18.3284 11 17.5 11C16.6716 11 16 11.6716 16 12.5Z" fill="currentColor"/><path d="M3 9H21" stroke="currentColor" stroke-width="1.6"/></svg>
				</div>
				<div class="vm-wallet-info">
					<span class="vm-wallet-label">موجودی کیف پول شما</span>
					<span class="vm-wallet-amount"><?php echo esc_html( number_format( $balance ) ); ?> <small>تومان</small></span>
					<div class="vm-wallet-sub">
						<span class="vm-chip vm-chip-green">قابل برداشت: <?php echo esc_html( number_format( $withdrawable ) ); ?></span>
						<?php if ( $pending > 0 ) : ?>
							<span class="vm-chip vm-chip-orange">در انتظار تسویه: <?php echo esc_html( number_format( $pending ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return $this->render_admin_preview_bar() . ob_get_clean();
	}

	/* ==================================================================
	 * ۲) نمایش درصد/مبلغ کمیسیون فروشنده
	 * ================================================================ */
	public function commission_info( $atts ) {
		$guard = $this->guard();
		if ( $guard !== true ) return $guard;

		$user_id = $this->get_display_user_id();
		$type  = VM_Commission::get_commission_type( $user_id );
		$value = VM_Commission::get_commission_value( $user_id );

		$label = ( $type === 'fixed' )
			? number_format( $value ) . ' تومان به ازای هر محصول فروخته‌شده کسر می‌شود'
			: number_format( $value ) . ' درصد از مبلغ هر فروش شما کسر می‌شود';

		ob_start();
		?>
		<div class="vm-wrap">
			<div class="vm-card vm-commission-card">
				<span class="vm-commission-label">نرخ کمیسیون فعلی شما</span>
				<span class="vm-commission-value"><?php echo esc_html( $label ); ?></span>
			</div>
		</div>
		<?php
		return $this->render_admin_preview_bar() . ob_get_clean();
	}

	/* ==================================================================
	 * ۳) فرم درخواست برداشت
	 * (شماره حساب دیگر توسط این افزونه گرفته نمی‌شود؛ از طریق فرم اختصاصی
	 *  JetEngine خود سایت که به متای کاربر وصل است تأمین می‌شود. دکمه برداشت
	 *  برای هر فروشنده‌ای که موجودی قابل‌برداشت داشته باشد فعال است.)
	 * ================================================================ */
	public function withdraw_request_form( $atts ) {
		$guard = $this->guard();
		if ( $guard !== true ) return $guard;

		$user_id = $this->get_display_user_id();
		$withdrawable = VM_Wallet::get_withdrawable_balance( $user_id );
		$min = (float) get_option( 'vm_min_withdrawal', 0 );

		ob_start();
		?>
		<div class="vm-wrap">
			<form class="vm-card vm-form" id="vm-withdraw-form" onsubmit="return vmSubmitWithdrawForm(this);">
				<h3 class="vm-card-title">درخواست برداشت وجه</h3>
				<p class="vm-form-desc">
					موجودی قابل برداشت شما: <strong><?php echo esc_html( number_format( $withdrawable ) ); ?> تومان</strong>
					<?php if ( $min > 0 ) : ?><br />حداقل مبلغ برداشت: <?php echo esc_html( number_format( $min ) ); ?> تومان<?php endif; ?>
				</p>

				<div class="vm-field">
					<label>مبلغ درخواستی (تومان)</label>
					<input type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $withdrawable ); ?>" step="1000" name="amount" required placeholder="مثلاً 500000" />
				</div>
				<button type="submit" class="vm-btn vm-btn-primary" <?php echo $withdrawable <= 0 ? 'disabled' : ''; ?>>
					<span class="vm-btn-text">ثبت درخواست برداشت</span>
				</button>
				<div class="vm-form-message"></div>
			</form>
		</div>
		<?php
		echo $this->render_wallet_forms_script();
		return $this->render_admin_preview_bar() . ob_get_clean();
	}

	/**
	 * جاوااسکریپت مستقل و تضمینی برای فرم درخواست برداشت
	 * - بدون وابستگی به jQuery یا فایل خارجی enqueue شده، تا در صورت هر مشکل بارگذاری
	 *   اسکریپت خارجی (کش، افزونه بهینه‌ساز و ...) باز هم به‌طور تضمینی کار کند.
	 * - در صورت بروز هر خطای غیرمنتظره (مثلاً پاسخ غیر-JSON به دلیل هشدار PHP)، دکمه
	 *   همیشه از حالت «در حال پردازش» خارج می‌شود و پیام خطا نمایش داده می‌شود.
	 */
	private function render_wallet_forms_script() {
		if ( self::$wallet_forms_script_printed ) {
			return '';
		}
		self::$wallet_forms_script_printed = true;

		ob_start();
		?>
		<script>
		window.vmWalletAjaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		window.vmWalletNonce = <?php echo wp_json_encode( wp_create_nonce( 'vm_nonce' ) ); ?>;

		function vmWalletRequest( form, params, onDone ) {
			var btn = form.querySelector( 'button[type="submit"]' );
			var btnText = btn ? btn.querySelector( '.vm-btn-text' ) : null;
			var msg = form.querySelector( '.vm-form-message' );
			var originalText = btnText ? btnText.textContent : '';

			if ( btn ) btn.disabled = true;
			if ( btnText ) btnText.textContent = 'در حال پردازش...';
			if ( msg ) { msg.textContent = ''; msg.className = 'vm-form-message'; }

			function finish( success, text ) {
				if ( btn ) btn.disabled = false;
				if ( btnText ) btnText.textContent = originalText;
				if ( msg ) {
					msg.textContent = text;
					msg.className = 'vm-form-message ' + ( success ? 'is-success' : 'is-error' );
				}
			}

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', window.vmWalletAjaxUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.timeout = 20000;
			xhr.onload = function () {
				var res = null;
				try { res = JSON.parse( xhr.responseText ); } catch ( e ) { res = null; }
				if ( res && res.success ) {
					finish( true, ( res.data && res.data.message ) ? res.data.message : 'با موفقیت انجام شد.' );
					if ( typeof onDone === 'function' ) onDone( res );
				} else {
					finish( false, ( res && res.data && res.data.message ) ? res.data.message : 'خطایی رخ داد. لطفاً دوباره تلاش کنید.' );
				}
			};
			xhr.onerror = function () { finish( false, 'ارتباط با سرور برقرار نشد. اتصال اینترنت خود را بررسی کنید.' ); };
			xhr.ontimeout = function () { finish( false, 'پاسخی از سرور دریافت نشد (Timeout). دوباره تلاش کنید.' ); };
			xhr.send( params );
		}

		window.vmSubmitWithdrawForm = function ( form ) {
			if ( ! window.confirm( 'آیا از ثبت این درخواست برداشت اطمینان دارید؟' ) ) return false;
			var amountField = form.querySelector( '[name="amount"]' );
			var params = 'action=vm_request_withdrawal'
				+ '&nonce=' + encodeURIComponent( window.vmWalletNonce )
				+ '&amount=' + encodeURIComponent( amountField ? amountField.value : '' );
			vmWalletRequest( form, params, function () {
				if ( amountField ) amountField.value = '';
			} );
			return false;
		};

		window.vmSwitchDashboardTab = function ( btn, tab ) {
			var tabs = btn.closest( '.vm-tabs' );
			if ( ! tabs ) return;
			var buttons = tabs.querySelectorAll( '.vm-tab-btn' );
			for ( var i = 0; i < buttons.length; i++ ) { buttons[ i ].classList.remove( 'is-active' ); }
			btn.classList.add( 'is-active' );
			var panels = tabs.querySelectorAll( '.vm-tab-panel' );
			for ( var j = 0; j < panels.length; j++ ) { panels[ j ].classList.remove( 'is-active' ); }
			var target = tabs.querySelector( '[data-tab-panel="' + tab + '"]' );
			if ( target ) target.classList.add( 'is-active' );
		};
		</script>
		<?php
		return ob_get_clean();
	}

	/* ==================================================================
	 * ۵) تاریخچه درخواست‌های برداشت
	 * ================================================================ */
	public function withdraw_history( $atts ) {
		$guard = $this->guard();
		if ( $guard !== true ) return $guard;

		$user_id = $this->get_display_user_id();
		$requests = VM_Wallet::get_withdrawal_requests( $user_id, 20 );

		$status_map = array(
			'pending'  => array( 'در انتظار بررسی', 'vm-chip-orange' ),
			'approved' => array( 'پرداخت‌شده', 'vm-chip-green' ),
			'rejected' => array( 'رد شده', 'vm-chip-red' ),
		);

		ob_start();
		?>
		<div class="vm-wrap">
			<div class="vm-card">
				<h3 class="vm-card-title">تاریخچه درخواست‌های برداشت</h3>
				<?php if ( empty( $requests ) ) : ?>
					<div class="vm-empty">هنوز درخواست برداشتی ثبت نکرده‌اید.</div>
				<?php else : ?>
					<div class="vm-table-wrap">
						<table class="vm-table">
							<thead>
								<tr>
									<th>تاریخ</th>
									<th>مبلغ (تومان)</th>
									<th>وضعیت</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $requests as $r ) :
								$s = $status_map[ $r->status ] ?? array( $r->status, 'vm-chip-gray' );
							?>
								<tr>
									<td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $r->created_at ) ) ); ?></td>
									<td><?php echo esc_html( number_format( $r->amount ) ); ?></td>
									<td><span class="vm-chip <?php echo esc_attr( $s[1] ); ?>"><?php echo esc_html( $s[0] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return $this->render_admin_preview_bar() . ob_get_clean();
	}

	/* ==================================================================
	 * ۶) سفارشات اختصاصی فروشنده
	 * ================================================================ */
	public function vendor_orders( $atts ) {
		$guard = $this->guard();
		if ( $guard !== true ) return $guard;

		$atts = shortcode_atts( array( 'per_page' => 10 ), $atts );
		$user_id  = $this->get_display_user_id();
		$per_page = (int) $atts['per_page'];

		$paged_active    = isset( $_GET['vm_page'] ) ? max( 1, absint( $_GET['vm_page'] ) ) : 1;
		$paged_cancelled = isset( $_GET['vm_page_c'] ) ? max( 1, absint( $_GET['vm_page_c'] ) ) : 1;

		$active_data    = VM_Orders::get_vendor_orders_data( $user_id, $paged_active, $per_page, 'active' );
		$cancelled_data = VM_Orders::get_vendor_orders_data( $user_id, $paged_cancelled, $per_page, 'cancelled' );
		$counts         = VM_Orders::get_vendor_order_counts( $user_id );
		$gross_earned   = VM_Wallet::get_gross_earned( $user_id );

		ob_start();
		?>
		<div class="vm-wrap">
			<div class="vm-card">
				<h3 class="vm-card-title">سفارشات محصولات من</h3>

				<?php echo $this->render_summary_boxes( $counts, $gross_earned ); ?>

				<div class="vm-tabs-nav" style="margin-top:18px;">
					<button type="button" class="vm-tab-btn is-active" onclick="vmSwitchOrderTab(this,'vm-orders-tab-active');">
						سفارشات موفق (<?php echo esc_html( $counts['active_count'] ); ?>)
					</button>
					<button type="button" class="vm-tab-btn" onclick="vmSwitchOrderTab(this,'vm-orders-tab-cancelled');">
						لغو شده (<?php echo esc_html( $counts['cancelled_count'] ); ?>)
					</button>
				</div>

				<div class="vm-tab-panel is-active" id="vm-orders-tab-active">
					<?php echo $this->render_orders_list( $active_data, 'vm_page' ); ?>
				</div>

				<div class="vm-tab-panel" id="vm-orders-tab-cancelled" style="display:none;">
					<?php echo $this->render_orders_list( $cancelled_data, 'vm_page_c' ); ?>
				</div>
			</div>
		</div>

		<?php
		// جاوااسکریپت‌های باز/بسته‌کردن جزئیات، سوییچ تب، و تکمیل سفارش به‌صورت مستقل و درون‌خطی
		// چاپ می‌شوند (نه فقط از طریق فایل جاوااسکریپت جداگانه) تا حتی اگر به هر دلیلی
		// (کش، بهینه‌سازهای جاوااسکریپت، بارگذاری تأخیری و ...) فایل خارجی اجرا نشود، تضمینی کار کنند.
		if ( ! self::$detail_toggle_script_printed ) :
			self::$detail_toggle_script_printed = true;
			?>
			<script>
			if ( typeof window.vmToggleOrderDetail !== 'function' ) {
				window.vmToggleOrderDetail = function ( btn ) {
					var targetId = btn.getAttribute( 'data-target' );
					var panel = document.getElementById( targetId );
					if ( ! panel ) return;
					var isHidden = ( panel.style.display === 'none' || panel.style.display === '' );
					panel.style.display = isHidden ? 'block' : 'none';
					btn.textContent = isHidden ? 'بستن جزئیات' : 'جزئیات سفارش';
				};
			}
			if ( typeof window.vmSwitchOrderTab !== 'function' ) {
				window.vmSwitchOrderTab = function ( btn, panelId ) {
					var nav = btn.parentElement;
					var wrap = nav.parentElement;
					var buttons = nav.querySelectorAll( '.vm-tab-btn' );
					for ( var i = 0; i < buttons.length; i++ ) { buttons[ i ].classList.remove( 'is-active' ); }
					btn.classList.add( 'is-active' );
					var panels = wrap.querySelectorAll( '.vm-tab-panel' );
					for ( var j = 0; j < panels.length; j++ ) { panels[ j ].style.display = 'none'; panels[ j ].classList.remove('is-active'); }
					var target = document.getElementById( panelId );
					if ( target ) { target.style.display = 'block'; target.classList.add('is-active'); }
				};
			}
			if ( typeof window.vmCompleteOrder !== 'function' ) {
				window.vmCompleteOrderAjaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				window.vmCompleteOrderNonce = <?php echo wp_json_encode( wp_create_nonce( 'vm_nonce' ) ); ?>;
				window.vmCompleteOrder = function ( btn, orderId ) {
					if ( ! window.confirm( 'با تکمیل این سفارش، وضعیت کل سفارش (شامل احتمالاً محصولات سایر فروشندگان در همین سفارش) به «تکمیل‌شده» تغییر می‌کند و کمیسیون شما محاسبه می‌شود. ادامه می‌دهید؟' ) ) return;
					btn.disabled = true;
					btn.textContent = 'در حال ثبت...';
					var xhr = new XMLHttpRequest();
					xhr.open( 'POST', window.vmCompleteOrderAjaxUrl, true );
					xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
					xhr.onload = function () {
						try {
							var res = JSON.parse( xhr.responseText );
							if ( res.success ) {
								alert( res.data.message );
								window.location.reload();
							} else {
								alert( res.data && res.data.message ? res.data.message : 'خطایی رخ داد.' );
								btn.disabled = false;
								btn.textContent = 'ثبت سفارش به‌عنوان تکمیل‌شده';
							}
						} catch ( e ) {
							alert( 'ارتباط با سرور برقرار نشد.' );
							btn.disabled = false;
							btn.textContent = 'ثبت سفارش به‌عنوان تکمیل‌شده';
						}
					};
					xhr.send( 'action=vm_vendor_complete_order&nonce=' + encodeURIComponent( window.vmCompleteOrderNonce ) + '&order_id=' + encodeURIComponent( orderId ) );
				};
			}
			</script>
			<?php
		endif;

		return $this->render_admin_preview_bar() . ob_get_clean();
	}

	/**
	 * شمارش‌های سریع فروشنده - قابل استفاده به‌صورت شورت‌کد جداگانه در هر جای صفحه
	 */
	public function orders_summary( $atts ) {
		$guard = $this->guard();
		if ( $guard !== true ) return $guard;

		$user_id      = $this->get_display_user_id();
		$counts       = VM_Orders::get_vendor_order_counts( $user_id );
		$gross_earned = VM_Wallet::get_gross_earned( $user_id );

		ob_start();
		?>
		<div class="vm-wrap">
			<div class="vm-card">
				<h3 class="vm-card-title">خلاصه سفارشات من</h3>
				<?php echo $this->render_summary_boxes( $counts, $gross_earned ); ?>
			</div>
		</div>
		<?php
		return $this->render_admin_preview_bar() . ob_get_clean();
	}

	/**
	 * سه باکس خلاصه (در حال انجام / موفق / جمع کل درآمد) با همان زبان طراحی وب‌سایت
	 * هم در شورت‌کد مستقل [vm_orders_summary] و هم در بالای [vm_vendor_orders] استفاده می‌شود
	 */
	private function render_summary_boxes( $counts, $gross_earned ) {
		ob_start();
		?>
		<div class="vm-summary-grid">
			<div class="vm-summary-box vm-summary-box-progress">
				<div class="vm-summary-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 7V12L15 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="1.8"/></svg>
				</div>
				<div class="vm-summary-info">
					<span class="vm-summary-label">سفارش‌های در حال انجام</span>
					<strong class="vm-summary-value"><?php echo esc_html( number_format( $counts['in_progress_count'] ) ); ?> سفارش</strong>
					<span class="vm-summary-sub">جمع: <?php echo esc_html( number_format( $counts['in_progress_sum'] ?? 0 ) ); ?> تومان</span>
				</div>
			</div>

			<div class="vm-summary-box vm-summary-box-success">
				<div class="vm-summary-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 13L9 17L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>
				<div class="vm-summary-info">
					<span class="vm-summary-label">سفارش موفق</span>
					<strong class="vm-summary-value"><?php echo esc_html( number_format( $counts['paid_count'] ) ); ?> سفارش</strong>
				</div>
			</div>

			<div class="vm-summary-box vm-summary-box-income">
				<div class="vm-summary-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 7.5C3 6.11929 4.11929 5 5.5 5H18.5C19.8807 5 21 6.11929 21 7.5V16.5C21 17.8807 19.8807 19 18.5 19H5.5C4.11929 19 3 17.8807 3 16.5V7.5Z" stroke="currentColor" stroke-width="1.6"/><path d="M16 12.5C16 13.3284 16.6716 14 17.5 14C18.3284 14 19 13.3284 19 12.5C19 11.6716 18.3284 11 17.5 11C16.6716 11 16 11.6716 16 12.5Z" fill="currentColor"/></svg>
				</div>
				<div class="vm-summary-info">
					<span class="vm-summary-label">جمع کل درآمد شما</span>
					<strong class="vm-summary-value"><?php echo esc_html( number_format( $gross_earned ) ); ?> تومان</strong>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * رندر لیست کارت‌های سفارش + صفحه‌بندی برای یک مجموعه داده (فعال یا لغوشده)
	 */
	private function render_orders_list( $data, $page_query_var ) {
		$status_labels = array(
			'completed'  => 'vm-chip-green',
			'processing' => 'vm-chip-blue',
			'pending'    => 'vm-chip-orange',
			'cancelled'  => 'vm-chip-red',
			'refunded'   => 'vm-chip-red',
			'on-hold'    => 'vm-chip-orange',
			'failed'     => 'vm-chip-red',
		);

		ob_start();

		if ( empty( $data['orders'] ) ) : ?>
			<div class="vm-empty">سفارشی در این بخش یافت نشد.</div>
		<?php else : ?>
			<div class="vm-order-cards">
				<?php foreach ( $data['orders'] as $order ) :
					$chip_class = $status_labels[ $order['status_key'] ] ?? 'vm-chip-gray';
					$detail_id  = 'vm-detail-' . $order['order_id'];
					$item_count = count( $order['items'] );
					$is_success_display = ! $order['is_cancelled'];
					$card_class = 'vm-order-card' . ( $is_success_display ? ' is-success' : ' is-cancelled' );
				?>
					<div class="<?php echo esc_attr( $card_class ); ?>">
						<div class="vm-order-card-head">
							<div class="vm-order-card-head-right">
								<span class="vm-order-number">
									سفارش #<?php echo esc_html( $order['order_id'] ); ?>
									<?php if ( $is_success_display ) : ?>
										<span class="vm-paid-badge" title="پرداخت موفق">
											<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 10.5L8.5 14L15 6.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
											پرداخت موفق
										</span>
									<?php endif; ?>
								</span>
								<span class="vm-order-date"><?php echo esc_html( $order['date'] ); ?></span>
							</div>
							<span class="vm-chip <?php echo esc_attr( $chip_class ); ?>"><?php echo esc_html( $order['status'] ); ?></span>
						</div>

						<div class="vm-order-card-customer">مشتری: <strong><?php echo esc_html( $order['customer'] ?: '—' ); ?></strong></div>

						<div class="vm-order-products-list">
							<?php foreach ( array_slice( $order['items'], 0, 3 ) as $item ) : ?>
								<div class="vm-product-row">
									<div class="vm-product-row-name"><?php echo esc_html( $item['name'] ); ?></div>
									<div class="vm-product-row-meta">تعداد: <?php echo esc_html( $item['quantity'] ); ?> &nbsp;·&nbsp; قیمت واحد: <?php echo esc_html( number_format( $item['unit_price'] ) ); ?> تومان</div>
								</div>
							<?php endforeach; ?>
							<?php if ( $item_count > 3 ) : ?>
								<span class="vm-products-more">و <?php echo esc_html( $item_count - 3 ); ?> محصول دیگر...</span>
							<?php endif; ?>
						</div>

						<div class="vm-order-stats">
							<div class="vm-order-stat">
								<span>مبلغ فروش</span>
								<strong><?php echo esc_html( number_format( $order['sale_total'] ) ); ?></strong>
							</div>
							<div class="vm-order-stat">
								<span>کمیسیون کسرشده</span>
								<strong><?php echo esc_html( number_format( $order['commission_total'] ) ); ?></strong>
							</div>
							<div class="vm-order-stat vm-order-stat-highlight">
								<span>واریزی به حساب</span>
								<strong><?php echo esc_html( number_format( $order['payout_total'] ) ); ?></strong>
							</div>
						</div>

						<div class="vm-order-card-actions">
							<button type="button"
								class="vm-btn vm-btn-outline vm-btn-sm vm-order-detail-toggle"
								data-target="<?php echo esc_attr( $detail_id ); ?>"
								onclick="vmToggleOrderDetail(this);">
								جزئیات سفارش
							</button>

							<?php if ( $order['can_vendor_complete'] ) : ?>
								<button type="button"
									class="vm-btn vm-btn-primary vm-btn-sm"
									onclick="vmCompleteOrder(this, <?php echo (int) $order['order_id']; ?>);">
									ثبت سفارش به‌عنوان تکمیل‌شده
								</button>
							<?php endif; ?>
						</div>

						<div class="vm-detail-block" id="<?php echo esc_attr( $detail_id ); ?>" style="display:none;">
							<?php echo $this->render_order_detail_panel( $order ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $data['total_pages'] > 1 ) : ?>
				<div class="vm-pagination">
					<?php for ( $i = 1; $i <= $data['total_pages']; $i++ ) :
						$url = add_query_arg( $page_query_var, $i );
					?>
						<a href="<?php echo esc_url( $url ); ?>" class="vm-page-link <?php echo $i === $data['paged'] ? 'is-active' : ''; ?>"><?php echo esc_html( $i ); ?></a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		<?php endif;

		return ob_get_clean();
	}

	/**
	 * پنل جزئیات کامل یک سفارش (اطلاعات مشتری + ریز اقلام + مبالغ) - با همان ظاهر vm-*
	 * هم در شورت‌کد فروشنده و هم قابل استفاده مجدد در سایر بخش‌ها
	 */
	private function render_order_detail_panel( $order ) {
		$has_estimated = false;
		foreach ( $order['items'] as $item ) {
			if ( empty( $item['is_final'] ) ) { $has_estimated = true; break; }
		}

		ob_start();
		?>
		<div class="vm-order-detail-panel">
			<div class="vm-detail-grid">
				<div class="vm-detail-item"><span>مشتری</span><strong><?php echo esc_html( $order['customer'] ?: '—' ); ?></strong></div>
				<div class="vm-detail-item"><span>شماره تماس</span><strong><?php echo esc_html( $order['phone'] ?: '—' ); ?></strong></div>
				<?php if ( isset( $order['email'] ) ) : ?>
				<div class="vm-detail-item"><span>ایمیل</span><strong><?php echo esc_html( $order['email'] ?: '—' ); ?></strong></div>
				<?php endif; ?>
				<?php if ( isset( $order['payment_method'] ) ) : ?>
				<div class="vm-detail-item"><span>روش پرداخت</span><strong><?php echo esc_html( $order['payment_method'] ?: '—' ); ?></strong></div>
				<?php endif; ?>
				<?php if ( ! empty( $order['address'] ) ) : ?>
				<div class="vm-detail-item vm-detail-wide"><span>آدرس</span><strong><?php echo wp_kses_post( $order['address'] ); ?></strong></div>
				<?php endif; ?>
				<?php if ( ! empty( $order['customer_note'] ) ) : ?>
				<div class="vm-detail-item vm-detail-wide"><span>یادداشت مشتری</span><strong><?php echo esc_html( $order['customer_note'] ); ?></strong></div>
				<?php endif; ?>
			</div>

			<?php if ( $has_estimated ) : ?>
				<div class="vm-notice vm-notice-warning" style="margin-top:14px;">
					این سفارش هنوز به وضعیت نهایی نرسیده؛ مبالغ کمیسیون و واریزی نمایش‌داده‌شده «تخمینی» است و پس از تکمیل سفارش قطعی می‌شود.
				</div>
			<?php endif; ?>

			<div class="vm-table-wrap" style="margin-top:14px;">
				<table class="vm-table">
					<thead>
						<tr>
							<th>محصول</th>
							<th>تعداد</th>
							<th>قیمت واحد</th>
							<th>جمع</th>
							<th>کمیسیون کسرشده</th>
							<th>واریزی خالص</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $order['items'] as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><?php echo esc_html( $item['quantity'] ); ?></td>
							<td><?php echo esc_html( number_format( $item['unit_price'] ) ); ?></td>
							<td><?php echo esc_html( number_format( $item['total'] ) ); ?></td>
							<td><?php echo esc_html( number_format( $item['commission'] ) ); ?></td>
							<td><strong><?php echo esc_html( number_format( $item['payout'] ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="3"><strong>جمع کل</strong></td>
							<td><strong><?php echo esc_html( number_format( $order['sale_total'] ) ); ?></strong></td>
							<td><strong><?php echo esc_html( number_format( $order['commission_total'] ) ); ?></strong></td>
							<td><strong><?php echo esc_html( number_format( $order['payout_total'] ) ); ?></strong></td>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ==================================================================
	 * ۷) داشبورد ترکیبی (اختیاری) - همه بخش‌ها در یک صفحه با تب
	 * ================================================================ */
	public function vendor_dashboard( $atts ) {
		$guard = $this->guard();
		if ( $guard !== true ) return $guard;

		ob_start();
		?>
		<div class="vm-wrap">
			<div class="vm-dashboard">
				<div class="vm-dashboard-top">
					<?php echo $this->wallet_balance( array() ); ?>
					<?php echo $this->commission_info( array() ); ?>
				</div>

				<div class="vm-tabs" id="vm-tabs">
					<div class="vm-tabs-nav">
						<button type="button" class="vm-tab-btn is-active" data-tab="orders" onclick="vmSwitchDashboardTab(this,'orders');">سفارشات من</button>
						<button type="button" class="vm-tab-btn" data-tab="withdraw" onclick="vmSwitchDashboardTab(this,'withdraw');">درخواست برداشت</button>
						<button type="button" class="vm-tab-btn" data-tab="history" onclick="vmSwitchDashboardTab(this,'history');">تاریخچه برداشت</button>
					</div>

					<div class="vm-tab-panel is-active" data-tab-panel="orders">
						<?php echo $this->vendor_orders( array() ); ?>
					</div>
					<div class="vm-tab-panel" data-tab-panel="withdraw">
						<?php echo $this->withdraw_request_form( array() ); ?>
					</div>
					<div class="vm-tab-panel" data-tab-panel="history">
						<?php echo $this->withdraw_history( array() ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
