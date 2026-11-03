# HTTP trên socket: client, keep-alive, đóng kết nối, WebSocket, tầng 7

Nền tảng về socket (đường dẫn/object/fd, listen/accept, blocking read, epoll): xem [socket.md](../../06-web-server/work/socket.md).

## 1. Kernel lo tầng 4, chương trình lo tầng 7

**Kernel không hiểu HTTP hay WebSocket.** Nó chỉ xử lý tới TCP, rồi đưa lên một **luồng byte không có ý nghĩa**. Việc parse HTTP, WebSocket, FastCGI là việc của **chương trình ở user space**.

Demo: gửi HTTP hợp lệ và byte rác tới `php -S`:
```
HTTP hợp lệ: kernel connect OK, send 27 byte OK → ứng dụng trả: b'HTTP/1.1 200 OK'
byte rác   : kernel connect OK, send 37 byte OK → ứng dụng trả: b''   (ứng dụng đóng kết nối)
```
Với kernel, hai trường hợp giống hệt nhau. Chỉ chương trình PHP, khi parse, mới biết dãy thứ hai không phải HTTP.

```
                         ┌──────────── KERNEL ────────────────────────────────────┐
Card mạng (NIC)          │                                                        │
 ─ tầng 1, 2 ─►  driver ─┤► IP (tầng 3): kiểm tra IP đích, ghép mảnh              │
                         │  ► TCP (tầng 4): tra 4-tuple → tìm đúng SOCKET         │
                         │    thứ tự, ACK, gửi lại gói mất → LUỒNG BYTE liên tục  │
                         │  ► chép vào buffer nhận của socket                     │
                         │  ► đánh thức process đang ngủ trong read()/epoll_wait  │
                         └───────────────────────────────┬────────────────────────┘
                                                         │ read(fd) → byte thô
                         ┌──────────── USER SPACE ───────▼────────────────────────┐
                         │ (TLS: OpenSSL giải mã, nếu là https)                   │
                         │ Tầng 7: chương trình PARSE byte                        │
                         │   "GET /chat HTTP/1.1\r\n..."  → đây là HTTP           │
                         │   có "Upgrade: websocket"      → trả 101, đổi parser   │
                         │   từ giờ: đọc theo định dạng WebSocket frame           │
                         └────────────────────────────────────────────────────────┘
```

| Tầng | Ai xử lý | Làm gì |
|---|---|---|
| 1–2 | Card mạng + driver | Nhận khung dữ liệu |
| 3 (IP) | **Kernel** | Định tuyến, ghép mảnh |
| 4 (TCP/UDP) | **Kernel** | Tìm socket theo 4-tuple, thứ tự, tin cậy, buffer |
| (5–6) | **Thư viện user space** | TLS (OpenSSL) |
| **7** (HTTP, WebSocket, FastCGI) | **Chương trình** | Parse, route, xử lý, trả lời |

- Ranh giới là **system call `read()`**: phía dưới trả byte, phía trên gán ý nghĩa.
- **Port không quyết định giao thức.** Kernel dùng port để biết gửi byte cho socket nào. "Port 80 là HTTP" chỉ là quy ước.
- **Upgrade sang WebSocket diễn ra hoàn toàn trong chương trình**: kernel thấy cùng một kết nối TCP trước và sau. Chương trình chỉ đổi một biến trạng thái của kết nối (HTTP → WEBSOCKET) rồi đổi parser.

| Chương trình | Parser tầng 7 |
|---|---|
| Nginx | `ngx_http_parse_request_line()`, module HTTP/2, HTTP/3 |
| Tomcat | `Http11InputBuffer` (Coyote), `WsFrameServer` (WebSocket) |
| dockerd | `net/http` của Go |
| PHP-FPM | Parser **FastCGI** (Nginx đã đọc HTTP thay) |
| `php -S` | Parser HTTP trong SAPI cli-server |
| Code phase 05 | Chính code PHP tự viết: `fread` rồi cắt `\r\n` |

