# Cấu hình Apache + PHP-FPM (stage B)

```
Trước (stage A):  Apache prefork ──► mod_php (PHP chạy BÊN TRONG Apache)
Sau  (stage B):   Apache event ──FastCGI qua /run/php/study.sock──► php-fpm pool "study"
```
Đổi ba thứ: **MPM** (prefork → event), **handler** (mod_php → mod_proxy_fcgi), và thêm **một pool FPM riêng**.

Trạng thái máy lúc ghi note (2026-11-03): Apache đang chạy `mpm_prefork` + `mod_php8.5`, chưa cài `php-fpm`.

## Bước 1: Cài PHP-FPM

```bash
sudo apt install php8.5-fpm
systemctl status php8.5-fpm
ls /etc/php/8.5/            # có thêm thư mục fpm/
ls -l /run/php/             # socket của pool mặc định www: php8.5-fpm.sock
```
Ubuntu kèm sẵn `/etc/apache2/conf-available/php8.5-fpm.conf`, file này chuyển **mọi** `.php` trên toàn server tới pool `www`. **Không bật** (`a2enconf`): handler sẽ tự viết trong vhost và dùng pool riêng. Kiểm tra bằng `ls /etc/apache2/conf-enabled/`.

## Bước 2: Pool riêng `configs/php-fpm/study.conf`

```ini
; Pool riêng cho repo học, không sửa www.conf
[study]
user = www-data
group = www-data

; Apache kết nối vào socket này
listen = /run/php/study.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

; Trang trạng thái và health check
pm.status_path = /fpm-status
ping.path = /fpm-ping

; Request chậm hơn 5 giây thì ghi stack trace PHP
slowlog = /var/log/php8.5-fpm-study.slow.log
request_slowlog_timeout = 5s
request_terminate_timeout = 30s

; Đưa stderr của worker vào log của FPM
catch_workers_output = yes

env[APP_ENV] = dev
```

```bash
REPO=/home/dev/php-from-scratch
sudo ln -s "$REPO/configs/php-fpm/study.conf" /etc/php/8.5/fpm/pool.d/study.conf
sudo ln -s "$REPO/configs/php/99-study.ini"   /etc/php/8.5/fpm/conf.d/99-study.ini   # php.ini riêng của SAPI fpm
sudo php-fpm8.5 -t
sudo systemctl reload php8.5-fpm
ls -l /run/php/study.sock          # www-data:www-data, srw-rw----
ps -ef --forest | grep php-fpm     # master + pool www + pool study
```

## Bước 3: Đổi MPM và module của Apache

```bash
sudo a2dismod php8.5 mpm_prefork                 # mod_php không chạy được với event MPM
sudo a2enmod mpm_event proxy_fcgi setenvif       # proxy_fcgi tự kéo theo mod_proxy
apache2ctl -M | grep -E 'mpm|proxy|php'
```
Đổi MPM phải **restart**, reload là không đủ: MPM được chọn lúc Apache khởi động.

## Bước 4: Vhost `configs/apache/php.local.conf`

```apache
<VirtualHost *:80>
    ServerName php.local
    DocumentRoot /home/dev/php-from-scratch/06-web-server/work/site/public

    <Directory /home/dev/php-from-scratch/06-web-server/work/site/public>
        Require all granted
        AllowOverride None          # không đọc .htaccess: nhanh hơn, config nằm hết ở đây
        Options -Indexes
        DirectoryIndex index.php
    </Directory>

    # Chuyển .php cho FPM, nhưng chỉ khi file thật sự tồn tại
    <FilesMatch "\.php$">
        <If "-f %{REQUEST_FILENAME}">
            SetHandler "proxy:unix:/run/php/study.sock|fcgi://localhost"
        </If>
    </FilesMatch>

    # Trang trạng thái FPM, chỉ cho truy cập từ localhost
    <LocationMatch "^/(fpm-status|fpm-ping)$">
        SetHandler "proxy:unix:/run/php/study.sock|fcgi://localhost"
        Require local
    </LocationMatch>

    ErrorLog  ${APACHE_LOG_DIR}/php.local-error.log
    CustomLog ${APACHE_LOG_DIR}/php.local-access.log combined
</VirtualHost>
```

