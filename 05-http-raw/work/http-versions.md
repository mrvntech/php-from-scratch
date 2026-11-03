# Các phiên bản HTTP và nên dùng cho việc gì

## Semantics giống nhau, chỉ cách truyền khác nhau

Từ HTTP/1.1 tới HTTP/3, **ý nghĩa** không đổi: method, status code, header, body (tách riêng thành RFC 9110 *HTTP Semantics*, 2022). Mỗi phiên bản chỉ đổi **cách đóng gói byte và gửi qua mạng**. Code ứng dụng gần như không cần biết. Xem phiên bản bằng `$_SERVER['SERVER_PROTOCOL']` hoặc `request.getProtocol()`.

```
                 ┌────────── Semantics (RFC 9110): method, status, header ──────────┐
  HTTP/1.1       │  text: "GET / HTTP/1.1\r\nHost: ..."                               │  TCP  (+ TLS tuỳ chọn)
  HTTP/2         │  binary frame, nhiều stream song song trên 1 kết nối               │  TCP  + TLS (trình duyệt bắt buộc)
  HTTP/3         │  binary frame trên QUIC                                            │  UDP  + TLS 1.3 nằm sẵn trong QUIC
                 └────────────────────────────────────────────────────────────────────┘
```

Máy hiện tại (2026-11-04): `curl` 8.18 có **HTTP/2** (nghttp2), **không có HTTP/3**. Extension `curl` của PHP chưa cài.

## HTTP/0.9 (1991) và HTTP/1.0 (1996)
- 0.9: một dòng `GET /page`, không header, trả HTML rồi đóng.
- 1.0: thêm header, status code, `Content-Type`, `POST`.
- **Mỗi request một kết nối** (mặc định `close`).
- **Không bắt buộc `Host`**: mỗi IP một website.
- Ngày nay: công cụ cũ, và **`proxy_pass` của Nginx** (mặc định nói 1.0 với backend).

## HTTP/1.1 (1997, RFC 9112 năm 2022)
Thêm:
- **Keep-alive mặc định**.
- **`Host` bắt buộc** → virtual host (`ServerName`, `server_name`).
- **`Transfer-Encoding: chunked`** (`docker logs -f`).
- Cache (`ETag`, `Cache-Control`), `Range`, `100-continue`, **`Upgrade`** (WebSocket, Docker hijack).

Vướng:
- **Mỗi kết nối một request tại một thời điểm.** Pipelining gần như không dùng được, nên trình duyệt mở ~6 kết nối mỗi domain.
- **Header text, lặp lại mỗi request** (cookie, user-agent).

## HTTP/2 (2015, RFC 9113)
- **Binary framing**: frame `HEADERS`, `DATA`, `SETTINGS`, `PING`...
- **Multiplexing**: nhiều stream song song trên **một** kết nối TCP, mỗi frame mang stream ID (giống `requestId` của FastCGI, nhưng thật sự được dùng).
- **HPACK**: nén header.
- *Pseudo-header* `:method`, `:path`, `:scheme`, `:authority` thay request line.
- *Server push* đã bị trình duyệt bỏ (Chrome gỡ năm 2022).

```
HTTP/1.1, 3 file, 3 kết nối:             HTTP/2, 3 file, 1 kết nối:
conn1: [req a][resp a.........]          ┌ [H a][H b][H c][D a][D b][D a][D c][D b]... ┐
conn2: [req b][resp b....]               └ frame của các stream đan xen trên 1 TCP    ┘
conn3: [req c][resp c..........]
```
Vướng:
- **Head-of-line blocking ở tầng TCP**: một gói mất làm đứng **mọi** stream tới khi gửi lại xong. Trên 4G chập chờn có lúc tệ hơn 1.1.
- Trình duyệt chỉ dùng qua TLS (`h2`, thương lượng bằng **ALPN**). `h2c` (không mã hoá) chỉ dùng nội bộ.

## HTTP/3 (2022, RFC 9114) trên QUIC (RFC 9000)
- **Bỏ TCP, chạy trên UDP.** QUIC tự lo gửi lại gói, thứ tự, tắc nghẽn, nhưng **theo từng stream**: mất gói ở stream A không chặn stream B.
- **TLS 1.3 nằm sẵn trong QUIC**: kết nối và mã hoá trong **1 RTT** (resume: **0-RTT**).
- **Connection migration**: định danh bằng *connection ID*, chuyển Wi-Fi sang 4G không đứt.
- **QPACK**: nén header cho QUIC.
- QUIC chạy ở **user space**: phần lớn việc quản lý "ống" chuyển từ kernel lên chương trình.

Vướng:
- Một số firewall chặn UDP 443, phải quay về HTTP/2.
- Tốn CPU hơn, công cụ debug ít hơn.
- Trình duyệt đi HTTP/2 trước, thấy `Alt-Svc: h3=":443"` (hoặc DNS record `HTTPS`) thì lần sau mới thử HTTP/3.

