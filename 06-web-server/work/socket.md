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

## 8. Ba thứ cùng được gọi là "socket": đường dẫn, socket object, fd

| Thứ | Là gì | Nằm ở đâu | Ví dụ trên máy |
|---|---|---|---|
| **Đường dẫn** `/run/docker.sock` | **Cái tên** (special file loại `s`) để client tìm tới socket | Filesystem | inode **177** |
| **Socket object** | **Đối tượng thật**: trạng thái LISTEN, hàng đợi accept, buffer | Bộ nhớ kernel | inode **6303** |
| **File descriptor (fd)** | **Con số** riêng của từng process để cầm socket object | Bảng fd của mỗi process | `3`, `7`... |

```bash
stat -c '%i %n' /run/docker.sock                            # 177  /run/docker.sock  ← inode FILE
ss -xl | awk '$5=="/run/docker.sock"{print $6, $5}'         # 6303 /run/docker.sock  ← inode SOCKET
```
Cùng tên nhưng hai inode khác nhau. `bind()` gắn tên với socket object.

Ví von: đường dẫn là **số điện thoại**, socket object là **tổng đài**, fd là **ống nghe trong tay** mỗi người (mỗi người tự đánh số, nhiều ống nghe có thể nối vào cùng một tổng đài).

**`dockerd -H fd://`** (systemd socket activation): systemd tạo socket object, `bind("/run/docker.sock")`, đặt quyền `root:docker 0660`, `listen()`, rồi **truyền fd** sang dockerd (biến `LISTEN_FDS`, xuất hiện ở fd 3). Thứ được truyền là **fd**, không phải file `.sock`. Worker FPM thừa hưởng socket từ master cũng theo cách này: thừa hưởng **fd** qua `fork()`.

## 9. Socket object là cái ống do kernel quản lý, không phải chương trình

Socket object là **cấu trúc dữ liệu trong kernel** (`struct socket`, `sock`, `unix_sock`): trạng thái, hàng đợi kết nối chờ `accept`, buffer gửi và nhận, con trỏ tới đầu bên kia.
- **Không tự chạy**: không có thread, không có vòng lặp. Nó chỉ "hoạt động" khi process gọi system call vào nó, lúc đó **code của kernel** chạy thay cho process.
- **Không hiểu HTTP**: `GET /containers/json HTTP/1.1` với nó chỉ là dãy byte.

```
┌─────────────── user space (chương trình) ────────────────┐
│ docker CLI                               dockerd          │
│  - tạo HTTP request                       - accept()      │
│  - quyết định connect / close             - parse HTTP    │
│  - parse HTTP response, in bảng           - router, xử lý │
└──────┬─────────────────────────────────────────┬─────────┘
       │ system call: connect/write/read/close   │ accept/read/write/close
┌──────▼─────────────────────────────────────────▼─────────┐
│ KERNEL                                                    │
│  socket object: [trạng thái][hàng đợi accept][buffer A][buffer B]
│  - kiểm tra quyền lúc connect                             │
│  - xếp kết nối vào hàng đợi chờ accept (backlog)          │
│  - chép byte vào buffer, giữ đúng thứ tự                  │
│  - chặn write khi buffer đầy (backpressure)               │
│  - báo EOF khi một bên close/shutdown                     │
└───────────────────────────────────────────────────────────┘
```

| Việc | Ai làm |
|---|---|
| "Lắng nghe" | **Chương trình** gọi `listen()` + `accept()`. Kernel chỉ đánh dấu LISTEN và giữ hàng đợi |
| Hiểu HTTP, JSON, FastCGI | **Chương trình** |
| Chuyển byte, thứ tự, buffer, backpressure | **Kernel** |
| Giữ trạng thái "đang kết nối" | **Kernel** |
| Quyết định kết nối sống bao lâu | **Hai chương trình** (khi nào gọi `close()`) |

