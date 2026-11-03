# PHP-FPM và servlet container (Tomcat)

Cả hai đều dùng **một pool worker tạo sẵn và có giới hạn**. FPM dùng **process**, Tomcat dùng **thread**. "Thread per request" trong Tomcat nghĩa là **mượn** một thread có sẵn từ pool, không phải tạo thread mới cho mỗi request.

## 1. FPM giữ process

```
php-fpm: master process (root)        ← đọc php-fpm.conf + php.ini, MINIT, mở listen socket
 ├─ pool www: worker (www-data)       ← fork() từ master, thừa hưởng socket và config
 ├─ pool www: worker
 └─ pool www: worker
```

Vòng đời một worker:
```
loop:
  accept() trên listen socket        ← các worker cùng chờ, kernel trao kết nối cho một worker
  đọc FastCGI record từ Nginx
  RINIT → chạy script → RSHUTDOWN     ← share-nothing: dọn sạch state
  gửi response, đóng kết nối FastCGI
  if (đủ pm.max_requests) exit       ← master fork worker mới
```

Master: tạo/huỷ worker theo `pm`, fork lại worker chết, xử lý signal (`USR2` reload, `QUIT` graceful shutdown), kill worker chạy quá `request_terminate_timeout`.

| `pm` | Hành vi |
|---|---|
| `static` | Luôn giữ đúng `pm.max_children` worker |
| `dynamic` | Giữ `pm.min_spare_servers`…`pm.max_spare_servers` worker rảnh, tối đa `pm.max_children` |
| `ondemand` | Ban đầu không có worker. Có request mới fork, rảnh quá `pm.process_idle_timeout` thì kill |

**Mỗi worker xử lý một request tại một thời điểm.** Khi tất cả bận, kết nối mới chờ trong hàng đợi kernel (`listen.backlog`).

## 2. Tomcat: thread per request

Connector mặc định từ Tomcat 8.5 là **NIO** (`Http11NioProtocol`). BIO (mỗi kết nối giữ chặt một thread) đã bị bỏ.

```
1 process JVM
└─ Connector (Coyote, Http11NioProtocol)
    ├─ Acceptor thread      ← ServerSocketChannel.accept(), nhận kết nối TCP
    ├─ Poller thread        ← java.nio.channels.Selector theo dõi nhiều socket,
    │                          biết socket nào có dữ liệu để đọc
    └─ Executor (thread pool "http-nio-8080-exec-N")
         worker thread ← nhận SocketProcessor từ Poller
           → Http11Processor parse HTTP
           → CoyoteAdapter → Catalina pipeline (Engine → Host → Context → Wrapper valves)
           → FilterChain → Servlet.service() → ứng dụng (DispatcherServlet...)
           → ghi response → trả thread về pool
```

Cơ chế Java phía sau:
- **Thread pool**: dựa trên `java.util.concurrent.ThreadPoolExecutor` (Tomcat dùng `StandardThreadExecutor` với `TaskQueue` riêng). Thread được tạo trước theo `minSpareThreads`, tăng tới `maxThreads`, rồi **được tái sử dụng**.
- **Khác pool chuẩn JDK**: `ThreadPoolExecutor` chuẩn **xếp hàng trước** rồi mới tăng thread. `TaskQueue` của Tomcat **tăng thread tới `maxThreads` trước**, hết thread mới xếp hàng.
- **Servlet là singleton**: mỗi servlet khai báo có một instance, mọi worker thread cùng gọi `service()` trên đó. Vì vậy field trong servlet hoặc Spring bean (scope singleton mặc định) phải thread-safe.
- **`ThreadLocal`**: dữ liệu riêng của request được gắn với thread (`RequestContextHolder`, `SecurityContextHolder` của Spring). Thread được tái sử dụng, nên không dọn `ThreadLocal` thì dữ liệu sẽ rò sang request sau.

| Tham số connector | Mặc định | Ý nghĩa |
|---|---|---|
| `maxThreads` | 200 | Số request xử lý đồng thời tối đa |
| `minSpareThreads` | 10 | Số thread giữ sẵn |
| `maxConnections` | 8192 | Số kết nối TCP Poller giữ được, kể cả keep-alive rảnh |
| `acceptCount` | 100 | Backlog của OS khi đã đạt `maxConnections` |

**Virtual thread** (JDK 21, JEP 444): Tomcat 10.1+ có `useVirtualThreads="true"`, Spring Boot 3.2+ có `spring.threads.virtual.enabled=true`. Khi bật, mỗi request được một virtual thread **tạo mới thật sự**, không cần pool. Khi gặp I/O chặn (JDBC, HTTP client), virtual thread *unmount* khỏi *carrier thread*, nên ít OS thread phục vụ được rất nhiều request.