## So sánh nhanh
| | HTTP/1.1 | HTTP/2 | HTTP/3 |
|---|---|---|---|
| Transport | TCP | TCP | **QUIC trên UDP** |
| Định dạng | Text | Binary frame | Binary frame |
| Song song trên 1 kết nối | Không | **Có** (stream) | **Có** (stream độc lập) |
| Mất gói ảnh hưởng | Kết nối đó | **Mọi stream** | Chỉ stream có gói mất |
| Nén header | Không | HPACK | QPACK |
| TLS | Tuỳ chọn | Trình duyệt bắt buộc | **Luôn có** (TLS 1.3) |
| Bắt tay kết nối mới | TCP 1 RTT + TLS 1–2 RTT | Như 1.1 | **1 RTT** (resume 0) |
| Đổi mạng | Đứt | Đứt | **Giữ được** |
| Dễ debug | **Rất dễ** (`curl -v`, `nc`) | Cần công cụ | Khó nhất |
| WebSocket | RFC 6455 | RFC 8441 (Extended CONNECT) | RFC 9220 |

## Nên dùng cho việc gì

Không chọn một loại cho cả hệ thống, **chọn theo từng chặng**:
```
Trình duyệt / app mobile ──HTTP/2 + HTTP/3──► CDN / Nginx (TLS termination)
                                                 ├──HTTP/1.1 keep-alive──► Tomcat / Node / Go
                                                 ├──FastCGI─────────────► PHP-FPM      (không phải HTTP)
                                                 └──HTTP/2 (h2c)────────► dịch vụ gRPC
Service A ──gRPC (HTTP/2)──► Service B
```

| Tình huống | Nên dùng | Lý do |
|---|---|---|
| Website, API công khai, **trình duyệt → edge** | **HTTP/2, bật thêm HTTP/3** | Multiplexing, mobile hưởng lợi từ HTTP/3. Bật ở Nginx/CDN, không sửa code |
| **App mobile**, mạng chập chờn, hay di chuyển | **HTTP/3** (fallback HTTP/2) | Mất gói không chặn mọi stream, đổi mạng không đứt, bắt tay nhanh |
| **Reverse proxy → backend** | **HTTP/1.1 + keepalive** | Mạng nội bộ nhanh, ít mất gói, dễ debug. Nhớ `proxy_http_version 1.1` |
| Nginx → PHP | **FastCGI** | FPM không nói HTTP |
| Microservice nội bộ | **gRPC (HTTP/2)** hoặc HTTP/1.1 + pool | gRPC cần HTTP/2 |
| Server gọi API ngoài | **HTTP/2** nếu client hỗ trợ, không thì 1.1 + pool | Nhiều request trên một kết nối, ít bắt tay TLS |
| Đẩy sự kiện một chiều | **SSE** trên HTTP/2 | Đơn giản, tránh giới hạn 6 kết nối mỗi domain |
| Hai chiều thời gian thực | **WebSocket** | Xem [http-connection.md](http-connection.md) |
| Học, debug, script, IoT đơn giản | **HTTP/1.1** | Đọc được bằng mắt, tự viết bằng socket được |
| HTTP/1.0 | Hầu như không | Chỉ để tương thích |

## Hỗ trợ trong các công cụ
| | HTTP/2 | HTTP/3 |
|---|---|---|
| Nginx | `listen 443 ssl; http2 on;` | `listen 443 quic reuseport;` + `Alt-Svc` (`ngx_http_v3_module`, từ 1.25) |
| Apache | `mod_http2`, `Protocols h2 http/1.1` (không chạy với prefork: thêm lý do bỏ mod_php) | Chưa chính thức |
| Caddy / FrankenPHP | Bật sẵn | **Bật sẵn** |
| Tomcat | `<UpgradeProtocol className="org.apache.coyote.http2.Http2Protocol"/>` | Chưa |
| Spring Boot | `server.http2.enabled=true` | Chưa, đặt sau proxy |
| Java `HttpClient` | **Mặc định ưu tiên HTTP/2** | JDK gần đây (JEP 517) |
| PHP curl / Guzzle | `CURL_HTTP_VERSION_2TLS`, cần libcurl có nghttp2 | Khi libcurl build kèm HTTP/3 |
| `curl` trên máy | Có (`--http2`) | Không |

Thực hành:
- **Phase 05**: tự viết server HTTP/1.1 bằng socket (đọc được bằng mắt).
- **Phase 06**: có TLS rồi thì bật `http2 on;`, dùng `curl -v --http2 https://php.local` để thấy ALPN chọn `h2`, rồi xem `$_SERVER['SERVER_PROTOCOL']` thành `HTTP/2.0` dù FPM vẫn nhận qua FastCGI.

## Tóm lại
- **HTTP/1.1**: đơn giản, dễ debug, hợp chặng nội bộ.
- **HTTP/2**: nhiều request song song trên một kết nối, hợp trình duyệt → server và gRPC.
- **HTTP/3**: QUIC trên UDP, mất gói không làm đứng mọi stream, giữ kết nối khi đổi mạng. Hợp mobile, mạng kém. Bật thêm bên cạnh HTTP/2.
- Semantics không đổi, nên chọn phiên bản thường là việc **cấu hình** Nginx, CDN, thư viện client, không phải sửa code.