| Dòng | Ý nghĩa |
|---|---|
| `SetHandler "proxy:unix:...\|fcgi://localhost"` | Giao request cho `mod_proxy_fcgi` qua unix socket. `fcgi://localhost` sau dấu `\|` chỉ là tên định danh cho proxy worker, không phải kết nối TCP |
| `<If "-f %{REQUEST_FILENAME}">` | Chỉ chuyển cho FPM khi file `.php` có thật. Thiếu dòng này, request tới file không tồn tại vẫn bị gửi sang FPM (cùng họ lỗ hổng với bẫy path info của Nginx) |
| `AllowOverride None` | Apache không phải tìm `.htaccess` ở mỗi thư mục cha cho mỗi request |
| `FallbackResource /index.php` | Thêm vào `<Directory>` ở phase 07 để có front controller. Tương đương `try_files ... /index.php` của Nginx |

```bash
sudo ln -s "$REPO/configs/apache/php.local.conf" /etc/apache2/sites-available/php.local.conf
sudo a2ensite php.local
sudo apache2ctl configtest
sudo systemctl restart apache2
```

## Bước 5: Phân quyền và hosts

DocumentRoot nằm dưới `/home/dev`, mà **cả Apache lẫn FPM** (chạy bằng `www-data`) đều cần quyền đi qua mọi thư mục cha:
```bash
namei -l /home/dev/php-from-scratch/06-web-server/work/site/public/index.php
chmod o+x /home/dev                      # chỉ cấp quyền "đi qua", không cấp quyền liệt kê
echo '127.0.0.1 php.local' | sudo tee -a /etc/hosts
```

## Bước 6: Kiểm chứng

```bash
curl -s http://php.local/ | head          # php_sapi_name() phải là fpm-fcgi
curl -s http://php.local/fpm-status       # pool: study, active processes, ...
ps -ef --forest | grep -E 'apache2|php-fpm'
apache2ctl -V | grep MPM                  # Server MPM: event
```
*Loaded Configuration File* trong `php -i` (CLI) và `phpinfo()` (web) phải lần lượt là `cli/php.ini` và `fpm/php.ini`.

## Khi gặp lỗi

| Triệu chứng | Nguyên nhân thường gặp |
|---|---|
| `configtest` báo lỗi ở `SetHandler proxy...`, hoặc 500 | Chưa bật `proxy_fcgi` |
| Apache không khởi động, báo MPM không thread-safe | Còn bật `mod_php` cùng `mpm_event` |
| **503**, log có `(13)Permission denied: connect to /run/php/study.sock` | Sai `listen.owner` / `listen.mode` |
| **503**, log có `No such file or directory` | FPM chưa chạy hoặc sai đường dẫn socket |
| Trình duyệt tải file `.php` về hoặc hiện mã nguồn | `SetHandler` không khớp, hoặc vhost chưa được bật |
| "File not found." / `Primary script unknown` | FPM không đọc được file (quyền thư mục cha) |
| Sửa `99-study.ini` mà web không đổi | Mới symlink vào `cli/conf.d`, quên `fpm/conf.d`, hoặc quên reload `php8.5-fpm` |

Log cần xem: `/var/log/apache2/php.local-error.log`, `/var/log/php8.5-fpm.log`, `journalctl -u php8.5-fpm`.

## Tiếp theo

- Đổi `pm = static | dynamic | ondemand`, reload, quan sát số process bằng `ps`.
- Điền cột B trong `compare.md`.

Xem thêm: [socket.md](socket.md) (Unix socket và quyền file socket), [request-flow.md](request-flow.md) (mục 5: luồng request qua Apache + FPM), [fpm-vs-servlet-container.md](fpm-vs-servlet-container.md).
