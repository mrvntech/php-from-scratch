# php.ini

`php.ini` là file cấu hình được nạp **khi PHP khởi động** (trước MINIT). Nó quyết định:
- Hành vi của engine: `memory_limit`, `max_execution_time`, `error_reporting`, `display_errors`.
- Extension nào được nạp: `extension=mbstring`, `zend_extension=opcache`.
- Cấu hình của từng extension: `opcache.*`, `session.save_path`, `date.timezone`...

```ini
; comment bắt đầu bằng dấu chấm phẩy
memory_limit = 256M
display_errors = On
error_reporting = E_ALL
date.timezone = Asia/Ho_Chi_Minh
extension = mbstring
```

## Mỗi SAPI một bộ config

```
/etc/php/8.5/
├── cli/        php.ini + conf.d/   ← lệnh `php`
├── apache2/    php.ini + conf.d/   ← mod_php
├── fpm/        php.ini + conf.d/   ← php-fpm (có sau khi cài php8.5-fpm)
└── mods-available/                 ← file .ini gốc của extension, phpenmod symlink vào conf.d/
```

Kết quả `php --ini` trên máy:
```
Configuration File (php.ini) Path: "/etc/php/8.5/cli"          ← nơi PHP tìm php.ini
Loaded Configuration File:         "/etc/php/8.5/cli/php.ini"  ← file chính được nạp
Scan for additional .ini files in: "/etc/php/8.5/cli/conf.d"   ← thư mục quét thêm
Additional .ini files parsed:      10-pdo.ini, 20-calendar.ini, ...
```

## Thứ tự nạp và ghi đè

```
1. php.ini chính               /etc/php/8.5/<sapi>/php.ini
2. conf.d/*.ini                theo bảng chữ cái: 10-pdo.ini → 20-*.ini → 99-study.ini
3. Config pool FPM             php_value[...] / php_admin_value[...]   (chỉ FPM)
4. .htaccess / .user.ini       theo thư mục                            (mod_php / FPM)
5. php -d key=value            (chỉ CLI)
6. ini_set() trong code
```
**Giá trị đặt sau ghi đè giá trị đặt trước.** Vì vậy file của mình đặt tên `99-study.ini`: nó được nạp cuối nên thắng các file khác, và không phải sửa `php.ini` gốc.

Trong pool FPM:
```ini
php_value[memory_limit] = 512M        ; code vẫn ghi đè được bằng ini_set()
php_admin_value[display_errors] = Off ; khoá lại, ini_set() không ghi đè được
```

## Mode: setting được đổi ở đâu

| Mode | Đổi được ở đâu | Ví dụ |
|---|---|---|
| `INI_SYSTEM` | Chỉ php.ini/conf.d, hoặc config pool FPM | `opcache.memory_consumption`, `extension` |
| `INI_PERDIR` | Thêm `.htaccess` (mod_php), `.user.ini` (FPM) | `upload_max_filesize`, `post_max_size` |
| `INI_USER` | Thêm `ini_set()` | `date.timezone`, `error_reporting` |
| `INI_ALL` | Mọi nơi | `memory_limit`, `display_errors` |

`ini_set()` với một setting `INI_SYSTEM` sẽ trả về `false`. Ví dụ, shared memory của OPcache đã được cấp từ lúc MINIT, nên không đổi được lúc chạy.

## Khi nào php.ini được đọc

Theo PHP Manual:

> The configuration file is read when PHP starts up. For the server module versions of PHP, this happens only once when the web server is started. For the CGI and CLI versions, it happens on every invocation.

"Server module version" là SAPI mà PHP được nạp **vào bên trong process web server** dưới dạng module, ví dụ `mod_php` (`apache2handler`):

```
systemctl start apache2
  └─ Apache parent process
       ├─ nạp mod_php (libphp8.5.so)
       ├─ đọc /etc/php/8.5/apache2/php.ini + conf.d/   ← CHỈ MỘT LẦN
       ├─ MINIT các extension
       └─ fork child process (prefork)
            child 1, 2, 3...  ← thừa hưởng config đã parse, KHÔNG đọc lại
                 └─ mỗi request: RINIT → script → RSHUTDOWN
```

| SAPI | PHP khởi động khi nào | php.ini được đọc | Sửa ini xong cần làm gì |
|---|---|---|---|
| `apache2handler` | Apache khởi động | 1 lần | Reload/restart **Apache** |
| `fpm-fcgi` | Master FPM khởi động | 1 lần (worker thừa hưởng) | `systemctl reload php8.5-fpm`, không cần đụng Nginx |
| `cgi` | Mỗi request một process | Mỗi request | Không cần gì |
| `cli` | Mỗi lần gõ `php` | Mỗi lần chạy | Không cần gì |

**Vì sao:** parse ini, nạp `.so` và chạy MINIT đều tốn thời gian. Làm một lần rồi dùng cho hàng nghìn request sẽ nhanh hơn nhiều. CGI làm lại ở mỗi request nên chậm, đó là lý do FastCGI ra đời.

## Bẫy hay gặp

- Sửa `cli/php.ini` mà mong web thay đổi: sửa nhầm SAPI.
- Sửa đúng file của SAPI web nhưng quên reload service.
- `.user.ini` được cache theo `user_ini.cache_ttl` (mặc định 300 giây): sửa xong có thể phải chờ tối đa 5 phút.
- Trên web, kiểm tra bằng một trang tạm `<?php phpinfo();`, xem dòng *Loaded Configuration File*, rồi **xoá ngay** sau khi xem.

## Lệnh hay dùng

```bash
php --ini                                        # file nào được nạp
php -i | grep -E 'memory_limit|display_errors'   # giá trị đang có hiệu lực
php -r 'var_dump(ini_get("memory_limit"));'
php -d display_errors=0 -i | grep display_errors # ghi đè tạm
php -n -i | grep memory_limit                    # -n: không nạp ini nào, xem giá trị mặc định
php -c /path/to/custom.ini script.php            # dùng php.ini khác
PHP_INI_SCAN_DIR=/tmp/myconf php --ini           # đổi thư mục conf.d
```

## Liên hệ Java

| PHP | Java |
|---|---|
| `php.ini` | JVM options + `application.properties` |
| `conf.d/*.ini` ghi đè theo thứ tự | `application.properties` → `application-{profile}.properties` → biến môi trường |
| `php -d key=value` | `java -Dkey=value`, `-Xmx512m` |
| `ini_set()` | `System.setProperty()` |
| `INI_SYSTEM` không đổi được lúc chạy | Heap size và GC chỉ đặt được lúc khởi động JVM |
| mod_php/FPM đọc ini một lần, sửa xong phải reload | Tomcat/Spring Boot đọc config lúc khởi động, sửa xong phải restart |
| CLI đọc ini mỗi lần chạy | Mỗi lần `java -jar` đọc config mới |

Xem thêm: [sapi.md](sapi.md).
