# Hành trình của một HTTP request: Apache, Nginx, PHP-FPM và Tomcat

Ví dụ dùng xuyên suốt:
```http
POST /todos?page=2 HTTP/1.1
Host: php.local
Cookie: PHPSESSID=abc
Content-Type: application/x-www-form-urlencoded
Content-Length: 15

title=Hoc+PHP&x
```

---

## 1. Kiến trúc Apache httpd

### MPM (Multi-Processing Module): mô hình đồng thời, mỗi lần chỉ bật một

```
prefork                          worker                          event (mặc định hiện nay)
parent (root, bind :80)          parent (root)                   parent (root)
 ├─ child process  ← 1 kết nối    ├─ child process                ├─ child process
 ├─ child process  ← 1 kết nối    │   ├─ thread ← 1 kết nối       │   ├─ listener thread (epoll)
 └─ child process  ← 1 kết nối    │   └─ thread ← 1 kết nối       │   │   giữ keep-alive rảnh
                                  └─ child process ...            │   ├─ worker thread ← request
   không dùng thread                                              │   └─ worker thread ← request
   → bắt buộc cho mod_php (NTS)                                   └─ child process ...
```
- **prefork**: mỗi process giữ chặt một kết nối, kể cả keep-alive rảnh. An toàn cho thư viện không thread-safe, nhưng tốn RAM.
- **event**: listener thread dùng `epoll` giữ keep-alive. Worker thread chỉ bị chiếm khi có request thật. Ý tưởng giống Poller + Executor của Tomcat.

### Hook và filter
Mỗi module đăng ký **hook** vào phase nó quan tâm:
```
post_read_request → translate_name (URL → file, mod_rewrite, Alias)
→ map_to_storage (<Directory>, đọc .htaccess)
→ access_checker / check_user_id / auth_checker (Require, auth)
→ type_checker (chọn handler: SetHandler, AddHandler)
→ fixups → HANDLER (file tĩnh, mod_php, mod_proxy_fcgi...)
→ log_transaction (access log)
```
Dữ liệu vào và ra đi qua **filter chain** (`mod_deflate`...) dưới dạng **bucket brigade**.

---

## 2. Kiến trúc Nginx

```
nginx: master process (root)        ← đọc nginx.conf, bind :80/:443, quản lý worker, reload
 ├─ worker process (www-data)       ← MỘT thread, một event loop (epoll), non-blocking
 ├─ worker process                  ← số lượng = worker_processes (thường = số CPU core)
 └─ worker process                  ← mỗi worker giữ hàng nghìn kết nối
```
- **Event-driven, non-blocking**: worker không bao giờ chờ. Socket nào sẵn sàng thì xử lý một chút rồi chuyển sang socket khác.
- **Nginx không chạy code ứng dụng.** Nó phục vụ file tĩnh, còn phần động thì chuyển cho **upstream** (`ngx_http_fastcgi_module` tới FPM, `ngx_http_proxy_module` tới Tomcat).

Các phase:
```
POST_READ → SERVER_REWRITE → FIND_CONFIG (chọn location) → REWRITE → POST_REWRITE
→ PREACCESS (limit_req) → ACCESS (allow/deny, auth_basic) → POST_ACCESS
→ PRECONTENT (try_files) → CONTENT (static / fastcgi_pass / proxy_pass) → LOG
```

---

## 3. Nginx → FastCGI → PHP-FPM → SAPI → response

### Bước 1: Client → Nginx worker
```
client ──TCP :80──► kernel (listen backlog) ──► một nginx worker (accept, epoll)
```
1. Parse HTTP: request line, header, `Host` để chọn `server {}`.
2. **FIND_CONFIG**: `/todos` rơi vào `location /`.
3. **PRECONTENT**: `try_files $uri $uri/ /index.php?$query_string`. Không có file `/todos`, nên Nginx **internal redirect** sang `/index.php?page=2`, rồi khớp vào `location ~ \.php$`.
4. **Đọc hết body trước** (`fastcgi_request_buffering on`), vào RAM hoặc file tạm. Client upload chậm sẽ không chiếm worker FPM.

