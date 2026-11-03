# 05 · HTTP bằng tay

## Mục tiêu

Hiểu HTTP ở mức byte trước khi giao việc đó cho web server: một request trông thế nào, ai parse nó, và PHP nhận dữ liệu qua đâu.

## Chủ đề

- Cấu trúc HTTP/1.1: request line, headers, body, `Content-Length`, `Connection`
- Quan sát bằng `curl -v`, `nc`
- Socket trong PHP: `stream_socket_server`, `stream_select`
- Built-in server `php -S` và router script
- Superglobals: `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `php://input`
- Gửi response: `header()`, `http_response_code()`, `headers_sent()`, output buffering `ob_*`

## Bài tập (viết vào `work/`)

1. **Quan sát:** `curl -v http://example.com` và tự gõ request bằng `nc`:
   ```bash
   printf 'GET / HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n' | nc example.com 80
   ```
   Nếu ở mạng công ty có proxy, xem lại note `no_proxy` trong repo `knowledge-cs`.
2. **`raw_server.php`**: HTTP server viết bằng PHP thuần.
   - Lắng nghe `tcp://127.0.0.1:8000`, parse request line và headers.
   - Trả về file tĩnh trong `public/`, đúng `Content-Type`, 404 khi không có file.
   - Chặn `../` (directory traversal).
   - Ghi access log giống định dạng của Nginx.
3. **`php -S`**: chạy `php -S 127.0.0.1:8001 -t public router.php`.
   - `router.php` trả `false` cho file tĩnh, còn lại đưa vào `index.php`.
   - `dump.php` in toàn bộ `$_SERVER`. So sánh với dữ liệu server ở bài 2 tự parse được.
4. **`forms.php`**: form GET, POST `application/x-www-form-urlencoded`, POST JSON (đọc `php://input`), upload file. In ra superglobal tương ứng.
5. **`headers.php`**: tạo lỗi "headers already sent" có chủ đích, rồi sửa bằng output buffering.

## Câu hỏi tự kiểm tra

- Ai điền `$_SERVER['REQUEST_URI']`, `SCRIPT_FILENAME`, `REMOTE_ADDR`: PHP hay web server?
- Vì sao `php -S` không dùng cho production?
- Vì sao `$_POST` rỗng khi gửi JSON?

## Checklist

- [ ] Gõ request HTTP bằng `nc` thành công
- [ ] `raw_server.php` phục vụ được file tĩnh và chặn traversal
- [ ] Router cho `php -S`
- [ ] Hiểu được nguồn của từng superglobal