Ngoại lệ (không đổi nguyên tắc): **kTLS** (kernel mã hoá TLS, vẫn không hiểu HTTP), **eBPF/XDP**, iptables `string` match (soi byte để lọc), **QUIC** (tầng vận chuyển cũng lên user space). Web server trong kernel (khttpd, TUX) từng có và đã bị gỡ.

## 2. HTTP client trên TCP socket

Script: [httpclient.php](httpclient.php).
```php
$fd = stream_socket_client('tcp://127.0.0.1:8099', ...);   // socket() + connect(): bắt tay TCP
fwrite($fd, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");   // write request (text thuần)
// đọc tới "\r\n\r\n" → hết header
// có Content-Length → đọc đủ số byte; không có → đọc tới EOF
```
```
HTTP/1.1 200 OK
Connection: close
Content-type: text/html; charset=UTF-8

cách xác định độ dài: đọc tới EOF (server đóng kết nối)
body: 20000 byte, sau 3 lần fread
```
- 20 KB tới qua **3 lần `fread`**: phải lặp để gom cho đủ. Mỗi vòng mà chưa có dữ liệu thì process ngủ.
- Bản đầu giả định luôn có `Content-Length` nên lỗi: `php -S` báo hết body bằng cách **đóng kết nối**. Client thật phải xử lý cả ba cách báo độ dài.

### Các bước
```
client.get("https://api.example.com/users")
 ├─ 1. DNS: api.example.com → IP                       (resolver, UDP :53, /etc/hosts)
 ├─ 2. socket(AF_INET, SOCK_STREAM) → fd
 ├─ 3. connect(fd, IP:443)       KERNEL: SYN → SYN-ACK → ACK, process ngủ trong lúc chờ
 ├─ 4. TLS handshake (https)     ← THƯ VIỆN user space (OpenSSL), không phải kernel
 ├─ 5. write: "GET /users HTTP/1.1\r\nHost: ...\r\n\r\n"
 ├─ 6. read: ngủ tới khi có byte → parse status line + header
 ├─ 7. read body tới khi ĐỦ:
 │       Content-Length: 1234        → đọc đúng 1234 byte
 │       Transfer-Encoding: chunked  → từng khúc "<size hex>\r\n<data>\r\n" tới khúc 0
 │       không có cả hai             → đọc tới EOF
 └─ 8. giữ kết nối (keep-alive) hoặc close()
```

| Việc | Ai làm |
|---|---|
| Bắt tay TCP, gửi lại gói, thứ tự, ACK | **Kernel** |
| DNS, TLS, viết request, parse response, xác định hết body | **Thư viện HTTP client** |
| Đánh thức process khi có dữ liệu | **Kernel** |

### Timeout: bắt buộc phải đặt
Server treo, không gửi gì, thì process **ngủ mãi** trong `read()`.

| Timeout | Áp dụng | PHP | Java `HttpClient` |
|---|---|---|---|
| Connect | Bước 3 | `stream_socket_client(..., timeout:)`, `CURLOPT_CONNECTTIMEOUT` | `HttpClient.newBuilder().connectTimeout(...)` |
| Read / request | Bước 6–7 | `stream_set_timeout()`, `CURLOPT_TIMEOUT` | `HttpRequest.newBuilder().timeout(...)` |

Thiếu timeout gây **hết worker**: mỗi request PHP gọi API ngoài đang treo giữ chặt một worker FPM. Hết `pm.max_children` thì cả site 502/504. Tomcat thì hết `maxThreads`.

### Client thường gặp chờ dữ liệu thế nào
| Client | Cách chờ |
|---|---|
| `file_get_contents('http://...')`, `fread` | Blocking |
| PHP curl, Guzzle đồng bộ | Blocking. `curl_multi` / Guzzle `Pool` để song song (poll/epoll) |
| `curl` (lệnh) | Non-blocking + poll |
| Java `HttpURLConnection` | Blocking, mỗi request một thread |
| Java `HttpClient` (JDK 11+) | `send()` blocking; `sendAsync()` → `CompletableFuture`, NIO `Selector`. Hỗ trợ HTTP/2 |
| Java virtual thread | Viết blocking, JVM dùng epoll |
| Go `net/http` (docker CLI) | Trông blocking, runtime dùng epoll |
| Trình duyệt, Node.js | Event loop + epoll/kqueue |