### Bước 2: HTTP → FastCGI PARAMS

| Nguồn trong HTTP | FastCGI PARAM | Ai đặt |
|---|---|---|
| Method | `REQUEST_METHOD=POST` | `fastcgi_params` |
| URL gốc | `REQUEST_URI=/todos?page=2` | `fastcgi_params` |
| Query | `QUERY_STRING=page=2` | `fastcgi_params` |
| File cần chạy | `SCRIPT_FILENAME=/var/www/site/public/index.php` | **Tự đặt** (`$document_root$fastcgi_script_name`) |
| Body | `CONTENT_TYPE`, `CONTENT_LENGTH` | `fastcgi_params` |
| Mọi header | `HTTP_HOST`, `HTTP_COOKIE`, `HTTP_USER_AGENT`... | Nginx tự thêm (viết hoa, `-` thành `_`, tiền tố `HTTP_`) |
| Kết nối | `REMOTE_ADDR`, `SERVER_PORT`, `HTTPS`... | `fastcgi_params` |

Chuỗi record gửi qua socket:
```
BEGIN_REQUEST  (requestId=1, role=RESPONDER)
PARAMS         REQUEST_METHOD=POST, SCRIPT_FILENAME=..., HTTP_COOKIE=PHPSESSID=abc, ...
PARAMS         (rỗng = hết PARAMS)
STDIN          title=Hoc+PHP&x
STDIN          (rỗng = hết body)
```

### Bước 3: FPM worker, SAPI `fpm-fcgi`, Zend Engine
```
FPM worker đang chờ accept() → nhận kết nối từ Nginx
  ├─ đọc BEGIN_REQUEST + PARAMS
  ├─ php_request_startup()                         ← RINIT mọi extension
  │     PARAMS        → $_SERVER
  │     QUERY_STRING  → $_GET
  │     HTTP_COOKIE   → $_COOKIE
  │     STDIN         → $_POST / $_FILES (multipart → upload_tmp_dir) / php://input
  ├─ php_execute_script(SCRIPT_FILENAME)
  │     OPcache có opcode? dùng luôn : compile → opcode
  │     Zend VM thực thi: router → controller → ...
  │       header(...) → lưu vào danh sách header của SAPI, CHƯA gửi
  │       echo        → output buffer
  └─ php_request_shutdown()                        ← flush, RSHUTDOWN, dọn sạch state
```

SAPI là tập **callback** (`sapi_module_struct`) mà engine gọi khi cần giao tiếp ra ngoài:

| Callback | Khi nào | `fpm-fcgi` làm gì |
|---|---|---|
| `register_server_variables` | Đầu request | PARAMS → `$_SERVER` |
| `read_cookies` | Đầu request | Trả về `HTTP_COOKIE` |
| `read_post` | Cần đọc body | Đọc record STDIN |
| `send_headers` | Lần flush output **đầu tiên** | Ghi `Status:` + header vào STDOUT |
| `ub_write` | `echo`, output | Ghi vào STDOUT |
| `flush` | `flush()`, cuối request | Đẩy record ra socket |
| `log_message` | Lỗi, `error_log()` | STDERR hoặc error_log của FPM |

**"headers already sent"**: header chỉ được gửi một lần, ở lần flush đầu tiên. `output_buffering = 4096` giúp hoãn lần flush đó.

### Bước 4: FPM → Nginx: response kiểu CGI
```
STDOUT   "Status: 302 Found\r\n
          Location: /todos\r\n
          Set-Cookie: PHPSESSID=abc; HttpOnly\r\n
          Content-type: text/html; charset=UTF-8\r\n
          \r\n
          <html>..."
STDERR   "PHP Warning: ..."            ← Nginx ghi vào error_log
END_REQUEST (appStatus=0, protocolStatus=REQUEST_COMPLETE)
```
**Không có dòng `HTTP/1.1 302 Found`**: PHP không biết HTTP, nó chỉ trả header `Status:` theo chuẩn CGI. Sau `END_REQUEST`, worker quay lại `accept()`.

