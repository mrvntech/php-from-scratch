# Proxy "nghe lén" giữa docker CLI và dockerd: in ra mọi byte đi qua.
# Chạy:  python3 sniff.py /tmp/dbg.sock &
#        DOCKER_HOST=unix:///tmp/dbg.sock docker ps -a
import socket, threading, os, sys
P = sys.argv[1]
if os.path.exists(P): os.unlink(P)
srv = socket.socket(socket.AF_UNIX); srv.bind(P); srv.listen()
def pipe(a, b, tag):
    while (d := a.recv(65536)):
        print(f"----- {tag} ({len(d)} bytes) -----\n" + d.decode('utf-8','replace'), flush=True)
        b.sendall(d)
    b.shutdown(socket.SHUT_WR)
while True:
    c, _ = srv.accept()
    u = socket.socket(socket.AF_UNIX); u.connect('/run/docker.sock')
    threading.Thread(target=pipe, args=(c,u,'CLI -> dockerd'), daemon=True).start()
    threading.Thread(target=pipe, args=(u,c,'dockerd -> CLI'), daemon=True).start()
