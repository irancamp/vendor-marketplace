<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * افزودن باکس «نویسنده / فروشنده» به صفحه ویرایش محصول
 * فقط مدیرکل (manage_options) این باکس را می‌بیند و می‌تواند نویسنده محصول
 * را از بین کاربران فروشنده و مدیران تغییر دهد.
 */
class VM_Product_Meta {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_vendor_field' ), 20, 1 );

		// ستون «فروشنده» در لیست محصولات پیشخوان برای دید سریع‌تر مدیرکل
		add_filter( 'manage_edit-product_columns', array( $this, 'add_vendor_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_vendor_column' ), 10, 2 );

		// امکان تغییر فروشنده از طریق «ویرایش سریع» (Quick Edit) محصول، دقیقاً مشابه صفحه ویرایش کامل
		add_action( 'woocommerce_product_quick_edit_end', array( $this, 'render_quick_edit_field' ) );
		add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_edit_field' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_quick_edit_script' ) );
	}

	/**
	 * ثبت باکس فروشنده / نویسنده محصول (فقط برای مدیرکل)
	 */
	public function register_meta_box() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		add_meta_box(
			'vm_product_vendor_box',
			'فروشنده محصول',
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'vm_save_vendor_field', 'vm_vendor_nonce' );

		$current_author = (int) $post->post_author;

		// لیست کاربران فروشنده + مدیران (برای امکان برگرداندن محصول به مالکیت خود مدیر)
		$users = get_users( array(
			'role__in' => array_merge( array_keys( VM_Roles::get_vendor_roles() ), array( 'administrator' ) ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );

		$current_user_obj = get_userdata( $current_author );
		?>
		<p>
			<label for="vm_vendor_select"><strong>نویسنده فعلی:</strong>
				<?php echo $current_user_obj ? esc_html( $current_user_obj->display_name ) : 'نامشخص'; ?>
			</label>
		</p>
		<p>
			<select name="vm_vendor_select" id="vm_vendor_select" style="width:100%;">
				<?php foreach ( $users as $u ) : ?>
					<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $current_author, $u->ID ); ?>>
						<?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">با تغییر این گزینه و به‌روزرسانی محصول، این محصول و کمیسیون‌های آینده‌اش به کاربر انتخاب‌شده منتقل می‌شود.</p>
		<?php
	}

	public function save_vendor_field( $post_id ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( ! isset( $_POST['vm_vendor_nonce'] ) || ! wp_verify_nonce( $_POST['vm_vendor_nonce'], 'vm_save_vendor_field' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( empty( $_POST['vm_vendor_select'] ) ) return;

		$new_author = absint( $_POST['vm_vendor_select'] );
		if ( $new_author && $new_author !== (int) get_post_field( 'post_author', $post_id ) ) {
			// جلوگیری از لوپ بی‌نهایت save_post با remove/add موقت هوک
			remove_action( 'save_post_product', array( $this, 'save_vendor_field' ), 20 );
			wp_update_post( array( 'ID' => $post_id, 'post_author' => $new_author ) );
			update_post_meta( $post_id, '_vm_vendor_id', $new_author );
			add_action( 'save_post_product', array( $this, 'save_vendor_field' ), 20, 1 );
		}
	}

	/**
	 * ستون فروشنده در جدول محصولات پیشخوان
	 */
	public function add_vendor_column( $columns ) {
		if ( ! current_user_can( 'manage_options' ) ) return $columns;

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'name' ) {
				$new['vm_vendor'] = 'فروشنده';
			}
		}
		return $new;
	}

	public function render_vendor_column( $column, $post_id ) {
		if ( $column !== 'vm_vendor' ) return;
		$author_id = (int) get_post_field( 'post_author', $post_id );
		$user = get_userdata( $author_id );

		// داده مخفی برای خواندن توسط اسکریپت ویرایش سریع (Quick Edit)
		echo '<div class="hidden" id="vm_vendor_inline_' . esc_attr( $post_id ) . '">' . esc_html( $author_id ) . '</div>';

		if ( $user && VM_Roles::user_is_vendor( $user->ID ) ) {
			echo '<span class="vm-chip vm-chip-blue" style="display:inline-block;">' . esc_html( $user->display_name ) . '</span>';
		} else {
			echo '<span style="color:#999;">مدیر سایت</span>';
		}
	}

	/* ---------------------------------------------------------------
	 * ویرایش سریع (Quick Edit) - نمایش و ذخیره فروشنده مشابه صفحه ویرایش کامل
	 * ------------------------------------------------------------- */

	/**
	 * رندر فیلد انتخاب فروشنده داخل فرم Quick Edit ووکامرس
	 */
	public function render_quick_edit_field() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$users = get_users( array(
			'role__in' => array_merge( array_keys( VM_Roles::get_vendor_roles() ), array( 'administrator' ) ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );
		?>
		<br class="clear" />
		<label class="alignleft">
			<span class="title">فروشنده محصول</span>
			<span class="input-text-wrap">
				<select class="select vm-quick-edit-vendor" name="vm_vendor_select">
					<?php foreach ( $users as $u ) : ?>
						<option value="<?php echo esc_attr( $u->ID ); ?>">
							<?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</span>
		</label>
		<?php
	}

	/**
	 * ذخیره فروشنده انتخاب‌شده هنگام ذخیره فرم Quick Edit
	 * (نانس امنیتی توسط خود ووکامرس پیش از فراخوانی این هوک بررسی می‌شود)
	 */
	public function save_quick_edit_field( $product ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_POST['vm_vendor_select'] ) || ! $product ) return;

		$post_id    = $product->get_id();
		$new_author = absint( $_POST['vm_vendor_select'] );

		if ( $new_author && $new_author !== (int) get_post_field( 'post_author', $post_id ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_author' => $new_author ) );
			update_post_meta( $post_id, '_vm_vendor_id', $new_author );
		}
	}

	/**
	 * بارگذاری اسکریپت کوچک برای پرکردن خودکار مقدار فعلی فروشنده هنگام باز شدن Quick Edit
	 * فقط در صفحه لیست محصولات پیشخوان لود می‌شود
	 */
	public function enqueue_quick_edit_script( $hook ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( $hook !== 'edit.php' || ( $_GET['post_type'] ?? '' ) !== 'product' ) return;

		wp_enqueue_script(
			'vm-quick-edit',
			VM_PLUGIN_URL . 'assets/js/vm-quick-edit.js',
			array( 'jquery', 'inline-edit-post' ),
			VM_VERSION,
			true
		);
	}
}
