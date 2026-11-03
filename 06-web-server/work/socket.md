# Socket là gì, và file `.sock` có phải file không

**Socket không phải file chứa dữ liệu.** Socket là một **đối tượng trong kernel** để hai bên trao đổi dữ liệu. File `.sock` chỉ là **cái tên (địa chỉ) và ổ khoá phân quyền** để tìm tới đối tượng đó.

## 1. Socket là gì

Socket là **điểm đầu cuối của một kênh giao tiếp** do kernel quản lý. Mỗi socket có buffer gửi và buffer nhận nằm trong RAM của kernel. Chương trình cầm một **file descriptor (fd)**, là một số nguyên, rồi gọi `read()`/`write()` trên đó giống như với file.

Đó là triết lý *"everything is a file"* của Unix: file, pipe, terminal, socket đều được truy cập qua **cùng một giao diện fd**, dù bản chất khác nhau.
```
$ ls -l /proc/$$/fd
... 10 -> socket:[463439]      ← fd 10 trỏ tới socket có inode 463439, KHÔNG có đường dẫn trên ổ đĩa
```

## 2. Hai loại socket hay gặp

| | TCP/IP socket (`AF_INET`) | Unix domain socket (`AF_UNIX`) |
|---|---|---|
| Địa chỉ | IP + port: `127.0.0.1:9000` | **Đường dẫn trên filesystem**: `/run/php/php8.5-fpm.sock` |
| Phạm vi | Qua mạng, giữa các máy | **Chỉ trong cùng một máy** (cùng kernel) |
| Đường đi của dữ liệu | Qua network stack: TCP header, checksum, loopback | Kernel chép thẳng từ buffer bên này sang bên kia |
| Tốc độ | Chậm hơn một chút | Nhanh hơn, ít overhead |
| Kiểm soát truy cập | Firewall, bind vào IP nào | **Quyền file** (owner, group, mode) |
| Ví dụ | Nginx ↔ FPM khác máy, MySQL :3306 | FPM `/run/php/*.sock`, Docker `/run/docker.sock`, MySQL `/run/mysqld/mysqld.sock` |

Socket TCP không có file nào để `ls` thấy. Chỉ **Unix domain socket** mới có file, vì nó dùng đường dẫn làm địa chỉ.

## 3. File `.sock` thực chất là gì

```
$ ls -l /run/docker.sock
srw-rw---- 1 root docker 0 Nov  3 20:58 /run/docker.sock
│                        │
│                        └─ size = 0
└─ "s" = socket ("-" file thường, "d" thư mục, "l" symlink, "p" named pipe, "c"/"b" device)

$ stat -c 'type=%F size=%s owner=%U:%G mode=%A' /run/docker.sock
type=socket size=0 owner=root:docker mode=srw-rw----
```
Đây là một **special file**. Trên filesystem nó chỉ có **inode** (loại file, owner, group, quyền), **không có nội dung**. Nó không phải file thường, cũng không phải directory.

### Demo: dữ liệu không đi qua file

`server.php`:
```php
<?php
$srv = stream_socket_server('unix://' . __DIR__ . '/demo.sock', $errno, $err);
$conn = stream_socket_accept($srv);
fwrite($conn, 'server nhận: ' . fgets($conn));
```
```bash
php server.php &
ls -l demo.sock                        # srwxr-xr-x ... 0 demo.sock  ← bind() tạo ra file
php -r '$c = stream_socket_client("unix://'"$PWD"'/demo.sock");
        fwrite($c, str_repeat("x", 100000) . "\n");
        echo strlen(fgets($c)), " byte trả về\n";'
                                       # 100016 byte trả về
wait
stat -c 'size=%s' demo.sock            # size=0  ← truyền 100 KB nhưng file vẫn 0 byte
ls -l demo.sock                        # file VẪN CÒN dù server đã thoát (stale socket)
rm demo.sock
```
Kết quả đã chạy thử: truyền ~100 KB qua lại, file vẫn `size=0`. Dữ liệu đi **từ buffer kernel của process này sang buffer kernel của process kia**. File `.sock` chỉ dùng **lúc kết nối**, để kernel tìm ra socket đang lắng nghe ở đường dẫn đó.

## 4. Vòng đời

```
SERVER (php-fpm, dockerd)                       CLIENT (nginx, apache, docker CLI)
socket(AF_UNIX)          → tạo socket trong kernel, nhận fd
bind("/run/php/x.sock")  → TẠO file socket trên filesystem
listen()                 → bắt đầu nhận kết nối
                                                socket(AF_UNIX)
                                                connect("/run/php/x.sock")
                                                  → kernel kiểm tra QUYỀN trên file
                                                  → tìm socket đang listen ở đường dẫn đó
accept()                 → nhận một fd MỚI cho kết nối này
read()/write() ◄──── dữ liệu qua buffer kernel ────► read()/write()
close()                                         close()
unlink("/run/php/x.sock") → xoá file (server phải tự làm)
```