### Bước 5: Nginx → client: FastCGI thành HTTP
1. `Status:` → status line `HTTP/1.1 302 Found`. Header đặc biệt (`X-Accel-Redirect`) do Nginx tự xử lý.
2. Thêm `Server`, `Date`, `Connection: keep-alive`. Chưa có `Content-Length` thì dùng `Transfer-Encoding: chunked`.
3. Output filter: `gzip`, `add_header`.
4. **Buffering** (`fastcgi_buffering on`): nhận hết response từ FPM trước, nên FPM được giải phóng sớm. Sau đó Nginx gửi dần cho client.
5. Ghi access log. Giữ keep-alive với client. Kết nối với FPM thì đóng, trừ khi bật `fastcgi_keep_conn on`.

### Toàn cảnh
```
Client          Nginx worker (event loop)              PHP-FPM worker (process)
  │  HTTP req       │                                       │ (đang chờ accept)
  │────────────────►│ parse HTTP, chọn server/location     │
  │                 │ try_files → /index.php                │
  │                 │ đọc hết body (buffer)                 │
  │                 │ HTTP → PARAMS + STDIN                 │
  │                 │──── FastCGI qua unix socket ─────────►│ PARAMS → $_SERVER, $_GET, $_COOKIE
  │                 │                                       │ STDIN  → $_POST
  │                 │   (worker Nginx đi phục vụ            │ RINIT → index.php → RSHUTDOWN
  │                 │    kết nối khác, không chờ)           │
  │                 │◄─── STDOUT (Status + header + body) ──│
  │                 │◄─── END_REQUEST ──────────────────────│ → quay lại accept()
  │                 │ Status: → HTTP status line            │
  │                 │ thêm header, gzip, buffer             │
  │◄────────────────│ HTTP response                         │
  │  (keep-alive)   │ access log                            │
```

---

## 4. Giao thức FastCGI ở mức byte

Mỗi **record** có header 8 byte:
```
┌─────────┬──────┬──────────────┬────────────────┬─────────────┬──────────┐
│ version │ type │ requestId    │ contentLength  │ paddingLen  │ reserved │  + content + padding
│ 1 byte  │ 1    │ 2 byte       │ 2 byte (≤64KB) │ 1           │ 1        │
└─────────┴──────┴──────────────┴────────────────┴─────────────┴──────────┘
```
| Type | Tên | Hướng |
|---|---|---|
| 1 | `BEGIN_REQUEST` | Web server → app |
| 4 | `PARAMS` | Web server → app (name-value, độ dài mã hoá 1 hoặc 4 byte) |
| 5 | `STDIN` | Web server → app (body) |
| 6 | `STDOUT` | App → web server (header CGI + body) |
| 7 | `STDERR` | App → web server (log lỗi) |
| 3 | `END_REQUEST` | App → web server |
| 2 | `ABORT_REQUEST` | Web server → app (client ngắt kết nối) |

`requestId` cho phép multiplex nhiều request trên một kết nối, nhưng **PHP-FPM không dùng**: mỗi kết nối chỉ chở một request tại một thời điểm.

---

## 5. Các đường khác ở phase 06

### Stage B: Apache (event MPM) + PHP-FPM
```
client → Apache child: listener thread (epoll) → worker thread
       → hook: translate_name, map_to_storage, access...
       → type_checker: <FilesMatch \.php$> SetHandler "proxy:unix:/run/php/study.sock|fcgi://localhost"
       → handler mod_proxy_fcgi: HTTP → FastCGI → FPM (bước 3, 4 giống hệt)
       → nhận STDOUT → output filter (mod_deflate...) → client
```
Apache không buffer kỹ như Nginx: worker thread bị chiếm suốt thời gian chờ FPM.

