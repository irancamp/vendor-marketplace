<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ساخت جداول اختصاصی دیتابیس
 */
class VM_Install {

	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_transactions = $wpdb->prefix . 'vm_wallet_transactions';
		$table_withdrawals  = $wpdb->prefix . 'vm_withdrawal_requests';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// جدول تراکنش‌های کیف پول
		$sql1 = "CREATE TABLE {$table_transactions} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'credit',
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			order_id BIGINT(20) UNSIGNED DEFAULT NULL,
			order_item_id BIGINT(20) UNSIGNED DEFAULT NULL,
			description TEXT,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY order_id (order_id)
		) {$charset_collate};";
		dbDelta( $sql1 );

		// جدول درخواست‌های برداشت
		$sql2 = "CREATE TABLE {$table_withdrawals} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			account_number VARCHAR(191) DEFAULT '',
			account_owner VARCHAR(191) DEFAULT '',
			bank_name VARCHAR(191) DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			admin_note TEXT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql2 );
	}
}