### Demo: kernel giữ kết nối dù server không làm gì
Server `listen()` nhưng **không bao giờ `accept()`**, không đọc:
```python
s = socket.socket(socket.AF_UNIX); s.bind('lazy.sock'); s.listen(); time.sleep(4)
```
```
client: connect OK, gửi được 5016 byte dù server chưa accept
$ ss -xa
u_str LISTEN 1 ... lazy.sock      ← Recv-Q = 1: một kết nối đang nằm trong hàng đợi accept của kernel
```
Với socket activation của Docker cũng vậy: `dockerd` bị stop thì systemd vẫn giữ `/run/docker.sock`. Client connect vẫn được, kết nối nằm chờ, tới khi systemd khởi động lại dockerd.

### TCP: kernel làm nhiều hơn
Unix socket chỉ cần chép byte giữa hai buffer trong cùng máy. Với TCP, **kernel** còn lo: bắt tay 3 bước, số thứ tự, ACK, gửi lại gói mất, sắp xếp lại gói, điều khiển luồng và tắc nghẽn, keepalive, `TIME_WAIT`. Tất cả vẫn là code kernel (chạy khi có ngắt mạng hoặc timer), không phải chương trình riêng. Chương trình vẫn chỉ thấy một ống byte có thứ tự. Vì vậy dockerd nghe `unix://` hay `tcp://` đều dùng chung code.

## 10. Socket listen và socket kết nối

**Chỉ server `listen`.** Client `connect`. Sau khi kết nối, **cả hai** đều `read`/`write` trên socket của mình.

```
SERVER (dockerd)                                   CLIENT (docker CLI)

① socket listen  ─ chỉ để NHẬN KẾT NỐI MỚI
   bind("/run/docker.sock"), listen()
   KHÔNG truyền dữ liệu qua socket này
        │  accept()                                 socket()
        │◄───────────── kết nối mới ─────────────── connect("/run/docker.sock")
        ▼
② socket kết nối (do accept tạo ra)  ◄═══ cặp socket nối chéo ═══►  ③ socket kết nối của client
   read / write                                                      read / write
```

| Socket | Thuộc về | Dùng để | System call |
|---|---|---|---|
| ① **listen** | Server | Nhận kết nối mới. Mỗi `accept()` sinh ra một ② | `bind`, `listen`, `accept` |
| ② **kết nối** phía server | Server, mỗi client một cái | Trao đổi dữ liệu với **một** client | `read`, `write`, `close` |
| ③ **kết nối** phía client | Client | Trao đổi dữ liệu với server | `connect`, `read`, `write`, `close` |

dockerd phục vụ 5 lệnh `docker` cùng lúc thì có **1 socket listen + 5 socket kết nối** (`ss -xa`: một dòng `LISTEN`, nhiều dòng `ESTAB`). Ví von: ① là **quầy lễ tân**, ② và ③ là **bàn** nơi hai bên nói chuyện.

| | Socket listen | Socket kết nối |
|---|---|---|
| PHP-FPM | `listen = /run/php/study.sock`, mọi worker cùng `accept()` | Mỗi request từ Nginx một socket |
| Tomcat | `ServerSocketChannel` của Acceptor | `SocketChannel` mà Poller theo dõi |
| Java API | `ServerSocket.accept()` trả về `Socket` | `new Socket(host, port)` |

## 11. Chờ dữ liệu: client không quay vòng hỏi liên tục

Gọi `read()` khi chưa có dữ liệu thì **kernel cho process ngủ**, và **đánh thức** khi dữ liệu tới. Lúc ngủ gần như không tốn CPU.

Demo: server đợi 3 giây mới gửi, trong lúc đó xem client:
```
  PID STAT %CPU WCHAN                CMD
40817 S     0.6 unix_stream_read_gen python3 ... c.recv(100)
              │         └─ đang ngủ trong hàm đọc Unix socket của kernel
              └─ S = Sleeping
client nhận: b'hello sau 3 giay' sau 3.0s
```

