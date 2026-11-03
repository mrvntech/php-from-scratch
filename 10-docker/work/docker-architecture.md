# Kiến trúc Docker: từ lệnh `docker` tới process trong container

Unix socket chỉ là **chặng đầu**. **Container thực chất là một process Linux bình thường** bị kernel cô lập.

## 1. Tổng thể

```
bạn gõ: docker run -d -p 8080:80 nginx
   │
   ▼
docker CLI (user dev, group docker)          ← chỉ là một HTTP client
   │  HTTP/1.1 REST API (Docker Engine API)
   │  qua /run/docker.sock  (root:docker 0660)
   ▼
dockerd (root) = Docker Engine               ← quản lý image, network, volume, log
   │  gRPC
   │  qua /run/containerd/containerd.sock  (root:root 0660, chỉ root vào được)
   ▼
containerd (root)                            ← vòng đời container, snapshot image
   │  fork/exec
   ▼
containerd-shim-runc-v2                      ← process "cha" của container, giữ stdio
   │  exec
   ▼
runc                                          ← gọi kernel tạo container rồi THOÁT
   │  clone() + namespaces + cgroups + pivot_root
   ▼
nginx (PID 1 trong container)                ← process Linux bình thường, bị cô lập
```
Máy hiện tại (2026-11-04, Docker Engine 29.9.2, API 1.56):
```
PID 255  /usr/bin/containerd
PID 398  /usr/bin/dockerd -H fd:// --containerd=/run/containerd/containerd.sock
```

## 2. Chặng 1: CLI nói HTTP với dockerd

### Không có gì được "ghi vào" file `docker.sock`
- File luôn 0 byte (xem [socket.md](../../06-web-server/work/socket.md)). Thứ CLI gửi là **byte của HTTP request**, nằm trong buffer kernel của kết nối.
- `docker.sock` **không thực thi** dockerd. dockerd là chương trình Go **chạy sẵn**, đang lắng nghe.

### Bắt gói thật: `docker ps -a`
Dùng proxy [sniff.py](sniff.py) đứng giữa CLI và `docker.sock`:
```bash
python3 sniff.py /tmp/dbg.sock &
DOCKER_HOST=unix:///tmp/dbg.sock docker ps -a
```
```
----- CLI -> dockerd (92 bytes) -----
HEAD /_ping HTTP/1.1
Host: api.moby.localhost
User-Agent: Docker-Client/29.9.2 (linux)

----- dockerd -> CLI (316 bytes) -----
HTTP/1.1 200 OK
Api-Version: 1.56
Server: Docker/29.9.2 (linux)
Content-Length: 0
...

----- CLI -> dockerd (113 bytes) -----
GET /v1.56/containers/json?all=1 HTTP/1.1
Host: api.moby.localhost
User-Agent: Docker-Client/29.9.2 (linux)

----- dockerd -> CLI (203 bytes) -----
HTTP/1.1 200 OK
Api-Version: 1.56
Content-Type: application/json
Content-Length: 3

[]
```
- **HTTP/1.1 text thuần**, giống request trình duyệt gửi Nginx.
- `HEAD /_ping`: daemon sống không, hỗ trợ API version nào.
- `GET /v1.56/containers/json?all=1` = `docker ps -a` (`-a` thành `all=1`).
- `Host: api.moby.localhost`: tên giả, vì HTTP/1.1 bắt buộc `Host` còn Unix socket không có host.
- `[]` là JSON. **CLI tự format thành bảng.**

Tự đóng vai CLI:
```bash
curl -s --unix-socket /run/docker.sock http://localhost/_ping
curl -s --unix-socket /run/docker.sock 'http://localhost/v1.56/containers/json?all=1'
curl -v --unix-socket /run/docker.sock http://localhost/version 2>&1 | grep -E '^[<>]'
```
CLI biết kết nối tới đâu nhờ **context** / `DOCKER_HOST` (`docker context ls` → `unix:///var/run/docker.sock`). Đổi thành `tcp://server:2376` hoặc `ssh://user@server` thì điều khiển dockerd máy khác.