Docker CLI → `docker.sock` và HTTP client → server trên mạng có **phần HTTP giống hệt nhau**. Docker CLI chính là một HTTP client, chỉ `connect` tới đường dẫn file thay vì IP:port.

## 3. Keep-alive: kết nối được giữ, request/response đi qua lại

HTTP/1.1 **mặc định giữ kết nối**. Request và response tiếp theo đi qua **đúng cặp socket đó**, không phải `connect` hay bắt tay TLS lại.

Kiểm chứng: một kết nối tới `docker.sock`, nhiều request:
```
fd của kết nối: 3
request 1: ('HTTP/1.1 200 OK', b'OK')               ← GET /_ping
... 2 giây không gửi gì, kết nối vẫn mở ...
request 2: ('HTTP/1.1 200 OK', b'[]\n')             ← GET /containers/json, vẫn fd 3
```
(Request 3 `/info` làm script lỗi vì response dạng `chunked`, script chỉ đọc theo `Content-Length`: client phải hiểu giao thức mới biết khi nào đủ.)

`curl` với hai URL trong một lệnh:
```
tới dockerd:  * Connection #0 ... left intact  (x2)   ← dùng lại kết nối
tới php -S:   < Connection: close  (x2)               ← mỗi request một kết nối mới
```

```
Client                                     Server
  │── SYN / SYN-ACK / ACK ─────────────────►│   bắt tay TCP (1 lần)
  │══ TLS handshake (nếu https, 1 lần) ═════│
  │── GET /a ──────────────────────────────►│
  │◄─────────────────────────── 200 + body ─│   request 1
  │        (rảnh: KHÔNG có gói nào đi qua)   │   kết nối chỉ là trạng thái trong kernel hai bên
  │── GET /b ──────────────────────────────►│
  │◄─────────────────────────── 200 + body ─│   request 2, cùng kết nối
  │── FIN ─────────────────────────────────►│   một bên đóng
```
- **Tầng HTTP là message, tầng TCP mới là gói tin.** Chương trình chỉ thấy luồng byte liên tục. Kernel cắt thành TCP segment rồi ghép lại. Ranh giới message do HTTP xác định.
- **Rảnh thì không gửi gì.** Trừ TCP keepalive (Linux mặc định sau 2 giờ) hoặc HTTP/2 PING.
- **Không sống mãi**:

| Bên | Tham số | Mặc định |
|---|---|---|
| Nginx | `keepalive_timeout` / `keepalive_requests` | 75 giây / 1000 |
| Apache | `KeepAliveTimeout` / `MaxKeepAliveRequests` | 5 giây / 100 |
| Tomcat | `keepAliveTimeout` / `maxKeepAliveRequests` | bằng `connectionTimeout` (20 giây trong `server.xml` mẫu) / 100 |
| Client pool | idle timeout | Tuỳ thư viện |
| NAT, firewall, LB | | Có thể xoá kết nối rảnh sau vài phút |

**Bẫy**: server hoặc firewall đóng kết nối rảnh mà client không biết. Client lấy ra từ pool để gửi thì nhận RST/EOF (`connection reset by peer`, `broken pipe`). Thư viện tốt tự thử lại (chỉ với request idempotent như GET). Idle timeout của client pool nên **ngắn hơn** keepalive timeout của server.

Connection pool: Java `HttpClient`, Apache HttpClient, Guzzle (curl handle), trình duyệt (~6 kết nối mỗi host với HTTP/1.1). HTTP/1.1 mỗi kết nối chỉ một request tại một thời điểm. HTTP/2 nhiều stream song song.

## 4. Vì sao kết nối hay bị đóng

