# SAPI là gì

**SAPI** (Server Application Programming Interface) là lớp giao tiếp giữa **lõi PHP (Zend Engine)** và **môi trường chạy nó**. Lõi PHP không tự biết request đến từ terminal hay từ web server. SAPI nhận input từ bên ngoài, chuyển cho engine chạy, rồi đưa output về đúng chỗ.

## SAPI quyết định những gì

- **Input đến từ đâu**: `argv` của dòng lệnh, hay HTTP request (header, body, query string).
- **Output đi đâu**: `echo` ra stdout của terminal, hay thành HTTP response.
- **Superglobal** như `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` được điền thế nào.
- **Vòng đời process**: chạy một lần rồi thoát, hay sống lâu và xử lý nhiều request.
- **`header()`** có tác dụng thật hay bị bỏ qua (trên CLI nó không làm gì).

## Các SAPI phổ biến

| SAPI | Dùng khi nào | Đặc điểm |
|---|---|---|
| `cli` | `php script.php` | Không có HTTP. `max_execution_time = 0`. Có `$argv`, `STDIN`. |
| `cli-server` | `php -S localhost:8000` | Web server có sẵn để dev, xử lý một request mỗi lần. Không dùng production. |
| `fpm-fcgi` | PHP-FPM sau Nginx/Apache | Chuẩn production. Pool worker process nói chuyện với web server qua FastCGI. |
| `apache2handler` | `mod_php` | PHP nhúng thẳng vào process Apache. Kiểu cũ. |
| `cgi-fcgi` | CGI/FastCGI kiểu cũ | |
| `embed` | Nhúng PHP vào chương trình C | |

Xem SAPI đang dùng:
```php
echo PHP_SAPI;          // "cli", "cli-server", "fpm-fcgi"...
echo php_sapi_name();
```

## Điều cần nhớ

- **Mỗi SAPI có `php.ini` và `conf.d/` riêng**: `/etc/php/8.5/cli/`, `/etc/php/8.5/fpm/`... Đây là lý do hay gặp chuyện "CLI chạy được, lên web lỗi". Kiểm tra bằng `php --ini` hoặc `phpinfo()` trên web.
- **Share-nothing**: với FPM, mỗi request bắt đầu với trạng thái sạch. Biến và object mất khi request kết thúc. Muốn giữ state thì dùng session, DB, cache.
- **Extension** gắn vào vòng đời mà SAPI điều khiển: `MINIT` chạy một lần khi process khởi động, còn `RINIT`/`RSHUTDOWN` chạy ở mỗi request. Vì vậy OPcache (shared memory tạo ở MINIT) sống qua nhiều request, còn biến PHP thì không.

## SAPI được khởi tạo khi nào

SAPI **không phải object được tạo ở mỗi request**. Nó là **code C có sẵn trong binary** (`php` chứa SAPI cli, `php-fpm` chứa SAPI fpm-fcgi): một bảng callback `sapi_module_struct` (`ub_write`, `read_post`, `send_headers`...). Ở mỗi lần process khởi động, SAPI được **khởi tạo** (startup) cùng engine. Thứ được tạo mới ở mỗi request là **trạng thái của request**.

### Vòng đời theo từng SAPI
```
CLI: mỗi lệnh `php file.php` = 1 process mới
  sapi_startup → đọc php.ini → MINIT → [RINIT → chạy file → RSHUTDOWN] → MSHUTDOWN → exit
  └──────────────────────── tất cả lặp lại ở lần gõ lệnh tiếp theo ────────────────────────┘

FPM: master process khởi động 1 lần
  master: sapi_startup → đọc php.ini → MINIT → fork worker
  worker: (thừa hưởng qua fork, không khởi tạo lại)
          [RINIT → chạy file → RSHUTDOWN] → [RINIT → … → RSHUTDOWN] → … (mỗi request một lần)
  MSHUTDOWN: chỉ khi worker thoát hoặc FPM dừng
```

Chi tiết trong FPM:
```
php-fpm MASTER (khởi động / reload)
  ├─ sapi_startup()            ← đăng ký SAPI fpm-fcgi (bảng callback)
  ├─ đọc php.ini + conf.d
  ├─ php_module_startup()      ← khởi động Zend Engine + MINIT mọi extension
  │                               (OPcache tạo shared memory ở đây)
  ├─ đọc php-fpm.conf, mở listen socket cho từng pool
  └─ fork() worker
        ▼
WORKER (sau khi fork, 1 lần)
  ├─ thừa hưởng sẵn: SAPI, engine, extension đã MINIT, config đã parse (copy-on-write)
  ├─ đổi sang user của pool (www-data), áp dụng php_value / php_admin_value của pool
  └─ vòng lặp:
        accept() ← chờ Nginx
        ┌─ MỖI REQUEST ─────────────────────────────────────────────┐
        │ đọc FastCGI PARAMS → điền SG(request_info) (method, URI…) │
        │ php_request_startup()   ← RINIT, tạo $_SERVER/$_GET/...   │
        │ php_execute_script()    ← chạy SCRIPT_FILENAME            │
        │      (opcode lấy từ OPcache, không compile lại)           │
        │ php_request_shutdown()  ← flush output, RSHUTDOWN,        │
        │                            huỷ mọi biến/object của request│
        └───────────────────────────────────────────────────────────┘
        quay lại accept()
```