### Một lệnh `docker run` = nhiều API call
```
docker run -d -p 8080:80 nginx

1. HEAD /_ping                                   ← thương lượng API version
2. POST /v1.56/containers/create                 ← body JSON: Image, PortBindings, Env, Volumes...
     → 404 "No such image: nginx:latest"
3. POST /v1.56/images/create?fromImage=nginx&tag=latest   ← pull, stream tiến độ JSON
4. POST /v1.56/containers/create                 ← thử lại → 201 {"Id": "a1b2..."}
5. POST /v1.56/containers/a1b2.../start          ← 204
   (không có -d: thêm POST .../attach và POST .../wait)
```
```http
POST /v1.56/containers/create?name=web HTTP/1.1
Host: api.moby.localhost
Content-Type: application/json
Content-Length: 312

{"Image":"nginx","ExposedPorts":{"80/tcp":{}},"HostConfig":{"PortBindings":{"80/tcp":[{"HostPort":"8080"}]}},...}
```
`docker ps` → `GET /containers/json`, `docker logs` → `GET /containers/{id}/logs`, `docker stop` → `POST /containers/{id}/stop`.

### Ở tầng kernel
```
docker CLI (user dev)                    KERNEL                         dockerd (root, chạy sẵn, chặn ở accept())
socket(AF_UNIX) → fd 3
connect(3, "/run/docker.sock") ──────►  tra inode, kiểm tra quyền
                                         (dev ∈ group docker, có w ✓)
                                         tạo CẶP socket nối nhau ──────────► accept() trả về fd 7
write(3, "GET /v1.56/...") ──────────►  chép vào buffer phía dockerd ─────► read(7)
                                         ◄──────────────────────────────── write(7, "HTTP/1.1 200 OK...")
read(3) ◄─────────────────────────────   chép vào buffer phía CLI
```
File `/run/docker.sock` chỉ dùng **một lần ở `connect()`**.

### Ai lắng nghe: systemd socket activation
```ini
# systemctl cat docker.socket
[Socket]
ListenStream=/run/docker.sock
SocketMode=0660
SocketUser=root
SocketGroup=docker        ← vì vậy phải vào group docker
```
- Lúc boot, systemd tạo và listen socket, rồi khởi động dockerd và **truyền fd** sang (`-H fd://` = "dùng fd systemd đưa, đừng tự tạo socket").
- Nếu dockerd bị stop (còn `docker.socket`), client connect vào thì systemd **khởi động lại `docker.service`** rồi giao kết nối. Thử: `sudo systemctl stop docker.service` rồi `docker ps`.
- `containerd.sock` thuộc `root:root`: chỉ dockerd gọi được containerd.

### Bên trong dockerd
dockerd viết bằng Go, phần API (mã nguồn moby, `api/server`) là HTTP server bình thường:
```
net/http Server.Serve(listener)          ← listener = fd do systemd đưa
  │ Accept() → mỗi kết nối một GOROUTINE
  ▼
parse HTTP request
  ▼
middleware: API version (1.40–1.56?), authz plugin, log
  ▼
router: "GET /v{version}/containers/json"        → getContainersJSON
        "POST /v{version}/containers/create"     → postContainersCreate
        "POST /v{version}/containers/{id}/start" → postContainersStart
  ▼
handler → backend: daemon.Containers(), daemon.ContainerCreate(), daemon.ContainerStart() (gRPC sang containerd)
  ▼
encode JSON → ghi "HTTP/1.1 200 OK ..." ra socket
```

| dockerd | Tomcat + Spring |
|---|---|
| `net/http`, một goroutine mỗi kết nối | Coyote, worker thread (virtual thread gần goroutine nhất) |
| Router khớp method + path | `DispatcherServlet` + `@RequestMapping` |
| Middleware | `Filter` / `HandlerInterceptor` |
| Handler | `@PostMapping` trong controller |
| Backend `daemon.*` | Service layer |
| Trả JSON | `@RestController` + Jackson |