```
client: read(fd)
  └─► kernel: buffer rỗng → đưa process vào wait queue của socket → Sleeping, CPU cho process khác
                    ... process không chạy chút nào ...
server: write(fd, "hello")
  └─► kernel: chép byte vào buffer client → thấy process đang chờ → ĐÁNH THỨC
client: read() trả về "hello"
```

**Vòng lặp `read` để gom cho đủ, không phải để thăm dò.** Socket là luồng byte, một lần `read()` có thể chỉ lấy được một phần. "Đủ" do **giao thức** quyết định: HTTP (`Content-Length`, `chunked`, EOF), FastCGI (`contentLength` + `END_REQUEST`), Docker stream (trường `size`).

| Cách chờ | Hoạt động | Ai dùng |
|---|---|---|
| **Blocking** | Mỗi kết nối một thread/process, ngủ trong `read()` | Worker FPM, Apache prefork, script PHP đơn giản |
| **Non-blocking + epoll** | Một thread ngủ trong `epoll_wait()` trên hàng nghìn socket, kernel đánh thức kèm danh sách socket sẵn sàng | Nginx, Apache event, Poller của Tomcat (`Selector` bọc epoll) |
| **Trông như blocking, bên dưới là epoll** | Goroutine / virtual thread "đỗ" lại, runtime dùng epoll | Go (`net/http`, docker CLI, dockerd), Java 21 virtual thread |
| Busy-wait | `while(true)` thử đọc liên tục | Không ai dùng: tốn 100% CPU |

## 12. File `.sock` nằm ở đâu và dùng để làm gì

### `/run/docker.sock` cũng không nằm trên disk
```
$ findmnt /run
/run  tmpfs          ← filesystem nằm hoàn toàn trong RAM, mất khi tắt máy
```
Vì vậy systemd và FPM phải tạo lại socket mỗi lần khởi động. (`/tmp` trên máy này cũng là tmpfs.)

File socket **có thể** nằm trên disk thật nếu `bind()` vào đường dẫn trên ext4 (`/home/dev/x.sock`). Khi đó disk chỉ có **inode** (loại `s`, owner, quyền), không có nội dung. Dữ liệu không bao giờ chạm disk, dù file nằm ở đâu.
```
/run/docker.sock (tmpfs):
  RAM: [inode 177 trong tmpfs]  ──bind──  [socket object 6303] ── buffer
  Disk: (không có gì)

/home/dev/x.sock (ext4):
  Disk: [inode, size 0]  ──bind──  RAM: [socket object] ── buffer
```

### Hai vai trò của file
| Vai trò | Ý nghĩa |
|---|---|
| **Địa chỉ (tên)** | Client `connect("/run/docker.sock")`, kernel tra đường dẫn để **tìm** socket object đang listen |
| **Phân quyền** | Kernel kiểm tra quyền `w` trên file (và `x` trên thư mục cha) **lúc `connect()`** |

Quyền được kiểm tra **một lần ở `connect`**, không phải ở mỗi lần ghi.

### Abstract socket: không có file vẫn chạy
Tên bắt đầu bằng byte `\0`, **không tạo file nào**:
```python
s.bind('\0hoc-php-demo'); s.listen()
c.connect('\0hoc-php-demo')
```
```
abstract socket: connect OK, không có file nào trên filesystem
u_str LISTEN 0 128  @hoc-php-demo  694817      ← ss hiển thị bằng ký tự @
```
Chứng minh file chỉ để **đặt tên và khoá cửa**. Đổi lại, abstract socket **không có quyền file**: mọi process trong cùng *network namespace* đều connect được. Vì vậy Docker, FPM dùng socket có file.

### `SO_PEERCRED`: server hỏi kernel "ai đang kết nối?"
```
server hỏi kernel "ai đang kết nối?" (SO_PEERCRED): pid=45801 uid=1000 gid=1000
```
Kernel trả **pid, uid, gid thật** của đầu bên kia, client không giả mạo được. Dùng bởi PostgreSQL `peer` authentication (user Linux `dev` → role `dev` không cần mật khẩu), systemd, D-Bus, Docker authz plugin.
```
lớp 1 (kernel, trước khi kết nối):     quyền file socket → ai được connect
lớp 2 (chương trình, sau khi kết nối): SO_PEERCRED      → biết chính xác ai, tự quyết định
```

