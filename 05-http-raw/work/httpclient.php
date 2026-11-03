<?php
// HTTP client tự viết trên TCP socket
// Chạy thử:  php -S 127.0.0.1:8099 -t <thư mục có index.php> &   rồi   php httpclient.php
$fd = stream_socket_client('tcp://127.0.0.1:8099', $errno, $err, timeout: 3);   // socket() + connect(): bắt tay TCP
stream_set_timeout($fd, 5);                                                       // read timeout
fwrite($fd, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");                       // write request

$head = '';
while (!str_contains($head, "\r\n\r\n")) $head .= fread($fd, 1);                 // đọc tới hết header
echo $head;

$body = ''; $reads = 0;
if (preg_match('/^Content-Length: (\d+)/mi', $head, $m)) {
    while (strlen($body) < (int)$m[1]) { $body .= fread($fd, 8192); $reads++; }   // đọc cho đủ Content-Length
    echo "cách xác định độ dài: Content-Length\n";
} else {
    while (!feof($fd)) { $body .= fread($fd, 8192); $reads++; }                   // đọc tới khi server đóng (EOF)
    echo "cách xác định độ dài: đọc tới EOF (server đóng kết nối)\n";
}
echo "body: ", strlen($body), " byte, sau $reads lần fread\n";
fclose($fd);
