# LUFLY Catalog - حل المشاكل

## لو الصفحة فاضية أو 404:

### الخطوة 1: تأكد إن PHP شغال
افتح في المتصفح:
```
http://localhost/manage_products_lufly/phpinfo.php
```
- لو شفت صفحة PHP info → PHP شغال ✅
- لو 404 أو فاضية → PHP مش شغال على Apache

### الخطوة 2: تأكد إن Apache شغال
- افتح XAMPP Control Panel
- Apache لازم يكون أخضر (Running)
- لو مش أخضر، اضغط Start

### الخطوة 3: تأكد إن MySQL شغال
- افتح XAMPP Control Panel
- MySQL لازم يكون أخضر (Running)
- لو مش أخضر، اضغط Start

### الخطوة 4: تأكد إن PHP Extensions مفعلة
افتح `C:\xampp\php\php.ini` وابحث عن:
```ini
extension=pdo_mysql
extension=fileinfo
```
لو في `;` في أول السطر، امسحها عشان تفعّل الـ extension.

مثال:
```ini
;extension=pdo_mysql   ← دي مش مفعلة
extension=pdo_mysql    ← دي مفعلة
```

بعد التعديل، أعد تشغيل Apache.

### الخطوة 5: تأكد من AllowOverride
افتح `C:\xampp\apache\conf\httpd.conf` وابحث عن:
```apache
<Directory "C:/xampp/htdocs">
```
تأكد إن السطر تحته:
```apache
    AllowOverride All
```
**مش** `AllowOverride None`

---

## لو PHP info شغال بس الموقع مش شغال:

المشكلة في MySQL أو الـ database. افتح:
```
http://localhost/manage_products_lufly/test.php
```
ده هيقولك إيه المشكلة بالظبط.

---

## روابط سريعة:
- PHP Info: `http://localhost/manage_products_lufly/phpinfo.php`
- Test Page: `http://localhost/manage_products_lufly/test.php`
- الموقع الرئيسي: `http://localhost/manage_products_lufly/index.php`