## 13. Dữ liệu và danh tính được lưu ở đâu

Cả hai đều nằm trong **socket object** trong RAM của kernel, không nằm trong file:
```
socket object (struct sock)
├─ trạng thái: LISTEN / ESTABLISHED / ...
├─ con trỏ tới socket ở đầu bên kia (Unix socket)
├─ sk_peer_pid, sk_peer_cred     ← DANH TÍNH đầu bên kia: pid, uid, gid
├─ sk_receive_queue              ← DỮ LIỆU chờ read(): danh sách khối sk_buff
├─ giới hạn buffer: SO_RCVBUF, SO_SNDBUF
└─ wait queue                    ← process đang ngủ chờ dữ liệu
```

### Danh tính được chụp lúc `connect()`
```
client (uid=1000) connect("/run/docker.sock")
  └─ kernel: kiểm tra quyền file (lớp 1)
             tạo cặp socket kết nối
             CHÉP pid/uid/gid của client vào socket phía server → sk_peer_cred
             CHÉP danh tính server vào socket phía client
server getsockopt(SO_PEERCRED) → kernel đọc lại sk_peer_cred
```
- Kernel tự ghi từ chính process gọi `connect()`, nên không giả mạo được.
- Chụp **tại thời điểm kết nối**: client đổi user sau đó (`setuid`) thì giá trị vẫn như cũ.

### Dữ liệu nằm trong hàng đợi `sk_buff`
Mỗi `write()`, kernel cấp một khối **`sk_buff`**, chép byte vào, móc vào **hàng đợi nhận** của socket đầu kia. `read()` chép ra bộ nhớ process rồi giải phóng.
```
write(a, 3000 byte)                                        read(b)
   ▼                                                          ▲
 [sk_buff 3000 byte] ──móc vào──► hàng đợi nhận của b: [skb][skb]... ──chép ra──┘
                                   (RAM của kernel)
```
Demo: `a` gửi 3000 byte, `b` chưa đọc:
```
u_str ESTAB 3000  0  ... users:(("python3",pid=46511,fd=4))
             └─ Recv-Q = 3000: đang nằm trong kernel chờ b đọc

buffer gửi tối đa (SO_SNDBUF): 212992 byte                       (~208 KB mặc định)
gửi thêm 176128 byte thì buffer ĐẦY → write sẽ bị chặn (backpressure)
```
Đầy thì `write()` blocking **ngủ**, non-blocking trả `EAGAIN`. Chỉnh bằng `SO_SNDBUF`/`SO_RCVBUF`, hoặc `net.core.wmem_default`, `rmem_default`.

### Thứ gì nằm ở đâu
| Thứ | Nằm ở | Sống bao lâu |
|---|---|---|
| Tên `/run/docker.sock`, owner, quyền | **Inode** (tmpfs = RAM) | Tới `unlink()` hoặc tắt máy |
| Trạng thái kết nối, con trỏ đầu kia | **Socket object** (RAM kernel) | Tới khi cả hai `close()` |
| Danh tính peer (`SO_PEERCRED`) | **Socket object** (`sk_peer_cred`) | Cùng kết nối |
| Dữ liệu chưa đọc | **Hàng đợi `sk_buff`** (RAM kernel) | Tới khi bên kia `read()` |
| Dữ liệu đã đọc | **Bộ nhớ process** (`$body`, `[]byte`...) | Do chương trình quản lý |

TCP giống vậy, thêm IP/port hai đầu, số thứ tự, **hàng đợi gửi lại** (byte chưa ACK). TCP **không có** `SO_PEERCRED`, vì kernel không biết uid của process ở máy khác.

## 14. File `.sock` và fd: ổ khoá và chìa khoá