**dockerd về bản chất là một REST API server**, nghe trên Unix socket thay vì TCP, "service layer" điều khiển kernel và containerd thay vì database.

## 3. Chặng 2: dockerd chuẩn bị
- **Image, filesystem**: layer xếp chồng bằng **overlayfs** (dưới chỉ đọc, trên cùng ghi được), thành **rootfs**.
- **Network**: cặp **veth**, một đầu cắm bridge `docker0`, đầu kia làm `eth0` trong container, cấp IP. `-p 8080:80` thêm luật **iptables/nftables DNAT**.
- **Volume, mount**: `-v`, `--mount`.
- **Log driver**: mặc định `json-file`, `/var/lib/docker/containers/<id>/<id>-json.log`.
- Tạo **OCI runtime spec** (`config.json`): lệnh chạy, namespace, giới hạn RAM/CPU, mount. Gửi cho containerd qua gRPC.

## 4. Chặng 3: containerd → shim → runc → kernel
```
containerd: tạo "task" từ spec
  └─ khởi động containerd-shim-runc-v2 (mỗi container một shim)
       └─ runc create + runc start
            clone() với namespace mới:
              pid   → process thấy mình là PID 1
              net   → network stack riêng
              mnt   → cây mount riêng
              uts   → hostname riêng
              ipc   → shared memory riêng
              (user, cgroup namespace tuỳ cấu hình)
            cgroups v2 → giới hạn RAM, CPU, số process
            pivot_root → "/" thành rootfs overlayfs
            bỏ capabilities, seccomp, AppArmor
            exec("nginx", ...)
          runc THOÁT
     shim ở lại làm cha của nginx: giữ stdio, thu exit code
```
Shim là cha nên **restart dockerd/containerd thì container vẫn chạy** (`live-restore`).

```bash
docker run -d --name web nginx
ps -ef --forest | grep -A2 containerd-shim     # shim → nginx master → worker
docker inspect -f '{{.State.Pid}}' web          # PID thật trên host
sudo ls -l /proc/<PID>/ns                       # namespace riêng
```

## 5. Kết nối hai chiều giữa CLI và dockerd

Unix socket `SOCK_STREAM` **vốn full-duplex**: mỗi đầu có buffer nhận riêng.
```
        CLI (fd 3)                                   dockerd (fd 7)
   │ write(3, ...) ───┼──► [ buffer nhận B ] ──►│ read(7)          │   CLI → dockerd
   │ read(3) ◄────────┼─── [ buffer nhận A ] ◄──┼── write(7, ...)  │   dockerd → CLI
```
- Hai chiều độc lập. **Backpressure**: buffer đầy thì `write()` bị chặn.
- **Đóng một nửa** `shutdown(fd, SHUT_WR)`: báo hết dữ liệu gửi, chiều ngược lại vẫn mở.

### Ba cách Docker dùng kết nối
| Kiểu | Lệnh | Cách hoạt động |
|---|---|---|
| Request/response | `docker ps`, `create` | Xen kẽ theo lượt, keep-alive dùng lại kết nối |
| Stream | `docker logs -f`, `events`, `pull` | `Transfer-Encoding: chunked`, response không kết thúc, chủ yếu một chiều |
| **Hijack** | `docker run -it`, `attach`, `exec -it` | HTTP upgrade rồi thành ống byte thô hai chiều |

### Hijack
```http
POST /v1.56/containers/a1b2/attach?stream=1&stdin=1&stdout=1&stderr=1 HTTP/1.1
Host: api.moby.localhost
Connection: Upgrade
Upgrade: tcp

HTTP/1.1 101 UPGRADED
Content-Type: application/vnd.docker.raw-stream
Connection: Upgrade
Upgrade: tcp
```
Sau `101`, kết nối không còn là HTTP: phím gõ → stdin container, output container → màn hình. Giống WebSocket (`101 Switching Protocols`).