### Stage A: Apache (prefork) + mod_php, không có FastCGI
```
client → Apache child process (prefork)
       → hook như trên
       → handler mod_php (SAPI apache2handler), chạy NGAY TRONG process Apache:
            $_SERVER    ← đọc thẳng từ request_rec (struct C của Apache)
            read_post   ← ap_get_client_block()
            ub_write    ← ap_rwrite() → output filter chain
       → client
```
Không có socket nội bộ, không chuyển đổi giao thức. Đổi lại, PHP chung process với Apache, nên bắt buộc dùng prefork (PHP NTS không an toàn với thread của event MPM).

---

## 6. Java: Tomcat xử lý cùng request đó

### Kiến trúc Tomcat (cấu hình trong `server.xml`)
```
Server
└─ Service "Catalina"
    ├─ Connector (Coyote)  HTTP/1.1 :8080   ← nhận và parse HTTP
    ├─ Connector (Coyote)  AJP :8009        ← tuỳ chọn, cho Apache httpd
    └─ Engine "Catalina"                    ← servlet container (Catalina)
        └─ Host "localhost"                 ← virtual host
            └─ Context "/app"               ← một web application (một WAR)
                └─ Wrapper                  ← bọc một servlet (DispatcherServlet...)
```
Mỗi *container* (Engine, Host, Context, Wrapper) có một **Pipeline** gồm nhiều **Valve** (ví dụ `AccessLogValve`, `RemoteIpValve`). Valve cuối của mỗi tầng là *basic valve*: `StandardEngineValve`, `StandardHostValve`, `StandardContextValve`, `StandardWrapperValve`.

Coyote (connector) chia thành các thành phần:
```
ProtocolHandler (Http11NioProtocol)
 ├─ Endpoint (NioEndpoint)     ← Acceptor, Poller, Executor
 ├─ Processor (Http11Processor) ← parse HTTP/1.1, quản lý keep-alive
 └─ Adapter (CoyoteAdapter)     ← cầu nối Coyote → Catalina
```

### Request đi qua Tomcat
```
client ──TCP :8080──► kernel (backlog = acceptCount)
  │
  ├─ Acceptor thread: ServerSocketChannel.accept() → bọc thành NioChannel
  │                   → đăng ký với Poller
  ├─ Poller thread: Selector.select() → socket đã có dữ liệu
  │                   → tạo SocketProcessor, giao cho Executor
  │
  └─ Worker thread "http-nio-8080-exec-N" (mượn từ thread pool):
       Http11Processor.service()
         Http11InputBuffer parse request line + header → org.apache.coyote.Request
       CoyoteAdapter.service()
         tạo org.apache.catalina.connector.Request/Response
         (ứng dụng chỉ thấy RequestFacade/ResponseFacade, implement HttpServletRequest/Response)
         postParseRequest(): Mapper ánh xạ URL → Host → Context → Wrapper
           (thứ tự url-pattern: exact match → path prefix /api/* → extension *.jsp → default "/")
           đọc session ID từ cookie JSESSIONID
       Engine pipeline → StandardEngineValve
       Host pipeline   → StandardHostValve      (đặt context class loader, xử lý error page)
       Context pipeline→ StandardContextValve   (Authenticator valve nếu có security constraint)
       Wrapper pipeline→ StandardWrapperValve
         allocate() servlet instance (init() một lần, singleton)
         tạo ApplicationFilterChain → Filter.doFilter() lần lượt
         → HttpServlet.service() → doPost()
            với Spring: DispatcherServlet → HandlerMapping → HandlerAdapter
                        → @Controller → HttpMessageConverter / ViewResolver
```

Cách dữ liệu request đến tay ứng dụng:

| Dữ liệu | Java (Servlet API) | Cơ chế |
|---|---|---|
| Header | `request.getHeader("Host")` | Đã parse sẵn bởi Http11InputBuffer |
| Query + form | `request.getParameter("title")` | **Lazy parsing**: lần gọi đầu mới parse query string và body form-urlencoded |
| Body thô | `request.getInputStream()` (`ServletInputStream`) | Đọc thẳng từ socket qua Http11InputBuffer, **không buffer trước** như Nginx |
| Multipart | `request.getParts()` | Cần `@MultipartConfig` / `multipart-config` |
| Cookie | `request.getCookies()` | |
| Session | `request.getSession()` | Tra `JSESSIONID` trong `Manager` của Context (session nằm trong heap) |

