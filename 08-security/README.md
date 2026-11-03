# 08 · Bảo mật

## Mục tiêu

Tấn công chính ứng dụng todo của phase 07, rồi vá lỗi và harden cả PHP lẫn web server.

> Chỉ thực hành trên máy và ứng dụng của chính bạn.

## Cách làm cho mỗi lỗ hổng

1. Tạo một nhánh `vuln/<tên>` cố ý viết code lỗi.
2. Khai thác và ghi lại cách khai thác.
3. Vá lỗi, viết test chứng minh đã vá, merge về `main`.

## Danh sách

| Lỗ hổng | Thử | Cách vá |
|---|---|---|
| XSS (stored, reflected) | Chèn `<script>` vào tiêu đề todo | Escape theo ngữ cảnh, header CSP |
| SQL injection | Nối chuỗi trong câu query | Prepared statement |
| CSRF | Form ở domain khác submit vào app | Token CSRF, `SameSite` |
| Session fixation | Đặt sẵn session id trước khi login | `session_regenerate_id(true)` sau khi đăng nhập |
| Brute force login | Thử mật khẩu hàng loạt | Rate limit, làm chậm phản hồi |
| Upload file | Upload `.php`, file giả ảnh | Kiểm tra MIME bằng `finfo`, đổi tên, lưu ngoài web root |
| Directory traversal | `?file=../../etc/passwd` | Whitelist, `realpath` |
| IDOR | Sửa `/todos/5` của người khác | Kiểm tra quyền sở hữu |
| Lộ thông tin | `display_errors`, `X-Powered-By` | Cấu hình production |

## Hardening (ghi vào `configs/`)

- `configs/php/99-prod.ini`: `expose_php=Off`, `display_errors=Off`, `log_errors=On`, `session.cookie_*`, `session.use_strict_mode=1`, cân nhắc `disable_functions` và `open_basedir`.
- Pool FPM: user riêng cho từng site, `php_admin_value` (không cho ứng dụng ghi đè).
- Nginx: `server_tokens off`, các header bảo mật, chặn `location ~ /\.`, giới hạn `client_max_body_size`.

## Checklist

- [ ] Khai thác và vá ít nhất 7/9 lỗ hổng trong bảng
- [ ] `99-prod.ini` + pool + Nginx đã harden
- [ ] Ghi bài học của từng lỗ hổng vào journal