### Sơ đồ: worker pool của hai bên
```
Nginx + PHP-FPM: NHIỀU process, mỗi process 1 request
┌──────────────┐  FastCGI   ┌────────────────────────── php-fpm ───────────────────────────┐
│ Nginx        │───────────►│ master process (root): fork / kill / respawn worker          │
│ (giữ kết nối │  unix sock │  ├─ ┌ worker process ┐  ├─ ┌ worker process ┐  ├─ ┌ worker ┐ │
│  client,     │            │  │  │ memory riêng   │  │  │ memory riêng   │  │  │  ...   │ │
│  keep-alive) │            │  │  │ 1 request      │  │  │ (rảnh, accept) │  │  └────────┘ │
└──────────────┘            │  │  └────────────────┘  │  └────────────────┘  │             │
                            │  shared memory: OPcache (opcode dùng chung giữa các worker)  │
                            └──────────────────────────────────────────────────────────────┘

Tomcat: MỘT process, nhiều thread dùng chung heap
┌──────────────────────────────────── 1 process JVM ───────────────────────────────────────┐
│ Acceptor thread ──► Poller thread (Selector, giữ keep-alive)                             │
│                          │ SocketProcessor                                               │
│                          ▼                                                               │
│ Executor: ┌ exec-1 ┐ ┌ exec-2 ┐ ┌ exec-3 ┐ ┌  ...  ┐   (tối đa maxThreads)               │
│           │ stack  │ │ stack  │ │ stack  │ │       │   mỗi thread: stack riêng (~1 MB)   │
│           │ 1 req  │ │ 1 req  │ │ rảnh   │ │       │   + ThreadLocal riêng               │
│           └───┬────┘ └───┬────┘ └────────┘ └───────┘                                     │
│               └────┬─────┘                                                               │
│                    ▼                                                                     │
│ HEAP DÙNG CHUNG: servlet singleton, Spring bean, connection pool, cache, HttpSession     │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

## 3. Đối chiếu

| FPM | Tomcat |
|---|---|
| Master process | JVM + Connector (quản lý Executor) |
| Worker **process** | Worker **thread** trong Executor |
| `pm.max_children` | `maxThreads` |
| `pm.start_servers`, `pm.min_spare_servers` | `minSpareThreads` |
| `listen.backlog` | `acceptCount` |
| Nginx giữ kết nối client, keep-alive | Acceptor + Poller (NIO Selector) |
| FastCGI giữa Nginx và FPM | Không cần, Coyote tự parse HTTP |
| `pm.max_requests` (tái tạo worker chống leak) | Không có tương đương |
| `request_terminate_timeout` (kill worker) | Không kill thread an toàn được. `StuckThreadDetectionValve` chỉ phát hiện, ghi log, có thể *interrupt* |
| `pm.status_path` | JMX MBean, Tomcat Manager status |

## 4. Giống nhau

- **Pool tạo sẵn, có giới hạn.** Không tạo worker mới cho mỗi request (trừ `ondemand` của FPM và virtual thread của Java).
- **Một worker một request, bị chặn suốt request.** Request chờ DB 2 giây thì worker process hoặc platform thread cũng đứng yên 2 giây.
- **Hết worker thì xếp hàng** (`listen.backlog` / `acceptCount`). Hàng đợi đầy thì client bị từ chối hoặc timeout. Đứng sau Nginx sẽ thấy **502/504**.
- **Có reload và graceful shutdown**: xử lý nốt request đang chạy rồi mới dừng.

## 5. Khác nhau

| | FPM (process) | Tomcat (thread) |
|---|---|---|
| Bộ nhớ | Mỗi worker có không gian địa chỉ riêng (đỡ tốn nhờ copy-on-write và shared memory của OPcache) | Một heap dùng chung. Mỗi thread có stack riêng (khoảng 1 MB, đặt bằng `-Xss`) |
| State giữa request | **Share-nothing**: RSHUTDOWN dọn sạch | **Shared heap**: singleton, cache, connection pool sống qua mọi request |
| Thread-safety | Gần như không phải lo | Bắt buộc: `synchronized`, `java.util.concurrent`, bean không giữ state |
| Cô lập lỗi | Worker segfault hoặc leak thì chỉ worker đó chết, master fork lại | `OutOfMemoryError` ảnh hưởng **cả JVM** |
| Chi phí mỗi request | Bootstrap lại app (autoload, DI container, kết nối DB). OPcache bớt phần compile | Gần như bằng 0: mọi thứ đã nằm trong heap, JIT đã warm-up |
| Keep-alive | Worker không thấy client, Nginx lo | Poller giữ keep-alive, không chiếm worker thread |
| Tính kích thước pool | `pm.max_children ≈ RAM dành cho PHP / RSS trung bình mỗi worker` (giới hạn bởi **RAM**) | `maxThreads ≈ throughput × latency` (Little's law; giới hạn bởi **CPU và tài nguyên phía sau** như DB connection pool) |
| Mở rộng khi I/O nhiều | Thêm process (tốn RAM) | Virtual thread, async servlet (`startAsync()`, Servlet 3.0) |

## Tóm lại

```
FPM:    Nginx (giữ kết nối) → master → [process][process][process]   1 process 1 request, xong dọn sạch
Tomcat: Acceptor/Poller (giữ kết nối) → Executor → [thread][thread]  1 thread 1 request, heap dùng chung
```
- **Hình dạng giống nhau**: pool worker có giới hạn, một worker một request, có hàng đợi khi quá tải.
- **Bản chất khác nhau**: process tách biệt, không giữ state; thread dùng chung heap, giữ state. Các khác biệt về thread-safety, cô lập lỗi, chi phí bootstrap và cách tính kích thước pool đều xuất phát từ điểm này.

## Kiểm chứng ở stage B

```bash
ps -ef --forest | grep php-fpm                 # master + các worker
# đổi pm = static | dynamic | ondemand, reload, quan sát lại số process
jcmd <pid> Thread.print | grep http-nio        # nếu có Tomcat: các thread http-nio-8080-exec-N
```

Xem thêm: [request-flow.md](request-flow.md) (luồng request chi tiết qua Nginx, FastCGI, SAPI và Tomcat), [../../00-setup/work/sapi.md](../../00-setup/work/sapi.md), [../../00-setup/work/php-cli-vs-java.md](../../00-setup/work/php-cli-vs-java.md).