Cách response được ghi:
```
response.setStatus(302); response.setHeader("Location", "/todos");
response.getWriter().write("...")
  → ghi vào OutputBuffer của Catalina (bufferSize mặc định 8 KB)
  → khi buffer đầy hoặc flush(): response bị COMMIT
       Http11OutputBuffer ghi status line "HTTP/1.1 302 Found" + header ra socket
       sau commit, setHeader() bị bỏ qua, sendRedirect() ném IllegalStateException
       (tương đương "headers already sent" của PHP)
servlet.service() trả về
  → response.finishResponse(): flush nốt. Không có Content-Length thì dùng chunked
  → Request/Response được recycle() (tái sử dụng object, không tạo mới)
  → keep-alive: socket đăng ký lại với Poller; worker thread trả về pool
```

Khác PHP ở chỗ **Tomcat tự viết status line HTTP**: không có tầng CGI `Status:` ở giữa, vì Coyote chính là HTTP server.

### Sơ đồ thành phần: mọi thứ nằm trong một process JVM
```
┌──────────────────────────────── 1 process JVM (java ... org.apache.catalina.startup.Bootstrap) ─────────────────────────────────┐
│                                                                                                                                 │
│  Service "Catalina"                                                                                                             │
│  ┌──────────────── Connector (Coyote) ──────────────────┐      ┌──────────────── Engine (Catalina) ───────────────────────────┐ │
│  │ Http11NioProtocol                                    │      │ Pipeline: [AccessLogValve] → StandardEngineValve             │ │
│  │                                                      │      │   │                                                          │ │
│  │  NioEndpoint                                         │      │   ▼                                                          │ │
│  │  ┌──────────────┐   ┌──────────────┐                 │      │ Host "localhost"                                             │ │
│  │  │ Acceptor     │──►│ Poller       │                 │      │ Pipeline: [RemoteIpValve] → StandardHostValve                │ │
│  │  │ thread       │   │ thread       │                 │      │   │                                                          │ │
│  │  │ accept()     │   │ Selector     │                 │      │   ▼                                                          │ │
│  │  └──────────────┘   │ .select()    │                 │      │ Context "/app"  (một web app, có class loader riêng)         │ │
│  │                     └──────┬───────┘                 │      │ Pipeline: [Authenticator] → StandardContextValve             │ │
│  │                            │ SocketProcessor         │      │   │            Manager (session trong heap)                  │ │
│  │                            ▼                         │      │   ▼                                                          │ │
│  │  Executor (thread pool, maxThreads=200)              │      │ Wrapper "dispatcher"                                         │ │
│  │  ┌────────┐┌────────┐┌────────┐┌────────┐            │      │ Pipeline: StandardWrapperValve                               │ │
│  │  │exec-1  ││exec-2  ││exec-3  ││ ...    │            │      │   │  ApplicationFilterChain: Filter → Filter → ...           │ │
│  │  └───┬────┘└────────┘└────────┘└────────┘            │      │   ▼                                                          │ │
│  │      │ Http11Processor (parse HTTP)                  │      │ Servlet instance (singleton): DispatcherServlet              │ │
│  │      │ CoyoteAdapter ────────────────────────────────┼─────►│   → HandlerMapping → @Controller → HttpMessageConverter      │ │
│  └──────┼───────────────────────────────────────────────┘      └──────────────────────────────────────────────────────────────┘ │
│         │                                                                                                                       │
│         └─ cùng một worker thread chạy từ lúc parse HTTP tới khi controller trả về                                              │
│                                                                                                                                 │
│  Heap dùng chung: singleton bean, connection pool, cache, HttpSession                                                           │
└─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Toàn cảnh: request đi qua Tomcat (đứng sau Nginx)
```
Client       Nginx worker           Tomcat: Acceptor / Poller          Tomcat: worker thread (exec-N)              Ứng dụng
  │              │                         │                                  │                                     │
  │──HTTP req───►│ parse, chọn location    │                                  │                                     │
  │              │ proxy_pass :8080        │                                  │                                     │
  │              │ thêm X-Forwarded-*      │                                  │                                     │
  │              │──── HTTP/1.1 ──────────►│ Acceptor: accept()               │                                     │
  │              │                         │ Poller: select() → có dữ liệu    │                                     │
  │              │                         │──── SocketProcessor ────────────►│ (mượn thread từ Executor)           │
  │              │                         │                                  │ Http11Processor: parse HTTP         │
  │              │                         │                                  │ CoyoteAdapter: tạo HttpServletRequest│
  │              │                         │                                  │ Mapper: Host → Context → Wrapper    │
  │              │                         │                                  │ Engine → Host → Context → Wrapper   │
  │              │                         │                                  │   valves                            │
  │              │                         │                                  │ FilterChain.doFilter()              │
  │              │                         │                                  │──── servlet.service() ─────────────►│ DispatcherServlet
  │              │                         │                                  │                                     │ → @Controller
  │              │                         │                                  │                                     │ → getParameter() (lazy parse)
  │              │                         │                                  │                                     │ → DB, service...
  │              │                         │                                  │◄─── ghi vào OutputBuffer (8 KB) ────│
  │              │                         │                                  │ commit: "HTTP/1.1 302 Found"        │
  │              │◄─────────────────── HTTP response ─────────────────────────│   + header + body                   │
  │              │                         │                                  │ finishResponse(), recycle()         │
  │              │                         │◄─── socket đăng ký lại (keep-alive)                                    │
  │              │                         │                                  │ → thread trả về pool                │
  │◄─HTTP resp───│ (buffer, gzip, log)     │                                  │                                     │
