# PHP CLI và Java launcher

Khi gõ `php file.php` là **đang dùng SAPI**: SAPI `cli`. Java cũng có một lớp host tương đương là **Java launcher** (lệnh `java`).

## Cấu trúc

```
php file.php                          java Main
┌──────────────────────────┐          ┌──────────────────────────────┐
│ binary /usr/bin/php8.5   │          │ Java launcher /usr/bin/java  │  ← chương trình C (libjli)
│  ┌────────────────────┐  │          │   │ JNI_CreateJavaVM()       │  ← JNI Invocation API
│  │ SAPI cli           │  │          │   ▼                          │
│  │  - argv, php.ini   │  │          │  ┌────────────────────────┐  │
│  │  - nối stdout      │  │          │  │ HotSpot JVM (libjvm.so)│  │
│  │      ▼             │  │          │  │  - class loading       │  │
│  │ Zend Engine        │  │          │  │  - bytecode verifier   │  │
│  └────────────────────┘  │          │  │  - interpreter + JIT   │  │
└──────────────────────────┘          │  │  - gọi main(String[])  │  │
                                      │  └────────────────────────┘  │
                                      └──────────────────────────────┘
```

- `php` = Zend Engine **liên kết sẵn với SAPI cli** trong một binary.
- `java` = **Java launcher**: chương trình C nhỏ (dùng `libjli`). Nó đọc các *command-line option*, tạo JVM qua **JNI Invocation API** (`JNI_CreateJavaVM`), nhờ **application class loader** nạp *main class*, rồi gọi *entry point* `main`.
- Launcher có vai trò giống SAPI cli. Cách nó nhúng JVM giống SAPI `embed` của PHP.

## Khác biệt cốt lõi: ai lo I/O

**Zend Engine không tự biết xuất dữ liệu ra đâu.** `echo`, `header()`, body POST và `$_SERVER` đều đi qua hàm do SAPI cung cấp:
```
echo "hi"  →  engine  →  sapi_module.ub_write("hi")
                           ├─ cli:            write(stdout)
                           ├─ fpm-fcgi:       FastCGI record gửi cho Nginx
                           └─ apache2handler: ghi vào response của Apache
```

**JVM tự có đủ I/O trong Java SE API** (`java.lang.System.out` là một `PrintStream`, `java.net.ServerSocket`, `java.nio.channels`...). Launcher chỉ khởi tạo JVM rồi đứng ngoài. Hệ quả:
- Java chỉ cần **một launcher**. Web server cũng chỉ là một ứng dụng Java chạy trên JVM, ví dụ **Tomcat**: *Coyote connector* lo HTTP, *Catalina servlet container* lo vòng đời servlet.
- PHP cần **nhiều SAPI**, vì phần nối với môi trường nằm ở tầng C, bên dưới engine.

```
Java:  Java launcher → JVM → Tomcat (Coyote + Catalina) → Jakarta Servlet API → ứng dụng (servlet/Spring)
PHP:   Nginx → FastCGI → php-fpm (SAPI, viết bằng C) → Zend Engine → script
```
Vì vậy Java không có khái niệm "SAPI web". Phần tương ứng là **servlet container**, được viết bằng Java.

## So sánh

| | `php file.php` | `java Main` |
|---|---|---|
| Lớp host | SAPI cli | Java launcher (`libjli`) |
| Engine | Zend Engine | JVM (bản phổ biến: HotSpot) |
| Biên dịch | Compile ra **opcode** ở mỗi lần chạy, không cần build | `javac` compile ra **bytecode** (`.class`) trước. **Source-file mode** (`java Main.java`, JEP 330, JDK 11+; nhiều file từ JDK 22, JEP 458) compile trong bộ nhớ rồi chạy |
| Thực thi | Zend VM thông dịch opcode (có JIT của OPcache nếu bật) | Bytecode được interpreter chạy, rồi **JIT compiler** (C1/C2, *tiered compilation*) biên dịch các *hot method* ra mã máy |
| Điểm vào | Chạy từ đầu file xuống | `public static void main(String[] args)`. Từ JDK 25 (JEP 512) cho phép *instance main method* `void main()` và *compact source file* |
| Tham số | `$argv`, `$argc` | `String[] args` |
| Stdin/stdout | Hằng `STDIN`, `STDOUT`, `STDERR` do SAPI cli tạo | `System.in`, `System.out`, `System.err` thuộc Java SE API |
| Cấu hình | `/etc/php/8.5/cli/php.ini`, `-d key=value` | *Standard option* (`-cp`, `-D` đặt *system property*), *extra option* `-X` (`-Xmx`), *advanced option* `-XX:`, biến môi trường `JAVA_TOOL_OPTIONS` |
| Đóng gói | Không bắt buộc (có PHAR) | JAR với `Main-Class` trong `MANIFEST.MF`, chạy bằng `java -jar app.jar` |
| Khởi động | Rất nhanh (vài chục ms) | Chậm hơn: khởi tạo JVM, class loading, JIT chưa *warm-up*. Có thể giảm bằng **CDS/AppCDS** (*Class Data Sharing*) |
| Cache code | OPcache **tắt** trên CLI (`opcache.enable_cli = Off`) | `.class` đã compile sẵn, CDS archive |

Máy hiện tại: PHP 8.5.4, OpenJDK 25.0.4.1.

## Mặc định riêng của SAPI cli

Chỉ PHP có thứ này: SAPI cli tự đặt vài giá trị cho hợp với terminal. Kiểm tra trên máy:
```bash
php -i | grep -E '^(max_execution_time|implicit_flush|html_errors|display_errors|output_buffering|register_argc_argv) '
```

| Directive | Giá trị trên CLI | Ghi chú |
|---|---|---|
| `max_execution_time` | `0` | Không giới hạn. Trên web mặc định là 30 giây, nên cùng một script có thể chạy được trên CLI nhưng timeout trên web |
| `implicit_flush` | `On` | Output được đẩy ra ngay |
| `html_errors` | `Off` | Thông báo lỗi dạng text, không phải HTML |
| `output_buffering` | `0` | |
| `register_argc_argv` | Hiển thị `Off` | Nhưng CLI **vẫn luôn** tạo `$argv`/`$argc` (đã thử `php -r 'var_dump($argv);' a b`) |
| `display_errors` | `Off` trên máy này | Mặc định của CLI là `STDOUT` (xem bằng `php -n -i`), nhưng `/etc/php/8.5/cli/php.ini` của Ubuntu (dòng 500) ghi đè thành `Off`. Bài tập 3 bật lại bằng `99-study.ini` |

Java không có khái niệm "mặc định theo môi trường": JVM chạy giống nhau, việc khác nhau là do *option* truyền vào launcher.

## Tóm lại

- Cả hai đều cần một lớp host để khởi động engine: PHP gọi là **SAPI**, Java gọi là **Java launcher**.
- SAPI của PHP lo cả I/O (output, header, request), nên mỗi môi trường mới cần một SAPI mới viết bằng C.
- Java launcher chỉ khởi tạo JVM. Mọi I/O, kể cả web server (servlet container), đều được viết bằng Java trên Java SE API.

Xem thêm: [sapi.md](sapi.md), [php-ini.md](php-ini.md).
