<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * پنل مدیریت افزونه در وردپرس
 */
class VM_Admin {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_vm_update_withdrawal', array( $this, 'handle_withdrawal_update' ) );
		add_action( 'admin_post_vm_update_vendor_commission', array( $this, 'handle_vendor_commission_update' ) );
		add_action( 'admin_post_vm_reprocess_commissions', array( $this, 'handle_reprocess_commissions' ) );
		add_action( 'admin_post_vm_manual_withdrawal', array( $this, 'handle_manual_withdrawal' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	public function admin_assets( $hook ) {
		if ( strpos( $hook, 'vm-' ) === false ) return;
		wp_enqueue_style( 'vm-admin-style', VM_PLUGIN_URL . 'assets/css/vm-style.css', array(), VM_VERSION );
	}

	public function register_menu() {
		add_menu_page(
			'بازار فروشندگان',
			'بازار فروشندگان',
			'manage_options',
			'vm-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-store',
			56
		);

		add_submenu_page( 'vm-settings', 'تنظیمات', 'تنظیمات', 'manage_options', 'vm-settings', array( $this, 'render_settings_page' ) );
		add_submenu_page( 'vm-settings', 'محصولات فروشندگان', 'محصولات فروشندگان', 'manage_options', 'vm-products', array( $this, 'render_products_page' ) );
		add_submenu_page( 'vm-settings', 'سفارشات فروشندگان', 'سفارشات فروشندگان', 'manage_options', 'vm-orders', array( $this, 'render_orders_page' ) );
		add_submenu_page( 'vm-settings', 'درخواست‌های برداشت', 'درخواست‌های برداشت', 'manage_options', 'vm-withdrawals', array( $this, 'render_withdrawals_page' ) );
		add_submenu_page( 'vm-settings', 'فروشندگان و کمیسیون', 'فروشندگان و کمیسیون', 'manage_options', 'vm-vendors', array( $this, 'render_vendors_page' ) );
	}

	public function register_settings() {
		register_setting( 'vm_settings_group', 'vm_default_commission_type' );
		register_setting( 'vm_settings_group', 'vm_default_commission_value' );
		register_setting( 'vm_settings_group', 'vm_min_withdrawal' );
		register_setting( 'vm_settings_group', 'vm_new_product_status' );
		register_setting( 'vm_settings_group', 'vm_jetengine_form_ids' );
		register_setting( 'vm_settings_group', 'vm_commission_trigger_statuses' );
		register_setting( 'vm_settings_group', 'vm_bank_account_meta_key' );
	}

	/* ---------------------------------------------------------------
	 * صفحه تنظیمات
	 * ------------------------------------------------------------- */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		?>
		<div class="wrap vm-admin-wrap">
			<h1>تنظیمات بازار فروشندگان</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'vm_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th>نوع کمیسیون پیش‌فرض</th>
						<td>
							<select name="vm_default_commission_type">
								<option value="percent" <?php selected( get_option( 'vm_default_commission_type', 'percent' ), 'percent' ); ?>>درصدی</option>
								<option value="fixed" <?php selected( get_option( 'vm_default_commission_type', 'percent' ), 'fixed' ); ?>>مبلغ ثابت</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>مقدار کمیسیون پیش‌فرض</th>
						<td>
							<input type="number" step="0.01" name="vm_default_commission_value" value="<?php echo esc_attr( get_option( 'vm_default_commission_value', 10 ) ); ?>" class="regular-text" />
							<p class="description">این مقدار برای فروشندگانی اعمال می‌شود که کمیسیون اختصاصی برایشان تعیین نشده باشد. (قابل تغییر برای هر کاربر از صفحه ویرایش کاربر)</p>
						</td>
					</tr>
					<tr>
						<th>حداقل مبلغ قابل برداشت (تومان)</th>
						<td><input type="number" name="vm_min_withdrawal" value="<?php echo esc_attr( get_option( 'vm_min_withdrawal', 0 ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>وضعیت پیش‌فرض محصول جدید فروشنده</th>
						<td>
							<select name="vm_new_product_status">
								<option value="pending" <?php selected( get_option( 'vm_new_product_status', 'pending' ), 'pending' ); ?>>در انتظار بررسی مدیر</option>
								<option value="publish" <?php selected( get_option( 'vm_new_product_status', 'pending' ), 'publish' ); ?>>انتشار مستقیم</option>
								<option value="draft" <?php selected( get_option( 'vm_new_product_status', 'pending' ), 'draft' ); ?>>پیش‌نویس</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>شناسه فرم(های) JetEngine محصول</th>
						<td>
							<input type="text" name="vm_jetengine_form_ids_raw" value="<?php echo esc_attr( implode( ',', (array) get_option( 'vm_jetengine_form_ids', array() ) ) ); ?>" class="regular-text" placeholder="مثلاً 12,18" onchange="document.querySelector('[name=vm_jetengine_form_ids]').value=JSON.stringify(this.value.split(',').map(s=>s.trim()).filter(Boolean))" />
							<input type="hidden" name="vm_jetengine_form_ids" value='<?php echo esc_attr( wp_json_encode( (array) get_option( 'vm_jetengine_form_ids', array() ) ) ); ?>' />
							<p class="description">شناسه عددی فرم(های) JetEngine که برای «افزودن محصول» توسط فروشنده استفاده می‌شود را با کاما جدا وارد کنید (اختیاری، برای بررسی امنیتی بیشتر).</p>
						</td>
					</tr>
					<tr>
						<th>کلید متای شماره حساب فروشنده</th>
						<td>
							<input type="text" name="vm_bank_account_meta_key" value="<?php echo esc_attr( get_option( 'vm_bank_account_meta_key', 'account_number' ) ); ?>" class="regular-text" placeholder="مثلاً account_number" />
							<p class="description">
								این افزونه دیگر فیلد اختصاصی برای شماره حساب ندارد؛ شماره حساب باید از طریق فرم/فیلد سفارشی خود شما
								(مثلاً یک فرم JetEngine که روی متای کاربر ذخیره می‌کند) گرفته شود. نام دقیق «کلید متا» (Meta Key) همان
								فیلد را اینجا وارد کنید تا هنگام تسویه، سیستم بتواند شماره حساب فروشنده را نمایش دهد. دکمه «درخواست
								برداشت» برای هر فروشنده‌ای که موجودی داشته باشد فعال است، صرف‌نظر از این‌که این فیلد پر شده باشد یا نه.
							</p>
						</td>
					</tr>
					<tr>
						<th>وضعیت سفارشی که کمیسیون را واریز کند</th>
						<td>
							<?php
							$selected_statuses = get_option( 'vm_commission_trigger_statuses', array( 'processing', 'completed' ) );
							if ( ! is_array( $selected_statuses ) ) $selected_statuses = array( 'processing', 'completed' );
							$all_statuses = wc_get_order_statuses(); // مثل wc-processing => در حال پردازش
							foreach ( $all_statuses as $status_key => $status_label ) :
								$slug = str_replace( 'wc-', '', $status_key );
							?>
								<label style="display:inline-block; margin-left:16px;">
									<input type="checkbox" name="vm_commission_trigger_statuses[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_statuses, true ) ); ?> />
									<?php echo esc_html( $status_label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">
								به محض رسیدن سفارش به هرکدام از این وضعیت‌ها، کمیسیون فروشنده محاسبه و به کیف پولش واریز می‌شود.
								پیشنهاد: هم «در حال پردازش» و هم «تکمیل‌شده» را انتخاب کنید، چون خیلی از سفارشات (به‌خصوص تور/اقامتگاه) هیچ‌وقت
								دستی به «تکمیل‌شده» تغییر نمی‌کنند. اگر سفارشی بعداً لغو/بازپرداخت شود، این افزونه به‌صورت خودکار مبلغ را
								از کیف پول فروشنده کسر نمی‌کند؛ در این موارد تسویه دستی از بخش «درخواست‌های برداشت» را بررسی کنید.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<hr />
			<h2>راهنمای اتصال به JetEngine</h2>
			<ol>
				<li>در JetEngine یک «Post Form» جدید بساز و Post Type را روی «Products» تنظیم کن.</li>
				<li>فیلدهای مورد نیاز محصول (نام، قیمت، تصویر، توضیحات، دسته‌بندی) را به فرم اضافه کن.</li>
				<li>فرم را در صفحه پنل فروشنده (ساخته‌شده با المنتور پرو) با ویجت JetEngine Form قرار بده.</li>
				<li>افزونه به‌صورت خودکار نویسنده محصول را برابر فروشنده لاگین‌کرده قرار می‌دهد و نیازی به تنظیم دستی نیست.</li>
			</ol>

			<hr />
			<h2>بازپردازش کمیسیون سفارشات قبلی</h2>
			<?php if ( isset( $_GET['vm_reprocessed'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( sprintf( '%d سفارش بازپردازش و کیف پول فروشندگان مربوطه به‌روزرسانی شد.', absint( $_GET['vm_reprocessed'] ) ) ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				اگر به هر دلیلی (مثلاً باگ در نسخه‌های قبلی افزونه) کمیسیون برخی سفارشات تکمیل‌شده هیچ‌وقت محاسبه و به کیف پول فروشنده واریز نشده،
				با کلیک روی دکمه زیر می‌توانید تمام سفارشات تکمیل‌شده را دوباره بررسی کنید. سفارشاتی که قبلاً درست پردازش شده‌اند دوباره واریز نمی‌گیرند
				(کاملاً امن است).
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('بازپردازش سفارشات قبلی انجام شود؟ این عملیات بسته به تعداد سفارشات ممکن است چند ثانیه طول بکشد.');">
				<?php wp_nonce_field( 'vm_reprocess_commissions_action' ); ?>
				<input type="hidden" name="action" value="vm_reprocess_commissions" />
				<?php submit_button( 'بازپردازش کمیسیون سفارشات قبلی', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * پردازش دکمه بازپردازش کمیسیون سفارشات قبلی
	 */
	public function handle_reprocess_commissions() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
		check_admin_referer( 'vm_reprocess_commissions_action' );

		$count = VM_Commission::reprocess_missing_commissions();

		wp_safe_redirect( admin_url( 'admin.php?page=vm-settings&vm_reprocessed=' . $count ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * صفحه درخواست‌های برداشت
	 * ------------------------------------------------------------- */
	public function render_withdrawals_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		global $wpdb;
		$table = VM_Wallet::withdrawals_table();
		$requests = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200" );

		$status_labels = array(
			'pending'  => 'در انتظار بررسی',
			'approved' => 'پرداخت‌شده',
			'rejected' => 'رد شده',
		);

		if ( isset( $_GET['vm_manual_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>برداشت دستی با موفقیت ثبت و از موجودی فروشنده کسر شد.</p></div>';
		}
		?>
		<div class="wrap vm-admin-wrap">
			<h1>درخواست‌های برداشت فروشندگان</h1>

			<h2>ثبت برداشت دستی برای فروشنده</h2>
			<p class="description">
				اگر خودتان (مثلاً به‌صورت نقدی یا کارت‌به‌کارت خارج از این سیستم) مبلغی را به فروشنده پرداخت کرده‌اید و می‌خواهید
				همان مقدار از موجودی کیف پول او کسر شود (بدون نیاز به این‌که خود فروشنده درخواست بدهد)، از این فرم استفاده کنید.
				این عملیات بلافاصله انجام می‌شود و نیازی به تأیید ندارد.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vm-manual-withdrawal-form">
				<?php wp_nonce_field( 'vm_manual_withdrawal_action' ); ?>
				<input type="hidden" name="action" value="vm_manual_withdrawal" />
				<table class="form-table">
					<tr>
						<th><label for="vm_manual_vendor">فروشنده</label></th>
						<td>
							<select name="user_id" id="vm_manual_vendor" onchange="document.getElementById('vm_manual_amount').setAttribute('max', this.selectedOptions[0].dataset.balance); document.getElementById('vm_manual_balance_hint').textContent = 'موجودی فعلی: ' + this.selectedOptions[0].dataset.balanceFormatted + ' تومان — شماره حساب: ' + (this.selectedOptions[0].dataset.account || 'ثبت نشده');">
								<?php
								$vendors = get_users( array( 'role__in' => array_keys( VM_Roles::get_vendor_roles() ), 'orderby' => 'display_name' ) );
								foreach ( $vendors as $v ) :
									$balance = VM_Wallet::get_balance( $v->ID );
									$bank    = VM_Wallet::get_bank_info( $v->ID );
								?>
									<option value="<?php echo esc_attr( $v->ID ); ?>" data-balance="<?php echo esc_attr( $balance ); ?>" data-balance-formatted="<?php echo esc_attr( number_format( $balance ) ); ?>" data-account="<?php echo esc_attr( $bank['account_number'] ); ?>">
										<?php echo esc_html( $v->display_name . ' — موجودی: ' . number_format( $balance ) . ' تومان' . ( $bank['account_number'] ? ' — حساب: ' . $bank['account_number'] : '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="vm_manual_balance_hint"></p>
						</td>
					</tr>
					<tr>
						<th><label for="vm_manual_amount">مبلغ (تومان)</label></th>
						<td>
							<input type="number" min="1" step="1000" name="amount" id="vm_manual_amount" required class="regular-text" />
							<button type="button" class="button" onclick="var s=document.getElementById('vm_manual_vendor'); document.getElementById('vm_manual_amount').value = s.selectedOptions[0].dataset.balance;">پرکردن با کل موجودی</button>
						</td>
					</tr>
					<tr>
						<th><label for="vm_manual_note">توضیح (اختیاری)</label></th>
						<td><input type="text" name="note" id="vm_manual_note" class="regular-text" placeholder="مثلاً واریز نقدی مورخ ..." /></td>
					</tr>
				</table>
				<?php submit_button( 'ثبت برداشت دستی و کسر از کیف پول', 'secondary' ); ?>
			</form>

			<hr />
			<h2>درخواست‌های برداشت ثبت‌شده توسط فروشندگان</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>فروشنده</th>
						<th>مبلغ</th>
						<th>شماره حساب</th>
						<th>بانک</th>
						<th>تاریخ</th>
						<th>وضعیت</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $requests ) ) : ?>
					<tr><td colspan="7">درخواستی ثبت نشده است.</td></tr>
				<?php else : foreach ( $requests as $r ) :
					$user = get_userdata( $r->user_id );
				?>
					<tr>
						<td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
						<td><?php echo esc_html( number_format( $r->amount ) ); ?> تومان</td>
						<td><?php echo esc_html( $r->account_number ); ?></td>
						<td><?php echo esc_html( $r->bank_name ); ?></td>
						<td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $r->created_at ) ) ); ?></td>
						<td><?php echo esc_html( $status_labels[ $r->status ] ?? $r->status ); ?></td>
						<td>
							<?php if ( $r->status === 'pending' ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'vm_withdrawal_action' ); ?>
									<input type="hidden" name="action" value="vm_update_withdrawal" />
									<input type="hidden" name="request_id" value="<?php echo esc_attr( $r->id ); ?>" />
									<button type="submit" name="new_status" value="approved" class="button button-primary">تأیید و پرداخت</button>
									<button type="submit" name="new_status" value="rejected" class="button">رد کردن</button>
								</form>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_withdrawal_update() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
		check_admin_referer( 'vm_withdrawal_action' );

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$new_status = isset( $_POST['new_status'] ) ? sanitize_text_field( $_POST['new_status'] ) : '';

		if ( $request_id && in_array( $new_status, array( 'approved', 'rejected' ), true ) ) {
			VM_Wallet::update_withdrawal_status( $request_id, $new_status );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=vm-withdrawals' ) );
		exit;
	}

	/**
	 * ثبت مستقیم یک برداشت دستی توسط مدیرکل (بدون نیاز به درخواست/تأیید جداگانه)
	 * برای مواردی که مدیر خودش خارج از سیستم به فروشنده پرداخت کرده و می‌خواهد
	 * همان مقدار از موجودی کیف پول کسر شود.
	 */
	public function handle_manual_withdrawal() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
		check_admin_referer( 'vm_manual_withdrawal_action' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$amount  = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
		$note    = isset( $_POST['note'] ) ? sanitize_text_field( $_POST['note'] ) : '';

		if ( $user_id && VM_Roles::user_is_vendor( $user_id ) && $amount > 0 ) {
			$balance = VM_Wallet::get_balance( $user_id );
			if ( $amount > $balance ) {
				$amount = $balance; // هرگز بیشتر از موجودی فعلی کسر نشود
			}
			if ( $amount > 0 ) {
				$description = 'برداشت دستی ثبت‌شده توسط مدیر' . ( $note ? ' - ' . $note : '' );
				VM_Wallet::debit( $user_id, $amount, $description );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=vm-withdrawals&vm_manual_done=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * صفحه لیست فروشندگان + ویرایش درون‌خطی درصد/مبلغ کمیسیون
	 * ------------------------------------------------------------- */
	public function render_vendors_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$vendor_roles = VM_Roles::get_vendor_roles();
		$vendors = get_users( array( 'role__in' => array_keys( $vendor_roles ), 'orderby' => 'display_name' ) );

		if ( isset( $_GET['vm_updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>کمیسیون فروشنده با موفقیت به‌روزرسانی شد.</p></div>';
		}
		?>
		<div class="wrap vm-admin-wrap">
			<h1>فروشندگان و کمیسیون</h1>
			<p class="description">درصد یا مبلغ ثابت کمیسیون هر فروشنده را مستقیماً از همین صفحه تغییر دهید. (این کمیسیون، مبلغی است که از هر فروش کسر می‌شود و باقی‌مانده به کیف پول فروشنده واریز می‌شود)</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>نام</th>
						<th>نوع فروشنده</th>
						<th>ایمیل</th>
						<th>تعداد محصولات</th>
						<th>موجودی کیف پول</th>
						<th>نوع کمیسیون</th>
						<th>مقدار کمیسیون</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $vendors ) ) : ?>
					<tr><td colspan="8">فروشنده‌ای ثبت نشده است.</td></tr>
				<?php else : foreach ( $vendors as $v ) :
					$product_count = count_user_posts( $v->ID, 'product' );
					$type  = VM_Commission::get_commission_type( $v->ID );
					$value = VM_Commission::get_commission_value( $v->ID );
					$role_slug = ! empty( $v->roles ) ? $v->roles[0] : '';
					$role_label = $vendor_roles[ $role_slug ] ?? 'فروشنده';
				?>
					<tr>
						<td><strong><?php echo esc_html( $v->display_name ); ?></strong></td>
						<td><span class="vm-chip vm-chip-blue"><?php echo esc_html( $role_label ); ?></span></td>
						<td><?php echo esc_html( $v->user_email ); ?></td>
						<td><?php echo esc_html( $product_count ); ?></td>
						<td><?php echo esc_html( number_format( VM_Wallet::get_balance( $v->ID ) ) ); ?> تومان</td>
						<td colspan="3">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vm-inline-commission-form" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
								<?php wp_nonce_field( 'vm_vendor_commission_action' ); ?>
								<input type="hidden" name="action" value="vm_update_vendor_commission" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $v->ID ); ?>" />

								<select name="commission_type">
									<option value="percent" <?php selected( $type, 'percent' ); ?>>درصدی</option>
									<option value="fixed" <?php selected( $type, 'fixed' ); ?>>مبلغ ثابت</option>
								</select>

								<input type="number" step="0.01" min="0" name="commission_value" value="<?php echo esc_attr( $value ); ?>" style="width:110px;" />

								<button type="submit" class="button button-primary">ذخیره</button>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&author=' . $v->ID ) ); ?>" class="button">محصولات</a>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * پردازش فرم ویرایش درون‌خطی کمیسیون فروشنده
	 */
	public function handle_vendor_commission_update() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
		check_admin_referer( 'vm_vendor_commission_action' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$type    = isset( $_POST['commission_type'] ) ? sanitize_text_field( $_POST['commission_type'] ) : 'percent';
		$value   = isset( $_POST['commission_value'] ) ? floatval( $_POST['commission_value'] ) : 0;

		if ( $user_id && VM_Roles::user_is_vendor( $user_id ) ) {
			update_user_meta( $user_id, 'vm_commission_type', in_array( $type, array( 'percent', 'fixed' ), true ) ? $type : 'percent' );
			update_user_meta( $user_id, 'vm_commission_value', $value );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=vm-vendors&vm_updated=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * صفحه لیست محصولات همه فروشندگان
	 * ------------------------------------------------------------- */
	public function render_products_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$vendors = get_users( array( 'role__in' => array_keys( VM_Roles::get_vendor_roles() ), 'fields' => 'ID' ) );

		if ( empty( $vendors ) ) {
			echo '<div class="wrap vm-admin-wrap"><h1>محصولات فروشندگان</h1><p>هنوز هیچ فروشنده‌ای در سایت ثبت نشده است.</p></div>';
			return;
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'author__in'     => $vendors,
			'posts_per_page' => 20,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$status_labels = array(
			'publish' => array( 'منتشرشده', 'vm-chip-green' ),
			'pending' => array( 'در انتظار بررسی', 'vm-chip-orange' ),
			'draft'   => array( 'پیش‌نویس', 'vm-chip-gray' ),
		);
		?>
		<div class="wrap vm-admin-wrap">
			<h1>محصولات فروشندگان</h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>تصویر</th>
						<th>نام محصول</th>
						<th>فروشنده</th>
						<th>قیمت</th>
						<th>وضعیت</th>
						<th>تاریخ</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $query->have_posts() ) : ?>
					<tr><td colspan="7">محصولی توسط فروشندگان ثبت نشده است.</td></tr>
				<?php else : while ( $query->have_posts() ) : $query->the_post();
					$product = wc_get_product( get_the_ID() );
					$author  = get_userdata( get_the_author_meta( 'ID' ) );
					$status  = get_post_status();
					$s = $status_labels[ $status ] ?? array( $status, 'vm-chip-gray' );
				?>
					<tr>
						<td><?php echo $product ? wp_kses_post( $product->get_image( array( 40, 40 ) ) ) : ''; ?></td>
						<td><strong><?php the_title(); ?></strong></td>
						<td><?php echo $author ? esc_html( $author->display_name ) : '—'; ?></td>
						<td><?php echo $product ? wp_kses_post( $product->get_price_html() ) : '—'; ?></td>
						<td><span class="vm-chip <?php echo esc_attr( $s[1] ); ?>"><?php echo esc_html( $s[0] ); ?></span></td>
						<td><?php echo esc_html( get_the_date( 'Y/m/d' ) ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link() ); ?>" class="button">ویرایش</a></td>
					</tr>
				<?php endwhile; wp_reset_postdata(); endif; ?>
				</tbody>
			</table>

			<?php
			$total_pages = $query->max_num_pages;
			if ( $total_pages > 1 ) {
				echo '<div style="margin-top:16px;">';
				echo paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
				) );
				echo '</div>';
			}
			?>
		</div>
		<?php
	}
	/* ---------------------------------------------------------------
	 * صفحه سفارشات همه فروشندگان (نمای کلی برای مدیرکل)
	 * ------------------------------------------------------------- */
	public function render_orders_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$paged = isset( $_GET['vm_page'] ) ? max( 1, absint( $_GET['vm_page'] ) ) : 1;
		$data  = VM_Orders::get_all_orders_overview( $paged, 20 );

		$status_labels = array(
			'completed'  => 'vm-chip-green',
			'processing' => 'vm-chip-blue',
			'pending'    => 'vm-chip-orange',
			'cancelled'  => 'vm-chip-red',
			'refunded'   => 'vm-chip-red',
			'on-hold'    => 'vm-chip-orange',
			'failed'     => 'vm-chip-red',
		);
		?>
		<div class="wrap vm-admin-wrap">
			<h1>سفارشات فروشندگان</h1>
			<p class="description">
				هر ردیف نشان‌دهنده سهم یک فروشنده از یک سفارش است. اگر یک سفارش شامل محصولات چند فروشنده باشد،
				برای هر فروشنده یک ردیف جداگانه با مبلغ فروش، کمیسیون کسرشده و واریزی خالص همان فروشنده نمایش داده می‌شود.
			</p>

			<?php if ( empty( $data['rows'] ) ) : ?>
				<p>هنوز سفارشی برای محصولات فروشندگان ثبت نشده است.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>سفارش</th>
							<th>فروشنده</th>
							<th>مشتری</th>
							<th>محصولات</th>
							<th>مبلغ فروش</th>
							<th>کمیسیون کسرشده</th>
							<th>واریزی به فروشنده</th>
							<th>وضعیت</th>
							<th>تاریخ</th>
							<th>عملیات</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $data['rows'] as $row ) :
						$chip_class = $status_labels[ $row['status_key'] ] ?? 'vm-chip-gray';
					?>
						<tr>
							<td>#<?php echo esc_html( $row['order_id'] ); ?></td>
							<td><?php echo esc_html( $row['vendor_name'] ); ?></td>
							<td><?php echo esc_html( $row['customer'] ); ?><?php if ( $row['phone'] ) : ?><br /><small><?php echo esc_html( $row['phone'] ); ?></small><?php endif; ?></td>
							<td class="vm-products-cell">
								<?php
								$item_count = count( $row['items'] );
								foreach ( array_slice( $row['items'], 0, 3 ) as $item ) : ?>
									<div class="vm-order-item"><?php echo esc_html( $item['name'] ); ?> × <?php echo esc_html( $item['quantity'] ); ?></div>
								<?php endforeach;
								if ( $item_count > 3 ) : ?>
									<span class="vm-products-more">و <?php echo esc_html( $item_count - 3 ); ?> مورد دیگر...</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format( $row['sale_total'] ) ); ?></td>
							<td><?php echo esc_html( number_format( $row['commission_total'] ) ); ?></td>
							<td><strong><?php echo esc_html( number_format( $row['payout_total'] ) ); ?></strong></td>
							<td><span class="vm-chip <?php echo esc_attr( $chip_class ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
							<td><?php echo esc_html( $row['date'] ); ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $row['order_id'] . '&action=edit' ) ); ?>" class="button">مشاهده سفارش</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $data['total_pages'] > 1 ) : ?>
					<div style="margin-top:16px;">
						<?php echo paginate_links( array(
							'base'    => add_query_arg( 'vm_page', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $data['total_pages'],
						) ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
