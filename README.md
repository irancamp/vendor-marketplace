# 🏪 Vendor Marketplace - WordPress Plugin

**افزونه چند فروشندگی سبک برای ووکامرس**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WordPress Compatibility](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net)

---

## 📋 توضیحات

افزونه **بازار فروشندگان** یک راه‌حل سبک و کارآمد برای ایجاد بازاری چندفروشندگی است:

- 👥 **نقش فروشنده**: دو نقش (تور/کمپ و اقامتگاه)
- 💰 **کیف پول و کمیسیون**: مدیریت خودکار درآمد و کسورات
- 📦 **مدیریت محصول**: ثبت محصول از جانب فروشنده
- 📊 **داشبورد فروشنده**: پنل کامل برای فروشندگان
- 🔌 **سازگاری**: JetEngine، Elementor Pro، WooCommerce

---

## 🚀 نصب و راه‌اندازی

### 1. نیازمندی‌ها
- WordPress 5.0+
- PHP 7.4+
- WooCommerce (فعال‌)
- Elementor Pro (اختیاری)
- JetEngine (اختیاری)

### 2. نصب
```bash
# دانلود و استخراج
cd wp-content/plugins
unzip vendor-marketplace.zip

# یا از طریق پیشخوان WordPress
# افزونه‌ها → افزودن → بارگذاری افزونه
```

### 3. فعال‌سازی
```
پیشخوان → افزونه‌ها → Vendor Marketplace → فعال کن
```

### 4. تنظیم فونت (اختیاری)
فایل‌های فونت IRANYekanX را در اینجا قرار دهید:
```
assets/fonts/
├── IRANYekanXFaNum-Regular.woff2
├── IRANYekanXFaNum-Medium.woff2
└── IRANYekanXFaNum-Bold.woff2
```

---

## ⚙️ تنظیمات

**پیشخوان** → **بازار فروشندگان** → **تنظیمات**

- 📊 نرخ کمیسیون پیش‌فرض
- 💵 حداقل مبلغ برداشت
- ✅ وضعیت محصول جدید
- 🔑 Meta Key شماره حساب

---

## 📝 شورت‌کدها

| شورت‌کد | توضیح |
|---------|-------|
| `[vm_wallet_balance]` | موجودی کیف پول |
| `[vm_commission_info]` | نرخ کمیسیون |
| `[vm_withdraw_request_form]` | فرم برداشت وجه |
| `[vm_withdraw_history]` | تاریخچه برداشت |
| `[vm_vendor_orders]` | سفارشات فروشنده |
| `[vm_orders_summary]` | خلاصه سفارشات |
| `[vm_vendor_dashboard]` | داشبورد کامل |

---

## 🔧 مدیریت

### نقش‌ها
```
پیشخوان → کاربران → ویرایش → نقش
```
- 👨‍🏢 فروشنده تور و کمپ
- 🏠 فروشنده اقامتگاه

### درخواست‌های برداشت
```
پیشخوان → بازار فروشندگان → درخواست‌های برداشت
```

### مدیریت محصولات
```
پیشخوان → بازار فروشندگان → محصولات فروشندگان
```

---

## 📚 منابع

- [توضیحات کامل فارسی](./README-fa.txt)
- [WooCommerce Documentation](https://docs.woocommerce.com)
- [JetEngine Docs](https://www.crocoblock.com/documentation/jetengine/)

---

## 📝 License

این افزونه تحت [MIT License](LICENSE) است.

---

## 👨‍💻 نویسنده

**Custom Build**

---

## 🐛 گزارش مشکلات

مشکل‌ها و پیشنهادات را در [Issues](https://github.com/irancamp/vendor-marketplace/issues) ثبت کنید.

---

## 📧 تماس

برای سؤالات و پشتیبانی:
- 📬 Email: support@example.com
- 💬 Issues: GitHub Issues

---

**نسخه**: 1.0.0  
**آپدیت آخر**: 2026-07-24
