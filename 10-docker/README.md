# 10 · Đóng gói bằng Docker

## Mục tiêu

Chuyển stack đã tự cấu hình (Nginx + PHP-FPM + DB) vào container. Bạn đã hiểu từng file config, nên phần này chỉ là "dời nhà", không phải phép màu.

## Bài tập

1. **Dockerfile PHP-FPM tự viết:**
   - Base image: tham khảo note `docker/trusted-content.md` trong `knowledge-cs`. Dùng Official `php:8.5-fpm` hoặc Docker Hardened Image.
   - Cài extension bằng `docker-php-ext-install`, copy `configs/php/99-prod.ini` và pool FPM của bạn vào.
   - Multi-stage: stage cài Composer dependencies riêng, image runtime không có Composer.
   - Chạy bằng user non-root.
2. **Container Nginx:** dùng lại `configs/nginx/php.local.conf`, chỉ đổi `fastcgi_pass` thành `php:9000` (TCP thay vì unix socket). Vì sao phải đổi?
3. **`compose.yaml` viết tay:** service `nginx`, `php`, `db`, volume cho dữ liệu DB, network, healthcheck.
4. **Log:** đưa log PHP-FPM và Nginx ra stdout/stderr (`docker compose logs`).
5. **So sánh** với bản cài trực tiếp: kích thước image, thời gian khởi động, cách đổi config.

## Checklist

- [ ] Dockerfile multi-stage, non-root
- [ ] Compose chạy được app todo/capstone
- [ ] Log tập trung ra stdout
- [ ] Ghi so sánh vào journal
