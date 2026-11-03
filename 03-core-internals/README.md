# 03 · Lỗi, autoload và bên trong engine

## Mục tiêu

Hiểu PHP chạy một request như thế nào: từ mã nguồn tới opcode, vòng đời request, bộ nhớ, và cách lỗi được xử lý.

## Chủ đề

- **Lỗi và exception:** `Throwable` → `Error` / `Exception`; `set_error_handler`, `set_exception_handler`, `register_shutdown_function`, `error_get_last`
- **Autoload:** `spl_autoload_register` tự viết, rồi ánh xạ PSR-4 bằng tay
- **Generator:** `yield`, `yield from`, `send()`, xử lý file lớn với bộ nhớ cố định
- **Closure:** `bind`, `bindTo`, `call`, `static fn`
- **Bộ nhớ:** zval, refcount, copy-on-write, vòng tham chiếu và `gc_collect_cycles()`
- **Vòng đời:** MINIT → RINIT → thực thi → RSHUTDOWN → MSHUTDOWN. Mô hình "share-nothing" giữa các request.
- **Biên dịch:** tokenizer → AST → opcode → OPcache (và JIT)

## Bài tập (viết vào `work/`)

1. **`errors.php`**: chuyển mọi warning/notice thành `ErrorException`, có handler cuối bắt fatal error qua shutdown function. In ra file, dòng, stack trace.
2. **`autoload/`**: tự viết autoloader PSR-4 cho namespace `App\` → `src/`, không dùng Composer. Thử trường hợp class không tồn tại.
3. **`big_file.php`**: tạo file CSV 1 triệu dòng, đọc bằng mảng và bằng generator, so sánh `memory_get_peak_usage()`.
4. **`cow.php`**: đo bộ nhớ trước và sau khi gán mảng lớn cho biến khác, rồi sửa một phần tử. Giải thích bằng copy-on-write.
5. **`cycles.php`**: tạo vòng tham chiếu giữa 2 object, `unset`, đo bộ nhớ trước và sau `gc_collect_cycles()`.
6. **`tokens.php`**: in kết quả `PhpToken::tokenize()` của một đoạn code nhỏ.
7. **`opcodes`**: xem opcode mà OPcache tạo ra:
   ```bash
   php -d opcache.enable_cli=1 -d opcache.opt_debug_level=0x10000 work/tokens.php 2>&1 | head -50
   ```
   Thử bật JIT (`opcache.jit=tracing`, `opcache.jit_buffer_size=64M`) và benchmark một vòng lặp tính toán.

## Đọc thêm

- PHP Internals Book: zval, hashtable, vòng đời: https://www.phpinternalsbook.com/
- Nếu đã build PHP debug ở phase 00, chạy bài 5 bằng bản debug để thấy cảnh báo rò rỉ bộ nhớ.

## Câu hỏi tự kiểm tra

- Vì sao biến global trong PHP-FPM không "rò" sang request sau, trong khi ở Swoole/RoadRunner thì có?
- Khi nào refcount không đủ và cần tới cycle collector?
- OPcache cache cái gì: mã nguồn, AST hay opcode? Khi nào cache bị vô hiệu?

## Checklist

- [ ] Error handler hoàn chỉnh
- [ ] Autoloader PSR-4 tự viết
- [ ] Bảng so sánh bộ nhớ (mảng và generator, copy-on-write, cycles)
- [ ] Đã xem opcode của ít nhất một file