### REST stateless ≠ không giữ kết nối
| Khái niệm | Tầng | Nghĩa |
|---|---|---|
| **REST stateless** | Ứng dụng | Mỗi request tự chứa đủ thông tin, server không nhớ phiên |
| **Keep-alive / close** | Kết nối TCP | Socket có được dùng lại không |

Hai khái niệm độc lập: REST stateless vẫn chạy tốt trên kết nối keep-alive.

### Cơ chế đóng
```
bên muốn đóng:  gửi "Connection: close" (báo trước) → ghi xong response → close(fd)
KERNEL:         FIN ───────►  bên kia read() nhận EOF → close(fd) → FIN
                ◄─────── FIN
                bên đóng TRƯỚC vào TIME_WAIT (Linux 60 giây)
```

### Các lý do
**a. Client không dùng lại kết nối** (hay gặp nhất)
- **PHP-FPM share-nothing**: tới `RSHUTDOWN` thì curl handle và connection pool của Guzzle bị huỷ. Request PHP sau phải `connect` + TLS lại từ đầu.
- Tạo client mới mỗi lần (`new GuzzleHttp\Client()` trong mỗi hàm, `HttpClient.newHttpClient()` mỗi request).
- **Không đọc hết body**: kết nối chỉ về pool khi đọc xong response (phải tới cuối message mới biết request sau bắt đầu ở byte nào).
- `file_get_contents('http://...')` mặc định gửi `Connection: close`.
- Java giữ `HttpClient` **singleton** trong heap nên tái sử dụng tự nhiên. PHP-FPM không làm được, trừ FrankenPHP worker mode, RoadRunner.

**b. Server không xác định được ranh giới message**
- Response không có `Content-Length` và không `chunked` (như `php -S`).
- Client gửi body mà server không đọc hết (trả 413/401 sớm).
- Request lỗi cú pháp.
- HTTP/1.0 (mặc định đóng).

**c. Kết nối rảnh tốn tài nguyên server**
| Mô hình | Một kết nối keep-alive rảnh tốn |
|---|---|
| Apache **prefork** | **Nguyên một process**, nên `KeepAliveTimeout` chỉ 5 giây |
| Nginx, Apache event, Tomcat NIO | Vài KB (epoll / Poller giữ) |

**d. Cân bằng tải**: kết nối sống lâu dính vào một backend. Giới hạn request mỗi kết nối buộc client kết nối lại để chia tải đều.

**e. Proxy mặc định đóng với backend**
```nginx
upstream app { server 127.0.0.1:8080; keepalive 32; }
location / {
    proxy_pass http://app;
    proxy_http_version 1.1;              # mặc định 1.0 + "Connection: close"
    proxy_set_header Connection "";
}
```
Nginx → FPM cũng đóng sau mỗi request, trừ khi có `fastcgi_keep_conn on` + `upstream { keepalive N; }`.

### Cái giá
1. **Độ trễ**: TCP 1 RTT + TLS 1.3 thêm 1 RTT (TLS 1.2: 2 RTT). RTT 50 ms nghĩa là thêm 100 ms trước byte request đầu tiên.
2. **CPU**: TLS handshake.
3. **TIME_WAIT**: demo 7 kết nối ngắn tới `php -S` để lại **7** socket TIME_WAIT (`ss -tan state time-wait`). Client đóng hàng nghìn kết nối mỗi giây tới cùng IP:port có thể **cạn ephemeral port** (~28 nghìn, `net.ipv4.ip_local_port_range`): `Cannot assign requested address`.
4. **TCP slow start**: kết nối mới gửi chậm rồi tăng dần.

Đóng ngay hợp lý khi: gọi một lần (cron, CLI), lưu lượng rất thấp, muốn phân tải tuyệt đối, server dạng một process mỗi kết nối.

## 5. WebSocket

HTTP/1.1 **mặc định giữ kết nối** (chỉ HTTP/1.0 mới mặc định đóng). Khác biệt thật của WebSocket là **ai được gửi, gửi lúc nào**.