```
Khi không có Nginx (ví dụ Spring Boot chạy thẳng `:8080`), client nối trực tiếp vào Acceptor. Phần còn lại giữ nguyên.

### Đặt cạnh PHP-FPM
```
Nginx + PHP-FPM: 2 chương trình, 2 lần parse, 1 lần chuyển giao thức

  client ─HTTP─► [ Nginx ] ─FastCGI─► [ FPM master ]
                  parse HTTP            ├─ worker process ── SAPI fpm-fcgi ── Zend Engine ── index.php
                  try_files             ├─ worker process        (PARAMS → superglobal, STDOUT → Status:)
                  buffer body/response  └─ worker process        RSHUTDOWN: dọn sạch

Tomcat: 1 process JVM, 1 lần parse, không chuyển giao thức

  client ─HTTP─► [ JVM: Acceptor → Poller → worker thread ─ Coyote ─ Catalina ─ Filter ─ Servlet ]
                                             (parse HTTP → HttpServletRequest → controller → commit status line)
                                             recycle() request/response, heap vẫn giữ nguyên
```

### Đặt web server phía trước Tomcat
| Cách | Cấu hình | Ghi chú |
|---|---|---|
| Nginx → Tomcat qua **HTTP** | `proxy_pass http://127.0.0.1:8080;` + `proxy_set_header Host`, `X-Forwarded-For`, `X-Forwarded-Proto` | Phổ biến nhất. Bên Tomcat dùng `RemoteIpValve` (Spring Boot: `server.forward-headers-strategy`) để `getRemoteAddr()` và `isSecure()` trả đúng giá trị |
| Apache → Tomcat qua **AJP** | `mod_proxy_ajp`, connector AJP :8009 | Giao thức nhị phân, vai trò giống FastCGI. **Tắt mặc định** từ Tomcat 9.0.31 sau lỗ hổng Ghostcat (CVE-2020-1938), bật lại phải đặt `secret` |
| Spring Boot | Tomcat **nhúng** trong app (`spring-boot-starter-web`) | Cùng kiến trúc trên, cấu hình qua `server.tomcat.*` (`server.tomcat.threads.max`...) |

---

## 7. Đối chiếu từng bước: PHP-FPM và Tomcat