| Tình huống | Kết quả |
|---|---|
| Server chết mà không `unlink()` | File còn lại (stale socket). Client connect bị **Connection refused**. Server khởi động lại `bind()` báo **Address already in use**. FPM và Docker tự xoá file cũ khi khởi động |
| Không có file | Không có ai đang lắng nghe. Client nhận **No such file or directory** (lỗi 503 khi FPM chưa chạy) |
| Có file nhưng không có quyền `w` | Client nhận **Permission denied** |

## 5. Phân quyền: vì sao bị lỗi với Docker

Muốn `connect()` vào Unix socket thì process phải có **quyền ghi (`w`) trên file socket** và quyền đi qua (`x`) mọi thư mục cha.

```
srw-rw----  root  docker  /run/docker.sock
  │││ │││
  │││ ││└─ others: không có quyền
  │││ └┴── group docker: đọc + ghi  → được connect
  └┴┴───── owner root: đọc + ghi    → được connect
```
Lệnh `docker` chỉ là **client**: nó connect vào `/run/docker.sock` để gửi HTTP request tới daemon `dockerd`, daemon chạy bằng root.

| Cách | Vì sao được |
|---|---|
| Không làm gì | `dev` rơi vào "others", gặp `permission denied while trying to connect to the Docker daemon socket` |
| `sudo docker ...` | Process client chạy bằng root |
| `sudo usermod -aG docker dev` | `dev` thuộc group `docker`, có `rw`. Phải **đăng nhập lại** hoặc `newgrp docker`, vì danh sách group của process được gán lúc đăng nhập |

Kiểm tra: `id` cho thấy `groups=...,986(docker)`.

**Bảo mật:** ai connect được `docker.sock` thì có thể `docker run -v /:/host ...` và đọc/ghi toàn bộ máy bằng quyền root. **Group `docker` gần như tương đương quyền root.** Đó là lý do socket bị khoá `660`.

## 6. Áp dụng cho PHP-FPM

Cùng cơ chế với Docker:
```ini
listen = /run/php/study.sock
listen.owner = www-data     ; owner của file socket
listen.group = www-data     ; group của file socket
listen.mode = 0660          ; rw cho owner và group, không cho others
```
Apache/Nginx chạy bằng `www-data` nên connect được. Sai owner/group thì gặp `(13) Permission denied: connect to /run/php/study.sock`, giống lỗi Docker.

| `listen =` | Loại | Ghi chú |
|---|---|---|
| `/run/php/study.sock` | Unix socket | Nhanh hơn, kiểm soát bằng quyền file, chỉ dùng trong cùng máy |
| `127.0.0.1:9000` | TCP socket | Không có file `.sock`, quyền file không áp dụng, ai trên máy cũng connect được. Đổi lại, web server ở máy khác (hoặc container khác) kết nối được |

## 7. Lệnh để quan sát

```bash
ss -xlp                          # Unix socket đang LISTEN (x = unix, l = listen, p = process)
ss -tlnp                         # TCP socket đang LISTEN
ls -l /run/php/ /run/docker.sock # các file socket, chữ "s" ở đầu
stat -c '%F' /run/docker.sock    # → socket
ls -l /proc/$$/fd                # fd của shell, có cả "socket:[...]"
sudo lsof -U | grep php-fpm      # Unix socket mà php-fpm đang mở
namei -l /run/php/study.sock     # quyền từng thư mục cha
```

## Liên hệ Java

- TCP socket: `java.net.ServerSocket` / `Socket`, hoặc NIO `ServerSocketChannel` (Acceptor của Tomcat dùng cái này).
- Unix domain socket: từ **JDK 16** (JEP 380) qua `UnixDomainSocketAddress` và `ServerSocketChannel.open(StandardProtocolFamily.UNIX)`. Trước đó phải dùng thư viện native như junixsocket.

## Tóm lại

- **Socket** là đối tượng trong kernel có buffer gửi và nhận, truy cập qua fd. Nó không nằm trên ổ đĩa.
- **File `.sock`** là special file (loại `s`, size 0). Nó là **địa chỉ** để client tìm server và là **ổ khoá phân quyền** (owner, group, mode). Dữ liệu không bao giờ ghi vào file này.
- Lỗi permission với Docker hay FPM có cùng một nguyên nhân: process client không có quyền `w` trên file socket.

Xem thêm: [apache-fpm-setup.md](apache-fpm-setup.md), [request-flow.md](request-flow.md).