| | HTTP/1.1 (keep-alive) | WebSocket |
|---|---|---|
| Kết nối | Giữ, nhưng rảnh quá timeout thì server đóng | Giữ **lâu dài có chủ đích** |
| Ai được gửi | **Client hỏi, server trả lời.** Server không tự gửi được | **Cả hai bất cứ lúc nào** (full-duplex) |
| Thứ tự | Request → response → request | Message bay hai chiều, không có cặp hỏi-đáp |
| Đơn vị | HTTP message | **Frame**, header 2–14 byte |

### Bắt tay bằng HTTP rồi chiếm socket (giống Docker hijack)
```http
GET /chat HTTP/1.1
Host: example.com
Connection: Upgrade
Upgrade: websocket
Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==
Sec-WebSocket-Version: 13

HTTP/1.1 101 Switching Protocols
Connection: Upgrade
Upgrade: websocket
Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=
```
Sau `101`, socket không còn nói HTTP, hai bên trao đổi frame:
```
┌─────┬────────┬──────┬─────────────┬──────────────┬──────────┐
│ FIN │ opcode │ MASK │ payload len │ masking key  │ payload  │
│ 1b  │ 4b     │ 1b   │ 7/16/64 bit │ 4 byte (nếu  │          │
│     │        │      │             │ client gửi)  │          │
└─────┴────────┴──────┴─────────────┴──────────────┴──────────┘
opcode: 1 = text, 2 = binary, 8 = close, 9 = ping, 10 = pong
```
Frame có trường độ dài, giống header 8 byte của FastCGI và Docker stream.

| | Docker `attach` | WebSocket |
|---|---|---|
| Bắt tay | `Upgrade: tcp` → `101 UPGRADED` | `Upgrade: websocket` → `101 Switching Protocols` |
| Sau upgrade | Byte thô (header 8 byte nếu không TTY) | Frame có opcode và độ dài |
| Chuẩn | Riêng của Docker | RFC 6455 |

- **Ping/pong**: phát hiện kết nối chết, giữ NAT/firewall không xoá. **Close frame** (opcode 8) để đóng có chủ đích.
- Vẫn có thể bị cắt (mạng rớt, proxy timeout, deploy), nên client luôn có logic **tự kết nối lại**.

### Ảnh hưởng kiến trúc
- **PHP-FPM không hợp**: mỗi kết nối WebSocket giữ chặt một worker hàng giờ (`pm.max_children = 5` thì 5 người chat là hết). Dùng **Swoole**, **ReactPHP/Ratchet**, **RoadRunner**, hoặc tách service riêng (Node, Go, Mercure).
- **Java hỗ trợ tự nhiên**: Tomcat NIO, kết nối rảnh nằm trong Poller. **Jakarta WebSocket** (`@ServerEndpoint`), **Spring WebSocket**/STOMP. Bên dưới: `HttpUpgradeHandler` (Servlet 3.1).
- **Nginx đứng trước**:
```nginx
location /ws/ {
    proxy_pass http://app;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 1h;          # mặc định 60 giây: rảnh quá thì Nginx cắt
}
```

### Toàn cảnh
| | Kết nối | Server tự đẩy? |
|---|---|---|
| HTTP/1.0 | Đóng sau mỗi request (mặc định) | Không |
| HTTP/1.1 | **Giữ** (mặc định), đóng khi rảnh quá timeout | Không |
| HTTP/2 | Một kết nối, nhiều stream song song | Không (server push đã bị bỏ) |
| Long polling | Server giữ response tới khi có dữ liệu | Gần như, tốn kém |
| SSE (`text/event-stream`) | Một response kéo dài mãi (như `docker logs -f`) | Có, **một chiều** |
| WebSocket | Upgrade, giữ lâu dài | **Có, hai chiều** |

Xem thêm: [http-versions.md](http-versions.md), [../../10-docker/work/docker-architecture.md](../../10-docker/work/docker-architecture.md).