| Bước | Nginx + PHP-FPM | Tomcat |
|---|---|---|
| Nhận kết nối TCP | Nginx worker (epoll) | Acceptor thread |
| Giữ keep-alive | Nginx | Poller (NIO `Selector`) |
| Parse HTTP | Nginx | `Http11Processor` / `Http11InputBuffer` |
| Chọn ứng dụng | `server_name`, `location`, `try_files` | `Mapper`: Host → Context → Wrapper |
| Chuyển vào runtime | HTTP → FastCGI PARAMS + STDIN qua socket | Không chuyển giao thức, `CoyoteAdapter` gọi thẳng Catalina trong cùng JVM |
| Worker xử lý | FPM worker **process** | Executor worker **thread** |
| Đối tượng request | Superglobal (`$_SERVER`, `$_GET`, `$_POST`) do SAPI điền **sẵn** | `HttpServletRequest`, parse parameter **lazy** |
| Front controller | `public/index.php` | `DispatcherServlet` |
| Middleware | Pipeline tự viết (phase 07) | `Filter` / `FilterChain`, Spring `HandlerInterceptor` |
| Buffer header | `output_buffering`, lỗi "headers already sent" | `bufferSize`, `isCommitted()`, `IllegalStateException` |
| Status line | PHP trả `Status:` (CGI), Nginx viết `HTTP/1.1 ...` | Coyote tự viết |
| Kết thúc request | `RSHUTDOWN` dọn sạch mọi state | `recycle()` Request/Response, heap (singleton, session) vẫn còn |
| Tầng giao thức nội bộ | FastCGI | AJP (khi dùng Apache), hoặc HTTP (khi dùng Nginx) |

---

## 8. Lỗi thường gặp và vị trí trong luồng

| Triệu chứng | Hỏng ở đâu |
|---|---|
| **502**, `connect() to unix:/run/php/... failed` | Bước 2: FPM không chạy, sai đường dẫn hoặc sai quyền socket (`listen.owner`) |
| **502**, `upstream prematurely closed` | Bước 3: worker chết giữa chừng (segfault, `request_terminate_timeout`) |
| **504 Gateway Timeout** | Bước 3 lâu quá `fastcgi_read_timeout` (mặc định 60 giây) |
| "File not found." do FPM trả, log có `Primary script unknown` | Bước 2: sai `SCRIPT_FILENAME`, hoặc FPM không đọc được file |
| Hiện **mã nguồn PHP** | Bước 1: không khớp `location ~ \.php$`, Nginx trả như file tĩnh |
| `headers already sent` | Bước 3: có output trước `header()` |
| Upload lớn lỗi **413** | Bước 1: `client_max_body_size` của Nginx (trước cả `upload_max_filesize` của PHP) |
| Tomcat: 502 từ Nginx | Tomcat chưa khởi động xong hoặc đã chết, sai `proxy_pass` |
| Tomcat: `getRemoteAddr()` luôn là `127.0.0.1` | Chưa cấu hình `RemoteIpValve` / forward headers |
| Tomcat: request treo khi tải cao | Hết `maxThreads`, request chờ trong `acceptCount` |

---

## 9. Tự quan sát

```bash
# các record FastCGI thật mà worker FPM đọc và ghi
sudo strace -f -p $(pgrep -f 'php-fpm: pool study' | head -1) -e trace=read,write,accept -s 300
# terminal khác:
curl -i -X POST -d 'title=Hoc+PHP' 'http://php.local/todos?page=2'
```
Trong output sẽ thấy `SCRIPT_FILENAME`, `HTTP_HOST` (PARAMS), `title=Hoc+PHP` (STDIN), `Status: ...` + HTML (STDOUT).

Với Tomcat:
```bash
jcmd <pid> Thread.print | grep -E 'http-nio-8080-(Acceptor|Poller|exec)'   # Acceptor, Poller, worker
```
Bật `AccessLogValve` để xem log, hoặc đặt breakpoint trong `CoyoteAdapter.service()` để đi theo stack trace từ socket tới controller.

Xem thêm: [fpm-vs-servlet-container.md](fpm-vs-servlet-container.md), [../../00-setup/work/sapi.md](../../00-setup/work/sapi.md).
