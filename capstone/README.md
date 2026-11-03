# Capstone · Mini blog từ đầu

Dự án tổng kết. Viết lại từ đầu (không copy app todo), áp dụng mọi thứ đã học.

## Yêu cầu chức năng

- Đăng ký, đăng nhập, quên mật khẩu (gửi mail ra log hoặc file thay vì SMTP thật)
- Vai trò: admin, author, reader
- Bài viết: CRUD, Markdown, slug, ảnh bìa (upload an toàn), trạng thái nháp/xuất bản
- Bình luận có kiểm duyệt
- Tìm kiếm, phân trang, tag
- RSS feed, sitemap.xml
- Trang admin

## Yêu cầu kỹ thuật

- PSR-4, PSR-7/15, có thể dùng thư viện nhỏ nhưng **không dùng full framework**
- Database: PostgreSQL hoặc MariaDB, có migration
- Test: PHPUnit, coverage ≥ 70% cho phần domain
- PHPStan level 8
- Bảo mật: đã kiểm tra theo danh sách của phase 08
- Chạy được theo 2 cách: Nginx + FPM trên WSL và Docker Compose
- Cache: OPcache bật ở production, cache trang hoặc query đơn giản bằng file hoặc APCu

## Định nghĩa "xong"

- [ ] Mọi chức năng chạy được
- [ ] `composer test` và `composer stan` đều pass
- [ ] README trong `capstone/work/` mô tả kiến trúc và cách chạy
- [ ] Ghi retrospective vào journal: nếu làm lại thì sẽ thay đổi gì
