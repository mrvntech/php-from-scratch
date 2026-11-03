# 04 · PHP CLI

## Mục tiêu

Dùng PHP như một ngôn ngữ script hệ thống và tự viết công cụ dùng lại ở các phase sau.

## Chủ đề

- `$argv`, `$argc`, `getopt()`
- Stream: `STDIN`, `STDOUT`, `STDERR`, `php://stdin`, `php://memory`, `php://temp`
- Exit code và quy ước Unix
- Signal: `pcntl_signal`, `pcntl_async_signals`, xử lý Ctrl+C
- Tiến trình: `proc_open`, `pcntl_fork`
- Đóng gói: Phar, shebang `#!/usr/bin/env php`

## Bài tập (viết vào `work/`)

1. **`greet`**: script có shebang, `chmod +x`, nhận `--name`, `-v`, `--help` qua `getopt`. Lỗi ghi ra STDERR và thoát với exit code khác 0.
2. **`watch.php`**: vòng lặp vô hạn in thời gian mỗi giây, bắt `SIGINT`/`SIGTERM` để dọn dẹp rồi thoát gọn.
3. **`parallel.php`**: dùng `pcntl_fork` chạy 4 tác vụ song song, cha đợi con bằng `pcntl_waitpid`.
4. **`minitest/`** (dùng lại ở phase 07): mini test runner tự viết.
   - Tìm file `*Test.php`, chạy các method bắt đầu bằng `test`.
   - Có `assertSame`, `assertTrue`, `assertThrows`.
   - In kết quả có màu, exit code 1 nếu có test fail.
5. **`build-phar.php`**: đóng gói `minitest` thành `minitest.phar`. Cần đặt `phar.readonly=0` khi build. Ghi vào `99-study.ini` hay dùng `-d`? Vì sao?

## Câu hỏi tự kiểm tra

- `echo` và `fwrite(STDOUT, ...)` khác nhau thế nào khi có output buffering?
- Vì sao `pcntl` không có trong SAPI web?
- Exit code của script khi có uncaught exception là bao nhiêu?

## Checklist

- [ ] `greet` chạy như một lệnh Unix thật
- [ ] Xử lý signal đúng
- [ ] `minitest` chạy được và có test cho chính nó
- [ ] `minitest.phar`