- **Có `-t`**: byte thô, stdout và stderr gộp chung.
- **Không có `-t`**: mỗi khúc có header 8 byte (stream multiplexing):
  ```
  ┌──────────┬───────────┬──────────────────┐
  │ stream   │ 0  0  0   │ size (4 byte,    │ + payload
  │ 1 byte   │ 3 byte    │  big-endian)     │
  └──────────┴───────────┴──────────────────┘
  stream: 0 = stdin, 1 = stdout, 2 = stderr
  ```

### Đường đi của một phím trong `docker run -it alpine sh`
```
bàn phím → terminal (CLI chuyển sang raw mode)
  ▼
docker CLI   read(stdin) → write(socket)
  │ ═══ Unix socket, kết nối đã hijack (full-duplex) ═══
  ▼
dockerd      read(socket) → write(FIFO stdin)
  │ ═══ FIFO (named pipe), /run/docker/containerd/<id>/init-stdin, init-stdout ═══
  ▼
containerd-shim-runc-v2
  │ ═══ pseudo-terminal (pty): shim giữ đầu "master" ═══
  ▼
/dev/pts/0 ("slave") = stdin/stdout của sh trong container
  ▲ output đi NGƯỢC lại đúng đường đó
```

| Chặng | Kênh | Hai chiều? |
|---|---|---|
| CLI ↔ dockerd | Unix socket | **Có**, một kết nối |
| dockerd ↔ shim | FIFO | **Không**, mỗi FIFO một chiều, cần hai |
| shim ↔ process | pty (có `-t`) / pipe (không `-t`) | pty: có. pipe: không |

Socket một kết nối đã đủ hai chiều, pipe/FIFO cần hai cái.

### Kết thúc
- `exit` trong `sh`: pty đóng, shim thấy EOF, dockerd `close()` socket, CLI gọi `POST .../wait` lấy exit code rồi thoát với đúng mã đó.
- **Ctrl+P Ctrl+Q** (detach): CLI đóng kết nối, container vẫn chạy.
- `echo hi | docker run -i alpine cat`: hết stdin thì CLI `shutdown(SHUT_WR)`, container nhận EOF, CLI vẫn đọc output.

## 6. Quyền và bảo mật
- `docker` CLI không phải root, **không thuộc group `docker`** → `permission denied while trying to connect to the Docker daemon socket`.
- `sudo docker` (client chạy bằng root) hoặc `sudo usermod -aG docker dev` (rồi đăng nhập lại hoặc `newgrp docker`).
- **Group `docker` gần như tương đương root**: `docker run -v /:/host ...` đọc/ghi toàn bộ máy.

## 7. Đối chiếu với kiến thức đã học
| Docker | Web stack |
|---|---|
| `docker` CLI | Trình duyệt / `curl` |
| `/run/docker.sock` | `/run/php/study.sock` (Unix socket, owner/group/mode) |
| Docker Engine API (REST) | Giao thức client–server |
| dockerd | Web server + ứng dụng |
| gRPC tới containerd | FastCGI tới FPM |
| shim → runc → process | FPM master fork worker |
| systemd socket activation | FPM master mở socket trước, worker thừa hưởng fd |
| Hijack (`101 UPGRADED`) | WebSocket (`101 Switching Protocols`) |

Liên hệ Java: **docker-java**, **Testcontainers** là HTTP client gọi vào `docker.sock`. Kubernetes: `kubelet` nói thẳng với **containerd** qua **CRI** (gRPC).

## Thực hành (cần pull image)
```bash
docker pull alpine
DOCKER_HOST=unix:///tmp/dbg.sock docker run -it --rm alpine sh   # qua sniff.py: thấy "Upgrade: tcp", "101 UPGRADED", từng phím
docker run -dit --name t alpine sh
sudo ls -l /run/docker/containerd/$(docker inspect -f '{{.Id}}' t)/   # FIFO (chữ "p")
docker exec t ls -l /proc/1/fd                                         # 0,1,2 → /dev/pts/0
```

Xem thêm: [socket.md](../../06-web-server/work/socket.md), [http-connection.md](../../05-http-raw/work/http-connection.md).
