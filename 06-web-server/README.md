# 06 · Web server: 3 cách chạy PHP

## Mục tiêu

Tự cấu hình 3 kiến trúc phổ biến và hiểu khác biệt ở mức process:

```
A. Apache + mod_php     : PHP chạy BÊN TRONG process Apache (SAPI apache2handler)
B. Apache + PHP-FPM     : Apache ──FastCGI──► pool php-fpm (SAPI fpm-fcgi)
C. Nginx  + PHP-FPM     : Nginx  ──FastCGI──► pool php-fpm (cách phổ biến nhất hiện nay)
```

Mọi file config tự viết đặt trong `configs/apache/`, `configs/nginx/`, `configs/php-fpm/` rồi symlink vào `/etc`.

## Chuẩn bị chung

- App thử nghiệm: `work/site/public/index.php` in `php_sapi_name()`, `getmypid()`, `get_current_user()`, `$_SERVER`.
- Domain local `php.local`:
  - Trong WSL: thêm `127.0.0.1 php.local` vào `/etc/hosts`.
  - Muốn mở từ trình duyệt Windows: sửa thêm `C:\Windows\System32\drivers\etc\hosts`.
- Phân quyền: user `www-data` cần đọc được thư mục site. Thử để quyền sai và đọc log lỗi.

## Stage A: Apache + mod_php

```bash
apache2ctl -V                       # MPM, đường dẫn config
apache2ctl -M                       # module đã nạp
ls /etc/apache2/{mods,sites}-enabled
```
- Tự viết `configs/apache/php.local.conf` (VirtualHost, DocumentRoot, `<Directory>`, log riêng).
- Bật bằng `a2ensite`, kiểm tra cú pháp bằng `apache2ctl configtest`, reload.
- Vì sao mod_php **bắt buộc** dùng `mpm_prefork`? Thử bật `mpm_event` và xem điều gì xảy ra.
- `ps aux | grep apache2`: có bao nhiêu process? PHP nằm ở đâu?
- Lưu ý: chạy `apache2` trực tiếp sẽ báo lỗi `APACHE_RUN_DIR`. Luôn dùng `apache2ctl` hoặc `systemctl`.

## Stage B: Apache + PHP-FPM

```bash
sudo apt install php8.5-fpm
sudo a2dismod php8.5 mpm_prefork && sudo a2enmod mpm_event proxy_fcgi setenvif
```
- Tự viết pool riêng `configs/php-fpm/study.conf` (user, `listen` socket, `pm`, `pm.max_children`, `pm.status_path`, `slowlog`, `request_slowlog_timeout`).
- Trong VirtualHost: `<FilesMatch \.php$> SetHandler "proxy:unix:/run/php/study.sock|fcgi://localhost" </FilesMatch>`
- Kiểm tra `php_sapi_name()` đã đổi thành `fpm-fcgi`. Thư mục `/etc/php/8.5/fpm/` có `php.ini` riêng: symlink `99-study.ini` vào đó.
- Thử `pm = static`, `dynamic`, `ondemand` và quan sát số process bằng `ps`.

## Stage C: Nginx + PHP-FPM

```bash
sudo systemctl stop apache2       # hoặc cho Nginx nghe cổng 8080
sudo apt install nginx
```
- Tự viết `configs/nginx/php.local.conf`: `root`, `index`, `location /` với `try_files $uri $uri/ /index.php?$query_string`, `location ~ \.php$` với `fastcgi_pass` và `include fastcgi_params`, `SCRIPT_FILENAME`.
- `nginx -t` trước mỗi lần reload.
- **Bẫy bảo mật kinh điển:** upload `avatar.jpg` có chứa code PHP, rồi truy cập `/uploads/avatar.jpg/x.php`. Tìm hiểu `cgi.fix_pathinfo` và `try_files $uri =404`.
- Phục vụ file tĩnh trực tiếp bằng Nginx, chỉ chuyển `.php` cho FPM.

## So sánh

Ghi vào `work/compare.md`:

| | A. mod_php | B. Apache + FPM | C. Nginx + FPM |
|---|---|---|---|
| Process chạy PHP | | | |
| User chạy PHP | | | |
| php.ini dùng | | | |
| Log lỗi nằm ở | | | |
| Đổi config PHP cần restart gì | | | |
| Kết quả `ab -n 2000 -c 50` | | | |

## Câu hỏi tự kiểm tra

- FastCGI là gì? So với CGI cũ thì cải thiện điều gì?
- `.htaccess` hoạt động thế nào và vì sao Nginx không có?
- `pm.max_children` nên tính theo cách nào?

## Checklist

- [ ] Stage A chạy với vhost tự viết
- [ ] Stage B chạy với pool FPM tự viết
- [ ] Stage C chạy, đã tái hiện và vá bẫy path info
- [ ] Bảng so sánh có số liệu benchmark