| | **File `.sock`** (đường dẫn) | **fd** |
|---|---|---|
| Là gì | Inode: tên, owner, quyền | Con số trong bảng fd **của từng process**, trỏ tới socket object |
| Dùng khi nào | **Chỉ lúc `connect()`** | **Mọi thao tác sau đó**: `read`, `write`, `close`, `epoll` |
| Vai trò | **Tìm** socket listen + **kiểm tra quyền connect** | **Cầm** socket object để trao đổi dữ liệu |
| Ai có | Ai đi qua được thư mục đều thấy | Chỉ process đã `socket()`/`connect()`/`accept()`, hoặc được thừa hưởng / truyền fd |

```
1. client: socket()                  → kernel tạo socket object, trả fd 3      (fd có trước cả khi kết nối)
2. client: connect(3, "/run/docker.sock")
             └─ kernel dùng FILE: tìm socket listen + kiểm tra quyền w        ← vai trò của FILE
3. client: write(3, ...), read(3)    → kernel dùng fd 3 tìm socket object      ← vai trò của fd
                                        KHÔNG kiểm tra quyền file nữa
```

**Có fd là có quyền.** Kernel không kiểm tra quyền ở mỗi `read`/`write`. fd là **chìa khoá đã mở cửa**, file là **ổ khoá**:
- Worker FPM **thừa hưởng fd** từ master qua `fork()`, không kiểm tra lại quyền file.
- systemd **truyền fd** cho dockerd (`-H fd://`).
- Process có thể **gửi fd** cho process khác qua Unix socket (`SCM_RIGHTS`). Bên nhận dùng được ngay, **kể cả khi không có quyền trên file `.sock`**.
- `chmod 000 /run/docker.sock` **sau khi** client đã kết nối: client vẫn đọc ghi bình thường.

## 15. Cùng kernel và khác kernel

**Mỗi kernel luôn chỉ quản lý process và socket trên máy của chính nó.** Hai kernel không điều khiển được nhau, chỉ **phối hợp qua gói tin TCP**.

### Cùng một kernel
```
            ┌──────────────────────── 1 KERNEL ────────────────────────┐
 client ──► │ socket A ──── chép thẳng byte vào hàng đợi ────► socket B │ ◄── server
 write/read │  (đánh thức B khi có dữ liệu, chặn A khi B đầy)           │     read/write
            └───────────────────────────────────────────────────────────┘
```
Một kernel giữ cả hai socket: chép byte, đánh thức, backpressure, phân quyền bằng file + `SO_PEERCRED`. Áp dụng cho Unix socket. TCP qua `127.0.0.1` cũng một kernel, nhưng chạy đầy đủ TCP stack, đóng vai cả hai đầu.

### Hai kernel
```
     MÁY A                                                    MÁY B
┌──────── KERNEL A ────────┐                          ┌──────── KERNEL B ────────┐
│ socket client            │   gói TCP qua mạng       │ socket server            │
│  buffer gửi ────────────►│══ SYN/ACK, dữ liệu, ════►│──► buffer nhận           │
│  buffer nhận ◄───────────│◄═ ACK, window, FIN ══════│◄── buffer gửi            │
│ cho client ngủ/đánh thức │                          │ cho server ngủ/đánh thức │
└──────────────────────────┘                          └──────────────────────────┘
```

| Việc | Cùng kernel | Khác kernel |
|---|---|---|
| Chuyển dữ liệu | Chép buffer A sang buffer B | Kernel A gửi gói, kernel B nhận vào buffer của B |
| Ngủ / đánh thức | Một kernel lo cả hai | **Mỗi kernel tự lo process của mình**. Kernel B đánh thức server khi card mạng báo có gói |
| Backpressure | Buffer B đầy thì `write` của A ngủ ngay | Kernel B quảng bá **TCP receive window** trong ACK. Window = 0 thì kernel A ngừng gửi, buffer gửi A đầy dần, rồi `write` của client ngủ |
| Không mất dữ liệu | Không cần (chép trong RAM) | Kernel A giữ byte chưa ACK để **gửi lại** |
| Phát hiện bên kia đóng | Thấy ngay | Gói **FIN** / **RST**. Máy B mất điện thì kernel A **không biết** tới khi gửi không được ACK hoặc TCP keepalive phát hiện |
| Phân quyền | Quyền file + `SO_PEERCRED` | **Không có**. Chuyển lên **ứng dụng** (token, mật khẩu, TLS client cert) và **firewall** |

