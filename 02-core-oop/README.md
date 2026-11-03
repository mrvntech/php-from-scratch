# 02 · OOP và PHP 8.x

## Mục tiêu

Viết OOP đúng kiểu PHP hiện đại và biết các tính năng mới từ 8.0 tới 8.5.

## Chủ đề

- Class, visibility, `static`, `self` và `static` (late static binding)
- Abstract class, interface, trait (xung đột tên, `insteadof`)
- Constructor promotion, `readonly` property và `readonly class`
- Enum (pure và backed), method trên enum, `cases()`, `from`/`tryFrom`
- Magic methods: `__get`, `__set`, `__call`, `__toString`, `__invoke`
- Namespace, `use`, `use function`
- First-class callable `strlen(...)`
- **PHP 8.4:** property hooks, asymmetric visibility (`public private(set)`), `new` không cần ngoặc khi gọi method
- **PHP 8.5:** pipe operator `|>`, `clone with`, extension `uri`, `#[\NoDiscard]`

> Với tính năng 8.4/8.5, tự đọc RFC tương ứng trên https://wiki.php.net/rfc trước khi viết ví dụ.

## Bài tập (viết vào `work/`)

1. **Domain "kho hàng"**: `Product` (readonly), `Money` (value object, không dùng float), `StockStatus` (backed enum), `Inventory` (thêm, xuất, báo cáo).
2. **`lsb.php`**: ví dụ cho thấy khác biệt giữa `new self()` và `new static()` trong factory method.
3. **`traits.php`**: hai trait có cùng tên method, giải quyết bằng `insteadof` và `as`.
4. **`hooks.php`**: viết lại một class có getter/setter thành property hooks (8.4).
5. **`pipe.php`**: xử lý chuỗi `" Xin Chào PHP "` thành slug `xin-chao-php` bằng `|>` (8.5), so sánh với cách lồng hàm.
6. **`immutability.php`**: dùng `clone with` để tạo bản sao đã sửa của object readonly.

## Câu hỏi tự kiểm tra

- Khi nào dùng interface, khi nào dùng abstract class, khi nào dùng trait?
- Readonly class có thật sự bất biến không nếu nó chứa một object khác?
- Property hooks thay thế được magic `__get`/`__set` ở những trường hợp nào?

## Checklist

- [ ] Domain kho hàng chạy được và có ví dụ sử dụng
- [ ] 5 file ví dụ còn lại
- [ ] Ghi tóm tắt tính năng 8.4/8.5 vào journal
