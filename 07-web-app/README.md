# 07 · Web app không framework

## Mục tiêu

Tự viết các thành phần mà framework thường làm sẵn để hiểu chúng hoạt động thế nào. Chạy trên stack Nginx + FPM của phase 06.

## Thành phần cần tự viết

```
work/todo/
├── public/index.php      # front controller: điểm vào duy nhất
├── src/
│   ├── Http/Request.php  # bọc superglobals
│   ├── Http/Response.php # status, headers, body
│   ├── Router.php        # GET/POST, tham số /todos/{id}
│   ├── View.php          # template PHP thuần + escape
│   ├── Session.php       # bọc $_SESSION
│   ├── Db.php            # PDO
│   └── Controller/...
├── templates/
├── migrations/001_init.sql
└── tests/                # dùng minitest từ phase 04
```

## Lộ trình

1. **Front controller và router:** mọi request vào `index.php`. Router khớp method và path, có 404 và 405.
2. **Request/Response:** không `echo` trực tiếp trong controller. Controller trả về `Response`.
3. **Template:** `View::render('todos/list', [...])` với helper `e()` (gọi `htmlspecialchars`), layout và partial.
4. **Form:** validate, giữ lại input cũ, hiển thị lỗi, áp dụng mẫu **Post/Redirect/Get**.
5. **Session và cookie:** đăng ký và đăng nhập (`password_hash`/`password_verify`), flash message. Xem file session nằm ở đâu (`session.save_path`). Đặt cờ cookie `HttpOnly`, `Secure`, `SameSite`.
6. **Database:**
   - Bắt đầu với **SQLite** (`pdo_sqlite`).
   - Sau đó cài **MariaDB hoặc PostgreSQL** bằng tay: tạo user và database, cấp quyền tối thiểu.
   - Prepared statement 100%, transaction, viết migration runner đơn giản (chạy file `.sql` theo thứ tự, ghi bảng `migrations`).
7. **Middleware:** pipeline `fn(Request $r, callable $next): Response` cho auth, log và bắt lỗi.
8. **Xử lý lỗi:** trang 500 thân thiện ở production, chi tiết ở dev. Chuyển chế độ bằng biến môi trường đặt trong pool FPM (`env[APP_ENV]`).

## Kết quả

Ứng dụng todo có đăng ký, đăng nhập, CRUD todo của riêng từng user, phân trang và có test cho Router và Request.

## Câu hỏi tự kiểm tra

- Vì sao cần front controller thay vì mỗi trang một file `.php`?
- Session PHP lock file thế nào? Ảnh hưởng gì khi có nhiều request AJAX song song?
- `PDO::ATTR_EMULATE_PREPARES` là gì và nên đặt thế nào?

## Checklist

- [ ] Router + Request/Response
- [ ] Template có escape
- [ ] Đăng nhập và session an toàn
- [ ] SQLite rồi MariaDB/PostgreSQL
- [ ] Migration runner
- [ ] Middleware
- [ ] Test bằng minitest
