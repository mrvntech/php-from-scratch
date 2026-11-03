# 00 · Cấu trúc cài đặt PHP

## Mục tiêu

- Biết PHP trên Ubuntu gồm những gì: binary, **SAPI**, `php.ini` riêng cho từng SAPI, `conf.d/`, extension `.so`.
- Tự viết file config đầu tiên và kiểm chứng nó được nạp.
- Quyết định điểm xuất phát: giữ Apache + mod_php có sẵn, hay gỡ để tự cài từ đầu.

## Bài tập

### 1. Khám phá
```bash
php -v
php --ini                         # php.ini nào được nạp, conf.d nào được quét
php -m                            # module đang bật
php -i | less                     # toàn bộ cấu hình (phpinfo dạng text)
php -i | grep -E "extension_dir|Server API"
php --re json                     # reflection một extension
ls /etc/php/8.5/                  # mỗi thư mục con là một SAPI
ls /etc/php/8.5/mods-available/
ls /usr/lib/php/8.5/              # php.ini-development / -production
```
Vẽ vào `work/php-map.md` một sơ đồ: file nào → nạp bởi SAPI nào → theo thứ tự nào.

### 2. So sánh development và production
```bash
diff <(grep -vE '^\s*(;|$)' /usr/lib/php/8.5/php.ini-development) \
     <(grep -vE '^\s*(;|$)' /usr/lib/php/8.5/php.ini-production)
```
Ghi lại các khác biệt vào `work/ini-diff.md` và giải thích từng dòng: `display_errors`, `error_reporting`, `zend.assertions`, `opcache.*`…

### 3. File config đầu tiên của bạn
- Tự viết `configs/php/99-study.ini` (bật hiển thị lỗi, `error_reporting = E_ALL`, `zend.assertions = 1`, đặt `date.timezone`…).
- Symlink vào CLI và kiểm chứng:
  ```bash
  sudo ln -s "$PWD/configs/php/99-study.ini" /etc/php/8.5/cli/conf.d/99-study.ini
  php --ini | grep study
  php -i | grep display_errors
  ```
- Thử ghi đè tạm thời: `php -d display_errors=0 -i | grep display_errors`. Thứ tự ưu tiên là gì?

### 4. Extension
```bash
sudo apt install php8.5-mbstring php8.5-xml php8.5-curl php8.5-sqlite3 php8.5-intl
ls -l /etc/php/8.5/cli/conf.d/    # phpenmod tạo symlink ở đây
sudo phpdismod -s cli mbstring && php -m | grep mbstring
sudo phpenmod  -s cli mbstring && php -m | grep mbstring
```
Câu hỏi: số `20-` trong `20-mbstring.ini` dùng để làm gì?

### 5. Chọn điểm xuất phát cho phần web
Apache + mod_php đang chạy sẵn ở cổng 80 (`systemctl status apache2`). Chọn một trong hai:
- **Giữ lại** và dùng nó làm stage A của phase 06. Bạn sẽ "khảo cổ" một cấu hình có sẵn.
- **Gỡ để bắt đầu trống** (khuyến nghị nếu muốn tự cấu hình 100%):
  ```bash
  sudo apt purge apache2 apache2-bin apache2-data apache2-utils libapache2-mod-php8.5
  sudo apt autoremove
  ```
  Lưu ý: `ab` nằm trong `apache2-utils`. Nếu cần benchmark ở phase 06 thì cài lại riêng gói đó.

### 6. (Tùy chọn, nâng cao) Build PHP từ source
Dành cho ai muốn hiểu sâu engine, đặc biệt trước phase 03:
```bash
sudo apt install build-essential autoconf bison re2c pkg-config libxml2-dev libsqlite3-dev
git clone --depth 1 --branch PHP-8.5 https://github.com/php/php-src.git ~/src/php-src
cd ~/src/php-src && ./buildconf && \
  ./configure --prefix=$HOME/opt/php-debug --enable-debug --disable-all --enable-cli
make -j"$(nproc)" && make install
~/opt/php-debug/bin/php -v       # bản DEBUG, không thay thế PHP của hệ thống
```

## Câu hỏi tự kiểm tra

- SAPI là gì? Kể các SAPI bạn biết và SAPI nào đang dùng khi chạy `php file.php`.
- Tại sao CLI và Apache có `php.ini` khác nhau? Lợi và hại?
- NTS và ZTS khác nhau thế nào? Bản đang cài là loại nào, vì sao?
- `extension=` và `zend_extension=` khác nhau thế nào? OPcache thuộc loại nào?

## Checklist

- [ ] Sơ đồ `work/php-map.md`
- [ ] `work/ini-diff.md`
- [ ] `configs/php/99-study.ini` đã symlink và kiểm chứng
- [ ] Cài và bật/tắt được extension bằng `phpenmod`/`phpdismod`
- [ ] Đã quyết định giữ hay gỡ Apache (ghi lý do vào journal)
