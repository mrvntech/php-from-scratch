# configs/

Mọi file config **tự viết** đều nằm ở đây. File trong `/etc` chỉ là symlink trỏ về repo này, nhờ vậy:

- Config có lịch sử trong git, có thể diff và rollback.
- Luôn biết mình đã đổi gì so với mặc định.
- Dùng lại trực tiếp khi đóng gói Docker ở phase 10.

## Quy ước

| Thư mục | Symlink tới | Ghi chú |
|---|---|---|
| `php/99-study.ini` | `/etc/php/8.5/{cli,fpm,apache2}/conf.d/99-study.ini` | Cấu hình dev. Tiền tố `99-` để nạp sau cùng |
| `php/99-prod.ini` | như trên, ở phase 08 | Thay cho `99-study.ini` khi thử chế độ production |
| `php-fpm/study.conf` | `/etc/php/8.5/fpm/pool.d/study.conf` | Pool riêng, không sửa `www.conf` |
| `apache/php.local.conf` | `/etc/apache2/sites-available/php.local.conf` | Sau đó chạy `a2ensite php.local` |
| `nginx/php.local.conf` | `/etc/nginx/sites-available/` + `sites-enabled/` | |

## Mẫu lệnh

```bash
REPO=/home/dev/php-from-scratch
sudo ln -sf "$REPO/configs/php/99-study.ini" /etc/php/8.5/cli/conf.d/99-study.ini

# Luôn kiểm tra cú pháp trước khi reload
php --ini
sudo php-fpm8.5 -t
sudo apache2ctl configtest
sudo nginx -t
```

## Lưu ý

- `www-data` phải đọc được file đích **và mọi thư mục cha**. Chạy `namei -l <file>` khi gặp lỗi permission.
- Ghi chú lý do bằng comment ngay trong file config (`;` cho ini, `#` cho Nginx/Apache).
