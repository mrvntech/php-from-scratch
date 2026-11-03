# 01 · Ngôn ngữ cơ bản

## Mục tiêu

Nắm chắc phần ngôn ngữ trước khi đụng tới web: kiểu dữ liệu, mảng, chuỗi, hàm, phạm vi biến, include.

## Chủ đề

- Kiểu và ép kiểu ngầm (type juggling), `declare(strict_types=1)`, `==` và `===`
- `null`, `??`, `??=`, `?->`, `match`
- Mảng: thực chất là **ordered hash map**; key int và string, thứ tự, copy-on-write
- Chuỗi: byte và ký tự, `strlen` và `mb_strlen`, heredoc/nowdoc, `sprintf`
- Hàm: tham số mặc định, named arguments, variadic, tham chiếu `&`, kiểu trả về, union/nullable types
- Phạm vi: `global`, `static`, closure `use`, arrow function `fn`
- `include`/`require`/`*_once`: trả về giá trị, phạm vi biến
- Ngày giờ: `DateTimeImmutable`, timezone

## Bài tập (viết vào `work/`)

1. **`juggling.php`**: in bảng so sánh `==` và `===` cho các cặp giá trị `0, "0", "", null, false, "a", [], "1e3", 1000`. Bật và tắt `strict_types`, rồi giải thích kết quả.
2. **`my_array.php`**: tự viết `my_map`, `my_filter`, `my_reduce`, `my_array_unique` không dùng hàm có sẵn. So sánh kết quả với hàm built-in.
3. **`array_order.php`**: chứng minh mảng PHP giữ thứ tự chèn. Điều gì xảy ra với key `"1"`, `"01"`, `1.5`, `true`?
4. **`wc.php`**: đọc từ STDIN, đếm dòng, từ và ký tự (đúng với tiếng Việt). Ví dụ `cat README.md | php wc.php`.
5. **`refs.php`**: viết ví dụ cho thấy bẫy của `foreach ($arr as &$v)` khi không `unset($v)`.
6. **`include_return.php`**: một file config trả về mảng (`return [...]`) và được nạp bằng `$cfg = require 'config.php';`.
7. **`dates.php`**: tính số ngày làm việc giữa hai ngày theo timezone `Asia/Ho_Chi_Minh`.

## Câu hỏi tự kiểm tra

- `strict_types` ảnh hưởng tới lời gọi hàm ở file nào: file gọi hay file định nghĩa hàm?
- Vì sao `"abc" == 0` cho kết quả khác nhau giữa PHP 7 và PHP 8?
- Copy-on-write là gì? Khi nào mảng thật sự bị sao chép?

## Checklist

- [ ] 7 bài tập trong `work/`
- [ ] Trả lời được 3 câu hỏi (ghi vào journal)
