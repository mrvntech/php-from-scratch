# 09 · Composer, PSR và công cụ

## Mục tiêu

Đến lúc này bạn đã tự viết autoloader, router, HTTP layer và test runner. Phase này thay chúng bằng chuẩn của cộng đồng và nhìn lại vì sao chuẩn đó được thiết kế như vậy.

## Bài tập

1. **Cài Composer bằng tay:** tải installer, **kiểm tra hash SHA-384** theo hướng dẫn trên getcomposer.org, đặt binary vào `~/.local/bin`. Không dùng `apt install composer`.
2. **`composer.json` viết tay:** `autoload.psr-4`, `require`, `require-dev`, `scripts`. Đọc `vendor/composer/autoload_psr4.php` và so với autoloader bạn viết ở phase 03.
3. **Hiểu `composer.lock`:** khi nào dùng `install`, khi nào dùng `update`? Commit file nào?
4. **Chuẩn PSR:** đọc và áp dụng
   - PSR-4 (autoload), PER Coding Style
   - PSR-3 (logger), PSR-11 (container)
   - PSR-7 (HTTP message), PSR-15 (middleware), PSR-17 (factory)
5. **Refactor app todo:** thay `Request`/`Response` tự viết bằng PSR-7 (ví dụ `nyholm/psr7`), middleware bằng PSR-15.
6. **Công cụ:**
   - PHPUnit: chuyển test từ minitest sang PHPUnit.
   - PHPStan: tăng dần level tới 8.
   - PHP-CS-Fixer hoặc PHP_CodeSniffer.
   - Xdebug: cài bằng tay (`php8.5-xdebug`), bật step debug và coverage.
7. **So sánh với framework:** đọc mã nguồn router của Slim hoặc Laravel. Ghi vào journal điểm giống và khác với router của bạn.

## Checklist

- [ ] Composer cài thủ công có kiểm tra hash
- [ ] App chạy với autoload của Composer
- [ ] PSR-7/15 đã tích hợp
- [ ] PHPUnit + PHPStan level ≥ 6
- [ ] Xdebug step debug hoạt động