Vì dòng cuối mà Docker mặc định chỉ nghe Unix socket: mở `tcp://0.0.0.0:2375` không TLS thì **ai trên mạng cũng điều khiển được dockerd**.

### Trường hợp thực tế
- **Container trên cùng host dùng chung kernel** (chỉ là process + namespace, không có kernel riêng). Vì vậy `-v /var/run/docker.sock:/var/run/docker.sock` chạy được, và cũng vì vậy nó nguy hiểm: container đó điều khiển được dockerd.
- **WSL2 có kernel Linux riêng** trong máy ảo, tách khỏi kernel Windows. Trình duyệt Windows vào `php.local` trong WSL là kết nối **giữa hai kernel** qua mạng ảo, phải dùng TCP.
- **Unix socket chỉ dùng được trong cùng một kernel.** Qua kernel khác thì dùng TCP/UDP.

## Liên hệ Java

- TCP socket: `java.net.ServerSocket` / `Socket`, hoặc NIO `ServerSocketChannel` (Acceptor của Tomcat dùng cái này).
- Unix domain socket: từ **JDK 16** (JEP 380) qua `UnixDomainSocketAddress` và `ServerSocketChannel.open(StandardProtocolFamily.UNIX)`. Trước đó phải dùng thư viện native như junixsocket.

## Tóm lại

- **Socket** là đối tượng trong kernel có buffer gửi và nhận, truy cập qua fd. Nó không nằm trên ổ đĩa.
- **File `.sock`** là special file (loại `s`, size 0). Nó là **địa chỉ** để client tìm server và là **ổ khoá phân quyền** (owner, group, mode). Dữ liệu không bao giờ ghi vào file này.
- Lỗi permission với Docker hay FPM có cùng một nguyên nhân: process client không có quyền `w` trên file socket.
- **Đường dẫn ≠ socket object ≠ fd**: tên để tìm, đối tượng thật trong kernel, tay cầm của từng process.
- Socket object là **cái ống do kernel quản lý**, không phải chương trình. Chương trình lắng nghe, hiểu giao thức và quyết định đóng. Kernel giữ trạng thái và chuyển byte.
- Chỉ server `listen`. Mỗi `accept()` tạo một socket kết nối riêng. Hai bên `read`/`write` trên socket của mình.
- `read()` không có dữ liệu thì process **ngủ**. Muốn phục vụ nhiều kết nối thì dùng **epoll**.
- `/run/docker.sock` nằm trên **tmpfs** (RAM). File có hai vai trò: **địa chỉ** và **ổ khoá** (kiểm tra lúc `connect`). Abstract socket không có file. `SO_PEERCRED` cho server biết chính xác client là ai.
- **Danh tính peer** và **dữ liệu** (hàng đợi `sk_buff`, ~208 KB mặc định) đều nằm trong **socket object**.
- **File là ổ khoá, fd là chìa khoá**: có fd là đọc ghi được, không kiểm tra quyền lại.
- **Cùng kernel**: một kernel lo cả hai đầu. **Khác kernel**: mỗi kernel lo máy mình, phối hợp bằng TCP (ACK, window, gửi lại, FIN/RST), phân quyền chuyển lên ứng dụng. Container dùng chung kernel host, WSL2 có kernel riêng.

Xem thêm: [apache-fpm-setup.md](apache-fpm-setup.md), [request-flow.md](request-flow.md), [../../05-http-raw/work/http-connection.md](../../05-http-raw/work/http-connection.md), [../../10-docker/work/docker-architecture.md](../../10-docker/work/docker-architecture.md).
