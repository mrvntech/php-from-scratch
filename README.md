# PHP from scratch

Repo tự học PHP từ core tới web, **tự cấu hình bằng tay** để hiểu từng lớp: PHP engine → SAPI → web server → ứng dụng.

## Nguyên tắc

1. **Không dùng framework** cho tới phase 09. Tự viết router, request/response, template… rồi mới so sánh với framework.
2. **Không dùng Docker** cho tới phase 10. Cài và cấu hình trực tiếp trên WSL để thấy file config nằm ở đâu, process nào chạy.
3. **Mọi config tự viết đều lưu trong `configs/`** rồi symlink vào `/etc`. Repo chính là lịch sử cấu hình của bạn.
4. **Không sửa file gốc** như `/etc/php/8.5/*/php.ini`. Ghi đè bằng file riêng trong `conf.d/` để luôn biết mình đã đổi gì.
5. **Mỗi buổi học ghi một entry trong `journal/`**: làm gì, vướng gì, hiểu ra gì.

## Lộ trình

| Phase | Chủ đề | Đầu ra | Ước tính |
|---|---|---|---|
| [00](00-setup/) | Cấu trúc cài đặt PHP: SAPI, php.ini, extension | `configs/php/99-study.ini`, bản đồ file của PHP | 1–2 buổi |
| [01](01-core-basics/) | Ngôn ngữ cơ bản: kiểu, mảng, chuỗi, hàm | Bộ script bài tập trong `work/` | 1 tuần |
| [02](02-core-oop/) | OOP và tính năng PHP 8.x | Một domain model nhỏ | 1 tuần |
| [03](03-core-internals/) | Lỗi, autoload, generator, bộ nhớ, OPcache | Autoloader tự viết, đo bộ nhớ | 1 tuần |
| [04](04-cli/) | PHP CLI: argv, stream, exit code, signal | Mini test runner tự viết | 3–4 buổi |
| [05](05-http-raw/) | HTTP bằng tay: socket server, `php -S`, superglobals | HTTP server viết bằng PHP thuần | 3–4 buổi |
| [06](06-web-server/) | Apache mod_php → Apache + FPM → Nginx + FPM | 3 bộ config trong `configs/` | 1–2 tuần |
| [07](07-web-app/) | Web app không framework: router, session, PDO | Ứng dụng todo có đăng nhập | 2 tuần |
| [08](08-security/) | Bảo mật: XSS, SQLi, CSRF, upload, hardening | App đã vá + php.ini/nginx đã harden | 1 tuần |
| [09](09-composer-psr/) | Composer, PSR, PHPUnit, PHPStan | Refactor app theo PSR-4/7/15 | 1 tuần |
| [10](10-docker/) | Đóng gói lại toàn bộ bằng Docker | Dockerfile + compose tự viết | 3–4 buổi |
| [capstone](capstone/) | Mini blog từ đầu | Dự án tổng kết | 2–3 tuần |

Phase 00–04 là **PHP core**, phase 05–08 là **PHP web**, phase 09–10 là **hệ sinh thái và triển khai**.

## Cách dùng mỗi phase

1. Đọc `README.md` của phase: mục tiêu, bài tập, câu hỏi tự kiểm tra.
2. Viết code vào `<phase>/work/` và config vào `configs/`.
3. Tick checklist ở cuối README khi xong.
4. Ghi journal theo `journal/TEMPLATE.md`.
5. Commit sau mỗi bài tập: `git commit -m "01: array functions tự viết"`.

## Môi trường ban đầu (2026-11-03)

| Thành phần | Trạng thái |
|---|---|
| OS | Ubuntu 26.04 trên WSL2, có systemd |
| PHP | 8.5.4 CLI (NTS), cài từ apt |
| Apache | 2.4.66 **đang chạy** cổng 80, có `mod_php8.5` + `mpm_prefork` (do gói `php` kéo vào) |
| Extension thiếu | `mbstring`, `curl`, `xml/dom`, `pdo_sqlite`, `pdo_mysql`, `intl` |
| Chưa có | php-fpm, nginx, composer, database, xdebug, gcc/make |
| Có sẵn | Docker 29.9, git, `ab` (apache2-utils) |

Phase 00 hướng dẫn kiểm tra lại và quyết định giữ hay gỡ Apache để bắt đầu từ trạng thái trống.

## Cấu trúc repo

```
php-from-scratch/
├── 00-setup/ … 10-docker/   # mỗi phase: README.md + work/
├── capstone/                # dự án tổng kết
├── configs/                 # config TỰ VIẾT, symlink vào /etc
│   ├── php/  apache/  nginx/  php-fpm/
└── journal/                 # nhật ký học
```

## Tài liệu chính

- PHP Manual: https://www.php.net/manual/en/
- PHP Internals Book: https://www.phpinternalsbook.com/
- php-src: https://github.com/php/php-src
- PHP The Right Way: https://phptherightway.com/
- RFC các phiên bản: https://wiki.php.net/rfc