### Ba tầng với ba vòng đời
| Tầng | Là gì | Khởi tạo khi nào | Sống bao lâu |
|---|---|---|---|
| **SAPI** | Code adapter (bảng callback C) | Process khởi động (CLI: mỗi lệnh; FPM: master) | Suốt đời process |
| **Engine + extension** | Zend Engine, extension đã MINIT, config | Cùng lúc với SAPI. Worker FPM thừa hưởng từ master | Tới khi process thoát (FPM: `pm.max_requests`, reload) |
| **Request** | `SG(request_info)`, superglobal, biến, object, output buffer | `php_request_startup()` | Một request, bị huỷ ở `php_request_shutdown()` |

| | CLI | FPM |
|---|---|---|
| Khởi tạo SAPI + engine + MINIT | Mỗi lệnh | Một lần ở master |
| Số request trong vòng đời process | 1 (script chính là "request") | Nhiều (tới `pm.max_requests`) |
| RINIT / RSHUTDOWN | 1 lần | Mỗi request |
| Khởi tạo lại khi nào | Lần gõ lệnh sau | `systemctl reload php8.5-fpm` / restart |

### Trường hợp đặc biệt
- **`php -S` (cli-server)**: một process khởi động một lần, phục vụ nhiều request, mỗi request có RINIT/RSHUTDOWN riêng. Vận hành giống FPM nhưng chỉ có một process, xử lý tuần tự.
- **Worker FPM thoát và được fork lại** (`pm.max_requests`, bị kill, `pm = ondemand`): worker mới vẫn thừa hưởng từ master, **không** khởi tạo lại SAPI hay chạy lại MINIT.
- **Gọi đúng tên**: *master process* chỉ quản lý worker, không xử lý request.

Kiểm chứng ở phase 06 stage B: thêm `error_log(getmypid());` vào `index.php`, gọi nhiều request rồi xem log. PID lặp lại, nghĩa là cùng một worker phục vụ nhiều request mà không khởi động lại.

## Liên hệ Java

- SAPI có vai trò gần giống **Servlet container** (Tomcat): quyết định request vào và response ra thế nào.
- Khác về hướng interface: Servlet API là interface mà **app implement** (`doGet()`). SAPI là interface C mà **môi trường host implement** cho engine. Code PHP chỉ đọc superglobal và `echo`.
- Khác về vòng đời: **Tomcat** là một process sống lâu, mỗi request một thread, state được giữ giữa request. **PHP-FPM** là nhiều process, mỗi request bắt đầu lại từ đầu.
- Tomcat gộp được web server (Coyote) và container (Catalina) vào một process vì JVM khởi động nặng nên vốn phải sống lâu. PHP chạy theo từng request nên cần Nginx/Apache giữ socket rồi gọi PHP qua FastCGI. Đây là lựa chọn kiến trúc, không phải bên nào tiến bộ hơn. Production vẫn thường đặt Nginx trước Tomcat.
- Vòng đời khởi tạo: CLI giống mỗi lần `java Main` là một JVM mới. FPM giống Tomcat: Connector và container được tạo một lần khi khởi động, mỗi request chỉ có một cặp `Request`/`Response` (được `recycle()`). Khác ở chỗ PHP huỷ toàn bộ state của script cuối request, còn Tomcat giữ heap.
- FrankenPHP (worker mode), RoadRunner và Swoole cho PHP chạy sống lâu giống Java. Chúng nhanh hơn nhưng có thể rò state giữa request và bị memory leak.

Bảng đối chiếu đầy đủ và bài tập counter: xem mục "Góc nhìn cho dev Java" trong [README.md](../README.md).

Xem thêm: [php-ini.md](php-ini.md) (php.ini được nạp và ghi đè thế nào, đọc lúc nào theo từng SAPI), [php-cli-vs-java.md](php-cli-vs-java.md) (SAPI cli và Java launcher), [fpm-vs-servlet-container.md](../../06-web-server/work/fpm-vs-servlet-container.md) (process pool của FPM và thread pool của Tomcat).
