# HomaCRM — هما برای وردپرس

افزونهٔ رسمیِ **[HomaCRM](https://homacrm.com)** برای وردپرس و ووکامرس. یک افزونه، یک توکن، همهٔ امکاناتِ هما:

| بخش | چه می‌کند |
|---|---|
| اتصال | فقط با **توکنِ API** هما؛ نمایشِ پرتال و اعتبارِ پیامک |
| ورود با پیامک | ورود و ثبت‌نام با کدِ یک‌بارمصرف در wp-login، ووکامرس و `[homacrm_login]` (جایگزینِ Digits) |
| ووکامرس | پیامکِ هر وضعیتِ سفارش به مشتری، پیامکِ سفارشِ تازه به مدیران، سازگار با HPOS |
| CRM | خریدار و کاربرِ تازه ⇒ «مخاطب»؛ سفارش ⇒ «معامله» در قیفِ فروش |
| فرم‌ها | `[homacrm_form]` و Contact Form 7 ⇒ سرنخ یا تیکتِ پشتیبانی |
| هوش مصنوعی و توسعه | REST در `/wp-json/homacrm/v1`، توانایی‌های Abilities APIِ وردپرس (پایهٔ MCP)، هوک‌ها |

- **نسخه:** 1.0.0 · **به‌روزرسانی:** ۱۴۰۵/۰۷/۲۴ (2026-10-16)
- **پیش‌نیاز:** وردپرس 6.0+، PHP 7.4+؛ ووکامرس اختیاری.
- **مالکیت:** © HomaCRM — فناوران نگین شهر نوین. مجوز: GPLv2 یا بالاتر.

## نصب

۱ — آخرین نسخه را از [Releases](https://github.com/Homa-OS/homacrm-wordpress/releases) بگیرید (`homacrm.zip`) و در وردپرس از «افزونه‌ها › افزودن › بارگذاری» نصب کنید.
۲ — در پنلِ هما، «تنظیمات › توکنِ API» (مدیرِ اصلیِ پرتال) توکن بسازید.
۳ — در وردپرس «هما › اتصال» توکن را بچسبانید.
۴ — بخش‌های لازم را روشن کنید و از «ابزارها و گزارش» پیامکِ آزمایشی بفرستید.

**کوچ از افزونه‌های قدیمی:** پس از راه‌اندازی، «پیامکِ حرفه‌ای ووکامرس» (درگاهِ Homais/Negins) و Digits را غیرفعال کنید. کاربرانِ Digits (`digits_phone_no`) و ووکامرس (`billing_phone`) با همان موبایل وارد می‌شوند.

## کدهای کوتاه

| کد | توضیح |
|---|---|
| `[homacrm_login redirect="/my-account"]` | فرمِ ورود/ثبت‌نام با پیامک |
| `[homacrm_form title="درخواستِ مشاوره" button="ارسال"]` | فرمِ سرنخ/تیکت |

## متغیرهای پیامکِ سفارش

`{name}` `{order}` `{total}` `{status}` `{site}` `{mobile}` `{tracking}` (متای `_tracking_code`)

## برای توسعه‌دهنده

```php
// ارسالِ پیامک از هر جای سایت
do_action( 'homacrm_send_sms', '09121234567', 'سلام!', 'my-plugin' );
// یا
$res = HomaCRM\Sms::send( '09121234567', 'سلام!' ); // ['ok'=>bool,'error'=>string,'data'=>…]

// هر درخواستی به API بیرونیِ هما با توکنِ ذخیره‌شده
$r = HomaCRM\Api::request( 'GET', '/crm/contacts?search=0912' );
```

هوک‌ها: `homacrm_sms_message` (فیلترِ متن)، `homacrm_sms_sent`، `homacrm_user_registered`، `homacrm_login_redirect`، `homacrm_form_submitted`.

**REST** (فقط مدیرِ سایت، با Application Password):

```
GET  /wp-json/homacrm/v1/status
POST /wp-json/homacrm/v1/sms   {"mobile":"09…","message":"…"}
POST /wp-json/homacrm/v1/lead  {"firstName":"…","mobile":"09…","notes":"…"}
```

**MCP:** در وردپرس‌هایی که Abilities API دارند (افزونهٔ MCP Adapter وردپرس)، توانایی‌های `homacrm/send-sms`، `homacrm/credit` و `homacrm/create-lead` ثبت می‌شوند. برای دسترسیِ کامل به همهٔ ابزارهای هما (CRM، پیامک، مالی، کارتابل…) از سرورِ MCPِ هما (`homa-mcp-server`) با همین توکن (`HOMA_API_TOKEN`) استفاده کنید.

API کامل: https://homacrm.com/api/v1/external/docs

## امنیت

- توکن در گزینهٔ جدا و بدونِ autoload ذخیره می‌شود و هرگز در فرم، لاگ یا خروجی نمایش داده نمی‌شود.
- کدِ ورود فقط به‌صورتِ HMAC و کوتاه‌عمر در transient است؛ ۵ تلاشِ غلط کد را باطل می‌کند.
- هر موبایل هر ۶۰ ثانیه یک کد و حداکثر ۵ کد در ساعت؛ هر IP حداکثر ۱۵ درخواست در ساعت.
- لاگِ پیامک موبایل را پوشانده نشان می‌دهد، متنِ کدِ ورود را ذخیره نمی‌کند و پس از ۳۰ روز پاک می‌شود.

## ساختار

```
homacrm/
  homacrm.php            سرآمد و راه‌انداز
  includes/class-api.php         کلاینتِ API هما (توکن)
  includes/class-settings.php    پیشخوانِ «هما»
  includes/class-sms.php         ارسال + لاگ
  includes/class-login.php       ورود و ثبت‌نام با پیامک
  includes/class-woocommerce.php سفارش‌ها
  includes/class-crm-sync.php    مخاطبان
  includes/class-forms.php       فرم و CF7
  includes/class-abilities.php   REST و MCP
  includes/class-log.php         لاگ
```

## پشتیبانی

از پنلِ هما تیکت بزنید یا به https://homacrm.com سر بزنید.
